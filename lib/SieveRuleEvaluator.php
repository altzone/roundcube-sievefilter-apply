<?php

/**
 * Sieve Rule Evaluator
 *
 * Evaluates Sieve filter rules against message headers.
 * Works with the parsed rule format from rcube_sieve_script::as_array().
 *
 * Implements RFC 5228 semantics:
 * - All matching rules fire (actions accumulate) unless an explicit 'stop' is encountered
 * - Multi-value headers are tested individually (not concatenated)
 * - 'matches' wildcards use Sieve semantics (* = any string, ? = any char)
 *
 * @author Claude Code
 * @license GPLv3
 */
class SieveRuleEvaluator
{
    private $skip_redirect;
    private $skip_reject;

    /**
     * @param bool $skip_redirect Whether to skip redirect actions
     * @param bool $skip_reject   Whether to skip reject actions
     */
    public function __construct($skip_redirect = true, $skip_reject = true)
    {
        $this->skip_redirect = $skip_redirect;
        $this->skip_reject   = $skip_reject;
    }

    /**
     * Evaluate all rules against a message's headers.
     *
     * Per RFC 5228 §2.10: all matching rules execute their actions unless
     * an explicit 'stop' command halts evaluation. Actions accumulate across rules.
     *
     * @param array $rules   Rules from rcube_sieve_script::as_array()
     * @param array $headers Associative array of message headers (lowercase keys)
     * @param int   $size    Message size in bytes (for size tests)
     * @return array List of actions: [{type: string, target: string|null}, ...]
     */
    public function evaluate(array $rules, array $headers, $size = 0)
    {
        // Normalize header keys to lowercase
        $normalized = [];
        foreach ($headers as $key => $value) {
            $normalized[strtolower($key)] = $value;
        }
        $headers = $normalized;

        $collected_actions = [];

        foreach ($rules as $rule) {
            // Skip disabled rules
            if (!empty($rule['disabled'])) {
                continue;
            }

            // Rules without tests are skipped (unconditional rules should use 'true' test)
            if (!isset($rule['tests']) || !is_array($rule['tests'])) {
                continue;
            }

            $match = $this->_evaluate_tests($rule, $headers, $size);

            if ($match) {
                $actions = $this->_extract_actions($rule);
                $collected_actions = array_merge($collected_actions, $actions);

                // Check for explicit stop action - halts rule evaluation (RFC 5228 §2.10.1)
                if ($this->_has_stop($rule)) {
                    return $collected_actions;
                }
            }
        }

        return $collected_actions;
    }

    /**
     * Check if a rule contains a stop action.
     *
     * @param array $rule Rule definition
     * @return bool
     */
    private function _has_stop(array $rule)
    {
        if (empty($rule['actions']) || !is_array($rule['actions'])) {
            return false;
        }

        foreach ($rule['actions'] as $action) {
            if (isset($action['type']) && $action['type'] === 'stop') {
                return true;
            }
        }

        return false;
    }

    /**
     * Evaluate the test(s) of a rule.
     *
     * @param array $rule    The rule with 'join' and 'tests'
     * @param array $headers Normalized headers
     * @param int   $size    Message size
     * @return bool
     */
    private function _evaluate_tests(array $rule, array $headers, $size)
    {
        $tests = $rule['tests'];
        $join  = isset($rule['join']) ? $rule['join'] : false; // false=anyof, true=allof

        if (empty($tests)) {
            return false;
        }

        // allof = AND (all must match), anyof = OR (at least one must match)
        foreach ($tests as $test) {
            $result = $this->_evaluate_test($test, $headers, $size);

            if ($join) {
                // allof: if any fails, rule fails
                if (!$result) {
                    return false;
                }
            } else {
                // anyof: if any succeeds, rule matches
                if ($result) {
                    return true;
                }
            }
        }

        // allof: all passed → true; anyof: none passed → false
        return (bool) $join;
    }

