<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Admin felület a grafikus profilokhoz (Mockupok › Grafikusok fül).
 *
 *  - profilok listája, létrehozás / szerkesztés / törlés
 *  - szétosztás: a meglévő minták véletlen besorolása kötegelt AJAX-szal
 *  - beállítások: megjelenés az oldalon (alapból ki), automatikus besorolás,
 *    programoldal szövegei
 *
 * A panelen csak egy <form> van (a beállításoké, vagy szerkesztéskor a
 * profilé): a shell mentés-sávja mindig a panel első űrlapját küldi el, a
 * törlés és az oldal-létrehozás ezért nonce-os link, nem űrlap.
 */
class MG_Designer_Profiles_Page {

    const CAPABILITY = 'manage_woocommerce';
    const TAB = 'designers';
    const BATCH_SIZE = 200;

    public static function init() {
        add_action('admin_post_mg_designer_save', array(__CLASS__, 'handle_save_profile'));
        add_action('admin_post_mg_designer_delete', array(__CLASS__, 'handle_delete_profile'));
        add_action('admin_post_mg_designer_settings', array(__CLASS__, 'handle_save_settings'));
        add_action('admin_post_mg_designer_create_page', array(__CLASS__, 'handle_create_page'));
        add_action('wp_ajax_mg_designer_distribute', array(__CLASS__, 'ajax_distribute'));
        add_action('admin_enqueue_scripts', array(__CLASS__, 'enqueue_assets'));
    }

    public static function enqueue_assets($hook) {
        $page = isset($_GET['page']) ? sanitize_key(wp_unslash($_GET['page'])) : '';
        $tab = isset($_GET['mg_tab']) ? sanitize_key(wp_unslash($_GET['mg_tab'])) : '';
        if ($page !== 'mockup-generator' || $tab !== self::TAB) {
            return;
        }

        $base_file = dirname(__DIR__) . '/mockup-generator.php';
        wp_enqueue_media();
        wp_enqueue_style(
            'mg-designer-profiles-admin',
            plugins_url('assets/css/designer-profiles-admin.css', $base_file),
            array(),
            MG_VERSION
        );
        wp_enqueue_script(
            'mg-designer-profiles-admin',
            plugins_url('assets/js/designer-profiles-admin.js', $base_file),
            array('jquery'),
            MG_VERSION,
            true
        );
        wp_localize_script('mg-designer-profiles-admin', 'MG_DESIGNERS', array(
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonce'   => wp_create_nonce('mg_designer_distribute'),
            'i18n'    => array(
                'mediaTitle'   => __('Profilkép kiválasztása', 'mockup-generator'),
                'mediaButton'  => __('Kiválasztom', 'mockup-generator'),
                'running'      => __('Folyamatban…', 'mockup-generator'),
                'done'         => __('Kész: %d termék.', 'mockup-generator'),
                'skipped'      => __('%d termék grafikus nélkül maradt, mert minden profil betelt.', 'mockup-generator'),
                'unlimited'    => __('korlátlan', 'mockup-generator'),
                'error'        => __('Hiba történt, a folyamat megállt.', 'mockup-generator'),
                'confirmAll'   => __('Minden minta újrasorsolásra kerül, a mostani besorolások elvesznek. Mehet?', 'mockup-generator'),
                'confirmClear' => __('Minden mintáról lekerül a grafikus. Mehet?', 'mockup-generator'),
            ),
        ));
    }

    private static function tab_url($args = array()) {
        return add_query_arg(
            array_merge(array('page' => 'mockup-generator', 'mg_tab' => self::TAB), $args),
            admin_url('admin.php')
        );
    }

    private static function redirect($args = array()) {
        wp_safe_redirect(self::tab_url($args));
        exit;
    }

    /* ------------------------------------------------------------------ */
    /* Megjelenítés                                                        */
    /* ------------------------------------------------------------------ */

    public static function render_page() {
        if (!current_user_can(self::CAPABILITY)) {
            echo '<div class="notice notice-error"><p>' . esc_html__('Nincs jogosultságod a grafikus profilok kezeléséhez.', 'mockup-generator') . '</p></div>';
            return;
        }

        echo '<div class="mg-panel-body mg-panel-body--bulk mg-designers">';
        self::render_notices();

        $edit = isset($_GET['mg_designer_edit']) ? sanitize_key(wp_unslash($_GET['mg_designer_edit'])) : '';
        if ($edit !== '') {
            self::render_edit_form($edit === 'new' ? 0 : absint($edit));
        } else {
            self::render_status_banner();
            self::render_profiles_section();
            self::render_distribution_section();
            self::render_settings_form();
        }
        echo '</div>';
    }

