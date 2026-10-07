<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * SEO és AI keresők beállítások: címsablonok, kategóriaoldal, cégadatok,
 * llms.txt, IndexNow, előnézet és robots.txt ellenőrzés.
 */
class MG_SEO_Settings_Page {
    const SLUG = 'mg-seo-settings';

    public static function init() {
        add_action('admin_menu', array(__CLASS__, 'add_submenu_page'));
        add_action('admin_post_mg_seo_settings_save', array(__CLASS__, 'handle_save'));
        add_action('admin_post_mg_seo_robots_check', array(__CLASS__, 'handle_robots_check'));
    }

    public static function add_submenu_page() {
        add_submenu_page(
            'mockup-generator',
            __('SEO és AI keresők', 'mockup-generator'),
            __('SEO és AI keresők', 'mockup-generator'),
            'manage_options',
            self::SLUG,
            array(__CLASS__, 'render_page')
        );
    }

    protected static function page_url($args = array()) {
        return add_query_arg(array_merge(array('page' => 'mockup-generator', 'mg_tab' => 'seo'), $args), admin_url('admin.php'));
    }

    public static function handle_save() {
        if (!current_user_can('manage_options')) {
            wp_die('Unauthorized');
        }
        check_admin_referer('mg_seo_settings_save_action');
        $input = isset($_POST['mg_seo_settings']) && is_array($_POST['mg_seo_settings']) ? $_POST['mg_seo_settings'] : array();
        MG_SEO_Meta::save_settings($input);

        $contact = isset($_POST['mg_seo_contact']) && is_array($_POST['mg_seo_contact']) ? wp_unslash($_POST['mg_seo_contact']) : array();
        update_option('mg_seo_contact', array(
            'email' => sanitize_email($contact['email'] ?? ''),
            'phone' => sanitize_text_field($contact['phone'] ?? ''),
        ));

        if (class_exists('MG_IndexNow')) {
            MG_IndexNow::save_settings(!empty($_POST['mg_indexnow_enabled']));
        }
        wp_safe_redirect(self::page_url(array('updated' => 1)));
        exit;
    }

    public static function handle_robots_check() {
        if (!current_user_can('manage_options')) {
            wp_die('Unauthorized');
        }
        check_admin_referer('mg_seo_robots_check_action');
        MG_SEO_AI_Visibility::get_robots_report(true);
        wp_safe_redirect(self::page_url(array('robots_checked' => 1)) . '#mg-seo-ai');
        exit;
    }

    protected static function checkbox($name, $checked, $label, $help = '') {
        echo '<label><input type="checkbox" name="mg_seo_settings[' . esc_attr($name) . ']" value="1"' . checked((bool) $checked, true, false) . ' /> ' . esc_html($label) . '</label>';
        if ($help !== '') {
            echo '<p class="description">' . esc_html($help) . '</p>';
        }
    }

    protected static function text($name, $value, $help = '', $class = 'large-text') {
        echo '<input type="text" name="mg_seo_settings[' . esc_attr($name) . ']" value="' . esc_attr($value) . '" class="' . esc_attr($class) . '" />';
        if ($help !== '') {
            echo '<p class="description">' . esc_html($help) . '</p>';
        }
    }

    protected static function textarea($name, $value, $rows = 3, $help = '') {
        echo '<textarea name="mg_seo_settings[' . esc_attr($name) . ']" rows="' . (int) $rows . '" class="large-text">' . esc_textarea($value) . '</textarea>';
        if ($help !== '') {
            echo '<p class="description">' . esc_html($help) . '</p>';
        }
    }

    protected static function row($label, $callback) {
        echo '<tr><th scope="row">' . esc_html($label) . '</th><td>';
        call_user_func($callback);
        echo '</td></tr>';
    }

