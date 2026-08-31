<?php
if (!defined('ABSPATH')) exit;

/* -------------------------------------------------------------------------
 * Global Settings page — Cloudflare Turnstile keys apply site-wide (one
 * Turnstile site covers the whole domain); which forms actually show the
 * challenge is a per-form toggle in that form's Settings box.
 * ---------------------------------------------------------------------- */
add_action('admin_menu', function () {
    add_submenu_page(
        'edit.php?post_type=wa_form',
        __('Settings', 'alchemy-forms'),
        __('Settings', 'alchemy-forms'),
        'manage_options',
        'wa-form-settings',
        'alchemy_forms_settings_page'
    );
});

add_action('admin_init', function () {
    register_setting('alchemy_forms_settings', 'alchemy_forms_turnstile_site_key', [
        'sanitize_callback' => 'sanitize_text_field',
        'default'           => '',
    ]);
    register_setting('alchemy_forms_settings', 'alchemy_forms_turnstile_secret_key', [
        'sanitize_callback' => 'sanitize_text_field',
        'default'           => '',
    ]);
    register_setting('alchemy_forms_settings', 'alchemy_forms_aweber_client_id', [
        'sanitize_callback' => 'sanitize_text_field',
        'default'           => '',
    ]);
    register_setting('alchemy_forms_settings', 'alchemy_forms_aweber_client_secret', [
        'sanitize_callback' => 'sanitize_text_field',
        'default'           => '',
    ]);
});

