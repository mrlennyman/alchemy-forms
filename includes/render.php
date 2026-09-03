<?php
if (!defined('ABSPATH')) exit;

add_shortcode('wa_form', 'alchemy_forms_render_shortcode');

function alchemy_forms_render_shortcode($atts) {
    $atts    = shortcode_atts(['id' => 0, 'title' => ''], $atts, 'wa_form');
    $form_id = (int) $atts['id'];
    $form    = $form_id ? get_post($form_id) : null;

    if (!$form || $form->post_type !== 'wa_form' || $form->post_status !== 'publish') {
        return current_user_can('edit_posts')
            ? '<p><em>' . esc_html__('Alchemy Forms: no published form found for this ID.', 'alchemy-forms') . '</em></p>'
            : '';
    }

    $fields = get_post_meta($form_id, '_wa_form_fields', true);
    if (!is_array($fields) || empty($fields)) {
        return current_user_can('edit_posts')
            ? '<p><em>' . esc_html__('Alchemy Forms: this form has no fields yet.', 'alchemy-forms') . '</em></p>'
            : '';
    }

    $settings = get_post_meta($form_id, '_wa_form_settings', true);
    if (!is_array($settings)) $settings = [];
    $recipient   = alchemy_forms_parse_recipients(isset($settings['recipient']) ? $settings['recipient'] : '');
    $submit_text = !empty($settings['submit_text']) ? $settings['submit_text'] : __('Submit', 'alchemy-forms');
    $success_msg = !empty($settings['success_msg']) ? $settings['success_msg'] : __('Thanks — your submission has been received.', 'alchemy-forms');
    $turnstile_active = !empty($settings['turnstile_enabled']) && alchemy_forms_turnstile_configured();
    $payment_config   = (isset($settings['stripe']) && is_array($settings['stripe'])) ? $settings['stripe'] : [];
    $payment_required = !empty($payment_config['enabled']) && alchemy_forms_stripe_configured();

    // Give each field a stable input name derived from its position + label,
    // and stamp which step it belongs to (a form with no page_break fields is
    // entirely step 0 — this is what keeps single-step forms unaffected below).
    $step        = 0;
    $step_titles = [0 => ''];
    foreach ($fields as $i => &$f) {
        $f['name'] = 'waf_' . $i . '_' . sanitize_title($f['label']);
        if ($f['type'] === 'page_break') {
            $step++;
            $step_titles[$step] = $f['label'] !== '' ? $f['label'] : sprintf(__('Step %d', 'alchemy-forms'), $step + 1);
        }
        $f['_step'] = $step;
    }
    unset($f);

    // Group fields by their raw step number, skipping page_break markers
    // themselves (they only mark where one step ends and the next begins). A
    // leading, trailing, or consecutive page_break leaves some raw step
    // numbers with no fields in them, which would otherwise never get
    // rendered — so re-index sequentially to match what will actually appear
    // in the DOM, and remap _step/$step_titles the same way. Without this,
    // $step_count and $first_error_step (used below) can point at a step
    // that doesn't exist, leaving the last real step without a Submit button
    // or reopening the form on the wrong step after a validation error.
    $raw_steps = [];
    foreach ($fields as $field) {
        // Neither is a visual grid field: page_break only marks a step boundary,
        // and hidden fields render separately (see $hidden_fields below).
        if (in_array($field['type'], ['page_break', 'hidden'], true)) continue;
        $raw_steps[$field['_step']][] = $field;
    }
    ksort($raw_steps);
    $step_remap = array_flip(array_keys($raw_steps));
    $steps      = array_values($raw_steps);
    $step_count = count($steps);

    $remapped_titles = [];
    foreach ($step_remap as $raw => $new) {
        $remapped_titles[$new] = isset($step_titles[$raw]) ? $step_titles[$raw] : '';
    }
    $step_titles = $remapped_titles;

    foreach ($fields as &$f) {
        $f['_step'] = isset($step_remap[$f['_step']]) ? $step_remap[$f['_step']] : 0;
    }
    unset($f);

    $style_settings = (isset($settings['style']) && is_array($settings['style'])) ? $settings['style'] : [];
    $style          = alchemy_forms_resolve_style($style_settings);

    alchemy_forms_enqueue_frontend_css($style['fonts']);

    if ($turnstile_active && !wp_script_is('alchemy-forms-turnstile', 'enqueued')) {
        // render=explicit: frontend.js calls turnstile.render() itself once the
        // API is ready, rather than relying on Cloudflare's own auto-render
        // scan — that scan only runs once on script load, so it would miss a
        // widget that appears later after an AJAX-swapped form re-render.
        wp_enqueue_script('alchemy-forms-turnstile', 'https://challenges.cloudflare.com/turnstile/v0/api.js?onload=alchemyFormsOnTurnstileLoad&render=explicit', [], null, true);
    }

    $errors            = [];
    $values            = [];
    $success           = false;
    $condition_lookup  = []; // uid => submitted value, used to evaluate conditions; empty on a fresh (non-POST) load
    $first_error_step  = null; // which step to reopen the form on if validation fails

    $posted_this_form = isset($_POST['wa_form_id']) && (int) $_POST['wa_form_id'] === $form_id;

    // Returning from Stripe Checkout — a real browser navigation (GET), not
    // the AJAX submit path, since the visitor's browser was away at Stripe's
    // hosted page in between. The token in the URL is form-agnostic (the
    // same page could embed more than one form), so only act on it once
    // alchemy_forms_stripe_pending_data() confirms it belongs to this form.
    if ($payment_required && !empty($_GET['alchemy_forms_token'])) {
        $token = sanitize_text_field(wp_unslash($_GET['alchemy_forms_token']));

        if (!empty($_GET['alchemy_forms_paid']) && !empty($_GET['session_id'])) {
            $paid_form_id = get_transient('alchemy_forms_paid_' . $token);
            if ($paid_form_id !== false && (int) $paid_form_id === $form_id) {
                $success = true; // already finalized earlier — e.g. the visitor refreshed this page
            } else {
                $pending = alchemy_forms_stripe_pending_data($token, $form_id);
                if ($pending !== null) {
                    $session = alchemy_forms_stripe_retrieve_session(sanitize_text_field(wp_unslash($_GET['session_id'])));
                    $paid = is_array($session)
                        && ($session['payment_status'] ?? '') === 'paid'
                        && isset($session['client_reference_id'])
                        && hash_equals($token, (string) $session['client_reference_id']);
                    if ($paid) {
                        alchemy_forms_stripe_finalize_token($token);
                        $success = true;
                    } else {
                        $errors[] = __('We could not confirm your payment yet. If you completed checkout, please wait a moment and refresh this page, or contact us.', 'alchemy-forms');
                    }
                }
            }
        } elseif (!empty($_GET['alchemy_forms_cancelled'])) {
            $pending = alchemy_forms_stripe_pending_data($token, $form_id);
            if ($pending !== null) {
                foreach ($pending['attachment_ids'] as $attachment_id) wp_delete_attachment($attachment_id, true);
                delete_transient('alchemy_forms_pending_' . $token);
                $errors[] = __('Payment was cancelled — your submission was not completed.', 'alchemy-forms');
            }
        }
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST' && $posted_this_form) {
        if (!isset($_POST['wa_form_token']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['wa_form_token'])), 'wa_form_submit_' . $form_id)) {
            $errors[] = __('Your session expired — please try submitting again.', 'alchemy-forms');
        } elseif (!empty($_POST['wa_website_hp'])) {
            $success = true; // Honeypot: pretend it worked, save/send nothing.
        } elseif ($turnstile_active && !alchemy_forms_verify_turnstile(isset($_POST['cf-turnstile-response']) ? wp_unslash($_POST['cf-turnstile-response']) : '')) {
            $errors[] = __('Please complete the verification challenge and try again.', 'alchemy-forms');
        } else {
            $entry_data       = [];
            $values_by_uid    = []; // submitted values keyed by field uid, for the Flodesk integration below
            $attachment_paths = []; // every file uploaded this submission, not just the last field's
            $attachment_ids   = [];

            // First pass: raw values by uid, used only to evaluate each field's
            // conditional visibility before the real per-field handling below.
            $condition_lookup = [];
            $skip_types       = alchemy_forms_condition_ineligible_types();
            foreach ($fields as $cf) {
                if (empty($cf['uid']) || in_array($cf['type'], $skip_types, true)) continue;
                $raw = isset($_POST[$cf['name']]) ? wp_unslash($_POST[$cf['name']]) : '';
                if (is_array($raw)) $raw = '';
                $condition_lookup[$cf['uid']] = sanitize_text_field($raw);
            }

            foreach ($fields as $field) {
                if (in_array($field['type'], alchemy_forms_noninput_field_types(), true)) continue;

                $name  = $field['name'];
                $label = $field['label'];

                $condition = isset($field['condition']) ? $field['condition'] : [];
                if (!alchemy_forms_evaluate_condition($condition, $condition_lookup)) {
                    continue; // hidden by its condition: not required, not stored
                }

                $errors_before = count($errors);

                if ($field['type'] === 'file') {
                    $file_result = alchemy_forms_handle_upload($name, !empty($field['required']), $label, $errors);
                    if ($file_result) {
                        $entry_data[$label] = $file_result['url'];
                        $attachment_paths[] = $file_result['path'];
                        if ($file_result['id']) $attachment_ids[] = $file_result['id'];
                    } else {
                        $entry_data[$label] = '';
                    }
                    if (!empty($field['uid'])) $values_by_uid[$field['uid']] = $entry_data[$label];
                } elseif ($field['type'] === 'checkbox') {
                    $posted  = (isset($_POST[$name]) && is_array($_POST[$name])) ? wp_unslash($_POST[$name]) : [];
                    $allowed = (isset($field['options']) && is_array($field['options'])) ? $field['options'] : [];
                    $val     = array_values(array_intersect(array_map('sanitize_text_field', $posted), $allowed));

                    $values[$name]      = $val;
                    $entry_data[$label] = implode(', ', $val);
                    if (!empty($field['uid'])) $values_by_uid[$field['uid']] = $entry_data[$label];

                    if (!empty($field['required']) && empty($val)) {
                        /* translators: %s: field label */
                        $errors[] = sprintf(__('%s is required.', 'alchemy-forms'), $label);
                    }
                } else {
                    $raw = isset($_POST[$name]) ? wp_unslash($_POST[$name]) : '';
                    if (is_array($raw)) $raw = '';

                    if ($field['type'] === 'textarea') {
                        $val = sanitize_textarea_field($raw);
                    } elseif ($field['type'] === 'email') {
                        $val = sanitize_email($raw);
                    } elseif ($field['type'] === 'url') {
                        $val = esc_url_raw(trim($raw));
                    } elseif ($field['type'] === 'select' || $field['type'] === 'radio') {
                        $val     = sanitize_text_field($raw);
                        $allowed = (isset($field['options']) && is_array($field['options'])) ? $field['options'] : [];
                        if ($val !== '' && !in_array($val, $allowed, true)) $val = '';
                    } else {
                        $val = sanitize_text_field($raw);
                    }

                    $values[$name]      = $val;
                    $entry_data[$label] = $val;
                    if (!empty($field['uid'])) $values_by_uid[$field['uid']] = $val;

                    if (!empty($field['required']) && $val === '') {
                        /* translators: %s: field label */
                        $errors[] = sprintf(__('%s is required.', 'alchemy-forms'), $label);
                    }
                    if ($field['type'] === 'email' && $val !== '' && !is_email($val)) {
                        /* translators: %s: field label */
                        $errors[] = sprintf(__('Please enter a valid email address for %s.', 'alchemy-forms'), $label);
                    }
                    if ($field['type'] === 'number' && $val !== '' && !is_numeric($val)) {
                        /* translators: %s: field label */
                        $errors[] = sprintf(__('Please enter a valid number for %s.', 'alchemy-forms'), $label);
                    }
                    if ($field['type'] === 'date' && $val !== '') {
                        $date_check = DateTime::createFromFormat('Y-m-d', $val);
                        if (!$date_check || $date_check->format('Y-m-d') !== $val) {
                            /* translators: %s: field label */
                            $errors[] = sprintf(__('Please enter a valid date for %s.', 'alchemy-forms'), $label);
                        }
                    }
                }

                if (count($errors) > $errors_before && $first_error_step === null) {
                    $first_error_step = isset($field['_step']) ? $field['_step'] : 0;
                }
            }

            if (empty($errors) && $payment_required) {
                $amount_cents = alchemy_forms_stripe_resolve_amount($payment_config, isset($_POST['wa_payment_amount']) ? wp_unslash($_POST['wa_payment_amount']) : null);
                if ($amount_cents === null) {
                    $errors[] = __('Please enter a valid payment amount.', 'alchemy-forms');
                    foreach ($attachment_ids as $attachment_id) wp_delete_attachment($attachment_id, true);
                } else {
                    $currency = !empty($payment_config['currency']) ? $payment_config['currency'] : 'usd';
                    $token    = wp_generate_password(32, false);
                    set_transient('alchemy_forms_pending_' . $token, [
                        'form_id'          => $form_id,
                        'entry_data'       => $entry_data,
                        'values_by_uid'    => $values_by_uid,
                        'attachment_paths' => $attachment_paths,
                        'attachment_ids'   => $attachment_ids,
                        'amount_cents'     => $amount_cents,
                        'currency'         => $currency,
                    ], DAY_IN_SECONDS);

                    $page_url = isset($_POST['wa_page_url']) ? esc_url_raw(wp_unslash($_POST['wa_page_url'])) : '';
                    if ($page_url === '') $page_url = wp_get_referer();
                    if (!$page_url) $page_url = home_url('/');

                    $email_field    = isset($payment_config['email_field']) ? $payment_config['email_field'] : '';
                    $customer_email = ($email_field !== '' && !empty($values_by_uid[$email_field]) && is_email($values_by_uid[$email_field])) ? $values_by_uid[$email_field] : '';

                    $session = alchemy_forms_stripe_create_checkout_session([
                        'amount_cents'   => $amount_cents,
                        'currency'       => $currency,
                        'description'    => !empty($payment_config['description']) ? $payment_config['description'] : $form->post_title,
                        'token'          => $token,
                        'return_url'     => $page_url,
                        'customer_email' => $customer_email,
                    ]);

                    if ($session && !empty($session['url'])) {
                        alchemy_forms_stripe_pending_redirect($session['url']);
                    } else {
                        delete_transient('alchemy_forms_pending_' . $token);
                        foreach ($attachment_ids as $attachment_id) wp_delete_attachment($attachment_id, true);
                        $errors[] = __('We could not start the payment process — please try again in a moment.', 'alchemy-forms');
                    }
                }
            } elseif (empty($errors)) {
                alchemy_forms_finalize_submission($form_id, $form->post_title, $entry_data, $values_by_uid, $attachment_paths, $settings, $recipient);
                $success = true;
            } elseif ($attachment_ids) {
                // Files were uploaded and attached before a later field failed
                // validation — don't leave any of them orphaned in the Media Library.
                foreach ($attachment_ids as $attachment_id) {
                    wp_delete_attachment($attachment_id, true);
                }
            }
        }
    }

    // A payment-required submission that passed validation doesn't finish
    // here — it needs the visitor's browser to actually navigate to Stripe,
    // which a fetch() response can't do on its own. Render a minimal
    // redirecting placeholder; alchemy_forms_ajax_submit() also reads this
    // same URL back out to send as JSON so the AJAX path can redirect via
    // window.location instead of relying on a <script> tag that a fetch-based
    // HTML swap would never execute.
    $stripe_redirect_url = alchemy_forms_stripe_pending_redirect();
    if ($stripe_redirect_url !== '') {
        ob_start();
        ?>
        <div class="wa-form-wrap" data-ajax-url="<?php echo esc_url(admin_url('admin-ajax.php')); ?>">
            <p><?php esc_html_e('Redirecting you to payment…', 'alchemy-forms'); ?></p>
            <script>window.location.href = <?php echo wp_json_encode($stripe_redirect_url); ?>;</script>
        </div>
        <?php
        return ob_get_clean();
    }

    ob_start();
    ?>
    <div class="wa-form-wrap" style="<?php echo esc_attr($style['inline']); ?>" data-ajax-url="<?php echo esc_url(admin_url('admin-ajax.php')); ?>" data-title="<?php echo esc_attr($atts['title']); ?>" data-embed-post-id="<?php echo (int) get_the_ID(); ?>">
        <?php if ($atts['title'] !== '') : ?>
            <h2 class="wa-form-title"><?php echo esc_html($atts['title']); ?></h2>
        <?php endif; ?>

        <?php if ($success) : ?>
            <div class="wa-form-success" role="status">
                <h3><?php esc_html_e('Thank you', 'alchemy-forms'); ?></h3>
                <p><?php echo esc_html($success_msg); ?></p>
            </div>
        <?php else : ?>
            <form class="wa-form" method="post" enctype="multipart/form-data" novalidate data-initial-step="<?php echo (int) ($first_error_step !== null ? $first_error_step : 0); ?>">
                <input type="hidden" name="wa_form_id" value="<?php echo (int) $form_id; ?>">
                <input type="hidden" name="wa_form_token" value="<?php echo esc_attr(wp_create_nonce('wa_form_submit_' . $form_id)); ?>">
                <div class="wa-form-honeypot" aria-hidden="true">
                    <label><?php esc_html_e('Leave this field blank', 'alchemy-forms'); ?>
                        <input type="text" name="wa_website_hp" tabindex="-1" autocomplete="off">
                    </label>
                </div>

                <?php foreach ($fields as $field) :
                    if ($field['type'] !== 'hidden') continue;
                ?>
                    <input type="hidden" name="<?php echo esc_attr($field['name']); ?>" value="<?php echo esc_attr(alchemy_forms_resolve_hidden_value($field)); ?>">
                <?php endforeach; ?>

                <?php if (!empty($errors)) : ?>
                    <div class="wa-form-errors" role="alert">
                        <ul>
                            <?php foreach ($errors as $error) : ?>
                                <li><?php echo esc_html($error); ?></li>
                            <?php endforeach; ?>
                        </ul>
                    </div>
                <?php endif; ?>

                <?php if ($step_count <= 1) : ?>
                    <div class="wa-form-grid">
                        <?php foreach ((isset($steps[0]) ? $steps[0] : []) as $field) : alchemy_forms_render_field_markup($field, $form_id, $values, $condition_lookup); endforeach; ?>
                    </div>

                    <?php if ($payment_required) : alchemy_forms_render_payment_field($payment_config, $form_id); endif; ?>

                    <div class="wa-form-submit-wrap">
                        <?php if ($turnstile_active) : ?>
                            <div class="cf-turnstile" data-sitekey="<?php echo esc_attr(get_option('alchemy_forms_turnstile_site_key', '')); ?>"></div>
                        <?php endif; ?>
                        <button type="submit" class="wa-form-submit"><?php echo esc_html($submit_text); ?></button>
                    </div>

                <?php else : ?>
                    <div class="wa-form-progress" data-label-template="<?php echo esc_attr(__('Step {n} of {total}', 'alchemy-forms')); ?>">
                        <div class="wa-form-progress-label"></div>
                        <div class="wa-form-progress-bar"><div class="wa-form-progress-fill"></div></div>
                    </div>
                    <?php foreach ($steps as $step_index => $step_fields) : ?>
                        <div class="wa-form-step" data-step="<?php echo (int) $step_index; ?>">
                            <?php if (!empty($step_titles[$step_index])) : ?>
                                <h3 class="wa-form-step-title"><?php echo esc_html($step_titles[$step_index]); ?></h3>
                            <?php endif; ?>
                            <div class="wa-form-grid">
                                <?php foreach ($step_fields as $field) : alchemy_forms_render_field_markup($field, $form_id, $values, $condition_lookup); endforeach; ?>
                            </div>
                            <?php if ($payment_required && $step_index === $step_count - 1) : alchemy_forms_render_payment_field($payment_config, $form_id); endif; ?>
                            <?php if ($turnstile_active && $step_index === $step_count - 1) : ?>
                                <div class="cf-turnstile" data-sitekey="<?php echo esc_attr(get_option('alchemy_forms_turnstile_site_key', '')); ?>"></div>
                            <?php endif; ?>
                            <div class="wa-form-step-nav">
                                <?php if ($step_index > 0) : ?>
                                    <button type="button" class="wa-form-prev"><?php esc_html_e('Back', 'alchemy-forms'); ?></button>
                                <?php endif; ?>
                                <?php if ($step_index < $step_count - 1) : ?>
                                    <button type="button" class="wa-form-next"><?php esc_html_e('Next', 'alchemy-forms'); ?></button>
                                <?php else : ?>
                                    <button type="submit" class="wa-form-submit"><?php echo esc_html($submit_text); ?></button>
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </form>
        <?php endif; ?>
    </div>
    <?php
    return ob_get_clean();
}

