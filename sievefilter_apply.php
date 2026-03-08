<?php

/**
 * Sieve Filter Apply Plugin for Roundcube
 *
 * Retroactively applies Sieve filter rules to existing messages in a mailbox folder.
 * Companion plugin to managesieve - reuses its libraries without duplication.
 *
 * @author Claude Code
 * @license GPLv3
 * @requires managesieve plugin
 */
class sievefilter_apply extends rcube_plugin
{
    public $task = 'mail|settings';

    private $rc;
    private $sieve;

    /**
     * Plugin initialization.
     */
    public function init()
    {
        $this->rc = rcmail::get_instance();

        // Check that managesieve plugin is available
        if (!in_array('managesieve', $this->rc->config->get('plugins', []))) {
            rcube::raise_error([
                'code' => 500,
                'message' => 'sievefilter_apply requires the managesieve plugin to be enabled'
            ], true, false);
            return;
        }

        $this->load_config();
        $this->add_texts('localization/', true);

        // Determine context
        $is_mail = ($this->rc->task === 'mail');
        $is_sieve = ($this->rc->task === 'settings'
            && strpos($this->rc->action, 'plugin.managesieve') === 0);

        // Include JavaScript and CSS in mail or managesieve contexts
        if ($is_mail || $is_sieve) {
            $this->include_script('sievefilter_apply.js');
            $this->include_stylesheet($this->local_skin_path() . '/sievefilter_apply.css');
        }

        // Add toolbar button in mail task
        if ($is_mail && $this->rc->output && $this->rc->output->type === 'html') {
            $this->add_button([
                'command'    => 'plugin.sievefilter-apply',
                'type'       => 'link',
                'class'      => 'button sievefilter-apply',
                'classsel'   => 'button sievefilter-apply selected',
                'innerclass' => 'inner',
                'label'      => 'sievefilter_apply.applysievefilters',
                'title'      => 'sievefilter_apply.applysievefilters_title',
            ], 'toolbar');
        }

        // Inject folder list for the managesieve page
        if ($is_sieve && $this->rc->output) {
            $storage = $this->rc->get_storage();
            $folders = $storage->list_folders_subscribed('', '*', null, null, true);
            $this->rc->output->set_env('sievefilter_apply_folders', $folders);
        }

        // Register actions
        $this->register_action('plugin.sievefilter-apply-list-rules', [$this, 'action_list_rules']);
        $this->register_action('plugin.sievefilter-apply-preview', [$this, 'action_preview']);
        $this->register_action('plugin.sievefilter-apply-execute', [$this, 'action_execute']);
    }

    /**
     * Validate that a mailbox belongs to the current user's subscribed folders.
     *
     * @param string $mbox Mailbox name
     * @return bool
     */
    private function _validate_mbox($mbox)
    {
        if (empty($mbox)) {
            return false;
        }

        $storage = $this->rc->get_storage();
        $folders = $storage->list_folders_subscribed('', '*', null, null, true);

        return in_array($mbox, $folders);
    }

    /**
     * List available Sieve rules for user selection.
     */
    public function action_list_rules()
    {
        try {
            $rules = $this->_get_sieve_rules();
            $rule_list = [];

            foreach ($rules as $idx => $rule) {
                $disabled = !empty($rule['disabled']);
                $name = isset($rule['name']) ? $rule['name'] : $this->gettext('rule') . ' ' . ($idx + 1);

                // Build a human-readable description of the rule
                $desc = '';
                if (!empty($rule['tests'])) {
                    $parts = [];
                    foreach ($rule['tests'] as $test) {
                        $parts[] = $this->_describe_test($test);
                    }
                    $join = !empty($rule['join']) ? ' AND ' : ' OR ';
                    $desc = implode($join, $parts);
                }

                // Describe actions
                $action_desc = '';
                if (!empty($rule['actions'])) {
                    $action_parts = [];
                    foreach ($rule['actions'] as $action) {
                        if ($action['type'] === 'fileinto') {
                            $action_parts[] = '→ ' . $action['target'];
                        } elseif ($action['type'] === 'discard') {
                            $action_parts[] = '→ ' . $this->gettext('action_discard');
                        } elseif ($action['type'] === 'addflag' || $action['type'] === 'setflag') {
                            $action_parts[] = '→ ' . $this->gettext('action_flag') . ' ' . $action['target'];
                        } elseif ($action['type'] === 'redirect') {
                            $action_parts[] = '→ ' . $this->gettext('action_redirect_skipped');
                        } elseif ($action['type'] === 'keep') {
                            $action_parts[] = '→ ' . $this->gettext('action_keep');
                        } elseif ($action['type'] !== 'stop') {
                            $action_parts[] = '→ ' . $action['type'];
                        }
                    }
                    $action_desc = implode(', ', $action_parts);
                }

                $rule_list[] = [
                    'index'    => $idx,
                    'name'     => $name,
                    'desc'     => $desc,
                    'actions'  => $action_desc,
                    'disabled' => $disabled,
                ];
            }

            $this->rc->output->command('plugin.sievefilter_apply_rules_list', [
                'rules' => $rule_list,
            ]);

        } catch (Exception $e) {
            rcube::raise_error($e, true, false);
            $this->rc->output->command('plugin.sievefilter_apply_error',
                ['message' => $this->gettext('error_sieve_connect')]);
        }

        $this->rc->output->send();
    }

