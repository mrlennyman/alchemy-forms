<?php
if (!defined('ABSPATH')) exit;

/* -------------------------------------------------------------------------
 * Post type
 * ---------------------------------------------------------------------- */
add_action('init', function () {
    register_post_type('wa_form', [
        'labels' => [
            'name'          => __('Alchemy Forms', 'alchemy-forms'),
            'singular_name' => __('Form', 'alchemy-forms'),
            'add_new_item'  => __('Add New Form', 'alchemy-forms'),
            'edit_item'     => __('Edit Form', 'alchemy-forms'),
            'menu_name'     => __('Alchemy Forms', 'alchemy-forms'),
        ],
        'public'       => false,
        'show_ui'      => true,
        'menu_icon'    => 'dashicons-feedback',
        'supports'     => ['title'],
        'capability_type' => 'post',
        'map_meta_cap' => true,
    ]);
});

/* -------------------------------------------------------------------------
 * Shortcode column on the forms list
 * ---------------------------------------------------------------------- */
add_filter('manage_wa_form_posts_columns', function ($cols) {
    $cols['wa_shortcode'] = __('Shortcode', 'alchemy-forms');
    $cols['wa_entries']   = __('Entries', 'alchemy-forms');
    return $cols;
});
add_action('manage_wa_form_posts_custom_column', function ($col, $post_id) {
    if ($col === 'wa_shortcode') {
        echo '<code>[wa_form id="' . (int) $post_id . '"]</code>';
    }
    if ($col === 'wa_entries') {
        $count = alchemy_forms_count_entries($post_id);
        $url   = admin_url('edit.php?post_type=wa_form&page=wa-form-entries&form_id=' . (int) $post_id);
        echo '<a href="' . esc_url($url) . '">' . (int) $count . '</a>';
    }
}, 10, 2);

/* -------------------------------------------------------------------------
 * Full-width builder screen
 * ---------------------------------------------------------------------- */
add_filter('admin_body_class', function ($classes) {
    $screen = get_current_screen();
    if ($screen && $screen->post_type === 'wa_form' && $screen->base === 'post') {
        $classes .= ' alchemy-forms-builder';
    }
    return $classes;
});

/* -------------------------------------------------------------------------
 * Metaboxes
 * ---------------------------------------------------------------------- */
add_action('add_meta_boxes', function () {
    add_meta_box('wa_form_fields', __('Form Fields', 'alchemy-forms'), 'alchemy_forms_fields_metabox', 'wa_form', 'normal', 'high');
    add_meta_box('wa_form_settings', __('Form Settings', 'alchemy-forms'), 'alchemy_forms_settings_metabox', 'wa_form', 'side', 'default');
    add_meta_box('wa_form_style', __('Style', 'alchemy-forms'), 'alchemy_forms_style_metabox', 'wa_form', 'side', 'default');
    add_meta_box('wa_form_integrations', __('Email Marketing', 'alchemy-forms'), 'alchemy_forms_integrations_metabox', 'wa_form', 'side', 'low');
    add_meta_box('wa_form_usage', __('Usage', 'alchemy-forms'), 'alchemy_forms_usage_metabox', 'wa_form', 'side', 'low');
});

function alchemy_forms_fields_metabox($post) {
    $fields = get_post_meta($post->ID, '_wa_form_fields', true);
    if (!is_array($fields)) $fields = [];
    $types        = alchemy_forms_field_types();
    $icons        = alchemy_forms_field_type_icons();
    $option_types = alchemy_forms_option_field_types();

    // Lightweight list of every field, for populating each card's "which field" condition dropdown.
    // Excludes types the submission handler can't evaluate as a trigger (see
    // alchemy_forms_condition_ineligible_types()) so the builder never offers a
    // condition that would silently fail server-side.
    $ineligible_types = alchemy_forms_condition_ineligible_types();
    $all_fields       = [];
    foreach ($fields as $f) {
        if (empty($f['uid'])) continue; // gets one on next save; not selectable as a trigger until then
        if (isset($f['type']) && in_array($f['type'], $ineligible_types, true)) continue;
        $all_fields[] = [
            'uid'     => $f['uid'],
            'label'   => isset($f['label']) ? $f['label'] : '',
            'type'    => isset($f['type']) ? $f['type'] : 'text',
            'options' => (isset($f['options']) && is_array($f['options'])) ? $f['options'] : [],
        ];
    }

    wp_nonce_field('wa_form_save', 'wa_form_nonce');
    ?>
    <div class="wa-builder">
        <div class="wa-builder-toolbar">
            <p class="description"><?php esc_html_e('Click or drag a field type onto the canvas to add it. Drag cards to reorder.', 'alchemy-forms'); ?></p>
        </div>
        <div class="wa-builder-body">
            <div class="wa-palette">
                <h3><?php esc_html_e('Field Types', 'alchemy-forms'); ?></h3>
                <ul id="wa-palette-list">
                    <?php foreach ($types as $key => $name) :
                        $icon = isset($icons[$key]) ? $icons[$key] : 'dashicons-admin-generic';
                    ?>
                        <li class="wa-palette-item" data-type="<?php echo esc_attr($key); ?>">
                            <span class="dashicons <?php echo esc_attr($icon); ?>"></span>
                            <?php echo esc_html($name); ?>
                        </li>
                    <?php endforeach; ?>
                </ul>
            </div>
            <div class="wa-canvas">
                <p class="wa-canvas-empty" id="wa-canvas-empty" <?php echo empty($fields) ? '' : 'style="display:none"'; ?>>
                    <?php esc_html_e('No fields yet — click or drag a field type from the left to get started.', 'alchemy-forms'); ?>
                </p>
                <div id="wa-fields-list">
                    <?php foreach ($fields as $i => $f) : ?>
                        <?php alchemy_forms_field_row($i, $f, $types, $icons, $all_fields); ?>
                    <?php endforeach; ?>
                </div>
            </div>
            <div id="wa-style-panel-slot" class="wa-style-panel"></div>
        </div>
    </div>

    <?php foreach ($types as $key => $name) :
        $default_field = [
            'label'      => $key === 'page_break' ? '' : sprintf(__('New %s', 'alchemy-forms'), $name),
            'type'       => $key,
            'required'   => 0,
            'hide_label' => 0,
            'width'      => 'full',
            'options'    => in_array($key, $option_types, true) ? [__('Option 1', 'alchemy-forms'), __('Option 2', 'alchemy-forms')] : [],
            'uid'        => '',
            'content'    => '',
            'condition'  => [],
        ];
    ?>
        <script type="text/template" id="wa-field-template-<?php echo esc_attr($key); ?>">
            <?php alchemy_forms_field_row('{{i}}', $default_field, $types, $icons, $all_fields); ?>
        </script>
    <?php endforeach; ?>
    <?php
}

