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
        add_action('wp_ajax_mgcf_save_product_assignments', array(__CLASS__, 'ajax_save_product_assignments'));
        add_action('wp_ajax_mgcf_update_preset', array(__CLASS__, 'ajax_update_preset'));
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

        $js_url = plugins_url('assets/js/custom-fields-admin.js', $base_file);
        $js_path = dirname(__DIR__) . '/assets/js/custom-fields-admin.js';
        wp_enqueue_script(
            'mg-custom-fields-admin-js',
            $js_url,
            array('jquery'),
            file_exists($js_path) ? filemtime($js_path) : '1.0.0',
            true
        );
        wp_localize_script('mg-custom-fields-admin-js', 'mgcfAdmin', array(
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonce'   => wp_create_nonce(self::NONCE_ACTION),
            'strings' => array(
                'saving'       => __('Mentés...', 'mgcf'),
                'saved'        => __('Mentve!', 'mgcf'),
                'error'        => __('Hiba történt', 'mgcf'),
                'confirmDelete'=> __('Biztosan törölni szeretnéd ezt a presetet?', 'mgcf'),
            ),
        ));
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
        
        $action = sanitize_key($_POST['mg_custom_fields_action']);
        $preset_id = isset($_POST['preset_id']) ? sanitize_key($_POST['preset_id']) : '';

        switch ($action) {
            case 'create_preset':
                $preset_name = isset($_POST['preset_name']) ? sanitize_text_field($_POST['preset_name']) : '';
                if ($preset_name === '') {
                    add_settings_error('mg_custom_fields_admin', 'mgcf_missing_preset_name', __('A preset neve kötelező.', 'mgcf'), 'error');
                    break;
                }
                $new_preset_id = MG_Custom_Fields_Manager::create_preset($preset_name);
                if ($new_preset_id) {
                    add_settings_error('mg_custom_fields_admin', 'mgcf_preset_created', __('Új preset létrehozva.', 'mgcf'), 'updated');
                    wp_safe_redirect(add_query_arg(array('page' => 'mockup-generator-custom-fields', 'preset_id' => $new_preset_id), admin_url('admin.php')));
                    exit;
                } else {
                    add_settings_error('mg_custom_fields_admin', 'mgcf_preset_exists', __('Ilyen névvel már létezik preset.', 'mgcf'), 'error');
                }
                break;

            case 'delete_preset':
                if ($preset_id === '') {
                    add_settings_error('mg_custom_fields_admin', 'mgcf_missing_delete_preset', __('Hiányzik a törlendő preset azonosítója.', 'mgcf'), 'error');
                    break;
                }
                MG_Custom_Fields_Manager::delete_preset($preset_id);
                add_settings_error('mg_custom_fields_admin', 'mgcf_preset_deleted', __('Preset törölve.', 'mgcf'), 'updated');
                wp_safe_redirect(add_query_arg('page', 'mockup-generator-custom-fields', admin_url('admin.php')));
                exit;

            case 'save_product_assignments':
                if ($preset_id === '') {
                    add_settings_error('mg_custom_fields_admin', 'mgcf_missing_preset', __('Hiányzó preset azonosító.', 'mgcf'), 'error');
                    break;
                }
                $product_ids = isset($_POST['product_ids']) && is_array($_POST['product_ids']) ? array_map('intval', $_POST['product_ids']) : array();
                if (MG_Custom_Fields_Manager::assign_products_to_preset($preset_id, $product_ids)) {
                    add_settings_error('mg_custom_fields_admin', 'mgcf_assignments_saved', __('Termék hozzárendelések mentve.', 'mgcf'), 'updated');
                } else {
                    add_settings_error('mg_custom_fields_admin', 'mgcf_assignments_failed', __('Nem sikerült menteni a hozzárendeléseket.', 'mgcf'), 'error');
                }
                break;

            case 'add_field':
            case 'update_field':
                if ($preset_id === '') {
                    add_settings_error('mg_custom_fields_admin', 'mgcf_missing_preset', __('Hiányzó preset azonosító.', 'mgcf'), 'error');
                    break;
                }
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
                $preset = MG_Custom_Fields_Manager::get_preset($preset_id);
                if (!$preset) {
                    add_settings_error('mg_custom_fields_admin', 'mgcf_preset_not_found', __('A preset nem található.', 'mgcf'), 'error');
                    break;
                }
                $fields = isset($preset['fields']) ? $preset['fields'] : array();
                $found = false;
                foreach ($fields as &$existing) {
                    if (!empty($existing['id']) && $existing['id'] === $field['id']) {
                        $existing = $field;
                        $found = true;
                        break;
                    }
                }
                if (!$found) {
                    $fields[] = $field;
                }
                MG_Custom_Fields_Manager::update_preset($preset_id, array('fields' => $fields));
                add_settings_error('mg_custom_fields_admin', 'mgcf_field_saved', __('A mező beállításai mentve.', 'mgcf'), 'updated');
                break;

            case 'delete_field':
                if ($preset_id === '') {
                    add_settings_error('mg_custom_fields_admin', 'mgcf_missing_preset', __('Hiányzó preset azonosító.', 'mgcf'), 'error');
                    break;
                }
                $field_id = isset($_POST['field_id']) ? sanitize_key($_POST['field_id']) : '';
                if ($field_id === '') {
                    add_settings_error('mg_custom_fields_admin', 'mgcf_missing_field_id', __('Hiányzik a mező azonosítója.', 'mgcf'), 'error');
                    break;
                }
                $preset = MG_Custom_Fields_Manager::get_preset($preset_id);
                if (!$preset) {
                    break;
                }
                $fields = isset($preset['fields']) ? $preset['fields'] : array();
                $fields = array_filter($fields, function($f) use ($field_id) {
                    return empty($f['id']) || $f['id'] !== $field_id;
                });
                MG_Custom_Fields_Manager::update_preset($preset_id, array('fields' => array_values($fields)));
                add_settings_error('mg_custom_fields_admin', 'mgcf_field_deleted', __('Mező törölve.', 'mgcf'), 'updated');
                break;

            case 'update_preset_name':
                if ($preset_id === '') {
                    add_settings_error('mg_custom_fields_admin', 'mgcf_missing_preset', __('Hiányzó preset azonosító.', 'mgcf'), 'error');
                    break;
                }
                $preset_name = isset($_POST['preset_name']) ? sanitize_text_field($_POST['preset_name']) : '';
                if ($preset_name === '') {
                    add_settings_error('mg_custom_fields_admin', 'mgcf_missing_preset_name', __('A preset neve kötelező.', 'mgcf'), 'error');
                    break;
                }
                MG_Custom_Fields_Manager::update_preset($preset_id, array('name' => $preset_name));
                add_settings_error('mg_custom_fields_admin', 'mgcf_preset_updated', __('Preset neve frissítve.', 'mgcf'), 'updated');
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

        $current_preset_id = isset($_GET['preset_id']) ? sanitize_key($_GET['preset_id']) : '';

        echo '<div class="wrap mg-custom-fields-admin mgcf-admin">';
        echo '<div class="mgcf-notices">';
        settings_errors('mg_custom_fields_admin');
        echo '</div>';

        if ($current_preset_id !== '') {
            $preset = MG_Custom_Fields_Manager::get_preset($current_preset_id);
            if ($preset) {
                self::render_preset_products_page($current_preset_id, $preset);
            } else {
                echo '<div class="mgcf-empty"><p>' . esc_html__('A preset nem található.', 'mgcf') . '</p></div>';
            }
        } else {
            self::render_header_main();
            self::render_presets_list();
        }

        echo '</div>';
    }

    protected static function render_header_main() {
        $title = __('Egyedi mezők', 'mgcf');
        $description = __('Kezelj preset-eket, amiket egyedi termékekhez tudsz hozzárendelni.', 'mgcf');

        echo '<header class="mgcf-hero">';
        echo '<div class="mgcf-hero__text">';
        echo '<h1>' . esc_html($title) . '</h1>';
        echo '<p>' . esc_html($description) . '</p>';
        echo '</div>';
        echo '<div class="mgcf-hero__actions">';
        echo '<button type="button" class="button button-primary" id="mgcf-new-preset-btn">' . esc_html__('Új preset', 'mgcf') . '</button>';
        echo '</div>';
        echo '</header>';

        // New preset modal
        echo '<div id="mgcf-new-preset-modal" class="mgcf-modal" style="display:none;">';
        echo '<div class="mgcf-modal-overlay"></div>';
        echo '<div class="mgcf-modal-content">';
        echo '<div class="mgcf-modal-header">';
        echo '<h2>' . esc_html__('Új preset létrehozása', 'mgcf') . '</h2>';
        echo '<button type="button" class="mgcf-modal-close">&times;</button>';
        echo '</div>';
        echo '<form method="post" class="mgcf-modal-body">';
        wp_nonce_field(self::NONCE_ACTION, self::NONCE_FIELD);
        echo '<input type="hidden" name="mg_custom_fields_action" value="create_preset" />';
        echo '<p><label for="mgcf-new-preset-name">' . esc_html__('Preset neve', 'mgcf') . '</label>';
        echo '<input type="text" id="mgcf-new-preset-name" name="preset_name" class="regular-text" required /></p>';
        echo '<p class="submit"><button type="submit" class="button button-primary">' . esc_html__('Létrehozás', 'mgcf') . '</button></p>';
        echo '</form>';
        echo '</div>';
        echo '</div>';
    }

    protected static function render_presets_list() {
        $presets = MG_Custom_Fields_Manager::get_presets();

        echo '<section class="mgcf-section">';
        echo '<div class="mgcf-section__header">';
        echo '<h2>' . esc_html__('Preset-ek listája', 'mgcf') . '</h2>';
        echo '<p>' . esc_html__('Válassz egy preset-et a termékek hozzárendeléséhez.', 'mgcf') . '</p>';
        echo '</div>';

        if (empty($presets)) {
            echo '<div class="mgcf-empty">';
            echo '<p>' . esc_html__('Még nincs preset létrehozva. Kattints az "Új preset" gombra a létrehozáshoz.', 'mgcf') . '</p>';
            echo '</div>';
            echo '</section>';
            return;
        }

        echo '<div class="mgcf-presets-cards">';
        foreach ($presets as $preset) {
            $preset_id = isset($preset['id']) ? $preset['id'] : '';
            $name = isset($preset['name']) ? $preset['name'] : __('(névtelen)', 'mgcf');
            $fields = isset($preset['fields']) ? $preset['fields'] : array();
            $product_ids = isset($preset['product_ids']) ? $preset['product_ids'] : array();
            $field_count = count($fields);
            $product_count = count($product_ids);
            $updated = isset($preset['updated']) ? $preset['updated'] : '';

            $edit_link = add_query_arg(array('page' => 'mockup-generator-custom-fields', 'preset_id' => $preset_id), admin_url('admin.php'));

            echo '<div class="mgcf-preset-card">';
            echo '<div class="mgcf-preset-card__header">';
            echo '<h3 class="mgcf-preset-card__title">' . esc_html($name) . '</h3>';
            echo '</div>';
            echo '<div class="mgcf-preset-card__body">';
            echo '<p><strong>' . esc_html__('Mezők:', 'mgcf') . '</strong> ' . intval($field_count) . '</p>';
            echo '<p><strong>' . esc_html__('Termékek:', 'mgcf') . '</strong> ' . intval($product_count) . '</p>';
            if ($updated) {
                $formatted = mysql2date('Y.m.d H:i', $updated);
                echo '<p class="mgcf-preset-card__meta">' . esc_html(sprintf(__('Frissítve: %s', 'mgcf'), $formatted)) . '</p>';
            }
            echo '</div>';
            echo '<div class="mgcf-preset-card__actions">';
            echo '<a href="' . esc_url($edit_link) . '" class="button button-primary">' . esc_html__('Termékek kezelése', 'mgcf') . '</a>';
            echo '<form method="post" style="display:inline;">';
            wp_nonce_field(self::NONCE_ACTION, self::NONCE_FIELD);
            echo '<input type="hidden" name="mg_custom_fields_action" value="delete_preset" />';
            echo '<input type="hidden" name="preset_id" value="' . esc_attr($preset_id) . '" />';
            echo '<button type="submit" class="button button-link-delete" onclick="return confirm(\'' . esc_js(__('Biztosan törölni szeretnéd ezt a presetet?', 'mgcf')) . '\');">' . esc_html__('Törlés', 'mgcf') . '</button>';
            echo '</form>';
            echo '</div>';
            echo '</div>';
        }
        echo '</div>';
        echo '</section>';
    }

    protected static function render_preset_products_page($preset_id, $preset) {
        $preset_name = isset($preset['name']) ? $preset['name'] : '';
        $fields = isset($preset['fields']) ? $preset['fields'] : array();
        $assigned_product_ids = MG_Custom_Fields_Manager::get_products_for_preset($preset_id);

        $back_link = remove_query_arg('preset_id');

        echo '<nav class="mgcf-breadcrumb">';
        echo '<a class="mgcf-breadcrumb__link" href="' . esc_url($back_link) . '">' . esc_html__('Preset-ek listája', 'mgcf') . '</a>';
        echo '<span class="mgcf-breadcrumb__divider">›</span>';
        echo '<span class="mgcf-breadcrumb__current">' . esc_html($preset_name) . '</span>';
        echo '</nav>';

        echo '<header class="mgcf-hero">';
        echo '<div class="mgcf-hero__text">';
        echo '<h1>' . esc_html($preset_name) . '</h1>';
        echo '<p>' . esc_html(sprintf(__('%d mező konfigurálva', 'mgcf'), count($fields))) . '</p>';
        echo '</div>';
        echo '<div class="mgcf-hero__actions">';
        echo '<button type="button" class="button button-secondary" id="mgcf-edit-preset-btn">' . esc_html__('Preset szerkesztése', 'mgcf') . '</button>';
        echo '</div>';
        echo '</header>';

        // Preset editor popup
        self::render_preset_editor_popup($preset_id, $preset);

        // Product assignment section
        self::render_product_assignment_section($preset_id, $assigned_product_ids);
    }

    protected static function render_preset_editor_popup($preset_id, $preset) {
        $preset_name = isset($preset['name']) ? $preset['name'] : '';
        $fields = isset($preset['fields']) ? $preset['fields'] : array();

        echo '<div id="mgcf-edit-preset-modal" class="mgcf-modal" style="display:none;">';
        echo '<div class="mgcf-modal-overlay"></div>';
        echo '<div class="mgcf-modal-content mgcf-modal-content--large">';
        echo '<div class="mgcf-modal-header">';
        echo '<h2>' . esc_html__('Preset beállítások', 'mgcf') . '</h2>';
        echo '<button type="button" class="mgcf-modal-close">&times;</button>';
        echo '</div>';
        echo '<div class="mgcf-modal-body">';

        // Preset name form
        echo '<form method="post" class="mgcf-preset-name-form">';
        wp_nonce_field(self::NONCE_ACTION, self::NONCE_FIELD);
        echo '<input type="hidden" name="mg_custom_fields_action" value="update_preset_name" />';
        echo '<input type="hidden" name="preset_id" value="' . esc_attr($preset_id) . '" />';
        echo '<p><label for="mgcf-preset-name-edit">' . esc_html__('Preset neve', 'mgcf') . '</label>';
        echo '<input type="text" id="mgcf-preset-name-edit" name="preset_name" value="' . esc_attr($preset_name) . '" class="regular-text" required />';
        echo '<button type="submit" class="button">' . esc_html__('Mentés', 'mgcf') . '</button></p>';
        echo '</form>';

        echo '<hr />';

        // Existing fields
        if (!empty($fields)) {
            echo '<h3>' . esc_html__('Mezők', 'mgcf') . '</h3>';
            echo '<div class="mgcf-field-grid">';
            foreach ($fields as $field) {
                self::render_field_editor_form($preset_id, $field, false);
            }
            echo '</div>';
        }

        // New field form
        echo '<h3>' . esc_html__('Új mező hozzáadása', 'mgcf') . '</h3>';
        echo '<div class="mgcf-field-grid">';
        self::render_field_editor_form($preset_id, null, true);
        echo '</div>';

        echo '</div>';
        echo '</div>';
        echo '</div>';
    }

    protected static function render_field_editor_form($preset_id, $field = null, $is_new = false) {
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
        echo '<input type="hidden" name="preset_id" value="' . esc_attr($preset_id) . '" />';
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
        echo '<tr><th scope="row"><label>' . esc_html__('Választólista értékek', 'mgcf') . '</label></th><td><textarea name="field_options" rows="3" class="large-text" placeholder="Érték1&#10;Érték2">' . esc_textarea($options_text) . '</textarea><p class="description">' . esc_html__('Választólista típusnál soronként egy opció.', 'mgcf') . '</p></td></tr>';
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
            echo ' <button type="submit" name="mg_custom_fields_action" value="delete_field" class="button mg-delete-field" onclick="return confirm(\'' . esc_js(__('Biztosan törölni szeretnéd ezt a mezőt?', 'mgcf')) . '\');">' . esc_html__('Mező törlése', 'mgcf') . '</button>';
        }
        echo '</p>';
        echo '</form>';
        echo '</div>';
    }

    protected static function render_product_assignment_section($preset_id, $assigned_product_ids) {
        $page = isset($_GET['paged']) ? max(1, intval($_GET['paged'])) : 1;
        $per_page = isset($_GET['per_page']) ? intval($_GET['per_page']) : 25;
        if (!in_array($per_page, array(25, 50, 100), true)) {
            $per_page = 25;
        }

        $data = MG_Custom_Fields_Manager::get_custom_products_paginated($page, $per_page);
        $products = $data['products'];
        $total = $data['total'];
        $pages = $data['pages'];

        echo '<section class="mgcf-section">';
        echo '<div class="mgcf-section__header">';
        echo '<h2>' . esc_html__('Termék hozzárendelés', 'mgcf') . '</h2>';
        echo '<p>' . esc_html__('Jelöld be azokat a termékeket, amelyekre ez a preset vonatkozzon.', 'mgcf') . '</p>';
        echo '</div>';

        if (empty($products) && $total === 0) {
            echo '<div class="mgcf-empty">';
            echo '<p>' . esc_html__('Nincsenek egyedi termékek. Először jelölj ki termékeket egyediként a bulk feltöltő oldalon.', 'mgcf') . '</p>';
            echo '</div>';
            echo '</section>';
            return;
        }

        // Per-page selector
        $base_url = add_query_arg(array('page' => 'mockup-generator-custom-fields', 'preset_id' => $preset_id), admin_url('admin.php'));
        echo '<div class="mgcf-pagination-controls">';
        echo '<form method="get" class="mgcf-per-page-form">';
        echo '<input type="hidden" name="page" value="mockup-generator-custom-fields" />';
        echo '<input type="hidden" name="preset_id" value="' . esc_attr($preset_id) . '" />';
        echo '<label>' . esc_html__('Elemek oldalanként:', 'mgcf') . ' ';
        echo '<select name="per_page" onchange="this.form.submit()">';
        foreach (array(25, 50, 100) as $opt) {
            echo '<option value="' . $opt . '"' . selected($per_page, $opt, false) . '>' . $opt . '</option>';
        }
        echo '</select></label>';
        echo '</form>';
        echo '<span class="mgcf-total">' . esc_html(sprintf(__('Összesen: %d termék', 'mgcf'), $total)) . '</span>';
        echo '</div>';

        // Product list form
        echo '<form method="post" id="mgcf-product-assignment-form">';
        wp_nonce_field(self::NONCE_ACTION, self::NONCE_FIELD);
        echo '<input type="hidden" name="mg_custom_fields_action" value="save_product_assignments" />';
        echo '<input type="hidden" name="preset_id" value="' . esc_attr($preset_id) . '" />';

        echo '<div class="mgcf-table-wrap">';
        echo '<table class="widefat striped mgcf-table">';
        echo '<thead><tr>';
        echo '<th class="mgcf-table__check"><input type="checkbox" id="mgcf-select-all" /></th>';
        echo '<th>' . esc_html__('Termék', 'mgcf') . '</th>';
        echo '<th>' . esc_html__('Állapot', 'mgcf') . '</th>';
        echo '</tr></thead>';
        echo '<tbody>';
        foreach ($products as $product) {
            $is_assigned = in_array($product->ID, $assigned_product_ids, true);
            $status = get_post_status_object($product->post_status);
            $status_label = $status ? $status->label : $product->post_status;

            echo '<tr>';
            echo '<td class="mgcf-table__check"><input type="checkbox" name="product_ids[]" value="' . esc_attr($product->ID) . '"' . checked($is_assigned, true, false) . ' /></td>';
            echo '<td>' . esc_html(get_the_title($product)) . '</td>';
            echo '<td>' . esc_html($status_label) . '</td>';
            echo '</tr>';
        }
        echo '</tbody></table>';
        echo '</div>';

        // Pagination
        if ($pages > 1) {
            echo '<div class="mgcf-pagination">';
            for ($i = 1; $i <= $pages; $i++) {
                $url = add_query_arg(array('paged' => $i, 'per_page' => $per_page), $base_url);
                $class = $i === $page ? 'button button-primary' : 'button';
                echo '<a href="' . esc_url($url) . '" class="' . $class . '">' . $i . '</a> ';
            }
            echo '</div>';
        }

        echo '<p class="submit">';
        echo '<button type="submit" class="button button-primary">' . esc_html__('Hozzárendelések mentése', 'mgcf') . '</button>';
        echo '</p>';
        echo '</form>';
        echo '</section>';
    }

    public static function ajax_save_product_assignments() {
        check_ajax_referer(self::NONCE_ACTION, 'nonce');

        if (!current_user_can('edit_products')) {
            wp_send_json_error(array('message' => __('Nincs jogosultság.', 'mgcf')));
        }

        $preset_id = isset($_POST['preset_id']) ? sanitize_key($_POST['preset_id']) : '';
        $product_ids = isset($_POST['product_ids']) && is_array($_POST['product_ids']) ? array_map('intval', $_POST['product_ids']) : array();

        if ($preset_id === '') {
            wp_send_json_error(array('message' => __('Hiányzó preset azonosító.', 'mgcf')));
        }

        if (MG_Custom_Fields_Manager::assign_products_to_preset($preset_id, $product_ids)) {
            wp_send_json_success(array('message' => __('Hozzárendelések mentve.', 'mgcf')));
        } else {
            wp_send_json_error(array('message' => __('Hiba történt a mentés során.', 'mgcf')));
        }
    }

    public static function ajax_update_preset() {
        check_ajax_referer(self::NONCE_ACTION, 'nonce');

        if (!current_user_can('edit_products')) {
            wp_send_json_error(array('message' => __('Nincs jogosultság.', 'mgcf')));
        }

        $preset_id = isset($_POST['preset_id']) ? sanitize_key($_POST['preset_id']) : '';
        $name = isset($_POST['name']) ? sanitize_text_field($_POST['name']) : '';

        if ($preset_id === '') {
            wp_send_json_error(array('message' => __('Hiányzó preset azonosító.', 'mgcf')));
        }

        $data = array();
        if ($name !== '') {
            $data['name'] = $name;
        }

        if (MG_Custom_Fields_Manager::update_preset($preset_id, $data)) {
            wp_send_json_success(array('message' => __('Preset frissítve.', 'mgcf')));
        } else {
            wp_send_json_error(array('message' => __('Hiba történt a mentés során.', 'mgcf')));
        }
    }
}
