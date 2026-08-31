<?php
if (!defined('ABSPATH')) exit;

/* -------------------------------------------------------------------------
 * AWeber integration — OAuth2 only (AWeber has no static API key option;
 * every new integration must use the authorization-code flow). One
 * site-wide connection, stored as options, used by any form that enables
 * AWeber sync in its Email Marketing tab.
 * ---------------------------------------------------------------------- */

define('ALCHEMY_FORMS_AWEBER_AUTH_URL', 'https://auth.aweber.com/oauth2/authorize');
define('ALCHEMY_FORMS_AWEBER_TOKEN_URL', 'https://auth.aweber.com/oauth2/token');
define('ALCHEMY_FORMS_AWEBER_API_BASE', 'https://api.aweber.com/1.0');

/**
 * Must be registered exactly as-is as the app's redirect URI in AWeber's
 * developer console before "Connect to AWeber" will work.
 */
function alchemy_forms_aweber_redirect_uri() {
    return admin_url('admin-post.php?action=alchemy_forms_aweber_callback');
}

function alchemy_forms_aweber_client_configured() {
    return get_option('alchemy_forms_aweber_client_id', '') !== '' && get_option('alchemy_forms_aweber_client_secret', '') !== '';
}

function alchemy_forms_aweber_connected() {
    return get_option('alchemy_forms_aweber_refresh_token', '') !== '';
}

/**
 * Step 1: send the admin's browser to AWeber's consent screen.
 */
add_action('admin_post_alchemy_forms_aweber_connect', function () {
    if (!current_user_can('manage_options')) wp_die(esc_html__('You do not have permission to do this.', 'alchemy-forms'));
    check_admin_referer('alchemy_forms_aweber_connect');

    if (!alchemy_forms_aweber_client_configured()) {
        wp_die(esc_html__('Enter an AWeber Client ID and Client Secret first.', 'alchemy-forms'));
    }

    $state = wp_generate_password(32, false);
    set_transient('alchemy_forms_aweber_state_' . get_current_user_id(), $state, 10 * MINUTE_IN_SECONDS);

    $url = add_query_arg([
        'response_type' => 'code',
        'client_id'     => get_option('alchemy_forms_aweber_client_id', ''),
        'redirect_uri'  => alchemy_forms_aweber_redirect_uri(),
        'scope'         => 'account.read list.read subscriber.read subscriber.write',
        'state'         => $state,
    ], ALCHEMY_FORMS_AWEBER_AUTH_URL);

    wp_redirect($url);
    exit;
});

/**
 * Step 2: AWeber redirects the browser back here with ?code=...&state=....
 * Exchange the code for an access + refresh token pair and store them.
 */
add_action('admin_post_alchemy_forms_aweber_callback', function () {
    if (!current_user_can('manage_options')) wp_die(esc_html__('You do not have permission to do this.', 'alchemy-forms'));

    $settings_url = admin_url('edit.php?post_type=wa_form&page=wa-form-settings');

    $state          = isset($_GET['state']) ? sanitize_text_field(wp_unslash($_GET['state'])) : '';
    $expected_state = get_transient('alchemy_forms_aweber_state_' . get_current_user_id());
    delete_transient('alchemy_forms_aweber_state_' . get_current_user_id());

    if ($state === '' || !is_string($expected_state) || !hash_equals($expected_state, $state)) {
        wp_safe_redirect(add_query_arg('aweber_error', 'state', $settings_url));
        exit;
    }

    if (empty($_GET['code'])) {
        wp_safe_redirect(add_query_arg('aweber_error', 'denied', $settings_url));
        exit;
    }
    $code = sanitize_text_field(wp_unslash($_GET['code']));

    $response = wp_remote_post(ALCHEMY_FORMS_AWEBER_TOKEN_URL, [
        'timeout' => 10,
        'headers' => [
            'Authorization' => 'Basic ' . base64_encode(get_option('alchemy_forms_aweber_client_id', '') . ':' . get_option('alchemy_forms_aweber_client_secret', '')),
            'Content-Type'  => 'application/x-www-form-urlencoded',
        ],
        'body' => [
            'grant_type'   => 'authorization_code',
            'code'         => $code,
            'redirect_uri' => alchemy_forms_aweber_redirect_uri(),
        ],
    ]);

    if (is_wp_error($response) || wp_remote_retrieve_response_code($response) !== 200) {
        wp_safe_redirect(add_query_arg('aweber_error', 'token', $settings_url));
        exit;
    }

    $body = json_decode(wp_remote_retrieve_body($response), true);
    if (empty($body['access_token']) || empty($body['refresh_token'])) {
        wp_safe_redirect(add_query_arg('aweber_error', 'token', $settings_url));
        exit;
    }

    alchemy_forms_aweber_store_tokens($body);

    // One AWeber account per connection is the overwhelmingly common case —
    // store its ID now so every later list/subscriber call doesn't need an
    // extra round trip to look it up first.
    $account = alchemy_forms_aweber_api_get('/accounts');
    if (is_array($account) && !empty($account['entries'][0]['id'])) {
        update_option('alchemy_forms_aweber_account_id', (string) $account['entries'][0]['id'], false);
    }

    wp_safe_redirect(add_query_arg('aweber_connected', '1', $settings_url));
    exit;
});