    private static function render_notices() {
        $notice = isset($_GET['mg_designer_notice']) ? sanitize_key(wp_unslash($_GET['mg_designer_notice'])) : '';
        if ($notice === '') {
            return;
        }
        $messages = array(
            'created'       => array('success', __('Profil létrehozva.', 'mockup-generator')),
            'saved'         => array('success', __('Profil elmentve.', 'mockup-generator')),
            'deleted'       => array('success', __('Profil törölve. A mintái grafikus nélkül maradtak.', 'mockup-generator')),
            'settings'      => array('success', __('Beállítások elmentve.', 'mockup-generator')),
            'page_created'  => array('success', __('A programoldal létrejött.', 'mockup-generator')),
            'empty_name'    => array('error', __('A név nem lehet üres.', 'mockup-generator')),
            'exists'        => array('error', __('Már van ilyen nevű vagy slugú profil.', 'mockup-generator')),
            'save_failed'   => array('error', __('A profilt nem sikerült elmenteni.', 'mockup-generator')),
            'delete_failed' => array('error', __('A profilt nem sikerült törölni.', 'mockup-generator')),
            'page_failed'   => array('error', __('A programoldalt nem sikerült létrehozni.', 'mockup-generator')),
        );
        if (!isset($messages[$notice])) {
            return;
        }
        printf(
            '<div class="notice notice-%1$s mg-designers__notice"><p>%2$s</p></div>',
            esc_attr($messages[$notice][0]),
            esc_html($messages[$notice][1])
        );
    }

    private static function render_status_banner() {
        $enabled = MG_Designer_Profiles::is_display_enabled();
        echo '<div class="mg-designers__status ' . ($enabled ? 'is-on' : 'is-off') . '">';
        if ($enabled) {
            echo '<strong>' . esc_html__('Megjelenés az oldalon: BEKAPCSOLVA', 'mockup-generator') . '</strong> ';
            echo esc_html__('A látogatók látják a grafikus nevét a termékeknél, a profiloldalakat és a programoldalt.', 'mockup-generator');
        } else {
            echo '<strong>' . esc_html__('Megjelenés az oldalon: KIKAPCSOLVA', 'mockup-generator') . '</strong> ';
            echo esc_html__('A látogatók semmit nem látnak; a profilok és a kiosztás közben is szerkeszthetők, adminként a profiloldalak előnézetben megnyílnak.', 'mockup-generator');
        }
        echo ' <a href="#mg-designer-settings">' . esc_html__('Beállítások ↓', 'mockup-generator') . '</a>';
        echo '</div>';
    }

