/**
 * Sieve Filter Apply - Roundcube Plugin JavaScript
 *
 * Two modes:
 * A) Mail view: toolbar button → select filters → preview → execute
 * B) ManageSieve settings: "Apply to folder" button per selected filter
 */

var sievefilter_apply_lock = null;
var sievefilter_apply_selected_rules = null;

if (window.rcmail) {
    rcmail.addEventListener('init', function () {
        // Register the command (mail toolbar)
        rcmail.register_command('plugin.sievefilter-apply', function () {
            sievefilter_apply_start();
        }, true);

        // Register command for managesieve "apply single rule to folder"
        rcmail.register_command('plugin.sievefilter-apply-single', function () {
            sievefilter_apply_single();
        }, false);

        // Register response handlers
        rcmail.addEventListener('plugin.sievefilter_apply_rules_list', function (data) {
            sievefilter_apply_unlock();
            sievefilter_apply_show_rules(data);
        });

        rcmail.addEventListener('plugin.sievefilter_apply_preview_result', function (data) {
            sievefilter_apply_unlock();
            sievefilter_apply_show_preview(data);
        });

        rcmail.addEventListener('plugin.sievefilter_apply_execute_result', function (data) {
            sievefilter_apply_unlock();
            sievefilter_apply_show_result(data);
        });

        rcmail.addEventListener('plugin.sievefilter_apply_error', function (data) {
            sievefilter_apply_unlock();
            rcmail.display_message(data.message, 'error');
        });

        // ManageSieve integration: add "Apply" button when on filters page
        if (rcmail.env.task === 'settings' && rcmail.env.action === 'plugin.managesieve') {
            sievefilter_apply_add_managesieve_button();
        }
    });
}

/** Lock/unlock helpers using Roundcube's set_busy */
function sievefilter_apply_lock_ui(msg) {
    sievefilter_apply_lock = rcmail.set_busy(true, msg);
}

function sievefilter_apply_unlock() {
    if (sievefilter_apply_lock) {
        rcmail.set_busy(false, null, sievefilter_apply_lock);
        sievefilter_apply_lock = null;
    }
}

/* =========================================================
 * Mode A: Mail view - apply selected filters to current folder
 * ========================================================= */

function sievefilter_apply_start() {
    var mbox = rcmail.env.mailbox;

    if (!mbox) {
        rcmail.display_message(rcmail.gettext('sievefilter_apply.error_no_folder'), 'error');
        return;
    }

    sievefilter_apply_lock_ui('sievefilter_apply.loading_rules');

    rcmail.http_post('plugin.sievefilter-apply-list-rules', {
        _mbox: mbox
    });
}

function sievefilter_apply_show_rules(data) {
    if (!data || !data.rules || data.rules.length === 0) {
        rcmail.display_message(rcmail.gettext('sievefilter_apply.error_no_rules'), 'error');
        return;
    }

    var html = '<div class="sievefilter-apply-rules">';
    html += '<p>' + rcmail.gettext('sievefilter_apply.select_rules') + '</p>';

    html += '<div class="sievefilter-apply-selectall">';
    html += '<a href="#" onclick="$(\'input.sieve-rule-cb\').prop(\'checked\', true); return false;">'
        + rcmail.gettext('sievefilter_apply.select_all') + '</a>';
    html += ' | <a href="#" onclick="$(\'input.sieve-rule-cb\').prop(\'checked\', false); return false;">'
        + rcmail.gettext('sievefilter_apply.select_none') + '</a>';
    html += '</div>';

    html += '<div class="sievefilter-apply-rulelist">';
    for (var i = 0; i < data.rules.length; i++) {
        var rule = data.rules[i];
        var disabled_class = rule.disabled ? ' rule-disabled' : '';
        var checked = rule.disabled ? '' : ' checked';

        html += '<label class="sieve-rule-item' + disabled_class + '">';
        html += '<input type="checkbox" class="sieve-rule-cb" value="' + rule.index + '"' + checked + '>';
        html += '<span class="rule-name">' + rcmail.quote_html(rule.name) + '</span>';
        if (rule.actions) {
            html += '<span class="rule-action">' + rcmail.quote_html(rule.actions) + '</span>';
        }
        if (rule.disabled) {
            html += '<span class="rule-badge-disabled">' + rcmail.gettext('sievefilter_apply.disabled') + '</span>';
        }
        html += '</label>';
    }
    html += '</div></div>';

    var mbox = rcmail.env.mailbox;

    var buttons = [
        {
            text: rcmail.gettext('sievefilter_apply.analyze'),
            'class': 'mainaction',
            click: function () {
                var selected = [];
                $('input.sieve-rule-cb:checked').each(function () {
                    selected.push(parseInt($(this).val(), 10));
                });

                if (selected.length === 0) {
                    rcmail.display_message(rcmail.gettext('sievefilter_apply.error_no_selection'), 'warning');
                    return;
                }

                $(this).dialog('close');
                sievefilter_apply_preview(mbox, selected);
            }
        },
        {
            text: rcmail.gettext('sievefilter_apply.cancel'),
            'class': 'cancel',
            click: function () { $(this).dialog('close'); }
        }
    ];

    rcmail.show_popup_dialog(html, rcmail.gettext('sievefilter_apply.dialog_title'), buttons, {
        width: 550, modal: true
    });
}

