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

    function init(form) {
        refresh(form);
        form.addEventListener('change', function () { refresh(form); });
        form.addEventListener('input', function () { refresh(form); });
    }

    document.addEventListener('DOMContentLoaded', function () {
        document.querySelectorAll('.wa-form-wrap').forEach(init);
    });
})();