    private static function render_profiles_section() {
        $profiles = MG_Designer_Profiles::get_profiles();
        $counts = MG_Designer_Profiles::get_assignment_counts();

        echo '<section class="mg-panel-section">';
        echo '<div class="mg-panel-section__header mg-designers__header">';
        echo '<div><h2>' . esc_html__('Grafikus profilok', 'mockup-generator') . '</h2>';
        echo '<p>' . esc_html__('A profil neve a terméknév alatt jelenik meg, és a profiloldalra visz. Csak az aktív profilok kapnak mintát a véletlen kiosztásban, és csak a beállított maximumig.', 'mockup-generator') . '</p></div>';
        echo '<a class="button button-primary" href="' . esc_url(self::tab_url(array('mg_designer_edit' => 'new'))) . '">' . esc_html__('Új profil', 'mockup-generator') . '</a>';
        echo '</div>';

        if (empty($profiles)) {
            echo '<p class="mg-designers__empty">' . esc_html__('Még nincs profil. Hozd létre az első grafikust az „Új profil” gombbal.', 'mockup-generator') . '</p>';
            echo '</section>';
            return;
        }

        echo '<table class="widefat striped mg-designers__table">';
        echo '<thead><tr>';
        echo '<th class="column-avatar"><span class="screen-reader-text">' . esc_html__('Profilkép', 'mockup-generator') . '</span></th>';
        echo '<th>' . esc_html__('Név', 'mockup-generator') . '</th>';
        echo '<th>' . esc_html__('Profiloldal', 'mockup-generator') . '</th>';
        echo '<th class="num">' . esc_html__('Minták / max', 'mockup-generator') . '</th>';
        echo '<th>' . esc_html__('Kiosztásban', 'mockup-generator') . '</th>';
        echo '<th>' . esc_html__('Műveletek', 'mockup-generator') . '</th>';
        echo '</tr></thead><tbody>';

        foreach ($profiles as $term) {
            $tagline = (string) get_term_meta($term->term_id, MG_Designer_Profiles::META_TAGLINE, true);
            $active = MG_Designer_Profiles::is_profile_active($term->term_id);
            $link = get_term_link($term);
            $delete_url = wp_nonce_url(
                add_query_arg(array('action' => 'mg_designer_delete', 'term_id' => $term->term_id), admin_url('admin-post.php')),
                'mg_designer_delete_' . $term->term_id
            );

            echo '<tr>';
            echo '<td class="column-avatar">' . MG_Designer_Profiles::get_avatar_html($term) . '</td>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
            echo '<td><strong>' . esc_html($term->name) . '</strong>';
            if ($tagline !== '') {
                echo '<br><span class="description">' . esc_html($tagline) . '</span>';
            }
            echo '</td>';
            echo '<td><code>/' . esc_html(MG_Designer_Profiles::REWRITE_SLUG . '/' . $term->slug) . '/</code></td>';
            $count = isset($counts[$term->term_id]) ? $counts[$term->term_id] : 0;
            $max = MG_Designer_Profiles::get_profile_max($term->term_id);
            echo '<td class="num">' . esc_html(number_format_i18n($count)) . ' / ' . ($max > 0 ? esc_html(number_format_i18n($max)) : '∞');
            if ($max > 0 && $count >= $max) {
                echo ' <span class="mg-designers__badge is-full">' . esc_html__('Betelt', 'mockup-generator') . '</span>';
            }
            echo '</td>';
            echo '<td>' . ($active
                ? '<span class="mg-designers__badge is-on">' . esc_html__('Aktív', 'mockup-generator') . '</span>'
                : '<span class="mg-designers__badge">' . esc_html__('Szünetel', 'mockup-generator') . '</span>') . '</td>';
            echo '<td class="mg-designers__actions">';
            echo '<a href="' . esc_url(self::tab_url(array('mg_designer_edit' => $term->term_id))) . '">' . esc_html__('Szerkesztés', 'mockup-generator') . '</a>';
            if (!is_wp_error($link)) {
                echo ' | <a href="' . esc_url($link) . '" target="_blank" rel="noopener">' . esc_html__('Megnézem', 'mockup-generator') . '</a>';
            }
            echo ' | <a class="mg-designers__delete" href="' . esc_url($delete_url) . '" data-confirm="' . esc_attr(sprintf(
                /* translators: %s: profile name */
                __('Biztosan törlöd: %s? A mintái grafikus nélkül maradnak.', 'mockup-generator'),
                $term->name
            )) . '">' . esc_html__('Törlés', 'mockup-generator') . '</a>';
            echo '</td>';
            echo '</tr>';
        }
        echo '</tbody></table>';
        echo '</section>';
    }

    private static function render_distribution_section() {
        $stats = MG_Designer_Profiles::get_assignment_stats();
        $active = count(MG_Designer_Profiles::get_active_profile_ids());
        $free = MG_Designer_Profiles::get_free_capacity();

        echo '<section class="mg-panel-section mg-designers__distribute">';
        echo '<div class="mg-panel-section__header">';
        echo '<h2>' . esc_html__('Szétosztás', 'mockup-generator') . '</h2>';
        echo '<p>' . esc_html__('A minták teljesen véletlenszerűen kerülnek az aktív profilokhoz; a betelt profil (elérte a maximumát) kimarad. Az új termékek a bulk feltöltéskor automatikusan kapnak grafikust (a beállításoknál kikapcsolható). Ha minden profil betelt, a minta grafikus nélkül marad.', 'mockup-generator') . '</p>';
        echo '</div>';

        echo '<div class="mg-stats-grid">';
        self::render_stat('total', $stats['total'], __('Minta (termék) összesen', 'mockup-generator'));
        self::render_stat('assigned', $stats['assigned'], __('Van grafikusa', 'mockup-generator'));
        self::render_stat('missing', $stats['missing'], __('Nincs grafikusa', 'mockup-generator'));
        self::render_stat('active', $active, __('Aktív profil', 'mockup-generator'));
        self::render_stat('free', $free === null ? __('korlátlan', 'mockup-generator') : $free, __('Szabad hely a profiloknál', 'mockup-generator'));
        echo '</div>';

        $disabled = $active === 0 ? ' disabled' : '';
        echo '<div class="mg-designers__buttons">';
        echo '<button type="button" class="button button-primary" data-mg-designer-run="missing"' . $disabled . '>' . esc_html__('Grafikus nélküliek szétosztása', 'mockup-generator') . '</button>';
        echo '<button type="button" class="button" data-mg-designer-run="all"' . $disabled . '>' . esc_html__('Összes újrasorsolása', 'mockup-generator') . '</button>';
        echo '<button type="button" class="button button-link-delete" data-mg-designer-run="clear">' . esc_html__('Összes besorolás törlése', 'mockup-generator') . '</button>';
        echo '</div>';
        if ($active === 0) {
            echo '<p class="description">' . esc_html__('A szétosztáshoz legalább egy aktív profil kell.', 'mockup-generator') . '</p>';
        }

        echo '<div class="mg-designers__progress" hidden>';
        echo '<div class="mg-designers__progress-bar"><span></span></div>';
        echo '<p class="mg-designers__progress-text"></p>';
        echo '</div>';
        echo '</section>';
    }