/**
 * Renders the auto-injected payment field near the submit button, the same
 * way the Turnstile widget is auto-injected — not part of the field builder,
 * since it's tied to the form-level Payment settings, not an individual
 * field. A fixed-price form shows the amount as read-only text (a hidden
 * field carries the actual value only so the visitor sees what they're
 * about to pay — the server never trusts this value, see
 * alchemy_forms_stripe_resolve_amount()); a variable-price form shows an
 * editable amount input instead.
 */
function alchemy_forms_render_payment_field($payment_config, $form_id) {
    $currency = !empty($payment_config['currency']) ? $payment_config['currency'] : 'usd';
    $label    = !empty($payment_config['description']) ? $payment_config['description'] : __('Amount', 'alchemy-forms');
    $id       = 'wa-' . $form_id . '-payment-amount';
    ?>
    <div class="wa-field wa-field--full wa-payment-field">
        <?php if (($payment_config['amount_type'] ?? 'fixed') === 'variable') : ?>
            <label for="<?php echo esc_attr($id); ?>"><?php echo esc_html($label); ?> <span class="wa-req">*</span></label>
            <input type="number" id="<?php echo esc_attr($id); ?>" name="wa_payment_amount" min="<?php echo esc_attr($payment_config['min_amount'] ?? 0); ?>" step="0.01" value="<?php echo esc_attr($payment_config['default_amount'] ?? ''); ?>" required>
        <?php else : ?>
            <label for="<?php echo esc_attr($id); ?>"><?php echo esc_html($label); ?></label>
            <p id="<?php echo esc_attr($id); ?>" class="wa-payment-fixed-amount"><?php echo esc_html(alchemy_forms_stripe_format_money($payment_config['fixed_amount'] ?? 0, $currency)); ?></p>
            <input type="hidden" name="wa_payment_amount" value="<?php echo esc_attr($payment_config['fixed_amount'] ?? 0); ?>">
        <?php endif; ?>
    </div>
    <?php
}

