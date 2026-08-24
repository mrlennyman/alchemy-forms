<?php
if (!defined('ABSPATH')) exit;

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
