jQuery(function ($) {
    $('.wa-color-field').wpColorPicker();

    var $list = $('#wa-fields-list');
    if (!$list.length) return; // Not on the form builder screen.

    var counter = Date.now(); // Unique index for new rows; server reindexes on save.

    function getTemplate(type) {
        var $tpl = $('#wa-field-template-' + type);
        return $tpl.length ? $tpl.html() : null;
    }

    function updateEmptyState() {
        $('#wa-canvas-empty').toggle($list.children('.wa-field-item').length === 0);
    }

    function insertField(type, $before) {
        var html = getTemplate(type);
        if (!html) return null;
        html = html.replace(/\{\{i\}\}/g, 'new_' + (counter++));
        var $item = $(html);
        // Assign a temporary uid immediately so this field can be selected as a
        // condition trigger by other fields before anything is saved. The save
        // handler keeps whatever uid it's given, so this becomes permanent.
        $item.find('.wa-field-uid').val('c_' + Date.now() + '_' + Math.random().toString(36).slice(2));
        if ($before && $before.length) {
            $item.insertBefore($before);
        } else {
            $item.appendTo($list);
        }
        $item.addClass('wa-field-item--expanded');
        updateEmptyState();
        refreshConditionDropdowns();
        $item.find('.wa-field-label').trigger('focus').trigger('select');
        return $item;
    }

    /* -------------------------------------------------------------------
     * Conditional logic — keeps each card's "which field" dropdown (and its
     * value input) in sync with the fields actually present in the canvas.
     * ---------------------------------------------------------------- */
    function collectFieldsData() {
        var items = [];
        $list.children('.wa-field-item').each(function () {
            var $item = $(this);
            var options = [];
            var $optionsTa = $item.find('.wa-field-options textarea');
            if ($optionsTa.length) {
                options = $optionsTa.val().split(/\r\n|\r|\n/).map(function (s) {
                    return s.trim();
                }).filter(function (s) {
                    return s !== '';
                });
            }
            items.push({
                uid: $item.find('.wa-field-uid').val(),
                label: $item.find('.wa-field-label').val(),
                type: $item.data('type'),
                options: options,
                $item: $item
            });
        });
        return items;
    }

    function updateConditionValueInput($item) {
        var $valueText = $item.find('.wa-condition-value-text');
        var $valueSelect = $item.find('.wa-condition-value-select');

        // Leave both disabled while the condition itself is off, so a stale
        // selection can't slip into the submission via refreshConditionDropdowns().
        if (!$item.find('.wa-condition-enable').is(':checked')) {
            $valueText.prop('disabled', true);
            $valueSelect.prop('disabled', true);
            return;
        }

        var $selected = $item.find('.wa-condition-field option:selected');
        var options = [];
        try {
            options = JSON.parse($selected.attr('data-options') || '[]');
        } catch (e) {}

        var current = $valueSelect.prop('disabled') ? $valueText.val() : $valueSelect.val();

        if (options.length) {
            $valueSelect.empty();
            options.forEach(function (opt) {
                $valueSelect.append($('<option>', { value: opt, text: opt }));
            });
            if (options.indexOf(current) !== -1) $valueSelect.val(current);
            $valueSelect.prop('disabled', false);
            $valueText.prop('disabled', true);
        } else {
            $valueText.prop('disabled', false);
            if (current) $valueText.val(current);
            $valueSelect.prop('disabled', true).empty();
        }
    }

    function refreshConditionDropdowns() {
        var items = collectFieldsData();
        items.forEach(function (self) {
            var $select = self.$item.find('.wa-condition-field');
            if (!$select.length) return;
            var current = $select.val();

            $select.empty().append($('<option>', { value: '', text: 'Select a field…' }));
            items.forEach(function (other) {
                // Mirrors alchemy_forms_condition_ineligible_types() in alchemy-forms.php: step
                // breaks/HTML blocks collect no value, and checkbox/file fields can't
                // be evaluated consistently between this dropdown and the server.
                if (!other.uid || other.uid === self.uid || ['page_break', 'html', 'checkbox', 'file'].indexOf(other.type) !== -1) return;
                var $opt = $('<option>', { value: other.uid, text: other.label || other.type });
                $opt.attr('data-type', other.type);
                $opt.attr('data-options', JSON.stringify(other.options));
                $select.append($opt);
            });

            if (current && $select.find('option[value="' + current + '"]').length) {
                $select.val(current);
            }
            updateConditionValueInput(self.$item);
        });
    }

    $list.on('change', '.wa-condition-enable', function () {
        var enabled = this.checked;
        var $item = $(this).closest('.wa-field-item');
        var $condition = $item.find('.wa-field-condition');

        $condition.toggle(enabled);
        // Disabled inputs are excluded from form submission — when the toggle is
        // off, this makes sure a stale condition doesn't silently keep applying.
        $condition.find('.wa-condition-field, .wa-condition-comparator').prop('disabled', !enabled);
        if (enabled) {
            updateConditionValueInput($item);
        } else {
            $condition.find('.wa-condition-value-text, .wa-condition-value-select').prop('disabled', true);
        }
    });

    $list.on('change', '.wa-condition-field', function () {
        updateConditionValueInput($(this).closest('.wa-field-item'));
    });

    $list.on('change', '.wa-hidden-source', function () {
        $(this).closest('.wa-field-item').find('.wa-hidden-static-value').toggle(this.value === 'static');
    });

    $list.on('blur', '.wa-field-label', refreshConditionDropdowns);

    // Palette: click a field type to append it to the end of the canvas.
    $('#wa-palette-list').on('click', '.wa-palette-item', function () {
        insertField($(this).data('type'));
    });

    // Canvas: click a card's header/toggle to expand or collapse it.
    $list.on('click', '.wa-field-card-header', function (e) {
        if ($(e.target).closest('.wa-remove-field, .wa-field-handle').length) return;
        $(this).closest('.wa-field-item').toggleClass('wa-field-item--expanded');
    });

    $list.on('click', '.wa-remove-field', function (e) {
        e.stopPropagation();
        $(this).closest('.wa-field-item').remove();
        updateEmptyState();
        refreshConditionDropdowns();
    });

    // Canvas is sortable for reordering, and accepts drops from the palette.
    $list.sortable({
        items: '> .wa-field-item',
        handle: '.wa-field-handle',
        axis: 'y',
        connectWith: '#wa-palette-list',
        placeholder: 'wa-field-row-placeholder',
        forcePlaceholderSize: true,
        receive: function (event, ui) {
            var type = ui.item.data('type');
            var $placeholder = ui.item;
            insertField(type, $placeholder);
            $placeholder.remove();
        }
    });

    // Palette is a drag source only: items are cloned onto the canvas, never removed,
    // and anything dropped back onto the palette (e.g. a canvas card) is rejected.
    $('#wa-palette-list').sortable({
        items: '> .wa-palette-item',
        connectWith: '#wa-fields-list',
        helper: 'clone',
        distance: 3,
        placeholder: 'wa-palette-item-placeholder',
        appendTo: 'body',
        start: function (event, ui) {
            ui.helper.width(ui.item.width());
        },
        receive: function (event, ui) {
            ui.sender.sortable('cancel');
        }
    });

    updateEmptyState();
    refreshConditionDropdowns();

    // Style box relocates into its own persistent sidebar slot next to the
    // canvas, so it's visible without opening the Settings drawer.
    $('#wa-style-panel-slot').append($('#wa_form_style'));

    $('#wa_style_container_opacity').on('input', function () {
        $('#wa_style_container_opacity_val').text($(this).val());
    });

    /* -------------------------------------------------------------------
     * Settings drawer — relocates the Publish/Settings/Usage boxes
     * (rendered normally by WordPress) into a slide-out panel so the
     * canvas can use the full width of the screen.
     * ---------------------------------------------------------------- */
    // Appended inside the post form (not body) so the relocated Publish/Settings/
    // Usage fields stay part of the form and still get submitted on save.
    var $drawer = $('<div id="wa-settings-drawer"><div class="wa-drawer-panel"></div></div>').appendTo('#post');
    var $panel = $drawer.find('.wa-drawer-panel');

    ['#submitdiv', '#wa_form_settings', '#wa_form_usage'].forEach(function (selector) {
        var $box = $(selector);
        if ($box.length) $panel.append($box);
    });

    $('#wa-open-settings').on('click', function () {
        $drawer.addClass('wa-drawer-open');
    });

    $drawer.on('click', function (e) {
        if (e.target === this) $drawer.removeClass('wa-drawer-open');
    });

    $(document).on('keydown', function (e) {
        if (e.key === 'Escape') $drawer.removeClass('wa-drawer-open');
    });
});
