<?php
if (!defined('ABSPATH')) exit;

add_shortcode('wa_form', 'wa_forms_render_shortcode');

function wa_forms_render_shortcode($atts) {
    $atts    = shortcode_atts(['id' => 0, 'title' => ''], $atts, 'wa_form');
    $form_id = (int) $atts['id'];
    $form    = $form_id ? get_post($form_id) : null;

    if (!$form || $form->post_type !== 'wa_form' || $form->post_status !== 'publish') {
        return current_user_can('edit_posts')
            ? '<p><em>' . esc_html__('WA Forms: no published form found for this ID.', 'wa-forms') . '</em></p>'
            : '';
    }

    $fields = get_post_meta($form_id, '_wa_form_fields', true);
    if (!is_array($fields) || empty($fields)) {
        return current_user_can('edit_posts')
            ? '<p><em>' . esc_html__('WA Forms: this form has no fields yet.', 'wa-forms') . '</em></p>'
            : '';
    }

    $settings = get_post_meta($form_id, '_wa_form_settings', true);
    if (!is_array($settings)) $settings = [];
    $recipient   = !empty($settings['recipient']) && is_email($settings['recipient']) ? $settings['recipient'] : get_option('admin_email');
    $submit_text = !empty($settings['submit_text']) ? $settings['submit_text'] : __('Submit', 'wa-forms');
    $success_msg = !empty($settings['success_msg']) ? $settings['success_msg'] : __('Thanks — your submission has been received.', 'wa-forms');

    // Give each field a stable input name derived from its position + label.
    foreach ($fields as $i => &$f) {
        $f['name'] = 'waf_' . $i . '_' . sanitize_title($f['label']);
    }
    unset($f);

    $style_settings = (isset($settings['style']) && is_array($settings['style'])) ? $settings['style'] : [];
    $style          = wa_forms_resolve_style($style_settings);

    wa_forms_enqueue_frontend_css($style['font']);

    $errors           = [];
    $values           = [];
    $success          = false;
    $condition_lookup = []; // uid => submitted value, used to evaluate conditions; empty on a fresh (non-POST) load

    $posted_this_form = isset($_POST['wa_form_id']) && (int) $_POST['wa_form_id'] === $form_id;

    if ($_SERVER['REQUEST_METHOD'] === 'POST' && $posted_this_form) {
        if (!isset($_POST['wa_form_token']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['wa_form_token'])), 'wa_form_submit_' . $form_id)) {
            $errors[] = __('Your session expired — please try submitting again.', 'wa-forms');
        } elseif (!empty($_POST['wa_website_hp'])) {
            $success = true; // Honeypot: pretend it worked, save/send nothing.
        } else {
            $entry_data      = [];
            $attachment_path = '';

            // First pass: raw values by uid, used only to evaluate each field's
            // conditional visibility before the real per-field handling below.
            $condition_lookup = [];
            foreach ($fields as $cf) {
                if (empty($cf['uid']) || in_array($cf['type'], ['file', 'checkbox'], true)) continue;
                $raw = isset($_POST[$cf['name']]) ? wp_unslash($_POST[$cf['name']]) : '';
                if (is_array($raw)) $raw = '';
                $condition_lookup[$cf['uid']] = sanitize_text_field($raw);
            }

            foreach ($fields as $field) {
                $name  = $field['name'];
                $label = $field['label'];

                $condition = isset($field['condition']) ? $field['condition'] : [];
                if (!wa_forms_evaluate_condition($condition, $condition_lookup)) {
                    continue; // hidden by its condition: not required, not stored
                }

                if ($field['type'] === 'file') {
                    $file_result = wa_forms_handle_upload($name, !empty($field['required']), $label, $errors);
                    if ($file_result) {
                        $entry_data[$label] = $file_result['url'];
                        $attachment_path    = $file_result['path'];
                    } else {
                        $entry_data[$label] = '';
                    }
                    continue;
                }

                if ($field['type'] === 'checkbox') {
                    $posted  = (isset($_POST[$name]) && is_array($_POST[$name])) ? wp_unslash($_POST[$name]) : [];
                    $allowed = (isset($field['options']) && is_array($field['options'])) ? $field['options'] : [];
                    $val     = array_values(array_intersect(array_map('sanitize_text_field', $posted), $allowed));

                    $values[$name]      = $val;
                    $entry_data[$label] = implode(', ', $val);

                    if (!empty($field['required']) && empty($val)) {
                        /* translators: %s: field label */
                        $errors[] = sprintf(__('%s is required.', 'wa-forms'), $label);
                    }
                    continue;
                }

                $raw = isset($_POST[$name]) ? wp_unslash($_POST[$name]) : '';
                if (is_array($raw)) $raw = '';

                if ($field['type'] === 'textarea') {
                    $val = sanitize_textarea_field($raw);
                } elseif ($field['type'] === 'email') {
                    $val = sanitize_email($raw);
                } elseif ($field['type'] === 'url') {
                    $val = esc_url_raw(trim($raw));
                } elseif ($field['type'] === 'select' || $field['type'] === 'radio') {
                    $val     = sanitize_text_field($raw);
                    $allowed = (isset($field['options']) && is_array($field['options'])) ? $field['options'] : [];
                    if ($val !== '' && !in_array($val, $allowed, true)) $val = '';
                } else {
                    $val = sanitize_text_field($raw);
                }

                $values[$name]      = $val;
                $entry_data[$label] = $val;

                if (!empty($field['required']) && $val === '') {
                    /* translators: %s: field label */
                    $errors[] = sprintf(__('%s is required.', 'wa-forms'), $label);
                }
                if ($field['type'] === 'email' && $val !== '' && !is_email($val)) {
                    /* translators: %s: field label */
                    $errors[] = sprintf(__('Please enter a valid email address for %s.', 'wa-forms'), $label);
                }
                if ($field['type'] === 'number' && $val !== '' && !is_numeric($val)) {
                    /* translators: %s: field label */
                    $errors[] = sprintf(__('Please enter a valid number for %s.', 'wa-forms'), $label);
                }
                if ($field['type'] === 'date' && $val !== '') {
                    $date_check = DateTime::createFromFormat('Y-m-d', $val);
                    if (!$date_check || $date_check->format('Y-m-d') !== $val) {
                        /* translators: %s: field label */
                        $errors[] = sprintf(__('Please enter a valid date for %s.', 'wa-forms'), $label);
                    }
                }
            }

            if (empty($errors)) {
                wa_forms_save_entry($form_id, $entry_data);

                $body = sprintf(__("New submission — %s:\n\n", 'wa-forms'), $form->post_title);
                foreach ($entry_data as $label => $val) {
                    $body .= $label . ': ' . $val . "\n";
                }
                $entries_url = admin_url('edit.php?post_type=wa_form&page=wa-form-entries&form_id=' . $form_id);
                $body .= "\n" . __('View all entries:', 'wa-forms') . ' ' . $entries_url . "\n";

                wp_mail(
                    $recipient,
                    sprintf(__('New submission: %s', 'wa-forms'), $form->post_title),
                    $body,
                    [],
                    $attachment_path ? [$attachment_path] : []
                );
                // Entry is stored either way — email failure shouldn't lose the submission.
                $success = true;
            }
        }
    }

    ob_start();
    ?>
    <div class="wa-form-wrap" style="<?php echo esc_attr($style['inline']); ?>">
        <?php if ($atts['title'] !== '') : ?>
            <h2 class="wa-form-title"><?php echo esc_html($atts['title']); ?></h2>
        <?php endif; ?>

        <?php if ($success) : ?>
            <div class="wa-form-success" role="status">
                <h3><?php esc_html_e('Thank you', 'wa-forms'); ?></h3>
                <p><?php echo esc_html($success_msg); ?></p>
            </div>
        <?php else : ?>
            <form class="wa-form" method="post" enctype="multipart/form-data" novalidate>
                <input type="hidden" name="wa_form_id" value="<?php echo (int) $form_id; ?>">
                <input type="hidden" name="wa_form_token" value="<?php echo esc_attr(wp_create_nonce('wa_form_submit_' . $form_id)); ?>">
                <div class="wa-form-honeypot" aria-hidden="true">
                    <label><?php esc_html_e('Leave this field blank', 'wa-forms'); ?>
                        <input type="text" name="wa_website_hp" tabindex="-1" autocomplete="off">
                    </label>
                </div>

                <?php if (!empty($errors)) : ?>
                    <div class="wa-form-errors" role="alert">
                        <ul>
                            <?php foreach ($errors as $error) : ?>
                                <li><?php echo esc_html($error); ?></li>
                            <?php endforeach; ?>
                        </ul>
                    </div>
                <?php endif; ?>

                <div class="wa-form-grid">
                    <?php foreach ($fields as $field) :
                        $name     = $field['name'];
                        $type     = $field['type'];
                        $is_group = in_array($type, ['radio', 'checkbox'], true);
                        $val      = isset($values[$name]) ? $values[$name] : ($type === 'checkbox' ? [] : '');
                        $id       = 'wa-' . $form_id . '-' . $name;
                        $req      = !empty($field['required']);
                        $hidden_l = !empty($field['hide_label']);
                        $wid      = ($field['width'] === 'half') ? 'half' : 'full';
                        $options  = (isset($field['options']) && is_array($field['options'])) ? $field['options'] : [];

                        $condition      = isset($field['condition']) ? $field['condition'] : [];
                        $has_condition  = !empty($condition['field']);
                        $cond_visible   = wa_forms_evaluate_condition($condition, $condition_lookup);
                        $field_classes  = 'wa-field wa-field--' . $wid . (!$cond_visible ? ' wa-field--hidden' : '');
                    ?>
                        <div class="<?php echo esc_attr($field_classes); ?>"
                            <?php if (!empty($field['uid'])) : ?>data-field-uid="<?php echo esc_attr($field['uid']); ?>"<?php endif; ?>
                            <?php if ($has_condition) : ?>
                                data-condition-field="<?php echo esc_attr($condition['field']); ?>"
                                data-condition-comparator="<?php echo esc_attr(isset($condition['comparator']) ? $condition['comparator'] : 'equals'); ?>"
                                data-condition-value="<?php echo esc_attr(isset($condition['value']) ? $condition['value'] : ''); ?>"
                            <?php endif; ?>
                        >
                            <?php if ($is_group) : ?>
                                <fieldset class="wa-field-group">
                                    <legend<?php echo $hidden_l ? ' class="wa-visually-hidden"' : ''; ?>>
                                        <?php echo esc_html($field['label']); ?>
                                        <?php if ($req) : ?><span class="wa-req">*</span><?php endif; ?>
                                    </legend>
                                    <?php foreach ($options as $oi => $option) :
                                        $opt_id  = $id . '-' . $oi;
                                        $checked = ($type === 'checkbox') ? in_array($option, (array) $val, true) : ((string) $val === (string) $option);
                                    ?>
                                        <label class="wa-choice-option">
                                            <input type="<?php echo esc_attr($type); ?>" id="<?php echo esc_attr($opt_id); ?>" name="<?php echo esc_attr($type === 'checkbox' ? $name . '[]' : $name); ?>" value="<?php echo esc_attr($option); ?>" <?php checked($checked); ?>>
                                            <?php echo esc_html($option); ?>
                                        </label>
                                    <?php endforeach; ?>
                                </fieldset>

                            <?php else : ?>
                                <label for="<?php echo esc_attr($id); ?>"<?php echo $hidden_l ? ' class="wa-visually-hidden"' : ''; ?>>
                                    <?php echo esc_html($field['label']); ?>
                                    <?php if ($req) : ?><span class="wa-req">*</span><?php endif; ?>
                                </label>

                                <?php if ($type === 'textarea') : ?>
                                    <textarea id="<?php echo esc_attr($id); ?>" name="<?php echo esc_attr($name); ?>" rows="4" <?php echo $req ? 'required aria-required="true"' : ''; ?>><?php echo esc_textarea($val); ?></textarea>

                                <?php elseif ($type === 'file') : ?>
                                    <div class="wa-file-input">
                                        <input type="file" id="<?php echo esc_attr($id); ?>" name="<?php echo esc_attr($name); ?>" accept=".pdf,.jpg,.jpeg,.png" <?php echo $req ? 'required aria-required="true"' : ''; ?>>
                                        <span class="wa-file-hint"><?php esc_html_e('PDF, JPG or PNG, up to 5MB', 'wa-forms'); ?></span>
                                    </div>

                                <?php elseif ($type === 'select') : ?>
                                    <select id="<?php echo esc_attr($id); ?>" name="<?php echo esc_attr($name); ?>" <?php echo $req ? 'required aria-required="true"' : ''; ?>>
                                        <option value=""><?php esc_html_e('— Select —', 'wa-forms'); ?></option>
                                        <?php foreach ($options as $option) : ?>
                                            <option value="<?php echo esc_attr($option); ?>" <?php selected((string) $val, (string) $option); ?>><?php echo esc_html($option); ?></option>
                                        <?php endforeach; ?>
                                    </select>

                                <?php else : ?>
                                    <input type="<?php echo esc_attr($type); ?>" id="<?php echo esc_attr($id); ?>" name="<?php echo esc_attr($name); ?>" value="<?php echo esc_attr($val); ?>" <?php echo $req ? 'required aria-required="true"' : ''; ?>>
                                <?php endif; ?>
                            <?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                </div>

                <button type="submit" class="wa-form-submit"><?php echo esc_html($submit_text); ?></button>
            </form>
        <?php endif; ?>
    </div>
    <?php
    return ob_get_clean();
}

