(function ($) {
    'use strict';

    const state = {
        active: null,
        framesLoaded: new Set(),
    };

    function activateTab(id) {
        if (!id) {
            return;
        }
        state.active = id;
        const $tabs = $('.mg-tab');
        const $panels = $('.mg-panel');
        $tabs.removeClass('is-active');
        $panels.removeClass('is-active');

        const $tab = $tabs.filter('[data-tab="' + id + '"]');
        const $panel = $('#tab-' + id);
        if ($tab.length) {
            $tab.addClass('is-active');
        }
        if ($panel.length) {
            $panel.addClass('is-active');
            const $frame = $panel.find('.mg-panel-frame');
            if ($frame.length) {
                const src = $frame.attr('data-src');
                if (src && !state.framesLoaded.has(src)) {
                    $frame.attr('src', src);
                    state.framesLoaded.add(src);
                }
            }
        }

        if (typeof window.history.replaceState === 'function') {
            const hash = '#' + id;
            if (window.location.hash !== hash) {
                window.history.replaceState(null, '', hash);
            }
        } else {
            window.location.hash = id;
        }
    }

    function determineInitialTab() {
        if (window.location.hash) {
            return window.location.hash.replace('#', '');
        }
        if (window.MG_ADMIN_UI && window.MG_ADMIN_UI.defaultTab) {
            return window.MG_ADMIN_UI.defaultTab;
        }
        return 'dashboard';
    }

    function handleMessages(event) {
        if (!event || !event.data) {
            return;
        }
        if (event.data === 'mg-dirty-on') {
            $('.mg-save-bar').addClass('is-visible');
        }
        if (event.data === 'mg-dirty-off') {
            $('.mg-save-bar').removeClass('is-visible');
        }
    }

    $(function () {
        const initial = determineInitialTab();
        activateTab(initial);

        $('.mg-tab').on('click', function () {
            activateTab($(this).data('tab'));
        });

        window.addEventListener('hashchange', function () {
            const id = window.location.hash.replace('#', '');
            if (id && id !== state.active) {
                activateTab(id);
            }
        });

        window.addEventListener('message', handleMessages);

        $('.mg-save-bar__submit').on('click', function () {
            $('.mg-save-bar').removeClass('is-visible');
            window.postMessage('mg-save-request', '*');
        });
    });
})(jQuery);
