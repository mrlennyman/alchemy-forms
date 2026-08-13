<?php
if (!defined('ABSPATH')) exit;

/* -------------------------------------------------------------------------
 * Admin page
 * ---------------------------------------------------------------------- */
add_action('admin_menu', function () {
    add_submenu_page(
        'edit.php?post_type=wa_form',
        __('Import', 'alchemy-forms'),
        __('Import', 'alchemy-forms'),
        'edit_posts',
        'wa-form-import',
        'alchemy_forms_import_page'
    );
});

/**
 * Handles the upload on admin_init (before any HTML has been sent) so a
 * redirect on success/failure actually works — the submenu page callback
 * itself runs too late in WordPress's admin request lifecycle for that.
 */
add_action('admin_init', function () {
    if (!isset($_GET['page']) || $_GET['page'] !== 'wa-form-import') return;
    if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !isset($_FILES['nff_file'])) return;

    if (!current_user_can('edit_posts')) wp_die(esc_html__('You do not have permission to import forms.', 'alchemy-forms'));
    check_admin_referer('alchemy_forms_import');

    $error = alchemy_forms_process_import();

    if ($error) {
        set_transient('alchemy_forms_import_error_' . get_current_user_id(), $error, MINUTE_IN_SECONDS);
        wp_safe_redirect(admin_url('edit.php?post_type=wa_form&page=wa-form-import'));
        exit;
    }
    // alchemy_forms_process_import() redirects to the new draft itself on success.
});

function alchemy_forms_import_page() {
    if (!current_user_can('edit_posts')) wp_die(esc_html__('You do not have permission to import forms.', 'alchemy-forms'));

    $error_key = 'alchemy_forms_import_error_' . get_current_user_id();
    $error     = get_transient($error_key);
    if ($error !== false) delete_transient($error_key);
    ?>
    <div class="wrap">
        <h1><?php esc_html_e('Import from Ninja Forms', 'alchemy-forms'); ?></h1>

        <?php if ($error) : ?>
            <div class="notice notice-error"><p><?php echo esc_html($error); ?></p></div>
        <?php endif; ?>

        <p><?php esc_html_e('Upload a Ninja Forms export (.nff) to create a new Alchemy Forms draft with the same fields, layout, and conditional logic where possible. The new form is saved as a draft so you can review it before publishing.', 'alchemy-forms'); ?></p>

        <div class="notice notice-info inline">
            <p><strong><?php esc_html_e('What carries over', 'alchemy-forms'); ?></strong></p>
            <ul style="list-style:disc;margin-left:1.5em;">
                <li><?php esc_html_e('Text, email, phone, number, date, paragraph, dropdown, radio, and checkbox fields, with their labels, required flags, and options.', 'alchemy-forms'); ?></li>
                <li><?php esc_html_e('Multi-step forms — each page becomes a step, with a progress indicator and Back/Next navigation.', 'alchemy-forms'); ?></li>
                <li><?php esc_html_e('Informational HTML blocks and divider lines, converted to HTML content blocks.', 'alchemy-forms'); ?></li>
                <li><?php esc_html_e('Simple "show this field only if another field equals a value" conditional logic — including on HTML blocks and across steps.', 'alchemy-forms'); ?></li>
                <li><?php esc_html_e('The recipient email, submit button text, and success message.', 'alchemy-forms'); ?></li>
            </ul>
            <p><strong><?php esc_html_e("What doesn't", 'alchemy-forms'); ?></strong></p>
            <ul style="list-style:disc;margin-left:1.5em;">
                <li><?php esc_html_e('Only one recipient email and one condition per field are kept.', 'alchemy-forms'); ?></li>
                <li><?php esc_html_e('Conditions using a comparator other than "equals"/"is not" are skipped (the affected field will always show).', 'alchemy-forms'); ?></li>
            </ul>
        </div>

        <form method="post" enctype="multipart/form-data" style="margin-top:1.5em;">
            <?php wp_nonce_field('alchemy_forms_import'); ?>
            <input type="file" name="nff_file" accept=".nff,.json" required>
            <p class="submit">
                <button type="submit" class="button button-primary"><?php esc_html_e('Import Form', 'alchemy-forms'); ?></button>
            </p>
        </form>
    </div>
    <?php
}

/**
 * Shows a one-time summary of anything dropped/simplified, right after
 * landing on the newly imported draft's edit screen.
 */