function alchemy_forms_field_row($i, $f, $types, $icons = null, $all_fields = []) {
    if ($icons === null) $icons = alchemy_forms_field_type_icons();

    $label        = isset($f['label']) ? $f['label'] : '';
    $type         = isset($f['type']) ? $f['type'] : 'text';
    $required     = !empty($f['required']);
    $hide_label   = !empty($f['hide_label']);
    $width        = (isset($f['width']) && $f['width'] === 'half') ? 'half' : 'full';
    $uid          = isset($f['uid']) ? $f['uid'] : '';
    $content      = isset($f['content']) ? $f['content'] : '';
    $placeholder  = isset($f['placeholder']) ? $f['placeholder'] : '';
    $show_placeholder = in_array($type, alchemy_forms_placeholder_eligible_types(), true);
    $option_types = alchemy_forms_option_field_types();
    $options      = (isset($f['options']) && is_array($f['options'])) ? implode("\n", $f['options']) : '';
    $show_options = in_array($type, $option_types, true);
    $icon         = isset($icons[$type]) ? $icons[$type] : 'dashicons-admin-generic';
    $type_label   = isset($types[$type]) ? $types[$type] : $type;

    $is_page_break = ($type === 'page_break');
    $is_html       = ($type === 'html');
    $is_hidden     = ($type === 'hidden');
    $show_width    = !$is_page_break && !$is_hidden;
    $show_meta     = !$is_page_break && !$is_html && !$is_hidden;

    $source = isset($f['source']) ? $f['source'] : 'post_title';
    $static_value = isset($f['static_value']) ? $f['static_value'] : '';
    $hidden_sources = alchemy_forms_hidden_sources();

    $summary = trim($type_label
        . ($show_width && $width === 'half' ? ' · ' . __('Half width', 'alchemy-forms') : '')
        . ($show_meta && $required ? ' · ' . __('Required', 'alchemy-forms') : '')
        . ($show_meta && $hide_label ? ' · ' . __('Label hidden', 'alchemy-forms') : '')
        . ($is_hidden && isset($hidden_sources[$source]) ? ' · ' . $hidden_sources[$source] : ''));

    $condition            = (isset($f['condition']) && is_array($f['condition'])) ? $f['condition'] : [];
    $condition_enabled    = !empty($condition['field']);
    $condition_field      = isset($condition['field']) ? $condition['field'] : '';
    $condition_comparator = isset($condition['comparator']) ? $condition['comparator'] : 'equals';
    $condition_value      = isset($condition['value']) ? $condition['value'] : '';
    $condition_options    = [];
    foreach ($all_fields as $of) {
        if ($of['uid'] === $condition_field) { $condition_options = $of['options']; break; }
    }
    ?>
    <div class="wa-field-item<?php echo $is_page_break ? ' wa-field-item--page-break' : ''; ?>" data-type="<?php echo esc_attr($type); ?>">
        <div class="wa-field-card">
            <div class="wa-field-card-header">
                <span class="wa-field-handle dashicons dashicons-menu" title="<?php esc_attr_e('Drag to reorder', 'alchemy-forms'); ?>"></span>
                <span class="wa-field-icon dashicons <?php echo esc_attr($icon); ?>"></span>
                <span class="wa-field-summary">
                    <span class="wa-field-summary-label"><?php echo esc_html($label !== '' ? $label : $type_label); ?></span>
                    <span class="wa-field-summary-meta"><?php echo esc_html($summary); ?></span>
                </span>
                <button type="button" class="button-link wa-field-toggle" title="<?php esc_attr_e('Edit field', 'alchemy-forms'); ?>"><span class="dashicons dashicons-arrow-down-alt2"></span></button>
                <button type="button" class="button-link wa-remove-field" title="<?php esc_attr_e('Remove field', 'alchemy-forms'); ?>"><span class="dashicons dashicons-trash"></span></button>
            </div>
            <div class="wa-field-card-body">
                <input type="hidden" class="wa-field-type" name="wa_fields[<?php echo esc_attr($i); ?>][type]" value="<?php echo esc_attr($type); ?>">
                <input type="hidden" class="wa-field-uid" name="wa_fields[<?php echo esc_attr($i); ?>][uid]" value="<?php echo esc_attr($uid); ?>">

                <?php if ($is_page_break) : ?>
                    <p>
                        <label><?php esc_html_e('Step title', 'alchemy-forms'); ?></label>
                        <input type="text" class="wa-field-label widefat" name="wa_fields[<?php echo esc_attr($i); ?>][label]" value="<?php echo esc_attr($label); ?>" placeholder="<?php esc_attr_e('e.g. Contact Details', 'alchemy-forms'); ?>">
                    </p>

                <?php elseif ($is_hidden) : ?>
                    <p>
                        <label><?php esc_html_e('Label (used in entries/emails)', 'alchemy-forms'); ?></label>
                        <input type="text" class="wa-field-label widefat" name="wa_fields[<?php echo esc_attr($i); ?>][label]" value="<?php echo esc_attr($label); ?>" placeholder="<?php esc_attr_e('e.g. Event Page', 'alchemy-forms'); ?>">
                    </p>
                    <p>
                        <label><?php esc_html_e('Value', 'alchemy-forms'); ?></label>
                        <select class="wa-hidden-source" name="wa_fields[<?php echo esc_attr($i); ?>][source]">
                            <?php foreach ($hidden_sources as $key => $src_label) : ?>
                                <option value="<?php echo esc_attr($key); ?>" <?php selected($source, $key); ?>><?php echo esc_html($src_label); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </p>
                    <p class="wa-hidden-static-value" <?php echo $source === 'static' ? '' : 'style="display:none"'; ?>>
                        <label><?php esc_html_e('Fixed value', 'alchemy-forms'); ?></label>
                        <input type="text" class="widefat" name="wa_fields[<?php echo esc_attr($i); ?>][static_value]" value="<?php echo esc_attr($static_value); ?>">
                    </p>

                <?php else : ?>

                    <?php if ($is_html) : ?>
                        <p>
                            <label><?php esc_html_e('Content (HTML allowed)', 'alchemy-forms'); ?></label>
                            <textarea class="wa-field-label widefat" name="wa_fields[<?php echo esc_attr($i); ?>][content]" rows="4" placeholder="<?php esc_attr_e('Text or HTML to display', 'alchemy-forms'); ?>"><?php echo esc_textarea($content); ?></textarea>
                        </p>
                    <?php else : ?>
                        <p>
                            <label><?php esc_html_e('Label', 'alchemy-forms'); ?></label>
                            <input type="text" class="wa-field-label widefat" name="wa_fields[<?php echo esc_attr($i); ?>][label]" value="<?php echo esc_attr($label); ?>" placeholder="<?php esc_attr_e('Field label', 'alchemy-forms'); ?>">
                        </p>
                        <?php if ($show_placeholder) : ?>
                            <p>
                                <label><?php esc_html_e('Placeholder text (optional)', 'alchemy-forms'); ?></label>
                                <input type="text" class="widefat" name="wa_fields[<?php echo esc_attr($i); ?>][placeholder]" value="<?php echo esc_attr($placeholder); ?>" placeholder="<?php esc_attr_e('e.g. Jane Smith', 'alchemy-forms'); ?>">
                                <span class="description"><?php esc_html_e('A hint shown inside the empty field. This is not a replacement for the label above — screen readers and browser autofill rely on the label, not the placeholder.', 'alchemy-forms'); ?></span>
                            </p>
                        <?php endif; ?>
                    <?php endif; ?>

                    <p class="wa-field-row-inline">
                        <label class="wa-field-width">
                            <?php esc_html_e('Width', 'alchemy-forms'); ?>
                            <select name="wa_fields[<?php echo esc_attr($i); ?>][width]">
                                <option value="full" <?php selected($width, 'full'); ?>><?php esc_html_e('Full width', 'alchemy-forms'); ?></option>
                                <option value="half" <?php selected($width, 'half'); ?>><?php esc_html_e('Half width', 'alchemy-forms'); ?></option>
                            </select>
                        </label>
                        <?php if (!$is_html) : ?>
                            <label class="wa-field-required">
                                <input type="checkbox" name="wa_fields[<?php echo esc_attr($i); ?>][required]" value="1" <?php checked($required); ?>>
                                <?php esc_html_e('Required', 'alchemy-forms'); ?>
                            </label>
                            <label class="wa-field-required">
                                <input type="checkbox" name="wa_fields[<?php echo esc_attr($i); ?>][hide_label]" value="1" <?php checked($hide_label); ?>>
                                <?php esc_html_e('Hide label', 'alchemy-forms'); ?>
                            </label>
                        <?php endif; ?>
                    </p>

                    <?php if ($show_options) : ?>
                        <p class="wa-field-options">
                            <label><?php esc_html_e('Options (one per line)', 'alchemy-forms'); ?></label>
                            <textarea name="wa_fields[<?php echo esc_attr($i); ?>][options]" rows="3" placeholder="<?php esc_attr_e('One option per line', 'alchemy-forms'); ?>"><?php echo esc_textarea($options); ?></textarea>
                        </p>
                    <?php endif; ?>

                    <p class="wa-field-condition-toggle">
                        <label class="wa-field-required">
                            <input type="checkbox" class="wa-condition-enable" <?php checked($condition_enabled); ?>>
                            <?php esc_html_e('Only show this field based on another field', 'alchemy-forms'); ?>
                        </label>
                    </p>
                    <div class="wa-field-condition" <?php echo $condition_enabled ? '' : 'style="display:none"'; ?>>
                        <p class="wa-field-row-inline">
                            <select class="wa-condition-field" name="wa_fields[<?php echo esc_attr($i); ?>][condition][field]" <?php echo $condition_enabled ? '' : 'disabled'; ?>>
                                <option value=""><?php esc_html_e('Select a field…', 'alchemy-forms'); ?></option>
                                <?php foreach ($all_fields as $of) :
                                    if ($of['uid'] === $uid) continue;
                                    $of_label = $of['label'] !== '' ? $of['label'] : (isset($types[$of['type']]) ? $types[$of['type']] : $of['type']);
                                ?>
                                    <option value="<?php echo esc_attr($of['uid']); ?>" data-type="<?php echo esc_attr($of['type']); ?>" data-options="<?php echo esc_attr(wp_json_encode($of['options'])); ?>" <?php selected($condition_field, $of['uid']); ?>><?php echo esc_html($of_label); ?></option>
                                <?php endforeach; ?>
                            </select>
                            <select class="wa-condition-comparator" name="wa_fields[<?php echo esc_attr($i); ?>][condition][comparator]" <?php echo $condition_enabled ? '' : 'disabled'; ?>>
                                <?php foreach (alchemy_forms_condition_comparators() as $key => $comp_label) : ?>
                                    <option value="<?php echo esc_attr($key); ?>" <?php selected($condition_comparator, $key); ?>><?php echo esc_html($comp_label); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </p>
                        <p>
                            <input type="text" class="wa-condition-value-text" name="wa_fields[<?php echo esc_attr($i); ?>][condition][value]" value="<?php echo esc_attr($condition_value); ?>" placeholder="<?php esc_attr_e('Value', 'alchemy-forms'); ?>" <?php echo ($condition_enabled && empty($condition_options)) ? '' : 'disabled'; ?>>
                            <select class="wa-condition-value-select" name="wa_fields[<?php echo esc_attr($i); ?>][condition][value]" <?php echo ($condition_enabled && !empty($condition_options)) ? '' : 'disabled'; ?>>
                                <?php foreach ($condition_options as $opt) : ?>
                                    <option value="<?php echo esc_attr($opt); ?>" <?php selected($condition_value, $opt); ?>><?php echo esc_html($opt); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </p>
                    </div>

                <?php endif; ?>
            </div>
        </div>
    </div>
    <?php
}

