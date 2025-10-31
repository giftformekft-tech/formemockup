(function ($) {
    'use strict';

    const config = window.MG_ADMIN_UI || {};
    const legacyMap = config.legacyMap || {};
    const pageSlug = config.pageSlug || 'mockup-generator';

    const state = {
        active: null,
        dirty: false,
    };

    function sanitizeTab(value) {
        if (!value) {
            return '';
        }
        return String(value).toLowerCase().replace(/[^a-z0-9_-]/g, '');
    }

    function getCurrentProductKey() {
        const $panel = $('#tab-mockups');
        const datasetValue = sanitizeTab($panel.data('productKey'));
        if (datasetValue) {
            return datasetValue;
        }
        if (config.currentProduct) {
            return sanitizeTab(config.currentProduct);
        }
        try {
            const params = new URL(window.location.href).searchParams;
            return sanitizeTab(params.get('mg_product'));
        } catch (err) {
            return '';
        }
    }

    function determineInitialTab() {
        try {
            const params = new URL(window.location.href).searchParams;
            const queryTab = sanitizeTab(params.get('mg_tab'));
            if (queryTab) {
                return queryTab;
            }
        } catch (err) {
            // ignore
        }
        if (config.defaultTab) {
            const preset = sanitizeTab(config.defaultTab);
            if (preset) {
                return preset;
            }
        }
        if (window.location.hash) {
            const hashTab = sanitizeTab(window.location.hash.replace('#', ''));
            if (hashTab) {
                return hashTab;
            }
        }
        return 'dashboard';
    }

    function ensureHiddenField($form, name, value) {
        let $field = $form.find('input[name="' + name + '"]');
        if (!value && value !== 0) {
            if ($field.length) {
                $field.remove();
            }
            return;
        }
        if (!$field.length) {
            $field = $('<input>', { type: 'hidden', name: name }).appendTo($form);
        }
        $field.val(value);
    }

    function handleFormSubmit() {
        const $form = $(this);
        const active = state.active || determineInitialTab();

        ensureHiddenField($form, 'page', pageSlug);
        ensureHiddenField($form, 'mg_tab', active);

        if (active === 'mockups') {
            const product = getCurrentProductKey();
            if (product) {
                ensureHiddenField($form, 'mg_product', product);
            } else {
                ensureHiddenField($form, 'mg_product', '');
            }
        } else {
            ensureHiddenField($form, 'mg_product', '');
        }

        state.dirty = false;
        $('.mg-save-bar').removeClass('is-visible');
    }

    function markDirty() {
        if (!state.dirty) {
            state.dirty = true;
            $('.mg-save-bar').addClass('is-visible');
        }
    }

    const colorLabels = {
        addTitle: 'Új szín',
        editTitle: 'Szín szerkesztése',
        name: 'Szín neve',
        slug: 'Rövid kód (slug)',
        color: 'Szín kiválasztása',
        cancel: 'Mégse',
        save: 'Mentés',
        duplicateSlug: 'Ez a slug már szerepel a listában.',
        missingName: 'Add meg a szín nevét.',
        missingSlug: 'Add meg a slug értékét.',
        emptyState: 'Még nincs elérhető szín.',
        remove: 'Eltávolítás: %s',
    };

    function slugify(value) {
        if (!value) {
            return '';
        }
        return String(value)
            .normalize('NFD')
            .replace(/[\u0300-\u036f]/g, '')
            .replace(/[^a-zA-Z0-9]+/g, '-')
            .replace(/^-+|-+$/g, '')
            .replace(/-+/g, '-')
            .toLowerCase();
    }

    function sanitizeHexValue(value) {
        if (!value) {
            return '';
        }
        let hex = String(value).trim();
        if (hex === '') {
            return '';
        }
        if (hex.charAt(0) !== '#') {
            hex = '#' + hex;
        }
        if (/^#([0-9a-fA-F]{3}|[0-9a-fA-F]{6})$/.test(hex)) {
            return hex.toUpperCase();
        }
        return '';
    }

    function parseColorText(value) {
        if (!value) {
            return [];
        }
        return String(value)
            .split(/\r?\n/)
            .map(function (line) {
                const trimmed = line.trim();
                if (!trimmed || trimmed.indexOf(':') === -1) {
                    return null;
                }
                const parts = trimmed.split(':');
                const slugPart = parts.slice(1).join(':');
                const namePart = parts[0];
                const slug = slugify(slugPart);
                if (!slug) {
                    return null;
                }
                let name = namePart;
                let hex = '';
                if (namePart.indexOf('|') !== -1) {
                    const namePieces = namePart.split('|');
                    name = namePieces[0];
                    hex = sanitizeHexValue(namePieces.slice(1).join('|'));
                }
                name = name.trim();
                if (!name) {
                    name = slug;
                }
                return {
                    name: name,
                    slug: slug,
                    hex: hex,
                };
            })
            .filter(function (item) {
                return !!item;
            });
    }

    function formatColorText(colors) {
        return colors
            .map(function (color) {
                if (!color || !color.name || !color.slug) {
                    return '';
                }
                var label = color.name;
                if (color.hex) {
                    label += '|' + color.hex;
                }
                return label + ':' + color.slug;
            })
            .filter(function (line) {
                return line !== '';
            })
            .join('\n');
    }

    function deriveSwatch(color) {
        if (color && color.hex) {
            return color.hex;
        }
        const base = color && (color.slug || color.name) ? String(color.slug || color.name) : 'color';
        let hash = 0;
        for (let i = 0; i < base.length; i++) {
            hash = (hash * 31 + base.charCodeAt(i)) & 0xffffffff;
        }
        const hue = Math.abs(hash) % 360;
        return 'hsl(' + hue + ', 70%, 55%)';
    }

    function buildColorModal($field) {
        const $modal = $('<div>', {
            class: 'mg-color-modal',
            'aria-hidden': 'true',
        });
        const $dialog = $('<div>', {
            class: 'mg-color-modal__dialog',
            role: 'dialog',
            'aria-modal': 'true',
        });
        const $title = $('<h2>', {
            class: 'mg-color-modal__title',
            text: colorLabels.addTitle,
        });
        const $form = $('<form>', {
            class: 'mg-color-modal__form',
            novalidate: 'novalidate',
        });
        const $nameRow = $('<div>', { class: 'mg-color-modal__row' });
        const $nameLabel = $('<label>').text(colorLabels.name);
        const $nameInput = $('<input>', {
            type: 'text',
            class: 'regular-text',
            required: 'required',
        });
        $nameRow.append($nameLabel, $nameInput);

        const $slugRow = $('<div>', { class: 'mg-color-modal__row' });
        const $slugLabel = $('<label>').text(colorLabels.slug);
        const $slugInput = $('<input>', {
            type: 'text',
            class: 'regular-text',
            required: 'required',
        });
        $slugRow.append($slugLabel, $slugInput);

        const $colorRow = $('<div>', { class: 'mg-color-modal__row' });
        const $colorLabel = $('<label>').text(colorLabels.color);
        const $colorInput = $('<input>', {
            type: 'color',
            class: 'mg-color-modal__picker',
            value: '#ffffff',
        });
        $colorRow.append($colorLabel, $colorInput);

        const $error = $('<p>', {
            class: 'mg-color-modal__error',
            role: 'alert',
        }).hide();

        const $actions = $('<div>', { class: 'mg-color-modal__actions' });
        const $cancel = $('<button>', {
            type: 'button',
            class: 'button button-link mg-color-modal__cancel',
            text: colorLabels.cancel,
        });
        const $submit = $('<button>', {
            type: 'submit',
            class: 'button button-primary',
            text: colorLabels.save,
        });
        $actions.append($cancel, $submit);

        $form.append($nameRow, $slugRow, $colorRow, $error, $actions);
        $dialog.append($title, $form);
        $modal.append($dialog);
        $('body').append($modal);

        let onSubmit = null;
        let previousFocus = null;
        let slugPristine = true;

        function setError(message) {
            if (message) {
                $error.text(message).show();
            } else {
                $error.text('').hide();
            }
        }

        function close() {
            $modal.removeClass('is-open').attr('aria-hidden', 'true');
            setError('');
            if (previousFocus && typeof previousFocus.focus === 'function') {
                previousFocus.focus();
            }
        }

        function open(initial, options) {
            const data = initial || {};
            const opts = options || {};
            previousFocus = document.activeElement;
            slugPristine = !data.slug;
            $title.text(opts.title || colorLabels.addTitle);
            $nameInput.val(data.name || '');
            $slugInput.val(data.slug || '');
            const initialHex = sanitizeHexValue(data.hex);
            $colorInput.val(initialHex || '#ffffff');
            $modal.addClass('is-open').attr('aria-hidden', 'false');
            setError('');
            onSubmit = typeof opts.onSubmit === 'function' ? opts.onSubmit : null;
            setTimeout(function () {
                $nameInput.trigger('focus');
                $nameInput[0].setSelectionRange($nameInput.val().length, $nameInput.val().length);
            }, 20);
        }

        $cancel.on('click', function (event) {
            event.preventDefault();
            close();
        });

        $modal.on('click', function (event) {
            if (event.target === $modal[0]) {
                close();
            }
        });

        $modal.on('keydown', function (event) {
            if (event.key === 'Escape') {
                event.preventDefault();
                close();
            }
        });

        $nameInput.on('input', function () {
            if (slugPristine) {
                $slugInput.val(slugify($nameInput.val()));
            }
        });

        $slugInput.on('input', function () {
            slugPristine = $slugInput.val().trim() === '';
        });

        $slugInput.on('blur', function () {
            const sanitized = slugify($slugInput.val());
            $slugInput.val(sanitized);
        });

        $form.on('submit', function (event) {
            event.preventDefault();
            const name = $nameInput.val().trim();
            const slug = slugify($slugInput.val());
            const hex = sanitizeHexValue($colorInput.val());
            if (!name) {
                setError(colorLabels.missingName);
                $nameInput.trigger('focus');
                return;
            }
            if (!slug) {
                setError(colorLabels.missingSlug);
                $slugInput.trigger('focus');
                return;
            }
            const payload = {
                name: name,
                slug: slug,
                hex: hex,
            };
            if (onSubmit) {
                const result = onSubmit(payload, { close: close, setError: setError });
                if (result === false) {
                    return;
                }
            }
            close();
        });

        return {
            open: open,
            close: close,
            setError: setError,
        };
    }

    function initColorManagers(scope) {
        const $scope = scope ? $(scope) : $(document);
        $scope.find('[data-mg-color-manager]').each(function () {
            const $field = $(this);
            if ($field.data('mgColorManagerReady')) {
                return;
            }
            $field.data('mgColorManagerReady', true);
            const $textarea = $field.find('textarea[name="colors"]');
            if (!$textarea.length) {
                return;
            }
            const $list = $field.find('.mg-color-field__chips');
            const $addButton = $field.find('.mg-color-field__add');
            const modal = buildColorModal($field);
            const $empty = $('<p>', { class: 'mg-color-field__empty' }).text(colorLabels.emptyState);

            let colors = parseColorText($textarea.val());
            let dragIndex = null;

            function updateInput(triggerChange) {
                const formatted = formatColorText(colors);
                if ($textarea.val() !== formatted) {
                    $textarea.val(formatted);
                    if (triggerChange) {
                        $textarea.trigger('change');
                    }
                }
            }

            function focusChip(index) {
                const $chips = $list.find('.mg-color-chip');
                if (index >= 0 && index < $chips.length) {
                    $chips.eq(index).trigger('focus');
                }
            }

            function render(focusSlug) {
                $list.empty();
                if (!colors.length) {
                    $list.append($empty);
                    return;
                }
                $empty.detach();
                colors.forEach(function (color, index) {
                    const $chip = $('<div>', {
                        class: 'mg-color-chip',
                        tabindex: 0,
                        role: 'listitem',
                        draggable: true,
                        'data-index': index,
                        'aria-label': color.name + ' (' + color.slug + ')',
                    });
                    const swatch = deriveSwatch(color);
                    const $swatch = $('<span>', { class: 'mg-color-chip__swatch' }).css('background', swatch);
                    if (color.hex) {
                        $swatch.attr('title', color.hex);
                    }
                    const $label = $('<span>', { class: 'mg-color-chip__label' });
                    $label.append($('<strong>').text(color.name));
                    $label.append($('<code>').text(color.slug));
                    const removeLabel = colorLabels.remove.replace('%s', color.name);
                    const $remove = $('<button>', {
                        type: 'button',
                        class: 'mg-color-chip__remove',
                        'aria-label': removeLabel,
                    }).html('&times;');

                    $remove.on('click', function (event) {
                        event.stopPropagation();
                        removeColor(index);
                    });

                    $chip.on('click', function (event) {
                        if ($(event.target).closest('.mg-color-chip__remove').length) {
                            return;
                        }
                        editColor(index);
                    });

                    $chip.on('keydown', function (event) {
                        if (event.key === 'Delete' || event.key === 'Backspace') {
                            event.preventDefault();
                            removeColor(index);
                            return;
                        }
                        if (event.key === 'Enter' || event.key === ' ') {
                            event.preventDefault();
                            editColor(index);
                            return;
                        }
                        if (event.key === 'ArrowRight' || event.key === 'ArrowDown') {
                            event.preventDefault();
                            focusChip(index + 1);
                        }
                        if (event.key === 'ArrowLeft' || event.key === 'ArrowUp') {
                            event.preventDefault();
                            focusChip(index - 1);
                        }
                    });

                    $chip.on('dragstart', function (event) {
                        dragIndex = index;
                        $(this).addClass('is-dragging');
                        if (event.originalEvent && event.originalEvent.dataTransfer) {
                            event.originalEvent.dataTransfer.effectAllowed = 'move';
                            event.originalEvent.dataTransfer.setData('text/plain', String(index));
                        }
                    });

                    $chip.on('dragend', function () {
                        dragIndex = null;
                        $(this).removeClass('is-dragging');
                        $list.find('.mg-color-chip').removeClass('is-drop-target');
                    });

                    $chip.on('dragover', function (event) {
                        event.preventDefault();
                        $(this).addClass('is-drop-target');
                        if (event.originalEvent && event.originalEvent.dataTransfer) {
                            event.originalEvent.dataTransfer.dropEffect = 'move';
                        }
                    });

                    $chip.on('dragleave', function () {
                        $(this).removeClass('is-drop-target');
                    });

                    $chip.on('drop', function (event) {
                        event.preventDefault();
                        $(this).removeClass('is-drop-target');
                        if (dragIndex === null) {
                            return;
                        }
                        const targetIndex = parseInt($(this).data('index'), 10);
                        if (Number.isInteger(targetIndex) && targetIndex !== dragIndex) {
                            const moved = colors.splice(dragIndex, 1)[0];
                            colors.splice(targetIndex, 0, moved);
                            render(moved.slug);
                            updateInput(true);
                            dragIndex = null;
                            $list.find('.mg-color-chip').removeClass('is-drop-target');
                        }
                    });

                    $chip.append($swatch, $label, $remove);
                    $list.append($chip);
                    if (focusSlug && color.slug === focusSlug) {
                        setTimeout(function () {
                            $chip.trigger('focus');
                        }, 20);
                    }
                });
            }

            function removeColor(index) {
                if (index < 0 || index >= colors.length) {
                    return;
                }
                const removed = colors.splice(index, 1)[0];
                render();
                updateInput(true);
                if (colors.length) {
                    const nextIndex = index >= colors.length ? colors.length - 1 : index;
                    focusChip(nextIndex);
                }
                return removed;
            }

            function addColor() {
                modal.open({}, {
                    title: colorLabels.addTitle,
                    onSubmit: function (data) {
                        const hex = sanitizeHexValue(data.hex);
                        const entry = {
                            name: data.name,
                            slug: data.slug,
                            hex: hex,
                        };
                        const exists = colors.some(function (color) {
                            return color.slug === entry.slug;
                        });
                        if (exists) {
                            modal.setError(colorLabels.duplicateSlug);
                            return false;
                        }
                        colors.push(entry);
                        render(entry.slug);
                        updateInput(true);
                        return true;
                    },
                });
            }

            function editColor(index) {
                if (index < 0 || index >= colors.length) {
                    return;
                }
                const current = colors[index];
                modal.open(current, {
                    title: colorLabels.editTitle,
                    onSubmit: function (data) {
                        const hex = sanitizeHexValue(data.hex);
                        const duplicate = colors.some(function (color, idx) {
                            return idx !== index && color.slug === data.slug;
                        });
                        if (duplicate) {
                            modal.setError(colorLabels.duplicateSlug);
                            return false;
                        }
                        const changed =
                            current.name !== data.name ||
                            current.slug !== data.slug ||
                            (current.hex || '') !== hex;
                        if (!changed) {
                            return true;
                        }
                        colors[index] = {
                            name: data.name,
                            slug: data.slug,
                            hex: hex,
                        };
                        render(data.slug);
                        updateInput(true);
                        return true;
                    },
                });
            }

            $list.on('dragover', function (event) {
                event.preventDefault();
                if (event.originalEvent && event.originalEvent.dataTransfer) {
                    event.originalEvent.dataTransfer.dropEffect = 'move';
                }
            });

            $list.on('drop', function (event) {
                event.preventDefault();
                if (dragIndex === null) {
                    return;
                }
                $list.find('.mg-color-chip').removeClass('is-drop-target');
                const moved = colors.splice(dragIndex, 1)[0];
                colors.push(moved);
                render(moved.slug);
                updateInput(true);
                dragIndex = null;
            });

            $addButton.on('click', function (event) {
                event.preventDefault();
                addColor();
            });

            render();
            updateInput(false);
        });
    }

    function rewriteLegacyLinks(scope) {
        const $scope = scope ? $(scope) : $(document);
        $scope.find('a[href*="admin.php"]').each(function () {
            const href = this.getAttribute('href');
            if (!href) {
                return;
            }
            let url;
            try {
                url = new URL(href, window.location.origin);
            } catch (err) {
                return;
            }
            const legacySlug = url.searchParams.get('page');
            const mappedTab = legacySlug ? legacyMap[legacySlug] : '';
            if (!mappedTab) {
                return;
            }
            url.searchParams.set('page', pageSlug);
            url.searchParams.set('mg_tab', mappedTab);
            if (legacySlug === 'mockup-generator-product') {
                const product = sanitizeTab(url.searchParams.get('product')) || getCurrentProductKey();
                if (product) {
                    url.searchParams.set('mg_product', product);
                }
            } else {
                url.searchParams.delete('mg_product');
            }
            this.href = url.toString();
        });
    }

    function updateUrl(tab, options) {
        const opts = options || {};
        try {
            const url = new URL(window.location.href);
            url.searchParams.set('page', pageSlug);
            url.searchParams.set('mg_tab', tab);
            if (tab === 'mockups') {
                const product = getCurrentProductKey();
                if (product) {
                    url.searchParams.set('mg_product', product);
                }
            } else {
                url.searchParams.delete('mg_product');
            }
            const finalUrl = url.toString();
            if (opts.replace) {
                window.history.replaceState(null, '', opts.skipHash ? finalUrl : finalUrl.split('#')[0] + '#' + tab);
            } else {
                window.history.pushState(null, '', opts.skipHash ? finalUrl : finalUrl.split('#')[0] + '#' + tab);
            }
        } catch (err) {
            // Ignore URL API issues for older browsers.
        }
    }

    function updateHash(tab) {
        const targetHash = '#' + tab;
        if (window.location.hash !== targetHash) {
            window.location.hash = targetHash;
        }
    }

    function setActiveTab(tab, options) {
        const sanitized = sanitizeTab(tab);
        if (!sanitized) {
            return;
        }
        const opts = options || {};
        if (state.active === sanitized) {
            if (!opts.skipHistory) {
                updateUrl(sanitized, { replace: true, skipHash: opts.skipHash });
            }
            if (!opts.skipHash) {
                updateHash(sanitized);
            }
            return;
        }

        state.active = sanitized;
        state.dirty = false;
        $('.mg-save-bar').removeClass('is-visible');

        $('.mg-tab').removeClass('is-active').filter('[data-tab="' + sanitized + '"]').addClass('is-active');
        $('.mg-panel').removeClass('is-active').filter('#tab-' + sanitized).addClass('is-active');
        rewriteLegacyLinks('#tab-' + sanitized);
        initColorManagers('#tab-' + sanitized);

        if (!opts.skipHistory) {
            updateUrl(sanitized, { replace: opts.replace === true, skipHash: opts.skipHash });
        }
        if (!opts.skipHash) {
            updateHash(sanitized);
        }
    }

    function handleHashChange() {
        const tab = sanitizeTab(window.location.hash.replace('#', ''));
        if (tab && tab !== state.active) {
            setActiveTab(tab, { skipHistory: false, replace: true });
        }
    }

    function handlePopState() {
        try {
            const params = new URL(window.location.href).searchParams;
            const tab = sanitizeTab(params.get('mg_tab')) || sanitizeTab(window.location.hash.replace('#', ''));
            if (tab && tab !== state.active) {
                setActiveTab(tab, { skipHistory: true, skipHash: true, replace: true });
            }
        } catch (err) {
            // ignore
        }
    }

    $(function () {
        const initial = sanitizeTab(config.defaultTab) || determineInitialTab();
        setActiveTab(initial, { skipHistory: true, skipHash: true, replace: true });
        updateUrl(state.active, { replace: true, skipHash: true });

        if (window.location.hash) {
            const hashTab = sanitizeTab(window.location.hash.replace('#', ''));
            if (hashTab && hashTab !== state.active) {
                setActiveTab(hashTab, { skipHistory: true });
            }
        }

        $('.mg-tab').on('click', function () {
            const target = sanitizeTab($(this).data('tab'));
            if (target) {
                setActiveTab(target);
            }
        });

        $(document).on('change input', '.mg-panel input, .mg-panel select, .mg-panel textarea', function () {
            markDirty();
        });

        $(document).on('submit', '.mg-panel form', function () {
            handleFormSubmit.call(this);
        });

        $('.mg-save-bar__submit').on('click', function () {
            const $panel = $('.mg-panel.is-active');
            const $form = $panel.find('form').first();
            if ($form.length) {
                $form.trigger('submit');
            }
        });

        window.addEventListener('hashchange', handleHashChange);
        window.addEventListener('popstate', handlePopState);

        rewriteLegacyLinks(document);
        initColorManagers(document);
    });
})(jQuery);