add_action('admin_notices', function () {
    $screen = get_current_screen();
    if (!$screen || $screen->post_type !== 'wa_form' || $screen->base !== 'post' || empty($_GET['post'])) return;

    $post_id = (int) $_GET['post'];
    $summary = get_transient('alchemy_forms_import_summary_' . $post_id);
    if ($summary === false) return;
    delete_transient('alchemy_forms_import_summary_' . $post_id);
    ?>
    <div class="notice notice-success is-dismissible">
        <p><strong><?php esc_html_e('Form imported from Ninja Forms.', 'alchemy-forms'); ?></strong> <?php esc_html_e("It's saved as a draft — review it before publishing.", 'alchemy-forms'); ?></p>
        <?php if (!empty($summary)) : ?>
            <p><?php esc_html_e('A few things to double-check:', 'alchemy-forms'); ?></p>
            <ul style="list-style:disc;margin-left:1.5em;">
                <?php foreach ($summary as $line) : ?>
                    <li><?php echo esc_html($line); ?></li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>
    </div>
    <?php
});

/* -------------------------------------------------------------------------
 * Upload handling + mapping
 * ---------------------------------------------------------------------- */

/**
 * Validates the upload, maps it into a new draft wa_form, and redirects to
 * its edit screen. Returns an error string (and does NOT redirect) on failure.
 */
function alchemy_forms_process_import() {
    $file = $_FILES['nff_file'];
    if (empty($file['tmp_name']) || $file['error'] !== UPLOAD_ERR_OK) {
        return __('Please choose a valid .nff file to upload.', 'alchemy-forms');
    }
    if (!is_uploaded_file($file['tmp_name'])) {
        return __('Upload failed — please try again.', 'alchemy-forms');
    }
    if ($file['size'] > 2 * 1024 * 1024) {
        return __('That file is too large to be a Ninja Forms export.', 'alchemy-forms');
    }

    $json = file_get_contents($file['tmp_name']);
    $data = json_decode($json, true);

    if (!is_array($data) || !isset($data['fields']) || !is_array($data['fields']) || !isset($data['settings']) || !is_array($data['settings'])) {
        return __("That doesn't look like a Ninja Forms export — no fields/settings found.", 'alchemy-forms');
    }

    $parts = (isset($data['settings']['formContentData']) && is_array($data['settings']['formContentData'])) ? $data['settings']['formContentData'] : [];

    $result = alchemy_forms_map_nf_import($data, $parts);

    $title = !empty($data['settings']['title']) ? sanitize_text_field($data['settings']['title']) : __('Imported Form', 'alchemy-forms');

    $post_id = wp_insert_post([
        'post_type'   => 'wa_form',
        'post_status' => 'draft',
        'post_title'  => $title,
    ]);
    if (!$post_id || is_wp_error($post_id)) {
        return __('Could not create the new form — please try again.', 'alchemy-forms');
    }

    update_post_meta($post_id, '_wa_form_fields', $result['fields']);
    update_post_meta($post_id, '_wa_form_settings', $result['settings']);
    set_transient('alchemy_forms_import_summary_' . $post_id, $result['summary'], MINUTE_IN_SECONDS * 10);

    wp_safe_redirect(admin_url('post.php?post=' . $post_id . '&action=edit'));
    exit;
}

/**
 * Field types this plugin can represent, keyed by their Ninja Forms type.
 */
function alchemy_forms_nf_type_map() {
    return [
        'firstname'    => 'text',
        'lastname'     => 'text',
        'textbox'      => 'text',
        'address'      => 'text',
        'email'        => 'email',
        'phone'        => 'tel',
        'textarea'     => 'textarea',
        'date'         => 'date',
        'number'       => 'number',
        'listradio'    => 'radio',
        'listcheckbox' => 'checkbox',
        'html'         => 'html',
        'hr'           => 'html',
    ];
}

/**
 * Converts a decoded .nff array into Alchemy Forms' _wa_form_fields/_wa_form_settings
 * shape, plus a plain-language summary of anything dropped or simplified.
 */