    public static function render_page() {
        if (!current_user_can('manage_options')) {
            return;
        }
        $s = MG_SEO_Meta::get_settings();
        $contact = get_option('mg_seo_contact', array('email' => '', 'phone' => ''));
        $contact = is_array($contact) ? $contact : array();
        $indexnow = class_exists('MG_IndexNow') ? MG_IndexNow::get_settings() : array('enabled' => 0, 'key' => '');
        $seo_plugin = MG_SEO_Meta::detect_seo_plugin();
        ?>
        <div class="wrap mg-seo-settings">
            <h1><?php esc_html_e('SEO és AI keresők', 'mockup-generator'); ?></h1>
            <?php if (isset($_GET['updated'])): ?>
                <div class="notice notice-success is-dismissible"><p><?php esc_html_e('Beállítások elmentve. A LiteSpeed/Cloudflare gyorsítótárat érdemes üríteni, hogy a változás a látogatóknál is megjelenjen.', 'mockup-generator'); ?></p></div>
            <?php endif; ?>
            <?php if ($seo_plugin !== ''): ?>
                <div class="notice notice-warning"><p><?php echo esc_html(sprintf(__('Aktív SEO bővítmény: %s. A fejléc-kimenet (cím, meta leírás, Open Graph, canonical, breadcrumb) ezért kimarad; a kategóriaoldal H1-e, alsó szövege és GYIK-je továbbra is megjelenik.', 'mockup-generator'), $seo_plugin)); ?></p></div>
            <?php endif; ?>

            <p style="max-width:900px"><?php esc_html_e('A termékoldalak a virtuális típusrendszert mutatják a vevőknek, a keresőknek és a feedeknek is: minden típus (pl. Férfi póló, Bögre) saját URL-t, „Név - Típus” nevet, árat és képet kap. Az alap termék-URL kanonikusa az alapértelmezett típus URL-je, a sitemap is ezt adja.', 'mockup-generator'); ?></p>

            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                <input type="hidden" name="action" value="mg_seo_settings_save" />
                <?php wp_nonce_field('mg_seo_settings_save_action'); ?>

                <h2><?php esc_html_e('Általános', 'mockup-generator'); ?></h2>
                <table class="form-table" role="presentation">
                    <?php
                    self::row(__('SEO modul', 'mockup-generator'), function () use ($s) {
                        self::checkbox('enabled', $s['enabled'], __('Bekapcsolva', 'mockup-generator'));
                    });
                    self::row(__('Oldalnév a címekben', 'mockup-generator'), function () use ($s) {
                        self::text('site_name', $s['site_name'], sprintf(__('A <title> végére kerül, pl. „… | Forme.hu”. (A WordPress webhelynév most: „%s”.)', 'mockup-generator'), get_bloginfo('name')), 'regular-text');
                    });
                    self::row(__('Elválasztó', 'mockup-generator'), function () use ($s) {
                        echo '<select name="mg_seo_settings[separator]">';
                        foreach (array('|', '–', '-', '·', '•') as $separator) {
                            echo '<option value="' . esc_attr($separator) . '"' . selected($s['separator'], $separator, false) . '>' . esc_html($separator) . '</option>';
                        }
                        echo '</select>';
                    });
                    self::row(__('Márkanév', 'mockup-generator'), function () use ($s) {
                        self::text('brand_name', $s['brand_name'], sprintf(__('A termékséma, az Open Graph és mindhárom termékfeed (Google, Facebook, egyedi/ChatGPT) márkája. Üresen a webhely neve: „%s”, ahogy a feedek eddig is küldték.', 'mockup-generator'), get_bloginfo('name')), 'regular-text');
                    });
                    self::row(__('Előnyök (rövid)', 'mockup-generator'), function () use ($s) {
                        self::text('usp', $s['usp'], __('A {elonyok} helyőrző tartalma a leírásokban. Csak valós állítást írj.', 'mockup-generator'));
                    });
                    ?>
                </table>

                <h2><?php esc_html_e('Termékoldalak (virtuális típusok)', 'mockup-generator'); ?></h2>
                <table class="form-table" role="presentation">
                    <?php
                    self::row(__('Cím sablon', 'mockup-generator'), function () use ($s) {
                        self::text('product_title_template', $s['product_title_template'], __('Helyőrzők: {termek}, {tipus}, {tipus_kisbetu}, {kategoria}, {oldal}. A „|” szakaszhatár: az üres szakasz kimarad, 70 karakter fölött előbb az oldalnév, majd a kategória esik ki. A {kategoria} a termék kategóriájának „Kifejezés a termékoldalak címében” mezője vagy neve; ha a terméknév már tartalmazza, nem ismétlődik.', 'mockup-generator'));
                    });
                    self::row(__('Meta leírás sablon', 'mockup-generator'), function () use ($s) {
                        self::textarea('product_description_template', $s['product_description_template'], 2, __('Helyőrzők: {termek}, {tipus}, {kategoria}, {minta_leiras}, {elonyok}, {oldal}. A {minta_leiras} a minta AI SEO szövegéből (_mg_sample_seo) vagy a kategória leírásából annyi mondat, amennyi 160 karakterbe fér.', 'mockup-generator'));
                    });
                    self::row(__('Kanonikus URL', 'mockup-generator'), function () use ($s) {
                        self::checkbox('consolidate_base_url', $s['consolidate_base_url'], __('Az alap /termek/minta/ URL kanonikusa és sitemap-bejegyzése az alapértelmezett típus URL-je legyen', 'mockup-generator'), __('Az alap URL ugyanazt mutatja, mint az alapértelmezett típusé; a lista, a feedek és a termékséma eddig is a típusos URL-t használta. Így nincs két indexelhető példány.', 'mockup-generator'));
                    });
                    self::row(__('Közösségi megosztás', 'mockup-generator'), function () use ($s) {
                        self::checkbox('og_tags', $s['og_tags'], __('Open Graph és Twitter tagek (Facebook, Messenger, Viber előnézet; típusos kép és ár)', 'mockup-generator'));
                    });
                    self::row(__('Breadcrumb séma', 'mockup-generator'), function () use ($s) {
                        self::checkbox('breadcrumb_schema', $s['breadcrumb_schema'], __('BreadcrumbList a termék- és kategóriaoldalakon', 'mockup-generator'), __('Ha később az Astra látható breadcrumbját bekapcsolod, ezt kapcsold ki, hogy ne legyen kettő.', 'mockup-generator'));
                    });
                    ?>
                </table>

                <h2><?php esc_html_e('Kategóriaoldalak', 'mockup-generator'); ?></h2>
                <p class="description"><?php esc_html_e('Kategóriánként a Termékek → Kategóriák → szerkesztés alatt adhatsz egyedi címet, H1-et, meta leírást, alsó szöveget és GYIK-et. Ahol üres, ezek a sablonok érvényesek. A sablon felsorolásából kimarad az a szó, ami a kategória nevében már szerepel (a „Vicces” kategóriánál: „Vicces – egyedi pólók…”, nem „Vicces – vicces, egyedi pólók…”).', 'mockup-generator'); ?></p>
                <table class="form-table" role="presentation">
                    <?php
                    self::row(__('Cím sablon', 'mockup-generator'), function () use ($s) {
                        self::text('category_title_template', $s['category_title_template'], __('Helyőrzők: {kategoria}, {szulo}, {h1}, {db}, {oldal}. A 2. oldaltól „N. oldal” is bekerül.', 'mockup-generator'));
                    });
                    self::row(__('H1 sablon', 'mockup-generator'), function () use ($s) {
                        self::text('category_h1_template', $s['category_h1_template'], __('A kategóriaoldal látható főcíme. Helyőrzők: {kategoria}, {szulo} (szülőkategória), {db}. Üresen a kategória neve. Kategóriánként a H1 mezőben felülírható – a legfontosabb kategóriáknál érdemes kézzel megírni (pl. „Születésnapi pólók – …”).', 'mockup-generator'));
                    });
                    self::row(__('Meta leírás sablon', 'mockup-generator'), function () use ($s) {
                        self::textarea('category_description_template', $s['category_description_template'], 2, __('Akkor használt, ha a kategóriának nincs sem egyedi meta leírása, sem leírása. Helyőrzők: {kategoria}, {h1}, {db}, {elonyok}, {oldal}.', 'mockup-generator'));
                    });
                    self::row(__('H1', 'mockup-generator'), function () use ($s) {
                        self::checkbox('category_h1', $s['category_h1'], __('Látható H1 a kategóriaoldalon (a téma eddig elrejtette)', 'mockup-generator'));
                    });
                    self::row(__('Alsó tartalom', 'mockup-generator'), function () use ($s) {
                        self::checkbox('category_content', $s['category_content'], __('Alsó SEO szöveg és GYIK a terméklista alatt (csak az 1. oldalon)', 'mockup-generator'));
                    });
                    self::row(__('Címkeoldalak', 'mockup-generator'), function () use ($s) {
                        self::checkbox('noindex_tags', $s['noindex_tags'], __('A termékcímke-archívumok (product_tag) noindex-et kapnak és kimaradnak a sitemapből', 'mockup-generator'));
                    });
                    ?>
                </table>

                <h2><?php esc_html_e('Főoldal', 'mockup-generator'); ?></h2>
                <table class="form-table" role="presentation">
                    <?php
                    self::row(__('Cím', 'mockup-generator'), function () use ($s) {
                        self::text('home_title', $s['home_title'], __('Helyőrző: {oldal}.', 'mockup-generator'));
                    });
                    self::row(__('Meta leírás', 'mockup-generator'), function () use ($s) {
                        self::textarea('home_description', $s['home_description'], 3);
                    });
                    ?>
                </table>

                <h2><?php esc_html_e('Cégadatok (Organization séma)', 'mockup-generator'); ?></h2>
                <p class="description" style="max-width:900px"><?php esc_html_e('A főoldal Organization sémájába kerül. Egyezzen a láblécben és az Impresszumban szereplő adatokkal: az eltérő cégadat rontja a megbízhatóságot a Google és az AI-keresők szemében.', 'mockup-generator'); ?></p>
                <table class="form-table" role="presentation">
                    <?php
                    self::row(__('Cégnév', 'mockup-generator'), function () use ($s) {
                        self::text('legal_name', $s['legal_name'], '', 'regular-text');
                    });
                    self::row(__('Utca, házszám', 'mockup-generator'), function () use ($s) {
                        self::text('street', $s['street'], '', 'regular-text');
                    });
                    self::row(__('Irányítószám', 'mockup-generator'), function () use ($s) {
                        self::text('postal_code', $s['postal_code'], '', 'small-text');
                    });
                    self::row(__('Település', 'mockup-generator'), function () use ($s) {
                        self::text('city', $s['city'], '', 'regular-text');
                    });
                    self::row(__('Ország (ISO kód)', 'mockup-generator'), function () use ($s) {
                        self::text('country', $s['country'], '', 'small-text');
                    });
                    self::row(__('Adószám', 'mockup-generator'), function () use ($s) {
                        self::text('tax_id', $s['tax_id'], __('pl. 12345678-2-15', 'mockup-generator'), 'regular-text');
                    });
                    self::row(__('EU adószám', 'mockup-generator'), function () use ($s) {
                        self::text('vat_id', $s['vat_id'], __('pl. HU12345678', 'mockup-generator'), 'regular-text');
                    });
                    self::row(__('Kapcsolati e-mail', 'mockup-generator'), function () use ($contact) {
                        echo '<input type="email" name="mg_seo_contact[email]" value="' . esc_attr($contact['email'] ?? '') . '" class="regular-text" placeholder="' . esc_attr(get_bloginfo('admin_email')) . '" />';
                    });
                    self::row(__('Telefonszám', 'mockup-generator'), function () use ($contact) {
                        echo '<input type="text" name="mg_seo_contact[phone]" value="' . esc_attr($contact['phone'] ?? '') . '" class="regular-text" placeholder="+36301234567" />';
                    });
                    self::row(__('Közösségi profilok', 'mockup-generator'), function () use ($s) {
                        self::textarea('same_as', $s['same_as'], 4, __('Soronként egy URL (Facebook, Instagram, TikTok, YouTube, Árukereső…). Ezekből azonosítják a keresők és az AI-k, hogy ugyanarról a boltról van szó.', 'mockup-generator'));
                    });
                    ?>
                </table>

                <h2 id="mg-seo-ai-settings"><?php esc_html_e('AI keresők', 'mockup-generator'); ?></h2>
                <table class="form-table" role="presentation">
                    <?php
                    self::row('llms.txt', function () use ($s) {
                        self::checkbox('llms_txt', $s['llms_txt'], __('Bolt-összefoglaló a /llms.txt címen (kategóriák, fontos oldalak, kapcsolat)', 'mockup-generator'));
                        echo '<p class="description"><a href="' . esc_url(home_url('/llms.txt')) . '" target="_blank" rel="noopener">' . esc_html(home_url('/llms.txt')) . '</a></p>';
                    });
                    self::row(__('llms.txt összefoglaló', 'mockup-generator'), function () use ($s) {
                        self::textarea('llms_summary', $s['llms_summary'], 3, __('Üresen automatikus: webshopnév + terméktípusok + előnyök. 1–3 mondat arról, mit árul a bolt és miben különleges.', 'mockup-generator'));
                    });
                    if (class_exists('MG_IndexNow')) self::row('IndexNow', function () use ($indexnow) {
                        echo '<label><input type="checkbox" name="mg_indexnow_enabled" value="1"' . checked(!empty($indexnow['enabled']), true, false) . ' /> ' . esc_html__('Új és módosított termékek, kategóriák azonnali bejelentése a Bingnek (ChatGPT keresés, Copilot)', 'mockup-generator') . '</label>';
                        if (!empty($indexnow['key'])) {
                            echo '<p class="description">' . esc_html__('Kulcsfájl:', 'mockup-generator') . ' <a href="' . esc_url(MG_IndexNow::key_url($indexnow['key'])) . '" target="_blank" rel="noopener">' . esc_html(MG_IndexNow::key_url($indexnow['key'])) . '</a></p>';
                        }
                        $status = get_option(MG_IndexNow::STATUS, array());
                        if (is_array($status) && !empty($status['time'])) {
                            echo '<p class="description">' . esc_html(sprintf(
                                __('Utolsó küldés: %1$s – %2$d URL, HTTP %3$s %4$s', 'mockup-generator'),
                                wp_date('Y-m-d H:i', (int) $status['time']),
                                (int) $status['count'],
                                $status['code'] ? (int) $status['code'] : '–',
                                $status['message']
                            )) . '</p>';
                        }
                        $queue = get_option(MG_IndexNow::QUEUE, array());
                        echo '<p class="description">' . esc_html(sprintf(__('Várakozó URL-ek: %d', 'mockup-generator'), is_array($queue) ? count($queue) : 0)) . '</p>';
                    });
                    ?>
                </table>

                <?php submit_button(__('Mentés', 'mockup-generator')); ?>
            </form>

            <hr />
            <?php self::render_preview(); ?>
            <hr />
            <?php self::render_robots_report(); ?>
        </div>
        <?php
    }

