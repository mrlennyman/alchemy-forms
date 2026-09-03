(function () {
    /**
     * Cloudflare's Turnstile script is loaded with render=explicit, so its own
     * auto-render scan never runs — needed because a widget can appear after
     * the script already loaded (an AJAX-swapped form re-render), which that
     * one-time scan would miss. window.alchemyFormsOnTurnstileLoad is the
     * script's onload callback (see wp_enqueue_script() in render.php); this
     * same render pass also runs from init() on every fresh/replaced form.
     */
    function renderTurnstileWidgets(root) {
        if (!window.turnstile) return;
        (root || document).querySelectorAll('.cf-turnstile:not([data-rendered])').forEach(function (el) {
            el.setAttribute('data-rendered', '1');
            window.turnstile.render(el, { sitekey: el.getAttribute('data-sitekey') });
        });
    }
    window.alchemyFormsOnTurnstileLoad = function () {
        renderTurnstileWidgets(document);
    };

    function getFieldValue(wrapper) {
        var checkable = wrapper.querySelectorAll('input[type=radio], input[type=checkbox]');
        if (checkable.length) {
            for (var i = 0; i < checkable.length; i++) {
                if (checkable[i].checked) return checkable[i].value;
            }
            return '';
        }
        var control = wrapper.querySelector('input, select, textarea');
        return control ? control.value : '';
    }

    function evaluate(comparator, actual, expected) {
        if (comparator === 'not_equals') return actual !== expected;
        return actual === expected;
    }

    function setRequired(wrapper, shouldBeRequired) {
        wrapper.querySelectorAll('input, select, textarea').forEach(function (el) {
            if (shouldBeRequired) {
                if (el.dataset.waWasRequired === '1') {
                    el.required = true;
                    el.setAttribute('aria-required', 'true');
                }
            } else {
                if (el.required) el.dataset.waWasRequired = '1';
                el.required = false;
                el.removeAttribute('aria-required');
            }
        });
    }

    function refresh(form) {
        var fieldsByUid = {};
        form.querySelectorAll('.wa-field[data-field-uid]').forEach(function (el) {
            fieldsByUid[el.getAttribute('data-field-uid')] = el;
        });

        form.querySelectorAll('.wa-field[data-condition-field]').forEach(function (dependent) {
            var trigger = fieldsByUid[dependent.getAttribute('data-condition-field')];
            if (!trigger) return;

            var actual      = getFieldValue(trigger);
            var expected    = dependent.getAttribute('data-condition-value') || '';
            var comparator  = dependent.getAttribute('data-condition-comparator') || 'equals';
            var visible     = evaluate(comparator, actual, expected);

            dependent.classList.toggle('wa-field--hidden', !visible);
            setRequired(dependent, visible);
        });
    }

    function initSteps(form) {
        var steps = Array.prototype.slice.call(form.querySelectorAll('.wa-form-step'));
        if (steps.length < 2) return;

        var progress      = form.querySelector('.wa-form-progress');
        var progressFill  = form.querySelector('.wa-form-progress-fill');
        var progressLabel = form.querySelector('.wa-form-progress-label');
        var labelTemplate = (progress && progress.getAttribute('data-label-template')) || 'Step {n} of {total}';

        function currentIndex() {
            for (var i = 0; i < steps.length; i++) {
                if (steps[i].classList.contains('wa-form-step--active')) return i;
            }
            return 0;
        }

        function showStep(index, scroll) {
            steps.forEach(function (step, i) {
                step.classList.toggle('wa-form-step--active', i === index);
            });
            if (progressFill) progressFill.style.width = Math.round(((index + 1) / steps.length) * 100) + '%';
            if (progressLabel) {
                progressLabel.textContent = labelTemplate.replace('{n}', index + 1).replace('{total}', steps.length);
            }
            if (scroll) form.scrollIntoView({ behavior: 'smooth', block: 'start' });
        }

        function validateStep(step) {
            var valid = true;
            step.querySelectorAll('input, select, textarea').forEach(function (el) {
                if (el.disabled || el.closest('.wa-field--hidden')) return;
                if (!el.checkValidity()) {
                    el.reportValidity();
                    valid = false;
                }
            });
            return valid;
        }

        form.querySelectorAll('.wa-form-next').forEach(function (btn) {
            btn.addEventListener('click', function () {
                var i = currentIndex();
                if (!validateStep(steps[i])) return;
                if (i < steps.length - 1) showStep(i + 1, true);
            });
        });

        form.querySelectorAll('.wa-form-prev').forEach(function (btn) {
            btn.addEventListener('click', function () {
                var i = currentIndex();
                if (i > 0) showStep(i - 1, true);
            });
        });

        var initial = parseInt(form.getAttribute('data-initial-step') || '0', 10);
        if (isNaN(initial) || initial < 0 || initial >= steps.length) initial = 0;
        showStep(initial, false);
    }

    function clearAjaxError(formEl) {
        var existing = formEl.querySelector('.wa-form-ajax-error');
        if (existing) existing.remove();
    }

    function showAjaxError(formEl) {
        var err = document.createElement('div');
        err.className = 'wa-form-errors wa-form-ajax-error';
        err.setAttribute('role', 'alert');
        var ul = document.createElement('ul');
        var li = document.createElement('li');
        li.textContent = 'Something went wrong submitting the form — please try again.';
        ul.appendChild(li);
        err.appendChild(ul);
        formEl.insertBefore(err, formEl.firstChild);
    }

    /**
     * Submits via fetch/FormData instead of a normal POST, so the page never
     * navigates away — no full reload, no scroll jump back to the top. The
     * server re-runs the exact same rendering the shortcode always has
     * (alchemy_forms_ajax_submit() in render.php just calls
     * alchemy_forms_render_shortcode() again), so the returned markup is a
     * complete, fresh .wa-form-wrap — success message, or the form again
     * with validation errors — which replaces this one in place.
     */
    function initAjaxSubmit(wrap) {
        var formEl = wrap.querySelector('.wa-form');
        if (!formEl) return; // showing the success message — nothing left to submit

        var ajaxUrl = wrap.getAttribute('data-ajax-url');
        if (!ajaxUrl) return; // no endpoint known — falls back to a normal POST

        formEl.addEventListener('submit', function (e) {
            e.preventDefault();

            var submitBtn = formEl.querySelector('button[type=submit]');
            if (submitBtn) {
                submitBtn.disabled = true;
                submitBtn.classList.add('wa-form-submit--loading');
                submitBtn.setAttribute('aria-busy', 'true');
            }
            clearAjaxError(formEl);

            var formData = new FormData(formEl);
            formData.append('action', 'alchemy_forms_submit');
            formData.append('wa_form_title', wrap.getAttribute('data-title') || '');
            formData.append('wa_embed_post_id', wrap.getAttribute('data-embed-post-id') || '0');
            formData.append('wa_page_url', window.location.href);

            fetch(ajaxUrl, { method: 'POST', body: formData, credentials: 'same-origin' })
                .then(function (res) { return res.json(); })
                .then(function (json) {
                    if (!json || !json.success || !json.data) {
                        throw new Error('bad response');
                    }
                    if (json.data.redirect) {
                        // A payment-required submission — leave this page for
                        // Stripe's hosted checkout instead of swapping in new markup.
                        window.location.href = json.data.redirect;
                        return;
                    }
                    if (!json.data.html) {
                        throw new Error('bad response');
                    }
                    var temp = document.createElement('div');
                    temp.innerHTML = json.data.html;
                    var newWrap = temp.firstElementChild;
                    if (!newWrap) throw new Error('empty response');
                    wrap.replaceWith(newWrap);
                    init(newWrap);
                    newWrap.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
                })
                .catch(function () {
                    if (submitBtn) {
                        submitBtn.disabled = false;
                        submitBtn.classList.remove('wa-form-submit--loading');
                        submitBtn.removeAttribute('aria-busy');
                    }
                    showAjaxError(formEl);
                });
        });
    }

    function init(form) {
        refresh(form);
        form.addEventListener('change', function () { refresh(form); });
        form.addEventListener('input', function () { refresh(form); });
        initSteps(form);
        initAjaxSubmit(form);
        renderTurnstileWidgets(form);
    }

    document.addEventListener('DOMContentLoaded', function () {
        document.querySelectorAll('.wa-form-wrap').forEach(init);
    });
})();
