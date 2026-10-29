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

    function toggleModalState(isOpen) {
        $('body').toggleClass('mg-modal-open', !!isOpen);
    }

    function openModalById(id) {
        const $modal = $('#' + id);
        if (!$modal.length) {
            return;
        }

        $modal.attr('aria-hidden', 'false').addClass('is-visible');
        toggleModalState(true);

        const $firstField = $modal.find('input, select, textarea, button').filter(':visible').first();
        if ($firstField.length) {
            $firstField.trigger('focus');
        }
    }

    function closeModal($modal) {
        if (!$modal || !$modal.length) {
            return;
        }

        $modal.attr('aria-hidden', 'true').removeClass('is-visible');
        toggleModalState(false);
    }

    function initModals() {
        $(document).on('click', '[data-mg-open-modal]', function (e) {
            const target = sanitizeTab($(this).data('mgOpenModal'));
            if (!target) {
                return;
            }
            const modalId = 'mg-' + target + '-modal';
            if ($('#' + modalId).length) {
                e.preventDefault();
                openModalById(modalId);
                return;
            }

            const fallbackUrl = $(this).data('fallbackUrl');
            if (fallbackUrl) {
                window.location.href = fallbackUrl;
            }
        });

        $(document).on('click', '[data-mg-close-modal]', function (e) {
            e.preventDefault();
            closeModal($(this).closest('.mg-modal'));
        });

        $(document).on('click', '.mg-modal__backdrop', function (e) {
            e.preventDefault();
            closeModal($(this).closest('.mg-modal'));
        });

        $(document).on('click', '.mg-modal', function (e) {
            if ($(e.target).closest('.mg-modal__dialog').length) {
                return;
            }
            closeModal($(this));
        });

        $(document).on('keydown', function (e) {
            if (e.key === 'Escape') {
                closeModal($('.mg-modal.is-visible'));
            }
        });
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

    function syncGroupUi(tab) {
        const $btn = $('.mg-tab[data-tab="' + tab + '"]');
        const group = sanitizeTab($btn.data('group'));
        if (!group) {
            return;
        }
        $('.mg-group').removeClass('is-active').filter('[data-group="' + group + '"]').addClass('is-active');
        $('.mg-subtabs').removeClass('is-active').filter('[data-group="' + group + '"]').addClass('is-active');
    }

    function setActiveTab(tab, options) {
        let sanitized = sanitizeTab(tab);
        if (!sanitized) {
            return;
        }
        const $targetBtn = $('.mg-tab[data-tab="' + sanitized + '"]');
        if (!$targetBtn.length) {
            sanitized = sanitizeTab($('.mg-tab').first().data('tab'));
            if (!sanitized) {
                return;
            }
        }

        // Only the active panel is rendered server-side; switching to any
        // other tab is a normal navigation to its deep link.
        if (!$('#tab-' + sanitized).length) {
            const targetUrl = $('.mg-tab[data-tab="' + sanitized + '"]').data('url');
            if (targetUrl) {
                window.location.href = targetUrl;
                return;
            }
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
        syncGroupUi(sanitized);
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

        $('.mg-group').on('click', function () {
            const group = sanitizeTab($(this).data('group'));
            const isCurrent = $(this).hasClass('is-active');
            const target = sanitizeTab($(this).data('defaultTab'));
            if (!target || (isCurrent && group)) {
                return;
            }
            setActiveTab(target);
        });

        $(document).on('change input', '.mg-panel input, .mg-panel select, .mg-panel textarea', function () {
            if ($(this).closest('[data-mg-readonly]').length) {
                return;
            }
            markDirty();
        });

        $(document).on('submit', '.mg-panel form', function () {
            if ($(this).closest('[data-mg-readonly]').length) {
                return;
            }
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

        initModals();
        rewriteLegacyLinks(document);
        initColorManagers(document);
        initProductEditorTabs();
        initDesignReplace();
        initAiRedesign();
    });

    // "Minta cseréje" modal a Dashboard soraiból: feltölti a javított mintát
    // és sorba állítja az újragenerálást (mg_replace_design AJAX).
    function initDesignReplace() {
        $(document).on('click', '.mg-replace-design-trigger', function () {
            $('#mg-replace-product-id').val($(this).data('productId') || '');
            $('#mg-replace-product-name').text($(this).data('productTitle') || '');
            $('#mg-replace-design-file').val('');
            $('#mg-replace-design-status').text('');
        });

        $(document).on('click', '#mg-replace-design-submit', function () {
            const $btn = $(this);
            const pid = $('#mg-replace-product-id').val();
            const fileInput = document.getElementById('mg-replace-design-file');
            const $status = $('#mg-replace-design-status');
            if (!pid || !fileInput || !fileInput.files || !fileInput.files.length) {
                $status.text('Válaszd ki az új minta fájlt.');
                return;
            }
            const ajaxCfg = window.MG_AJAX || {};
            if (!ajaxCfg.ajax_url || !ajaxCfg.nonce) {
                $status.text('Hiányzó AJAX konfiguráció – frissítsd az oldalt.');
                return;
            }
            const form = new FormData();
            form.append('action', 'mg_replace_design');
            form.append('nonce', ajaxCfg.nonce);
            form.append('product_id', pid);
            form.append('design_file', fileInput.files[0]);
            $btn.prop('disabled', true);
            $status.text('Feltöltés és sorba állítás…');
            $.ajax({
                url: ajaxCfg.ajax_url,
                method: 'POST',
                data: form,
                processData: false,
                contentType: false,
                dataType: 'json',
            }).done(function (resp) {
                if (resp && resp.success) {
                    $status.text((resp.data && resp.data.message) || 'Sorba állítva.');
                } else {
                    $status.text('Hiba: ' + ((resp && resp.data && resp.data.message) || 'ismeretlen'));
                }
            }).fail(function (xhr) {
                let msg = 'a kérés nem sikerült.';
                if (xhr && xhr.responseJSON && xhr.responseJSON.data && xhr.responseJSON.data.message) {
                    msg = xhr.responseJSON.data.message;
                } else if (xhr && xhr.status === 413) {
                    msg = 'a fájl túl nagy a szerver feltöltési limitjéhez (HTTP 413).';
                } else if (xhr && xhr.status) {
                    msg = 'a kérés nem sikerült (HTTP ' + xhr.status + ').';
                }
                $status.text('Hiba: ' + msg);
            }).always(function () {
                $btn.prop('disabled', false);
            });
        });
    }

    // "AI újratervezés" modal: az elemző modell új stílusú promptot ír a
    // minta szövegével, a GPT Image megrajzolja, a jóváhagyott PNG pedig a
    // Minta cseréje sorral újragenerálja a mockupokat (mg_ai_redesign_* AJAX).
    function initAiRedesign() {
        const stageLabels = {
            prepare: 'Előkészítés…',
            analyze: 'Az elemző modell vizsgálja a mintát és írja az új promptot…',
            image: 'A képgeneráló rajzolja az új mintát (1–3 perc)…',
            save: 'Mentés a médiatárba…',
        };
        const intensityLabels = { strong: 'új stílus', medium: 'finom átdolgozás', subtle: 'extra finom', full: 'teljesen új' };
        const modeLabels = { brief: 'brief', free: 'szabad leírás', describe: 'részletes leírás' };
        const upscaleLabels = {
            ai2x: 'AI felskálázva 2×',
            ai3x: 'AI felskálázva 3×',
            ai4x: 'AI felskálázva 4×',
            lanczos2x: '2× sima nagyítás',
            lanczos3x: '3× sima nagyítás',
            lanczos4x: '4× sima nagyítás',
            native: 'Eredeti felbontás (elég nagy, nem kellett felskálázni)',
            none: 'Még nincs felskálázva – jóváhagyáskor sima nagyítás lesz (ha kell), paletta nélkül',
        };
        // ai3x was always Real-CUGAN, ai4x Real-ESRGAN; 2× tells by upscale_model.
        const upscaleLabel = function (c) {
            const label = upscaleLabels[c.upscale];
            if (!label || c.upscale.indexOf('ai') !== 0) {
                return label || '';
            }
            return label + (c.upscale === 'ai4x' || c.upscale_model === 'esrgan' ? ' (Real-ESRGAN)' : ' (Real-CUGAN)');
        };
        const megapixels = function (w, h) { return (w * h / 1e6).toFixed(2).replace('.', ','); };
        let upscaling = false;
        let autoUpscaleId = '';
        let productId = '';
        let pollTimer = null;
        let running = false;
        let pollFailures = 0;
        const $status = function () { return $('#mg-ai-redesign-status'); };

        function post(action, data) {
            const cfg = window.MG_AJAX || {};
            return $.ajax({
                url: cfg.ajax_url,
                method: 'POST',
                dataType: 'json',
                data: $.extend({ action: action, nonce: cfg.nonce, product_id: productId }, data || {}),
            });
        }

        function responseMessage(resp) {
            return (resp && resp.data && resp.data.message) || '';
        }

        function stopPolling() {
            if (pollTimer) {
                clearTimeout(pollTimer);
                pollTimer = null;
            }
        }

        function setRunning(value) {
            running = value;
            $('#mg-ai-redesign-submit').prop('disabled', value);
        }

        function renderCandidates(list) {
            const $wrap = $('#mg-ai-redesign-candidates').empty();
            $('.mg-ai-redesign__bulk').prop('hidden', !(list && list.length > 1));
            (list || []).forEach(function (c) {
                const $card = $('<div class="mg-ai-redesign__candidate"></div>');
                const $link = $('<a target="_blank" rel="noopener"></a>').attr('href', c.full || c.preview);
                $link.append($('<img alt="" />').attr('src', c.preview || c.full));
                $card.append($link);
                const meta = [c.analyzer_label, modeLabels[c.prompt_mode] || '', intensityLabels[c.intensity] || c.intensity, 'minőség: ' + c.quality, c.generated_size ? 'generálva ' + c.generated_size + ' px' : ''].filter(Boolean).join(' · ');
                $card.append($('<p class="description"></p>').text(meta));
                const size = c.width && c.height ? ' (' + c.width + '×' + c.height + ' px)' : '';
                const palette = c.palette ? ' · palettás PNG, ' + c.palette + ' szín' : '';
                const upscaled = upscaleLabel(c);
                $card.append($('<p class="description mg-ai-redesign__upscale"></p>').text(upscaled ? upscaled + palette + size : ''));
                if (c.text_lines && c.text_lines.length) {
                    $card.append($('<p></p>').append($('<strong>Szöveg: </strong>')).append(document.createTextNode(c.text_lines.join(' / '))));
                }
                if (c.new_style) {
                    $card.append($('<p></p>').append($('<strong>Új stílus: </strong>')).append(document.createTextNode(c.new_style)));
                }
                const $actions = $('<p></p>');
                $actions.append($('<button type="button" class="button button-primary mg-ai-redesign-apply">Jóváhagyás és mockupok újragenerálása</button>').attr('data-attachment-id', c.attachment_id));
                $actions.append(' ');
                $actions.append($('<button type="button" class="button mg-ai-redesign-edit-prompt">Prompt szerkesztése</button>').data('prompt', c.prompt || ''));
                if (c.upscale === 'none' && c.upscale_factor > 1) {
                    $actions.append(' ');
                    $actions.append($('<button type="button" class="button mg-ai-redesign-upscale"></button>').text('AI felskálázás (' + c.upscale_factor + '×)').attr('data-attachment-id', c.attachment_id).data('url', c.full));
                }
                $actions.append(' ');
                $actions.append($('<button type="button" class="button button-link-delete mg-ai-redesign-discard">Törlés</button>').attr('data-attachment-id', c.attachment_id));
                $card.attr('data-upscale', c.upscale || '');
                $card.append($actions);
                $wrap.append($card);
            });
        }

        function loadInfo() {
            return post('mg_ai_redesign_info').done(function (resp) {
                if (!resp || !resp.success) {
                    $status().text('Hiba: ' + (responseMessage(resp) || 'ismeretlen'));
                    return;
                }
                const d = resp.data;
                if (d.design_url) {
                    $('#mg-ai-redesign-original').attr('src', d.design_url).show();
                }
                $('#mg-ai-redesign-original-error').text(d.design_error || '');
                $('#mg-ai-redesign-submit').prop('disabled', !!d.design_error);
                const $analyzer = $('#mg-ai-redesign-analyzer').empty();
                $.each(d.analyzers || {}, function (value, label) {
                    $analyzer.append($('<option></option>').val(value).text(label));
                });
                $analyzer.val(d.analyzer);
                $('#mg-ai-redesign-quality').val(d.quality || 'medium');
                // Every size the image model can make at this design's aspect ratio; the
                // default is the largest one within the saved longest side.
                const $res = $('#mg-ai-redesign-resolution').empty();
                const sizes = d.sizes || [];
                $res.append($('<option value="economy"></option>').text('Gazdaságos – a legkisebb' + (sizes[0] ? ' (' + sizes[0].width + '×' + sizes[0].height + ', ' + megapixels(sizes[0].width, sizes[0].height) + ' MP)' : '')));
                let pick = 'economy';
                sizes.forEach(function (z) {
                    $res.append($('<option></option>').val(String(z.edge))
                        .text(z.width + '×' + z.height + ' px · ' + megapixels(z.width, z.height) + ' MP' + (z.experimental ? ' · kísérleti' : '')));
                    if (d.resolution !== 'economy' && z.edge <= Number(d.resolution)) {
                        pick = String(z.edge);
                    }
                });
                $res.val(pick);
                renderCandidates(d.candidates);
                const fresh = (d.candidates || [])[0];
                if (fresh && fresh.upscale === 'none' && String(fresh.attachment_id) === autoUpscaleId) {
                    autoUpscaleId = '';
                    runUpscale(String(fresh.attachment_id), fresh.full);
                }
                if (d.state && d.state.status === 'running') {
                    setRunning(true);
                    poll();
                }
            }).fail(function () {
                $status().text('Hiba: a minta adatai nem tölthetők be.');
            });
        }

        function handleState(state) {
            if (!state || state.status === 'none') {
                setRunning(false);
                $status().text('A generálás állapota nem ismert – frissítsd a listát vagy indítsd újra.');
                return;
            }
            if (state.status === 'running') {
                const elapsed = state.started ? Math.max(0, Math.round(Date.now() / 1000 - state.started)) : 0;
                $status().text((stageLabels[state.stage] || 'Folyamatban…') + (elapsed ? ' (' + elapsed + ' mp)' : ''));
                return;
            }
            stopPolling();
            setRunning(false);
            if (state.status === 'error') {
                $status().text('Hiba: ' + (state.message || 'ismeretlen'));
                return;
            }
            $status().text('Elkészült. Nézd meg a mintát (kattintásra teljes méretben nyílik), és ha jó, hagyd jóvá.');
            if (state.candidate && state.candidate.upscale === 'none' && window.MGAIUpscale) {
                autoUpscaleId = String(state.candidate.attachment_id);
            }
            loadInfo();
        }

        function poll() {
            stopPolling();
            if (!running) {
                return;
            }
            pollTimer = setTimeout(function () {
                const pid = productId;
                post('mg_ai_redesign_status').done(function (resp) {
                    if (pid !== productId) {
                        return;
                    }
                    pollFailures = 0;
                    handleState(resp && resp.data);
                }).fail(function () {
                    pollFailures++;
                    if (pollFailures >= 10 && pid === productId) {
                        handleState({ status: 'error', message: 'az állapot nem kérdezhető le. Frissítsd az oldalt.' });
                    }
                }).always(function () {
                    if (running && pid === productId) {
                        poll();
                    }
                });
            }, 4000);
        }

        $(document).on('click', '.mg-ai-redesign-trigger', function () {
            stopPolling();
            setRunning(false);
            productId = String($(this).data('productId') || '');
            $('#mg-ai-redesign-product-id').val(productId);
            $('#mg-ai-redesign-product-name').text($(this).data('productTitle') || '');
            $('#mg-ai-redesign-original').attr('src', '').hide();
            $('#mg-ai-redesign-original-error, #mg-ai-redesign-candidates').empty();
            $('.mg-ai-redesign__bulk').prop('hidden', true);
            $('#mg-ai-redesign-text, #mg-ai-redesign-hint, #mg-ai-redesign-custom-prompt').val('');
            $status().text('');
            if (productId) {
                loadInfo();
            }
        });

        $(document).on('click', '#mg-ai-redesign-submit', function () {
            if (!productId || running) {
                return;
            }
            setRunning(true);
            pollFailures = 0;
            $status().text(stageLabels.prepare);
            const pid = productId;
            post('mg_ai_redesign_generate', {
                analyzer: $('#mg-ai-redesign-analyzer').val() || '',
                intensity: $('#mg-ai-redesign-intensity').val() || 'strong',
                prompt_mode: $('#mg-ai-redesign-mode').val() || 'brief',
                quality: $('#mg-ai-redesign-quality').val() || 'medium',
                resolution: $('#mg-ai-redesign-resolution').val() || 'economy',
                design_text: $('#mg-ai-redesign-text').val() || '',
                style_hint: $('#mg-ai-redesign-hint').val() || '',
                custom_prompt: $('#mg-ai-redesign-custom-prompt').val() || '',
            }).done(function (resp) {
                if (pid !== productId) {
                    return;
                }
                if (resp && resp.data && resp.data.status === 'running') {
                    return; // már fut egy generálás, a lekérdezés követi
                }
                handleState(resp && resp.data ? resp.data : { status: 'error', message: responseMessage(resp) });
            }).fail(function (xhr) {
                // Proxy / időkorlát (nem JSON válasz): a szerver tovább dolgozik,
                // az állapotot a lekérdezés követi. JSON hiba esetén megállunk.
                if (pid === productId && xhr && xhr.responseJSON && xhr.responseJSON.data) {
                    handleState({ status: 'error', message: xhr.responseJSON.data.message || ('HTTP ' + xhr.status) });
                }
            });
            poll();
        });

        // AI felskálázás a böngészőben (assets/js/ai-upscale.js, a beállított modellel), majd feltöltés.
        function runUpscale(attachmentId, url) {
            if (upscaling || !window.MGAIUpscale || !url) {
                return;
            }
            upscaling = true;
            const pid = productId;
            $('.mg-ai-redesign-upscale, .mg-ai-redesign-apply').prop('disabled', true);
            $status().text('A szélek előkészítése a felskálázáshoz…');
            // Edge cleanup, binary alpha and crop on the server first (Design-flow input).
            Promise.resolve(post('mg_ai_redesign_prepare', { attachment_id: attachmentId })).then(function (prepared) {
                if (!prepared || !prepared.success || !prepared.data || !prepared.data.full) {
                    throw new Error((prepared && prepared.data && prepared.data.message) || 'az előkészítés nem sikerült.');
                }
                // 3× (Real-ESRGAN: 4×) or 2× by its size; a large one (above 2560 px) needs none.
                const factor = prepared.data.upscale_factor || 0;
                if (factor < 2) {
                    return null;
                }
                $status().text('AI felskálázás indul (első alkalommal a modell betöltése is pár másodperc)…');
                return window.MGAIUpscale.upscale(prepared.data.full, function (p) {
                    if (pid === productId) {
                        $status().text('AI felskálázás ' + factor + '×: ' + Math.round(p * 100) + '% – ne zárd be az ablakot.');
                    }
                }, factor, prepared.data.upscale_engine, prepared.data.palette_finish);
            }).then(function (blob) {
                if (!blob) {
                    return { success: true, native: true };
                }
                const cfg = window.MG_AJAX || {};
                const form = new FormData();
                form.append('action', 'mg_ai_redesign_upscaled');
                form.append('nonce', cfg.nonce);
                form.append('product_id', pid);
                form.append('attachment_id', attachmentId);
                form.append('upscaled', blob, 'upscaled.png');
                $status().text('A felskálázott PNG feltöltése…');
                return $.ajax({ url: cfg.ajax_url, method: 'POST', data: form, processData: false, contentType: false, dataType: 'json' });
            }).then(function (resp) {
                if (pid !== productId) {
                    return;
                }
                if (resp && resp.native) {
                    $status().text('A minta elég nagy, nem kell felskálázni. Nézd meg teljes méretben, és ha jó, hagyd jóvá.');
                } else if (resp && resp.success) {
                    $status().text('AI felskálázás kész. Nézd meg teljes méretben, és ha jó, hagyd jóvá.');
                } else {
                    $status().text('AI felskálázás hiba: ' + (responseMessage(resp) || 'ismeretlen') + ' Jóváhagyáskor sima nagyítás lesz (ha kell).');
                }
            }, function (err) {
                if (pid !== productId) {
                    return;
                }
                const msg = (err && err.message) || (err && err.status === 413 ? 'a fájl nagyobb a szerver feltöltési korlátjánál (HTTP 413).' : 'a kérés nem sikerült.');
                $status().text('AI felskálázás hiba: ' + msg + ' Jóváhagyáskor sima nagyítás lesz (ha kell).');
            }).finally(function () {
                upscaling = false;
                if (pid === productId) {
                    loadInfo();
                }
            });
        }

        $(document).on('click', '.mg-ai-redesign-upscale', function () {
            runUpscale(String($(this).attr('data-attachment-id')), $(this).data('url'));
        });

        $(document).on('click', '.mg-ai-redesign-apply', function () {
            const $btn = $(this);
            if (upscaling) {
                return;
            }
            const notUpscaled = $btn.closest('.mg-ai-redesign__candidate').attr('data-upscale') === 'none';
            if (!window.confirm('Ez a minta kerül a termékre, és az összes mockup újragenerálódik. A többi jelölt törlődik.'
                + (notUpscaled ? ' Ez a minta még nincs AI-val felskálázva, ezért sima nagyítást kap (ha kell).' : '') + ' Folytatod?')) {
                return;
            }
            $btn.prop('disabled', true);
            post('mg_ai_redesign_apply', { attachment_id: $btn.attr('data-attachment-id') }).done(function (resp) {
                if (resp && resp.success) {
                    $('#mg-ai-redesign-candidates').empty();
                    $status().text(responseMessage(resp) || 'Sorba állítva.');
                } else {
                    $btn.prop('disabled', false);
                    $status().text('Hiba: ' + (responseMessage(resp) || 'ismeretlen'));
                }
            }).fail(function () {
                $btn.prop('disabled', false);
                $status().text('Hiba: a kérés nem sikerült.');
            });
        });

        function discard(data, question) {
            if (upscaling) {
                $status().text('Várd meg, amíg a felskálázás befejeződik.');
                return;
            }
            if (!window.confirm(question)) {
                return;
            }
            $('.mg-ai-redesign-discard, #mg-ai-redesign-discard-all').prop('disabled', true);
            post('mg_ai_redesign_discard', data).done(function (resp) {
                $status().text(resp && resp.success ? (responseMessage(resp) || 'Törölve.') : 'Hiba: ' + (responseMessage(resp) || 'ismeretlen'));
            }).fail(function () {
                $status().text('Hiba: a törlés nem sikerült.');
            }).always(function () {
                $('#mg-ai-redesign-discard-all').prop('disabled', false);
                loadInfo();
            });
        }

        // A „részletes leírás” mód pontos leírást kér, ott a fokozat nem számít.
        $(document).on('change', '#mg-ai-redesign-mode', function () {
            $('#mg-ai-redesign-intensity').prop('disabled', $(this).val() === 'describe');
        });

        $(document).on('click', '.mg-ai-redesign-discard', function () {
            discard({ attachment_id: $(this).attr('data-attachment-id') }, 'Törlöd ezt az AI mintát? A PNG a médiatárból is törlődik.');
        });

        $(document).on('click', '#mg-ai-redesign-discard-all', function () {
            discard({ all: 1 }, 'Törlöd az összes AI jelöltet ennél a terméknél? A PNG-k a médiatárból is törlődnek. Az eredeti minta megmarad.');
        });

        $(document).on('click', '.mg-ai-redesign-edit-prompt', function () {
            $('#mg-ai-redesign-custom-prompt').val($(this).data('prompt') || '');
            $('.mg-ai-redesign__prompt').prop('open', true);
            document.getElementById('mg-ai-redesign-custom-prompt').focus();
        });
    }

    // Client-side section tabs of the product type editor. All panels stay in
    // the DOM (the single form submits every field), only visibility changes.
    function initProductEditorTabs() {
        const $tabs = $('.mg-pst-tab');
        if (!$tabs.length) {
            return;
        }
        $tabs.on('click', function () {
            const target = $(this).data('pst');
            if (!target) {
                return;
            }
            $('.mg-pst-tab').removeClass('is-active');
            $(this).addClass('is-active');
            $('.mg-pst-panel').removeClass('is-active').filter('[data-pst-panel="' + target + '"]').addClass('is-active');
            // Nudge TinyMCE / lazy widgets after becoming visible.
            try {
                window.dispatchEvent(new Event('resize'));
            } catch (err) {
                // ignore
            }
        });
    }
})(jQuery);