    /**
     * Describe a test condition in human-readable form.
     */
    private function _describe_test(array $test)
    {
        if (!isset($test['test'])) {
            return '?';
        }

        switch ($test['test']) {
            case 'header':
            case 'address':
                $headers = isset($test['arg1']) ? (array) $test['arg1'] : [];
                $values  = isset($test['arg2']) ? (array) $test['arg2'] : [];
                $type    = isset($test['type']) ? $test['type'] : 'is';
                $not     = !empty($test['not']) ? 'NOT ' : '';
                return $not . implode(',', $headers) . ' ' . $type . ' "' . implode('","', $values) . '"';

            case 'size':
                $comparator = isset($test['type']) ? $test['type'] : 'over';
                $arg = isset($test['arg']) ? $test['arg'] : '0';
                return 'size ' . $comparator . ' ' . $arg;

            case 'exists':
                $args = isset($test['arg']) ? (array) $test['arg'] : [];
                return 'exists ' . implode(',', $args);

            case 'true':
                return 'true';

            default:
                return $test['test'];
        }
    }

    /**
     * Preview action: analyze messages and return planned actions.
     */
    public function action_preview()
    {
        $mbox = rcube_utils::get_input_value('_mbox', rcube_utils::INPUT_POST);

        // Validate mailbox belongs to the current user
        if (!$this->_validate_mbox($mbox)) {
            $this->rc->output->command('plugin.sievefilter_apply_error',
                ['message' => $this->gettext('error_no_folder')]);
            $this->rc->output->send();
            return;
        }

        // Get selected rule indices (optional filter)
        $selected_rules = rcube_utils::get_input_value('_rules', rcube_utils::INPUT_POST);
        if (is_string($selected_rules)) {
            $selected_rules = json_decode($selected_rules, true);
        }

        try {
            // Get Sieve rules
            $all_rules = $this->_get_sieve_rules();

            if (empty($all_rules)) {
                $this->rc->output->command('plugin.sievefilter_apply_error',
                    ['message' => $this->gettext('error_no_rules')]);
                $this->rc->output->send();
                return;
            }

            // Filter to selected rules if specified
            $rules = $this->_filter_rules($all_rules, $selected_rules);

            // Get messages from the folder
            $storage = $this->rc->get_storage();
            $storage->set_folder($mbox);

            $max_messages = $this->rc->config->get('sievefilter_apply_max_messages', 500);
            $index = $storage->index($mbox, null, null, true);
            $uids  = $index->get();

            if (empty($uids)) {
                $this->rc->output->command('plugin.sievefilter_apply_error',
                    ['message' => $this->gettext('error_empty_folder')]);
                $this->rc->output->send();
                return;
            }

            // Limit number of messages
            $total_count = count($uids);
            if ($total_count > $max_messages) {
                $uids = array_slice($uids, 0, $max_messages);
            }

            // Initialize evaluator
            $skip_redirect = $this->rc->config->get('sievefilter_apply_skip_redirect', true);
            $skip_reject   = $this->rc->config->get('sievefilter_apply_skip_reject', true);

            require_once __DIR__ . '/lib/SieveRuleEvaluator.php';
            $evaluator = new SieveRuleEvaluator($skip_redirect, $skip_reject);

            // Fetch headers and evaluate rules
            $batch_size = $this->rc->config->get('sievefilter_apply_batch_size', 50);
            $actions    = [];
            $summary    = [];

            $batches = array_chunk($uids, $batch_size);
            foreach ($batches as $batch_uids) {
                $headers_batch = $this->_fetch_headers_batch($storage, $mbox, $batch_uids);

                foreach ($headers_batch as $uid => $msg_data) {
                    $msg_headers = $msg_data['headers'];
                    $msg_size    = $msg_data['size'];

                    $msg_actions = $evaluator->evaluate($rules, $msg_headers, $msg_size);

                    if (!empty($msg_actions)) {
                        // Build a description of what will happen
                        $action_desc = $this->_describe_actions($msg_actions);
                        $action_key  = $action_desc;

                        $actions[] = [
                            'uid'     => $uid,
                            'subject' => $msg_data['subject'],
                            'from'    => $msg_data['from'],
                            'desc'    => $action_desc,
                        ];

                        if (!isset($summary[$action_key])) {
                            $summary[$action_key] = 0;
                        }
                        $summary[$action_key]++;
                    }
                }
            }

            $no_action_count = count($uids) - count($actions);

            $result = [
                'mbox'        => $mbox,
                'total'       => $total_count,
                'analyzed'    => count($uids),
                'matched'     => count($actions),
                'no_action'   => $no_action_count,
                'actions'     => $actions,
                'summary'     => $summary,
                'truncated'   => $total_count > $max_messages,
            ];

            $this->rc->output->command('plugin.sievefilter_apply_preview_result', $result);

        } catch (Exception $e) {
            rcube::raise_error($e, true, false);
            $this->rc->output->command('plugin.sievefilter_apply_error',
                ['message' => $this->gettext('error_sieve_connect')]);
        }

        $this->rc->output->send();
    }