/**
 * Saves the entry, sends the notification email, and syncs any enabled
 * email-marketing integration — the finish line every successful submission
 * reaches, whether that happens immediately (no payment required) or later,
 * once Stripe confirms payment (see alchemy_forms_stripe_finalize_token()).
 */
function alchemy_forms_finalize_submission($form_id, $form_title, $entry_data, $values_by_uid, $attachment_paths, $settings, $recipient) {
    alchemy_forms_save_entry($form_id, $entry_data);

    $body = sprintf(__("New submission — %s:\n\n", 'alchemy-forms'), $form_title);
    foreach ($entry_data as $label => $val) {
        $body .= $label . ': ' . $val . "\n";
    }
    $entries_url = admin_url('edit.php?post_type=wa_form&page=wa-form-entries&form_id=' . $form_id);
    $body .= "\n" . __('View all entries:', 'alchemy-forms') . ' ' . $entries_url . "\n";

    wp_mail(
        $recipient,
        sprintf(__('New submission: %s', 'alchemy-forms'), $form_title),
        $body,
        [],
        $attachment_paths
    );
    // Entry is stored either way — email failure shouldn't lose the submission.

    if (!empty($settings['integrations']['flodesk']['enabled'])) {
        alchemy_forms_send_to_flodesk($settings['integrations']['flodesk'], $values_by_uid);
    }
    if (!empty($settings['integrations']['aweber']['enabled'])) {
        alchemy_forms_send_to_aweber($settings['integrations']['aweber'], $values_by_uid);
    }
    if (!empty($settings['integrations']['mailchimp']['enabled'])) {
        alchemy_forms_send_to_mailchimp($settings['integrations']['mailchimp'], $values_by_uid);
    }
}