function alchemy_forms_settings_metabox($post) {
    $settings = get_post_meta($post->ID, '_wa_form_settings', true);
    if (!is_array($settings)) $settings = [];
    $recipients  = alchemy_forms_parse_recipients(isset($settings['recipient']) ? $settings['recipient'] : '');
    $submit_text = isset($settings['submit_text']) ? $settings['submit_text'] : 'Submit';
    $success_msg = isset($settings['success_msg']) ? $settings['success_msg'] : "Thanks — your submission has been received.";
    ?>
    <p>
        <label for="wa_recipient"><strong><?php esc_html_e('Send submissions to', 'alchemy-forms'); ?></strong></label>
        <input type="email" multiple id="wa_recipient" name="wa_settings[recipient]" value="<?php echo esc_attr(implode(', ', $recipients)); ?>" class="widefat">
        <span class="description"><?php esc_html_e('One or more addresses, separated by commas.', 'alchemy-forms'); ?></span>
    </p>
    <p>
        <label for="wa_submit_text"><strong><?php esc_html_e('Submit button text', 'alchemy-forms'); ?></strong></label>
        <input type="text" id="wa_submit_text" name="wa_settings[submit_text]" value="<?php echo esc_attr($submit_text); ?>" class="widefat">
    </p>
    <p>
        <label for="wa_success_msg"><strong><?php esc_html_e('Success message', 'alchemy-forms'); ?></strong></label>
        <textarea id="wa_success_msg" name="wa_settings[success_msg]" rows="3" class="widefat"><?php echo esc_textarea($success_msg); ?></textarea>
    </p>
    <?php
}

