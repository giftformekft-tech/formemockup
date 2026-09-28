<?php
if (!defined('ABSPATH')) {
    exit;
}

class MG_Order_Attribution_Page {
    public static function init() {
        add_action('admin_enqueue_scripts', array(__CLASS__, 'enqueue_assets'));
    }

    public static function enqueue_assets() {
        if (!current_user_can('manage_woocommerce') || ($_GET['page'] ?? '') !== 'mockup-generator' || ($_GET['mg_tab'] ?? '') !== 'order_attribution') {
            return;
        }
        wp_enqueue_style('mg-order-attribution', plugins_url('../assets/css/order-attribution.css', __FILE__), array('mg-admin-ui'), MG_VERSION);
        wp_enqueue_script('mg-order-attribution', plugins_url('../assets/js/order-attribution.js', __FILE__), array(), MG_VERSION, true);
        wp_localize_script('mg-order-attribution', 'MG_ORDER_ATTRIBUTION', array(
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('mg_order_attribution_report'),
            'today' => wp_date('Y-m-d'),
            'currency' => get_woocommerce_currency(),
        ));
    }

    public static function render_page() {
        if (!current_user_can('manage_woocommerce')) {
            wp_die('Nincs jogosultságod a rendelésstatisztikához.');
        }
        ?>
        <div id="mg-order-attribution" class="mg-attribution" data-mg-readonly>
            <div class="mg-attribution-heading">
                <div><h2>Honnan érkeznek a vásárlások?</h2><p>Platformok és kampányok a rendelésekhez mentett WooCommerce-forrásadatok alapján.</p></div>
                <span class="mg-attribution-badge">Rendelésforrás-statisztika</span>
            </div>
            <form id="mg-attribution-form" class="mg-attribution-filters">
                <label>Időszak<select id="mg-attribution-preset">
                    <option value="today">Ma</option><option value="yesterday">Tegnap</option>
                    <option value="7">Utolsó 7 nap</option><option value="30" selected>Utolsó 30 nap</option>
                    <option value="this_month">Ez a hónap</option><option value="last_month">Előző hónap</option>
                    <option value="this_year">Ez az év</option><option value="last_year">Előző év</option><option value="custom">Egyéni időszak</option>
                </select></label>
                <label>Ettől<input id="mg-attribution-from" type="date" required></label>
                <label>Eddig<input id="mg-attribution-to" type="date" required></label>
                <button class="button button-primary" type="submit">Lekérdezés / frissítés</button>
            </form>
            <div class="mg-attribution-filters mg-attribution-view-filters">
                <label>Platform<select id="mg-attribution-platform" disabled><option value="">Összes platform</option></select></label>
                <label>Kampány<select id="mg-attribution-campaign" disabled><option value="">Összes kampány</option></select></label>
                <label>Forgalom típusa<select id="mg-attribution-channel" disabled>
                    <option value="">Minden forgalom</option><option value="paid">Fizetett hirdetés (UTM alapján)</option>
                    <option value="organic">Organikus keresés</option><option value="referral">Hivatkozás / közösségi</option>
                    <option value="email">E-mail</option><option value="direct">Közvetlen</option><option value="unknown">Ismeretlen</option>
                    <option value="other">Egyéb címkézett</option><option value="admin">Adminban létrehozott</option>
                    <option value="pos">Bolti pénztár</option><option value="mobile_app">Mobilalkalmazás</option>
                </select></label>
                <label>Bontás<select id="mg-attribution-group" disabled><option value="day">Napi</option><option value="week">Heti</option><option value="month">Havi</option><option value="year">Éves</option></select></label>
                <label>Pénznem<select id="mg-attribution-currency" disabled></select></label>
            </div>
            <div class="mg-attribution-status" role="status" aria-live="polite"><span id="mg-attribution-status">Betöltésre vár…</span> <button id="mg-attribution-retry" type="button" class="button" hidden>Betöltés folytatása</button></div>
            <div id="mg-attribution-results" hidden>
                <div class="mg-attribution-cards">
                    <div><span>Vásárlások</span><strong id="mg-attribution-orders">—</strong><small>Rendelések száma</small></div>
                    <div><span>Bevétel</span><strong id="mg-attribution-revenue">—</strong><small>Visszatérítések levonása után</small></div>
                    <div><span>Átlagos rendelésérték</span><strong id="mg-attribution-average">—</strong><small>A szűrt rendelések alapján</small></div>
                    <div><span>UTM-kampánnyal</span><strong id="mg-attribution-coverage">—</strong><small id="mg-attribution-coverage-detail"></small></div>
                </div>
                <section class="mg-attribution-section"><h3>Vásárlások időben</h3><p id="mg-attribution-period"></p><div id="mg-attribution-chart" class="mg-attribution-chart"></div></section>
                <section class="mg-attribution-section"><h3>Platformok</h3><p>Kattints egy platform nevére a hozzá tartozó kampányok szűréséhez.</p><div class="mg-attribution-table" id="mg-attribution-platforms"></div></section>
                <section class="mg-attribution-section"><h3>Kampányok</h3><div class="mg-attribution-table" id="mg-attribution-campaigns"></div></section>
                <section class="mg-attribution-section"><h3>Időszakos bontás</h3><div class="mg-attribution-table" id="mg-attribution-timeline"></div></section>
            </div>
            <details class="mg-attribution-explanation"><summary>Mit tartalmaz a kimutatás?</summary>
                <p>A Feldolgozás alatt, Gyártás alatt, Teljesítve és Visszatérítve állapotú rendeléseket számoljuk, továbbá a WooCommerce által kifizetettként kezelt egyedi állapotokat. A függő, sikertelen és lemondott rendelések kimaradnak. A vásárlások száma a visszatérített rendeléseket is tartalmazza.</p>
                <p>A bevétel a kedvezmények utáni bruttó rendelésérték szállítással együtt, a rögzített visszatérítések levonásával. A visszatérítés az eredeti rendelés időszakát módosítja. Eltérő pénznemeket külön mutatunk.</p>
                <p>A bontás a rendelés létrehozásának idejét és a webshop időzónáját használja. A hét hétfőn kezdődik. A régi, forrásadat nélküli rendeléseket Ismeretlen forrásként mutatjuk; kampányadatot nem pótolunk visszamenőleg.</p>
                <p>A Facebook vagy Google forrás önmagában nem bizonyít fizetett hirdetést. A Fizetett hirdetés szűrő az UTM-médiumot (például paid_social vagy cpc) használja. A WooCommerce munkamenethez kötött forrásadata eltérhet a hirdetéskezelő számaitól. Hirdetési költséget és megtérülést ez a kimutatás nem tartalmaz.</p>
            </details>
            <noscript><p>A statisztika megjelenítéséhez engedélyezd a JavaScriptet.</p></noscript>
        </div>
        <?php
    }
}