/**
 * Handles a form submission sent via fetch/FormData instead of a normal POST,
 * so the page never navigates away — no full reload, no scroll jump back to
 * the top. Re-invokes alchemy_forms_render_shortcode() with the same $_POST
 * data a native submission would have had, and returns the resulting markup
 * (success message, or the form again with validation errors) as-is.
 *
 * A Hidden field sourced from the embedding page (post_title/post_id/post_url)
 * resolves via get_the_title()/get_the_ID()/get_permalink() with no
 * arguments, which read WordPress's global "current post" — not set in an
 * admin-ajax.php request. wa_embed_post_id (captured client-side from the
 * page the form actually rendered on) restores that context first.
 */
function alchemy_forms_ajax_submit() {
    $embed_post_id = isset($_POST['wa_embed_post_id']) ? (int) $_POST['wa_embed_post_id'] : 0;
    if ($embed_post_id) {
        $embed_post = get_post($embed_post_id);
        if ($embed_post) {
            global $post;
            $post = $embed_post;
            setup_postdata($post);
        }
    }

    $html = alchemy_forms_render_shortcode([
        'id'    => isset($_POST['wa_form_id']) ? (int) $_POST['wa_form_id'] : 0,
        'title' => isset($_POST['wa_form_title']) ? sanitize_text_field(wp_unslash($_POST['wa_form_title'])) : '',
    ]);

    if ($embed_post_id) wp_reset_postdata();

    // A payment-required submission needs a real browser navigation to
    // Stripe, which fetch() can't do — send the URL back explicitly so
    // frontend.js can redirect via window.location instead of trying (and
    // failing) to run a <script> tag from injected HTML.
    $redirect = alchemy_forms_stripe_pending_redirect();
    if ($redirect !== '') {
        wp_send_json_success(['redirect' => $redirect]);
    }

    wp_send_json_success(['html' => $html]);
}
add_action('wp_ajax_alchemy_forms_submit', 'alchemy_forms_ajax_submit');
add_action('wp_ajax_nopriv_alchemy_forms_submit', 'alchemy_forms_ajax_submit');

/**
 * Renders one field's markup within the form grid — label/legend + input,
 * or sanitized content for an html block. Echoes directly (called inside an
 * output buffer). page_break fields are never passed in here; the caller
 * only uses them to decide where one step ends and the next begins.
 */
function alchemy_forms_render_field_markup($field, $form_id, $values, $condition_lookup) {
    $name     = $field['name'];
    $type     = $field['type'];
    $is_group = in_array($type, ['radio', 'checkbox'], true);
    $val      = isset($values[$name]) ? $values[$name] : ($type === 'checkbox' ? [] : '');
    $id       = 'wa-' . $form_id . '-' . $name;
    $req      = !empty($field['required']);
    $hidden_l = !empty($field['hide_label']);
    $wid      = ($field['width'] === 'half') ? 'half' : 'full';
    $options  = (isset($field['options']) && is_array($field['options'])) ? $field['options'] : [];
    // A hint shown only inside the empty field — never a substitute for the
    // <label> above, which is what screen readers and browser autofill use.
    $placeholder_attr = (!empty($field['placeholder'])) ? ' placeholder="' . esc_attr($field['placeholder']) . '"' : '';

    $condition     = isset($field['condition']) ? $field['condition'] : [];
    $has_condition = !empty($condition['field']);
    $cond_visible  = alchemy_forms_evaluate_condition($condition, $condition_lookup);
    $field_classes = 'wa-field wa-field--' . $wid . (!$cond_visible ? ' wa-field--hidden' : '');
    ?>
    <div class="<?php echo esc_attr($field_classes); ?>"
        <?php if (!empty($field['uid'])) : ?>data-field-uid="<?php echo esc_attr($field['uid']); ?>"<?php endif; ?>
        <?php if ($has_condition) : ?>
            data-condition-field="<?php echo esc_attr($condition['field']); ?>"
            data-condition-comparator="<?php echo esc_attr(isset($condition['comparator']) ? $condition['comparator'] : 'equals'); ?>"
            data-condition-value="<?php echo esc_attr(isset($condition['value']) ? $condition['value'] : ''); ?>"
        <?php endif; ?>
    >
        <?php if ($type === 'html') : ?>
            <div class="wa-field-html"><?php echo wp_kses_post(isset($field['content']) ? $field['content'] : ''); ?></div>

        <?php elseif ($type === 'checkbox_single') : ?>
            <label class="wa-choice-option">
                <input type="checkbox" id="<?php echo esc_attr($id); ?>" name="<?php echo esc_attr($name); ?>" value="1" <?php checked($val === '1'); ?> <?php echo $req ? 'required aria-required="true"' : ''; ?>>
                <?php echo esc_html($field['label']); ?>
                <?php if ($req) : ?><span class="wa-req">*</span><?php endif; ?>
            </label>

        <?php elseif ($is_group) : ?>
            <fieldset class="wa-field-group">
                <legend<?php echo $hidden_l ? ' class="wa-visually-hidden"' : ''; ?>>
                    <?php echo esc_html($field['label']); ?>
                    <?php if ($req) : ?><span class="wa-req">*</span><?php endif; ?>
                </legend>
                <?php foreach ($options as $oi => $option) :
                    $opt_id  = $id . '-' . $oi;
                    $checked = ($type === 'checkbox') ? in_array($option, (array) $val, true) : ((string) $val === (string) $option);
                ?>
                    <label class="wa-choice-option">
                        <input type="<?php echo esc_attr($type); ?>" id="<?php echo esc_attr($opt_id); ?>" name="<?php echo esc_attr($type === 'checkbox' ? $name . '[]' : $name); ?>" value="<?php echo esc_attr($option); ?>" <?php checked($checked); ?>>
                        <?php echo esc_html($option); ?>
                    </label>
                <?php endforeach; ?>
            </fieldset>

        <?php else : ?>
            <label for="<?php echo esc_attr($id); ?>"<?php echo $hidden_l ? ' class="wa-visually-hidden"' : ''; ?>>
                <?php echo esc_html($field['label']); ?>
                <?php if ($req) : ?><span class="wa-req">*</span><?php endif; ?>
            </label>

            <?php if ($type === 'textarea') : ?>
                <textarea id="<?php echo esc_attr($id); ?>" name="<?php echo esc_attr($name); ?>" rows="4"<?php echo $placeholder_attr; ?> <?php echo $req ? 'required aria-required="true"' : ''; ?>><?php echo esc_textarea($val); ?></textarea>

            <?php elseif ($type === 'file') : ?>
                <div class="wa-file-input">
                    <input type="file" id="<?php echo esc_attr($id); ?>" name="<?php echo esc_attr($name); ?>" accept=".pdf,.jpg,.jpeg,.png" <?php echo $req ? 'required aria-required="true"' : ''; ?>>
                    <span class="wa-file-hint"><?php esc_html_e('PDF, JPG or PNG, up to 5MB', 'alchemy-forms'); ?></span>
                </div>

            <?php elseif ($type === 'select') : ?>
                <select id="<?php echo esc_attr($id); ?>" name="<?php echo esc_attr($name); ?>" <?php echo $req ? 'required aria-required="true"' : ''; ?>>
                    <option value=""><?php esc_html_e('— Select —', 'alchemy-forms'); ?></option>
                    <?php foreach ($options as $option) : ?>
                        <option value="<?php echo esc_attr($option); ?>" <?php selected((string) $val, (string) $option); ?>><?php echo esc_html($option); ?></option>
                    <?php endforeach; ?>
                </select>

            <?php else : ?>
                <input type="<?php echo esc_attr($type); ?>" id="<?php echo esc_attr($id); ?>" name="<?php echo esc_attr($name); ?>" value="<?php echo esc_attr($val); ?>"<?php echo $placeholder_attr; ?> <?php echo $req ? 'required aria-required="true"' : ''; ?>>
            <?php endif; ?>
        <?php endif; ?>
    </div>
    <?php
}