/**
 * Handle a single uploaded file. Returns ['url' => ..., 'path' => ...] or null.
 * Appends to $errors by reference on failure.
 */
function wa_forms_handle_upload($input_name, $required, $label, array &$errors) {
    if (empty($_FILES[$input_name]['name'])) {
        if ($required) {
            /* translators: %s: field label */
            $errors[] = sprintf(__('%s is required.', 'wa-forms'), $label);
        }
        return null;
    }

    $file      = $_FILES[$input_name];
    $allowed   = ['image/jpeg', 'image/png', 'application/pdf'];
    $max_bytes = 5 * 1024 * 1024;

    if ($file['error'] !== UPLOAD_ERR_OK) {
        $errors[] = __('There was a problem uploading your file — please try again.', 'wa-forms');
        return null;
    }
    if ($file['size'] > $max_bytes) {
        $errors[] = __('That file is too large — please keep it under 5MB.', 'wa-forms');
        return null;
    }
    // Check the real type, not just what the browser claims.
    $check = wp_check_filetype_and_ext($file['tmp_name'], $file['name']);
    if (empty($check['type']) || !in_array($check['type'], $allowed, true)) {
        $errors[] = __('Please upload a PDF, JPG or PNG file.', 'wa-forms');
        return null;
    }

    require_once ABSPATH . 'wp-admin/includes/file.php';
    require_once ABSPATH . 'wp-admin/includes/image.php';
    require_once ABSPATH . 'wp-admin/includes/media.php';

    $upload = wp_handle_upload($file, ['test_form' => false]);
    if (isset($upload['error'])) {
        $errors[] = __('Upload failed:', 'wa-forms') . ' ' . $upload['error'];
        return null;
    }

    $attach_id = wp_insert_attachment([
        'post_mime_type' => $upload['type'],
        'post_title'     => sanitize_file_name(basename($upload['file'])),
        'post_status'    => 'inherit',
    ], $upload['file']);

    $url = $upload['url'];
    if (!is_wp_error($attach_id) && $attach_id) {
        wp_update_attachment_metadata($attach_id, wp_generate_attachment_metadata($attach_id, $upload['file']));
        $url = wp_get_attachment_url($attach_id) ?: $url;
    }

    return ['url' => $url, 'path' => $upload['file']];
}

