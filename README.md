# sievefilter_apply - Apply Sieve Filters to Existing Messages

A Roundcube plugin that retroactively applies Sieve filter rules to existing messages in a mailbox folder.

Sieve filters only apply to **new incoming messages**. This plugin bridges the gap by letting users run their existing Sieve rules against messages already in their mailbox, similar to Thunderbird's "Run Filters on Folder" or Zimbra's "Apply Filter" feature.

## Features

### Mail View
- **Toolbar button** to apply filters to the current folder
- **Filter selection** dialog with checkboxes to choose which rules to apply
- **Preview mode** showing a summary of planned actions before execution
- **Detailed view** listing each affected message with its action
- **Result summary** with per-folder breakdown (moved, flagged, deleted)

### ManageSieve Settings Integration
- **"Apply to folder" button** in the Sieve filter management toolbar
- Apply a single selected filter to any folder via a folder picker dialog

### Safety & Security
- **Preview before execute** workflow prevents accidental bulk operations
- **Server-side re-evaluation** on execute: the server re-evaluates all Sieve rules against message headers before applying actions, preventing any client-side tampering
- **Mailbox validation**: the target folder is validated against the user's subscribed folder list before any operation
- **Redirect actions are skipped** by default to prevent email storms
- **Reject actions are skipped** by default (meaningless for received messages)
- **Configurable message limit** to avoid runaway operations on large folders
- **ReDoS protection**: regex patterns from Sieve rules are executed with backtracking limits
- **No information leakage**: internal server errors are logged but never exposed to the browser
- **XSS prevention**: all user-supplied content is escaped before DOM insertion
- **CSRF protection**: all requests use Roundcube's built-in request token mechanism

## Requirements

| Component | Version |
|-----------|---------|
| Roundcube | >= 1.6 |
| PHP | >= 7.4 |
| `managesieve` plugin | Enabled and configured |
| Dovecot ManageSieve | Accessible (port 4190) |

This plugin is a **companion** to the built-in `managesieve` plugin. It reuses its libraries (`rcube_sieve`, `rcube_sieve_script`) and connection settings without any code duplication.

## Installation

### Manual

1. Copy the `sievefilter_apply` directory to your Roundcube `plugins/` folder:

```bash
cp -r sievefilter_apply /path/to/roundcube/plugins/
```

2. Add the plugin to your Roundcube configuration (`config/config.inc.php`):

```php
$config['plugins'] = [
    // ...
    'managesieve',       // Required dependency
    'sievefilter_apply',
];
```

3. Optionally copy and edit the configuration file:

```bash
cp plugins/sievefilter_apply/config.inc.php.dist plugins/sievefilter_apply/config.inc.php
```

### Composer

```bash
composer require altzone/sievefilter-apply
```

Then enable the plugin in your Roundcube configuration as shown above.

## Configuration

All settings are optional. Defaults are designed for safe operation.

```php
// Maximum number of messages to process per operation (default: 500)
$config['sievefilter_apply_max_messages'] = 500;

// IMAP header fetch batch size (default: 50)
$config['sievefilter_apply_batch_size'] = 50;

// Skip redirect actions in retroactive mode (default: true)
// Prevents email storms when applying filters to existing messages
$config['sievefilter_apply_skip_redirect'] = true;

// Skip reject/ereject actions in retroactive mode (default: true)
// Reject is meaningless for already-received messages
$config['sievefilter_apply_skip_reject'] = true;
```

## Supported Sieve Features

### Test Types

| Test | Support | Notes |
|------|---------|-------|
| `header` | Full | All standard headers, multi-value tested individually per RFC 5228 |
| `address` | Full | Supports `:all`, `:localpart`, `:domain` parts; uses RFC 5321-compliant address parser |
| `envelope` | Partial | Falls back to address test on From/To headers (see Limitations) |
| `size` | Full | `:over` and `:under` comparators with K/M/G suffixes |
| `exists` | Full | Tests header presence (not emptiness) |
| `allof` | Full | Nested AND logic with short-circuit evaluation |
| `anyof` | Full | Nested OR logic with short-circuit evaluation |
| `not` | Full | Negation |
| `true` / `false` | Full | |
| `body` | Not supported | Requires message body download |
| `date` | Not supported | |

