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
    public $task = 'mail';

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
        $this->add_texts('localization/');

        // Include JavaScript and CSS
        if ($this->rc->output && $this->rc->output->type === 'html') {
            $this->include_script('sievefilter_apply.js');
            $this->include_stylesheet($this->local_skin_path() . '/sievefilter_apply.css');

            // Add toolbar button
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

        // Register actions
        $this->register_action('plugin.sievefilter-apply-preview', [$this, 'action_preview']);
        $this->register_action('plugin.sievefilter-apply-execute', [$this, 'action_execute']);
    }

    /**
     * Preview action: analyze messages and return planned actions.
     */
    public function action_preview()
    {
        $mbox = rcube_utils::get_input_value('_mbox', rcube_utils::INPUT_POST);

        if (empty($mbox)) {
            $this->rc->output->command('plugin.sievefilter_apply_error',
                ['message' => $this->gettext('error_no_folder')]);
            $this->rc->output->send();
            return;
        }

        try {
            // Get Sieve rules
            $rules = $this->_get_sieve_rules();

            if (empty($rules)) {
                $this->rc->output->command('plugin.sievefilter_apply_error',
                    ['message' => $this->gettext('error_no_rules')]);
                $this->rc->output->send();
                return;
            }

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
                            'actions' => $msg_actions,
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
                ['message' => $this->gettext('error_sieve_connect') . ': ' . $e->getMessage()]);
        }

        $this->rc->output->send();
    }

    /**
     * Execute action: apply confirmed actions to messages.
     */
    public function action_execute()
    {
        $mbox    = rcube_utils::get_input_value('_mbox', rcube_utils::INPUT_POST);
        $actions = rcube_utils::get_input_value('_actions', rcube_utils::INPUT_POST);

        if (empty($mbox) || empty($actions)) {
            $this->rc->output->command('plugin.sievefilter_apply_error',
                ['message' => $this->gettext('error_no_actions')]);
            $this->rc->output->send();
            return;
        }

        if (is_string($actions)) {
            $actions = json_decode($actions, true);
        }

        if (!is_array($actions)) {
            $this->rc->output->command('plugin.sievefilter_apply_error',
                ['message' => $this->gettext('error_invalid_actions')]);
            $this->rc->output->send();
            return;
        }

        $storage = $this->rc->get_storage();
        $storage->set_folder($mbox);

        $success = 0;
        $errors  = 0;

        // Group actions by type and target for batch processing
        $grouped = [];
        foreach ($actions as $action) {
            if (!isset($action['uid']) || !isset($action['actions'])) {
                continue;
            }

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

        // Execute grouped actions
        foreach ($grouped as $group) {
            $uids   = $group['uids'];
            $type   = $group['type'];
            $target = $group['target'];

            if (empty($uids)) {
                continue;
            }

            $uid_str = implode(',', $uids);

            switch ($type) {
                case 'fileinto':
                    if (empty($target)) {
                        $errors += count($uids);
                        break;
                    }
                    // Verify target folder exists, create if needed
                    $folders = $storage->list_folders();
                    if (!in_array($target, $folders)) {
                        if (!$storage->create_folder($target, true)) {
                            $errors += count($uids);
                            break;
                        }
                    }
                    if ($storage->move_message($uids, $target, $mbox)) {
                        $success += count($uids);
                    } else {
                        $errors += count($uids);
                    }
                    break;

                case 'discard':
                    if ($storage->delete_message($uids, $mbox)) {
                        $success += count($uids);
                    } else {
                        $errors += count($uids);
                    }
                    break;

                case 'addflag':
                case 'setflag':
                    $flag = $this->_sieve_flag_to_imap($target);
                    if ($flag && $storage->set_flag($uids, $flag, $mbox)) {
                        $success += count($uids);
                    } else {
                        $errors += count($uids);
                    }
                    break;

                case 'removeflag':
                    $flag = $this->_sieve_flag_to_imap($target);
                    if ($flag && $storage->unset_flag($uids, $flag, $mbox)) {
                        $success += count($uids);
                    } else {
                        $errors += count($uids);
                    }
                    break;

                case 'keep':
                    // Do nothing
                    $success += count($uids);
                    break;

                case 'redirect':
                    // Skip redirect in retroactive mode
                    break;

                case 'reject':
                case 'ereject':
                    // Skip reject in retroactive mode
                    break;

                default:
                    $errors += count($uids);
                    break;
            }
        }

        $result = [
            'success' => $success,
            'errors'  => $errors,
            'mbox'    => $mbox,
        ];

        $this->rc->output->command('plugin.sievefilter_apply_execute_result', $result);
        $this->rc->output->send();
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
            // Try to get the first available script
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

        // Parse the script into rules
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
        $port = $this->rc->config->get('managesieve_port', 4190);
        $usetls = $this->rc->config->get('managesieve_usetls', true);
        $auth_type = $this->rc->config->get('managesieve_auth_type');

        // Get IMAP credentials from session
        $user = $_SESSION['username'];
        $pass = $this->rc->decrypt($_SESSION['password']);

        // Resolve host if it's a variable
        if ($host === '%h') {
            $host = $_SESSION['storage_host'] ?? $this->rc->config->get('default_host', 'localhost');
        }

        $this->sieve = new rcube_sieve(
            $user,
            $pass,
            $host,
            $port,
            $auth_type,
            $usetls,
            [],                    // disabled extensions
            $this->rc->config->get('managesieve_debug', false),
            $this->rc->config->get('managesieve_auth_cid'),
            $this->rc->config->get('managesieve_auth_pw')
        );

        if ($this->sieve->error()) {
            $error = $this->sieve->error();
            throw new Exception("Sieve connection failed: $error");
        }

        return $this->sieve;
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

            // Build headers array for evaluation
            $hdrs = [];
            if (!empty($headers->from))    $hdrs['from']       = $headers->from;
            if (!empty($headers->to))      $hdrs['to']         = $headers->to;
            if (!empty($headers->cc))      $hdrs['cc']         = $headers->cc;
            if (!empty($headers->bcc))     $hdrs['bcc']        = $headers->bcc;
            if (!empty($headers->subject)) $hdrs['subject']    = $headers->subject;
            if (!empty($headers->replyto)) $hdrs['reply-to']   = $headers->replyto;
            if (!empty($headers->date))    $hdrs['date']       = $headers->date;
            if (!empty($headers->others)) {
                foreach ($headers->others as $key => $val) {
                    $hdrs[strtolower($key)] = $val;
                }
            }
            // List-Id header (commonly used in mailing list filters)
            if (isset($headers->list_id)) {
                $hdrs['list-id'] = $headers->list_id;
            }

            $result[$uid] = [
                'headers' => $hdrs,
                'size'    => isset($headers->size) ? (int) $headers->size : 0,
                'subject' => isset($headers->subject) ? mb_substr($headers->subject, 0, 80) : '',
                'from'    => isset($headers->from) ? $headers->from : '',
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