    private static function render_stat($key, $value, $label) {
        printf(
            '<div class="mg-stat" data-mg-designer-stat="%1$s"><strong>%2$s</strong><span>%3$s</span></div>',
            esc_attr($key),
            esc_html(is_int($value) ? number_format_i18n($value) : $value),
            esc_html($label)
        );
    }

    private static function render_settings_form() {
        $s = MG_Designer_Profiles::get_settings();
        $page_id = MG_Designer_Profiles::get_program_page_id();

        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" id="mg-designer-settings" class="mg-designers__settings">';
        echo '<input type="hidden" name="action" value="mg_designer_settings" />';
        wp_nonce_field('mg_designer_settings');

        echo '<section class="mg-panel-section">';
        echo '<div class="mg-panel-section__header"><h2>' . esc_html__('Megjelenés', 'mockup-generator') . '</h2></div>';
        echo '<table class="form-table" role="presentation"><tbody>';

        echo '<tr><th scope="row">' . esc_html__('Megjelenés az oldalon', 'mockup-generator') . '</th><td>';
        self::checkbox('enabled', $s['enabled'], __('Bekapcsolva – a látogatók látják a grafikusokat', 'mockup-generator'));
        echo '<p class="description">' . esc_html__('Kikapcsolva se tervezősor, se profiloldal, se programoldal nem látszik (a profil- és a programoldal 404-et ad). Feedekbe, structured datába és sitemapbe semmilyen állapotban nem kerül.', 'mockup-generator') . '</p>';
        echo '</td></tr>';

        echo '<tr><th scope="row">' . esc_html__('Hol jelenjen meg a név', 'mockup-generator') . '</th><td>';
        self::checkbox('show_single', $s['show_single'], __('Termékoldal, a terméknév alatt (profilképpel)', 'mockup-generator'));
        echo '<br>';
        self::checkbox('show_loop', $s['show_loop'], __('Terméklisták: bolt, kategóriák, keresés, kapcsolódó termékek', 'mockup-generator'));
        echo '<br>';
        self::checkbox('show_gift_finder', $s['show_gift_finder'], __('Ajándékkereső kártyái', 'mockup-generator'));
        echo '</td></tr>';

        echo '<tr><th scope="row"><label for="mg-designer-label">' . esc_html__('Felirat a név előtt', 'mockup-generator') . '</label></th><td>';
        echo '<input type="text" class="regular-text" id="mg-designer-label" name="mg_designer[label]" value="' . esc_attr($s['label']) . '" />';
        echo '<p class="description">' . esc_html__('Pl. „Tervezte:” – üresen csak a név látszik.', 'mockup-generator') . '</p>';
        echo '</td></tr>';

        echo '<tr><th scope="row">' . esc_html__('Új termékek', 'mockup-generator') . '</th><td>';
        self::checkbox('auto_assign', $s['auto_assign'], __('Feltöltéskor (bulk is) véletlen aktív grafikus a grafikus nélküli új termékeknek', 'mockup-generator'));
        echo '</td></tr>';

        echo '</tbody></table>';
        echo '</section>';

        echo '<section class="mg-panel-section">';
        echo '<div class="mg-panel-section__header"><h2>' . esc_html__('Grafikus program oldal', 'mockup-generator') . '</h2>';
        echo '<p>' . esc_html__('Tájékoztató oldal a programról, jelentkezési űrlap nélkül. A tartalmat a [mg_designer_program] shortcode rendereli; a profiloldalak erre az oldalra linkelnek.', 'mockup-generator') . '</p></div>';
        echo '<table class="form-table" role="presentation"><tbody>';

        echo '<tr><th scope="row"><label for="mg-designer-page">' . esc_html__('Oldal', 'mockup-generator') . '</label></th><td>';
        echo wp_dropdown_pages(array( // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
            'name'              => 'mg_designer[program_page_id]',
            'id'                => 'mg-designer-page',
            'selected'          => $page_id,
            'show_option_none'  => __('— nincs kiválasztva —', 'mockup-generator'),
            'option_none_value' => '0',
            'post_status'       => array('publish', 'draft', 'private'),
            'echo'              => 0,
        ));
        if ($page_id > 0) {
            echo ' <a href="' . esc_url(get_permalink($page_id)) . '" target="_blank" rel="noopener">' . esc_html__('Megnézem', 'mockup-generator') . '</a>';
            echo ' | <a href="' . esc_url(get_edit_post_link($page_id)) . '">' . esc_html__('Oldal szerkesztése', 'mockup-generator') . '</a>';
        } else {
            $create_url = wp_nonce_url(add_query_arg('action', 'mg_designer_create_page', admin_url('admin-post.php')), 'mg_designer_create_page');
            echo ' <a class="button" href="' . esc_url($create_url) . '">' . esc_html__('Programoldal létrehozása', 'mockup-generator') . '</a>';
        }
        echo '</td></tr>';

        echo '<tr><th scope="row"><label for="mg-designer-commission">' . esc_html__('Jutalék (%)', 'mockup-generator') . '</label></th><td>';
        echo '<input type="number" min="0" max="100" step="0.1" class="small-text" id="mg-designer-commission" name="mg_designer[program_commission]" value="' . esc_attr($s['program_commission']) . '" />';
        echo '</td></tr>';

        echo '<tr><th scope="row"><label for="mg-designer-minimum">' . esc_html__('Minimum kifizetés (Ft)', 'mockup-generator') . '</label></th><td>';
        echo '<input type="number" min="0" step="100" class="small-text mg-designers__wide-number" id="mg-designer-minimum" name="mg_designer[program_minimum]" value="' . esc_attr($s['program_minimum']) . '" />';
        echo '<p class="description">' . esc_html__('0 esetén a sor nem jelenik meg.', 'mockup-generator') . '</p>';
        echo '</td></tr>';

        echo '<tr><th scope="row"><label for="mg-designer-payout">' . esc_html__('Kifizetés módja', 'mockup-generator') . '</label></th><td>';
        echo '<input type="text" class="large-text" id="mg-designer-payout" name="mg_designer[program_payout]" value="' . esc_attr($s['program_payout']) . '" />';
        echo '</td></tr>';

        echo '<tr><th scope="row"><label for="mg-designer-intro">' . esc_html__('Bevezető', 'mockup-generator') . '</label></th><td>';
        echo '<textarea class="large-text" rows="4" id="mg-designer-intro" name="mg_designer[program_intro]">' . esc_textarea($s['program_intro']) . '</textarea>';
        echo '</td></tr>';

        echo '<tr><th scope="row"><label for="mg-designer-faq">' . esc_html__('Gyakori kérdések', 'mockup-generator') . '</label></th><td>';
        echo '<textarea class="large-text code" rows="6" id="mg-designer-faq" name="mg_designer[program_faq]">' . esc_textarea($s['program_faq']) . '</textarea>';
        echo '<p class="description">' . esc_html__('Soronként egy: Kérdés | Válasz', 'mockup-generator') . '</p>';
        echo '</td></tr>';

        echo '<tr><th scope="row"><label for="mg-designer-extra">' . esc_html__('További tartalom', 'mockup-generator') . '</label></th><td>';
        echo '<textarea class="large-text" rows="4" id="mg-designer-extra" name="mg_designer[program_extra]">' . esc_textarea($s['program_extra']) . '</textarea>';
        echo '<p class="description">' . esc_html__('Az oldal végére kerül, HTML is lehet. Mindenhol használható: {jutalek}, {minimum}, {kifizetes}, {oldal}.', 'mockup-generator') . '</p>';
        echo '</td></tr>';

        echo '</tbody></table>';
        echo '</section>';

        echo '<p><button type="submit" class="button button-primary">' . esc_html__('Beállítások mentése', 'mockup-generator') . '</button></p>';
        echo '</form>';
    }

