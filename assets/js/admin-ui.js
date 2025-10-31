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
    });
})(jQuery);