    protected static function chars($text) {
        return '<span style="color:#646970">(' . (int) MG_SEO_Meta::length($text) . ')</span>';
    }

    protected static function render_preview() {
        echo '<h2>' . esc_html__('Előnézet', 'mockup-generator') . '</h2>';
        echo '<p class="description">' . esc_html__('A mentett beállításokkal így jelenik meg a 10 legnagyobb kategória és az 5 legutóbbi termék (alapértelmezett típussal). Zárójelben a karakterszám.', 'mockup-generator') . '</p>';

        $terms = get_terms(array('taxonomy' => 'product_cat', 'hide_empty' => true, 'orderby' => 'count', 'order' => 'DESC', 'number' => 10));
        if (!is_wp_error($terms) && $terms) {
            echo '<table class="widefat striped" style="max-width:1200px;margin-bottom:20px"><thead><tr><th>' . esc_html__('Kategória', 'mockup-generator') . '</th><th>Title</th><th>H1</th><th>' . esc_html__('Meta leírás', 'mockup-generator') . '</th></tr></thead><tbody>';
            foreach ($terms as $term) {
                $title = MG_SEO_Meta::build_term_title($term);
                $description = MG_SEO_Meta::build_term_description($term);
                $edit = get_edit_term_link($term->term_id, 'product_cat', 'product');
                $slug_warning = MG_SEO_Meta::looks_like_slug(MG_SEO_Meta::plain($term->name))
                    ? '<br /><span style="color:#b32d2e">' . esc_html__('⚠ slug-szerű név – nevezd át', 'mockup-generator') . '</span>'
                    : '';
                echo '<tr><td><a href="' . esc_url((string) $edit) . '">' . esc_html($term->name) . '</a> (' . (int) $term->count . ')' . $slug_warning . '</td>';
                echo '<td>' . esc_html($title) . ' ' . self::chars($title) . '</td>';
                echo '<td>' . esc_html(MG_SEO_Meta::get_term_h1($term)) . '</td>';
                echo '<td>' . esc_html($description) . ' ' . self::chars($description) . '</td></tr>';
            }
            echo '</tbody></table>';
        }

        $ids = get_posts(array('post_type' => 'product', 'post_status' => 'publish', 'posts_per_page' => 5, 'fields' => 'ids', 'orderby' => 'date', 'order' => 'DESC', 'no_found_rows' => true));
        if ($ids) {
            echo '<table class="widefat striped" style="max-width:1200px"><thead><tr><th>' . esc_html__('Termék', 'mockup-generator') . '</th><th>Title</th><th>' . esc_html__('Meta leírás', 'mockup-generator') . '</th><th>' . esc_html__('Kanonikus URL', 'mockup-generator') . '</th></tr></thead><tbody>';
            foreach ($ids as $id) {
                $product = wc_get_product($id);
                $ctx = $product ? MG_SEO_Meta::get_product_context($product, '') : null;
                if (!$ctx) {
                    continue;
                }
                $title = MG_SEO_Meta::build_product_title($ctx);
                $description = MG_SEO_Meta::build_product_description($ctx);
                $canonical = MG_SEO_Meta::get_product_canonical_url($product);
                echo '<tr><td><a href="' . esc_url((string) get_edit_post_link($id)) . '">' . esc_html($product->get_name()) . '</a></td>';
                echo '<td>' . esc_html($title) . ' ' . self::chars($title) . '</td>';
                echo '<td>' . esc_html($description) . ' ' . self::chars($description) . '</td>';
                echo '<td><a href="' . esc_url($canonical) . '" target="_blank" rel="noopener">' . esc_html($canonical) . '</a></td></tr>';
            }
            echo '</tbody></table>';
        }
    }

