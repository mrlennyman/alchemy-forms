<?php
if (!defined('ABSPATH')) exit;

/* -------------------------------------------------------------------------
 * Admin page
 * ---------------------------------------------------------------------- */
add_action('admin_menu', function () {
    add_submenu_page(
        'edit.php?post_type=wa_form',
        __('Import', 'wa-forms'),
        __('Import', 'wa-forms'),
        'edit_posts',
        'wa-form-import',
        'wa_forms_import_page'
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

    if (!current_user_can('edit_posts')) wp_die(esc_html__('You do not have permission to import forms.', 'wa-forms'));
    check_admin_referer('wa_forms_import');

    $error = wa_forms_process_import();

    if ($error) {
        set_transient('wa_forms_import_error_' . get_current_user_id(), $error, MINUTE_IN_SECONDS);
        wp_safe_redirect(admin_url('edit.php?post_type=wa_form&page=wa-form-import'));
        exit;
    }
    // wa_forms_process_import() redirects to the new draft itself on success.
});

function wa_forms_import_page() {
    if (!current_user_can('edit_posts')) wp_die(esc_html__('You do not have permission to import forms.', 'wa-forms'));

    $error_key = 'wa_forms_import_error_' . get_current_user_id();
    $error     = get_transient($error_key);
    if ($error !== false) delete_transient($error_key);
    ?>
    <div class="wrap">
        <h1><?php esc_html_e('Import from Ninja Forms', 'wa-forms'); ?></h1>

        <?php if ($error) : ?>
            <div class="notice notice-error"><p><?php echo esc_html($error); ?></p></div>
        <?php endif; ?>

        <p><?php esc_html_e('Upload a Ninja Forms export (.nff) to create a new WA Forms draft with the same fields, layout, and conditional logic where possible. The new form is saved as a draft so you can review it before publishing.', 'wa-forms'); ?></p>

        <div class="notice notice-info inline">
            <p><strong><?php esc_html_e('What carries over', 'wa-forms'); ?></strong></p>
            <ul style="list-style:disc;margin-left:1.5em;">
                <li><?php esc_html_e('Text, email, phone, number, date, paragraph, dropdown, radio, and checkbox fields, with their labels, required flags, and options.', 'wa-forms'); ?></li>
                <li><?php esc_html_e('Simple "show this field only if another field equals a value" conditional logic.', 'wa-forms'); ?></li>
                <li><?php esc_html_e('The recipient email, submit button text, and success message.', 'wa-forms'); ?></li>
            </ul>
            <p><strong><?php esc_html_e("What doesn't", 'wa-forms'); ?></strong></p>
            <ul style="list-style:disc;margin-left:1.5em;">
                <li><?php esc_html_e("Multi-step forms (forms with more than one page) — not supported yet, and will be refused rather than imported broken.", 'wa-forms'); ?></li>
                <li><?php esc_html_e('Informational HTML blocks and divider lines have no equivalent and are skipped.', 'wa-forms'); ?></li>
                <li><?php esc_html_e('Only one recipient email and one condition per field are kept.', 'wa-forms'); ?></li>
            </ul>
        </div>

        <form method="post" enctype="multipart/form-data" style="margin-top:1.5em;">
            <?php wp_nonce_field('wa_forms_import'); ?>
            <input type="file" name="nff_file" accept=".nff,.json" required>
            <p class="submit">
                <button type="submit" class="button button-primary"><?php esc_html_e('Import Form', 'wa-forms'); ?></button>
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
    $summary = get_transient('wa_forms_import_summary_' . $post_id);
    if ($summary === false) return;
    delete_transient('wa_forms_import_summary_' . $post_id);
    ?>
    <div class="notice notice-success is-dismissible">
        <p><strong><?php esc_html_e('Form imported from Ninja Forms.', 'wa-forms'); ?></strong> <?php esc_html_e("It's saved as a draft — review it before publishing.", 'wa-forms'); ?></p>
        <?php if (!empty($summary)) : ?>
            <p><?php esc_html_e('A few things to double-check:', 'wa-forms'); ?></p>
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
function wa_forms_process_import() {
    $file = $_FILES['nff_file'];
    if (empty($file['tmp_name']) || $file['error'] !== UPLOAD_ERR_OK) {
        return __('Please choose a valid .nff file to upload.', 'wa-forms');
    }
    if (!is_uploaded_file($file['tmp_name'])) {
        return __('Upload failed — please try again.', 'wa-forms');
    }
    if ($file['size'] > 2 * 1024 * 1024) {
        return __('That file is too large to be a Ninja Forms export.', 'wa-forms');
    }

    $json = file_get_contents($file['tmp_name']);
    $data = json_decode($json, true);

    if (!is_array($data) || !isset($data['fields']) || !is_array($data['fields']) || !isset($data['settings']) || !is_array($data['settings'])) {
        return __("That doesn't look like a Ninja Forms export — no fields/settings found.", 'wa-forms');
    }

    $parts = (isset($data['settings']['formContentData']) && is_array($data['settings']['formContentData'])) ? $data['settings']['formContentData'] : [];
    if (count($parts) > 1) {
        return __("This form has multiple steps (pages), which WA Forms doesn't support yet — import isn't available for it.", 'wa-forms');
    }

    $result = wa_forms_map_nf_import($data, $parts);

    $title = !empty($data['settings']['title']) ? sanitize_text_field($data['settings']['title']) : __('Imported Form', 'wa-forms');

    $post_id = wp_insert_post([
        'post_type'   => 'wa_form',
        'post_status' => 'draft',
        'post_title'  => $title,
    ]);
    if (!$post_id || is_wp_error($post_id)) {
        return __('Could not create the new form — please try again.', 'wa-forms');
    }

    update_post_meta($post_id, '_wa_form_fields', $result['fields']);
    update_post_meta($post_id, '_wa_form_settings', $result['settings']);
    set_transient('wa_forms_import_summary_' . $post_id, $result['summary'], MINUTE_IN_SECONDS * 10);

    wp_safe_redirect(admin_url('post.php?post=' . $post_id . '&action=edit'));
    exit;
}

/**
 * Field types this plugin can represent, keyed by their Ninja Forms type.
 */
function wa_forms_nf_type_map() {
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
    ];
}

/**
 * Converts a decoded .nff array into WA Forms' _wa_form_fields/_wa_form_settings
 * shape, plus a plain-language summary of anything dropped or simplified.
 */
function wa_forms_map_nf_import($data, $parts) {
    $type_map     = wa_forms_nf_type_map();
    $option_types = wa_forms_option_field_types();
    $summary      = [];

    // Cell width (percentage) per field key, from the single allowed layout part.
    $field_widths = [];
    if (!empty($parts[0]['formContentData']) && is_array($parts[0]['formContentData'])) {
        foreach ($parts[0]['formContentData'] as $row) {
            if (empty($row['cells']) || !is_array($row['cells'])) continue;
            foreach ($row['cells'] as $cell) {
                if (empty($cell['fields']) || !is_array($cell['fields'])) continue;
                $w = isset($cell['width']) ? (string) $cell['width'] : '100';
                foreach ($cell['fields'] as $fkey) {
                    $field_widths[$fkey] = $w;
                }
            }
        }
    }

    $fields      = [];
    $key_to_uid  = [];
    $submit_text = '';

    foreach ($data['fields'] as $nf_field) {
        if (!is_array($nf_field) || empty($nf_field['type'])) continue;
        $nf_type = $nf_field['type'];
        $nf_key  = isset($nf_field['key']) ? $nf_field['key'] : '';
        $label   = isset($nf_field['label']) ? sanitize_text_field(wp_strip_all_tags($nf_field['label'])) : '';

        if ($nf_type === 'submit') {
            if ($label !== '') $submit_text = $label;
            continue;
        }

        if (!isset($type_map[$nf_type])) {
            /* translators: 1: field label, 2: Ninja Forms field type */
            $summary[] = sprintf(__('Skipped "%1$s" (%2$s fields aren\'t supported).', 'wa-forms'), $label !== '' ? $label : __('(untitled)', 'wa-forms'), $nf_type);
            continue;
        }

        $type  = $type_map[$nf_type];
        $width = (isset($field_widths[$nf_key]) && (int) $field_widths[$nf_key] < 100) ? 'half' : 'full';
        $uid   = wp_generate_uuid4();
        if ($nf_key !== '') $key_to_uid[$nf_key] = $uid;

        $field = [
            'label'      => $label !== '' ? $label : ucfirst($type),
            'type'       => $type,
            'required'   => !empty($nf_field['required']) ? 1 : 0,
            'hide_label' => 0,
            'width'      => $width,
            'uid'        => $uid,
            '_nf_key'    => $nf_key, // temporary; used below, stripped before returning
        ];

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

        foreach ($rule['then'] as $action) {
            if (empty($action['trigger']) || $action['trigger'] !== 'show_field' || empty($action['key'])) continue;
            $target_key = $action['key'];

            if (!isset($comparator_map[$comparator])) {
                /* translators: %s: Ninja Forms comparator name */
                $summary[] = sprintf(__('A condition using the "%s" comparator was skipped (only equals/is not are supported); the affected field will always show.', 'wa-forms'), $comparator);
                continue;
            }

            foreach ($fields as &$f) {
                if ($f['_nf_key'] === $target_key) {
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
            $summary[] = __('Only the first recipient email was kept; the others from the original form were dropped.', 'wa-forms');
        }
        if (count($email_actions) > 1) {
            /* translators: %d: number of extra email notifications dropped */
            $summary[] = sprintf(__('%d extra email notification(s) from the original form were dropped; only one recipient is supported.', 'wa-forms'), count($email_actions) - 1);
        }
    }

    $success_msg = __('Thanks — your submission has been received.', 'wa-forms');
    foreach ($actions as $a) {
        if (!is_array($a) || !isset($a['type']) || $a['type'] !== 'successmessage') continue;
        $raw = !empty($a['success_msg']) ? $a['success_msg'] : (!empty($a['message']) ? $a['message'] : '');
        if ($raw === '') break;

        $stripped = trim(wp_strip_all_tags($raw));
        if ($stripped !== '') {
            $success_msg = $stripped;
            if ($stripped !== trim($raw)) {
                $summary[] = __('The success message contained formatting/links, which were removed (WA Forms success messages are plain text).', 'wa-forms');
            }
        }
        break;
    }

    if (empty($fields)) {
        $summary[] = __('No supported fields were found in this file.', 'wa-forms');
    }

    return [
        'fields'   => $fields,
        'settings' => [
            'recipient'   => $recipient,
            'submit_text' => $submit_text !== '' ? $submit_text : __('Submit', 'wa-forms'),
            'success_msg' => $success_msg,
        ],
        'summary'  => $summary,
    ];
}
