<?php
if (!defined('ABSPATH')) exit;

/**
 * Mailchimp's data-center subdomain is embedded in the API key itself, after
 * the dash (e.g. "abc123def456-us21" → "us21") — there's no separate
 * setting for it.
 */
function alchemy_forms_mailchimp_datacenter($api_key) {
    $pos = strrpos((string) $api_key, '-');
    return ($pos !== false) ? substr($api_key, $pos + 1) : '';
}

function alchemy_forms_mailchimp_api_base($api_key) {
    $dc = alchemy_forms_mailchimp_datacenter($api_key);
    return $dc !== '' ? "https://{$dc}.api.mailchimp.com/3.0" : '';
}

/**
 * Fetches the account's audiences (lists) from Mailchimp, for the admin
 * audience picker. Returns [] on any failure — the picker just falls back
 * to its "no audiences found" state rather than blocking the admin.
 */
function alchemy_forms_fetch_mailchimp_lists($api_key) {
    $api_key = trim((string) $api_key);
    $base    = alchemy_forms_mailchimp_api_base($api_key);
    if ($base === '') return [];

    $response = wp_remote_get(add_query_arg(['count' => 100], $base . '/lists'), [
        'timeout' => 8,
        'headers' => ['Authorization' => 'Basic ' . base64_encode('anystring:' . $api_key)],
    ]);

    if (is_wp_error($response) || wp_remote_retrieve_response_code($response) !== 200) {
        return [];
    }

    $body = json_decode(wp_remote_retrieve_body($response), true);
    if (!is_array($body) || empty($body['lists']) || !is_array($body['lists'])) {
        return [];
    }

    $lists = [];
    foreach ($body['lists'] as $list) {
        if (!is_array($list) || empty($list['id'])) continue;
        $lists[] = [
            'id'          => (string) $list['id'],
            'name'        => isset($list['name']) ? (string) $list['name'] : (string) $list['id'],
            'subscribers' => isset($list['stats']['member_count']) ? (int) $list['stats']['member_count'] : null,
        ];
    }
    return $lists;
}

add_action('wp_ajax_alchemy_forms_fetch_mailchimp_lists', function () {
    $post_id = isset($_POST['post_id']) ? (int) $_POST['post_id'] : 0;
    if (!$post_id || !current_user_can('edit_post', $post_id)) {
        wp_send_json_error(__('You do not have permission to do this.', 'alchemy-forms'));
    }
    check_ajax_referer('alchemy_forms_mailchimp_lists_' . $post_id, 'nonce');

    $api_key = isset($_POST['api_key']) ? sanitize_text_field(wp_unslash($_POST['api_key'])) : '';
    if ($api_key === '') {
        wp_send_json_error(__('Enter an API key first.', 'alchemy-forms'));
    }

    $lists = alchemy_forms_fetch_mailchimp_lists($api_key);
    if (empty($lists)) {
        wp_send_json_error(__('Could not fetch audiences — check the API key and try again.', 'alchemy-forms'));
    }

    set_transient('alchemy_forms_mailchimp_lists_' . $post_id, $lists, HOUR_IN_SECONDS);
    wp_send_json_success($lists);
});

/**
 * Adds/updates a Mailchimp list member after a successful submission. Same
 * fail-quietly contract as the Flodesk/AWeber senders — the entry is already
 * saved and the notification email already sent by the time this runs, so a
 * failure here should never cost the actual submission.
 *
 * Mailchimp upserts by a member ID derived from the lowercased, MD5-hashed
 * email address (PUT .../members/{hash}) — this both adds new subscribers
 * and updates existing ones without needing a separate "does this email
 * already exist" lookup first.
 */
function alchemy_forms_send_to_mailchimp($config, $values_by_uid) {
    $api_key = isset($config['api_key']) ? trim($config['api_key']) : '';
    $base    = alchemy_forms_mailchimp_api_base($api_key);
    if ($base === '' || empty($config['list_id'])) return;

    $email_field = isset($config['email_field']) ? $config['email_field'] : '';
    $email       = ($email_field !== '' && isset($values_by_uid[$email_field])) ? trim($values_by_uid[$email_field]) : '';
    if ($email === '' || !is_email($email)) return; // nothing to send, or the mapped field was empty/not an address

    $merge_fields = [];
    $first_field = isset($config['first_name_field']) ? $config['first_name_field'] : '';
    if ($first_field !== '' && !empty($values_by_uid[$first_field])) {
        $merge_fields['FNAME'] = (string) $values_by_uid[$first_field];
    }
    $last_field = isset($config['last_name_field']) ? $config['last_name_field'] : '';
    if ($last_field !== '' && !empty($values_by_uid[$last_field])) {
        $merge_fields['LNAME'] = (string) $values_by_uid[$last_field];
    }

    // "status_if_new" only applies when this email doesn't already exist on
    // the audience — deliberately not also passing "status", so re-submitting
    // this form never silently re-subscribes someone who'd unsubscribed.
    $body = [
        'email_address' => $email,
        'status_if_new' => 'subscribed',
    ];
    if ($merge_fields) $body['merge_fields'] = $merge_fields;

    $member_hash = md5(strtolower($email));
    $url = $base . '/lists/' . rawurlencode($config['list_id']) . '/members/' . $member_hash;

    $response = wp_remote_request($url, [
        'method'  => 'PUT',
        'timeout' => 8,
        'headers' => [
            'Authorization' => 'Basic ' . base64_encode('anystring:' . $api_key),
            'Content-Type'  => 'application/json',
        ],
        'body' => wp_json_encode($body),
    ]);

    if (is_wp_error($response)) {
        error_log('Alchemy Forms: Mailchimp request failed — ' . $response->get_error_message());
        return;
    }

    $code = wp_remote_retrieve_response_code($response);
    if ($code < 200 || $code >= 300) {
        error_log(sprintf('Alchemy Forms: Mailchimp API returned HTTP %d — %s', $code, wp_remote_retrieve_body($response)));
    }
}
