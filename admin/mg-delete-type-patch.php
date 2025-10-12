<?php
/**
 * Patch: MG Delete Type (single-file)
 * Description: Extra menü a Mockup Generatorhoz – terméktípus biztonságos törlése (mentéssel).
 * Version: 1.0.0
 * Author: helper
 */

if (!defined('ABSPATH')) exit;

/**
 * Ezt a fájlt a fő plugin fájl tölti be:
 *   require_once __DIR__ . '/admin/mg-delete-type-patch.php';
 */

if (!function_exists('mgdtp__read')) {
function mgdtp__read(){
    $opt = get_option('mg_products', array());
    return is_array($opt) ? $opt : array();
}}

if (!function_exists('mgdtp__save')) {
function mgdtp__save($data){
    update_option('mg_products', $data);
}}

if (!function_exists('mgdtp__backup')) {
function mgdtp__backup($data){
    $key = 'mg_products_backup_' . gmdate('Ymd_His');
    update_option($key, $data, false);
    return $key;
}}

if (!function_exists('mgdtp__render_page')) {
function mgdtp__render_page(){
    if (!current_user_can('manage_woocommerce')) wp_die(__('Nincs jogosultság.','mgdtp'));

    $data = mgdtp__read();
    $notice = '';

    // Törlés kezelése
    if (isset($_POST['mgdtp_delete']) && check_admin_referer('mgdtp_delete_nonce')) {
        $del_key = isset($_POST['del_key']) ? sanitize_title(wp_unslash($_POST['del_key'])) : '';
        if ($del_key) {
            $idx = null;
            foreach ($data as $i => $row) {
                if (!is_array($row)) continue;
                $k = isset($row['key']) ? sanitize_title($row['key']) : (is_string($i) ? sanitize_title($i) : '');
                if ($k === $del_key) { $idx = $i; break; }
            }
            if (!is_null($idx)) {
                $backup_key = mgdtp__backup($data);
                unset($data[$idx]);
                $data = array_values($data);
                mgdtp__save($data);
                $notice = sprintf(
                    esc_html__('„%1$s” törölve. Biztonsági mentés kulcs: %2$s','mgdtp'),
                    esc_html($del_key),
                    esc_html($backup_key)
                );
            } else {
                $notice = esc_html__('Nem találtam a megadott kulcsot.','mgdtp');
            }
        }
    }

    echo '<div class="wrap"><h1>'.esc_html__('Mockup Generator – Típus törlése','mgdtp').'</h1>';
    echo '<p class="description">'.esc_html__('Itt végleg eltávolíthatsz egy terméktípust az mg_products beállításból. A törlés előtt automatikus biztonsági mentést készítünk.','mgdtp').'</p>';

    if ($notice) echo '<div class="notice notice-success is-dismissible"><p>'.$notice.'</p></div>';

    if (empty($data)) {
        echo '<p><em>'.esc_html__('Nincs beállított terméktípus.','mgdtp').'</em></p></div>';
        return;
    }

    echo '<table class="widefat striped" style="max-width:1000px"><thead><tr>';
    echo '<th>'.esc_html__('Kulcs','mgdtp').'</th>';
    echo '<th>'.esc_html__('Címke','mgdtp').'</th>';
    echo '<th>'.esc_html__('Színek (db)','mgdtp').'</th>';
    echo '<th>'.esc_html__('Views','mgdtp').'</th>';
    echo '<th>'.esc_html__('Művelet','mgdtp').'</th>';
    echo '</tr></thead><tbody>';

    foreach ($data as $row){
        if (!is_array($row)) continue;
        $k = isset($row['key']) ? sanitize_title($row['key']) : '';
        $label = isset($row['label']) ? $row['label'] : $k;
        $colors = (isset($row['colors']) && is_array($row['colors'])) ? count($row['colors']) : 0;
        $views  = '';
        if (isset($row['views']) && is_array($row['views'])) {
            $views = implode(', ', array_map('esc_html', array_keys($row['views'])));
        } else {
            $views = '<span style="opacity:.7">'.esc_html__('nincs/üres','mgdtp').'</span>';
        }
        echo '<tr>';
        echo '<td><code>'.esc_html($k).'</code></td>';
        echo '<td>'.esc_html($label).'</td>';
        echo '<td>'.intval($colors).'</td>';
        echo '<td>'.$views.'</td>';
        echo '<td>';
        echo '<form method="post" onsubmit="return mgdtpConfirm(this)">';
        wp_nonce_field('mgdtp_delete_nonce');
        echo '<input type="hidden" name="del_key" value="'.esc_attr($k).'">';
        submit_button(__('Törlés','mgdtp'), 'delete', 'mgdtp_delete', false);
        echo '</form>';
        echo '</td>';
        echo '</tr>';
    }
    echo '</tbody></table>';

    echo '<script>
    function mgdtpConfirm(f){
        var key = f.querySelector("input[name=del_key]").value;
        return confirm("Biztosan törlöd a(z) \"" + key + "\" típust? A lépés visszavonásához használd a biztonsági mentést (options: mg_products_backup_...).");
    }
    </script>';

    echo '</div>';
}}

// Almenü regisztrálása a Mockup Generator alatt
add_action('admin_menu', function(){
    add_submenu_page(
        'mockup-generator', // ha más a fő menü slugja, ezt cseréld
        __('Típus törlése','mgdtp'),
        __('Típus törlése','mgdtp'),
        'manage_woocommerce',
        'mockup-generator-delete-type',
        'mgdtp__render_page'
    );
}, 99);