function alchemy_forms_style_metabox($post) {
    $settings = get_post_meta($post->ID, '_wa_form_settings', true);
    $style    = (is_array($settings) && isset($settings['style']) && is_array($settings['style'])) ? $settings['style'] : [];

    $d = alchemy_forms_style_defaults();

    $primary            = alchemy_forms_sanitize_hex($style['primary_color'] ?? '', $d['primary_color']);
    $accent             = alchemy_forms_sanitize_hex($style['accent_color'] ?? '', $d['accent_color']);
    $border             = alchemy_forms_sanitize_hex($style['border_color'] ?? '', $d['border_color']);
    $placeholder        = alchemy_forms_sanitize_hex($style['placeholder_color'] ?? '', $d['placeholder_color']);
    $radius             = alchemy_forms_sanitize_px($style['radius'] ?? null, $d['radius']);
    $font               = !empty($style['font']) ? $style['font'] : 'default';
    $label_color        = alchemy_forms_sanitize_hex($style['label_color'] ?? '', $d['label_color']);
    $label_font_size    = alchemy_forms_sanitize_px($style['label_font_size'] ?? null, $d['label_font_size']);
    $field_gap          = alchemy_forms_sanitize_px($style['field_gap'] ?? null, $d['field_gap']);
    $input_padding      = alchemy_forms_sanitize_px($style['input_padding'] ?? null, $d['input_padding']);
    $input_bg           = alchemy_forms_sanitize_hex($style['input_bg_color'] ?? '', $d['input_bg_color']);
    $button_bg          = alchemy_forms_sanitize_hex($style['button_bg_color'] ?? '', $d['button_bg_color']);
    $button_hover       = alchemy_forms_sanitize_hex($style['button_hover_color'] ?? '', $d['button_hover_color']);
    $button_padding     = alchemy_forms_sanitize_px($style['button_padding'] ?? null, $d['button_padding']);
    $button_font_size   = alchemy_forms_sanitize_px($style['button_font_size'] ?? null, $d['button_font_size']);
    $button_width_opts  = alchemy_forms_button_width_options();
    $button_align_opts  = alchemy_forms_button_align_options();
    $button_width       = (isset($style['button_width']) && array_key_exists($style['button_width'], $button_width_opts)) ? $style['button_width'] : $d['button_width'];
    $button_align       = (isset($style['button_align']) && array_key_exists($style['button_align'], $button_align_opts)) ? $style['button_align'] : $d['button_align'];
    $container_bg       = alchemy_forms_sanitize_hex($style['container_bg_color'] ?? '', $d['container_bg_color']);
    $container_opacity  = alchemy_forms_sanitize_px($style['container_bg_opacity'] ?? null, $d['container_bg_opacity'], 0, 100);
    $container_padding  = alchemy_forms_sanitize_px($style['container_padding'] ?? null, $d['container_padding']);
    $container_border_w = alchemy_forms_sanitize_px($style['container_border_width'] ?? null, $d['container_border_width'], 0, 50);
    ?>
    <h4><?php esc_html_e('Colors', 'alchemy-forms'); ?></h4>
    <p>
        <label for="wa_style_primary"><?php esc_html_e('Primary color', 'alchemy-forms'); ?></label><br>
        <input type="text" id="wa_style_primary" class="wa-color-field" name="wa_settings[style][primary_color]" value="<?php echo esc_attr($primary); ?>">
    </p>
    <p>
        <label for="wa_style_accent"><?php esc_html_e('Accent color', 'alchemy-forms'); ?></label><br>
        <input type="text" id="wa_style_accent" class="wa-color-field" name="wa_settings[style][accent_color]" value="<?php echo esc_attr($accent); ?>">
    </p>
    <p>
        <label for="wa_style_border"><?php esc_html_e('Border color', 'alchemy-forms'); ?></label><br>
        <input type="text" id="wa_style_border" class="wa-color-field" name="wa_settings[style][border_color]" value="<?php echo esc_attr($border); ?>">
    </p>
    <p>
        <label for="wa_style_placeholder"><?php esc_html_e('Placeholder text color', 'alchemy-forms'); ?></label><br>
        <input type="text" id="wa_style_placeholder" class="wa-color-field" name="wa_settings[style][placeholder_color]" value="<?php echo esc_attr($placeholder); ?>">
    </p>
    <p>
        <label for="wa_style_radius"><?php esc_html_e('Corner radius (px)', 'alchemy-forms'); ?></label><br>
        <input type="number" id="wa_style_radius" class="small-text" name="wa_settings[style][radius]" value="<?php echo esc_attr($radius); ?>" min="0" max="999" step="1">
    </p>
    <p>
        <label for="wa_style_font"><?php esc_html_e('Font pairing', 'alchemy-forms'); ?></label>
        <select id="wa_style_font" name="wa_settings[style][font]" class="widefat">
            <?php foreach (alchemy_forms_font_presets() as $key => $preset) : ?>
                <option value="<?php echo esc_attr($key); ?>" <?php selected($font, $key); ?>><?php echo esc_html($preset['label']); ?></option>
            <?php endforeach; ?>
        </select>
    </p>

    <h4><?php esc_html_e('Label', 'alchemy-forms'); ?></h4>
    <p>
        <label for="wa_style_label_color"><?php esc_html_e('Text color', 'alchemy-forms'); ?></label><br>
        <input type="text" id="wa_style_label_color" class="wa-color-field" name="wa_settings[style][label_color]" value="<?php echo esc_attr($label_color); ?>">
    </p>
    <p>
        <label for="wa_style_label_font_size"><?php esc_html_e('Font size (px)', 'alchemy-forms'); ?></label><br>
        <input type="number" id="wa_style_label_font_size" class="small-text" name="wa_settings[style][label_font_size]" value="<?php echo esc_attr($label_font_size); ?>" min="0" max="999" step="1">
    </p>

    <h4><?php esc_html_e('Inputs', 'alchemy-forms'); ?></h4>
    <p>
        <label for="wa_style_field_gap"><?php esc_html_e('Gap between fields (px)', 'alchemy-forms'); ?></label><br>
        <input type="number" id="wa_style_field_gap" class="small-text" name="wa_settings[style][field_gap]" value="<?php echo esc_attr($field_gap); ?>" min="0" max="999" step="1">
    </p>
    <p>
        <label for="wa_style_input_padding"><?php esc_html_e('Padding (px)', 'alchemy-forms'); ?></label><br>
        <input type="number" id="wa_style_input_padding" class="small-text" name="wa_settings[style][input_padding]" value="<?php echo esc_attr($input_padding); ?>" min="0" max="999" step="1">
    </p>
    <p>
        <label for="wa_style_input_bg"><?php esc_html_e('Background color', 'alchemy-forms'); ?></label><br>
        <input type="text" id="wa_style_input_bg" class="wa-color-field" name="wa_settings[style][input_bg_color]" value="<?php echo esc_attr($input_bg); ?>">
    </p>

    <h4><?php esc_html_e('Button', 'alchemy-forms'); ?></h4>
    <p>
        <label for="wa_style_button_bg"><?php esc_html_e('Background color', 'alchemy-forms'); ?></label><br>
        <input type="text" id="wa_style_button_bg" class="wa-color-field" name="wa_settings[style][button_bg_color]" value="<?php echo esc_attr($button_bg); ?>">
    </p>
    <p>
        <label for="wa_style_button_hover"><?php esc_html_e('Hover color', 'alchemy-forms'); ?></label><br>
        <input type="text" id="wa_style_button_hover" class="wa-color-field" name="wa_settings[style][button_hover_color]" value="<?php echo esc_attr($button_hover); ?>">
    </p>
    <p>
        <label for="wa_style_button_padding"><?php esc_html_e('Padding (px)', 'alchemy-forms'); ?></label><br>
        <input type="number" id="wa_style_button_padding" class="small-text" name="wa_settings[style][button_padding]" value="<?php echo esc_attr($button_padding); ?>" min="0" max="999" step="1">
    </p>
    <p>
        <label for="wa_style_button_font_size"><?php esc_html_e('Font size (px)', 'alchemy-forms'); ?></label><br>
        <input type="number" id="wa_style_button_font_size" class="small-text" name="wa_settings[style][button_font_size]" value="<?php echo esc_attr($button_font_size); ?>" min="0" max="999" step="1">
    </p>
    <p>
        <label for="wa_style_button_width"><?php esc_html_e('Width', 'alchemy-forms'); ?></label>
        <select id="wa_style_button_width" name="wa_settings[style][button_width]" class="widefat">
            <?php foreach ($button_width_opts as $key => $label) : ?>
                <option value="<?php echo esc_attr($key); ?>" <?php selected($button_width, $key); ?>><?php echo esc_html($label); ?></option>
            <?php endforeach; ?>
        </select>
    </p>
    <p>
        <label for="wa_style_button_align"><?php esc_html_e('Alignment (when Auto width)', 'alchemy-forms'); ?></label>
        <select id="wa_style_button_align" name="wa_settings[style][button_align]" class="widefat">
            <?php foreach ($button_align_opts as $key => $label) : ?>
                <option value="<?php echo esc_attr($key); ?>" <?php selected($button_align, $key); ?>><?php echo esc_html($label); ?></option>
            <?php endforeach; ?>
        </select>
    </p>

    <h4><?php esc_html_e('Container', 'alchemy-forms'); ?></h4>
    <p>
        <label for="wa_style_container_bg"><?php esc_html_e('Background color', 'alchemy-forms'); ?></label><br>
        <input type="text" id="wa_style_container_bg" class="wa-color-field" name="wa_settings[style][container_bg_color]" value="<?php echo esc_attr($container_bg); ?>">
    </p>
    <p>
        <label for="wa_style_container_opacity"><?php esc_html_e('Background opacity', 'alchemy-forms'); ?> (<span id="wa_style_container_opacity_val"><?php echo esc_html($container_opacity); ?></span>%)</label><br>
        <input type="range" id="wa_style_container_opacity" name="wa_settings[style][container_bg_opacity]" value="<?php echo esc_attr($container_opacity); ?>" min="0" max="100" step="1">
    </p>
    <p>
        <label for="wa_style_container_padding"><?php esc_html_e('Padding (px)', 'alchemy-forms'); ?></label><br>
        <input type="number" id="wa_style_container_padding" class="small-text" name="wa_settings[style][container_padding]" value="<?php echo esc_attr($container_padding); ?>" min="0" max="999" step="1">
    </p>
    <p>
        <label for="wa_style_container_border_width"><?php esc_html_e('Border width (px)', 'alchemy-forms'); ?></label><br>
        <input type="number" id="wa_style_container_border_width" class="small-text" name="wa_settings[style][container_border_width]" value="<?php echo esc_attr($container_border_w); ?>" min="0" max="50" step="1">
        <span class="description"><?php esc_html_e('Uses the Border color above. Set to 0 to remove.', 'alchemy-forms'); ?></span>
    </p>
    <?php
}