/* -------------------------------------------------------------------------
 * Per-form style resolution
 * ---------------------------------------------------------------------- */
function wa_forms_resolve_style($style_settings) {
    if (!is_array($style_settings)) $style_settings = [];

    $primary      = wa_forms_sanitize_hex($style_settings['primary_color'] ?? '', '#2F4F3E');
    $accent       = wa_forms_sanitize_hex($style_settings['accent_color'] ?? '', '#C9A227');
    $border       = wa_forms_sanitize_hex($style_settings['border_color'] ?? '', '#DCE3D9');
    $placeholder  = wa_forms_sanitize_hex($style_settings['placeholder_color'] ?? '', '#5B6B60');
    // Forms saved under the old preset system stored a slug (e.g. 'rounded') here;
    // wa_forms_sanitize_px() falls back cleanly when the value isn't numeric.
    $radius       = wa_forms_sanitize_px($style_settings['radius'] ?? null, 10);

    $label_color       = wa_forms_sanitize_hex($style_settings['label_color'] ?? '', '#1F2A23');
    $label_font_size   = wa_forms_sanitize_px($style_settings['label_font_size'] ?? null, 14);
    $field_gap         = wa_forms_sanitize_px($style_settings['field_gap'] ?? null, 20);
    $input_padding     = wa_forms_sanitize_px($style_settings['input_padding'] ?? null, 10);
    $input_bg          = wa_forms_sanitize_hex($style_settings['input_bg_color'] ?? '', '#F6F8F3');
    $button_bg         = wa_forms_sanitize_hex($style_settings['button_bg_color'] ?? '', '#2F4F3E');
    $button_hover      = wa_forms_sanitize_hex($style_settings['button_hover_color'] ?? '', '#22392B');
    $button_padding    = wa_forms_sanitize_px($style_settings['button_padding'] ?? null, 13);
    $button_font_size  = wa_forms_sanitize_px($style_settings['button_font_size'] ?? null, 15);
    $container_bg      = wa_forms_sanitize_hex($style_settings['container_bg_color'] ?? '', '#FFFFFF');
    $container_opacity = wa_forms_sanitize_px($style_settings['container_bg_opacity'] ?? null, 100, 0, 100);

    [$cr, $cg, $cb] = wa_forms_hex_to_rgb($container_bg);
    $container_rgba = sprintf('rgba(%d, %d, %d, %s)', $cr, $cg, $cb, $container_opacity / 100);

    $font_presets = wa_forms_font_presets();
    $font_key     = (!empty($style_settings['font']) && isset($font_presets[$style_settings['font']])) ? $style_settings['font'] : 'default';
    $font         = $font_presets[$font_key];

    $vars = [
        '--wa-primary'          => $primary,
        '--wa-primary-dark'     => wa_forms_darken_hex($primary, 0.22),
        '--wa-accent'           => $accent,
        '--wa-border'           => $border,
        '--wa-placeholder'      => $placeholder,
        '--wa-radius'           => $radius . 'px',
        '--wa-font-display'     => $font['display'],
        '--wa-font-body'        => $font['body'],
        '--wa-label-color'      => $label_color,
        '--wa-label-font-size'  => $label_font_size . 'px',
        '--wa-field-gap'        => $field_gap . 'px',
        '--wa-input-padding'    => $input_padding . 'px',
        '--wa-input-bg'         => $input_bg,
        '--wa-button-bg'        => $button_bg,
        '--wa-button-bg-hover'  => $button_hover,
        '--wa-button-padding'   => $button_padding . 'px',
        '--wa-button-font-size' => $button_font_size . 'px',
        '--wa-container-bg'     => $container_rgba,
    ];

    $inline = '';
    foreach ($vars as $prop => $value) {
        $inline .= $prop . ': ' . $value . '; ';
    }

    return ['inline' => trim($inline), 'font' => $font];
}

