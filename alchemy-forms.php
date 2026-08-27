<?php
/**
 * Plugin Name: Alchemy Forms
 * Plugin URI:  https://websitealchemy.com
 * Description: Lightweight form builder with editable fields, layout control, file uploads, and an entries dashboard with CSV export.
 * Version:     1.4.1
 * Author:      Website Alchemy
 * Author URI:  https://websitealchemy.com
 * License:     GPL-2.0-or-later
 * Text Domain: alchemy-forms
 */

if (!defined('ABSPATH')) exit;

define('ALCHEMY_FORMS_VERSION', '1.4.1');
define('ALCHEMY_FORMS_DIR', plugin_dir_path(__FILE__));
define('ALCHEMY_FORMS_URL', plugin_dir_url(__FILE__));

require_once ALCHEMY_FORMS_DIR . 'includes/admin-editor.php';
require_once ALCHEMY_FORMS_DIR . 'includes/entries.php';
require_once ALCHEMY_FORMS_DIR . 'includes/render.php';
require_once ALCHEMY_FORMS_DIR . 'includes/import.php';
require_once ALCHEMY_FORMS_DIR . 'includes/integrations.php';

// Not on WordPress.org, so this is what gives client sites a real
// "Update available" notice + one-click Update Now instead of needing a
// manual zip re-upload every release (which is also what triggers the
// nested-folder bug in WP core's "Replace current with uploaded" flow).
require_once ALCHEMY_FORMS_DIR . 'includes/plugin-update-checker/plugin-update-checker.php';
use YahnisElsts\PluginUpdateChecker\v5\PucFactory;

$alchemy_forms_update_checker = PucFactory::buildUpdateChecker(
    'https://github.com/mrlennyman/alchemy-forms/',
    __FILE__,
    'alchemy-forms'
);
$alchemy_forms_update_checker->setBranch('main');
// Releases are tagged (vX.Y.Z on main), not published via GitHub's separate
// "Releases" feature, so release assets are left off — PUC builds the
// update zip from the tagged source automatically.

/**
 * Field types supported by the builder.
 * Each maps to render + sanitize behaviour in render.php.
 */
function alchemy_forms_field_types() {
    return [
        'text'     => __('Single line text', 'alchemy-forms'),
        'email'    => __('Email', 'alchemy-forms'),
        'tel'      => __('Phone', 'alchemy-forms'),
        'url'      => __('Website / URL', 'alchemy-forms'),
        'number'   => __('Number', 'alchemy-forms'),
        'date'     => __('Date', 'alchemy-forms'),
        'textarea' => __('Paragraph text', 'alchemy-forms'),
        'select'   => __('Dropdown', 'alchemy-forms'),
        'radio'    => __('Radio buttons', 'alchemy-forms'),
        'checkbox' => __('Checkboxes', 'alchemy-forms'),
        'checkbox_single' => __('Single Checkbox', 'alchemy-forms'),
        'file'     => __('File upload', 'alchemy-forms'),
        'html'     => __('HTML / Text Block', 'alchemy-forms'),
        'page_break' => __('Step Break', 'alchemy-forms'),
        'hidden'   => __('Hidden Field', 'alchemy-forms'),
    ];
}

/**
 * Field types that store a list of selectable options.
 */
function alchemy_forms_option_field_types() {
    return ['select', 'radio', 'checkbox'];
}

/**
 * Field types that collect no submitted value (nothing to validate/store),
 * used for the front-end submission loop and the condition-lookup pass.
 */
function alchemy_forms_noninput_field_types() {
    return ['page_break', 'html'];
}

/**
 * Field types that can't be used as a condition's trigger field: file inputs
 * expose only the browser's fake local path client-side, and checkbox groups
 * have no single value, so neither can be evaluated consistently between the
 * front-end (frontend.js's getFieldValue()) and the server-side condition
 * lookup below. Shared by the builder (which field types are offered as a
 * trigger) and the submission handler (which values are collected for
 * evaluation), so both stay in agreement.
 */
function alchemy_forms_condition_ineligible_types() {
    return array_merge(['file', 'checkbox'], alchemy_forms_noninput_field_types());
}

/**
 * Dashicon class per field type, used by the builder's palette and field cards.
 */
function alchemy_forms_field_type_icons() {
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
        'checkbox_single' => 'dashicons-yes-alt',
        'file'     => 'dashicons-upload',
        'html'     => 'dashicons-editor-code',
        'page_break' => 'dashicons-editor-break',
        'hidden'   => 'dashicons-hidden',
    ];
}

/**
 * Where a Hidden field's value comes from — resolved server-side at render
 * time (see alchemy_forms_resolve_hidden_value() in render.php), never shown
 * to or editable by the visitor.
 */
function alchemy_forms_hidden_sources() {
    return [
        'post_title' => __('Page/Post Title', 'alchemy-forms'),
        'post_id'    => __('Page/Post ID', 'alchemy-forms'),
        'post_url'   => __('Page/Post URL', 'alchemy-forms'),
        'static'     => __('Fixed value', 'alchemy-forms'),
    ];
}

/**
 * Available font-pairing presets for the Style panel.
 */
