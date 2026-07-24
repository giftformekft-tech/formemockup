<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Egységes marketing-consent híd.
 *
 * A Google Ads és a Meta modul is az `mg_gads_consent` eseményre és cookie-ra
 * épül. A legtöbb bolt viszont olyan sütikezelőt használ, amely egyiket sem
 * állítja elő. Ilyenkor a Meta Pixel egyetlen eseményt sem küld el (minden
 * esemény `mgFbConsentGranted !== true` miatt eldobódik), a Google tag végig
 * cookie nélküli módban marad, a szerveroldali küldés pedig
 * `skipped_no_consent` állapotban áll meg – vagyis a vásárlások jelentős része
 * sosem jut el a hirdetési fiókokba.
 *
 * Ez az osztály lefordítja a bevett sütikezelők jelzését arra a formára, amit a
 * mérőmodulok már értenek – a böngészőben és a szerveren egyaránt.
 */
class MG_Consent_Bridge {
    const COOKIE = 'mg_gads_consent';

    public static function init() {
        // A gtag (1) és a Pixel (2) után fut, így a setterek már léteznek.
        add_action('wp_head', array(__CLASS__, 'output_bridge_script'), 3);
    }

    /** True, ha legalább az egyik hirdetési modul be van állítva. */
    private static function measurement_active() {
        $gads = get_option('mg_gads_settings', array());
        $meta = get_option('mg_fb_pixel_settings', array());
        return !empty($gads['conversion_id']) || !empty($meta['pixel_id']);
    }

    /**
     * Szerveroldali consent-feloldás.
     *
     * Elsődlegesen a saját cookie-nk számít (ezt a híd is szinkronban tartja),
     * hiányában a felismert sütikezelő állapotát olvassuk ki.
     *
     * @return string 'granted', 'denied' vagy '' ha nem eldönthető
     */
    public static function detect_server_consent() {
        $own = isset($_COOKIE[self::COOKIE]) ? sanitize_key(wp_unslash($_COOKIE[self::COOKIE])) : '';
        if (in_array($own, array('granted', 'denied'), true)) {
            return $own;
        }
        return self::detect_cmp_consent();
    }

    /** A támogatott sütikezelők marketing-kategóriájának kiolvasása. */
    public static function detect_cmp_consent() {
        // WP Consent API (Complianz, CookieYes, Cookie Notice és társai támogatják).
        foreach ($_COOKIE as $name => $value) {
            if (strpos((string) $name, 'wp_consent_marketing') === 0) {
                $value = sanitize_key(wp_unslash($value));
                if ($value === 'allow') {
                    return 'granted';
                }
                if ($value === 'deny') {
                    return 'denied';
                }
            }
        }

        // Complianz
        if (isset($_COOKIE['cmplz_marketing'])) {
            $value = sanitize_key(wp_unslash($_COOKIE['cmplz_marketing']));
            if ($value === 'allow') {
                return 'granted';
            }
            if ($value === 'deny') {
                return 'denied';
            }
        }

        // CookieYes – "consentid:...,advertisement:yes,..."
        if (isset($_COOKIE['cookieyes-consent'])) {
            $raw = (string) wp_unslash($_COOKIE['cookieyes-consent']);
            if (preg_match('/advertisement:(yes|no)/i', $raw, $match)) {
                return strtolower($match[1]) === 'yes' ? 'granted' : 'denied';
            }
        }

        // Cookiebot – "{stamp:...,marketing:true,...}"
        if (isset($_COOKIE['CookieConsent'])) {
            $raw = urldecode((string) wp_unslash($_COOKIE['CookieConsent']));
            if (preg_match('/marketing:(true|false)/i', $raw, $match)) {
                return strtolower($match[1]) === 'true' ? 'granted' : 'denied';
            }
        }

        // Borlabs Cookie – JSON, a marketing csoport nem üres tömb, ha elfogadták.
        if (isset($_COOKIE['borlabs-cookie'])) {
            $data = json_decode(urldecode((string) wp_unslash($_COOKIE['borlabs-cookie'])), true);
            if (is_array($data) && isset($data['consents'])) {
                return !empty($data['consents']['marketing']) ? 'granted' : 'denied';
            }
        }

        // Moove GDPR Cookie Compliance
        if (isset($_COOKIE['moove_gdpr_popup'])) {
            $data = json_decode(urldecode((string) wp_unslash($_COOKIE['moove_gdpr_popup'])), true);
            if (is_array($data) && (isset($data['thirdparty']) || isset($data['advanced']))) {
                $granted = ($data['thirdparty'] ?? '0') === '1' || ($data['advanced'] ?? '0') === '1';
                return $granted ? 'granted' : 'denied';
            }
        }

        // Iubenda – purposes.5 a marketing kategória.
        foreach ($_COOKIE as $name => $value) {
            if (strpos((string) $name, '_iub_cs-') !== 0) {
                continue;
            }
            $data = json_decode(urldecode((string) wp_unslash($value)), true);
            if (is_array($data) && isset($data['purposes']['5'])) {
                return !empty($data['purposes']['5']) ? 'granted' : 'denied';
            }
        }

        // Cookie Notice & Compliance – egy kategóriás, elfogadás = teljes hozzájárulás.
        if (isset($_COOKIE['cookie_notice_accepted'])) {
            return sanitize_key(wp_unslash($_COOKIE['cookie_notice_accepted'])) === 'true' ? 'granted' : 'denied';
        }

        return '';
    }

