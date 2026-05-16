/**
 * MG Cross-sell – AJAX Kosárba adás
 * Ugyanaz a WC termék, más mg_product_type (bogre, parna stb.)
 */
(function ($) {
    'use strict';

    var cfg     = window.MG_Crosssell || {};
    var ajaxUrl = cfg.ajax_url || '';
    var nonce   = cfg.nonce   || '';
    var i18n    = cfg.i18n    || {};

    $(document).on('click', '.mg-crosssell-btn--add', function (e) {
        e.preventDefault();

        var $btn       = $(this);
        var targetType = $btn.data('target-type');   // mg_product_type slug (pl. "bogre")
        var sourceKey  = $btn.data('source-key');    // forrás cart item key
        var ruleId     = $btn.data('rule-id');

        if ($btn.hasClass('mg-crosssell-btn--adding') || $btn.hasClass('mg-crosssell-btn--added')) {
            return;
        }

        // Loading állapot
        $btn.addClass('mg-crosssell-btn--adding')
            .removeClass('mg-crosssell-btn--add')
            .text(i18n.adding || 'Hozzáadás...')
            .prop('disabled', true);

        $.ajax({
            url:    ajaxUrl,
            method: 'POST',
            data: {
                action:               'mg_crosssell_add',
                nonce:                nonce,
                target_type:          targetType,
                source_cart_item_key: sourceKey,
                rule_id:              ruleId,
            },
            success: function (response) {
                if (response.success) {
                    $btn.removeClass('mg-crosssell-btn--adding')
                        .addClass('mg-crosssell-btn--added')
                        .text(i18n.added || '✓ Kosárban van')
                        .prop('disabled', true);

                    // WC kosár fragment frissítése
                    $(document.body).trigger('wc_fragment_refresh');
                    $(document.body).trigger('added_to_cart', [response.data]);

                } else {
                    var alreadyAdded = response.data && response.data.already_added;

                    if (alreadyAdded) {
                        $btn.removeClass('mg-crosssell-btn--adding')
                            .addClass('mg-crosssell-btn--added')
                            .text(i18n.already || '✓ Már a kosárban')
                            .prop('disabled', true);
                    } else {
                        $btn.removeClass('mg-crosssell-btn--adding')
                            .addClass('mg-crosssell-btn--add')
                            .text(i18n.error || 'Hiba')
                            .prop('disabled', false);

                        setTimeout(function () {
                            $btn.text('+ Kosárba');
                        }, 2000);
                    }
                }
            },
            error: function () {
                $btn.removeClass('mg-crosssell-btn--adding')
                    .addClass('mg-crosssell-btn--add')
                    .text(i18n.error || 'Hiba')
                    .prop('disabled', false);

                setTimeout(function () {
                    $btn.text('+ Kosárba');
                }, 2000);
            }
        });
    });

})(jQuery);