    /**
     * Execute action: re-evaluate rules server-side and apply actions.
     *
     * Security: does NOT trust client-supplied action list.
     * Instead, re-evaluates the Sieve rules against each message's headers
     * to prevent tampering with the action payload.
     */
    public function action_execute()
    {
        $mbox = rcube_utils::get_input_value('_mbox', rcube_utils::INPUT_POST);
        $client_actions = rcube_utils::get_input_value('_actions', rcube_utils::INPUT_POST);
        $selected_rules_json = rcube_utils::get_input_value('_rules', rcube_utils::INPUT_POST);

        // Validate mailbox belongs to the current user
        if (!$this->_validate_mbox($mbox)) {
            $this->rc->output->command('plugin.sievefilter_apply_error',
                ['message' => $this->gettext('error_no_folder')]);
            $this->rc->output->send();
            return;
        }

        if (is_string($client_actions)) {
            $client_actions = json_decode($client_actions, true);
        }
        if (is_string($selected_rules_json)) {
            $selected_rules_json = json_decode($selected_rules_json, true);
        }

        if (!is_array($client_actions) || empty($client_actions)) {
            $this->rc->output->command('plugin.sievefilter_apply_error',
                ['message' => $this->gettext('error_no_actions')]);
            $this->rc->output->send();
            return;
        }

        // Extract UIDs from client request (we only trust UIDs, not actions)
        $requested_uids = [];
        foreach ($client_actions as $action) {
            if (isset($action['uid'])) {
                $requested_uids[] = (int) $action['uid'];
            }
        }
        $requested_uids = array_unique($requested_uids);

        if (empty($requested_uids)) {
            $this->rc->output->command('plugin.sievefilter_apply_error',
                ['message' => $this->gettext('error_no_actions')]);
            $this->rc->output->send();
            return;
        }

        try {
            // Re-fetch Sieve rules and re-evaluate server-side
            $all_rules = $this->_get_sieve_rules();
            $rules = $this->_filter_rules($all_rules, $selected_rules_json);

            if (empty($rules)) {
                $this->rc->output->command('plugin.sievefilter_apply_error',
                    ['message' => $this->gettext('error_no_rules')]);
                $this->rc->output->send();
                return;
            }

            $skip_redirect = $this->rc->config->get('sievefilter_apply_skip_redirect', true);
            $skip_reject   = $this->rc->config->get('sievefilter_apply_skip_reject', true);

            require_once __DIR__ . '/lib/SieveRuleEvaluator.php';
            $evaluator = new SieveRuleEvaluator($skip_redirect, $skip_reject);

            $storage = $this->rc->get_storage();
            $storage->set_folder($mbox);

            // Re-evaluate rules for each requested UID
            $batch_size = $this->rc->config->get('sievefilter_apply_batch_size', 50);
            $verified_actions = [];

            $batches = array_chunk($requested_uids, $batch_size);
            foreach ($batches as $batch_uids) {
                $headers_batch = $this->_fetch_headers_batch($storage, $mbox, $batch_uids);

                foreach ($headers_batch as $uid => $msg_data) {
                    $msg_actions = $evaluator->evaluate($rules, $msg_data['headers'], $msg_data['size']);

                    if (!empty($msg_actions)) {
                        $verified_actions[] = [
                            'uid'     => $uid,
                            'actions' => $msg_actions,
                        ];
                    }
                }
            }

            // Group verified actions by type and target for batch IMAP operations
            $grouped = [];
            foreach ($verified_actions as $action) {
                $uid = (int) $action['uid'];
                foreach ($action['actions'] as $act) {
                    $type   = isset($act['type']) ? $act['type'] : '';
                    $target = isset($act['target']) ? $act['target'] : '';
                    $key    = $type . '::' . $target;

                    if (!isset($grouped[$key])) {
                        $grouped[$key] = [
                            'type'   => $type,
                            'target' => $target,
                            'uids'   => [],
                        ];
                    }
                    $grouped[$key]['uids'][] = $uid;
                }
            }

            $success = 0;
            $errors  = 0;
            $moved   = 0;
            $flagged = 0;
            $deleted = 0;
            $details = [];

            // Get list of user's subscribed folders for fileinto target validation
            $user_folders = $storage->list_folders();

            // Execute grouped actions
            foreach ($grouped as $group) {
                $uids   = $group['uids'];
                $type   = $group['type'];
                $target = $group['target'];

                if (empty($uids)) {
                    continue;
                }

                switch ($type) {
                    case 'fileinto':
                        if (empty($target)) {
                            $errors += count($uids);
                            break;
                        }
                        // Validate and create target folder if needed
                        if (!in_array($target, $user_folders)) {
                            if (!$storage->create_folder($target, true)) {
                                $errors += count($uids);
                                break;
                            }
                            // Refresh folder list after creation
                            $user_folders = $storage->list_folders();
                        }
                        if ($storage->move_message($uids, $target, $mbox)) {
                            $cnt = count($uids);
                            $success += $cnt;
                            $moved += $cnt;
                            $details[$target] = ($details[$target] ?? 0) + $cnt;
                        } else {
                            $errors += count($uids);
                        }
                        break;

                    case 'discard':
                        if ($storage->delete_message($uids, $mbox)) {
                            $cnt = count($uids);
                            $success += $cnt;
                            $deleted += $cnt;
                        } else {
                            $errors += count($uids);
                        }
                        break;

                    case 'addflag':
                        $flag = $this->_sieve_flag_to_imap($target);
                        if ($flag && $storage->set_flag($uids, $flag, $mbox)) {
                            $cnt = count($uids);
                            $success += $cnt;
                            $flagged += $cnt;
                        } else {
                            $errors += count($uids);
                        }
                        break;

                    case 'setflag':
                        // setflag replaces flags - use set_flag (best effort, IMAP +FLAGS)
                        $flag = $this->_sieve_flag_to_imap($target);
                        if ($flag && $storage->set_flag($uids, $flag, $mbox)) {
                            $cnt = count($uids);
                            $success += $cnt;
                            $flagged += $cnt;
                        } else {
                            $errors += count($uids);
                        }
                        break;

                    case 'removeflag':
                        $flag = $this->_sieve_flag_to_imap($target);
                        if ($flag && $storage->unset_flag($uids, $flag, $mbox)) {
                            $success += count($uids);
                            $flagged += count($uids);
                        } else {
                            $errors += count($uids);
                        }
                        break;

                    case 'keep':
                        $success += count($uids);
                        break;

                    case 'redirect':
                    case 'reject':
                    case 'ereject':
                        // Skip in retroactive mode
                        break;

                    default:
                        $errors += count($uids);
                        break;
                }
            }

            $result = [
                'success' => $success,
                'errors'  => $errors,
                'moved'   => $moved,
                'flagged' => $flagged,
                'deleted' => $deleted,
                'details' => $details,
                'mbox'    => $mbox,
            ];

            $this->rc->output->command('plugin.sievefilter_apply_execute_result', $result);

        } catch (Exception $e) {
            rcube::raise_error($e, true, false);
            $this->rc->output->command('plugin.sievefilter_apply_error',
                ['message' => $this->gettext('error_sieve_connect')]);
        }

        $this->rc->output->send();
    }

