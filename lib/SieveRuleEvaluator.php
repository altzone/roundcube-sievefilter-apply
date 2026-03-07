<?php

/**
 * Sieve Rule Evaluator
 *
 * Evaluates Sieve filter rules against message headers.
 * Works with the parsed rule format from rcube_sieve_script::as_array().
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
     * Returns the list of actions to apply, or empty array if no match.
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

        foreach ($rules as $rule) {
            // Skip disabled rules
            if (!empty($rule['disabled'])) {
                continue;
            }

            // Evaluate the tests
            if (!isset($rule['tests']) || !is_array($rule['tests'])) {
                continue;
            }

            $match = $this->_evaluate_tests($rule, $headers, $size);

            if ($match) {
                $actions = $this->_extract_actions($rule);
                if (!empty($actions)) {
                    return $actions;
                }
                // If all actions were filtered out (redirect/reject), continue to next rule
            }

            // Check for stop
            if ($match && !empty($rule['actions'])) {
                foreach ($rule['actions'] as $action) {
                    if (isset($action['type']) && $action['type'] === 'stop') {
                        return [];
                    }
                }
            }
        }

        return [];
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
                // Envelope tests can't be evaluated retroactively (no envelope data)
                // Fall back to address test on From/To headers
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
                    foreach ($test['tests'] as $sub) {
                        if (!$this->_evaluate_test($sub, $headers, $size)) {
                            $result = false;
                            break 2;
                        }
                    }
                    $result = true;
                }
                break;

            case 'anyof':
                if (isset($test['tests'])) {
                    foreach ($test['tests'] as $sub) {
                        if ($this->_evaluate_test($sub, $headers, $size)) {
                            $result = true;
                            break 2;
                        }
                    }
                    $result = false;
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
     * Test a header value.
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

            $value = is_array($headers[$name]) ? implode(', ', $headers[$name]) : $headers[$name];

            foreach ($keys as $pattern) {
                if ($this->_match_value($value, $pattern, $match_type)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Test an address header, extracting the specified part.
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

            $value = is_array($headers[$name]) ? implode(', ', $headers[$name]) : $headers[$name];

            // Extract individual addresses
            $addresses = $this->_parse_addresses($value);

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

        return false;
    }

    /**
     * Parse email addresses from a header value.
     *
     * @param string $value Header value (may contain multiple addresses)
     * @return array List of email addresses
     */
    private function _parse_addresses($value)
    {
        $addresses = [];

        // Match email addresses in angle brackets or bare addresses
        if (preg_match_all('/[\w.+-]+@[\w.-]+\.\w+/', $value, $matches)) {
            $addresses = $matches[0];
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
     * Test that specified headers exist.
     *
     * @param array $test    Test with 'arg' (header names)
     * @param array $headers Normalized headers
     * @return bool
     */
    private function _test_exists(array $test, array $headers)
    {
        $header_names = isset($test['arg']) ? (array) $test['arg'] : [];

        foreach ($header_names as $name) {
            if (!isset($headers[strtolower($name)])) {
                return false;
            }
        }

        return !empty($header_names);
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
                return stripos($value, $pattern) !== false;

            case 'matches':
                // Sieve 'matches' uses * and ? wildcards
                // Convert to regex: * → .*, ? → .
                $regex = '/^' . preg_quote($pattern, '/') . '$/i';
                $regex = str_replace([preg_quote('*', '/'), preg_quote('?', '/')], ['.*', '.'], $regex);
                return (bool) preg_match($regex, $value);

            case 'regex':
                // Pattern is already a regex
                $delimited = '/' . str_replace('/', '\\/', $pattern) . '/i';
                return (bool) @preg_match($delimited, $value);

            default:
                return false;
        }
    }

    /**
     * Extract actions from a rule, filtering unsafe ones.
     *
     * @param array $rule Rule definition with 'actions'
     * @return array List of actions [{type, target}, ...]
     */
    public function _extract_actions(array $rule)
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
                case 'setflag':
                    $target = isset($action['target']) ? $action['target'] : null;
                    if ($target) {
                        $result[] = ['type' => 'addflag', 'target' => $target];
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
                    // Stop is handled at the rule evaluation level
                    break;

                case 'redirect':
                    if (!$this->skip_redirect) {
                        $target = isset($action['target']) ? $action['target'] : null;
                        if ($target) {
                            $result[] = ['type' => 'redirect', 'target' => $target];
                        }
                    }
                    // Silently skip redirect in retroactive mode
                    break;

                case 'reject':
                case 'ereject':
                    if (!$this->skip_reject) {
                        $result[] = ['type' => 'reject', 'target' => null];
                    }
                    // Silently skip reject in retroactive mode
                    break;

                default:
                    // Unknown action type → skip
                    break;
            }
        }

        return $result;
    }
}
