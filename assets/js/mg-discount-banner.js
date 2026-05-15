/**
 * MG Discount Banner – JS
 * Frissíti a termékoldali banner kosár-állapotát WooCommerce AJAX kosár változás után.
 */
(function ($) {
    'use strict';

    // WooCommerce cart fragment refresh után frissítjük a banner szövegét
    // (a tényleges banner újrarenderelése PHP-szinten történik, de a progress szöveg
    //  kliensoldali animációval frissíthető ha szükséges)
    $(document.body).on('wc_fragments_refreshed updated_cart_totals', function () {
        // Progress bar animáció a kosár banner elemekre
        $('.mg-discount-banner__bar').each(function () {
            var $bar   = $(this);
            var width  = $bar[0].style.width;
            // Rövid animáció trigger
            $bar.css('width', '0').animate({ width: width }, 500);
        });
    });

    // Termékoldali banner: ha változik a típus (virtuális variáns), frissíthető
    // A terméktípus változásakor az oldal nem töltődik újra, ezért a banner statikus marad
    // (a server-side PHP mindig az alapértelmezett típust mutatja)

})(jQuery);
