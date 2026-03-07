/**
 * Sieve Filter Apply - Roundcube Plugin JavaScript
 *
 * Two-step workflow:
 * 1. Preview: analyze messages and show planned actions
 * 2. Execute: apply confirmed actions
 */
if (window.rcmail) {
    rcmail.addEventListener('init', function () {
        // Register the command
        rcmail.register_command('plugin.sievefilter-apply', function () {
            sievefilter_apply_preview();
        }, true);

        // Enable the button when a folder is selected
        rcmail.addEventListener('selectfolder', function (props) {
            rcmail.enable_command('plugin.sievefilter-apply', !!props);
        });

        // Register response handlers
        rcmail.addEventListener('plugin.sievefilter_apply_preview_result', function (data) {
            sievefilter_apply_show_preview(data);
        });

        rcmail.addEventListener('plugin.sievefilter_apply_execute_result', function (data) {
            sievefilter_apply_show_result(data);
        });

        rcmail.addEventListener('plugin.sievefilter_apply_error', function (data) {
            rcmail.hide_message();
            rcmail.display_message(data.message, 'error');
        });
    });
}

/**
 * Step 1: Request preview of filter actions
 */
function sievefilter_apply_preview() {
    var mbox = rcmail.env.mailbox;

    if (!mbox) {
        rcmail.display_message(rcmail.gettext('sievefilter_apply.error_no_folder'), 'error');
        return;
    }

    rcmail.display_message(rcmail.gettext('sievefilter_apply.analyzing'), 'loading');

    rcmail.http_post('plugin.sievefilter-apply-preview', {
        _mbox: mbox
    });
}

/**
 * Step 2: Show preview dialog with summary
 */
function sievefilter_apply_show_preview(data) {
    rcmail.hide_message();

    if (!data || !data.actions || data.actions.length === 0) {
        rcmail.display_message(rcmail.gettext('sievefilter_apply.no_matches'), 'notice');
        return;
    }

    // Build summary HTML
    var html = '<div class="sievefilter-apply-preview">';

    // Header
    html += '<p class="summary-header">';
    if (data.truncated) {
        html += rcmail.gettext('sievefilter_apply.preview_header_truncated')
            .replace('%d', data.analyzed)
            .replace('%t', data.total)
            .replace('%s', data.mbox);
    } else {
        html += rcmail.gettext('sievefilter_apply.preview_header')
            .replace('%d', data.analyzed)
            .replace('%s', data.mbox);
    }
    html += '</p>';

    // Action summary
    html += '<ul class="action-summary">';
    for (var desc in data.summary) {
        html += '<li><strong>' + data.summary[desc] + '</strong> &rarr; '
            + rcmail.html_encode(desc) + '</li>';
    }
    if (data.no_action > 0) {
        html += '<li class="no-action"><strong>' + data.no_action + '</strong> &rarr; '
            + rcmail.gettext('sievefilter_apply.no_action') + '</li>';
    }
    html += '</ul>';

    html += '</div>';

    // Create dialog
    var dialog = $('<div>').html(html);

    var buttons = {};
    buttons[rcmail.gettext('sievefilter_apply.apply')] = function () {
        $(this).dialog('close');
        sievefilter_apply_execute(data.mbox, data.actions);
    };
    buttons[rcmail.gettext('sievefilter_apply.cancel')] = function () {
        $(this).dialog('close');
    };

    dialog.dialog({
        title: rcmail.gettext('sievefilter_apply.dialog_title'),
        modal: true,
        resizable: true,
        width: 500,
        close: function () {
            $(this).dialog('destroy').remove();
        },
        buttons: buttons
    });
}

/**
 * Step 3: Execute confirmed actions
 */
function sievefilter_apply_execute(mbox, actions) {
    rcmail.display_message(rcmail.gettext('sievefilter_apply.applying'), 'loading');

    rcmail.http_post('plugin.sievefilter-apply-execute', {
        _mbox: mbox,
        _actions: JSON.stringify(actions)
    });
}

/**
 * Step 4: Show execution result
 */
function sievefilter_apply_show_result(data) {
    rcmail.hide_message();

    if (data.errors > 0) {
        rcmail.display_message(
            rcmail.gettext('sievefilter_apply.result_with_errors')
                .replace('%s', data.success)
                .replace('%e', data.errors),
            'warning'
        );
    } else {
        rcmail.display_message(
            rcmail.gettext('sievefilter_apply.result_success')
                .replace('%s', data.success),
            'confirmation'
        );
    }

    // Refresh message list
    rcmail.command('list', data.mbox);
}
