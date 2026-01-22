<?php
if (!defined('ABSPATH')) {
    exit;
}

class MG_Custom_Fields_Page {
    const NONCE_FIELD = 'mg_custom_fields_nonce';
    const NONCE_ACTION = 'mg_custom_fields_action';

    public static function add_submenu_page() {
        $hook = add_submenu_page(
            'mockup-generator',
            __('Egyedi mezők', 'mgcf'),
            __('Egyedi mezők', 'mgcf'),
            'edit_products',
            'mockup-generator-custom-fields',
            array(__CLASS__, 'render_page')
        );
        if (!has_action('admin_enqueue_scripts', array(__CLASS__, 'enqueue_assets'))) {
            add_action('admin_enqueue_scripts', array(__CLASS__, 'enqueue_assets'));
        }
        return $hook;
    }

    public static function enqueue_assets($hook) {
        $valid_hooks = array(
            'mockup-generator_page_mockup-generator-custom-fields',
            'toplevel_page_mockup-generator',
        );

        if (!in_array($hook, $valid_hooks, true)) {
            return;
        }
        $base_file = dirname(__DIR__) . '/mockup-generator.php';
        $style_url = plugins_url('assets/css/custom-fields.css', $base_file);
        $style_path = dirname(__DIR__) . '/assets/css/custom-fields.css';
        wp_enqueue_style(
            'mg-custom-fields-admin',
            $style_url,
            array(),
            file_exists($style_path) ? filemtime($style_path) : '1.0.0'
        );
    }

