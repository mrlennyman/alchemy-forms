<?php
if (!defined('ABSPATH')) exit;

/* -------------------------------------------------------------------------
 * Stripe integration — one site-wide Secret Key + Webhook Signing Secret
 * (set under Alchemy Forms → Settings), used by any form with "Require
 * payment" turned on in its Payment box. Uses Stripe Checkout (a hosted
 * payment page) via plain REST calls — no Stripe SDK, no card data ever
 * touches this server, consistent with how Flodesk/AWeber/Mailchimp already
 * talk to their APIs in this plugin.
 *
 * A submission that requires payment is held in a transient (keyed by a
 * random token) instead of being saved immediately — the visitor is
 * redirected to Stripe and back, and the submission is only finalized
 * (entry saved, email sent, integrations synced) once payment is confirmed,
 * either by the visitor's return to the page or by the webhook below,
 * whichever happens first. See alchemy_forms_stripe_finalize_token().
 * ---------------------------------------------------------------------- */

function alchemy_forms_stripe_secret_key() {
    return trim((string) get_option('alchemy_forms_stripe_secret_key', ''));
}

function alchemy_forms_stripe_configured() {
    return alchemy_forms_stripe_secret_key() !== '';
}

/**
 * Whether the configured key is a Stripe test-mode key — shown as a badge
 * next to the key field so it's obvious which mode a site is in.
 */
function alchemy_forms_stripe_test_mode() {
    return strpos(alchemy_forms_stripe_secret_key(), 'sk_test_') === 0;
}

/**
 * Plain "CURRENCY 12.34" display for the fixed-amount summary shown to the
 * visitor — not locale-aware currency formatting, just enough to be clear.
 */
function alchemy_forms_stripe_format_money($amount, $currency) {
    return sprintf('%s %s', strtoupper($currency), number_format((float) $amount, 2));
}

function alchemy_forms_stripe_webhook_url() {
    return admin_url('admin-ajax.php?action=alchemy_forms_stripe_webhook');
}

/**
 * Currencies offered in the per-form Payment box — deliberately a short,
 * curated list rather than every ISO code Stripe supports.
 */
function alchemy_forms_stripe_currencies() {
    return [
        'usd' => __('USD — US Dollar', 'alchemy-forms'),
        'nzd' => __('NZD — New Zealand Dollar', 'alchemy-forms'),
        'aud' => __('AUD — Australian Dollar', 'alchemy-forms'),
        'gbp' => __('GBP — British Pound', 'alchemy-forms'),
        'eur' => __('EUR — Euro', 'alchemy-forms'),
        'cad' => __('CAD — Canadian Dollar', 'alchemy-forms'),
    ];
}

/**
 * Thin wrapper around Stripe's REST API. $method is 'GET' or 'POST'; $path
 * is relative to the API root (e.g. '/checkout/sessions'); $body (POST only)
 * is a plain associative array — WP_Http encodes nested arrays exactly the
 * way Stripe's form-encoded API expects (e.g. line_items[0][price_data]...).
 * Returns the decoded JSON body on any response (even an error one, so the
 * caller can read Stripe's error message), or null on a transport failure.
 */
function alchemy_forms_stripe_api($method, $path, $body = null) {
    $secret = alchemy_forms_stripe_secret_key();
    if ($secret === '') return null;

    $args = [
        'method'  => $method,
        'timeout' => 15,
        'headers' => ['Authorization' => 'Basic ' . base64_encode($secret . ':')],
    ];
    if ($body !== null) $args['body'] = $body;

    $response = wp_remote_request('https://api.stripe.com/v1' . $path, $args);
    if (is_wp_error($response)) {
        error_log('Alchemy Forms: Stripe request failed — ' . $response->get_error_message());
        return null;
    }

    $decoded = json_decode(wp_remote_retrieve_body($response), true);
    return is_array($decoded) ? $decoded : null;
}

/**
 * Determines the amount to actually charge, in the smallest currency unit
 * (cents). For a fixed-price form this ALWAYS returns the admin-configured
 * amount — the posted value is never trusted, since a visitor can edit a
 * hidden field's value in their browser before submitting. For a
 * visitor-entered amount, the posted value is validated as numeric and
 * clamped to the configured minimum (and a fixed hard ceiling, to catch a
 * stray extra digit rather than silently attempting to charge it). Returns
 * null when the amount can't be resolved (payment not actually configured,
 * or an invalid/missing visitor-entered amount).
 */