    protected static function render_robots_report() {
        $report = MG_SEO_AI_Visibility::get_robots_report();
        echo '<h2 id="mg-seo-ai">' . esc_html__('Kereső- és AI-botok hozzáférése (robots.txt)', 'mockup-generator') . '</h2>';
        echo '<p class="description" style="max-width:900px">' . esc_html__('Ha a ChatGPT keresés (OAI-SearchBot), a Perplexity vagy a Bing nem érheti el az oldalt, nem is ajánlhatja. A Cloudflare „AI Crawl Control” / „Block AI bots” beállítása a robots.txt-n kívül tűzfal-szinten is tilthat: ezt a Cloudflare felületén kell ellenőrizni (a keresőbotokat engedd, a tanító botokat tilthatod).', 'mockup-generator') . '</p>';

        if (!empty($report['error'])) {
            echo '<div class="notice notice-error inline"><p>' . esc_html(sprintf(__('A robots.txt nem olvasható: %s', 'mockup-generator'), $report['error'])) . '</p></div>';
        } elseif (isset($report['code']) && (int) $report['code'] === 404) {
            echo '<p>' . esc_html__('Nincs robots.txt (404): a robots.txt semmit nem tilt.', 'mockup-generator') . '</p>';
        } elseif (isset($report['code']) && (int) $report['code'] !== 200) {
            echo '<div class="notice notice-warning inline"><p>' . esc_html(sprintf(__('A robots.txt HTTP %d választ adott. 5xx esetén a botok átmenetileg az egész oldalt tiltottnak tekinthetik.', 'mockup-generator'), (int) $report['code'])) . '</p></div>';
        }

        if (!empty($report['bots'])) {
            $labels = array(
                'allowed' => array('✅', __('engedélyezve', 'mockup-generator')),
                'partial' => array('⚠️', __('részben tiltva', 'mockup-generator')),
                'blocked' => array('⛔', __('tiltva', 'mockup-generator')),
            );
            echo '<table class="widefat striped" style="max-width:1000px"><thead><tr><th>Bot</th><th>' . esc_html__('Mire való', 'mockup-generator') . '</th><th>' . esc_html__('Állapot', 'mockup-generator') . '</th></tr></thead><tbody>';
            foreach ($report['bots'] as $bot => $row) {
                $label = $labels[$row['status']];
                $detail = $row['status'] === 'partial' ? ' (' . implode(', ', $row['blocked']) . ')' : '';
                echo '<tr><td><code>' . esc_html($bot) . '</code></td><td>' . esc_html($row['purpose']) . '</td><td>' . esc_html($label[0] . ' ' . $label[1] . $detail) . '</td></tr>';
            }
            echo '</tbody></table>';
        }
        if (!empty($report['content_signals'])) {
            echo '<p><strong>' . esc_html__('Content-Signal (Cloudflare):', 'mockup-generator') . '</strong> <code>' . esc_html(implode(' | ', $report['content_signals'])) . '</code> – ' . esc_html__('a „search=yes” fontos, az „ai-train=no” a keresést nem érinti.', 'mockup-generator') . '</p>';
        }
        if (!empty($report['checked_at'])) {
            echo '<p class="description">' . esc_html(sprintf(__('Ellenőrizve: %s', 'mockup-generator'), wp_date('Y-m-d H:i', (int) $report['checked_at']))) . '</p>';
        }
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
        echo '<input type="hidden" name="action" value="mg_seo_robots_check" />';
        wp_nonce_field('mg_seo_robots_check_action');
        submit_button(__('Újraellenőrzés', 'mockup-generator'), 'secondary', 'submit', false);
        echo '</form>';
    }
}