    /**
     * Evaluate a single test condition.
     *
     * @param array $test    Test definition
     * @param array $headers Normalized headers
     * @param int   $size    Message size
     * @return bool
     */
    private function _evaluate_test(array $test, array $headers, $size)
    {
        if (!isset($test['test'])) {
            return false;
        }

        $not    = !empty($test['not']);
        $result = false;

        switch ($test['test']) {
            case 'header':
                $result = $this->_test_header($test, $headers);
                break;

            case 'address':
                $result = $this->_test_address($test, $headers);
                break;

            case 'envelope':
                // Envelope tests can't be evaluated retroactively (no envelope data).
                // Fall back to address test on From/To headers as a best-effort approximation.
                // Note: this may produce false positives for forwarded mail, mailing lists, etc.
                $result = $this->_test_address($test, $headers);
                break;

            case 'size':
                $result = $this->_test_size($test, $size);
                break;

            case 'exists':
                $result = $this->_test_exists($test, $headers);
                break;

            case 'allof':
                if (isset($test['tests'])) {
                    $result = true;
                    foreach ($test['tests'] as $sub) {
                        if (!$this->_evaluate_test($sub, $headers, $size)) {
                            $result = false;
                            break;
                        }
                    }
                }
                break;

            case 'anyof':
                if (isset($test['tests'])) {
                    $result = false;
                    foreach ($test['tests'] as $sub) {
                        if ($this->_evaluate_test($sub, $headers, $size)) {
                            $result = true;
                            break;
                        }
                    }
                }
                break;

            case 'true':
                $result = true;
                break;

            case 'false':
                $result = false;
                break;

            default:
                // Unknown test type → no match
                $result = false;
                break;
        }

        return $not ? !$result : $result;
    }

    /**
     * Test a header value (RFC 5228 §5.7).
     *
     * Multi-value headers are tested individually per RFC 5228 §5.7.1:
     * "If the header is multi-valued, each value is tested independently."
     *
     * @param array $test    Test with 'arg1' (header names), 'arg2' (values), 'type' (match type)
     * @param array $headers Normalized headers
     * @return bool
     */
    private function _test_header(array $test, array $headers)
    {
        $header_names = isset($test['arg1']) ? (array) $test['arg1'] : [];
        $keys         = isset($test['arg2']) ? (array) $test['arg2'] : [];
        $match_type   = isset($test['type']) ? $test['type'] : 'is';

        foreach ($header_names as $name) {
            $name = strtolower($name);
            if (!isset($headers[$name])) {
                continue;
            }

            // RFC 5228 §5.7.1: test each value individually for multi-value headers
            $values = is_array($headers[$name]) ? $headers[$name] : [$headers[$name]];

            foreach ($values as $value) {
                foreach ($keys as $pattern) {
                    if ($this->_match_value($value, $pattern, $match_type)) {
                        return true;
                    }
                }
            }
        }

        return false;
    }

    /**
     * Test an address header, extracting the specified part (RFC 5228 §5.1).
     *
     * @param array $test    Test with 'arg1' (header names), 'arg2' (values), 'type', 'part'
     * @param array $headers Normalized headers
     * @return bool
     */
    private function _test_address(array $test, array $headers)
    {
        $header_names = isset($test['arg1']) ? (array) $test['arg1'] : [];
        $keys         = isset($test['arg2']) ? (array) $test['arg2'] : [];
        $match_type   = isset($test['type']) ? $test['type'] : 'is';
        $part         = isset($test['part']) ? $test['part'] : 'all';

        foreach ($header_names as $name) {
            $name = strtolower($name);
            if (!isset($headers[$name])) {
                continue;
            }

            // Test each value individually for multi-value headers
            $header_values = is_array($headers[$name]) ? $headers[$name] : [$headers[$name]];

            foreach ($header_values as $hval) {
                // Extract individual addresses using Roundcube's parser if available
                $addresses = $this->_parse_addresses($hval);

                foreach ($addresses as $addr) {
                    $test_value = $addr;

                    switch ($part) {
                        case 'localpart':
                            $at = strrpos($addr, '@');
                            $test_value = $at !== false ? substr($addr, 0, $at) : $addr;
                            break;
                        case 'domain':
                            $at = strrpos($addr, '@');
                            $test_value = $at !== false ? substr($addr, $at + 1) : '';
                            break;
                        case 'all':
                        default:
                            $test_value = $addr;
                            break;
                    }

                    foreach ($keys as $pattern) {
                        if ($this->_match_value($test_value, $pattern, $match_type)) {
                            return true;
                        }
                    }
                }
            }
        }

        return false;
    }