    private static function checkbox($key, $checked, $label) {
        printf(
            '<label><input type="checkbox" name="mg_designer[%1$s]" value="1"%2$s /> %3$s</label>',
            esc_attr($key),
            checked(!empty($checked), true, false),
            esc_html($label)
        );
    }

    private static function render_edit_form($term_id) {
        $term = $term_id > 0 ? get_term($term_id, MG_Designer_Profiles::TAXONOMY) : null;
        if ($term_id > 0 && (!$term instanceof WP_Term)) {
            echo '<div class="notice notice-error"><p>' . esc_html__('A profil nem található.', 'mockup-generator') . '</p></div>';
            echo '<p><a href="' . esc_url(self::tab_url()) . '">' . esc_html__('← Vissza a profilokhoz', 'mockup-generator') . '</a></p>';
            return;
        }

        $meta = function ($key) use ($term) {
            return $term ? (string) get_term_meta($term->term_id, $key, true) : '';
        };
        $avatar_id = absint($meta(MG_Designer_Profiles::META_AVATAR));
        $active = $term ? MG_Designer_Profiles::is_profile_active($term->term_id) : true;
        $avatar_src = $avatar_id ? wp_get_attachment_image_url($avatar_id, 'thumbnail') : '';

        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" class="mg-designers__edit">';
        echo '<input type="hidden" name="action" value="mg_designer_save" />';
        echo '<input type="hidden" name="term_id" value="' . esc_attr($term ? $term->term_id : 0) . '" />';
        wp_nonce_field('mg_designer_save');

        echo '<section class="mg-panel-section">';
        echo '<div class="mg-panel-section__header">';
        echo '<p><a href="' . esc_url(self::tab_url()) . '">' . esc_html__('← Vissza a profilokhoz', 'mockup-generator') . '</a></p>';
        echo '<h2>' . esc_html($term ? sprintf(
            /* translators: %s: profile name */
            __('Profil szerkesztése: %s', 'mockup-generator'),
            $term->name
        ) : __('Új grafikus profil', 'mockup-generator')) . '</h2>';
        echo '</div>';

        echo '<table class="form-table" role="presentation"><tbody>';

        echo '<tr><th scope="row"><label for="mg-designer-name">' . esc_html__('Név', 'mockup-generator') . '</label></th><td>';
        echo '<input type="text" class="regular-text" id="mg-designer-name" name="name" required value="' . esc_attr($term ? $term->name : '') . '" />';
        echo '</td></tr>';

        echo '<tr><th scope="row"><label for="mg-designer-slug">' . esc_html__('Slug', 'mockup-generator') . '</label></th><td>';
        echo '<code>/' . esc_html(MG_Designer_Profiles::REWRITE_SLUG) . '/</code><input type="text" class="regular-text" id="mg-designer-slug" name="slug" value="' . esc_attr($term ? $term->slug : '') . '" /><code>/</code>';
        echo '<p class="description">' . esc_html__('Üresen hagyva a névből készül.', 'mockup-generator') . '</p>';
        echo '</td></tr>';

        echo '<tr><th scope="row">' . esc_html__('Profilkép', 'mockup-generator') . '</th><td>';
        echo '<div class="mg-designers__avatar-field">';
        echo '<span class="mg-designers__avatar-preview">' . ($avatar_src ? '<img src="' . esc_url($avatar_src) . '" alt="" />' : '') . '</span>';
        echo '<input type="hidden" name="avatar_id" value="' . esc_attr($avatar_id ?: '') . '" />';
        echo '<button type="button" class="button mg-designers__avatar-pick">' . esc_html__('Kép kiválasztása', 'mockup-generator') . '</button> ';
        echo '<button type="button" class="button-link mg-designers__avatar-remove"' . ($avatar_id ? '' : ' hidden') . '>' . esc_html__('Eltávolítás', 'mockup-generator') . '</button>';
        echo '</div>';
        echo '<p class="description">' . esc_html__('Négyzetes kép ajánlott. Kép nélkül a név kezdőbetűi jelennek meg.', 'mockup-generator') . '</p>';
        echo '</td></tr>';

        echo '<tr><th scope="row"><label for="mg-designer-tagline">' . esc_html__('Szakterület / szlogen', 'mockup-generator') . '</label></th><td>';
        echo '<input type="text" class="large-text" id="mg-designer-tagline" name="tagline" value="' . esc_attr($meta(MG_Designer_Profiles::META_TAGLINE)) . '" placeholder="' . esc_attr__('pl. Illusztrátor, vicces feliratok', 'mockup-generator') . '" />';
        echo '</td></tr>';

        echo '<tr><th scope="row"><label for="mg-designer-location">' . esc_html__('Helyszín', 'mockup-generator') . '</label></th><td>';
        echo '<input type="text" class="regular-text" id="mg-designer-location" name="location" value="' . esc_attr($meta(MG_Designer_Profiles::META_LOCATION)) . '" placeholder="' . esc_attr__('pl. Szeged', 'mockup-generator') . '" />';
        echo '</td></tr>';

        echo '<tr><th scope="row"><label for="mg-designer-since">' . esc_html__('Velünk mióta (év)', 'mockup-generator') . '</label></th><td>';
        echo '<input type="number" class="small-text mg-designers__wide-number" min="1990" max="' . esc_attr(gmdate('Y')) . '" id="mg-designer-since" name="since" value="' . esc_attr($meta(MG_Designer_Profiles::META_SINCE)) . '" />';
        echo '</td></tr>';

        echo '<tr><th scope="row"><label for="mg-designer-bio">' . esc_html__('Bemutatkozás', 'mockup-generator') . '</label></th><td>';
        echo '<textarea class="large-text" rows="6" id="mg-designer-bio" name="bio">' . esc_textarea($meta(MG_Designer_Profiles::META_BIO)) . '</textarea>';
        echo '</td></tr>';

        echo '<tr><th scope="row">' . esc_html__('Kiosztás', 'mockup-generator') . '</th><td>';
        echo '<label><input type="checkbox" name="active" value="1"' . checked($active, true, false) . ' /> ' . esc_html__('Aktív – kaphat mintát a véletlen kiosztásban', 'mockup-generator') . '</label>';
        echo '</td></tr>';

        $max = $term ? MG_Designer_Profiles::get_profile_max($term->term_id) : 0;
        echo '<tr><th scope="row"><label for="mg-designer-max">' . esc_html__('Maximum minta', 'mockup-generator') . '</label></th><td>';
        echo '<input type="number" class="small-text mg-designers__wide-number" min="0" step="1" id="mg-designer-max" name="max" value="' . esc_attr($max > 0 ? $max : '') . '" placeholder="' . esc_attr__('korlátlan', 'mockup-generator') . '" />';
        if ($term) {
            $counts = MG_Designer_Profiles::get_assignment_counts();
            echo ' <span class="description">' . esc_html(sprintf(
                /* translators: %s: number of designs */
                __('Most %s minta tartozik hozzá.', 'mockup-generator'),
                number_format_i18n(isset($counts[$term->term_id]) ? $counts[$term->term_id] : 0)
            )) . '</span>';
        }
        echo '<p class="description">' . esc_html__('Ennél több mintát nem kap a véletlen kiosztásban (szétosztás, újrasorsolás, bulk feltöltés). Üresen vagy 0-val korlátlan. Ha kisebbre állítod, mint ahány mintája már van, a meglévők maradnak, csak újat nem kap; kézzel a termékszerkesztőben továbbra is hozzá lehet rendelni.', 'mockup-generator') . '</p>';
        echo '</td></tr>';

        echo '</tbody></table>';
        echo '<p><button type="submit" class="button button-primary">' . esc_html__('Profil mentése', 'mockup-generator') . '</button></p>';
        echo '</section>';
        echo '</form>';
    }