/**
 * Resolves a Hidden field's value at render time — never shown to or editable
 * by the visitor. get_the_title()/get_the_ID()/get_permalink() are called with
 * no arguments, so they resolve against the current front-end page/post the
 * shortcode is embedded on (the same WordPress global-context dependency
 * Ninja Forms' own {wp:post_title} merge tag relies on).
 */
function alchemy_forms_resolve_hidden_value($field) {
    $source = isset($field['source']) ? $field['source'] : 'static';
    switch ($source) {
        case 'post_title': return get_the_title();
        case 'post_id':    return (string) get_the_ID();
        case 'post_url':   return (string) get_permalink();
        default:           return isset($field['static_value']) ? $field['static_value'] : '';
    }
}

/**
 * Handle a single uploaded file. Returns ['url' => ..., 'path' => ...] or null.
 * Appends to $errors by reference on failure.
 */
function alchemy_forms_handle_upload($input_name, $required, $label, array &$errors) {
    if (empty($_FILES[$input_name]['name'])) {
        if ($required) {
            /* translators: %s: field label */
            $errors[] = sprintf(__('%s is required.', 'alchemy-forms'), $label);
        }
        return null;
    }

    $file      = $_FILES[$input_name];
    $allowed   = ['image/jpeg', 'image/png', 'application/pdf'];
    $max_bytes = 5 * 1024 * 1024;

    if ($file['error'] !== UPLOAD_ERR_OK) {
        $errors[] = __('There was a problem uploading your file — please try again.', 'alchemy-forms');
        return null;
    }
    if ($file['size'] > $max_bytes) {
        $errors[] = __('That file is too large — please keep it under 5MB.', 'alchemy-forms');
        return null;
    }
    // Check the real type, not just what the browser claims.
    $check = wp_check_filetype_and_ext($file['tmp_name'], $file['name']);
    if (empty($check['type']) || !in_array($check['type'], $allowed, true)) {
        $errors[] = __('Please upload a PDF, JPG or PNG file.', 'alchemy-forms');
        return null;
    }

    require_once ABSPATH . 'wp-admin/includes/file.php';
    require_once ABSPATH . 'wp-admin/includes/image.php';
    require_once ABSPATH . 'wp-admin/includes/media.php';

    $upload = wp_handle_upload($file, ['test_form' => false]);
    if (isset($upload['error'])) {
        $errors[] = __('Upload failed:', 'alchemy-forms') . ' ' . $upload['error'];
        return null;
    }

    $attach_id = wp_insert_attachment([
        'post_mime_type' => $upload['type'],
        'post_title'     => sanitize_file_name(basename($upload['file'])),
        'post_status'    => 'inherit',
    ], $upload['file']);

    $url = $upload['url'];
    $id  = 0;
    if (!is_wp_error($attach_id) && $attach_id) {
        wp_update_attachment_metadata($attach_id, wp_generate_attachment_metadata($attach_id, $upload['file']));
        $url = wp_get_attachment_url($attach_id) ?: $url;
        $id  = $attach_id;
    }

    return ['url' => $url, 'path' => $upload['file'], 'id' => $id];
}

/* -------------------------------------------------------------------------
 * Per-form style resolution
 * ---------------------------------------------------------------------- */