    /**
     * Filter rules by selected indices.
     *
     * @param array      $all_rules All rules from Sieve
     * @param array|null $selected  Selected rule indices (null = all)
     * @return array Filtered rules
     */
    private function _filter_rules(array $all_rules, $selected)
    {
        if (!is_array($selected) || empty($selected)) {
            return $all_rules;
        }

        $rules = [];
        foreach ($selected as $idx) {
            $idx = (int) $idx;
            if (isset($all_rules[$idx])) {
                $rules[] = $all_rules[$idx];
            }
        }

        return $rules;
    }

    /**
     * Get Sieve rules from the active script.
     *
     * @return array Parsed rules
     * @throws Exception on connection failure
     */
    private function _get_sieve_rules()
    {
        // Load managesieve library
        $managesieve_path = dirname(__FILE__) . '/../managesieve/lib/Roundcube/';
        if (file_exists($managesieve_path . 'rcube_sieve.php')) {
            require_once $managesieve_path . 'rcube_sieve.php';
            require_once $managesieve_path . 'rcube_sieve_script.php';
        } else {
            throw new Exception('ManageSieve library not found');
        }

        $sieve = $this->_get_sieve_connection();

        // Get the active script
        $active = $sieve->get_active();
        if (empty($active)) {
            $scripts = $sieve->get_scripts();
            if (empty($scripts)) {
                return [];
            }
            $active = $scripts[0];
        }

        // Load and parse the script
        $script_content = $sieve->get_script($active);
        if (empty($script_content)) {
            return [];
        }

        $script = new rcube_sieve_script($script_content);
        $rules  = $script->as_array();

        return isset($rules['rules']) ? $rules['rules'] : $rules;
    }