### Match Types

| Type | Support | Notes |
|------|---------|-------|
| `is` | Full | Exact case-insensitive match |
| `contains` | Full | Substring search, case-insensitive |
| `matches` | Full | Sieve wildcards (`*` and `?`) with proper escape handling (`\*`, `\?`) |
| `regex` | Full | PCRE matching with ReDoS backtracking protection |

### Actions

| Action | Support | Notes |
|--------|---------|-------|
| `fileinto` | Full | Creates target folder if it does not exist |
| `discard` | Full | Deletes the message |
| `addflag` | Full | Adds IMAP flag (RFC 5232) |
| `setflag` | Full | Sets IMAP flag (RFC 5232, distinct from addflag) |
| `removeflag` | Full | Removes IMAP flag |
| `keep` | Full | No action (message stays) |
| `stop` | Full | Stops rule evaluation |
| `redirect` | Skipped | Disabled by default to prevent email storms |
| `reject` / `ereject` | Skipped | Disabled by default (message already received) |

### Rule Evaluation

Per RFC 5228, **all matching rules fire** and their actions accumulate, unless an explicit `stop` command halts evaluation. This matches the behavior of standard Sieve implementations.

### Flag Mapping (Sieve to IMAP)

| Sieve Flag | IMAP Flag |
|------------|-----------|
| `\Seen` | SEEN |
| `\Answered` | ANSWERED |
| `\Flagged` | FLAGGED |
| `\Deleted` | DELETED |
| `\Draft` | DRAFT |
| `$Forwarded` | FORWARDED |
| `$MDNSent` | MDNSENT |

## Architecture

```
sievefilter_apply (companion plugin)
        |
        |--- reuses ---> managesieve/lib/rcube_sieve.php        (connection)
        |--- reuses ---> managesieve/lib/rcube_sieve_script.php (parsing)
        |
        |--- Phase 1: Connect to Sieve, fetch & parse active script
        |--- Phase 2: Fetch IMAP headers in batches, evaluate rules
        |--- Phase 3: Preview results to user for confirmation
        |--- Phase 4: Re-evaluate rules server-side, execute IMAP actions
```

The plugin uses the same connection parameters as `managesieve` (`managesieve_host`, `managesieve_port`, `managesieve_usetls`, `managesieve_conn_options`, etc.). No additional server configuration is required.

### Security Model

- **No client trust**: the execute phase re-evaluates Sieve rules server-side against each message's actual headers. The client-supplied action list is only used to identify which UIDs to process.
- **Mailbox validation**: every mailbox parameter is validated against the user's subscribed folder list before any IMAP operation.
- **Credentials**: uses Roundcube's session API (`get_user_name()`, `get_user_password()`) - no direct `$_SESSION` access.
- **Input sanitization**: all user input goes through `rcube_utils::get_input_value()`.
- **CSRF**: inherits Roundcube's request token protection via `rcmail.http_post()`.

## Known Limitations

1. **Envelope tests** fall back to address tests on From/To headers since SMTP envelope data is not available retroactively. This may produce false positives for forwarded mail or mailing lists.
2. **Body and date tests** are not supported as they require downloading full message content.
3. **Maximum 500 messages** per operation by default. Increase `sievefilter_apply_max_messages` for larger folders, at the cost of longer processing time.
4. **No background processing**. Analysis and execution happen synchronously; the UI is blocked during the operation.
5. **No incremental processing**. If execution fails mid-batch, successfully processed messages are not rolled back.
6. **MIME header decoding** is applied before rule evaluation. This matches the behavior of Dovecot's Sieve implementation but may differ from other implementations.
7. **Headers are fetched individually** per message. Bulk IMAP FETCH is not used, which may be slow on very large folders over high-latency connections.

## Localization

The plugin ships with English (`en_US`) and French (`fr_FR`) translations. Additional translations can be added by creating files in the `localization/` directory following the same format.

## License

GNU General Public License v3.0 (GPLv3) - compatible with Roundcube and the managesieve plugin.

## Credits

Developed as a companion plugin to Roundcube's built-in [managesieve](https://plugins.roundcube.net/packages/roundcube/managesieve) plugin.