/* =========================================================
 * Mode B: ManageSieve settings - apply selected rule to chosen folder
 * ========================================================= */

function sievefilter_apply_add_managesieve_button() {
    var toolbar = $('#toolbar-menu');

    if (!toolbar.length) {
        toolbar = $('#layout-list .header .toolbar').first();
    }

    if (!toolbar.length) {
        return;
    }

    var btn = $('<a>')
        .attr({
            href: '#',
            id: 'sievefilter-apply-single-btn',
            role: 'button',
            title: rcmail.gettext('sievefilter_apply.apply_to_folder'),
            'class': 'button sievefilter-apply disabled'
        })
        .html('<span class="inner">' + rcmail.gettext('sievefilter_apply.apply_to_folder') + '</span>')
        .on('click', function (e) {
            e.preventDefault();
            if (!$(this).hasClass('disabled')) {
                rcmail.command('plugin.sievefilter-apply-single');
            }
        });

    toolbar.append(btn);

    if (rcmail.filters_list) {
        rcmail.filters_list.addEventListener('select', function () {
            rcmail.enable_command('plugin.sievefilter-apply-single', true);
            $('#sievefilter-apply-single-btn').removeClass('disabled');
        });
    }
}

function sievefilter_apply_single() {
    var selected = rcmail.filters_list ? rcmail.filters_list.get_single_selection() : null;

    if (!selected) {
        rcmail.display_message(rcmail.gettext('sievefilter_apply.error_no_selection'), 'warning');
        return;
    }

    var filter_index = selected.replace(/^rcmrow/, '');

    var folders = rcmail.env.sievefilter_apply_folders || [];
    var delimiter = rcmail.env.delimiter || '.';

    var html = '<div class="sievefilter-apply-folderpicker">';
    html += '<p>' + rcmail.gettext('sievefilter_apply.choose_folder') + '</p>';
    html += '<ul class="sievefilter-folder-list" id="sievefilter-apply-folder">';
    for (var i = 0; i < folders.length; i++) {
        var folder = folders[i];
        var delimRegex = new RegExp('\\' + delimiter, 'g');
        var depth = (folder.match(delimRegex) || []).length;
        var name = folder.split(delimiter).pop();
        var active = (folder === 'INBOX') ? ' active' : '';
        html += '<li class="folder-item' + active + '" data-folder="' + rcmail.quote_html(folder) + '"'
            + ' style="padding-left:' + (0.5 + depth * 1.2) + 'em">'
            + rcmail.quote_html(name) + '</li>';
    }
    html += '</ul></div>';

    var buttons = [
        {
            text: rcmail.gettext('sievefilter_apply.analyze'),
            'class': 'mainaction',
            click: function () {
                var folder = $('#sievefilter-apply-folder .folder-item.active').data('folder');
                if (!folder) {
                    rcmail.display_message(rcmail.gettext('sievefilter_apply.error_no_folder'), 'warning');
                    return;
                }
                $(this).dialog('close');
                sievefilter_apply_preview(folder, [parseInt(filter_index, 10)]);
            }
        },
        {
            text: rcmail.gettext('sievefilter_apply.cancel'),
            'class': 'cancel',
            click: function () { $(this).dialog('close'); }
        }
    ];

    rcmail.show_popup_dialog(html, rcmail.gettext('sievefilter_apply.apply_to_folder'), buttons, {
        width: 400, modal: true
    });

    // Click handler for folder selection
    $('#sievefilter-apply-folder').on('click', '.folder-item', function () {
        $('#sievefilter-apply-folder .folder-item').removeClass('active');
        $(this).addClass('active');
    });
}

/* =========================================================
 * Shared: preview, execute, result
 * ========================================================= */

function sievefilter_apply_preview(mbox, selected_rules) {
    // Save selected rules for re-sending during execute
    sievefilter_apply_selected_rules = selected_rules;

    sievefilter_apply_lock_ui('sievefilter_apply.analyzing');

    rcmail.http_post('plugin.sievefilter-apply-preview', {
        _mbox: mbox,
        _rules: JSON.stringify(selected_rules)
    });
}

