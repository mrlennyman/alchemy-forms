<?php
/**
 * Plugin Name: WA Forms
 * Plugin URI:  https://websitealchemy.co.nz
 * Description: Lightweight form builder with editable fields, layout control, file uploads, and an entries dashboard with CSV export.
 * Version:     1.0.7
 * Author:      Website Alchemy
 * Author URI:  https://websitealchemy.co.nz
 * License:     GPL-2.0-or-later
 * Text Domain: wa-forms
 */

if (!defined('ABSPATH')) exit;

define('WA_FORMS_VERSION', '1.0.7');
define('WA_FORMS_DIR', plugin_dir_path(__FILE__));
define('WA_FORMS_URL', plugin_dir_url(__FILE__));

require_once WA_FORMS_DIR . 'includes/admin-editor.php';
require_once WA_FORMS_DIR . 'includes/entries.php';
require_once WA_FORMS_DIR . 'includes/render.php';
require_once WA_FORMS_DIR . 'includes/import.php';

/**
 * Field types supported by the builder.
 * Each maps to render + sanitize behaviour in render.php.
 */
function wa_forms_field_types() {
    return [
        'text'     => __('Single line text', 'wa-forms'),
        'email'    => __('Email', 'wa-forms'),
        'tel'      => __('Phone', 'wa-forms'),
        'url'      => __('Website / URL', 'wa-forms'),
        'number'   => __('Number', 'wa-forms'),
        'date'     => __('Date', 'wa-forms'),
        'textarea' => __('Paragraph text', 'wa-forms'),
        'select'   => __('Dropdown', 'wa-forms'),
        'radio'    => __('Radio buttons', 'wa-forms'),
        'checkbox' => __('Checkboxes', 'wa-forms'),
        'file'     => __('File upload', 'wa-forms'),
    ];
}

/**
 * Field types that store a list of selectable options.
 */
function wa_forms_option_field_types() {
    return ['select', 'radio', 'checkbox'];
}

/**
 * Dashicon class per field type, used by the builder's palette and field cards.
 */
function wa_forms_field_type_icons() {
    return [
        'text'     => 'dashicons-editor-textcolor',
        'email'    => 'dashicons-email',
        'tel'      => 'dashicons-phone',
        'url'      => 'dashicons-admin-links',
        'number'   => 'dashicons-calculator',
        'date'     => 'dashicons-calendar-alt',
        'textarea' => 'dashicons-text-page',
        'select'   => 'dashicons-menu-alt',
        'radio'    => 'dashicons-marker',
        'checkbox' => 'dashicons-forms',
        'file'     => 'dashicons-upload',
    ];
}

/**
 * Available font-pairing presets for the Style panel.
 */
function wa_forms_font_presets() {
    return [
        'default' => [
            'label'   => __('Default (Fraunces / Inter)', 'wa-forms'),
            'display' => "'Fraunces', Georgia, serif",
            'body'    => "'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif",
            'google'  => 'https://fonts.googleapis.com/css2?family=Fraunces:opsz,wght@9..144,500;9..144,600&family=Inter:wght@400;500;600&display=swap',
        ],
        'classic' => [
            'label'   => __('Classic (Georgia / Arial)', 'wa-forms'),
            'display' => "Georgia, 'Times New Roman', serif",
            'body'    => "Arial, Helvetica, sans-serif",
            'google'  => null,
        ],
        'modern' => [
            'label'   => __('Modern (Poppins / Roboto)', 'wa-forms'),
            'display' => "'Poppins', -apple-system, sans-serif",
            'body'    => "'Roboto', -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif",
            'google'  => 'https://fonts.googleapis.com/css2?family=Poppins:wght@500;600&family=Roboto:wght@400;500;600&display=swap',
        ],
        'system' => [
            'label'   => __('System font', 'wa-forms'),
            'display' => "system-ui, -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif",
            'body'    => "system-ui, -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif",
            'google'  => null,
        ],
    ];
}

/**
 * Darken a #rrggbb hex color by a percentage, for hover/active shades.
 */
function wa_forms_darken_hex($hex, $percent) {
    $hex = ltrim((string) $hex, '#');
    if (strlen($hex) !== 6 || !ctype_xdigit($hex)) return '#000000';

    $rgb = array_map(function ($c) use ($percent) {
        $c = max(0, min(255, (int) round(hexdec($c) * (1 - $percent))));
        return str_pad(dechex($c), 2, '0', STR_PAD_LEFT);
    }, str_split($hex, 2));

    return '#' . implode('', $rgb);
}

