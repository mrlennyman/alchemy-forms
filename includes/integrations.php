<?php
if (!defined('ABSPATH')) exit;

/**
 * Fetches the account's segments from Flodesk (GET /v1/segments, paginated),
 * for the admin segment picker. Returns [] on any failure — the picker just
 * falls back to its "no segments found" state rather than blocking the admin.
 */
function alchemy_forms_fetch_flodesk_segments($api_key) {
    $api_key = trim((string) $api_key);
    if ($api_key === '') return [];

    $segments    = [];
    $page        = 1;
    $total_pages = 1;

    do {
        $response = wp_remote_get(add_query_arg(
            ['page' => $page, 'per_page' => 100],
            'https://api.flodesk.com/v1/segments'
        ), [
            'timeout' => 8,
            'headers' => [
                'Authorization' => 'Basic ' . base64_encode($api_key . ':'),
                'Content-Type'  => 'application/json',
            ],
        ]);

        if (is_wp_error($response) || wp_remote_retrieve_response_code($response) !== 200) {
            break;
        }

        $body = json_decode(wp_remote_retrieve_body($response), true);
        if (!is_array($body) || empty($body['data']) || !is_array($body['data'])) {
            break;
        }

        foreach ($body['data'] as $segment) {
            if (!is_array($segment) || empty($segment['id'])) continue;
            $segments[] = [
                'id'          => (string) $segment['id'],
                'name'        => isset($segment['name']) ? (string) $segment['name'] : (string) $segment['id'],
                'subscribers' => isset($segment['total_active_subscribers']) ? (int) $segment['total_active_subscribers'] : null,
            ];
        }

        $total_pages = isset($body['meta']['total_pages']) ? (int) $body['meta']['total_pages'] : 1;
        $page++;
    } while ($page <= $total_pages && $page <= 20); // hard cap — defensive against a runaway loop

    return $segments;
}

add_action('wp_ajax_alchemy_forms_fetch_flodesk_segments', function () {
    $post_id = isset($_POST['post_id']) ? (int) $_POST['post_id'] : 0;
    if (!$post_id || !current_user_can('edit_post', $post_id)) {
        wp_send_json_error(__('You do not have permission to do this.', 'alchemy-forms'));
    }
    check_ajax_referer('alchemy_forms_flodesk_segments_' . $post_id, 'nonce');

    $api_key = isset($_POST['api_key']) ? sanitize_text_field(wp_unslash($_POST['api_key'])) : '';
    if ($api_key === '') {
        wp_send_json_error(__('Enter an API key first.', 'alchemy-forms'));
    }

    $segments = alchemy_forms_fetch_flodesk_segments($api_key);
    if (empty($segments)) {
        wp_send_json_error(__('Could not fetch segments — check the API key and try again.', 'alchemy-forms'));
    }

    set_transient('alchemy_forms_flodesk_segments_' . $post_id, $segments, HOUR_IN_SECONDS);
    wp_send_json_success($segments);
});

/**
 * Adds/updates a Flodesk subscriber after a successful submission. Never
 * shown to or blocks the visitor — failures are logged server-side only,
 * since the entry is already saved and the notification email already sent
 * by the time this runs.
 *
 * @param array $config        The form's settings['integrations']['flodesk'] array.
 * @param array $values_by_uid Submitted values keyed by field uid.
 */
function alchemy_forms_send_to_flodesk($config, $values_by_uid) {
    $api_key = isset($config['api_key']) ? trim($config['api_key']) : '';
    if ($api_key === '') return;

    $email_field = isset($config['email_field']) ? $config['email_field'] : '';
    $email       = ($email_field !== '' && isset($values_by_uid[$email_field])) ? trim($values_by_uid[$email_field]) : '';
    if ($email === '' || !is_email($email)) return; // nothing to send, or the mapped field was empty/not an address

    $body = ['email' => $email];

    $first_field = isset($config['first_name_field']) ? $config['first_name_field'] : '';
    if ($first_field !== '' && !empty($values_by_uid[$first_field])) {
        $body['first_name'] = (string) $values_by_uid[$first_field];
    }

    $last_field = isset($config['last_name_field']) ? $config['last_name_field'] : '';
    if ($last_field !== '' && !empty($values_by_uid[$last_field])) {
        $body['last_name'] = (string) $values_by_uid[$last_field];
    }

    if (!empty($config['segment_ids']) && is_array($config['segment_ids'])) {
        $body['segment_ids'] = array_values($config['segment_ids']);
    }

    $response = wp_remote_post('https://api.flodesk.com/v1/subscribers', [
        'timeout' => 5,
        'headers' => [
            'Authorization' => 'Basic ' . base64_encode($api_key . ':'),
            'Content-Type'  => 'application/json',
        ],
        'body' => wp_json_encode($body),
    ]);

    if (is_wp_error($response)) {
        error_log('Alchemy Forms: Flodesk request failed — ' . $response->get_error_message());
        return;
    }

    $code = wp_remote_retrieve_response_code($response);
    if ($code < 200 || $code >= 300) {
        error_log(sprintf('Alchemy Forms: Flodesk API returned HTTP %d — %s', $code, wp_remote_retrieve_body($response)));
    }
}