    /* ------------------------------------------------------------------ */
    /* Mentések                                                            */
    /* ------------------------------------------------------------------ */

    public static function handle_save_profile() {
        if (!current_user_can(self::CAPABILITY)) {
            wp_die(esc_html__('Nincs jogosultságod.', 'mockup-generator'));
        }
        check_admin_referer('mg_designer_save');

        $term_id = isset($_POST['term_id']) ? absint($_POST['term_id']) : 0;
        $back = $term_id > 0 ? array('mg_designer_edit' => $term_id) : array('mg_designer_edit' => 'new');
        $name = isset($_POST['name']) ? sanitize_text_field(wp_unslash($_POST['name'])) : '';
        $slug = isset($_POST['slug']) ? sanitize_title(wp_unslash($_POST['slug'])) : '';
        if ($name === '') {
            self::redirect(array_merge($back, array('mg_designer_notice' => 'empty_name')));
        }

        $args = $slug !== '' ? array('slug' => $slug) : array();
        if ($term_id > 0) {
            $result = wp_update_term($term_id, MG_Designer_Profiles::TAXONOMY, array_merge(array('name' => $name), $args));
        } else {
            $result = wp_insert_term($name, MG_Designer_Profiles::TAXONOMY, $args);
        }
        if (is_wp_error($result)) {
            $code = in_array($result->get_error_code(), array('term_exists', 'duplicate_term_slug'), true) ? 'exists' : 'save_failed';
            self::redirect(array_merge($back, array('mg_designer_notice' => $code)));
        }
        $saved_id = (int) $result['term_id'];

        $avatar_id = isset($_POST['avatar_id']) ? absint($_POST['avatar_id']) : 0;
        if ($avatar_id > 0 && !wp_attachment_is_image($avatar_id)) {
            $avatar_id = 0;
        }
        $since = isset($_POST['since']) ? absint($_POST['since']) : 0;
        if ($since < 1990 || $since > (int) gmdate('Y')) {
            $since = 0;
        }

        $meta = array(
            MG_Designer_Profiles::META_AVATAR   => $avatar_id ?: '',
            MG_Designer_Profiles::META_TAGLINE  => isset($_POST['tagline']) ? sanitize_text_field(wp_unslash($_POST['tagline'])) : '',
            MG_Designer_Profiles::META_LOCATION => isset($_POST['location']) ? sanitize_text_field(wp_unslash($_POST['location'])) : '',
            MG_Designer_Profiles::META_SINCE    => $since ?: '',
            MG_Designer_Profiles::META_BIO      => isset($_POST['bio']) ? sanitize_textarea_field(wp_unslash($_POST['bio'])) : '',
            MG_Designer_Profiles::META_ACTIVE   => !empty($_POST['active']) ? '1' : '0',
            MG_Designer_Profiles::META_MAX      => (isset($_POST['max']) && absint($_POST['max']) > 0) ? absint($_POST['max']) : '',
        );
        foreach ($meta as $key => $value) {
            if ($value === '') {
                delete_term_meta($saved_id, $key);
            } else {
                update_term_meta($saved_id, $key, $value);
            }
        }
        MG_Designer_Profiles::flush_cache();

        self::redirect(array('mg_designer_notice' => $term_id > 0 ? 'saved' : 'created'));
    }