function alchemy_forms_settings_page() {
    if (!current_user_can('manage_options')) {
        wp_die(esc_html__('You do not have permission to access this page.', 'alchemy-forms'));
    }
    ?>
    <div class="wrap">
        <h1><?php esc_html_e('Alchemy Forms Settings', 'alchemy-forms'); ?></h1>

        <?php if (isset($_GET['aweber_connected'])) : ?>
            <div class="notice notice-success is-dismissible"><p><?php esc_html_e('AWeber connected.', 'alchemy-forms'); ?></p></div>
        <?php elseif (isset($_GET['aweber_disconnected'])) : ?>
            <div class="notice notice-success is-dismissible"><p><?php esc_html_e('AWeber disconnected.', 'alchemy-forms'); ?></p></div>
        <?php elseif (isset($_GET['aweber_error'])) : ?>
            <div class="notice notice-error is-dismissible"><p><?php esc_html_e('Could not connect to AWeber — the authorization was denied, expired, or the request failed. Please try again.', 'alchemy-forms'); ?></p></div>
        <?php endif; ?>

        <form method="post" action="options.php">
            <?php settings_fields('alchemy_forms_settings'); ?>
            <h2><?php esc_html_e('Cloudflare Turnstile', 'alchemy-forms'); ?></h2>
            <table class="form-table">
                <tr>
                    <th scope="row"><label for="alchemy_forms_turnstile_site_key"><?php esc_html_e('Site Key', 'alchemy-forms'); ?></label></th>
                    <td><input type="text" id="alchemy_forms_turnstile_site_key" name="alchemy_forms_turnstile_site_key" value="<?php echo esc_attr(get_option('alchemy_forms_turnstile_site_key', '')); ?>" class="regular-text"></td>
                </tr>
                <tr>
                    <th scope="row"><label for="alchemy_forms_turnstile_secret_key"><?php esc_html_e('Secret Key', 'alchemy-forms'); ?></label></th>
                    <td><input type="text" id="alchemy_forms_turnstile_secret_key" name="alchemy_forms_turnstile_secret_key" value="<?php echo esc_attr(get_option('alchemy_forms_turnstile_secret_key', '')); ?>" class="regular-text" autocomplete="off"></td>
                </tr>
            </table>
            <p class="description"><?php esc_html_e('Get these from your Cloudflare dashboard under Turnstile. One key pair covers this whole site — turn Turnstile on for individual forms under that form\'s Settings box.', 'alchemy-forms'); ?></p>

            <h2><?php esc_html_e('AWeber', 'alchemy-forms'); ?></h2>
            <table class="form-table">
                <tr>
                    <th scope="row"><label for="alchemy_forms_aweber_client_id"><?php esc_html_e('Client ID', 'alchemy-forms'); ?></label></th>
                    <td><input type="text" id="alchemy_forms_aweber_client_id" name="alchemy_forms_aweber_client_id" value="<?php echo esc_attr(get_option('alchemy_forms_aweber_client_id', '')); ?>" class="regular-text"></td>
                </tr>
                <tr>
                    <th scope="row"><label for="alchemy_forms_aweber_client_secret"><?php esc_html_e('Client Secret', 'alchemy-forms'); ?></label></th>
                    <td><input type="text" id="alchemy_forms_aweber_client_secret" name="alchemy_forms_aweber_client_secret" value="<?php echo esc_attr(get_option('alchemy_forms_aweber_client_secret', '')); ?>" class="regular-text" autocomplete="off"></td>
                </tr>
                <tr>
                    <th scope="row"><?php esc_html_e('Redirect URI', 'alchemy-forms'); ?></th>
                    <td>
                        <input type="text" readonly value="<?php echo esc_attr(alchemy_forms_aweber_redirect_uri()); ?>" class="regular-text" onclick="this.select();">
                        <p class="description"><?php esc_html_e('Register this exact URL as the redirect URI on your AWeber app before connecting.', 'alchemy-forms'); ?></p>
                    </td>
                </tr>
            </table>
            <p class="description">
                <?php esc_html_e('Create an app at AWeber\'s developer console to get a Client ID and Secret, then save this page before connecting. One connection covers this whole site — turn AWeber sync on for individual forms under that form\'s Email Marketing tab.', 'alchemy-forms'); ?>
            </p>
            <?php submit_button(); ?>
        </form>

        <?php if (alchemy_forms_aweber_client_configured()) : ?>
            <?php if (alchemy_forms_aweber_connected()) : ?>
                <p>
                    <strong><?php esc_html_e('Status:', 'alchemy-forms'); ?></strong> <?php esc_html_e('Connected', 'alchemy-forms'); ?>
                    &nbsp;
                    <a class="button button-secondary" href="<?php echo esc_url(wp_nonce_url(admin_url('admin-post.php?action=alchemy_forms_aweber_disconnect'), 'alchemy_forms_aweber_disconnect')); ?>"><?php esc_html_e('Disconnect', 'alchemy-forms'); ?></a>
                </p>
            <?php else : ?>
                <p>
                    <a class="button button-primary" href="<?php echo esc_url(wp_nonce_url(admin_url('admin-post.php?action=alchemy_forms_aweber_connect'), 'alchemy_forms_aweber_connect')); ?>"><?php esc_html_e('Connect to AWeber', 'alchemy-forms'); ?></a>
                </p>
            <?php endif; ?>
        <?php endif; ?>
    </div>
    <?php
}

/**
 * Whether both Turnstile keys are set — the per-form toggle only does
 * anything once this is true.
 */
function alchemy_forms_turnstile_configured() {
    return get_option('alchemy_forms_turnstile_site_key', '') !== '' && get_option('alchemy_forms_turnstile_secret_key', '') !== '';
}

/**
 * Verifies a Turnstile response token against Cloudflare's siteverify API.
 * Fails closed: a missing token, missing secret, network error, or anything
 * other than an explicit "success" in the response is treated as failed
 * verification, not silently passed through.
 */
function alchemy_forms_verify_turnstile($token) {
    $secret = get_option('alchemy_forms_turnstile_secret_key', '');
    if ($secret === '' || empty($token)) return false;

    $response = wp_remote_post('https://challenges.cloudflare.com/turnstile/v0/siteverify', [
        'timeout' => 8,
        'body'    => [
            'secret'   => $secret,
            'response' => $token,
            'remoteip' => isset($_SERVER['REMOTE_ADDR']) ? $_SERVER['REMOTE_ADDR'] : '',
        ],
    ]);

    if (is_wp_error($response)) return false;

    $body = json_decode(wp_remote_retrieve_body($response), true);
    return is_array($body) && !empty($body['success']);
}
