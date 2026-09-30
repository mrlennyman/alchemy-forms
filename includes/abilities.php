<?php
if (!defined('ABSPATH')) exit;

/* -------------------------------------------------------------------------
 * WordPress Abilities API integration (WordPress 6.9+) — read-only.
 *
 * Registers a category plus three abilities so AI clients connected through
 * the WordPress MCP Adapter (github.com/WordPress/mcp-adapter) can list and
 * inspect Alchemy Forms forms and their submitted entries. Nothing here can
 * create, update, or delete a form or an entry, and none of it changes any
 * existing form-rendering, submission, upload, or admin behaviour — it only
 * reads data that already exists via the same functions/tables the admin
 * screens already use (_wa_form_fields, _wa_form_settings,
 * alchemy_forms_entries_table()).
 *
 * function_exists( 'wp_register_ability' ) guards every hook below, so this
 * file is inert (no errors, no notices) on a WordPress install older than
 * 6.9, where the Abilities API doesn't exist yet.
 * ---------------------------------------------------------------------- */

if (function_exists('wp_register_ability')) {

    add_action('wp_abilities_api_categories_init', function () {
        wp_register_ability_category('alchemy-forms', [
            'label'       => __('Alchemy Forms', 'alchemy-forms'),
            'description' => __('Read-only access to Alchemy Forms form definitions and submitted entries.', 'alchemy-forms'),
        ]);
    });

    add_action('wp_abilities_api_init', function () {

        wp_register_ability('alchemy-forms/list-forms', [
            'label'               => __('List Alchemy Forms', 'alchemy-forms'),
            'description'         => __('Lists Alchemy Forms forms with their ID, title, status, and embed shortcode. Optionally filter by a partial title match or post status.', 'alchemy-forms'),
            'category'            => 'alchemy-forms',
            'input_schema'        => [
                'type'       => 'object',
                'properties' => [
                    'title'  => [
                        'type'        => 'string',
                        'description' => __('Only return forms whose title contains this text (case-insensitive).', 'alchemy-forms'),
                    ],
                    'status' => [
                        'type'        => 'string',
                        'description' => __('Only return forms with this post status (e.g. publish, draft, pending, private, trash). Omit to return forms in any status.', 'alchemy-forms'),
                    ],
                ],
                'additionalProperties' => false,
            ],
            'output_schema'       => [
                'type'       => 'object',
                'properties' => [
                    'total' => ['type' => 'integer'],
                    'forms' => [
                        'type'  => 'array',
                        'items' => [
                            'type'       => 'object',
                            'properties' => [
                                'id'        => ['type' => 'integer'],
                                'title'     => ['type' => 'string'],
                                'status'    => ['type' => 'string'],
                                'shortcode' => ['type' => 'string'],
                            ],
                        ],
                    ],
                ],
            ],
            'execute_callback'    => 'alchemy_forms_ability_list_forms',
            'permission_callback' => 'alchemy_forms_ability_permission_check',
            'meta'                => [
                'public'      => true,
                'annotations' => ['readonly' => true],
            ],
        ]);

        wp_register_ability('alchemy-forms/get-form', [
            'label'               => __('Get an Alchemy Forms form', 'alchemy-forms'),
            'description'         => __('Returns one form\'s full definition: every field (uid, label, type, required, width, options, order) and its settings (recipients, submit button text, success message, and other per-form configuration). Integration API keys/secrets are never included, even though this ability already requires manage_options.', 'alchemy-forms'),
            'category'            => 'alchemy-forms',
            'input_schema'        => [
                'type'       => 'object',
                'properties' => [
                    'form_id' => [
                        'type'        => 'integer',
                        'description' => __('The form\'s post ID.', 'alchemy-forms'),
                    ],
                ],
                'required'             => ['form_id'],
                'additionalProperties' => false,
            ],
            'output_schema'       => [
                'type'       => 'object',
                'properties' => [
                    'id'        => ['type' => 'integer'],
                    'title'     => ['type' => 'string'],
                    'status'    => ['type' => 'string'],
                    'shortcode' => ['type' => 'string'],
                    'fields'    => [
                        'type'  => 'array',
                        'items' => [
                            'type'       => 'object',
                            'properties' => [
                                'uid'      => ['type' => 'string'],
                                'label'    => ['type' => 'string'],
                                'type'     => ['type' => 'string'],
                                'required' => ['type' => 'boolean'],
                                'width'    => ['type' => 'string'],
                                'options'  => ['type' => 'array', 'items' => ['type' => 'string']],
                                'order'    => ['type' => 'integer'],
                            ],
                        ],
                    ],
                    'settings'  => ['type' => 'object'],
                ],
            ],
            'execute_callback'    => 'alchemy_forms_ability_get_form',
            'permission_callback' => 'alchemy_forms_ability_permission_check',
            'meta'                => [
                'public'      => true,
                'annotations' => ['readonly' => true],
            ],
        ]);

        wp_register_ability('alchemy-forms/get-entries', [
            'label'               => __('Get Alchemy Forms entries', 'alchemy-forms'),
            'description'         => __('Returns submitted entries for one form, newest first, with the submitted field values and submission timestamp. Can also fetch a single entry by ID.', 'alchemy-forms'),
            'category'            => 'alchemy-forms',
            'input_schema'        => [
                'type'       => 'object',
                'properties' => [
                    'form_id'  => [
                        'type'        => 'integer',
                        'description' => __('The form\'s post ID.', 'alchemy-forms'),
                    ],
                    'entry_id' => [
                        'type'        => 'integer',
                        'description' => __('Return only this entry (it must belong to form_id). Omit to return a page of entries instead.', 'alchemy-forms'),
                    ],
                    'limit'    => [
                        'type'        => 'integer',
                        'minimum'     => 1,
                        'maximum'     => 100,
                        'default'     => 20,
                        'description' => __('Maximum number of entries to return (1-100).', 'alchemy-forms'),
                    ],
                    'offset'   => [
                        'type'        => 'integer',
                        'minimum'     => 0,
                        'default'     => 0,
                        'description' => __('Number of newest entries to skip, for paging.', 'alchemy-forms'),
                    ],
                ],
                'required'             => ['form_id'],
                'additionalProperties' => false,
            ],
            'output_schema'       => [
                'type'       => 'object',
                'properties' => [
                    'form_id' => ['type' => 'integer'],
                    'total'   => ['type' => 'integer'],
                    'entries' => [
                        'type'  => 'array',
                        'items' => [
                            'type'       => 'object',
                            'properties' => [
                                'id'           => ['type' => 'integer'],
                                'submitted_at' => ['type' => 'string'],
                                'data'         => ['type' => 'object'],
                            ],
                        ],
                    ],
                ],
            ],
            'execute_callback'    => 'alchemy_forms_ability_get_entries',
            'permission_callback' => 'alchemy_forms_ability_permission_check',
            'meta'                => [
                'public'      => true,
                'annotations' => ['readonly' => true],
            ],
        ]);
    });

}