function alchemy_forms_resolve_style($style_settings) {
    if (!is_array($style_settings)) $style_settings = [];
    $d            = alchemy_forms_style_defaults();
    $google_fonts = alchemy_forms_google_fonts();
    $weight_opts  = alchemy_forms_font_weight_options();

    // A form saved before the 2.4.0 per-component redesign only has the old
    // global keys (primary/accent/border/muted color, heading/body font —
    // themselves possibly migrated further back from the original "Font
    // pairing" preset). Resolve those once into a patched defaults array so
    // every per-component field below falls back to its pre-2.4.0 equivalent
    // and an unedited form keeps rendering the same until it's next saved.
    $legacy_font_key = !empty($style_settings['font']) ? $style_settings['font'] : null;
    $legacy_preset   = alchemy_forms_legacy_font_migration($legacy_font_key ?: 'default');
    $legacy_heading_font   = $legacy_font_key ? $legacy_preset['heading_font'] : $d['heading_font'];
    $legacy_heading_weight = $legacy_font_key ? $legacy_preset['heading_weight'] : $d['heading_weight'];
    $legacy_body_font      = $legacy_font_key ? $legacy_preset['body_font'] : $d['body_font'];
    $legacy_body_weight    = $legacy_font_key ? $legacy_preset['body_weight'] : $d['body_weight'];
    if (isset($style_settings['heading_font'])) $legacy_heading_font = $style_settings['heading_font'];
    if (isset($style_settings['heading_weight'])) $legacy_heading_weight = (int) $style_settings['heading_weight'];
    if (isset($style_settings['body_font'])) $legacy_body_font = $style_settings['body_font'];
    if (isset($style_settings['body_weight'])) $legacy_body_weight = (int) $style_settings['body_weight'];

    $ld = $d;
    $ld['title_font'] = $ld['step_font'] = $ld['success_heading_font'] = $legacy_heading_font;
    $ld['title_weight'] = $ld['step_weight'] = $ld['success_heading_weight'] = $legacy_heading_weight;
    $ld['label_font'] = $ld['input_font'] = $ld['button_font'] = $ld['success_text_font'] = $legacy_body_font;
    $ld['label_weight'] = $ld['input_weight'] = $ld['button_weight'] = $ld['success_text_weight'] = $legacy_body_weight;
    // The pre-2.4.0 design always computed the title/success-heading color as
    // the legacy Primary color darkened by 22% — never stored separately.
    $legacy_primary_dark = alchemy_forms_darken_hex(alchemy_forms_resolve_style_color($style_settings, 'primary_color', $d), 0.22);
    $ld['title_color'] = $ld['success_heading_color'] = $legacy_primary_dark;

    $title_color     = alchemy_forms_resolve_style_color($style_settings, 'title_color', $ld);
    $title_font_key  = alchemy_forms_resolve_style_font($style_settings, 'title_font', $ld, $google_fonts);
    $title_weight    = alchemy_forms_resolve_style_weight($style_settings, 'title_weight', $ld, $weight_opts);
    $title_font_size = alchemy_forms_resolve_style_px($style_settings, 'title_font_size', $d);

    $label_color     = alchemy_forms_resolve_style_color($style_settings, 'label_color', $d);
    $label_font_key  = alchemy_forms_resolve_style_font($style_settings, 'label_font', $ld, $google_fonts);
    $label_weight    = alchemy_forms_resolve_style_weight($style_settings, 'label_weight', $ld, $weight_opts);
    $label_font_size = alchemy_forms_resolve_style_px($style_settings, 'label_font_size', $d);
    $label_required  = alchemy_forms_resolve_style_color($style_settings, 'label_required_color', $d, 'accent_color');

    $input_border    = alchemy_forms_resolve_style_color($style_settings, 'input_border_color', $d, 'border_color');
    $input_bg        = alchemy_forms_resolve_style_color($style_settings, 'input_bg_color', $d);
    $input_text      = alchemy_forms_resolve_style_color($style_settings, 'input_text_color', $d);
    $input_focus     = alchemy_forms_resolve_style_color($style_settings, 'input_focus_color', $d, 'primary_color');
    [$ifr, $ifg, $ifb] = alchemy_forms_hex_to_rgb($input_focus);
    $input_focus_shadow = sprintf('rgba(%d, %d, %d, 0.15)', $ifr, $ifg, $ifb);
    $input_font_key  = alchemy_forms_resolve_style_font($style_settings, 'input_font', $ld, $google_fonts);
    $input_weight    = alchemy_forms_resolve_style_weight($style_settings, 'input_weight', $ld, $weight_opts);
    $input_font_size = alchemy_forms_resolve_style_px($style_settings, 'input_font_size', $d);
    $input_padding   = alchemy_forms_resolve_style_px($style_settings, 'input_padding', $d);
    $field_gap       = alchemy_forms_resolve_style_px($style_settings, 'field_gap', $d);
    $input_hint      = alchemy_forms_resolve_style_color($style_settings, 'input_hint_color', $d, 'muted_color');

    $placeholder_style_opts = alchemy_forms_placeholder_font_style_options();
    $placeholder_font_opts  = alchemy_forms_placeholder_font_options();
    $placeholder_color      = alchemy_forms_resolve_style_color($style_settings, 'placeholder_color', $d);
    $placeholder_font_key   = alchemy_forms_resolve_style_choice($style_settings, 'placeholder_font', $d, array_keys($placeholder_font_opts));
    $placeholder_weight     = alchemy_forms_resolve_style_weight($style_settings, 'placeholder_weight', $d, $weight_opts);
    $placeholder_font_size  = alchemy_forms_resolve_style_px($style_settings, 'placeholder_font_size', $d);
    $placeholder_font_style = alchemy_forms_resolve_style_choice($style_settings, 'placeholder_font_style', $d, array_keys($placeholder_style_opts));
    // "inherit" (the default) matches whatever the Inputs tab's own font is;
    // anything else loads a second Google Fonts family for placeholder text only.
    if ($placeholder_font_key === 'inherit' || !isset($google_fonts[$placeholder_font_key])) {
        $placeholder_font_key = $input_font_key;
    }

    $button_bg        = alchemy_forms_resolve_style_color($style_settings, 'button_bg_color', $d);
    $button_hover      = alchemy_forms_resolve_style_color($style_settings, 'button_hover_color', $d);
    $button_text       = alchemy_forms_resolve_style_color($style_settings, 'button_text_color', $d);
    [$btr, $btg, $btb] = alchemy_forms_hex_to_rgb($button_text);
    $button_text_dim   = sprintf('rgba(%d, %d, %d, 0.4)', $btr, $btg, $btb);
    $button_font_key   = alchemy_forms_resolve_style_font($style_settings, 'button_font', $ld, $google_fonts);
    $button_weight     = alchemy_forms_resolve_style_weight($style_settings, 'button_weight', $ld, $weight_opts);
    $button_font_size  = alchemy_forms_resolve_style_px($style_settings, 'button_font_size', $d);
    $button_padding    = alchemy_forms_resolve_style_px($style_settings, 'button_padding', $d);
    $button_width_opts = alchemy_forms_button_width_options();
    $button_align_opts = alchemy_forms_button_align_options();
    $button_width      = alchemy_forms_resolve_style_choice($style_settings, 'button_width', $d, array_keys($button_width_opts));
    $button_align      = alchemy_forms_resolve_style_choice($style_settings, 'button_align', $d, array_keys($button_align_opts));
    $button_spacing    = alchemy_forms_resolve_style_px($style_settings, 'button_spacing', $d, 0, 200);

    $step_color       = alchemy_forms_resolve_style_color($style_settings, 'step_color', $d);
    $step_font_key    = alchemy_forms_resolve_style_font($style_settings, 'step_font', $ld, $google_fonts);
    $step_weight      = alchemy_forms_resolve_style_weight($style_settings, 'step_weight', $ld, $weight_opts);
    $step_font_size   = alchemy_forms_resolve_style_px($style_settings, 'step_font_size', $d);
    $step_label_color = alchemy_forms_resolve_style_color($style_settings, 'step_label_color', $d, 'muted_color');

    $radius             = alchemy_forms_resolve_style_px($style_settings, 'radius', $d);
    $container_bg       = alchemy_forms_resolve_style_color($style_settings, 'container_bg_color', $d);
    $container_opacity  = alchemy_forms_resolve_style_px($style_settings, 'container_bg_opacity', $d, 0, 100);
    $container_border   = alchemy_forms_resolve_style_color($style_settings, 'container_border_color', $d, 'border_color');
    $container_border_width = alchemy_forms_resolve_style_px($style_settings, 'container_border_width', $d, 0, 50);
    $container_padding  = alchemy_forms_resolve_style_px($style_settings, 'container_padding', $d);

    [$cr, $cg, $cb] = alchemy_forms_hex_to_rgb($container_bg);
    $container_rgba = sprintf('rgba(%d, %d, %d, %s)', $cr, $cg, $cb, $container_opacity / 100);

    $shadow_enabled = isset($style_settings['shadow_enabled']) ? !empty($style_settings['shadow_enabled']) : !empty($d['shadow_enabled']);
    $shadow_color   = alchemy_forms_resolve_style_color($style_settings, 'shadow_color', $d);
    $shadow_opacity = alchemy_forms_resolve_style_px($style_settings, 'shadow_opacity', $d, 0, 100);
    $shadow_blur    = alchemy_forms_resolve_style_px($style_settings, 'shadow_blur', $d, 0, 100);
    if ($shadow_enabled) {
        [$sr, $sg, $sb] = alchemy_forms_hex_to_rgb($shadow_color);
        $shadow_css = sprintf('0 8px %dpx rgba(%d, %d, %d, %s)', $shadow_blur, $sr, $sg, $sb, $shadow_opacity / 100);
    } else {
        $shadow_css = 'none';
    }

    $success_heading_color     = alchemy_forms_resolve_style_color($style_settings, 'success_heading_color', $ld);
    $success_heading_font_key  = alchemy_forms_resolve_style_font($style_settings, 'success_heading_font', $ld, $google_fonts);
    $success_heading_weight    = alchemy_forms_resolve_style_weight($style_settings, 'success_heading_weight', $ld, $weight_opts);
    $success_heading_font_size = alchemy_forms_resolve_style_px($style_settings, 'success_heading_font_size', $d);
    $success_text_color        = alchemy_forms_resolve_style_color($style_settings, 'success_text_color', $d, 'muted_color');
    $success_text_font_key     = alchemy_forms_resolve_style_font($style_settings, 'success_text_font', $ld, $google_fonts);
    $success_text_weight       = alchemy_forms_resolve_style_weight($style_settings, 'success_text_weight', $ld, $weight_opts);
    $success_text_font_size    = alchemy_forms_resolve_style_px($style_settings, 'success_text_font_size', $d);

    $vars = [
        '--wa-title-color'      => $title_color,
        '--wa-title-font'       => $google_fonts[$title_font_key]['family'],
        '--wa-title-weight'     => $title_weight,
        '--wa-title-size'       => $title_font_size . 'px',

        '--wa-label-color'          => $label_color,
        '--wa-label-font'           => $google_fonts[$label_font_key]['family'],
        '--wa-label-weight'         => $label_weight,
        '--wa-label-font-size'      => $label_font_size . 'px',
        '--wa-label-required-color' => $label_required,

        '--wa-input-border'  => $input_border,
        '--wa-input-bg'      => $input_bg,
        '--wa-input-text'    => $input_text,
        '--wa-input-focus'   => $input_focus,
        '--wa-input-focus-dark' => alchemy_forms_darken_hex($input_focus, 0.22),
        '--wa-input-focus-shadow' => $input_focus_shadow,
        '--wa-input-font'    => $google_fonts[$input_font_key]['family'],
        '--wa-input-weight'  => $input_weight,
        '--wa-input-size'    => $input_font_size . 'px',
        '--wa-input-padding' => $input_padding . 'px',
        '--wa-field-gap'     => $field_gap . 'px',
        '--wa-input-hint'    => $input_hint,

        '--wa-placeholder'             => $placeholder_color,
        '--wa-placeholder-font'        => $google_fonts[$placeholder_font_key]['family'],
        '--wa-placeholder-weight'      => $placeholder_weight,
        '--wa-placeholder-size'        => $placeholder_font_size . 'px',
        '--wa-placeholder-font-style'  => $placeholder_font_style,

        '--wa-button-bg'        => $button_bg,
        '--wa-button-bg-hover'  => $button_hover,
        '--wa-button-text'      => $button_text,
        '--wa-button-text-dim'  => $button_text_dim,
        '--wa-button-font'      => $google_fonts[$button_font_key]['family'],
        '--wa-button-weight'    => $button_weight,
        '--wa-button-font-size' => $button_font_size . 'px',
        '--wa-button-padding'   => $button_padding . 'px',
        '--wa-button-width'     => ($button_width === 'full') ? '100%' : 'auto',
        '--wa-button-align'     => $button_align,
        '--wa-button-spacing'   => $button_spacing . 'px',

        '--wa-step-color'       => $step_color,
        '--wa-step-font'        => $google_fonts[$step_font_key]['family'],
        '--wa-step-weight'      => $step_weight,
        '--wa-step-size'        => $step_font_size . 'px',
        '--wa-step-label-color' => $step_label_color,

        '--wa-radius'                 => $radius . 'px',
        '--wa-container-bg'           => $container_rgba,
        '--wa-container-border'       => $container_border,
        '--wa-container-border-width' => $container_border_width . 'px',
        '--wa-container-padding'      => $container_padding . 'px',
        '--wa-shadow'                 => $shadow_css,

        '--wa-success-heading-color' => $success_heading_color,
        '--wa-success-heading-font'  => $google_fonts[$success_heading_font_key]['family'],
        '--wa-success-heading-weight' => $success_heading_weight,
        '--wa-success-heading-size'  => $success_heading_font_size . 'px',
        '--wa-success-text-color'   => $success_text_color,
        '--wa-success-text-font'    => $google_fonts[$success_text_font_key]['family'],
        '--wa-success-text-weight'  => $success_text_weight,
        '--wa-success-text-size'    => $success_text_font_size . 'px',
    ];

    $inline = '';
    foreach ($vars as $prop => $value) {
        $inline .= $prop . ': ' . $value . '; ';
    }

    return [
        'inline' => trim($inline),
        'fonts'  => [
            ['key' => $title_font_key, 'font' => $google_fonts[$title_font_key], 'weight' => $title_weight],
            ['key' => $label_font_key, 'font' => $google_fonts[$label_font_key], 'weight' => $label_weight],
            ['key' => $input_font_key, 'font' => $google_fonts[$input_font_key], 'weight' => $input_weight],
            ['key' => $placeholder_font_key, 'font' => $google_fonts[$placeholder_font_key], 'weight' => $placeholder_weight],
            ['key' => $button_font_key, 'font' => $google_fonts[$button_font_key], 'weight' => $button_weight],
            ['key' => $step_font_key, 'font' => $google_fonts[$step_font_key], 'weight' => $step_weight],
            ['key' => $success_heading_font_key, 'font' => $google_fonts[$success_heading_font_key], 'weight' => $success_heading_weight],
            ['key' => $success_text_font_key, 'font' => $google_fonts[$success_text_font_key], 'weight' => $success_text_weight],
        ],
    ];
}