    /**
     * Parse email addresses from a header value.
     *
     * Uses rcube_mime::decode_address_list() when available (Roundcube context),
     * falls back to a regex pattern.
     *
     * @param string $value Header value (may contain multiple addresses)
     * @return array List of email addresses (lowercase)
     */
    private function _parse_addresses($value)
    {
        // Use Roundcube's address parser when available (handles RFC 5321 fully)
        if (class_exists('rcube_mime')) {
            $parsed = rcube_mime::decode_address_list($value, null, true, null, false);
            $addresses = [];
            if (is_array($parsed)) {
                foreach ($parsed as $entry) {
                    if (!empty($entry['mailto'])) {
                        $addresses[] = strtolower($entry['mailto']);
                    }
                }
            }
            if (!empty($addresses)) {
                return $addresses;
            }
        }

        // Fallback regex - handles common formats including user@localhost
        $addresses = [];
        if (preg_match_all('/[\w.+-]+@[\w.-]+/', $value, $matches)) {
            $addresses = array_map('strtolower', $matches[0]);
        }

        return $addresses;
    }

    /**
     * Test message size.
     *
     * @param array $test Test with 'type' (:over/:under) and 'arg' (size value)
     * @param int   $size Message size in bytes
     * @return bool
     */
    private function _test_size(array $test, $size)
    {
        $limit      = isset($test['arg']) ? $this->_parse_size($test['arg']) : 0;
        $comparator = isset($test['type']) ? $test['type'] : 'over';

        if ($comparator === 'under') {
            return $size < $limit;
        }

        // 'over' is default
        return $size > $limit;
    }

    /**
     * Parse a size value with optional unit suffix.
     *
     * @param string|int $value Size value (e.g., "100K", "1M", 4096)
     * @return int Size in bytes
     */
    private function _parse_size($value)
    {
        if (is_numeric($value)) {
            return (int) $value;
        }

        $value = strtoupper(trim($value));
        $num   = (int) $value;

        if (strpos($value, 'G') !== false) {
            return $num * 1073741824;
        }
        if (strpos($value, 'M') !== false) {
            return $num * 1048576;
        }
        if (strpos($value, 'K') !== false) {
            return $num * 1024;
        }

        return $num;
    }

    /**
     * Test that specified headers exist (RFC 5228 §5.7.3).
     *
     * @param array $test    Test with 'arg' (header names)
     * @param array $headers Normalized headers
     * @return bool
     */
    private function _test_exists(array $test, array $headers)
    {
        $header_names = isset($test['arg']) ? (array) $test['arg'] : [];

        // RFC: vacuous truth - empty list means all zero headers exist
        if (empty($header_names)) {
            return true;
        }

        foreach ($header_names as $name) {
            if (!isset($headers[strtolower($name)])) {
                return false;
            }
        }

        return true;
    }