/**
 * Shared permission check for every Alchemy Forms ability — entries can
 * contain personal information submitted by site visitors, and form
 * settings can include recipient addresses and (redacted, but still)
 * integration configuration, so this deliberately never loosens below
 * manage_options regardless of which ability or input is involved.
 */
function alchemy_forms_ability_permission_check($input) {
    return current_user_can('manage_options');
}

/**
 * alchemy-forms/list-forms execute_callback.
 */
function alchemy_forms_ability_list_forms($input) {
    $input  = is_array($input) ? $input : [];
    $status = (!empty($input['status']) && is_string($input['status'])) ? sanitize_key($input['status']) : 'any';
    $title  = !empty($input['title']) ? sanitize_text_field($input['title']) : '';

    $posts = get_posts([
        'post_type'   => 'wa_form',
        'numberposts' => -1,
        'post_status' => $status,
        'orderby'     => 'title',
        'order'       => 'ASC',
    ]);

    $forms = [];
    foreach ($posts as $post) {
        if ($title !== '' && stripos($post->post_title, $title) === false) continue;
        $forms[] = [
            'id'        => (int) $post->ID,
            'title'     => $post->post_title,
            'status'    => $post->post_status,
            'shortcode' => '[wa_form id="' . (int) $post->ID . '"]',
        ];
    }

    return [
        'total' => count($forms),
        'forms' => $forms,
    ];
}