    public static function handle_delete_profile() {
        if (!current_user_can(self::CAPABILITY)) {
            wp_die(esc_html__('Nincs jogosultságod.', 'mockup-generator'));
        }
        $term_id = isset($_GET['term_id']) ? absint($_GET['term_id']) : 0;
        check_admin_referer('mg_designer_delete_' . $term_id);

        $result = $term_id > 0 ? wp_delete_term($term_id, MG_Designer_Profiles::TAXONOMY) : false;
        MG_Designer_Profiles::flush_cache();
        self::redirect(array('mg_designer_notice' => ($result && !is_wp_error($result)) ? 'deleted' : 'delete_failed'));
    }

    public static function handle_save_settings() {
        if (!current_user_can(self::CAPABILITY)) {
            wp_die(esc_html__('Nincs jogosultságod.', 'mockup-generator'));
        }
        check_admin_referer('mg_designer_settings');

        $posted = isset($_POST['mg_designer']) && is_array($_POST['mg_designer']) ? wp_unslash($_POST['mg_designer']) : array();
        // A form minden kulcsot tartalmaz; a be nem pipált checkbox hiányzik, az hamis.
        foreach (array('enabled', 'show_single', 'show_loop', 'show_gift_finder', 'auto_assign') as $key) {
            $posted[$key] = !empty($posted[$key]);
        }
        MG_Designer_Profiles::update_settings($posted);

        self::redirect(array('mg_designer_notice' => 'settings'));
    }