function alchemy_forms_integrations_metabox($post) {
    $settings = get_post_meta($post->ID, '_wa_form_settings', true);
    $flodesk  = (is_array($settings) && isset($settings['integrations']['flodesk']) && is_array($settings['integrations']['flodesk'])) ? $settings['integrations']['flodesk'] : [];

    $enabled     = !empty($flodesk['enabled']);
    $api_key     = isset($flodesk['api_key']) ? $flodesk['api_key'] : '';
    $segment_ids = (isset($flodesk['segment_ids']) && is_array($flodesk['segment_ids'])) ? $flodesk['segment_ids'] : [];
    $email_field = isset($flodesk['email_field']) ? $flodesk['email_field'] : '';
    $first_field = isset($flodesk['first_name_field']) ? $flodesk['first_name_field'] : '';
    $last_field  = isset($flodesk['last_name_field']) ? $flodesk['last_name_field'] : '';

    $cached_segments = get_transient('alchemy_forms_flodesk_segments_' . $post->ID);
    if (!is_array($cached_segments)) $cached_segments = [];
    $known_segment_ids = wp_list_pluck($cached_segments, 'id');
    $unlisted_segment_ids = array_diff($segment_ids, $known_segment_ids);

    // Fields the visitor can actually fill in — same exclusion as everywhere else
    // that offers a "pick one of this form's fields" dropdown.
    $form_fields = get_post_meta($post->ID, '_wa_form_fields', true);
    if (!is_array($form_fields)) $form_fields = [];
    $noninput_types = alchemy_forms_noninput_field_types();
    $picker = [];
    foreach ($form_fields as $f) {
        if (empty($f['uid']) || empty($f['label'])) continue;
        if (isset($f['type']) && in_array($f['type'], $noninput_types, true)) continue;
        $picker[] = ['uid' => $f['uid'], 'label' => $f['label']];
    }
    ?>
    <p>
        <label>
            <input type="checkbox" name="wa_settings[integrations][flodesk][enabled]" value="1" <?php checked($enabled); ?>>
            <?php esc_html_e('Add subscribers to Flodesk on submission', 'alchemy-forms'); ?>
        </label>
    </p>
    <p>
        <label for="wa_flodesk_api_key"><?php esc_html_e('API key', 'alchemy-forms'); ?></label>
        <input type="text" id="wa_flodesk_api_key" name="wa_settings[integrations][flodesk][api_key]" value="<?php echo esc_attr($api_key); ?>" class="widefat" autocomplete="off">
    </p>
    <p>
        <label for="wa_flodesk_email_field"><?php esc_html_e('Email field', 'alchemy-forms'); ?></label>
        <select id="wa_flodesk_email_field" name="wa_settings[integrations][flodesk][email_field]" class="widefat">
            <option value=""><?php esc_html_e('— Select a field —', 'alchemy-forms'); ?></option>
            <?php foreach ($picker as $p) : ?>
                <option value="<?php echo esc_attr($p['uid']); ?>" <?php selected($email_field, $p['uid']); ?>><?php echo esc_html($p['label']); ?></option>
            <?php endforeach; ?>
        </select>
    </p>
    <p>
        <label for="wa_flodesk_first_name_field"><?php esc_html_e('First name field (optional)', 'alchemy-forms'); ?></label>
        <select id="wa_flodesk_first_name_field" name="wa_settings[integrations][flodesk][first_name_field]" class="widefat">
            <option value=""><?php esc_html_e('— None —', 'alchemy-forms'); ?></option>
            <?php foreach ($picker as $p) : ?>
                <option value="<?php echo esc_attr($p['uid']); ?>" <?php selected($first_field, $p['uid']); ?>><?php echo esc_html($p['label']); ?></option>
            <?php endforeach; ?>
        </select>
    </p>
    <p>
        <label for="wa_flodesk_last_name_field"><?php esc_html_e('Last name field (optional)', 'alchemy-forms'); ?></label>
        <select id="wa_flodesk_last_name_field" name="wa_settings[integrations][flodesk][last_name_field]" class="widefat">
            <option value=""><?php esc_html_e('— None —', 'alchemy-forms'); ?></option>
            <?php foreach ($picker as $p) : ?>
                <option value="<?php echo esc_attr($p['uid']); ?>" <?php selected($last_field, $p['uid']); ?>><?php echo esc_html($p['label']); ?></option>
            <?php endforeach; ?>
        </select>
    </p>
    <p>
        <label><?php esc_html_e('Segments', 'alchemy-forms'); ?></label>
    </p>
    <div id="wa-flodesk-segments-wrap" data-post-id="<?php echo (int) $post->ID; ?>" data-nonce="<?php echo esc_attr(wp_create_nonce('alchemy_forms_flodesk_segments_' . $post->ID)); ?>">
        <div id="wa-flodesk-segments-list">
            <?php foreach ($cached_segments as $segment) : ?>
                <label class="wa-choice-option" style="display:block;">
                    <input type="checkbox" name="wa_settings[integrations][flodesk][segment_ids][]" value="<?php echo esc_attr($segment['id']); ?>" <?php checked(in_array($segment['id'], $segment_ids, true)); ?>>
                    <?php echo esc_html($segment['name']); ?>
                    <?php if (isset($segment['subscribers'])) : ?>
                        <span class="description">(<?php echo esc_html(number_format_i18n($segment['subscribers'])); ?>)</span>
                    <?php endif; ?>
                </label>
            <?php endforeach; ?>
            <?php foreach ($unlisted_segment_ids as $legacy_id) : ?>
                <label class="wa-choice-option" style="display:block;">
                    <input type="checkbox" name="wa_settings[integrations][flodesk][segment_ids][]" value="<?php echo esc_attr($legacy_id); ?>" checked>
                    <?php echo esc_html($legacy_id); ?>
                    <span class="description"><?php esc_html_e('(not in the last refresh — may be renamed or deleted)', 'alchemy-forms'); ?></span>
                </label>
            <?php endforeach; ?>
            <?php if (empty($cached_segments) && empty($unlisted_segment_ids)) : ?>
                <p class="description"><?php esc_html_e('No segments loaded yet — click Refresh to fetch them from Flodesk.', 'alchemy-forms'); ?></p>
            <?php endif; ?>
        </div>
        <p>
            <button type="button" class="button button-secondary" id="wa-flodesk-refresh-segments"><?php esc_html_e('Refresh segments from Flodesk', 'alchemy-forms'); ?></button>
            <span id="wa-flodesk-segments-status" class="description"></span>
        </p>
    </div>
    <?php
}