function alchemy_forms_map_nf_import($data, $parts) {
    $type_map     = alchemy_forms_nf_type_map();
    $option_types = alchemy_forms_option_field_types();
    $summary      = [];

    // Key => raw NF field lookup, so the layout walk below can pull full field
    // definitions while following visual/part order rather than $data['fields'] order.
    $field_by_key = [];
    foreach ($data['fields'] as $nf_field) {
        if (is_array($nf_field) && !empty($nf_field['key'])) {
            $field_by_key[$nf_field['key']] = $nf_field;
        }
    }

    // Walk parts -> rows -> cells -> field keys, recording each key's part index,
    // cell width, and that part's title (for the page_break inserted at its start).
    $ordered_keys = [];
    foreach ($parts as $part_index => $part) {
        if (empty($part['formContentData']) || !is_array($part['formContentData'])) continue;
        foreach ($part['formContentData'] as $row) {
            if (empty($row['cells']) || !is_array($row['cells'])) continue;
            foreach ($row['cells'] as $cell) {
                if (empty($cell['fields']) || !is_array($cell['fields'])) continue;
                $w = isset($cell['width']) ? (string) $cell['width'] : '100';
                foreach ($cell['fields'] as $fkey) {
                    $ordered_keys[] = [
                        'key'        => $fkey,
                        'part'       => $part_index,
                        'width'      => $w,
                        'part_title' => isset($part['title']) ? $part['title'] : '',
                    ];
                }
            }
        }
    }

    // Defensive: any field present in the export but not placed in any part's
    // layout (shouldn't normally happen) still gets imported, at the end, full width.
    $seen_keys = wp_list_pluck($ordered_keys, 'key');
    foreach ($field_by_key as $key => $nf_field) {
        if (!in_array($key, $seen_keys, true)) {
            $ordered_keys[] = ['key' => $key, 'part' => 0, 'width' => '100', 'part_title' => ''];
        }
    }

    $fields       = [];
    $key_to_uid   = [];
    $key_to_type  = [];
    $key_to_label = [];
    $submit_text  = '';
    $current_part = null;

    foreach ($ordered_keys as $entry) {
        $nf_key = $entry['key'];
        if (!isset($field_by_key[$nf_key])) continue;
        $nf_field = $field_by_key[$nf_key];
        if (empty($nf_field['type'])) continue;

        $nf_type = $nf_field['type'];
        $label   = isset($nf_field['label']) ? sanitize_text_field(wp_strip_all_tags($nf_field['label'])) : '';

        if ($nf_type === 'submit') {
            if ($label !== '') $submit_text = $label;
            continue;
        }

        if (!isset($type_map[$nf_type])) {
            /* translators: 1: field label, 2: Ninja Forms field type */
            $summary[] = sprintf(__('Skipped "%1$s" (%2$s fields aren\'t supported).', 'alchemy-forms'), $label !== '' ? $label : __('(untitled)', 'alchemy-forms'), $nf_type);
            continue;
        }

        // Crossing into a new part: insert the step break that starts it (but
        // not before the very first part — that's just step 0, no marker needed).
        if ($entry['part'] !== $current_part) {
            if ($current_part !== null) {
                $fields[] = [
                    'label' => $entry['part_title'] !== '' ? sanitize_text_field($entry['part_title']) : '',
                    'type'  => 'page_break',
                    'uid'   => wp_generate_uuid4(),
                ];
            }
            $current_part = $entry['part'];
        }

        $type  = $type_map[$nf_type];
        $width = ((int) $entry['width'] < 100) ? 'half' : 'full';
        $uid   = wp_generate_uuid4();
        $key_to_uid[$nf_key]   = $uid;
        $key_to_type[$nf_key]  = $type;
        $key_to_label[$nf_key] = $label !== '' ? $label : ucfirst($type);

        $field = [
            'label'      => $label !== '' ? $label : ucfirst($type),
            'type'       => $type,
            'required'   => !empty($nf_field['required']) ? 1 : 0,
            'hide_label' => 0,
            'width'      => $width,
            'uid'        => $uid,
            '_nf_key'    => $nf_key, // temporary; used below, stripped before returning
        ];

        if ($type === 'html') {
            $field['content'] = ($nf_type === 'hr') ? '<hr>' : wp_kses_post(isset($nf_field['default']) ? $nf_field['default'] : '');
        }

        if (in_array($type, $option_types, true)) {
            $options = [];
            if (!empty($nf_field['options']) && is_array($nf_field['options'])) {
                foreach ($nf_field['options'] as $opt) {
                    if (!is_array($opt)) continue;
                    $opt_label = isset($opt['label']) ? sanitize_text_field($opt['label']) : (isset($opt['value']) ? sanitize_text_field($opt['value']) : '');
                    if ($opt_label !== '') $options[] = $opt_label;
                }
            }
            $field['options'] = $options;
        }

        $fields[] = $field;
    }

    // Resolve conditions now that every imported field has a uid.
    $comparator_map = ['equal' => 'equals', 'not_equal' => 'not_equals'];
    $conditions     = (isset($data['settings']['conditions']) && is_array($data['settings']['conditions'])) ? $data['settings']['conditions'] : [];

    foreach ($conditions as $rule) {
        if (!is_array($rule) || empty($rule['when'][0]) || empty($rule['then']) || !is_array($rule['then'])) continue;
        $when        = $rule['when'][0];
        $trigger_key = isset($when['key']) ? $when['key'] : '';
        $comparator  = isset($when['comparator']) ? $when['comparator'] : '';
        $value       = isset($when['value']) ? (string) $when['value'] : '';

        if (!isset($key_to_uid[$trigger_key])) continue; // trigger field wasn't imported

        // A checkbox or file trigger can't be evaluated consistently between
        // the front-end and the server (see alchemy_forms_condition_ineligible_types()
        // in alchemy-forms.php) — importing one anyway would silently drop the
        // dependent field's answers on every submission, so skip it here too.
        $trigger_type = isset($key_to_type[$trigger_key]) ? $key_to_type[$trigger_key] : '';
        if (in_array($trigger_type, alchemy_forms_condition_ineligible_types(), true)) {
            $trigger_label = isset($key_to_label[$trigger_key]) ? $key_to_label[$trigger_key] : __('(untitled)', 'alchemy-forms');
            /* translators: %s: field label */
            $summary[] = sprintf(__('A condition triggered by "%s" was skipped (checkbox/file fields can\'t be used as a condition trigger); the affected field will always show.', 'alchemy-forms'), $trigger_label);
            continue;
        }

        foreach ($rule['then'] as $action) {
            if (empty($action['trigger']) || $action['trigger'] !== 'show_field' || empty($action['key'])) continue;
            $target_key = $action['key'];

            if (!isset($comparator_map[$comparator])) {
                /* translators: %s: Ninja Forms comparator name */
                $summary[] = sprintf(__('A condition using the "%s" comparator was skipped (only equals/is not are supported); the affected field will always show.', 'alchemy-forms'), $comparator);
                continue;
            }

            foreach ($fields as &$f) {
                if (isset($f['_nf_key']) && $f['_nf_key'] === $target_key) {
                    $f['condition'] = [
                        'field'      => $key_to_uid[$trigger_key],
                        'comparator' => $comparator_map[$comparator],
                        'value'      => sanitize_text_field($value),
                    ];
                    break;
                }
            }
            unset($f);
        }
    }

    foreach ($fields as &$f) {
        unset($f['_nf_key']);
    }
    unset($f);

    // Settings.
    $recipient     = get_option('admin_email');
    $actions       = (isset($data['actions']) && is_array($data['actions'])) ? $data['actions'] : [];
    $email_actions = array_values(array_filter($actions, function ($a) {
        return is_array($a) && isset($a['type']) && $a['type'] === 'email';
    }));

    if (!empty($email_actions)) {
        $to        = isset($email_actions[0]['to']) ? trim((string) $email_actions[0]['to']) : '';
        $addresses = array_map('trim', explode(',', $to));
        $first     = isset($addresses[0]) ? $addresses[0] : '';

        if ($first !== '' && $first !== '{wp:admin_email}' && is_email($first)) {
            $recipient = $first;
        }
        if (count($addresses) > 1) {
            $summary[] = __('Only the first recipient email was kept; the others from the original form were dropped.', 'alchemy-forms');
        }
        if (count($email_actions) > 1) {
            /* translators: %d: number of extra email notifications dropped */
            $summary[] = sprintf(__('%d extra email notification(s) from the original form were dropped; only one recipient is supported.', 'alchemy-forms'), count($email_actions) - 1);
        }
    }

    $success_msg = __('Thanks — your submission has been received.', 'alchemy-forms');
    foreach ($actions as $a) {
        if (!is_array($a) || !isset($a['type']) || $a['type'] !== 'successmessage') continue;
        $raw = !empty($a['success_msg']) ? $a['success_msg'] : (!empty($a['message']) ? $a['message'] : '');
        if ($raw === '') break;

        $stripped = trim(wp_strip_all_tags($raw));
        if ($stripped !== '') {
            $success_msg = $stripped;
            if ($stripped !== trim($raw)) {
                $summary[] = __('The success message contained formatting/links, which were removed (Alchemy Forms success messages are plain text).', 'alchemy-forms');
            }
        }
        break;
    }

    if (empty($fields)) {
        $summary[] = __('No supported fields were found in this file.', 'alchemy-forms');
    }

    return [
        'fields'   => $fields,
        'settings' => [
            'recipient'   => $recipient,
            'submit_text' => $submit_text !== '' ? $submit_text : __('Submit', 'alchemy-forms'),
            'success_msg' => $success_msg,
        ],
        'summary'  => $summary,
    ];
}