    /**
     * Böngészős híd: a felismert sütikezelő állapotát átfordítja az
     * `mg_gads_consent` cookie-ra és eseményre.
     */
    public static function output_bridge_script() {
        if (is_admin() || !self::measurement_active()) {
            return;
        }
        ?>
        <!-- Consent bridge – Mockup Generator -->
        <script>
        (function() {
            var last = null;

            function readCookie(name) {
                var match = document.cookie.match(new RegExp('(?:^|; )' + name.replace(/[.*+?^${}()|[\]\\-]/g, '\\$&') + '=([^;]*)'));
                return match ? decodeURIComponent(match[1]) : '';
            }

            function allCookieNames() {
                return document.cookie.split(';').map(function(part) { return part.split('=')[0].trim(); });
            }

            /** @return {boolean|null} null, ha nincs felismerhető sütikezelő. */
            function cmpState() {
                var names = allCookieNames();

                for (var i = 0; i < names.length; i++) {
                    if (names[i].indexOf('wp_consent_marketing') === 0) {
                        var wpValue = readCookie(names[i]);
                        if (wpValue === 'allow') return true;
                        if (wpValue === 'deny') return false;
                    }
                    if (names[i].indexOf('_iub_cs-') === 0) {
                        try {
                            var iub = JSON.parse(readCookie(names[i]));
                            if (iub && iub.purposes && typeof iub.purposes['5'] !== 'undefined') {
                                return !!iub.purposes['5'];
                            }
                        } catch (e) {}
                    }
                }

                if (window.wp_has_consent && typeof window.wp_has_consent === 'function') {
                    try { return !!window.wp_has_consent('marketing'); } catch (e) {}
                }

                var cmplz = readCookie('cmplz_marketing');
                if (cmplz === 'allow') return true;
                if (cmplz === 'deny') return false;

                var cky = readCookie('cookieyes-consent');
                if (cky) {
                    var ckyMatch = cky.match(/advertisement:(yes|no)/i);
                    if (ckyMatch) return ckyMatch[1].toLowerCase() === 'yes';
                }

                if (window.Cookiebot && window.Cookiebot.consent && typeof window.Cookiebot.consent.marketing !== 'undefined') {
                    return !!window.Cookiebot.consent.marketing;
                }
                var cbot = readCookie('CookieConsent');
                if (cbot) {
                    var cbotMatch = cbot.match(/marketing:(true|false)/i);
                    if (cbotMatch) return cbotMatch[1].toLowerCase() === 'true';
                }

                var borlabs = readCookie('borlabs-cookie');
                if (borlabs) {
                    try {
                        var parsed = JSON.parse(borlabs);
                        if (parsed && parsed.consents) {
                            return !!(parsed.consents.marketing && parsed.consents.marketing.length);
                        }
                    } catch (e) {}
                }

                var moove = readCookie('moove_gdpr_popup');
                if (moove) {
                    try {
                        var mooveData = JSON.parse(moove);
                        if (mooveData && (typeof mooveData.thirdparty !== 'undefined' || typeof mooveData.advanced !== 'undefined')) {
                            return mooveData.thirdparty === '1' || mooveData.advanced === '1';
                        }
                    } catch (e) {}
                }

                var notice = readCookie('cookie_notice_accepted');
                if (notice) return notice === 'true';

                return null;
            }

            function apply(granted, force) {
                if (granted === null || typeof granted === 'undefined') return;
                granted = !!granted;
                if (last === granted && !force) return;
                last = granted;

                var state = granted ? 'granted' : 'denied';
                document.cookie = 'mg_gads_consent=' + state + ';path=/;max-age=31536000;SameSite=Lax' + (location.protocol === 'https:' ? ';Secure' : '');
                if (typeof window.mgGadsSetConsent === 'function') window.mgGadsSetConsent(granted);
                if (typeof window.mgFbSetConsent === 'function') window.mgFbSetConsent(granted);

                var detail = {granted: granted, marketing: granted};
                try {
                    document.dispatchEvent(new CustomEvent('mg_gads_consent', {detail: detail}));
                } catch (e) {
                    var legacy = document.createEvent('CustomEvent');
                    legacy.initCustomEvent('mg_gads_consent', false, false, detail);
                    document.dispatchEvent(legacy);
                }
            }

            function sync() { apply(cmpState()); }

            // 1. Azonnali állapot (visszatérő látogató, korábban eldöntött consent).
            sync();

            // 2. Sütikezelő-specifikus események.
            document.addEventListener('wp_listen_for_consent_change', function(event) {
                var detail = (event && event.detail) || {};
                if (typeof detail.marketing !== 'undefined') {
                    apply(detail.marketing === 'allow');
                } else {
                    sync();
                }
            });
            document.addEventListener('cmplz_status_change', sync);
            document.addEventListener('cmplz_fire_categories', sync);
            document.addEventListener('cookieyes_consent_update', function(event) {
                var accepted = (event && event.detail && event.detail.accepted) || null;
                if (accepted && accepted.length) {
                    apply(accepted.indexOf('advertisement') !== -1);
                } else {
                    sync();
                }
            });
            document.addEventListener('borlabs-cookie-consent-saved', sync);
            window.addEventListener('CookiebotOnAccept', sync);
            window.addEventListener('CookiebotOnDecline', function() { apply(false); });

            // 3. GTM/Consent Mode: bármely más tag consent update-jét is elfogadjuk.
            try {
                window.dataLayer = window.dataLayer || [];
                var originalPush = window.dataLayer.push;
                window.dataLayer.push = function() {
                    var result = originalPush.apply(this, arguments);
                    try {
                        for (var i = 0; i < arguments.length; i++) {
                            var entry = arguments[i];
                            if (entry && entry[0] === 'consent' && entry[1] === 'update' && entry[2] && entry[2].ad_storage) {
                                apply(entry[2].ad_storage === 'granted');
                            }
                        }
                    } catch (e) {}
                    return result;
                };
            } catch (e) {}

            // 4. Biztonsági háló: a bannerek egy része csak cookie-t ír, eseményt nem.
            var ticks = 0;
            var timer = setInterval(function() {
                sync();
                if (++ticks >= 20) clearInterval(timer);
            }, 1000);
            document.addEventListener('click', function() { setTimeout(sync, 300); }, true);

            // 5. A body-ban kirenderelt eseménykezelők a fejlécben eldöntött
            //    consentről is értesüljenek.
            document.addEventListener('DOMContentLoaded', function() {
                if (last !== null) apply(last, true);
            });
        })();
        </script>
        <?php
    }
}