    /**
     * Match a value against a pattern with the given match type.
     *
     * @param string $value   The value to test
     * @param string $pattern The pattern to match against
     * @param string $type    Match type: 'is', 'contains', 'matches', 'regex'
     * @return bool
     */
    private function _match_value($value, $pattern, $type)
    {
        switch ($type) {
            case 'is':
                return strcasecmp($value, $pattern) === 0;

            case 'contains':
                if ($pattern === '') {
                    return true; // empty string is contained in any string
                }
                return stripos($value, $pattern) !== false;

            case 'matches':
                // Sieve 'matches' uses * (any string incl. empty) and ? (any single char)
                // Sieve escape: \* matches literal *, \? matches literal ?
                // Convert to PCRE regex with proper escape handling
                $regex = $this->_sieve_match_to_regex($pattern);
                return (bool) @preg_match($regex, $value);

            case 'regex':
                // Pattern is already a regex - add safety limits against ReDoS
                $delimited = '/(*LIMIT_MATCH=1000000)' . str_replace('/', '\\/', $pattern) . '/i';
                $result = @preg_match($delimited, $value);
                return $result === 1;

            default:
                return false;
        }
    }

    /**
     * Convert a Sieve 'matches' pattern to a PCRE regex.
     *
     * Handles Sieve escape sequences: \* → literal *, \? → literal ?
     * Wildcards: * → any string (including empty), ? → any single character
     *
     * @param string $pattern Sieve matches pattern
     * @return string PCRE regex with delimiters
     */
    private function _sieve_match_to_regex($pattern)
    {
        $regex = '';
        $len = strlen($pattern);

        for ($i = 0; $i < $len; $i++) {
            $char = $pattern[$i];

            if ($char === '\\' && $i + 1 < $len) {
                $next = $pattern[$i + 1];
                if ($next === '*' || $next === '?') {
                    // Escaped wildcard → literal character
                    $regex .= preg_quote($next, '/');
                    $i++;
                } else {
                    // Literal backslash
                    $regex .= preg_quote($char, '/');
                }
            } elseif ($char === '*') {
                $regex .= '.*';
            } elseif ($char === '?') {
                $regex .= '.';
            } else {
                $regex .= preg_quote($char, '/');
            }
        }

        return '/^' . $regex . '$/is'; // s flag: . matches newlines too
    }

    /**
     * Extract actions from a rule, filtering unsafe ones.
     *
     * @param array $rule Rule definition with 'actions'
     * @return array List of actions [{type, target}, ...]
     */
    private function _extract_actions(array $rule)
    {
        $result = [];

        if (!isset($rule['actions']) || !is_array($rule['actions'])) {
            return $result;
        }

        foreach ($rule['actions'] as $action) {
            if (!isset($action['type'])) {
                continue;
            }

            switch ($action['type']) {
                case 'fileinto':
                    $target = isset($action['target']) ? $action['target'] : null;
                    if ($target) {
                        $result[] = ['type' => 'fileinto', 'target' => $target];
                    }
                    break;

                case 'addflag':
                    $target = isset($action['target']) ? $action['target'] : null;
                    if ($target) {
                        $result[] = ['type' => 'addflag', 'target' => $target];
                    }
                    break;

                case 'setflag':
                    // setflag replaces all flags (RFC 5232), distinct from addflag
                    $target = isset($action['target']) ? $action['target'] : null;
                    if ($target) {
                        $result[] = ['type' => 'setflag', 'target' => $target];
                    }
                    break;

                case 'removeflag':
                    $target = isset($action['target']) ? $action['target'] : null;
                    if ($target) {
                        $result[] = ['type' => 'removeflag', 'target' => $target];
                    }
                    break;

                case 'discard':
                    $result[] = ['type' => 'discard', 'target' => null];
                    break;

                case 'keep':
                    $result[] = ['type' => 'keep', 'target' => null];
                    break;

                case 'stop':
                    // Stop is handled at the rule evaluation level via _has_stop()
                    break;

                case 'redirect':
                    if (!$this->skip_redirect) {
                        $target = isset($action['target']) ? $action['target'] : null;
                        if ($target) {
                            $result[] = ['type' => 'redirect', 'target' => $target];
                        }
                    }
                    break;

                case 'reject':
                case 'ereject':
                    if (!$this->skip_reject) {
                        $result[] = ['type' => 'reject', 'target' => null];
                    }
                    break;

                default:
                    // Unknown action type → skip
                    break;
            }
        }

        return $result;
    }
}
