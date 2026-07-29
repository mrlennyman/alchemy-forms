<?php
if (!defined('ABSPATH')) exit;

/* -------------------------------------------------------------------------
 * Post type
 * ---------------------------------------------------------------------- */
add_action('init', function () {
    register_post_type('wa_form', [
        'labels' => [
            'name'          => __('WA Forms', 'wa-forms'),
            'singular_name' => __('Form', 'wa-forms'),
            'add_new_item'  => __('Add New Form', 'wa-forms'),
            'edit_item'     => __('Edit Form', 'wa-forms'),
            'menu_name'     => __('WA Forms', 'wa-forms'),
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
    $cols['wa_shortcode'] = __('Shortcode', 'wa-forms');
    $cols['wa_entries']   = __('Entries', 'wa-forms');
    return $cols;
});
add_action('manage_wa_form_posts_custom_column', function ($col, $post_id) {
    if ($col === 'wa_shortcode') {
        echo '<code>[wa_form id="' . (int) $post_id . '"]</code>';
    }
    if ($col === 'wa_entries') {
        $count = wa_forms_count_entries($post_id);
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
        $classes .= ' wa-forms-builder';
    }
    return $classes;
});

/* -------------------------------------------------------------------------
 * Metaboxes
 * ---------------------------------------------------------------------- */
add_action('add_meta_boxes', function () {
    add_meta_box('wa_form_fields', __('Form Fields', 'wa-forms'), 'wa_forms_fields_metabox', 'wa_form', 'normal', 'high');
    add_meta_box('wa_form_settings', __('Form Settings', 'wa-forms'), 'wa_forms_settings_metabox', 'wa_form', 'side', 'default');
    add_meta_box('wa_form_style', __('Style', 'wa-forms'), 'wa_forms_style_metabox', 'wa_form', 'side', 'default');
    add_meta_box('wa_form_usage', __('Usage', 'wa-forms'), 'wa_forms_usage_metabox', 'wa_form', 'side', 'low');
});

function wa_forms_fields_metabox($post) {
    $fields = get_post_meta($post->ID, '_wa_form_fields', true);
    if (!is_array($fields)) $fields = [];
    $types        = wa_forms_field_types();
    $icons        = wa_forms_field_type_icons();
    $option_types = wa_forms_option_field_types();

    // Lightweight list of every field, for populating each card's "which field" condition dropdown.
    $all_fields = [];
    foreach ($fields as $f) {
        if (empty($f['uid'])) continue; // gets one on next save; not selectable as a trigger until then
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
            <p class="description"><?php esc_html_e('Click or drag a field type onto the canvas to add it. Drag cards to reorder.', 'wa-forms'); ?></p>
            <button type="button" class="button button-secondary" id="wa-open-settings">
                <span class="dashicons dashicons-admin-generic"></span> <?php esc_html_e('Settings', 'wa-forms'); ?>
            </button>
        </div>
        <div class="wa-builder-body">
            <div class="wa-palette">
                <h3><?php esc_html_e('Field Types', 'wa-forms'); ?></h3>
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
                    <?php esc_html_e('No fields yet — click or drag a field type from the left to get started.', 'wa-forms'); ?>
                </p>
                <div id="wa-fields-list">
                    <?php foreach ($fields as $i => $f) : ?>
                        <?php wa_forms_field_row($i, $f, $types, $icons, $all_fields); ?>
                    <?php endforeach; ?>
                </div>
            </div>
            <div id="wa-style-panel-slot" class="wa-style-panel"></div>
        </div>
    </div>

    <?php foreach ($types as $key => $name) :
        $default_field = [
            'label'      => sprintf(__('New %s', 'wa-forms'), $name),
            'type'       => $key,
            'required'   => 0,
            'hide_label' => 0,
            'width'      => 'full',
            'options'    => in_array($key, $option_types, true) ? [__('Option 1', 'wa-forms'), __('Option 2', 'wa-forms')] : [],
            'uid'        => '',
            'condition'  => [],
        ];
    ?>
        <script type="text/template" id="wa-field-template-<?php echo esc_attr($key); ?>">
            <?php wa_forms_field_row('{{i}}', $default_field, $types, $icons, $all_fields); ?>
        </script>
    <?php endforeach; ?>
    <?php
}

function wa_forms_field_row($i, $f, $types, $icons = null, $all_fields = []) {
    if ($icons === null) $icons = wa_forms_field_type_icons();

    $label        = isset($f['label']) ? $f['label'] : '';
    $type         = isset($f['type']) ? $f['type'] : 'text';
    $required     = !empty($f['required']);
    $hide_label   = !empty($f['hide_label']);
    $width        = (isset($f['width']) && $f['width'] === 'half') ? 'half' : 'full';
    $uid          = isset($f['uid']) ? $f['uid'] : '';
    $option_types = wa_forms_option_field_types();
    $options      = (isset($f['options']) && is_array($f['options'])) ? implode("\n", $f['options']) : '';
    $show_options = in_array($type, $option_types, true);
    $icon         = isset($icons[$type]) ? $icons[$type] : 'dashicons-admin-generic';
    $type_label   = isset($types[$type]) ? $types[$type] : $type;
    $summary      = trim($type_label
        . ($width === 'half' ? ' · ' . __('Half width', 'wa-forms') : '')
        . ($required ? ' · ' . __('Required', 'wa-forms') : '')
        . ($hide_label ? ' · ' . __('Label hidden', 'wa-forms') : ''));

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
    <div class="wa-field-item" data-type="<?php echo esc_attr($type); ?>">
        <div class="wa-field-card">
            <div class="wa-field-card-header">
                <span class="wa-field-handle dashicons dashicons-menu" title="<?php esc_attr_e('Drag to reorder', 'wa-forms'); ?>"></span>
                <span class="wa-field-icon dashicons <?php echo esc_attr($icon); ?>"></span>
                <span class="wa-field-summary">
                    <span class="wa-field-summary-label"><?php echo esc_html($label !== '' ? $label : $type_label); ?></span>
                    <span class="wa-field-summary-meta"><?php echo esc_html($summary); ?></span>
                </span>
                <button type="button" class="button-link wa-field-toggle" title="<?php esc_attr_e('Edit field', 'wa-forms'); ?>"><span class="dashicons dashicons-arrow-down-alt2"></span></button>
                <button type="button" class="button-link wa-remove-field" title="<?php esc_attr_e('Remove field', 'wa-forms'); ?>"><span class="dashicons dashicons-trash"></span></button>
            </div>
            <div class="wa-field-card-body">
                <input type="hidden" class="wa-field-type" name="wa_fields[<?php echo esc_attr($i); ?>][type]" value="<?php echo esc_attr($type); ?>">
                <input type="hidden" class="wa-field-uid" name="wa_fields[<?php echo esc_attr($i); ?>][uid]" value="<?php echo esc_attr($uid); ?>">
                <p>
                    <label><?php esc_html_e('Label', 'wa-forms'); ?></label>
                    <input type="text" class="wa-field-label widefat" name="wa_fields[<?php echo esc_attr($i); ?>][label]" value="<?php echo esc_attr($label); ?>" placeholder="<?php esc_attr_e('Field label', 'wa-forms'); ?>">
                </p>
                <p class="wa-field-row-inline">
                    <label class="wa-field-width">
                        <?php esc_html_e('Width', 'wa-forms'); ?>
                        <select name="wa_fields[<?php echo esc_attr($i); ?>][width]">
                            <option value="full" <?php selected($width, 'full'); ?>><?php esc_html_e('Full width', 'wa-forms'); ?></option>
                            <option value="half" <?php selected($width, 'half'); ?>><?php esc_html_e('Half width', 'wa-forms'); ?></option>
                        </select>
                    </label>
                    <label class="wa-field-required">
                        <input type="checkbox" name="wa_fields[<?php echo esc_attr($i); ?>][required]" value="1" <?php checked($required); ?>>
                        <?php esc_html_e('Required', 'wa-forms'); ?>
                    </label>
                    <label class="wa-field-required">
                        <input type="checkbox" name="wa_fields[<?php echo esc_attr($i); ?>][hide_label]" value="1" <?php checked($hide_label); ?>>
                        <?php esc_html_e('Hide label', 'wa-forms'); ?>
                    </label>
                </p>
                <p class="wa-field-options" <?php echo $show_options ? '' : 'style="display:none"'; ?>>
                    <label><?php esc_html_e('Options (one per line)', 'wa-forms'); ?></label>
                    <textarea name="wa_fields[<?php echo esc_attr($i); ?>][options]" rows="3" placeholder="<?php esc_attr_e('One option per line', 'wa-forms'); ?>"><?php echo esc_textarea($options); ?></textarea>
                </p>
                <p class="wa-field-condition-toggle">
                    <label class="wa-field-required">
                        <input type="checkbox" class="wa-condition-enable" <?php checked($condition_enabled); ?>>
                        <?php esc_html_e('Only show this field based on another field', 'wa-forms'); ?>
                    </label>
                </p>
                <div class="wa-field-condition" <?php echo $condition_enabled ? '' : 'style="display:none"'; ?>>
                    <p class="wa-field-row-inline">
                        <select class="wa-condition-field" name="wa_fields[<?php echo esc_attr($i); ?>][condition][field]" <?php echo $condition_enabled ? '' : 'disabled'; ?>>
                            <option value=""><?php esc_html_e('Select a field…', 'wa-forms'); ?></option>
                            <?php foreach ($all_fields as $of) :
                                if ($of['uid'] === $uid) continue;
                                $of_label = $of['label'] !== '' ? $of['label'] : (isset($types[$of['type']]) ? $types[$of['type']] : $of['type']);
                            ?>
                                <option value="<?php echo esc_attr($of['uid']); ?>" data-type="<?php echo esc_attr($of['type']); ?>" data-options="<?php echo esc_attr(wp_json_encode($of['options'])); ?>" <?php selected($condition_field, $of['uid']); ?>><?php echo esc_html($of_label); ?></option>
                            <?php endforeach; ?>
                        </select>
                        <select class="wa-condition-comparator" name="wa_fields[<?php echo esc_attr($i); ?>][condition][comparator]" <?php echo $condition_enabled ? '' : 'disabled'; ?>>
                            <?php foreach (wa_forms_condition_comparators() as $key => $comp_label) : ?>
                                <option value="<?php echo esc_attr($key); ?>" <?php selected($condition_comparator, $key); ?>><?php echo esc_html($comp_label); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </p>
                    <p>
                        <input type="text" class="wa-condition-value-text" name="wa_fields[<?php echo esc_attr($i); ?>][condition][value]" value="<?php echo esc_attr($condition_value); ?>" placeholder="<?php esc_attr_e('Value', 'wa-forms'); ?>" <?php echo ($condition_enabled && empty($condition_options)) ? '' : 'disabled'; ?>>
                        <select class="wa-condition-value-select" name="wa_fields[<?php echo esc_attr($i); ?>][condition][value]" <?php echo ($condition_enabled && !empty($condition_options)) ? '' : 'disabled'; ?>>
                            <?php foreach ($condition_options as $opt) : ?>
                                <option value="<?php echo esc_attr($opt); ?>" <?php selected($condition_value, $opt); ?>><?php echo esc_html($opt); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </p>
                </div>
            </div>
        </div>
    </div>
    <?php
}

function wa_forms_settings_metabox($post) {
    $settings = get_post_meta($post->ID, '_wa_form_settings', true);
    if (!is_array($settings)) $settings = [];
    $recipient   = isset($settings['recipient']) ? $settings['recipient'] : get_option('admin_email');
    $submit_text = isset($settings['submit_text']) ? $settings['submit_text'] : 'Submit';
    $success_msg = isset($settings['success_msg']) ? $settings['success_msg'] : "Thanks — your submission has been received.";
    ?>
    <p>
        <label for="wa_recipient"><strong><?php esc_html_e('Send submissions to', 'wa-forms'); ?></strong></label>
        <input type="email" id="wa_recipient" name="wa_settings[recipient]" value="<?php echo esc_attr($recipient); ?>" class="widefat">
    </p>
    <p>
        <label for="wa_submit_text"><strong><?php esc_html_e('Submit button text', 'wa-forms'); ?></strong></label>
        <input type="text" id="wa_submit_text" name="wa_settings[submit_text]" value="<?php echo esc_attr($submit_text); ?>" class="widefat">
    </p>
    <p>
        <label for="wa_success_msg"><strong><?php esc_html_e('Success message', 'wa-forms'); ?></strong></label>
        <textarea id="wa_success_msg" name="wa_settings[success_msg]" rows="3" class="widefat"><?php echo esc_textarea($success_msg); ?></textarea>
    </p>
    <?php
}

function wa_forms_style_metabox($post) {
    $settings = get_post_meta($post->ID, '_wa_form_settings', true);
    $style    = (is_array($settings) && isset($settings['style']) && is_array($settings['style'])) ? $settings['style'] : [];

    $primary            = wa_forms_sanitize_hex($style['primary_color'] ?? '', '#2F4F3E');
    $accent             = wa_forms_sanitize_hex($style['accent_color'] ?? '', '#C9A227');
    $border             = wa_forms_sanitize_hex($style['border_color'] ?? '', '#DCE3D9');
    $placeholder        = wa_forms_sanitize_hex($style['placeholder_color'] ?? '', '#5B6B60');
    $radius             = wa_forms_sanitize_px($style['radius'] ?? null, 10);
    $font               = !empty($style['font']) ? $style['font'] : 'default';
    $label_color        = wa_forms_sanitize_hex($style['label_color'] ?? '', '#1F2A23');
    $label_font_size    = wa_forms_sanitize_px($style['label_font_size'] ?? null, 14);
    $field_gap          = wa_forms_sanitize_px($style['field_gap'] ?? null, 20);
    $input_padding      = wa_forms_sanitize_px($style['input_padding'] ?? null, 10);
    $input_bg           = wa_forms_sanitize_hex($style['input_bg_color'] ?? '', '#F6F8F3');
    $button_bg          = wa_forms_sanitize_hex($style['button_bg_color'] ?? '', '#2F4F3E');
    $button_hover       = wa_forms_sanitize_hex($style['button_hover_color'] ?? '', '#22392B');
    $button_padding     = wa_forms_sanitize_px($style['button_padding'] ?? null, 13);
    $button_font_size   = wa_forms_sanitize_px($style['button_font_size'] ?? null, 15);
    $container_bg       = wa_forms_sanitize_hex($style['container_bg_color'] ?? '', '#FFFFFF');
    $container_opacity  = wa_forms_sanitize_px($style['container_bg_opacity'] ?? null, 100, 0, 100);
    ?>
    <h4><?php esc_html_e('Colors', 'wa-forms'); ?></h4>
    <p>
        <label for="wa_style_primary"><?php esc_html_e('Primary color', 'wa-forms'); ?></label><br>
        <input type="text" id="wa_style_primary" class="wa-color-field" name="wa_settings[style][primary_color]" value="<?php echo esc_attr($primary); ?>">
    </p>
    <p>
        <label for="wa_style_accent"><?php esc_html_e('Accent color', 'wa-forms'); ?></label><br>
        <input type="text" id="wa_style_accent" class="wa-color-field" name="wa_settings[style][accent_color]" value="<?php echo esc_attr($accent); ?>">
    </p>
    <p>
        <label for="wa_style_border"><?php esc_html_e('Border color', 'wa-forms'); ?></label><br>
        <input type="text" id="wa_style_border" class="wa-color-field" name="wa_settings[style][border_color]" value="<?php echo esc_attr($border); ?>">
    </p>
    <p>
        <label for="wa_style_placeholder"><?php esc_html_e('Placeholder text color', 'wa-forms'); ?></label><br>
        <input type="text" id="wa_style_placeholder" class="wa-color-field" name="wa_settings[style][placeholder_color]" value="<?php echo esc_attr($placeholder); ?>">
    </p>
    <p>
        <label for="wa_style_radius"><?php esc_html_e('Corner radius (px)', 'wa-forms'); ?></label><br>
        <input type="number" id="wa_style_radius" class="small-text" name="wa_settings[style][radius]" value="<?php echo esc_attr($radius); ?>" min="0" max="999" step="1">
    </p>
    <p>
        <label for="wa_style_font"><?php esc_html_e('Font pairing', 'wa-forms'); ?></label>
        <select id="wa_style_font" name="wa_settings[style][font]" class="widefat">
            <?php foreach (wa_forms_font_presets() as $key => $preset) : ?>
                <option value="<?php echo esc_attr($key); ?>" <?php selected($font, $key); ?>><?php echo esc_html($preset['label']); ?></option>
            <?php endforeach; ?>
        </select>
    </p>

    <h4><?php esc_html_e('Label', 'wa-forms'); ?></h4>
    <p>
        <label for="wa_style_label_color"><?php esc_html_e('Text color', 'wa-forms'); ?></label><br>
        <input type="text" id="wa_style_label_color" class="wa-color-field" name="wa_settings[style][label_color]" value="<?php echo esc_attr($label_color); ?>">
    </p>
    <p>
        <label for="wa_style_label_font_size"><?php esc_html_e('Font size (px)', 'wa-forms'); ?></label><br>
        <input type="number" id="wa_style_label_font_size" class="small-text" name="wa_settings[style][label_font_size]" value="<?php echo esc_attr($label_font_size); ?>" min="0" max="999" step="1">
    </p>

    <h4><?php esc_html_e('Inputs', 'wa-forms'); ?></h4>
    <p>
        <label for="wa_style_field_gap"><?php esc_html_e('Gap between fields (px)', 'wa-forms'); ?></label><br>
        <input type="number" id="wa_style_field_gap" class="small-text" name="wa_settings[style][field_gap]" value="<?php echo esc_attr($field_gap); ?>" min="0" max="999" step="1">
    </p>
    <p>
        <label for="wa_style_input_padding"><?php esc_html_e('Padding (px)', 'wa-forms'); ?></label><br>
        <input type="number" id="wa_style_input_padding" class="small-text" name="wa_settings[style][input_padding]" value="<?php echo esc_attr($input_padding); ?>" min="0" max="999" step="1">
    </p>
    <p>
        <label for="wa_style_input_bg"><?php esc_html_e('Background color', 'wa-forms'); ?></label><br>
        <input type="text" id="wa_style_input_bg" class="wa-color-field" name="wa_settings[style][input_bg_color]" value="<?php echo esc_attr($input_bg); ?>">
    </p>

    <h4><?php esc_html_e('Button', 'wa-forms'); ?></h4>
    <p>
        <label for="wa_style_button_bg"><?php esc_html_e('Background color', 'wa-forms'); ?></label><br>
        <input type="text" id="wa_style_button_bg" class="wa-color-field" name="wa_settings[style][button_bg_color]" value="<?php echo esc_attr($button_bg); ?>">
    </p>
    <p>
        <label for="wa_style_button_hover"><?php esc_html_e('Hover color', 'wa-forms'); ?></label><br>
        <input type="text" id="wa_style_button_hover" class="wa-color-field" name="wa_settings[style][button_hover_color]" value="<?php echo esc_attr($button_hover); ?>">
    </p>
    <p>
        <label for="wa_style_button_padding"><?php esc_html_e('Padding (px)', 'wa-forms'); ?></label><br>
        <input type="number" id="wa_style_button_padding" class="small-text" name="wa_settings[style][button_padding]" value="<?php echo esc_attr($button_padding); ?>" min="0" max="999" step="1">
    </p>
    <p>
        <label for="wa_style_button_font_size"><?php esc_html_e('Font size (px)', 'wa-forms'); ?></label><br>
        <input type="number" id="wa_style_button_font_size" class="small-text" name="wa_settings[style][button_font_size]" value="<?php echo esc_attr($button_font_size); ?>" min="0" max="999" step="1">
    </p>

    <h4><?php esc_html_e('Container', 'wa-forms'); ?></h4>
    <p>
        <label for="wa_style_container_bg"><?php esc_html_e('Background color', 'wa-forms'); ?></label><br>
        <input type="text" id="wa_style_container_bg" class="wa-color-field" name="wa_settings[style][container_bg_color]" value="<?php echo esc_attr($container_bg); ?>">
    </p>
    <p>
        <label for="wa_style_container_opacity"><?php esc_html_e('Background opacity', 'wa-forms'); ?> (<span id="wa_style_container_opacity_val"><?php echo esc_html($container_opacity); ?></span>%)</label><br>
        <input type="range" id="wa_style_container_opacity" name="wa_settings[style][container_bg_opacity]" value="<?php echo esc_attr($container_opacity); ?>" min="0" max="100" step="1">
    </p>
    <?php
}

function wa_forms_usage_metabox($post) {
    ?>
    <p><?php esc_html_e('Add this form to any page or Beaver Builder text module:', 'wa-forms'); ?></p>
    <p><code>[wa_form id="<?php echo (int) $post->ID; ?>"]</code></p>
    <p class="description"><?php esc_html_e('Uploads land in the Media Library. Entries are stored under WA Forms → Entries and emailed to the recipient above.', 'wa-forms'); ?></p>
    <?php
}

/* -------------------------------------------------------------------------
 * Save
 * ---------------------------------------------------------------------- */
add_action('save_post_wa_form', function ($post_id) {
    if (!isset($_POST['wa_form_nonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['wa_form_nonce'])), 'wa_form_save')) return;
    if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) return;
    if (!current_user_can('edit_post', $post_id)) return;

    $types        = array_keys(wa_forms_field_types());
    $option_types = wa_forms_option_field_types();
    $fields       = [];
    if (isset($_POST['wa_fields']) && is_array($_POST['wa_fields'])) {
        foreach (wp_unslash($_POST['wa_fields']) as $f) {
            $label = isset($f['label']) ? sanitize_text_field($f['label']) : '';
            if ($label === '') continue;
            $type = (isset($f['type']) && in_array($f['type'], $types, true)) ? $f['type'] : 'text';

            $field = [
                'label'      => $label,
                'type'       => $type,
                'required'   => !empty($f['required']) ? 1 : 0,
                'hide_label' => !empty($f['hide_label']) ? 1 : 0,
                'width'      => (isset($f['width']) && $f['width'] === 'half') ? 'half' : 'full',
                'uid'        => (!empty($f['uid']) && is_string($f['uid'])) ? sanitize_text_field($f['uid']) : wp_generate_uuid4(),
            ];

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
    $comparator_keys  = array_keys(wa_forms_condition_comparators());
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

    $settings = ['recipient' => get_option('admin_email'), 'submit_text' => 'Submit', 'success_msg' => ''];
    if (isset($_POST['wa_settings']) && is_array($_POST['wa_settings'])) {
        $s = wp_unslash($_POST['wa_settings']);
        $recipient = isset($s['recipient']) ? sanitize_email($s['recipient']) : '';
        $settings['recipient']   = is_email($recipient) ? $recipient : get_option('admin_email');
        $settings['submit_text'] = isset($s['submit_text']) && $s['submit_text'] !== '' ? sanitize_text_field($s['submit_text']) : 'Submit';
        $settings['success_msg'] = isset($s['success_msg']) ? sanitize_textarea_field($s['success_msg']) : '';

        $style_in  = (isset($s['style']) && is_array($s['style'])) ? $s['style'] : [];
        $font_keys = array_keys(wa_forms_font_presets());

        $settings['style'] = [
            'primary_color'        => wa_forms_sanitize_hex($style_in['primary_color'] ?? '', '#2F4F3E'),
            'accent_color'         => wa_forms_sanitize_hex($style_in['accent_color'] ?? '', '#C9A227'),
            'border_color'         => wa_forms_sanitize_hex($style_in['border_color'] ?? '', '#DCE3D9'),
            'placeholder_color'    => wa_forms_sanitize_hex($style_in['placeholder_color'] ?? '', '#5B6B60'),
            'radius'               => wa_forms_sanitize_px($style_in['radius'] ?? null, 10),
            'font'                 => (isset($style_in['font']) && in_array($style_in['font'], $font_keys, true)) ? $style_in['font'] : 'default',
            'label_color'          => wa_forms_sanitize_hex($style_in['label_color'] ?? '', '#1F2A23'),
            'label_font_size'      => wa_forms_sanitize_px($style_in['label_font_size'] ?? null, 14),
            'field_gap'            => wa_forms_sanitize_px($style_in['field_gap'] ?? null, 20),
            'input_padding'        => wa_forms_sanitize_px($style_in['input_padding'] ?? null, 10),
            'input_bg_color'       => wa_forms_sanitize_hex($style_in['input_bg_color'] ?? '', '#F6F8F3'),
            'button_bg_color'      => wa_forms_sanitize_hex($style_in['button_bg_color'] ?? '', '#2F4F3E'),
            'button_hover_color'   => wa_forms_sanitize_hex($style_in['button_hover_color'] ?? '', '#22392B'),
            'button_padding'       => wa_forms_sanitize_px($style_in['button_padding'] ?? null, 13),
            'button_font_size'     => wa_forms_sanitize_px($style_in['button_font_size'] ?? null, 15),
            'container_bg_color'   => wa_forms_sanitize_hex($style_in['container_bg_color'] ?? '', '#FFFFFF'),
            'container_bg_opacity' => wa_forms_sanitize_px($style_in['container_bg_opacity'] ?? null, 100, 0, 100),
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

    wp_enqueue_style('wa-forms-admin', WA_FORMS_URL . 'assets/admin.css', [], WA_FORMS_VERSION);

    if (in_array($hook, ['post.php', 'post-new.php'], true)) {
        wp_enqueue_style('wp-color-picker');
        wp_enqueue_script('wa-forms-admin', WA_FORMS_URL . 'assets/admin.js', ['jquery', 'jquery-ui-sortable', 'wp-color-picker'], WA_FORMS_VERSION, true);
    }
});