/* -------------------------------------------------------------------------
 * Frontend CSS (only loads on pages that render a form)
 * ---------------------------------------------------------------------- */
function alchemy_forms_enqueue_frontend_css($fonts = []) {
    static $css_done = false;
    if (!$css_done) {
        $css_done = true;
        wp_register_style('alchemy-forms-frontend', false, [], ALCHEMY_FORMS_VERSION);
        wp_enqueue_style('alchemy-forms-frontend');
        wp_add_inline_style('alchemy-forms-frontend', alchemy_forms_frontend_css());
        wp_enqueue_script('alchemy-forms-frontend', ALCHEMY_FORMS_URL . 'assets/frontend.js', [], ALCHEMY_FORMS_VERSION, true);
    }

    // Heading/Body/Placeholder may independently pick the same family (or a
    // "System" pseudo-font that needs no web font at all) — combine every
    // family+weight actually needed into one Google Fonts request instead of
    // one request per role.
    $families = [];
    foreach ((array) $fonts as $f) {
        if (empty($f['font']['google'])) continue;
        $families[$f['key']][(int) $f['weight']] = true;
    }
    if (!$families) return;

    $parts = [];
    foreach ($families as $family => $weights) {
        $parts[] = 'family=' . str_replace(' ', '+', $family) . ':wght@' . implode(';', array_keys($weights));
    }
    $url = 'https://fonts.googleapis.com/css2?' . implode('&', $parts) . '&display=swap';
    wp_enqueue_style('alchemy-forms-fonts-' . substr(md5($url), 0, 8), $url, [], null);
}