function alchemy_forms_stripe_resolve_amount($payment_config, $posted_amount) {
    if (empty($payment_config['enabled'])) return null;

    if (($payment_config['amount_type'] ?? 'fixed') === 'variable') {
        $dollars = is_numeric($posted_amount) ? (float) $posted_amount : null;
        if ($dollars === null) return null;
        $min = isset($payment_config['min_amount']) ? (float) $payment_config['min_amount'] : 0;
        if ($dollars < $min || $dollars <= 0 || $dollars > 999999) return null;
        return (int) round($dollars * 100);
    }

    $fixed = isset($payment_config['fixed_amount']) ? (float) $payment_config['fixed_amount'] : 0;
    if ($fixed <= 0) return null;
    return (int) round($fixed * 100);
}

/**
 * Out-of-band signal from alchemy_forms_render_shortcode() to
 * alchemy_forms_ajax_submit(): when a submission needs to redirect the
 * visitor to Stripe rather than show a success message inline, the shortcode
 * still returns ordinary HTML (an interstitial, for a plain non-JS POST)
 * but also records the redirect URL here so the AJAX handler can send it
 * back as JSON instead, letting the browser navigate via window.location
 * rather than relying on a <script> tag inside injected HTML (which never
 * executes when inserted via innerHTML). A plain function-static, not a
 * global — scoped to the single request either way runs in.
 */
function alchemy_forms_stripe_pending_redirect($set_url = null) {
    static $url = '';
    if ($set_url !== null) $url = $set_url;
    return $url;
}

/**
 * Creates a Checkout Session for one pending submission. $args:
 *   amount_cents (int, already validated/clamped by the caller — never the
 *                 raw client-submitted value for a fixed-price form),
 *   currency (3-letter lowercase), description (string), token (the pending
 *   submission's transient key suffix — stored as client_reference_id so the
 *   webhook/return handler can look the submission back up), return_url
 *   (the page to send the visitor back to either way), customer_email
 *   (optional, prefills Stripe's own email field).
 * Returns ['id' => ..., 'url' => ...] on success, or null on failure.
 */
function alchemy_forms_stripe_create_checkout_session($args) {
    $success_url = add_query_arg([
        'alchemy_forms_paid'  => '1',
        'alchemy_forms_token' => $args['token'],
        'session_id'          => '{CHECKOUT_SESSION_ID}',
    ], $args['return_url']);
    // Stripe doesn't urlencode the {CHECKOUT_SESSION_ID} placeholder itself —
    // add_query_arg() already left it intact since it only touches the args
    // it was given, but double check nothing upstream encoded the braces.
    $success_url = str_replace(['%7B', '%7D'], ['{', '}'], $success_url);
    $cancel_url  = add_query_arg([
        'alchemy_forms_cancelled' => '1',
        'alchemy_forms_token'     => $args['token'],
    ], $args['return_url']);

    $body = [
        'mode'                 => 'payment',
        'success_url'          => $success_url,
        'cancel_url'           => $cancel_url,
        'client_reference_id'  => $args['token'],
        'line_items'           => [[
            'quantity'   => 1,
            'price_data' => [
                'currency'     => $args['currency'],
                'unit_amount'  => $args['amount_cents'],
                'product_data' => ['name' => $args['description']],
            ],
        ]],
    ];
    if (!empty($args['customer_email'])) $body['customer_email'] = $args['customer_email'];

    $session = alchemy_forms_stripe_api('POST', '/checkout/sessions', $body);
    if (!is_array($session) || empty($session['url'])) {
        if (is_array($session) && !empty($session['error']['message'])) {
            error_log('Alchemy Forms: Stripe checkout session creation failed — ' . $session['error']['message']);
        }
        return null;
    }
    return $session;
}

function alchemy_forms_stripe_retrieve_session($session_id) {
    if ($session_id === '') return null;
    return alchemy_forms_stripe_api('GET', '/checkout/sessions/' . rawurlencode($session_id));
}

/**
 * Verifies a webhook request's Stripe-Signature header against the raw
 * payload and the configured signing secret. Rejects a timestamp more than
 * 5 minutes old (Stripe's own recommended tolerance) to guard against a
 * replayed request. hash_equals() (not ===) avoids a timing side-channel.
 */
function alchemy_forms_stripe_verify_webhook($payload, $sig_header, $secret) {
    if ($secret === '' || $sig_header === '') return false;

    $timestamp = '';
    $signatures = [];
    foreach (explode(',', $sig_header) as $part) {
        $pair = explode('=', trim($part), 2);
        if (count($pair) !== 2) continue;
        if ($pair[0] === 't') $timestamp = $pair[1];
        if ($pair[0] === 'v1') $signatures[] = $pair[1];
    }
    if ($timestamp === '' || empty($signatures)) return false;
    if (abs(time() - (int) $timestamp) > 5 * MINUTE_IN_SECONDS) return false;

    $expected = hash_hmac('sha256', $timestamp . '.' . $payload, $secret);
    foreach ($signatures as $sig) {
        if (hash_equals($expected, $sig)) return true;
    }
    return false;
}