add_action('admin_post_alchemy_forms_aweber_disconnect', function () {
    if (!current_user_can('manage_options')) wp_die(esc_html__('You do not have permission to do this.', 'alchemy-forms'));
    check_admin_referer('alchemy_forms_aweber_disconnect');

    delete_option('alchemy_forms_aweber_access_token');
    delete_option('alchemy_forms_aweber_refresh_token');
    delete_option('alchemy_forms_aweber_token_expires');
    delete_option('alchemy_forms_aweber_account_id');

    wp_safe_redirect(add_query_arg('aweber_disconnected', '1', admin_url('edit.php?post_type=wa_form&page=wa-form-settings')));
    exit;
});

function alchemy_forms_aweber_store_tokens($body) {
    update_option('alchemy_forms_aweber_access_token', $body['access_token'], false);
    update_option('alchemy_forms_aweber_refresh_token', $body['refresh_token'], false);
    $expires_in = isset($body['expires_in']) ? (int) $body['expires_in'] : 3600;
    update_option('alchemy_forms_aweber_token_expires', time() + $expires_in, false);
}

/**
 * Returns a currently-valid access token, refreshing first if the stored one
 * is expired or about to be — AWeber's access tokens last about an hour, so
 * this will need to refresh on most submissions, not just occasionally.
 * Returns '' on any failure (network error, revoked connection); callers
 * must treat that as "can't sync right now", not retry indefinitely.
 */
function alchemy_forms_aweber_get_access_token() {
    if (!alchemy_forms_aweber_connected() || !alchemy_forms_aweber_client_configured()) return '';

    $expires = (int) get_option('alchemy_forms_aweber_token_expires', 0);
    if ($expires > time() + 60) {
        return get_option('alchemy_forms_aweber_access_token', '');
    }

    $response = wp_remote_post(ALCHEMY_FORMS_AWEBER_TOKEN_URL, [
        'timeout' => 10,
        'headers' => [
            'Authorization' => 'Basic ' . base64_encode(get_option('alchemy_forms_aweber_client_id', '') . ':' . get_option('alchemy_forms_aweber_client_secret', '')),
            'Content-Type'  => 'application/x-www-form-urlencoded',
        ],
        'body' => [
            'grant_type'    => 'refresh_token',
            'refresh_token' => get_option('alchemy_forms_aweber_refresh_token', ''),
        ],
    ]);

    if (is_wp_error($response) || wp_remote_retrieve_response_code($response) !== 200) {
        error_log('Alchemy Forms: AWeber token refresh failed — ' . (is_wp_error($response) ? $response->get_error_message() : wp_remote_retrieve_body($response)));
        return '';
    }

    $body = json_decode(wp_remote_retrieve_body($response), true);
    if (empty($body['access_token']) || empty($body['refresh_token'])) return '';

    // AWeber rotates the refresh token on every use — the old one won't
    // work again, so this replacement is required, not optional.
    alchemy_forms_aweber_store_tokens($body);
    return $body['access_token'];
}

/**
 * Thin GET wrapper against AWeber's API using the current access token.
 * $path is relative to ALCHEMY_FORMS_AWEBER_API_BASE (e.g. '/accounts') and
 * may include a query string. Returns the decoded JSON body, or null on
 * any failure.
 */
function alchemy_forms_aweber_api_get($path) {
    $token = alchemy_forms_aweber_get_access_token();
    if ($token === '') return null;

    $response = wp_remote_get(ALCHEMY_FORMS_AWEBER_API_BASE . $path, [
        'timeout' => 10,
        'headers' => ['Authorization' => 'Bearer ' . $token],
    ]);

    if (is_wp_error($response) || wp_remote_retrieve_response_code($response) !== 200) return null;
    $body = json_decode(wp_remote_retrieve_body($response), true);
    return is_array($body) ? $body : null;
}

/**
 * Fetches the connected account's lists, for the admin list picker.
 */
