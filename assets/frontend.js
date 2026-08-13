(function () {
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

    function init(form) {
        refresh(form);
        form.addEventListener('change', function () { refresh(form); });
        form.addEventListener('input', function () { refresh(form); });
        initSteps(form);
    }

    document.addEventListener('DOMContentLoaded', function () {
        document.querySelectorAll('.wa-form-wrap').forEach(init);
    });
})();