    protected static function handle_post() {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            return;
        }
        if (empty($_POST['mg_custom_fields_action'])) {
            return;
        }
        if (!current_user_can('edit_products')) {
            return;
        }
        if (!isset($_POST[self::NONCE_FIELD]) || !wp_verify_nonce(wp_unslash($_POST[self::NONCE_FIELD]), self::NONCE_ACTION)) {
            add_settings_error('mg_custom_fields_admin', 'mgcf_nonce', __('Érvénytelen biztonsági token.', 'mgcf'), 'error');
            return;
        }
        if (isset($_POST['mg_custom_fields_delete'])) {
            $action = 'delete_field';
        } else {
            $action = sanitize_key($_POST['mg_custom_fields_action']);
        }
        $product_id = isset($_POST['product_id']) ? intval($_POST['product_id']) : 0;
        if ($product_id <= 0) {
            add_settings_error('mg_custom_fields_admin', 'mgcf_product', __('Hiányzó termékazonosító.', 'mgcf'), 'error');
            return;
        }
        switch ($action) {
            case 'add_field':
            case 'update_field':
                $field = self::read_field_from_request();
                if (empty($field['label'])) {
                    add_settings_error('mg_custom_fields_admin', 'mgcf_missing_label', __('A mező neve nem lehet üres.', 'mgcf'), 'error');
                    break;
                }
                if ($action === 'update_field' && empty($field['id'])) {
                    add_settings_error('mg_custom_fields_admin', 'mgcf_missing_id', __('Hiányzik a mező azonosítója.', 'mgcf'), 'error');
                    break;
                }
                if ($action === 'add_field') {
                    $field['id'] = 'mgcf_' . uniqid();
                }
                MG_Custom_Fields_Manager::upsert_field($product_id, $field);
                add_settings_error('mg_custom_fields_admin', 'mgcf_saved', __('A mező beállításai mentve.', 'mgcf'), 'updated');
                break;
            case 'delete_field':
                $field_id = isset($_POST['field_id']) ? sanitize_key($_POST['field_id']) : '';
                if ($field_id === '') {
                    add_settings_error('mg_custom_fields_admin', 'mgcf_missing_delete_id', __('Hiányzik a mező azonosítója.', 'mgcf'), 'error');
                    break;
                }
                MG_Custom_Fields_Manager::delete_field($product_id, $field_id);
                add_settings_error('mg_custom_fields_admin', 'mgcf_deleted', __('A mező törlésre került.', 'mgcf'), 'updated');
                break;
            case 'toggle_custom':
                $flag = isset($_POST['make_custom']) ? (bool) $_POST['make_custom'] : false;
                MG_Custom_Fields_Manager::set_custom_product($product_id, $flag);
                if ($flag) {
                    add_settings_error('mg_custom_fields_admin', 'mgcf_marked', __('A termék egyedi státuszt kapott.', 'mgcf'), 'updated');
                } else {
                    add_settings_error('mg_custom_fields_admin', 'mgcf_unmarked', __('A termék többé nem egyedi.', 'mgcf'), 'updated');
                }
                break;
            case 'save_preset':
                $preset_name = isset($_POST['preset_name']) ? sanitize_text_field($_POST['preset_name']) : '';
                if ($preset_name === '') {
                    add_settings_error('mg_custom_fields_admin', 'mgcf_missing_preset_name', __('A preset neve kötelező.', 'mgcf'), 'error');
                    break;
                }
                $fields = MG_Custom_Fields_Manager::get_fields_for_product($product_id);
                if (empty($fields)) {
                    add_settings_error('mg_custom_fields_admin', 'mgcf_no_fields_for_preset', __('Nincsenek menthető mezők ezen a terméken.', 'mgcf'), 'error');
                    break;
                }
                $preset_id = MG_Custom_Fields_Manager::save_preset($preset_name, $fields);
                if ($preset_id) {
                    add_settings_error('mg_custom_fields_admin', 'mgcf_preset_saved', __('A preset elmentve.', 'mgcf'), 'updated');
                } else {
                    add_settings_error('mg_custom_fields_admin', 'mgcf_preset_failed', __('Nem sikerült elmenteni a presetet.', 'mgcf'), 'error');
                }
                break;
            case 'apply_preset':
                $preset_id = isset($_POST['preset_id']) ? sanitize_key($_POST['preset_id']) : '';
                if ($preset_id === '') {
                    add_settings_error('mg_custom_fields_admin', 'mgcf_missing_preset', __('Válassz ki egy presetet.', 'mgcf'), 'error');
                    break;
                }
                if (MG_Custom_Fields_Manager::apply_preset_to_product($product_id, $preset_id)) {
                    add_settings_error('mg_custom_fields_admin', 'mgcf_preset_applied', __('Preset sikeresen hozzárendelve a termékhez.', 'mgcf'), 'updated');
                } else {
                    add_settings_error('mg_custom_fields_admin', 'mgcf_preset_apply_failed', __('Nem sikerült alkalmazni a presetet.', 'mgcf'), 'error');
                }
                break;
            case 'delete_preset':
                $preset_id = isset($_POST['preset_id']) ? sanitize_key($_POST['preset_id']) : '';
                if ($preset_id === '') {
                    add_settings_error('mg_custom_fields_admin', 'mgcf_missing_delete_preset', __('Hiányzik a törlendő preset azonosítója.', 'mgcf'), 'error');
                    break;
                }
                MG_Custom_Fields_Manager::delete_preset($preset_id);
                add_settings_error('mg_custom_fields_admin', 'mgcf_preset_deleted', __('Preset törölve.', 'mgcf'), 'updated');
                break;
        }
    }

    protected static function read_field_from_request() {
        $field = array();
        $field['id'] = isset($_POST['field_id']) ? sanitize_key($_POST['field_id']) : '';
        $field['label'] = isset($_POST['field_label']) ? sanitize_text_field($_POST['field_label']) : '';
        $field['type'] = isset($_POST['field_type']) ? sanitize_key($_POST['field_type']) : 'text';
        $field['required'] = !empty($_POST['field_required']);
        $field['default'] = isset($_POST['field_default']) ? sanitize_text_field($_POST['field_default']) : '';
        $field['validation_min'] = isset($_POST['field_validation_min']) ? sanitize_text_field($_POST['field_validation_min']) : '';
        $field['validation_max'] = isset($_POST['field_validation_max']) ? sanitize_text_field($_POST['field_validation_max']) : '';
        $field['placement'] = isset($_POST['field_placement']) ? MG_Custom_Fields_Manager::normalize_placement($_POST['field_placement']) : 'variant_bottom';
        $field['position'] = isset($_POST['field_position']) ? intval($_POST['field_position']) : 0;
        $field['description'] = isset($_POST['field_description']) ? sanitize_textarea_field($_POST['field_description']) : '';
        $options_raw = isset($_POST['field_options']) ? wp_kses_post($_POST['field_options']) : '';
        $field['options'] = $options_raw;
        $field['surcharge_type'] = isset($_POST['field_surcharge_type']) ? sanitize_key($_POST['field_surcharge_type']) : 'none';
        $field['surcharge_amount'] = isset($_POST['field_surcharge_amount']) ? floatval($_POST['field_surcharge_amount']) : 0.0;
        $field['mockup'] = array(
            'x' => isset($_POST['field_mockup_x']) ? intval($_POST['field_mockup_x']) : '',
            'y' => isset($_POST['field_mockup_y']) ? intval($_POST['field_mockup_y']) : '',
            'font' => isset($_POST['field_mockup_font']) ? sanitize_text_field($_POST['field_mockup_font']) : '',
            'color' => isset($_POST['field_mockup_color']) ? sanitize_text_field($_POST['field_mockup_color']) : '',
            'size' => isset($_POST['field_mockup_size']) ? floatval($_POST['field_mockup_size']) : '',
            'view' => isset($_POST['field_mockup_view']) ? sanitize_text_field($_POST['field_mockup_view']) : '',
            'additional' => isset($_POST['field_mockup_additional']) ? sanitize_text_field($_POST['field_mockup_additional']) : '',
        );
        return $field;
    }

    public static function render_page() {
        if (!current_user_can('edit_products')) {
            wp_die(__('Nincs jogosultság.', 'mgcf'));
        }

        self::handle_post();

        $current_product_id = isset($_GET['product_id']) ? intval($_GET['product_id']) : 0;
        $product = ($current_product_id > 0) ? get_post($current_product_id) : null;

        echo '<div class="wrap mg-custom-fields-admin mgcf-admin">';
        self::render_header($current_product_id, $product);
        echo '<div class="mgcf-notices">';
        settings_errors('mg_custom_fields_admin');
        echo '</div>';

        if ($current_product_id > 0) {
            self::render_product_editor($current_product_id, $product);
        } else {
            self::render_product_list();
        }

        echo '</div>';
    }

    protected static function render_header($product_id, $product = null) {
        $title = __('Egyedi mezők', 'mgcf');
        $description = __('Állítsd be, hogy a termékoldalon milyen mezők és beviteli lehetőségek jelenjenek meg.', 'mgcf');

        echo '<header class="mgcf-hero">';
        echo '<div class="mgcf-hero__text">';
        echo '<h1>' . esc_html($title) . '</h1>';
        echo '<p>' . esc_html($description) . '</p>';
        echo '</div>';

        echo '<div class="mgcf-hero__actions">';
        if ($product_id > 0 && $product instanceof WP_Post) {
            $product_title = get_the_title($product);
            echo '<span class="mgcf-hero__badge">' . esc_html($product_title) . '</span>';
            $edit_link = get_edit_post_link($product_id);
            if ($edit_link) {
                echo '<a class="button" href="' . esc_url($edit_link) . '">' . esc_html__('Termék szerkesztése', 'mgcf') . '</a>';
            }
        } else {
            $bulk_url = admin_url('admin.php?page=mockup-generator');
            echo '<a class="button button-primary" href="' . esc_url($bulk_url) . '">' . esc_html__('Ugrás a Mockup Generatorra', 'mgcf') . '</a>';
        }
        echo '</div>';
        echo '</header>';
    }

    protected static function render_product_list() {
        $products = MG_Custom_Fields_Manager::get_custom_products();

        echo '<section class="mgcf-section">';
        echo '<div class="mgcf-section__header">';
        echo '<h2>' . esc_html__('Egyedi termékek listája', 'mgcf') . '</h2>';
        echo '<p>' . esc_html__('Itt találod azokat a termékeket, amelyeknél egyedi mezők megadását engedélyezted.', 'mgcf') . '</p>';
        echo '</div>';

        if (empty($products)) {
            echo '<div class="mgcf-empty">';
            echo '<p>' . esc_html__('Még nincs egyedi termék kijelölve. A bulk feltöltő oldalon pipáld be a „Egyedi termék” jelölőt.', 'mgcf') . '</p>';
            echo '</div>';
            echo '</section>';
            return;
        }

        echo '<div class="mgcf-table-wrap">';
        echo '<table class="widefat striped mgcf-table">';
        echo '<thead><tr><th>' . esc_html__('Termék', 'mgcf') . '</th><th>' . esc_html__('Állapot', 'mgcf') . '</th><th class="mgcf-table__actions">' . esc_html__('Művelet', 'mgcf') . '</th></tr></thead>';
        echo '<tbody>';
        foreach ($products as $product) {
            $status = get_post_status_object($product->post_status);
            $status_label = $status ? $status->label : $product->post_status;
            $edit_link = add_query_arg(array('page' => 'mockup-generator-custom-fields', 'product_id' => $product->ID), admin_url('admin.php'));
            echo '<tr>';
            echo '<td>' . esc_html(get_the_title($product)) . '</td>';
            echo '<td>' . esc_html($status_label) . '</td>';
            echo '<td class="mgcf-table__actions"><a class="button button-secondary" href="' . esc_url($edit_link) . '">' . esc_html__('Mezők szerkesztése', 'mgcf') . '</a></td>';
            echo '</tr>';
        }
        echo '</tbody></table>';
        echo '</div>';
        echo '</section>';
    }

    protected static function render_product_editor($product_id, $product = null) {
        if (!$product instanceof WP_Post) {
            $product = get_post($product_id);
        }
        if (!$product) {
            echo '<div class="mgcf-empty">';
            echo '<p>' . esc_html__('A megadott termék nem található.', 'mgcf') . '</p>';
            echo '</div>';
            return;
        }
        $is_custom = MG_Custom_Fields_Manager::is_custom_product($product_id);
        $back_link = remove_query_arg('product_id');
        echo '<nav class="mgcf-breadcrumb">';
        echo '<a class="mgcf-breadcrumb__link" href="' . esc_url($back_link) . '">' . esc_html__('Egyedi mezők listája', 'mgcf') . '</a>';
        echo '<span class="mgcf-breadcrumb__divider">›</span>';
        echo '<span class="mgcf-breadcrumb__current">' . esc_html(get_the_title($product)) . '</span>';
        echo '</nav>';

        echo '<section class="mgcf-section">';
        echo '<div class="mgcf-section__header">';
        echo '<h2>' . esc_html__('Termék státusza', 'mgcf') . '</h2>';
        echo '<p>' . esc_html__('Kapcsold be vagy ki az egyedi mezők használatát ehhez a termékhez.', 'mgcf') . '</p>';
        echo '</div>';

        echo '<form method="post" class="mg-custom-toggle-form mgcf-toggle">';
        wp_nonce_field(self::NONCE_ACTION, self::NONCE_FIELD);
        echo '<input type="hidden" name="mg_custom_fields_action" value="toggle_custom" />';
        echo '<input type="hidden" name="product_id" value="' . esc_attr($product_id) . '" />';
        if ($is_custom) {
            echo '<input type="hidden" name="make_custom" value="0" />';
            echo '<button type="submit" class="button button-secondary">' . esc_html__('Egyedi státusz eltávolítása', 'mgcf') . '</button>';
        } else {
            echo '<input type="hidden" name="make_custom" value="1" />';
            echo '<button type="submit" class="button button-primary">' . esc_html__('Egyedi státusz beállítása', 'mgcf') . '</button>';
        }
        echo '</form>';

        echo '</section>';

        $fields = MG_Custom_Fields_Manager::get_fields_for_product($product_id);
        self::render_presets_section($product_id, $fields);
        if (empty($fields)) {
            echo '<section class="mgcf-section">';
            echo '<div class="mgcf-section__header">';
            echo '<h3>' . esc_html__('Még nincs mező konfigurálva', 'mgcf') . '</h3>';
            echo '<p>' . esc_html__('Adj hozzá új mezőt az alábbi űrlap segítségével.', 'mgcf') . '</p>';
            echo '</div>';
            echo '</section>';
        } else {
            echo '<section class="mgcf-section">';
            echo '<div class="mgcf-section__header">';
            echo '<h3>' . esc_html__('Meglévő mezők', 'mgcf') . '</h3>';
            echo '<p>' . esc_html__('Szerkeszd vagy töröld a termékhez tartozó mezőket.', 'mgcf') . '</p>';
            echo '</div>';
            echo '<div class="mgcf-field-grid">';
            foreach ($fields as $field) {
                self::render_field_editor_form($product_id, $field);
            }
            echo '</div>';
            echo '</section>';
        }
        echo '<section class="mgcf-section">';
        echo '<div class="mgcf-section__header">';
        echo '<h3>' . esc_html__('Új mező hozzáadása', 'mgcf') . '</h3>';
        echo '<p>' . esc_html__('Állítsd be a mező típusát, elhelyezését és megjelenését.', 'mgcf') . '</p>';
        echo '</div>';
        echo '<div class="mgcf-field-grid">';
        self::render_field_editor_form($product_id, null, true);
        echo '</div>';
        echo '</section>';
    }

    protected static function render_field_editor_form($product_id, $field = null, $is_new = false) {
        $field = is_array($field) ? $field : array();
        $field_id = isset($field['id']) ? $field['id'] : '';
        $label = isset($field['label']) ? $field['label'] : '';
        $type = isset($field['type']) ? $field['type'] : 'text';
        $required = !empty($field['required']);
        $default = isset($field['default']) ? $field['default'] : '';
        $validation_min = isset($field['validation_min']) ? $field['validation_min'] : '';
        $validation_max = isset($field['validation_max']) ? $field['validation_max'] : '';
        $placement = isset($field['placement']) ? $field['placement'] : 'variant_bottom';
        $position = isset($field['position']) ? intval($field['position']) : 0;
        $description = isset($field['description']) ? $field['description'] : '';
        $options = isset($field['options']) ? $field['options'] : array();
        $options_text = '';
        if (is_array($options)) {
            $options_text = implode("\n", $options);
        } elseif (is_string($options)) {
            $options_text = $options;
        }
        $surcharge_type = isset($field['surcharge_type']) ? $field['surcharge_type'] : 'none';
        $surcharge_amount = isset($field['surcharge_amount']) ? floatval($field['surcharge_amount']) : 0.0;
        $mockup = isset($field['mockup']) && is_array($field['mockup']) ? $field['mockup'] : array();
        $mockup_x = isset($mockup['x']) ? $mockup['x'] : '';
        $mockup_y = isset($mockup['y']) ? $mockup['y'] : '';
        $mockup_font = isset($mockup['font']) ? $mockup['font'] : '';
        $mockup_color = isset($mockup['color']) ? $mockup['color'] : '';
        $mockup_size = isset($mockup['size']) ? $mockup['size'] : '';
        $mockup_view = isset($mockup['view']) ? $mockup['view'] : '';
        $mockup_additional = isset($mockup['additional']) ? $mockup['additional'] : '';

        $type_labels = array(
            'text'   => __('Szöveg', 'mgcf'),
            'number' => __('Szám', 'mgcf'),
            'select' => __('Választólista', 'mgcf'),
            'date'   => __('Dátum', 'mgcf'),
            'color'  => __('Szín', 'mgcf'),
        );

        $card_classes = 'mg-custom-field-card' . ($is_new ? ' is-new' : '');
        echo '<div class="' . esc_attr($card_classes) . '">';
        echo '<form method="post" class="mg-custom-field-form">';
        wp_nonce_field(self::NONCE_ACTION, self::NONCE_FIELD);
        echo '<input type="hidden" name="product_id" value="' . esc_attr($product_id) . '" />';
        echo '<input type="hidden" name="mg_custom_fields_action" value="' . ($is_new ? 'add_field' : 'update_field') . '" />';
        if (!$is_new) {
            echo '<input type="hidden" name="field_id" value="' . esc_attr($field_id) . '" />';
        }
        $headline = $is_new ? __('Új mező', 'mgcf') : ($label ? $label : __('Névtelen mező', 'mgcf'));
        echo '<header class="mg-custom-field-card__header">';
        echo '<div class="mg-custom-field-card__title">' . esc_html($headline) . '</div>';
        $badge_text = $is_new ? __('Új', 'mgcf') : (isset($type_labels[$type]) ? $type_labels[$type] : strtoupper($type));
        $badge_class = $is_new ? 'mg-custom-field-card__badge is-accent' : 'mg-custom-field-card__badge';
        echo '<span class="' . esc_attr($badge_class) . '">' . esc_html($badge_text) . '</span>';
        echo '</header>';

        echo '<div class="mg-custom-field-card__body">';
        echo '<table class="form-table mg-custom-field-table">';
        echo '<tr><th scope="row"><label>' . esc_html__('Mező neve', 'mgcf') . '</label></th><td><input type="text" name="field_label" value="' . esc_attr($label) . '" class="regular-text" required /></td></tr>';
        echo '<tr><th scope="row"><label>' . esc_html__('Típus', 'mgcf') . '</label></th><td><select name="field_type">';
        foreach ($type_labels as $key => $label_text) {
            echo '<option value="' . esc_attr($key) . '"' . selected($type, $key, false) . '>' . esc_html($label_text) . '</option>';
        }
        echo '</select></td></tr>';
        echo '<tr><th scope="row">' . esc_html__('Kötelező', 'mgcf') . '</th><td><label><input type="checkbox" name="field_required" value="1"' . checked($required, true, false) . ' /> ' . esc_html__('Igen', 'mgcf') . '</label></td></tr>';
        echo '<tr><th scope="row"><label>' . esc_html__('Alapértelmezett érték', 'mgcf') . '</label></th><td><input type="text" name="field_default" value="' . esc_attr($default) . '" class="regular-text" /></td></tr>';
        echo '<tr><th scope="row"><label>' . esc_html__('Érvényességi minimum', 'mgcf') . '</label></th><td><input type="text" name="field_validation_min" value="' . esc_attr($validation_min) . '" class="regular-text" /></td></tr>';
        echo '<tr><th scope="row"><label>' . esc_html__('Érvényességi maximum', 'mgcf') . '</label></th><td><input type="text" name="field_validation_max" value="' . esc_attr($validation_max) . '" class="regular-text" /></td></tr>';
        $placement_options = MG_Custom_Fields_Manager::get_placement_options();
        if (!isset($placement_options[$placement])) {
            $placement = MG_Custom_Fields_Manager::normalize_placement($placement);
        }
        echo '<tr><th scope="row"><label>' . esc_html__('Elhelyezés', 'mgcf') . '</label></th><td><select name="field_placement">';
        foreach ($placement_options as $value => $option_label) {
            echo '<option value="' . esc_attr($value) . '"' . selected($placement, $value, false) . '>' . esc_html($option_label) . '</option>';
        }
        echo '</select></td></tr>';
        echo '<tr><th scope="row"><label>' . esc_html__('Sorrend', 'mgcf') . '</label></th><td><input type="number" name="field_position" value="' . esc_attr($position) . '" class="small-text" /></td></tr>';
        echo '<tr><th scope="row"><label>' . esc_html__('Leírás', 'mgcf') . '</label></th><td><textarea name="field_description" rows="2" class="large-text">' . esc_textarea($description) . '</textarea></td></tr>';
        echo '<tr><th scope="row"><label>' . esc_html__('Választólista értékek', 'mgcf') . '</label></th><td><textarea name="field_options" rows="3" class="large-text" placeholder="Érték1\nÉrték2">' . esc_textarea($options_text) . '</textarea><p class="description">' . esc_html__('Választólista típusnál soronként egy opció.', 'mgcf') . '</p></td></tr>';
        echo '<tr><th scope="row"><label>' . esc_html__('Árpótdíj típusa', 'mgcf') . '</label></th><td><select name="field_surcharge_type">';
        $surcharge_types = array(
            'none' => __('Nincs', 'mgcf'),
            'fixed' => __('Fix összeg', 'mgcf'),
            'percent' => __('Százalékos', 'mgcf'),
        );
        foreach ($surcharge_types as $key => $label_text) {
            echo '<option value="' . esc_attr($key) . '"' . selected($surcharge_type, $key, false) . '>' . esc_html($label_text) . '</option>';
        }
        echo '</select></td></tr>';
        echo '<tr><th scope="row"><label>' . esc_html__('Árpótdíj összege', 'mgcf') . '</label></th><td><input type="number" step="0.01" name="field_surcharge_amount" value="' . esc_attr($surcharge_amount) . '" class="small-text" /></td></tr>';
        echo '<tr><th scope="row"><label>' . esc_html__('Mockup X koordináta', 'mgcf') . '</label></th><td><input type="number" name="field_mockup_x" value="' . esc_attr($mockup_x) . '" class="small-text" /></td></tr>';
        echo '<tr><th scope="row"><label>' . esc_html__('Mockup Y koordináta', 'mgcf') . '</label></th><td><input type="number" name="field_mockup_y" value="' . esc_attr($mockup_y) . '" class="small-text" /></td></tr>';
        echo '<tr><th scope="row"><label>' . esc_html__('Mockup betűtípus', 'mgcf') . '</label></th><td><input type="text" name="field_mockup_font" value="' . esc_attr($mockup_font) . '" class="regular-text" /></td></tr>';
        echo '<tr><th scope="row"><label>' . esc_html__('Mockup szín', 'mgcf') . '</label></th><td><input type="text" name="field_mockup_color" value="' . esc_attr($mockup_color) . '" class="regular-text" placeholder="#000000" /></td></tr>';
        echo '<tr><th scope="row"><label>' . esc_html__('Mockup méret', 'mgcf') . '</label></th><td><input type="number" step="0.1" name="field_mockup_size" value="' . esc_attr($mockup_size) . '" class="small-text" /></td></tr>';
        echo '<tr><th scope="row"><label>' . esc_html__('Mockup nézet', 'mgcf') . '</label></th><td><input type="text" name="field_mockup_view" value="' . esc_attr($mockup_view) . '" class="regular-text" /></td></tr>';
        echo '<tr><th scope="row"><label>' . esc_html__('Mockup megjegyzés', 'mgcf') . '</label></th><td><input type="text" name="field_mockup_additional" value="' . esc_attr($mockup_additional) . '" class="regular-text" /></td></tr>';
        echo '</table>';
        echo '</div>';
        echo '<p class="submit">';
        echo '<button type="submit" class="button button-primary">' . esc_html($is_new ? __('Mező hozzáadása', 'mgcf') : __('Mező frissítése', 'mgcf')) . '</button>';
        if (!$is_new) {
            echo ' <button type="submit" name="mg_custom_fields_delete" value="1" class="button mg-delete-field" onclick="return confirm(\'' . esc_js(__('Biztosan törölni szeretnéd ezt a mezőt?', 'mgcf')) . '\');">' . esc_html__('Mező törlése', 'mgcf') . '</button>';
        }
        echo '</p>';
        echo '</form>';
        echo '</div>';
    }

    protected static function render_presets_section($product_id, $fields) {
        echo '<section class="mgcf-section">';
        echo '<div class="mgcf-section__header">';
        echo '<h3>' . esc_html__('Presetek', 'mgcf') . '</h3>';
        echo '<p>' . esc_html__('Mentheted a mezőkonfigurációt későbbi felhasználásra, vagy alkalmazhatod egy korábbi preset beállításait.', 'mgcf') . '</p>';
        echo '</div>';

        $presets = MG_Custom_Fields_Manager::get_presets();

        echo '<div class="mgcf-presets-grid">';

        if (!empty($fields)) {
            echo '<div class="mg-custom-presets__block">';
            echo '<h4>' . esc_html__('Jelenlegi mezők mentése presetként', 'mgcf') . '</h4>';
            echo '<form method="post" class="mg-custom-preset-form">';
            wp_nonce_field(self::NONCE_ACTION, self::NONCE_FIELD);
            echo '<input type="hidden" name="product_id" value="' . esc_attr($product_id) . '" />';
            echo '<input type="hidden" name="mg_custom_fields_action" value="save_preset" />';
            echo '<p><label for="mgcf-preset-name" class="mg-custom-preset-label">' . esc_html__('Preset neve', 'mgcf') . '</label>';
            echo '<input type="text" id="mgcf-preset-name" name="preset_name" class="regular-text" required /></p>';
            echo '<p class="description">' . esc_html__('A jelenlegi mezők beállításait egy név alatt elmentheted későbbi felhasználásra.', 'mgcf') . '</p>';
            echo '<p class="submit"><button type="submit" class="button">' . esc_html__('Preset mentése', 'mgcf') . '</button></p>';
            echo '</form>';
            echo '</div>';
        }

        echo '<div class="mg-custom-presets__block">';
        echo '<h4>' . esc_html__('Preset alkalmazása', 'mgcf') . '</h4>';
        if (!empty($presets)) {
            echo '<form method="post" class="mg-custom-preset-form">';
            wp_nonce_field(self::NONCE_ACTION, self::NONCE_FIELD);
            echo '<input type="hidden" name="product_id" value="' . esc_attr($product_id) . '" />';
            echo '<input type="hidden" name="mg_custom_fields_action" value="apply_preset" />';
            echo '<select name="preset_id">';
            echo '<option value="">' . esc_html__('Válassz presetet…', 'mgcf') . '</option>';
            foreach ($presets as $preset) {
                $name = isset($preset['name']) ? $preset['name'] : '';
                $id = isset($preset['id']) ? $preset['id'] : '';
                echo '<option value="' . esc_attr($id) . '">' . esc_html($name) . '</option>';
            }
            echo '</select>';
            echo '<p class="description">' . esc_html__('A kiválasztott preset mezői lecserélik a termék jelenlegi mezőit.', 'mgcf') . '</p>';
            echo '<p class="submit"><button type="submit" class="button button-primary">' . esc_html__('Preset hozzárendelése', 'mgcf') . '</button></p>';
            echo '</form>';
        } else {
            echo '<p class="mgcf-empty">' . esc_html__('Még nincs elmentett preset.', 'mgcf') . '</p>';
        }
        echo '</div>';

        if (!empty($presets)) {
            echo '<div class="mg-custom-presets__block">';
            echo '<h4>' . esc_html__('Elmentett presetek kezelése', 'mgcf') . '</h4>';
            echo '<ul class="mg-custom-preset-list">';
            foreach ($presets as $preset) {
                $name = isset($preset['name']) ? $preset['name'] : '';
                $id = isset($preset['id']) ? $preset['id'] : '';
                $updated = isset($preset['updated']) ? $preset['updated'] : '';
                echo '<li>';
                echo '<div class="mgcf-preset-item">';
                echo '<span class="mg-custom-preset-name">' . esc_html($name) . '</span>';
                if ($updated !== '') {
                    $formatted = $updated;
                    if (function_exists('mysql2date')) {
                        $formatted = mysql2date('Y.m.d H:i', $updated);
                    }
                    echo '<span class="mg-custom-preset-meta">' . esc_html(sprintf(__('Utolsó frissítés: %s', 'mgcf'), $formatted)) . '</span>';
                }
                echo '</div>';
                echo '<form method="post" class="mg-custom-preset-delete">';
                wp_nonce_field(self::NONCE_ACTION, self::NONCE_FIELD);
                echo '<input type="hidden" name="product_id" value="' . esc_attr($product_id) . '" />';
                echo '<input type="hidden" name="mg_custom_fields_action" value="delete_preset" />';
                echo '<input type="hidden" name="preset_id" value="' . esc_attr($id) . '" />';
                echo '<button type="submit" class="button-link delete" onclick="return confirm(\'' . esc_js(__('Biztosan törölni szeretnéd ezt a presetet?', 'mgcf')) . '\');">' . esc_html__('Törlés', 'mgcf') . '</button>';
                echo '</form>';
                echo '</li>';
            }
            echo '</ul>';
            echo '</div>';
        }

        echo '</div>';
        echo '</section>';
    }
}