/**
 * Reads back a pending submission by its token, but only if it belongs to
 * $form_id — a page can embed more than one form, and the token in a
 * return-from-Stripe URL is form-agnostic, so each form's own shortcode
 * instance must ignore a token that isn't its own (some other instance on
 * the same page will claim it instead).
 */
function alchemy_forms_stripe_pending_data($token, $form_id) {
    $pending = get_transient('alchemy_forms_pending_' . $token);
    if (!is_array($pending) || (int) $pending['form_id'] !== (int) $form_id) return null;
    return $pending;
}

/**
 * The reliable payment-confirmation path — Stripe calls this directly, so a
 * payment still gets recorded even if the visitor closes the tab and never
 * returns to the success page. Idempotent with the return-URL path in
 * render.php via the same alchemy_forms_stripe_finalize_token() (whichever
 * of the two gets there first "claims" the pending transient).
 */
function alchemy_forms_stripe_webhook_handler() {
    $payload    = file_get_contents('php://input');
    $sig_header = isset($_SERVER['HTTP_STRIPE_SIGNATURE']) ? $_SERVER['HTTP_STRIPE_SIGNATURE'] : '';
    $secret     = trim((string) get_option('alchemy_forms_stripe_webhook_secret', ''));

    if (!alchemy_forms_stripe_verify_webhook($payload, $sig_header, $secret)) {
        status_header(400);
        exit;
    }

    $event = json_decode($payload, true);
    if (!is_array($event) || empty($event['type'])) {
        status_header(400);
        exit;
    }

    if ($event['type'] === 'checkout.session.completed') {
        $session = isset($event['data']['object']) ? $event['data']['object'] : [];
        if (!empty($session['client_reference_id']) && ($session['payment_status'] ?? '') === 'paid') {
            alchemy_forms_stripe_finalize_token((string) $session['client_reference_id']);
        }
    }

    status_header(200);
    exit;
}
add_action('wp_ajax_nopriv_alchemy_forms_stripe_webhook', 'alchemy_forms_stripe_webhook_handler');
add_action('wp_ajax_alchemy_forms_stripe_webhook', 'alchemy_forms_stripe_webhook_handler');

/**
 * Finalizes one paid submission: saves the entry, sends the notification
 * email, and syncs any enabled email-marketing integration — the same work
 * alchemy_forms_render_shortcode() does immediately for a non-payment form,
 * just deferred here until payment is actually confirmed. Safe to call more
 * than once for the same token (e.g. the return-URL check and the webhook
 * both firing for the same payment) — only the first call finds the pending
 * transient and does anything.
 */
function alchemy_forms_stripe_finalize_token($token) {
    if ($token === '' || get_transient('alchemy_forms_paid_' . $token)) {
        return true; // already finalized (or nothing to do)
    }

    $pending = get_transient('alchemy_forms_pending_' . $token);
    if (!is_array($pending)) return false; // unknown or expired token

    $form_id = (int) $pending['form_id'];

    // Claim it immediately so a near-simultaneous webhook + return-URL check
    // can't both finalize the same submission. Stores the form ID (not just a
    // flag) so a page embedding more than one payment-required form can tell
    // which one this token's "already paid" state actually belongs to.
    delete_transient('alchemy_forms_pending_' . $token);
    set_transient('alchemy_forms_paid_' . $token, $form_id, WEEK_IN_SECONDS);

    $form    = get_post($form_id);
    if (!$form) return false;

    $settings  = get_post_meta($form_id, '_wa_form_settings', true);
    if (!is_array($settings)) $settings = [];
    $recipient = alchemy_forms_parse_recipients(isset($settings['recipient']) ? $settings['recipient'] : '');

    $entry_data = $pending['entry_data'];
    if (isset($pending['amount_cents'], $pending['currency'])) {
        $entry_data[__('Amount paid', 'alchemy-forms')] = alchemy_forms_stripe_format_money($pending['amount_cents'] / 100, $pending['currency']);
    }

    alchemy_forms_finalize_submission(
        $form_id,
        $form->post_title,
        $entry_data,
        $pending['values_by_uid'],
        $pending['attachment_paths'],
        $settings,
        $recipient
    );

    return true;
}