    /**
     * Create a Sieve connection using managesieve config and IMAP credentials.
     *
     * @return rcube_sieve
     * @throws Exception on connection failure
     */
    private function _get_sieve_connection()
    {
        if ($this->sieve) {
            return $this->sieve;
        }

        $host = $this->rc->config->get('managesieve_host', 'localhost');
        $host = rcube_utils::parse_host($host);

        // Get IMAP credentials via Roundcube API (not $_SESSION directly)
        $user = $this->rc->get_user_name();
        $pass = $this->rc->get_user_password();

        $auth_type = $this->rc->config->get('managesieve_auth_type');
        $options   = $this->rc->config->get('managesieve_conn_options');

        // Parse URI to extract scheme, host, port (same as managesieve engine)
        list($host, $scheme, $port) = rcube_utils::parse_host_uri($host);

        // tls:// = explicit STARTTLS, ssl:// = implicit SSL
        $tls = ($scheme === 'tls');
        if ($scheme === 'ssl') {
            $host = 'ssl://' . $host;
        }

        if (empty($port)) {
            $port = getservbyname('sieve', 'tcp') ?: 4190;
        }

        $host = rcube_utils::idn_to_ascii($host);

        // Handle per-host socket options
        rcube_utils::parse_socket_options($options, $host);

        $this->sieve = new rcube_sieve(
            $user,
            $pass,
            $host,
            $port,
            $auth_type,
            $tls,
            $this->rc->config->get('managesieve_disabled_extensions', []),
            $this->rc->config->get('managesieve_debug', false),
            $this->rc->config->get('managesieve_auth_cid'),
            $this->rc->config->get('managesieve_auth_pw'),
            $options
        );

        if ($this->sieve->error()) {
            $error = $this->sieve->error();
            throw new Exception("Sieve connection failed: $error");
        }

        return $this->sieve;
    }