function alchemy_forms_frontend_css() {
    return <<<CSS
.wa-form-wrap {
  --wa-title-color: #22392B;
  --wa-title-font: 'Fraunces', Georgia, serif;
  --wa-title-weight: 600;
  --wa-title-size: 28px;
  --wa-label-color: #1F2A23;
  --wa-label-font: 'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif;
  --wa-label-weight: 500;
  --wa-label-font-size: 14px;
  --wa-label-required-color: #C9A227;
  --wa-input-border: #DCE3D9;
  --wa-input-bg: #F6F8F3;
  --wa-input-text: #1F2A23;
  --wa-input-focus: #2F4F3E;
  --wa-input-focus-dark: #22392B;
  --wa-input-focus-shadow: rgba(47,79,62,0.15);
  --wa-input-font: 'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif;
  --wa-input-weight: 400;
  --wa-input-size: 15px;
  --wa-input-padding: 10px;
  --wa-field-gap: 20px;
  --wa-input-hint: #5B6B60;
  --wa-placeholder: #5B6B60;
  --wa-placeholder-font: 'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif;
  --wa-placeholder-weight: 400;
  --wa-placeholder-size: 15px;
  --wa-placeholder-font-style: normal;
  --wa-button-bg: #2F4F3E;
  --wa-button-bg-hover: #22392B;
  --wa-button-text: #FFFFFF;
  --wa-button-text-dim: rgba(255,255,255,0.4);
  --wa-button-font: 'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif;
  --wa-button-weight: 600;
  --wa-button-font-size: 15px;
  --wa-button-padding: 13px;
  --wa-button-width: auto;
  --wa-button-align: left;
  --wa-button-spacing: 28px;
  --wa-step-color: #2F4F3E;
  --wa-step-font: 'Fraunces', Georgia, serif;
  --wa-step-weight: 600;
  --wa-step-size: 20px;
  --wa-step-label-color: #5B6B60;
  --wa-radius: 10px;
  --wa-container-bg: #FFFFFF;
  --wa-container-border: #DCE3D9;
  --wa-container-border-width: 1px;
  --wa-container-padding: 40px;
  --wa-shadow: 0 8px 24px rgba(31,42,35,0.06);
  --wa-success-heading-color: #22392B;
  --wa-success-heading-font: 'Fraunces', Georgia, serif;
  --wa-success-heading-weight: 600;
  --wa-success-heading-size: 24px;
  --wa-success-text-color: #5B6B60;
  --wa-success-text-font: 'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif;
  --wa-success-text-weight: 400;
  --wa-success-text-size: 15px;
  max-width: 720px;
  margin: 0 auto;
  font-family: var(--wa-input-font);
  color: var(--wa-input-text);
  box-sizing: border-box;
}
.wa-form-wrap *, .wa-form-wrap *::before, .wa-form-wrap *::after { box-sizing: inherit; }
.wa-form-title { font-family: var(--wa-title-font); font-weight: var(--wa-title-weight); font-size: var(--wa-title-size); color: var(--wa-title-color); margin: 0 0 1.25rem; }
.wa-form { background: var(--wa-container-bg); border: var(--wa-container-border-width) solid var(--wa-container-border); border-radius: calc(var(--wa-radius) + 6px); padding: var(--wa-container-padding); box-shadow: var(--wa-shadow); position: relative; }
.wa-form-grid { display: grid; grid-template-columns: repeat(2, 1fr); gap: var(--wa-field-gap); }
.wa-field--half { grid-column: span 1; }
.wa-field--full { grid-column: 1 / -1; }
.wa-field--hidden { display: none; }
@media (max-width: 560px) {
  .wa-form-grid { grid-template-columns: 1fr; }
  .wa-field--half { grid-column: 1 / -1; }
  .wa-form { padding: min(var(--wa-container-padding), 1.75rem) min(var(--wa-container-padding), 1.5rem); }
}
.wa-field label { display: block; font-family: var(--wa-label-font); font-weight: var(--wa-label-weight); font-size: var(--wa-label-font-size); color: var(--wa-label-color); margin-bottom: 0.4rem; }
.wa-req { color: var(--wa-label-required-color); margin-left: 2px; }
.wa-visually-hidden { position: absolute; width: 1px; height: 1px; padding: 0; margin: -1px; overflow: hidden; clip: rect(0,0,0,0); white-space: nowrap; border: 0; }
.wa-field input[type=text], .wa-field input[type=email], .wa-field input[type=tel], .wa-field input[type=url], .wa-field input[type=date], .wa-field input[type=number], .wa-field select, .wa-field textarea {
  width: 100%; font-family: var(--wa-input-font); font-weight: var(--wa-input-weight); font-size: var(--wa-input-size); padding: var(--wa-input-padding);
  border: 1px solid var(--wa-input-border); border-radius: var(--wa-radius); background: var(--wa-input-bg); color: var(--wa-input-text);
  transition: border-color 0.15s ease, box-shadow 0.15s ease, background 0.15s ease;
}
.wa-field textarea { resize: vertical; min-height: 5.5rem; }
.wa-field input::placeholder, .wa-field textarea::placeholder { color: var(--wa-placeholder); opacity: 1; font-style: var(--wa-placeholder-font-style); font-family: var(--wa-placeholder-font); font-weight: var(--wa-placeholder-weight); font-size: var(--wa-placeholder-size); }
.wa-field input:focus, .wa-field select:focus, .wa-field textarea:focus { outline: none; border-color: var(--wa-input-focus); box-shadow: 0 0 0 3px var(--wa-input-focus-shadow); }
.wa-field input:focus-visible, .wa-field select:focus-visible, .wa-field textarea:focus-visible { outline: 2px solid var(--wa-input-focus); outline-offset: 1px; }
.wa-field-group { border: none; margin: 0; padding: 0; }
.wa-field-group legend { display: block; width: 100%; font-family: var(--wa-label-font); font-weight: var(--wa-label-weight); font-size: var(--wa-label-font-size); color: var(--wa-label-color); margin-bottom: 0.5rem; padding: 0; }
.wa-choice-option { display: flex; align-items: center; gap: 0.5rem; font-family: var(--wa-input-font); font-weight: 400; font-size: 0.92rem; color: var(--wa-input-text); margin-bottom: 0.5rem; cursor: pointer; }
.wa-choice-option:last-child { margin-bottom: 0; }
.wa-choice-option input[type=radio], .wa-choice-option input[type=checkbox] { width: auto; margin: 0; accent-color: var(--wa-input-focus); }
.wa-file-input { border: 1.5px dashed var(--wa-input-border); border-radius: var(--wa-radius); padding: 1rem; background: var(--wa-input-bg); display: flex; flex-direction: column; gap: 0.4rem; }
.wa-file-input input[type=file] { font-family: var(--wa-input-font); font-size: 0.88rem; color: var(--wa-input-hint); }
.wa-file-input input[type=file]::file-selector-button {
  font-family: var(--wa-input-font); font-weight: 500; font-size: 0.85rem; color: #fff; background: var(--wa-input-focus);
  border: none; border-radius: 6px; padding: 0.5rem 0.9rem; margin-right: 0.75rem; cursor: pointer; transition: background 0.15s ease;
}
.wa-file-input input[type=file]::file-selector-button:hover { background: var(--wa-input-focus-dark); }
.wa-file-hint { font-size: 0.78rem; color: var(--wa-input-hint); }
.wa-payment-field { margin-top: var(--wa-field-gap); }
.wa-payment-fixed-amount { margin: 0; padding: var(--wa-input-padding); font-size: 1.05rem; font-weight: 600; font-family: var(--wa-input-font); color: var(--wa-input-text); background: var(--wa-input-bg); border: 1px solid var(--wa-input-border); border-radius: var(--wa-radius); }
.wa-form-submit {
  font-family: var(--wa-button-font); font-weight: var(--wa-button-weight); font-size: var(--wa-button-font-size); color: var(--wa-button-text);
  background: var(--wa-button-bg); border: none; border-radius: var(--wa-radius); padding: var(--wa-button-padding); cursor: pointer;
  transition: background 0.15s ease, transform 0.1s ease;
}
.wa-form-submit:hover { background: var(--wa-button-bg-hover); }
.wa-form-submit:active { transform: translateY(1px); }
/* Width/alignment only apply to the single-step submit button — the
   multi-step nav row (Back/Next/Submit side by side) keeps its own sizing. */
.wa-form-submit-wrap { margin-top: var(--wa-button-spacing); text-align: var(--wa-button-align); }
.wa-form-submit-wrap .wa-form-submit { width: var(--wa-button-width); }
.wa-form-submit:focus-visible { outline: 2px solid var(--wa-button-bg-hover); outline-offset: 2px; }
.wa-form-submit--loading { color: transparent !important; pointer-events: none; position: relative; }
.wa-form-submit--loading::after {
  content: ''; position: absolute; top: 50%; left: 50%; width: 16px; height: 16px; margin: -8px 0 0 -8px;
  border: 2px solid var(--wa-button-text-dim); border-top-color: var(--wa-button-text); border-radius: 50%;
  animation: wa-form-spin 0.6s linear infinite;
}
@keyframes wa-form-spin { to { transform: rotate(360deg); } }
.wa-form-honeypot { position: absolute; left: -9999px; width: 1px; height: 1px; overflow: hidden; }
.wa-form-errors { background: #FBEBEA; border: 1px solid #B3261E; border-radius: var(--wa-radius); padding: 0.9rem 1.1rem; margin-bottom: 1.5rem; }
.wa-form-errors ul { margin: 0; padding-left: 1.1rem; }
.wa-form-errors li { color: #B3261E; font-size: 0.88rem; }
.cf-turnstile { margin-bottom: 1rem; }
.wa-form-success { background: var(--wa-container-bg); border: 1px solid var(--wa-container-border); border-left: 4px solid var(--wa-success-heading-color); border-radius: var(--wa-radius); padding: 2rem; }
.wa-form-success h3 { font-family: var(--wa-success-heading-font); font-size: var(--wa-success-heading-size); font-weight: var(--wa-success-heading-weight); margin: 0 0 0.5rem; color: var(--wa-success-heading-color); }
.wa-form-success p { margin: 0; font-family: var(--wa-success-text-font); font-weight: var(--wa-success-text-weight); font-size: var(--wa-success-text-size); color: var(--wa-success-text-color); }
.wa-field-html { font-size: 0.95rem; line-height: 1.6; }
.wa-field-html img, .wa-field-html video { max-width: 100%; height: auto; }
.wa-field-html p:first-child { margin-top: 0; }
.wa-field-html p:last-child { margin-bottom: 0; }
.wa-form-progress { margin-bottom: 1.5rem; }
.wa-form-progress-label { font-size: 0.82rem; color: var(--wa-step-label-color); margin-bottom: 0.4rem; }
.wa-form-progress-bar { background: var(--wa-input-border); border-radius: 999px; height: 6px; overflow: hidden; }
.wa-form-progress-fill { background: var(--wa-step-color); height: 100%; width: 0; transition: width 0.25s ease; }
.wa-form-step { display: none; }
.wa-form-step.wa-form-step--active { display: block; }
.wa-form-step-title { font-family: var(--wa-step-font); font-weight: var(--wa-step-weight); font-size: var(--wa-step-size); color: var(--wa-step-color); margin: 0 0 1.25rem; }
.wa-form-step-nav { display: flex; align-items: center; justify-content: flex-end; gap: 0.75rem; margin-top: var(--wa-button-spacing); }
.wa-form-step-nav .wa-form-prev,
.wa-form-step-nav .wa-form-next {
  font-family: var(--wa-button-font); font-weight: var(--wa-button-weight); font-size: var(--wa-button-font-size); color: var(--wa-button-text);
  background: var(--wa-button-bg); border: none; border-radius: var(--wa-radius); padding: var(--wa-button-padding); cursor: pointer;
  transition: background 0.15s ease, transform 0.1s ease;
}
.wa-form-step-nav .wa-form-prev { margin-right: auto; }
.wa-form-step-nav .wa-form-prev:hover,
.wa-form-step-nav .wa-form-next:hover { background: var(--wa-button-bg-hover); }
@media (prefers-reduced-motion: reduce) {
  .wa-form-submit, .wa-form-next, .wa-form-prev, .wa-field input, .wa-field select, .wa-field textarea, .wa-form-progress-fill { transition: none; }
  .wa-form-submit--loading::after { animation-duration: 1.5s; }
}
CSS;
}