/**
 * Split a #rrggbb hex color into [r, g, b] integer channels.
 */
function wa_forms_hex_to_rgb($hex) {
    $hex = ltrim((string) $hex, '#');
    if (strlen($hex) !== 6 || !ctype_xdigit($hex)) return [0, 0, 0];
    return array_map('hexdec', str_split($hex, 2));
}

/**
 * Validate a hex color, falling back to a default when missing/invalid.
 */
function wa_forms_sanitize_hex($value, $fallback) {
    $color = !empty($value) ? sanitize_hex_color($value) : '';
    return $color ? $color : $fallback;
}

/**
 * Validate a pixel value, clamped to a range, falling back to a default when
 * missing or non-numeric (e.g. an old value saved under a different scheme).
 */
function wa_forms_sanitize_px($value, $fallback, $min = 0, $max = 999) {
    if (!isset($value) || !is_numeric($value)) return $fallback;
    return min($max, max($min, (int) $value));
}

/**
 * Comparators available for a field's conditional visibility rule.
 */
function wa_forms_condition_comparators() {
    return [
        'equals'     => __('is', 'wa-forms'),
        'not_equals' => __('is not', 'wa-forms'),
    ];
}

/**
 * Whether a field should be visible, given its condition and a uid => value
 * lookup of what's currently been entered elsewhere in the form. A field
 * with no condition (or an incomplete one) is always visible.
 */
function wa_forms_evaluate_condition($condition, $lookup) {
    if (!is_array($condition) || empty($condition['field'])) return true;

    $trigger_uid = $condition['field'];
    $comparator  = isset($condition['comparator']) ? $condition['comparator'] : 'equals';
    $value       = isset($condition['value']) ? (string) $condition['value'] : '';
    $actual      = isset($lookup[$trigger_uid]) ? (string) $lookup[$trigger_uid] : '';

    if ($comparator === 'not_equals') return $actual !== $value;
    return $actual === $value;
}

register_activation_hook(__FILE__, 'wa_forms_activate');
function wa_forms_activate() {
    wa_forms_create_entries_table();

    // Seed a sample form on first activation so there's something to test with.
    $existing = get_posts(['post_type' => 'wa_form', 'numberposts' => 1, 'post_status' => 'any', 'fields' => 'ids']);
    if (empty($existing)) {
        $form_id = wp_insert_post([
            'post_type'   => 'wa_form',
            'post_status' => 'publish',
            'post_title'  => 'Directory Submission Request',
        ]);
        if ($form_id && !is_wp_error($form_id)) {
            update_post_meta($form_id, '_wa_form_fields', [
                ['label' => 'First Name',                                                   'type' => 'text',     'required' => 1, 'width' => 'half'],
                ['label' => 'Last Name',                                                    'type' => 'text',     'required' => 1, 'width' => 'half'],
                ['label' => 'Business Name',                                                'type' => 'text',     'required' => 0, 'width' => 'half'],
                ['label' => 'Specialties',                                                  'type' => 'text',     'required' => 0, 'width' => 'half'],
                ['label' => 'Your Website',                                                 'type' => 'url',      'required' => 0, 'width' => 'half'],
                ['label' => 'Business Email',                                               'type' => 'email',    'required' => 1, 'width' => 'half'],
                ['label' => 'Business Phone',                                               'type' => 'tel',      'required' => 0, 'width' => 'half'],
                ['label' => 'Where do you offer your services?',                            'type' => 'text',     'required' => 1, 'width' => 'half'],
                ['label' => 'Do you offer in-person and/or online services?',               'type' => 'text',     'required' => 1, 'width' => 'full'],
                ['label' => 'Apart from English, which languages do you offer services in?','type' => 'text',     'required' => 0, 'width' => 'full'],
                ['label' => 'Short Bio',                                                    'type' => 'textarea', 'required' => 1, 'width' => 'full'],
                ['label' => 'Do you have more information you would like to include?',      'type' => 'textarea', 'required' => 0, 'width' => 'full'],
                ['label' => 'Certificate, qualification, or photo',                         'type' => 'file',     'required' => 0, 'width' => 'full'],
            ]);
            update_post_meta($form_id, '_wa_form_settings', [
                'recipient'   => get_option('admin_email'),
                'submit_text' => 'Submit',
                'success_msg' => "Thanks — you're on the list. Your submission has been received.",
            ]);
        }
    }
}