    /**
     * Disconnect Sieve connection on plugin destruction.
     */
    public function __destruct()
    {
        if ($this->sieve && method_exists($this->sieve, 'disconnect')) {
            $this->sieve->disconnect();
        }
        $this->sieve = null;
    }

    /**
     * Fetch headers for a batch of message UIDs.
     *
     * @param rcube_storage $storage Storage object
     * @param string        $mbox    Mailbox name
     * @param array         $uids    Message UIDs
     * @return array [uid => ['headers' => [...], 'size' => int, 'subject' => string, 'from' => string]]
     */
    private function _fetch_headers_batch($storage, $mbox, array $uids)
    {
        $result = [];

        foreach ($uids as $uid) {
            $headers = $storage->get_message_headers($uid, $mbox);

            if (!$headers) {
                continue;
            }

            // Build headers array for evaluation (decode MIME-encoded values)
            // Use isset() instead of !empty() to preserve headers with empty string values
            // (important for 'exists' and 'is ""' tests)
            $hdrs = [];
            if (isset($headers->from))    $hdrs['from']       = rcube_mime::decode_header($headers->from);
            if (isset($headers->to))      $hdrs['to']         = rcube_mime::decode_header($headers->to);
            if (isset($headers->cc))      $hdrs['cc']         = rcube_mime::decode_header($headers->cc);
            if (isset($headers->bcc))     $hdrs['bcc']        = rcube_mime::decode_header($headers->bcc);
            if (isset($headers->subject)) $hdrs['subject']    = rcube_mime::decode_header($headers->subject);
            if (isset($headers->replyto)) $hdrs['reply-to']   = rcube_mime::decode_header($headers->replyto);
            if (isset($headers->date))    $hdrs['date']       = $headers->date;
            if (isset($headers->others) && is_array($headers->others)) {
                foreach ($headers->others as $key => $val) {
                    $hdrs[strtolower($key)] = is_string($val) ? rcube_mime::decode_header($val) : $val;
                }
            }
            // List-Id header (commonly used in mailing list filters)
            if (isset($headers->list_id)) {
                $hdrs['list-id'] = $headers->list_id;
            }

            $decoded_subject = isset($headers->subject) ? rcube_mime::decode_header($headers->subject) : '';
            $decoded_from    = isset($headers->from) ? rcube_mime::decode_header($headers->from) : '';

            $result[$uid] = [
                'headers' => $hdrs,
                'size'    => isset($headers->size) ? (int) $headers->size : 0,
                'subject' => mb_substr($decoded_subject, 0, 80),
                'from'    => $decoded_from,
            ];
        }

        return $result;
    }

    /**
     * Describe a set of actions as a human-readable string.
     *
     * @param array $actions List of actions
     * @return string
     */
    private function _describe_actions(array $actions)
    {
        $parts = [];

        foreach ($actions as $action) {
            switch ($action['type']) {
                case 'fileinto':
                    $parts[] = $this->gettext('action_move') . ' ' . $action['target'];
                    break;
                case 'discard':
                    $parts[] = $this->gettext('action_discard');
                    break;
                case 'addflag':
                case 'setflag':
                    $parts[] = $this->gettext('action_flag') . ' ' . $action['target'];
                    break;
                case 'removeflag':
                    $parts[] = $this->gettext('action_removeflag') . ' ' . $action['target'];
                    break;
                case 'keep':
                    $parts[] = $this->gettext('action_keep');
                    break;
                default:
                    $parts[] = $action['type'];
                    break;
            }
        }

        return implode(', ', $parts);
    }

    /**
     * Convert Sieve flag name to IMAP flag.
     *
     * @param string $flag Sieve flag name
     * @return string|false IMAP flag name or false
     */
    private function _sieve_flag_to_imap($flag)
    {
        $map = [
            '\\seen'     => 'SEEN',
            '\\answered' => 'ANSWERED',
            '\\flagged'  => 'FLAGGED',
            '\\deleted'  => 'DELETED',
            '\\draft'    => 'DRAFT',
            '\\recent'   => 'RECENT',
            '$forwarded' => 'FORWARDED',
            '$mdnsent'   => 'MDNSENT',
        ];

        $lower = strtolower($flag);
        return isset($map[$lower]) ? $map[$lower] : false;
    }
}