function sievefilter_apply_show_preview(data) {
    if (!data || !data.actions || data.actions.length === 0) {
        rcmail.display_message(rcmail.gettext('sievefilter_apply.no_matches'), 'notice');
        return;
    }

    var html = '<div class="sievefilter-apply-preview">';

    html += '<p class="summary-header">';
    if (data.truncated) {
        html += rcmail.gettext('sievefilter_apply.preview_header_truncated')
            .replace('%d', data.analyzed).replace('%t', data.total)
            .replace('%s', rcmail.quote_html(data.mbox));
    } else {
        html += rcmail.gettext('sievefilter_apply.preview_header')
            .replace('%d', data.analyzed)
            .replace('%s', rcmail.quote_html(data.mbox));
    }
    html += '</p>';

    html += '<ul class="action-summary">';
    for (var desc in data.summary) {
        html += '<li><strong>' + data.summary[desc] + '</strong> &rarr; '
            + rcmail.quote_html(desc) + '</li>';
    }
    if (data.no_action > 0) {
        html += '<li class="no-action"><strong>' + data.no_action + '</strong> &rarr; '
            + rcmail.gettext('sievefilter_apply.no_action') + '</li>';
    }
    html += '</ul>';

    if (data.actions.length > 0 && data.actions.length <= 50) {
        html += '<details class="action-details">';
        html += '<summary>' + rcmail.gettext('sievefilter_apply.show_details') + '</summary>';
        html += '<table class="action-detail-table"><thead><tr>';
        html += '<th>' + rcmail.gettext('sievefilter_apply.col_from') + '</th>';
        html += '<th>' + rcmail.gettext('sievefilter_apply.col_subject') + '</th>';
        html += '<th>' + rcmail.gettext('sievefilter_apply.col_action') + '</th>';
        html += '</tr></thead><tbody>';
        for (var i = 0; i < data.actions.length; i++) {
            var a = data.actions[i];
            html += '<tr><td>' + rcmail.quote_html(a.from) + '</td>';
            html += '<td>' + rcmail.quote_html(a.subject) + '</td>';
            html += '<td>' + rcmail.quote_html(a.desc) + '</td></tr>';
        }
        html += '</tbody></table></details>';
    }

    html += '</div>';

    var buttons = [
        {
            text: rcmail.gettext('sievefilter_apply.apply'),
            'class': 'mainaction delete',
            click: function () {
                $(this).dialog('close');
                sievefilter_apply_execute(data.mbox, data.actions);
            }
        },
        {
            text: rcmail.gettext('sievefilter_apply.cancel'),
            'class': 'cancel',
            click: function () { $(this).dialog('close'); }
        }
    ];

    rcmail.show_popup_dialog(html, rcmail.gettext('sievefilter_apply.dialog_title_preview'), buttons, {
        width: 600, modal: true
    });
}

function sievefilter_apply_execute(mbox, actions) {
    sievefilter_apply_lock_ui('sievefilter_apply.applying');

    rcmail.http_post('plugin.sievefilter-apply-execute', {
        _mbox: mbox,
        _actions: JSON.stringify(actions),
        _rules: sievefilter_apply_selected_rules ? JSON.stringify(sievefilter_apply_selected_rules) : ''
    });
}

function sievefilter_apply_show_result(data) {
    var html = '<div class="sievefilter-apply-result">';

    if (data.errors > 0) {
        html += '<p class="result-status result-warning">'
            + rcmail.gettext('sievefilter_apply.result_with_errors')
                .replace('%s', data.success).replace('%e', data.errors)
            + '</p>';
    } else {
        html += '<p class="result-status result-ok">'
            + rcmail.gettext('sievefilter_apply.result_success')
                .replace('%s', data.success)
            + '</p>';
    }

    html += '<ul class="result-breakdown">';
    if (data.moved > 0) {
        html += '<li>' + rcmail.gettext('sievefilter_apply.result_summary_moved').replace('%d', data.moved) + '</li>';
    }
    if (data.flagged > 0) {
        html += '<li>' + rcmail.gettext('sievefilter_apply.result_summary_flagged').replace('%d', data.flagged) + '</li>';
    }
    if (data.deleted > 0) {
        html += '<li>' + rcmail.gettext('sievefilter_apply.result_summary_deleted').replace('%d', data.deleted) + '</li>';
    }
    html += '</ul>';

    if (data.details && Object.keys(data.details).length > 0) {
        html += '<ul class="result-details">';
        for (var folder in data.details) {
            html += '<li><strong>' + data.details[folder] + '</strong> &rarr; '
                + rcmail.quote_html(folder) + '</li>';
        }
        html += '</ul>';
    }

    html += '</div>';

    var buttons = [
        {
            text: rcmail.gettext('sievefilter_apply.ok'),
            'class': 'mainaction',
            click: function () { $(this).dialog('close'); }
        }
    ];

    rcmail.show_popup_dialog(html, rcmail.gettext('sievefilter_apply.dialog_title_result'), buttons, {
        width: 450, modal: true
    });

    if (rcmail.env.task === 'mail' && data.mbox) {
        rcmail.command('list', data.mbox);
    }
}