/**
 * alchemy-forms/get-form execute_callback.
 */
function alchemy_forms_ability_get_form($input) {
    $input   = is_array($input) ? $input : [];
    $form_id = isset($input['form_id']) ? (int) $input['form_id'] : 0;
    $post    = $form_id ? get_post($form_id) : null;

    if (!$post || $post->post_type !== 'wa_form') {
        return new WP_Error(
            'alchemy_forms_not_found',
            __('No Alchemy Forms form exists with that ID.', 'alchemy-forms'),
            ['status' => 404]
        );
    }

    $stored_fields = get_post_meta($form_id, '_wa_form_fields', true);
    if (!is_array($stored_fields)) $stored_fields = [];

    $fields = [];
    foreach (array_values($stored_fields) as $order => $f) {
        $fields[] = [
            'uid'      => isset($f['uid']) ? (string) $f['uid'] : '',
            'label'    => isset($f['label']) ? (string) $f['label'] : '',
            'type'     => isset($f['type']) ? (string) $f['type'] : '',
            'required' => !empty($f['required']),
            'width'    => (isset($f['width']) && $f['width'] === 'half') ? 'half' : 'full',
            'options'  => (isset($f['options']) && is_array($f['options'])) ? array_values(array_map('strval', $f['options'])) : [],
            'order'    => $order,
        ];
    }

    $settings = get_post_meta($form_id, '_wa_form_settings', true);
    if (!is_array($settings)) $settings = [];

    // Never surface stored integration credentials through this read-only
    // inspection ability — they're config values an AI client has no need
    // to see, not something that becomes safe to expose just because the
    // permission check happens to already require manage_options.
    foreach (['flodesk', 'mailchimp'] as $provider) {
        if (isset($settings['integrations'][$provider]['api_key'])) {
            unset($settings['integrations'][$provider]['api_key']);
        }
    }

    return [
        'id'        => (int) $post->ID,
        'title'     => $post->post_title,
        'status'    => $post->post_status,
        'shortcode' => '[wa_form id="' . (int) $post->ID . '"]',
        'fields'    => $fields,
        'settings'  => $settings,
    ];
}

/**
 * alchemy-forms/get-entries execute_callback.
 */
function alchemy_forms_ability_get_entries($input) {
    $input   = is_array($input) ? $input : [];
    $form_id = isset($input['form_id']) ? (int) $input['form_id'] : 0;
    $post    = $form_id ? get_post($form_id) : null;

    if (!$post || $post->post_type !== 'wa_form') {
        return new WP_Error(
            'alchemy_forms_not_found',
            __('No Alchemy Forms form exists with that ID.', 'alchemy-forms'),
            ['status' => 404]
        );
    }

    $entry_id = isset($input['entry_id']) ? (int) $input['entry_id'] : 0;
    $limit    = isset($input['limit']) ? (int) $input['limit'] : 20;
    $limit    = max(1, min(100, $limit));
    $offset   = isset($input['offset']) ? max(0, (int) $input['offset']) : 0;

    global $wpdb;
    $table = alchemy_forms_entries_table();

    if ($entry_id) {
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT id, submitted_at, data FROM {$table} WHERE id = %d AND form_id = %d",
            $entry_id,
            $form_id
        ));
    } else {
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT id, submitted_at, data FROM {$table} WHERE form_id = %d ORDER BY submitted_at DESC, id DESC LIMIT %d OFFSET %d",
            $form_id,
            $limit,
            $offset
        ));
    }

    $entries = [];
    foreach ($rows as $row) {
        $data = json_decode($row->data, true);
        if (!is_array($data)) $data = [];
        $entries[] = [
            'id'           => (int) $row->id,
            'submitted_at' => $row->submitted_at,
            'data'         => $data,
        ];
    }

    return [
        'form_id' => $form_id,
        'total'   => alchemy_forms_count_entries($form_id),
        'entries' => $entries,
    ];
}