function alchemy_forms_font_presets() {
    return [
        'default' => [
            'label'   => __('Default (Fraunces / Inter)', 'alchemy-forms'),
            'display' => "'Fraunces', Georgia, serif",
            'body'    => "'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif",
            'google'  => 'https://fonts.googleapis.com/css2?family=Fraunces:opsz,wght@9..144,500;9..144,600&family=Inter:wght@400;500;600&display=swap',
        ],
        'classic' => [
            'label'   => __('Classic (Georgia / Arial)', 'alchemy-forms'),
            'display' => "Georgia, 'Times New Roman', serif",
            'body'    => "Arial, Helvetica, sans-serif",
            'google'  => null,
        ],
        'modern' => [
            'label'   => __('Modern (Poppins / Roboto)', 'alchemy-forms'),
            'display' => "'Poppins', -apple-system, sans-serif",
            'body'    => "'Roboto', -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif",
            'google'  => 'https://fonts.googleapis.com/css2?family=Poppins:wght@500;600&family=Roboto:wght@400;500;600&display=swap',
        ],
        'system' => [
            'label'   => __('System font', 'alchemy-forms'),
            'display' => "system-ui, -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif",
            'body'    => "system-ui, -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif",
            'google'  => null,
        ],
    ];
}

/**
 * Darken a #rrggbb hex color by a percentage, for hover/active shades.
 */
function alchemy_forms_darken_hex($hex, $percent) {
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
function alchemy_forms_hex_to_rgb($hex) {
    $hex = ltrim((string) $hex, '#');
    if (strlen($hex) !== 6 || !ctype_xdigit($hex)) return [0, 0, 0];
    return array_map('hexdec', str_split($hex, 2));
}

/**
 * Validate a hex color, falling back to a default when missing/invalid.
 */
function alchemy_forms_sanitize_hex($value, $fallback) {
    $color = !empty($value) ? sanitize_hex_color($value) : '';
    return $color ? $color : $fallback;
}

/**
 * Validate a pixel value, clamped to a range, falling back to a default when
 * missing or non-numeric (e.g. an old value saved under a different scheme).
 */
function alchemy_forms_sanitize_px($value, $fallback, $min = 0, $max = 999) {
    if (!isset($value) || !is_numeric($value)) return $fallback;
    return min($max, max($min, (int) $value));
}

/**
 * Parse a recipient setting into a list of valid email addresses. Accepts
 * either the current comma/newline-separated string from the settings field,
 * or an already-stored array (so old forms saved before this existed keep
 * working without a migration). Falls back to the site admin email when
 * nothing valid is left.
 */
function alchemy_forms_parse_recipients($value) {
    $raw    = is_array($value) ? $value : preg_split('/[,\n]+/', (string) $value);
    $emails = [];
    foreach ($raw as $email) {
        $email = sanitize_email(trim($email));
        if ($email !== '' && is_email($email) && !in_array($email, $emails, true)) {
            $emails[] = $email;
        }
    }
    return $emails ? $emails : [get_option('admin_email')];
}

/**
 * Default style values, shared between the Style metabox (admin-editor.php)
 * and the front-end resolver (render.php) so the two can't drift apart.
 */
function alchemy_forms_style_defaults() {
    return [
        'primary_color'        => '#2F4F3E',
        'accent_color'         => '#C9A227',
        'border_color'         => '#DCE3D9',
        'placeholder_color'    => '#5B6B60',
        'radius'               => 10,
        'label_color'          => '#1F2A23',
        'label_font_size'      => 14,
        'field_gap'            => 20,
        'input_padding'        => 10,
        'input_bg_color'       => '#F6F8F3',
        'button_bg_color'      => '#2F4F3E',
        'button_hover_color'   => '#22392B',
        'button_padding'       => 13,
        'button_font_size'     => 15,
        'button_width'         => 'auto',
        'button_align'         => 'left',
        'container_bg_color'   => '#FFFFFF',
        'container_bg_opacity' => 100,
        'container_padding'    => 40,
        'container_border_width' => 1,
    ];
}

/**
 * Submit button width options — "auto" fits the button to its text, "full"
 * stretches it to the width of the form.
 */
function alchemy_forms_button_width_options() {
    return [
        'auto' => __('Auto (fits text)', 'alchemy-forms'),
        'full' => __('Full width', 'alchemy-forms'),
    ];
}

/**
 * Submit button horizontal alignment, used when width is "auto".
 */
function alchemy_forms_button_align_options() {
    return [
        'left'   => __('Left', 'alchemy-forms'),
        'center' => __('Center', 'alchemy-forms'),
        'right'  => __('Right', 'alchemy-forms'),
    ];
}

/**
 * Comparators available for a field's conditional visibility rule.
 */
function alchemy_forms_condition_comparators() {
    return [
        'equals'     => __('is', 'alchemy-forms'),
        'not_equals' => __('is not', 'alchemy-forms'),
    ];
}

/**
 * Whether a field should be visible, given its condition and a uid => value
 * lookup of what's currently been entered elsewhere in the form. A field
 * with no condition (or an incomplete one) is always visible.
 */
function alchemy_forms_evaluate_condition($condition, $lookup) {
    if (!is_array($condition) || empty($condition['field'])) return true;

    $trigger_uid = $condition['field'];
    $comparator  = isset($condition['comparator']) ? $condition['comparator'] : 'equals';
    $value       = isset($condition['value']) ? (string) $condition['value'] : '';
    $actual      = isset($lookup[$trigger_uid]) ? (string) $lookup[$trigger_uid] : '';

    if ($comparator === 'not_equals') return $actual !== $value;
    return $actual === $value;
}

register_activation_hook(__FILE__, 'alchemy_forms_activate');
function alchemy_forms_activate() {
    alchemy_forms_create_entries_table();

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