function alchemy_forms_usage_metabox($post) {
    ?>
    <p><?php esc_html_e('Add this form to any page or Beaver Builder text module:', 'alchemy-forms'); ?></p>
    <p><code>[wa_form id="<?php echo (int) $post->ID; ?>"]</code></p>
    <p class="description"><?php esc_html_e('Uploads land in the Media Library. Entries are stored under Alchemy Forms → Entries and emailed to the recipient(s) above.', 'alchemy-forms'); ?></p>
    <?php
}

/* -------------------------------------------------------------------------
 * Save
 * ---------------------------------------------------------------------- */
add_action('save_post_wa_form', function ($post_id) {
    if (!isset($_POST['wa_form_nonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['wa_form_nonce'])), 'wa_form_save')) return;
    if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) return;
    if (!current_user_can('edit_post', $post_id)) return;

    $types        = array_keys(alchemy_forms_field_types());
    $option_types = alchemy_forms_option_field_types();
    $fields       = [];
    if (isset($_POST['wa_fields']) && is_array($_POST['wa_fields'])) {
        foreach (wp_unslash($_POST['wa_fields']) as $f) {
            $type    = (isset($f['type']) && in_array($f['type'], $types, true)) ? $f['type'] : 'text';
            $label   = isset($f['label']) ? sanitize_text_field($f['label']) : '';
            $content = isset($f['content']) ? wp_kses_post($f['content']) : '';

            // Label is how every normal field is kept from being an empty leftover
            // row, but html fields don't use it (content instead) and a page break's
            // title is optional, so each type needs its own "keep this row?" rule.
            if ($type === 'html') {
                if ($content === '') continue;
            } elseif ($type !== 'page_break' && $label === '') {
                continue;
            }

            $field = [
                'label'      => $label,
                'type'       => $type,
                'required'   => !empty($f['required']) ? 1 : 0,
                'hide_label' => !empty($f['hide_label']) ? 1 : 0,
                'width'      => (isset($f['width']) && $f['width'] === 'half') ? 'half' : 'full',
                'uid'        => (!empty($f['uid']) && is_string($f['uid'])) ? sanitize_text_field($f['uid']) : wp_generate_uuid4(),
            ];

            if ($type === 'html') {
                $field['content'] = $content;
            } elseif ($type === 'hidden') {
                $source_keys = array_keys(alchemy_forms_hidden_sources());
                $field['source'] = (isset($f['source']) && in_array($f['source'], $source_keys, true)) ? $f['source'] : 'post_title';
                $field['static_value'] = isset($f['static_value']) ? sanitize_text_field($f['static_value']) : '';
            } elseif (in_array($type, alchemy_forms_placeholder_eligible_types(), true)) {
                $field['placeholder'] = isset($f['placeholder']) ? sanitize_text_field($f['placeholder']) : '';
            }

            if (in_array($type, $option_types, true)) {
                $raw_options = isset($f['options']) ? (string) $f['options'] : '';
                $lines       = preg_split('/\r\n|\r|\n/', $raw_options);
                $options     = [];
                foreach ($lines as $line) {
                    $line = sanitize_text_field($line);
                    if ($line !== '') $options[] = $line;
                }
                $field['options'] = $options;
            }

            // Condition can reference a sibling field by uid; validated below once
            // every field's final uid is known (not yet, mid-loop).
            $field['_raw_condition'] = (isset($f['condition']) && is_array($f['condition'])) ? $f['condition'] : [];

            $fields[] = $field;
        }
    }

    // Second pass: resolve each field's condition now that every uid is known.
    $known_uids       = array_column($fields, 'uid');
    $comparator_keys  = array_keys(alchemy_forms_condition_comparators());
    foreach ($fields as &$field) {
        $raw = isset($field['_raw_condition']) ? $field['_raw_condition'] : [];
        unset($field['_raw_condition']);

        $trigger_uid = isset($raw['field']) ? sanitize_text_field($raw['field']) : '';
        if ($trigger_uid !== '' && $trigger_uid !== $field['uid'] && in_array($trigger_uid, $known_uids, true)) {
            $comparator = (isset($raw['comparator']) && in_array($raw['comparator'], $comparator_keys, true)) ? $raw['comparator'] : 'equals';
            $field['condition'] = [
                'field'      => $trigger_uid,
                'comparator' => $comparator,
                'value'      => isset($raw['value']) ? sanitize_text_field($raw['value']) : '',
            ];
        }
    }
    unset($field);

    update_post_meta($post_id, '_wa_form_fields', $fields);

    $settings = ['recipient' => [get_option('admin_email')], 'submit_text' => 'Submit', 'success_msg' => ''];
    if (isset($_POST['wa_settings']) && is_array($_POST['wa_settings'])) {
        $s = wp_unslash($_POST['wa_settings']);
        $settings['recipient']   = alchemy_forms_parse_recipients(isset($s['recipient']) ? $s['recipient'] : '');
        $settings['submit_text'] = isset($s['submit_text']) && $s['submit_text'] !== '' ? sanitize_text_field($s['submit_text']) : 'Submit';
        $settings['success_msg'] = isset($s['success_msg']) ? sanitize_textarea_field($s['success_msg']) : '';

        $style_in         = (isset($s['style']) && is_array($s['style'])) ? $s['style'] : [];
        $font_keys        = array_keys(alchemy_forms_font_presets());
        $button_width_keys = array_keys(alchemy_forms_button_width_options());
        $button_align_keys = array_keys(alchemy_forms_button_align_options());
        $d                = alchemy_forms_style_defaults();

        $settings['style'] = [
            'primary_color'        => alchemy_forms_sanitize_hex($style_in['primary_color'] ?? '', $d['primary_color']),
            'accent_color'         => alchemy_forms_sanitize_hex($style_in['accent_color'] ?? '', $d['accent_color']),
            'border_color'         => alchemy_forms_sanitize_hex($style_in['border_color'] ?? '', $d['border_color']),
            'placeholder_color'    => alchemy_forms_sanitize_hex($style_in['placeholder_color'] ?? '', $d['placeholder_color']),
            'radius'               => alchemy_forms_sanitize_px($style_in['radius'] ?? null, $d['radius']),
            'font'                 => (isset($style_in['font']) && in_array($style_in['font'], $font_keys, true)) ? $style_in['font'] : 'default',
            'label_color'          => alchemy_forms_sanitize_hex($style_in['label_color'] ?? '', $d['label_color']),
            'label_font_size'      => alchemy_forms_sanitize_px($style_in['label_font_size'] ?? null, $d['label_font_size']),
            'field_gap'            => alchemy_forms_sanitize_px($style_in['field_gap'] ?? null, $d['field_gap']),
            'input_padding'        => alchemy_forms_sanitize_px($style_in['input_padding'] ?? null, $d['input_padding']),
            'input_bg_color'       => alchemy_forms_sanitize_hex($style_in['input_bg_color'] ?? '', $d['input_bg_color']),
            'button_bg_color'      => alchemy_forms_sanitize_hex($style_in['button_bg_color'] ?? '', $d['button_bg_color']),
            'button_hover_color'   => alchemy_forms_sanitize_hex($style_in['button_hover_color'] ?? '', $d['button_hover_color']),
            'button_padding'       => alchemy_forms_sanitize_px($style_in['button_padding'] ?? null, $d['button_padding']),
            'button_font_size'     => alchemy_forms_sanitize_px($style_in['button_font_size'] ?? null, $d['button_font_size']),
            'button_width'         => (isset($style_in['button_width']) && in_array($style_in['button_width'], $button_width_keys, true)) ? $style_in['button_width'] : $d['button_width'],
            'button_align'         => (isset($style_in['button_align']) && in_array($style_in['button_align'], $button_align_keys, true)) ? $style_in['button_align'] : $d['button_align'],
            'container_bg_color'   => alchemy_forms_sanitize_hex($style_in['container_bg_color'] ?? '', $d['container_bg_color']),
            'container_bg_opacity' => alchemy_forms_sanitize_px($style_in['container_bg_opacity'] ?? null, $d['container_bg_opacity'], 0, 100),
            'container_padding'    => alchemy_forms_sanitize_px($style_in['container_padding'] ?? null, $d['container_padding']),
            'container_border_width' => alchemy_forms_sanitize_px($style_in['container_border_width'] ?? null, $d['container_border_width'], 0, 50),
        ];

        $flodesk_in = (isset($s['integrations']['flodesk']) && is_array($s['integrations']['flodesk'])) ? $s['integrations']['flodesk'] : [];

        // Checkboxes from the segment picker submit an array; a value saved
        // before that existed is still a comma/space-separated string.
        $flodesk_segment_ids = [];
        if (!empty($flodesk_in['segment_ids'])) {
            $raw_segment_ids = is_array($flodesk_in['segment_ids'])
                ? $flodesk_in['segment_ids']
                : preg_split('/[,\s]+/', (string) $flodesk_in['segment_ids']);
            foreach ($raw_segment_ids as $segment_id) {
                $segment_id = sanitize_text_field($segment_id);
                if ($segment_id !== '') $flodesk_segment_ids[] = $segment_id;
            }
        }

        // Field pickers store a uid, not validated against known fields here (same
        // as elsewhere) — a stale uid just means the integration finds no value to
        // send at submission time, so there's nothing unsafe about leaving it as-is.
        $settings['integrations'] = [
            'flodesk' => [
                'enabled'          => !empty($flodesk_in['enabled']) ? 1 : 0,
                'api_key'          => isset($flodesk_in['api_key']) ? sanitize_text_field($flodesk_in['api_key']) : '',
                'segment_ids'      => $flodesk_segment_ids,
                'email_field'      => isset($flodesk_in['email_field']) ? sanitize_text_field($flodesk_in['email_field']) : '',
                'first_name_field' => isset($flodesk_in['first_name_field']) ? sanitize_text_field($flodesk_in['first_name_field']) : '',
                'last_name_field'  => isset($flodesk_in['last_name_field']) ? sanitize_text_field($flodesk_in['last_name_field']) : '',
            ],
        ];
    }
    update_post_meta($post_id, '_wa_form_settings', $settings);
});

/* -------------------------------------------------------------------------
 * Admin assets
 * ---------------------------------------------------------------------- */
add_action('admin_enqueue_scripts', function ($hook) {
    $screen = get_current_screen();
    if (!$screen || $screen->post_type !== 'wa_form') return;

    wp_enqueue_style('alchemy-forms-admin', ALCHEMY_FORMS_URL . 'assets/admin.css', [], ALCHEMY_FORMS_VERSION);

    if (in_array($hook, ['post.php', 'post-new.php'], true)) {
        wp_enqueue_style('wp-color-picker');
        wp_enqueue_script('alchemy-forms-admin', ALCHEMY_FORMS_URL . 'assets/admin.js', ['jquery', 'jquery-ui-sortable', 'wp-color-picker'], ALCHEMY_FORMS_VERSION, true);
    }
});