function alchemy_forms_fetch_aweber_lists() {
    $account_id = get_option('alchemy_forms_aweber_account_id', '');
    if ($account_id === '') return [];

    $lists = [];
    $path  = '/accounts/' . rawurlencode($account_id) . '/lists?ws.size=100';
    $guard = 0; // defensive cap against a runaway pagination loop

    while ($path !== '' && $guard < 20) {
        $body = alchemy_forms_aweber_api_get($path);
        if (!is_array($body) || empty($body['entries']) || !is_array($body['entries'])) break;

        foreach ($body['entries'] as $list) {
            if (!is_array($list) || empty($list['id'])) continue;
            $lists[] = [
                'id'          => (string) $list['id'],
                'name'        => isset($list['name']) ? (string) $list['name'] : (string) $list['id'],
                'subscribers' => isset($list['total_subscribers']) ? (int) $list['total_subscribers'] : null,
            ];
        }

        // AWeber's "next" link is a full URL; strip the API base back off so
        // alchemy_forms_aweber_api_get() (which prepends it) can be reused.
        $next = isset($body['next_collection_link']) ? (string) $body['next_collection_link'] : '';
        $path = ($next !== '' && strpos($next, ALCHEMY_FORMS_AWEBER_API_BASE) === 0) ? substr($next, strlen(ALCHEMY_FORMS_AWEBER_API_BASE)) : '';
        $guard++;
    }

    return $lists;
}

add_action('wp_ajax_alchemy_forms_fetch_aweber_lists', function () {
    $post_id = isset($_POST['post_id']) ? (int) $_POST['post_id'] : 0;
    if (!$post_id || !current_user_can('edit_post', $post_id)) {
        wp_send_json_error(__('You do not have permission to do this.', 'alchemy-forms'));
    }
    check_ajax_referer('alchemy_forms_aweber_lists_' . $post_id, 'nonce');

    if (!alchemy_forms_aweber_connected()) {
        wp_send_json_error(__('AWeber is not connected — go to Alchemy Forms → Settings to connect it.', 'alchemy-forms'));
    }

    $lists = alchemy_forms_fetch_aweber_lists();
    if (empty($lists)) {
        wp_send_json_error(__('Could not fetch lists — check the connection and try again.', 'alchemy-forms'));
    }

    set_transient('alchemy_forms_aweber_lists_' . $post_id, $lists, HOUR_IN_SECONDS);
    wp_send_json_success($lists);
});

/**
 * Adds/updates an AWeber subscriber after a successful submission. Same
 * fail-quietly contract as alchemy_forms_send_to_flodesk() — the entry is
 * already saved and the notification email already sent by the time this
 * runs, so a failure here should never cost the actual submission.
 *
 * AWeber's v1.0 API needs a ws.op=create query parameter alongside the POST
 * body to create a new resource — confirmed from a real "missing ws.op"
 * 400 error report, since AWeber's interactive docs didn't render for
 * direct verification. If the exact request shape needs a follow-up
 * adjustment, the logged HTTP status + response body below will show
 * exactly what AWeber objected to.
 */
function alchemy_forms_send_to_aweber($config, $values_by_uid) {
    if (empty($config['enabled']) || empty($config['list_id'])) return;

    $account_id = get_option('alchemy_forms_aweber_account_id', '');
    if ($account_id === '') return;

    $email_field = isset($config['email_field']) ? $config['email_field'] : '';
    $email       = ($email_field !== '' && isset($values_by_uid[$email_field])) ? trim($values_by_uid[$email_field]) : '';
    if ($email === '' || !is_email($email)) return; // nothing to send, or the mapped field was empty/not an address

    $token = alchemy_forms_aweber_get_access_token();
    if ($token === '') {
        error_log('Alchemy Forms: AWeber sync skipped — no valid access token (the connection may need reauthorizing under Alchemy Forms → Settings).');
        return;
    }

    $body = ['email' => $email];

    $name_field = isset($config['name_field']) ? $config['name_field'] : '';
    if ($name_field !== '' && !empty($values_by_uid[$name_field])) {
        $body['name'] = (string) $values_by_uid[$name_field];
    }

    $url = ALCHEMY_FORMS_AWEBER_API_BASE
        . '/accounts/' . rawurlencode($account_id)
        . '/lists/' . rawurlencode($config['list_id'])
        . '/subscribers?ws.op=create';

    $response = wp_remote_post($url, [
        'timeout' => 8,
        'headers' => [
            'Authorization' => 'Bearer ' . $token,
            'Content-Type'  => 'application/json',
        ],
        'body' => wp_json_encode($body),
    ]);

    if (is_wp_error($response)) {
        error_log('Alchemy Forms: AWeber request failed — ' . $response->get_error_message());
        return;
    }

    $code = wp_remote_retrieve_response_code($response);
    if ($code < 200 || $code >= 300) {
        error_log(sprintf('Alchemy Forms: AWeber API returned HTTP %d — %s', $code, wp_remote_retrieve_body($response)));
    }
}
