/* Grafikus profilok admin: profilkép-választó és kötegelt szétosztás. */
(function ($) {
    'use strict';

    const cfg = window.MG_DESIGNERS || {};
    const i18n = cfg.i18n || {};

    function initAvatarField() {
        let frame = null;

        $(document).on('click', '.mg-designers__avatar-pick', function (event) {
            event.preventDefault();
            const $field = $(this).closest('.mg-designers__avatar-field');
            if (!window.wp || !wp.media) {
                return;
            }
            if (!frame) {
                frame = wp.media({
                    title: i18n.mediaTitle,
                    button: { text: i18n.mediaButton },
                    library: { type: 'image' },
                    multiple: false
                });
            }
            frame.off('select').on('select', function () {
                const attachment = frame.state().get('selection').first().toJSON();
                const sizes = attachment.sizes || {};
                const url = (sizes.thumbnail || sizes.medium || attachment).url;
                $field.find('input[name="avatar_id"]').val(attachment.id).trigger('change');
                $field.find('.mg-designers__avatar-preview').empty().append($('<img>', { src: url, alt: '' }));
                $field.find('.mg-designers__avatar-remove').prop('hidden', false);
            });
            frame.open();
        });

        $(document).on('click', '.mg-designers__avatar-remove', function (event) {
            event.preventDefault();
            const $field = $(this).closest('.mg-designers__avatar-field');
            $field.find('input[name="avatar_id"]').val('').trigger('change');
            $field.find('.mg-designers__avatar-preview').empty();
            $(this).prop('hidden', true);
        });
    }

    function initDeleteConfirm() {
        $(document).on('click', '.mg-designers__delete', function (event) {
            const message = $(this).data('confirm');
            if (message && !window.confirm(message)) {
                event.preventDefault();
            }
        });
    }

    function updateStats(stats) {
        if (!stats) {
            return;
        }
        Object.keys(stats).forEach(function (key) {
            $('[data-mg-designer-stat="' + key + '"] strong').text(Number(stats[key]).toLocaleString('hu-HU'));
        });
    }

    function initDistribution() {
        const $section = $('.mg-designers__distribute');
        if (!$section.length) {
            return;
        }
        const $buttons = $section.find('[data-mg-designer-run]');
        const $progress = $section.find('.mg-designers__progress');
        const $bar = $progress.find('.mg-designers__progress-bar span');
        const $text = $progress.find('.mg-designers__progress-text');

        function finish(processed, isError, message) {
            $buttons.each(function () {
                $(this).prop('disabled', $(this).data('wasDisabled'));
            });
            $progress.toggleClass('is-error', !!isError);
            if (!isError) {
                $bar.css('width', '100%');
            }
            $text.text(message || (i18n.done || '').replace('%d', processed.toLocaleString('hu-HU')));
        }

        function run(mode) {
            let total = 0;
            let processed = 0;

            $buttons.each(function () {
                $(this).data('wasDisabled', $(this).prop('disabled')).prop('disabled', true);
            });
            $progress.prop('hidden', false).removeClass('is-error');
            $bar.css('width', '0%');
            $text.text(i18n.running);

            function step(cursor) {
                $.post(cfg.ajaxUrl, {
                    action: 'mg_designer_distribute',
                    nonce: cfg.nonce,
                    mode: mode,
                    cursor: cursor
                }).done(function (response) {
                    if (!response || !response.success) {
                        const msg = response && response.data && response.data.message;
                        finish(processed, true, msg || i18n.error);
                        return;
                    }
                    const data = response.data;
                    if (data.total !== null && data.total !== undefined) {
                        total = Number(data.total);
                    }
                    processed += Number(data.processed);
                    if (total > 0) {
                        $bar.css('width', Math.min(100, Math.round(processed / total * 100)) + '%');
                    }
                    $text.text(i18n.running + ' ' + processed.toLocaleString('hu-HU') + ' / ' + total.toLocaleString('hu-HU'));
                    if (data.done) {
                        updateStats(data.stats);
                        finish(processed, false);
                        // A profilonkénti mintaszámok a táblázatban csak újratöltéssel frissülnek.
                        window.setTimeout(function () {
                            const url = new URL(window.location.href);
                            url.searchParams.delete('mg_designer_notice');
                            window.location.assign(url.toString());
                        }, 1500);
                        return;
                    }
                    step(data.cursor);
                }).fail(function (xhr) {
                    const msg = xhr && xhr.responseJSON && xhr.responseJSON.data && xhr.responseJSON.data.message;
                    finish(processed, true, msg || i18n.error);
                });
            }

            step(0);
        }

        $buttons.on('click', function () {
            const mode = $(this).data('mgDesignerRun');
            if (mode === 'all' && !window.confirm(i18n.confirmAll)) {
                return;
            }
            if (mode === 'clear' && !window.confirm(i18n.confirmClear)) {
                return;
            }
            run(mode);
        });
    }

    $(function () {
        initAvatarField();
        initDeleteConfirm();
        initDistribution();
    });
})(jQuery);