/* -------------------------------------------------------------------------
 * Frontend CSS (only loads on pages that render a form)
 * ---------------------------------------------------------------------- */
function wa_forms_enqueue_frontend_css($font = null) {
    static $css_done = false;
    if (!$css_done) {
        $css_done = true;
        wp_register_style('wa-forms-frontend', false, [], WA_FORMS_VERSION);
        wp_enqueue_style('wa-forms-frontend');
        wp_add_inline_style('wa-forms-frontend', wa_forms_frontend_css());
        wp_enqueue_script('wa-forms-frontend', WA_FORMS_URL . 'assets/frontend.js', [], WA_FORMS_VERSION, true);
    }

    if (!empty($font['google'])) {
        wp_enqueue_style('wa-forms-fonts-' . substr(md5($font['google']), 0, 8), $font['google'], [], null);
    }
}

function wa_forms_frontend_css() {
    return <<<CSS
.wa-form-wrap {
  --wa-primary: #2F4F3E;
  --wa-primary-dark: #22392B;
  --wa-accent: #C9A227;
  --wa-bg: #F6F8F3;
  --wa-surface: #FFFFFF;
  --wa-text: #1F2A23;
  --wa-muted: #5B6B60;
  --wa-border: #DCE3D9;
  --wa-placeholder: #5B6B60;
  --wa-error: #B3261E;
  --wa-error-bg: #FBEBEA;
  --wa-radius: 10px;
  --wa-font-display: 'Fraunces', Georgia, serif;
  --wa-font-body: 'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif;
  --wa-label-color: #1F2A23;
  --wa-label-font-size: 14px;
  --wa-field-gap: 20px;
  --wa-input-padding: 10px;
  --wa-input-bg: #F6F8F3;
  --wa-button-bg: #2F4F3E;
  --wa-button-bg-hover: #22392B;
  --wa-button-padding: 13px;
  --wa-button-font-size: 15px;
  --wa-container-bg: #FFFFFF;
  max-width: 720px;
  margin: 0 auto;
  font-family: var(--wa-font-body);
  color: var(--wa-text);
  box-sizing: border-box;
}
.wa-form-wrap *, .wa-form-wrap *::before, .wa-form-wrap *::after { box-sizing: inherit; }
.wa-form-title { font-family: var(--wa-font-display); font-weight: 600; font-size: 1.75rem; color: var(--wa-primary-dark); margin: 0 0 1.25rem; }
.wa-form { background: var(--wa-container-bg); border: 1px solid var(--wa-border); border-radius: calc(var(--wa-radius) + 6px); padding: 2.5rem; box-shadow: 0 1px 2px rgba(31,42,35,0.04), 0 8px 24px rgba(31,42,35,0.06); position: relative; }
.wa-form-grid { display: grid; grid-template-columns: repeat(2, 1fr); gap: var(--wa-field-gap); }
.wa-field--half { grid-column: span 1; }
.wa-field--full { grid-column: 1 / -1; }
.wa-field--hidden { display: none; }
@media (max-width: 560px) {
  .wa-form-grid { grid-template-columns: 1fr; }
  .wa-field--half { grid-column: 1 / -1; }
  .wa-form { padding: 1.75rem 1.5rem; }
}
.wa-field label { display: block; font-weight: 500; font-size: var(--wa-label-font-size); color: var(--wa-label-color); margin-bottom: 0.4rem; }
.wa-req { color: var(--wa-accent); margin-left: 2px; }
.wa-visually-hidden { position: absolute; width: 1px; height: 1px; padding: 0; margin: -1px; overflow: hidden; clip: rect(0,0,0,0); white-space: nowrap; border: 0; }
.wa-field input[type=text], .wa-field input[type=email], .wa-field input[type=tel], .wa-field input[type=url], .wa-field input[type=date], .wa-field input[type=number], .wa-field select, .wa-field textarea {
  width: 100%; font-family: var(--wa-font-body); font-size: 0.95rem; padding: var(--wa-input-padding);
  border: 1px solid var(--wa-border); border-radius: var(--wa-radius); background: var(--wa-input-bg); color: var(--wa-text);
  transition: border-color 0.15s ease, box-shadow 0.15s ease, background 0.15s ease;
}
.wa-field textarea { resize: vertical; min-height: 5.5rem; }
.wa-field input::placeholder, .wa-field textarea::placeholder { color: var(--wa-placeholder); opacity: 1; }
.wa-field input:focus, .wa-field select:focus, .wa-field textarea:focus { outline: none; border-color: var(--wa-primary); background: var(--wa-surface); box-shadow: 0 0 0 3px rgba(47,79,62,0.15); }
.wa-field input:focus-visible, .wa-field select:focus-visible, .wa-field textarea:focus-visible { outline: 2px solid var(--wa-accent); outline-offset: 1px; }
.wa-field-group { border: none; margin: 0; padding: 0; }
.wa-field-group legend { display: block; width: 100%; font-weight: 500; font-size: var(--wa-label-font-size); color: var(--wa-label-color); margin-bottom: 0.5rem; padding: 0; }
.wa-choice-option { display: flex; align-items: center; gap: 0.5rem; font-weight: 400; font-size: 0.92rem; color: var(--wa-text); margin-bottom: 0.5rem; cursor: pointer; }
.wa-choice-option:last-child { margin-bottom: 0; }
.wa-choice-option input[type=radio], .wa-choice-option input[type=checkbox] { width: auto; margin: 0; accent-color: var(--wa-primary); }
.wa-file-input { border: 1.5px dashed var(--wa-border); border-radius: var(--wa-radius); padding: 1rem; background: var(--wa-bg); display: flex; flex-direction: column; gap: 0.4rem; }
.wa-file-input input[type=file] { font-family: var(--wa-font-body); font-size: 0.88rem; color: var(--wa-muted); }
.wa-file-input input[type=file]::file-selector-button {
  font-family: var(--wa-font-body); font-weight: 500; font-size: 0.85rem; color: #fff; background: var(--wa-primary);
  border: none; border-radius: 6px; padding: 0.5rem 0.9rem; margin-right: 0.75rem; cursor: pointer; transition: background 0.15s ease;
}
.wa-file-input input[type=file]::file-selector-button:hover { background: var(--wa-primary-dark); }
.wa-file-hint { font-size: 0.78rem; color: var(--wa-muted); }
.wa-form-submit {
  margin-top: 1.75rem; font-family: var(--wa-font-body); font-weight: 600; font-size: var(--wa-button-font-size); color: #fff;
  background: var(--wa-button-bg); border: none; border-radius: var(--wa-radius); padding: var(--wa-button-padding); cursor: pointer;
  transition: background 0.15s ease, transform 0.1s ease;
}
.wa-form-submit:hover { background: var(--wa-button-bg-hover); }
.wa-form-submit:active { transform: translateY(1px); }
.wa-form-submit:focus-visible { outline: 2px solid var(--wa-accent); outline-offset: 2px; }
.wa-form-honeypot { position: absolute; left: -9999px; width: 1px; height: 1px; overflow: hidden; }
.wa-form-errors { background: var(--wa-error-bg); border: 1px solid var(--wa-error); border-radius: var(--wa-radius); padding: 0.9rem 1.1rem; margin-bottom: 1.5rem; }
.wa-form-errors ul { margin: 0; padding-left: 1.1rem; }
.wa-form-errors li { color: var(--wa-error); font-size: 0.88rem; }
.wa-form-success { background: var(--wa-surface); border: 1px solid var(--wa-border); border-left: 4px solid var(--wa-primary); border-radius: var(--wa-radius); padding: 2rem; }
.wa-form-success h3 { font-family: var(--wa-font-display); font-size: 1.5rem; font-weight: 600; margin: 0 0 0.5rem; color: var(--wa-primary-dark); }
.wa-form-success p { margin: 0; color: var(--wa-muted); }
@media (prefers-reduced-motion: reduce) {
  .wa-form-submit, .wa-field input, .wa-field select, .wa-field textarea { transition: none; }
}
CSS;
}