    public static function handle_create_page() {
        if (!current_user_can(self::CAPABILITY) || !current_user_can('publish_pages')) {
            wp_die(esc_html__('Nincs jogosultságod.', 'mockup-generator'));
        }
        check_admin_referer('mg_designer_create_page');

        if (MG_Designer_Profiles::get_program_page_id() > 0) {
            self::redirect();
        }
        $page_id = wp_insert_post(array(
            'post_type'    => 'page',
            'post_status'  => 'publish',
            'post_title'   => __('Grafikus program', 'mockup-generator'),
            'post_name'    => 'grafikus-program',
            'post_content' => '<!-- wp:shortcode -->[' . MG_Designer_Profiles::SHORTCODE . ']<!-- /wp:shortcode -->',
        ), true);
        if (is_wp_error($page_id) || !$page_id) {
            self::redirect(array('mg_designer_notice' => 'page_failed'));
        }
        MG_Designer_Profiles::update_settings(array('program_page_id' => (int) $page_id));

        self::redirect(array('mg_designer_notice' => 'page_created'));
    }

    public static function ajax_distribute() {
        check_ajax_referer('mg_designer_distribute', 'nonce');
        if (!current_user_can(self::CAPABILITY)) {
            wp_send_json_error(array('message' => __('Nincs jogosultságod.', 'mockup-generator')), 403);
        }

        $mode = isset($_POST['mode']) ? sanitize_key(wp_unslash($_POST['mode'])) : '';
        $cursor = isset($_POST['cursor']) ? absint($_POST['cursor']) : 0;
        $result = MG_Designer_Profiles::process_batch($mode, $cursor, self::BATCH_SIZE);
        if (is_wp_error($result)) {
            wp_send_json_error(array('message' => $result->get_error_message()), 400);
        }
        if ($result['done']) {
            $result['stats'] = MG_Designer_Profiles::get_assignment_stats();
            $result['stats']['free'] = MG_Designer_Profiles::get_free_capacity();
        }
        wp_send_json_success($result);
    }
}
