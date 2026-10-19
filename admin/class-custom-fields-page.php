<?php
if (!defined('ABSPATH')) {
    exit;
}

class MG_Custom_Fields_Page {
    const NONCE_FIELD = 'mg_custom_fields_nonce';
    const NONCE_ACTION = 'mg_custom_fields_action';

    public static function init() {
        add_action('wp_ajax_mg_personalization_resolve', array('MG_Personalization_Import', 'ajax_resolve'));
        add_action('wp_ajax_mgcf_save_product_assignments', array(__CLASS__, 'ajax_save_product_assignments'));
        add_action('wp_ajax_mgcf_update_preset', array(__CLASS__, 'ajax_update_preset'));
        add_action('wp_ajax_mgcf_search_products_by_minta', array(__CLASS__, 'ajax_search_products_by_minta'));
        add_action('wp_ajax_mgcf_assign_searched_products', array(__CLASS__, 'ajax_assign_searched_products'));
        add_action('wp_ajax_mgcf_variant_ai_generate', array(__CLASS__, 'ajax_variant_ai_generate'));
        add_action('wp_ajax_mgcf_variant_ai_status', array(__CLASS__, 'ajax_variant_ai_status'));
    }

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

        // The linked-product mapping screen uses the native WordPress media
        // picker. Keep this limited to the custom-fields admin page.
        wp_enqueue_media();

        $js_url = plugins_url('assets/js/custom-fields-admin.js', $base_file);
        $js_path = dirname(__DIR__) . '/assets/js/custom-fields-admin.js';
        wp_enqueue_script(
            'mg-custom-fields-admin-js',
            $js_url,
            array('jquery'),
            file_exists($js_path) ? filemtime($js_path) : '1.0.0',
            true
        );
        wp_localize_script('mg-custom-fields-admin-js', 'mgPersonalizationPresets', MG_Custom_Fields_Manager::get_presets());
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
            case 'save_personalization_mappings':
                $result = MG_Personalization_Import::save_mappings(wp_unslash($_POST['personalization_mappings'] ?? array()));
                add_settings_error('mg_custom_fields_admin', 'mg_personalization_saved', is_wp_error($result) ? $result->get_error_message() : 'A megfeleltetések mentve.', is_wp_error($result) ? 'error' : 'updated');
                break;
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
                $visible_ids  = isset($_POST['visible_product_ids']) && is_array($_POST['visible_product_ids']) ? array_map('intval', $_POST['visible_product_ids']) : array();
                if (MG_Custom_Fields_Manager::assign_products_to_preset_merge($preset_id, $product_ids, $visible_ids)) {
                    add_settings_error('mg_custom_fields_admin', 'mgcf_assignments_saved', __('Termék hozzárendelések mentve.', 'mgcf'), 'updated');
                } else {
                    add_settings_error('mg_custom_fields_admin', 'mgcf_assignments_failed', __('Nem sikerült menteni a hozzárendeléseket.', 'mgcf'), 'error');
                }
                break;

            case 'save_product_variant_mapping':
                $variant_product_id = isset($_POST['variant_product_id']) ? absint($_POST['variant_product_id']) : 0;
                $variant_field_id = isset($_POST['field_id']) ? sanitize_key($_POST['field_id']) : '';
                $variant_context = self::get_variant_context($preset_id, $variant_product_id, $variant_field_id);
                if (is_wp_error($variant_context)) {
                    add_settings_error('mg_custom_fields_admin', 'mgcf_variant_mapping_invalid', $variant_context->get_error_message(), 'error');
                    break;
                }

                $existing_mapping = MG_Custom_Field_Product_Variants::get_mapping($variant_product_id, $variant_field_id);
                $mapping = self::read_variant_mapping_from_request($variant_context['field'], $existing_mapping);
                $saved = MG_Custom_Field_Product_Variants::save_mapping($variant_product_id, $variant_field_id, $mapping);
                if (is_wp_error($saved)) {
                    add_settings_error('mg_custom_fields_admin', 'mgcf_variant_mapping_failed', $saved->get_error_message(), 'error');
                    break;
                }
                $base_slug = isset($_POST['variant_base_slug']) ? sanitize_title(wp_unslash($_POST['variant_base_slug'])) : '';
                $base_label = '';
                foreach (self::get_variant_options($variant_context['field']) as $option) {
                    if ($option['slug'] === $base_slug) {
                        $base_label = $option['label'];
                    }
                }
                $group_exists = get_post_meta($variant_product_id, MG_Custom_Field_Product_Variants::META_GROUP, true) !== '';
                if ($base_label !== '' && !$group_exists) {
                    MG_Custom_Field_Product_Variants::save_config($variant_product_id, $variant_field_id, array('base_slug' => $base_slug));
                }
                if ($base_label !== '' && MG_Custom_Field_Product_Variants::ensure_base_attachment($variant_product_id, $variant_field_id, MG_Custom_Field_Product_Variants::get_base_slug($variant_product_id, $variant_field_id, self::get_variant_options($variant_context['field'])), $base_label) <= 0) {
                    add_settings_error('mg_custom_fields_admin', 'mgcf_variant_base_png', __('A termék mostani mintájához nem található PNG. Az alapértékhez válaszd ki kézzel a PNG-t.', 'mgcf'), 'error');
                    break;
                }

                $counts = self::get_variant_mapping_counts($variant_product_id, $variant_field_id, $variant_context['field']);
                set_transient(
                    'mg_custom_fields_notice_' . get_current_user_id(),
                    array(
                        'type' => 'updated',
                        'message' => sprintf(__('PNG-mapping mentve: %1$d/%2$d értékhez van minta.', 'mgcf'), $counts['mapped'], $counts['total']),
                    ),
                    60
                );
                wp_safe_redirect(self::get_variant_mapping_url($preset_id, $variant_product_id, $variant_field_id));
                exit;

            case 'import_product_variant_pngs':
                $variant_product_id = isset($_POST['variant_product_id']) ? absint($_POST['variant_product_id']) : 0;
                $variant_field_id = isset($_POST['field_id']) ? sanitize_key($_POST['field_id']) : '';
                $variant_context = self::get_variant_context($preset_id, $variant_product_id, $variant_field_id);
                if (is_wp_error($variant_context)) {
                    add_settings_error('mg_custom_fields_admin', 'mgcf_variant_ai_invalid', $variant_context->get_error_message(), 'error');
                    break;
                }
                if (empty($_POST['variant_ai_enabled'])) {
                    add_settings_error('mg_custom_fields_admin', 'mgcf_variant_ai_disabled', __('Az AI/JSON mód nincs bekapcsolva.', 'mgcf'), 'error');
                    break;
                }

                $imported = self::import_variant_ai_pngs($variant_context);
                if (is_wp_error($imported)) {
                    add_settings_error('mg_custom_fields_admin', 'mgcf_variant_ai_failed', $imported->get_error_message(), 'error');
                    break;
                }

                $counts = self::get_variant_mapping_counts($variant_product_id, $variant_field_id, $variant_context['field']);
                set_transient(
                    'mg_custom_fields_notice_' . get_current_user_id(),
                    array(
                        'type' => 'updated',
                        'message' => sprintf(
                            __('AI/JSON PNG-import kész: %1$d PNG hozzárendelve (%2$d/%3$d értékhez). A kapcsolt termékek generálása továbbra is külön művelet.', 'mgcf'),
                            absint($imported['imported']),
                            $counts['mapped'],
                            $counts['total']
                        ),
                    ),
                    60
                );
                wp_safe_redirect(self::get_variant_mapping_url($preset_id, $variant_product_id, $variant_field_id));
                exit;

            case 'generate_product_variants':
                @set_time_limit(1200);
                @ini_set('memory_limit', '1024M');
                $variant_product_id = isset($_POST['variant_product_id']) ? absint($_POST['variant_product_id']) : 0;
                $variant_field_id = isset($_POST['field_id']) ? sanitize_key($_POST['field_id']) : '';
                $variant_context = self::get_variant_context($preset_id, $variant_product_id, $variant_field_id);
                if (is_wp_error($variant_context)) {
                    add_settings_error('mg_custom_fields_admin', 'mgcf_variant_generation_invalid', $variant_context->get_error_message(), 'error');
                    break;
                }
                // Members copy the source's fields, so refresh them from the preset first.
                MG_Custom_Fields_Manager::apply_preset_to_product($variant_product_id, $preset_id);
                $validated = MG_Custom_Field_Product_Variants::validate_completeness($variant_product_id, $variant_field_id, null, $variant_context['field']);
                if (is_wp_error($validated)) {
                    $counts = self::get_variant_mapping_counts($variant_product_id, $variant_field_id, $variant_context['field']);
                    add_settings_error(
                        'mg_custom_fields_admin',
                        'mgcf_variant_generation_incomplete',
                        sprintf(__('A generálás blokkolva: %1$d/%2$d PNG van megadva. %3$s', 'mgcf'), $counts['mapped'], $counts['total'], $validated->get_error_message()),
                        'error'
                    );
                    break;
                }
                $generated = MG_Custom_Field_Product_Variants::generate_or_update(
                    $variant_product_id,
                    $variant_field_id,
                    array(
                        'mapping' => $validated['mapping'],
                        'field' => $variant_context['field'],
                        'preset_id' => $preset_id,
                    )
                );
                if (is_wp_error($generated)) {
                    add_settings_error('mg_custom_fields_admin', 'mgcf_variant_generation_failed', $generated->get_error_message(), 'error');
                    break;
                }
                $generated_count = !empty($generated['products']) && is_array($generated['products']) ? count($generated['products']) : 0;
                $rendered = MG_Custom_Field_Product_Variants::render_group_mockups($variant_product_id, $variant_field_id);
                $notice_type = 'updated';
                $notice_message = sprintf(__('Kapcsolt termékek és mockupok generálva/frissítve: %d.', 'mgcf'), $generated_count);
                if (is_wp_error($rendered)) {
                    $notice_type = 'warning';
                    $notice_message = sprintf(
                        __('A %1$d kapcsolt termék elkészült, de a mockup-generálás nem fejeződött be: %2$s', 'mgcf'),
                        $generated_count,
                        $rendered->get_error_message()
                    );
                } elseif (!empty($rendered['products']) && is_array($rendered['products'])) {
                    $render_errors = 0;
                    foreach ($rendered['products'] as $render_row) {
                        $render_errors += !empty($render_row['errors']) && is_array($render_row['errors']) ? count($render_row['errors']) : 0;
                    }
                    if ($render_errors > 0) {
                        $notice_type = 'warning';
                        $notice_message = sprintf(
                            __('A %1$d kapcsolt termék elkészült; %2$d mockup-típusnál hiba történt. A sikeres képek használhatók.', 'mgcf'),
                            $generated_count,
                            $render_errors
                        );
                    }
                }
                set_transient(
                    'mg_custom_fields_notice_' . get_current_user_id(),
                    array(
                        'type' => $notice_type,
                        'message' => $notice_message,
                    ),
                    60
                );
                wp_safe_redirect(self::get_variant_mapping_url($preset_id, $variant_product_id, $variant_field_id));
                exit;

            case 'add_field':
            case 'update_field':
                if ($preset_id === '') {
                    add_settings_error('mg_custom_fields_admin', 'mgcf_missing_preset', __('Hiányzó preset azonosító.', 'mgcf'), 'error');
                    break;
                }
                $field = self::read_field_from_request();
                if (!empty($field['ai_print_enabled']) && (trim($field['ai_print_prompt']) === '' || strpos($field['ai_print_prompt'], '{{ertek}}') === false)) {
                    add_settings_error('mg_custom_fields_admin', 'mgcf_ai_print_prompt', __('Az AI nyomat utasítása kötelező, és tartalmaznia kell a {{ertek}} helyőrzőt.', 'mgcf'), 'error');
                    break;
                }
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
                if (!empty($field['linked_product_variants'])) {
                    $linked_conflict = false;
                    foreach ($fields as $existing_field) {
                        $existing_id = !empty($existing_field['id']) ? sanitize_key($existing_field['id']) : '';
                        if ($existing_id !== $field['id'] && !empty($existing_field['linked_product_variants'])) {
                            $linked_conflict = true;
                            break;
                        }
                    }
                    if ($linked_conflict) {
                        add_settings_error(
                            'mg_custom_fields_admin',
                            'mgcf_linked_field_conflict',
                            __('Egy presetben csak egy mezőnél lehetnek minta variációk.', 'mgcf'),
                            'error'
                        );
                        break;
                    }
                    if (trim($field['variation_ai_prompt']) !== '' && strpos($field['variation_ai_prompt'], '{{ertek}}') === false) {
                        add_settings_error('mg_custom_fields_admin', 'mgcf_variation_prompt', __('A variáció AI-utasításnak tartalmaznia kell a {{ertek}} helyőrzőt.', 'mgcf'), 'error');
                        break;
                    }
                    if ($field['type'] !== 'select' && trim($field['variation_values']) === '') {
                        add_settings_error('mg_custom_fields_admin', 'mgcf_variation_values_missing', __('Beírós mezőnél add meg a variációk listáját (soronként egy érték).', 'mgcf'), 'error');
                        break;
                    }
                }
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
                set_transient('mg_custom_fields_notice_' . get_current_user_id(), array('type' => 'updated', 'message' => __('A mező beállításai mentve.', 'mgcf')), 60);
                wp_safe_redirect(add_query_arg(array('page' => 'mockup-generator-custom-fields', 'preset_id' => $preset_id), admin_url('admin.php')));
                exit;

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
                set_transient('mg_custom_fields_notice_' . get_current_user_id(), array('type' => 'updated', 'message' => __('Mező törölve.', 'mgcf')), 60);
                wp_safe_redirect(add_query_arg(array('page' => 'mockup-generator-custom-fields', 'preset_id' => $preset_id), admin_url('admin.php')));
                exit;

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
                set_transient('mg_custom_fields_notice_' . get_current_user_id(), array('type' => 'updated', 'message' => __('Preset neve frissítve.', 'mgcf')), 60);
                wp_safe_redirect(add_query_arg(array('page' => 'mockup-generator-custom-fields', 'preset_id' => $preset_id), admin_url('admin.php')));
                exit;
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
        $field['ai_print_enabled'] = !empty($_POST['field_ai_print_enabled']);
        $field['ai_print_prompt'] = isset($_POST['field_ai_print_prompt']) && is_string($_POST['field_ai_print_prompt']) ? sanitize_textarea_field(wp_unslash($_POST['field_ai_print_prompt'])) : '';
        $options_raw = isset($_POST['field_options']) ? wp_kses_post($_POST['field_options']) : '';
        $field['options'] = $options_raw;
        $field['linked_product_variants'] = in_array($field['type'], array('select', 'text', 'number'), true) && !empty($_POST['field_linked_product_variants']);
        $field['variation_ai_prompt'] = isset($_POST['field_variation_ai_prompt']) && is_string($_POST['field_variation_ai_prompt']) ? sanitize_textarea_field(wp_unslash($_POST['field_variation_ai_prompt'])) : '';
        $field['variation_values'] = isset($_POST['field_variation_values']) && is_string($_POST['field_variation_values']) ? sanitize_textarea_field(wp_unslash($_POST['field_variation_values'])) : '';
        $field['linked_option_categories'] = array();
        if (isset($_POST['field_linked_categories']) && is_array($_POST['field_linked_categories'])) {
            foreach (wp_unslash($_POST['field_linked_categories']) as $slug => $term_id) {
                $field['linked_option_categories'][sanitize_title($slug)] = absint($term_id);
            }
        }
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
        $variant_product_id = isset($_GET['variant_product_id']) ? absint($_GET['variant_product_id']) : 0;
        $variant_field_id = isset($_GET['field_id']) ? sanitize_key($_GET['field_id']) : '';

        echo '<div class="wrap mg-custom-fields-admin mgcf-admin">';
        echo '<div class="mgcf-notices">';
        settings_errors('mg_custom_fields_admin');
        $transient_key = 'mg_custom_fields_notice_' . get_current_user_id();
        $transient_notice = get_transient($transient_key);
        if ($transient_notice) {
            delete_transient($transient_key);
            $notice_type = isset($transient_notice['type']) ? $transient_notice['type'] : 'updated';
            $notice_msg  = isset($transient_notice['message']) ? $transient_notice['message'] : '';
            if ($notice_msg) {
                echo '<div class="notice notice-' . esc_attr($notice_type) . ' is-dismissible"><p>' . esc_html($notice_msg) . '</p></div>';
            }
        }
        echo '</div>';

        if ($current_preset_id !== '') {
            $preset = MG_Custom_Fields_Manager::get_preset($current_preset_id);
            if ($preset) {
                if ($variant_product_id > 0 && $variant_field_id !== '') {
                    self::render_variant_mapping_page($current_preset_id, $preset, $variant_product_id, $variant_field_id);
                } else {
                    self::render_preset_products_page($current_preset_id, $preset);
                }
            } else {
                echo '<div class="mgcf-empty"><p>' . esc_html__('A preset nem található.', 'mgcf') . '</p></div>';
            }
        } else {
            self::render_header_main();
            MG_Personalization_Import::render_admin();
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

        // Product search section
        self::render_product_search_section($preset_id);

        // Product assignment section
        self::render_product_assignment_section($preset_id, $assigned_product_ids);
    }

    /**
     * Render the PNG mapping editor for one assigned source product.
     *
     * This is intentionally a separate view on the same admin page so the
     * existing preset/product assignment workflow remains unchanged for
     * fields that do not use linked products.
     */
    protected static function render_variant_mapping_page($preset_id, $preset, $product_id, $field_id) {
        $context = self::get_variant_context($preset_id, $product_id, $field_id);
        if (is_wp_error($context)) {
            echo '<div class="mgcf-empty"><p>' . esc_html($context->get_error_message()) . '</p></div>';
            return;
        }

        $field = $context['field'];
        $options = self::get_variant_options($field);
        $product = function_exists('wc_get_product') ? wc_get_product($product_id) : null;
        $product_name = $product && method_exists($product, 'get_name') ? $product->get_name() : get_the_title($product_id);
        $config = MG_Custom_Field_Product_Variants::get_config($product_id, $field_id);
        $mapping = MG_Custom_Field_Product_Variants::get_mapping($product_id, $field_id);
        $counts = self::get_variant_mapping_counts($product_id, $field_id, $field);
        $validation = MG_Custom_Field_Product_Variants::validate_completeness($product_id, $field_id, null, $field);
        $complete = !is_wp_error($validation);
        $base_saved = !empty($config['base_slug']);
        $base_slug = MG_Custom_Field_Product_Variants::get_base_slug($product_id, $field_id, $options);
        $group_exists = get_post_meta($product_id, MG_Custom_Field_Product_Variants::META_GROUP, true) !== '';
        // Names depend on the base value, so they are only previewed once it is known.
        $names = ($base_saved || $group_exists) ? MG_Custom_Field_Product_Variants::preview_names($product_id, $field_id, $options, $base_slug) : array();
        $categories = MG_Custom_Field_Product_Variants::get_option_categories($field);
        $back_link = add_query_arg(array('page' => 'mockup-generator-custom-fields', 'preset_id' => $preset_id), admin_url('admin.php'));

        echo '<nav class="mgcf-breadcrumb">';
        echo '<a class="mgcf-breadcrumb__link" href="' . esc_url($back_link) . '">' . esc_html__('Preset termékei', 'mgcf') . '</a>';
        echo '<span class="mgcf-breadcrumb__divider">›</span>';
        echo '<span class="mgcf-breadcrumb__current">' . esc_html($product_name) . '</span>';
        echo '</nav>';

        echo '<header class="mgcf-hero">';
        echo '<div class="mgcf-hero__text">';
        echo '<h1>' . esc_html(sprintf(__('%s – Minta variációk', 'mgcf'), $product_name)) . '</h1>';
        echo '<p>' . esc_html(sprintf(__('%s mező: %d/%d PNG megadva', 'mgcf'), isset($field['label']) ? $field['label'] : '', $counts['mapped'], $counts['total'])) . '</p>';
        echo '</div>';
        echo '<div class="mgcf-hero__actions">';
        echo '<a href="' . esc_url($back_link) . '" class="button button-secondary">' . esc_html__('Vissza a termékekhez', 'mgcf') . '</a>';
        echo '</div>';
        echo '</header>';

        echo '<section class="mgcf-section mgcf-variant-mapping-section">';
        echo '<form method="post" class="mgcf-variant-mapping-form">';
        wp_nonce_field(self::NONCE_ACTION, self::NONCE_FIELD);
        echo '<input type="hidden" name="mg_custom_fields_action" value="save_product_variant_mapping" />';
        echo '<input type="hidden" name="preset_id" value="' . esc_attr($preset_id) . '" />';
        echo '<input type="hidden" name="variant_product_id" value="' . esc_attr($product_id) . '" />';
        echo '<input type="hidden" name="field_id" value="' . esc_attr($field_id) . '" />';

        echo '<div class="mgcf-section__header">';
        echo '<h2>' . esc_html__('1. Alapbeállítás', 'mgcf') . '</h2>';
        echo '<p>' . esc_html__('Melyik érték szerepel most a minta képén? Ez az eredeti termék marad, a többi értékből új termék készül.', 'mgcf') . '</p>';
        echo '</div>';
        echo '<p><select name="variant_base_slug" id="mgcf-variant-base"' . disabled($group_exists, true, false) . '>';
        if (!$base_saved && !$group_exists) {
            echo '<option value="">' . esc_html__('Válaszd ki…', 'mgcf') . '</option>';
        }
        foreach ($options as $option) {
            $selected = ($base_saved || $group_exists) && $option['slug'] === $base_slug;
            echo '<option value="' . esc_attr($option['slug']) . '"' . selected($selected, true, false) . '>' . esc_html($option['label']) . '</option>';
        }
        echo '</select>';
        if ($group_exists) {
            echo '<input type="hidden" name="variant_base_slug" value="' . esc_attr($base_slug) . '" />';
            echo ' <span class="description">' . esc_html__('A csoport már létezik, az alapbeállítás nem módosítható.', 'mgcf') . '</span>';
        }
        echo '</p>';

        echo '<div class="mgcf-section__header">';
        echo '<h2>' . esc_html__('2. PNG-k és jóváhagyás', 'mgcf') . '</h2>';
        echo '<p>' . esc_html__('Az alapérték PNG-je a termék mostani mintája. A többit az AI elkészíti a preset variáció-utasításával, vagy kézzel is kiválaszthatsz PNG-t. Az AI-képeket jóvá kell hagyni: ezek így kerülnek nyomtatásra, exportkor már nem módosulnak.', 'mgcf') . '</p>';
        echo '</div>';
        if ($base_saved || $group_exists) {
            echo '<p class="mgcf-variant-ai-actions"><button type="button" class="button button-primary" id="mgcf-variant-ai-all"' . ' data-preset="' . esc_attr($preset_id) . '" data-product="' . esc_attr($product_id) . '" data-field="' . esc_attr($field_id) . '">' . esc_html__('Hiányzó PNG-k elkészítése AI-val', 'mgcf') . '</button> ';
            echo '<button type="button" class="button" id="mgcf-variant-approve-all">' . esc_html__('Összes kijelölése jóváhagyásra', 'mgcf') . '</button> ';
            echo '<span id="mgcf-variant-ai-progress" class="mgcf-variant-ai-progress" role="status" aria-live="polite"></span></p>';
        } else {
            echo '<p class="description">' . esc_html__('Az AI-generáláshoz előbb válaszd ki és mentsd az alapbeállítást.', 'mgcf') . '</p>';
        }

        echo '<div class="mgcf-table-wrap">';
        echo '<table class="widefat striped mgcf-table mgcf-variant-mapping-table">';
        echo '<thead><tr><th>' . esc_html__('Érték', 'mgcf') . '</th><th>' . esc_html__('PNG', 'mgcf') . '</th><th>' . esc_html__('Előnézet', 'mgcf') . '</th><th>' . esc_html__('Jóváhagyva', 'mgcf') . '</th><th>' . esc_html__('Termék neve és alkategória', 'mgcf') . '</th></tr></thead><tbody>';
        foreach ($options as $option) {
            $slug = $option['slug'];
            $is_base = ($base_saved || $group_exists) && $slug === $base_slug;
            $entry = isset($mapping[$slug]) && is_array($mapping[$slug]) ? $mapping[$slug] : array();
            $attachment_id = !empty($entry['attachment_id']) ? absint($entry['attachment_id']) : 0;
            if ($attachment_id > 0 && !self::is_png_attachment($attachment_id)) {
                $attachment_id = 0;
            }
            $ai_generated = $attachment_id > 0 && !empty($entry['ai_generated']);
            $approved = $attachment_id > 0 && (!array_key_exists('approved', $entry) || !empty($entry['approved']));
            $input_id = 'mgcf-png-' . sanitize_key($field_id) . '-' . sanitize_key($slug);
            $preview_id = $input_id . '-preview';
            echo '<tr class="mgcf-variant-row" data-slug="' . esc_attr($slug) . '" data-base="' . ($is_base ? '1' : '0') . '" data-has-png="' . ($attachment_id > 0 ? '1' : '0') . '">';
            echo '<th scope="row"><label for="' . esc_attr($input_id) . '">' . esc_html($option['label']) . '</label>';
            if ($is_base) {
                echo '<br /><span class="mgcf-variant-badge">' . esc_html__('Alap – eredeti termék', 'mgcf') . '</span>';
            }
            echo '</th>';
            echo '<td><div class="mgcf-variant-mapping__asset">';
            echo '<input type="hidden" id="' . esc_attr($input_id) . '" class="mgcf-variant-attachment" name="variant_mapping[' . esc_attr($slug) . ']" value="' . esc_attr($attachment_id) . '" />';
            echo '<input type="hidden" class="mgcf-variant-ai-flag" name="variant_ai[' . esc_attr($slug) . ']" value="' . ($ai_generated ? '1' : '0') . '" />';
            echo '<button type="button" class="button mgcf-png-select" data-input="#' . esc_attr($input_id) . '" data-preview="#' . esc_attr($preview_id) . '">' . esc_html__('PNG kiválasztása', 'mgcf') . '</button> ';
            if (!$is_base && ($base_saved || $group_exists)) {
                echo '<button type="button" class="button mgcf-variant-ai-one">' . esc_html($attachment_id > 0 ? __('Újragenerálás AI-val', 'mgcf') : __('Elkészítés AI-val', 'mgcf')) . '</button> ';
            }
            echo '<button type="button" class="button-link-delete mgcf-png-remove" data-input="#' . esc_attr($input_id) . '" data-preview="#' . esc_attr($preview_id) . '"' . ($attachment_id > 0 ? '' : ' style="display:none;"') . '>' . esc_html__('Törlés', 'mgcf') . '</button>';
            echo '<div class="mgcf-variant-ai-status" role="status" aria-live="polite">' . ($ai_generated ? esc_html__('AI-val készült', 'mgcf') : '') . '</div>';
            echo '</div></td>';
            echo '<td class="mgcf-variant-mapping__preview">';
            if ($attachment_id > 0) {
                $full = wp_get_attachment_url($attachment_id);
                $image = wp_get_attachment_image($attachment_id, 'medium', false, array('id' => $preview_id, 'class' => 'mgcf-variant-png-preview', 'alt' => $option['label']));
                echo $image ? '<a href="' . esc_url($full) . '" target="_blank" rel="noopener noreferrer">' . $image . '</a>' : '<span id="' . esc_attr($preview_id) . '" class="mgcf-no-image">—</span>';
            } else {
                echo '<span id="' . esc_attr($preview_id) . '" class="mgcf-no-image">—</span>';
            }
            echo '</td>';
            echo '<td><label><input type="checkbox" class="mgcf-variant-approved" name="variant_approved[' . esc_attr($slug) . ']" value="1"' . checked($approved, true, false) . disabled($is_base || ($attachment_id > 0 && !$ai_generated), true, false) . ' /> ' . esc_html__('Rendben', 'mgcf') . '</label></td>';
            $category = !empty($categories[$slug]) ? get_term($categories[$slug], 'product_cat') : null;
            echo '<td>' . esc_html(isset($names[$slug]) ? $names[$slug] : '') . '<br /><span class="description">' . esc_html($category && !is_wp_error($category) ? $category->name : __('nincs alkategória', 'mgcf')) . '</span></td>';
            echo '</tr>';
        }
        echo '</tbody></table></div>';
        echo '<p class="submit"><button type="submit" class="button button-primary">' . esc_html__('Mentés', 'mgcf') . '</button></p>';
        echo '</form>';

        $ai_options = array();
        foreach (self::get_variant_options($field) as $option) {
            $ai_options[] = array(
                'slug' => $option['slug'],
                'label' => $option['label'],
            );
        }
        echo '<section class="mgcf-variant-ai-section">';
        echo '<div class="mgcf-variant-ai__header">';
        echo '<h3>' . esc_html__('AI adatok mód – PNG hozzárendelés', 'mgcf') . '</h3>';
        echo '<p>' . esc_html__('Opcionális mód: válassz azonos nevű PNG és JSON párokat. A JSON categories.main vagy categories.sub mezője alapján történik az érték felismerése. A kiválasztás csak előnézetet készít; importálás kizárólag a külön gombbal indul.', 'mgcf') . '</p>';
        echo '</div>';
        echo '<form method="post" enctype="multipart/form-data" id="mgcf-variant-ai-import-form" class="mgcf-variant-ai-import-form" data-options="' . esc_attr(wp_json_encode($ai_options)) . '">';
        wp_nonce_field(self::NONCE_ACTION, self::NONCE_FIELD);
        echo '<input type="hidden" name="mg_custom_fields_action" value="import_product_variant_pngs" />';
        echo '<input type="hidden" name="preset_id" value="' . esc_attr($preset_id) . '" />';
        echo '<input type="hidden" name="variant_product_id" value="' . esc_attr($product_id) . '" />';
        echo '<input type="hidden" name="field_id" value="' . esc_attr($field_id) . '" />';
        echo '<p><label><input type="checkbox" id="mgcf-variant-ai-toggle" name="variant_ai_enabled" value="1" /> ' . esc_html__('AI/JSON mód bekapcsolása', 'mgcf') . '</label></p>';
        echo '<div id="mgcf-variant-ai-controls" class="mgcf-variant-ai__controls" style="display:none;">';
        echo '<p><label for="mgcf-variant-ai-files"><strong>' . esc_html__('PNG és JSON fájlok', 'mgcf') . '</strong></label><br /><input type="file" id="mgcf-variant-ai-files" name="variant_ai_files[]" accept=".png,.json,image/png,application/json" multiple /></p>';
        echo '<p class="description">' . esc_html__('Válaszd ki egyben a fájlokat. Ugyanannak a párnak ugyanaz legyen a fájlnév alapja, a kiterjesztéstől eltekintve. Példa: januar.png + januar.json.', 'mgcf') . '</p>';
        echo '<div id="mgcf-variant-ai-status" class="mgcf-variant-ai__status" role="status" aria-live="polite">' . esc_html__('Válassz fájlokat az előnézethez.', 'mgcf') . '</div>';
        echo '<div class="mgcf-table-wrap"><table class="widefat striped mgcf-table mgcf-variant-ai-table"><thead><tr><th>' . esc_html__('PNG', 'mgcf') . '</th><th>' . esc_html__('JSON pár', 'mgcf') . '</th><th>' . esc_html__('Felismerés', 'mgcf') . '</th><th>' . esc_html__('Állapot', 'mgcf') . '</th></tr></thead><tbody id="mgcf-variant-ai-preview"><tr class="no-items"><td colspan="4">' . esc_html__('Még nincs kiválasztott fájl.', 'mgcf') . '</td></tr></tbody></table></div>';
        echo '<p class="submit"><button type="submit" id="mgcf-variant-ai-submit" class="button button-primary" disabled="disabled">' . esc_html__('Érvényes PNG-párok importálása', 'mgcf') . '</button></p>';
        echo '</div>';
        echo '</form>';
        echo '</section>';

        echo '<form method="post" class="mgcf-variant-generation-form">';
        wp_nonce_field(self::NONCE_ACTION, self::NONCE_FIELD);
        echo '<input type="hidden" name="mg_custom_fields_action" value="generate_product_variants" />';
        echo '<input type="hidden" name="preset_id" value="' . esc_attr($preset_id) . '" />';
        echo '<input type="hidden" name="variant_product_id" value="' . esc_attr($product_id) . '" />';
        echo '<input type="hidden" name="field_id" value="' . esc_attr($field_id) . '" />';
        echo '<h2>' . esc_html__('3. Termékek generálása', 'mgcf') . '</h2>';
        echo '<p class="mgcf-variant-generation-status">' . esc_html($complete ? __('Minden PNG megvan és jóvá van hagyva, generálható.', 'mgcf') : $validation->get_error_message()) . '</p>';
        echo '<button type="submit" class="button button-primary"' . disabled($complete && ($base_saved || $group_exists), false, false) . '>' . esc_html__('Variáció termékek generálása/frissítése', 'mgcf') . '</button>';
        echo '</form>';
        echo '</section>';

        self::render_variant_group_products($product_id, $field_id);
    }

    protected static function render_variant_group_products($product_id, $field_id) {
        if (!class_exists('MG_Custom_Field_Product_Variants')) {
            return;
        }
        $group = MG_Custom_Field_Product_Variants::get_group($product_id, $field_id);
        if (empty($group['products']) || !is_array($group['products'])) {
            return;
        }
        echo '<section class="mgcf-section mgcf-variant-group-section">';
        echo '<div class="mgcf-section__header"><h2>' . esc_html__('Kapcsolt termékek', 'mgcf') . '</h2><p>' . esc_html__('A generált termékek idempotensen frissíthetők; meglévő termék nem kerül törlésre.', 'mgcf') . '</p></div>';
        echo '<div class="mgcf-table-wrap"><table class="widefat striped mgcf-table"><thead><tr><th>' . esc_html__('Érték', 'mgcf') . '</th><th>' . esc_html__('Termék', 'mgcf') . '</th><th>' . esc_html__('Műveletek', 'mgcf') . '</th></tr></thead><tbody>';
        foreach ($group['products'] as $row) {
            $linked_id = !empty($row['product_id']) ? absint($row['product_id']) : 0;
            if ($linked_id <= 0) {
                continue;
            }
            $edit_link = get_edit_post_link($linked_id, '');
            $view_link = get_permalink($linked_id);
            echo '<tr><td>' . esc_html(isset($row['label']) ? $row['label'] : '') . '</td>';
            echo '<td>' . esc_html(get_the_title($linked_id)) . '</td><td>';
            if ($edit_link) {
                echo '<a class="button" href="' . esc_url($edit_link) . '">' . esc_html__('Szerkesztés', 'mgcf') . '</a> ';
            }
            if ($view_link) {
                echo '<a class="button" href="' . esc_url($view_link) . '" target="_blank" rel="noopener noreferrer">' . esc_html__('Megtekintés', 'mgcf') . '</a>';
            }
            echo '</td></tr>';
        }
        echo '</tbody></table></div></section>';
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

    /** Starter prompts for pre-generating variation PNGs from a design's base value. */
    public static function get_variation_prompt_templates() {
        $keep = ' Őrizd meg a betűtípust, a színt, a körvonalat, az ívelést és az elhelyezést; a betűméretet és a betűközt csak annyira igazítsd, hogy az új érték ugyanoda férjen. A minta minden más szövege, száma és grafikai eleme maradjon pontosan változatlan.';
        return array(
            'general' => array(
                'label' => 'Általános',
                'prompt' => 'A mintán jelenleg ez az érték szerepel: {{alap}}. Cseréld le erre: {{ertek}}, minden előfordulásánál. Ha az érték toldalékkal szerepel (például {{alap}}i, {{alap}}ban, {{alap}}-as), az új értékhez a helyes magyar toldalékot használd.' . $keep,
            ),
            'month' => array(
                'label' => 'Hónap',
                'prompt' => 'A mintán szereplő hónap most: {{alap}}. Cseréld erre a hónapra: {{ertek}}, minden előfordulásánál. Őrizd meg a hónap eredeti formáját (kiírt név, rövidítés vagy szám) és a kis- és nagybetűzést. Ha a hónap toldalékkal szerepel, az új hónaphoz a helyes magyar toldalékot használd (például FEBRUÁRI → SZEPTEMBERI, májusban → októberben). Évszámot, napot vagy életkort ne módosíts.' . $keep,
            ),
            'age' => array(
                'label' => 'Életkor',
                'prompt' => 'A mintán szereplő életkor most: {{alap}}. Cseréld erre: {{ertek}}, minden előfordulásánál. Tartsd meg a hozzá tartozó szavakat és jelöléseket (például „éves”, „felett”, „-as/-es”), és az új számhoz a helyes magyar toldalékot használd (például 60-as → 50-es). Csak ezt a számot változtasd meg; évszámot vagy más számot ne számolj ki és ne módosíts.' . $keep,
            ),
            'year' => array(
                'label' => 'Évszám',
                'prompt' => 'A mintán szereplő évszám most: {{alap}}. Cseréld erre az évszámra: {{ertek}}, minden előfordulásánál. A számjegyeket pontosan használd, és ha az évszám toldalékkal szerepel, az új évszámhoz a helyes magyar toldalékot használd (például 1960-as → 1966-os). Életkort, hónapot vagy más számot ne módosíts.' . $keep,
            ),
        );
    }

    /** Copyable starter prompts, deliberately independent of live preset IDs. */
    public static function get_ai_print_prompt_templates() {
        $name = 'A mintán szereplő, személyre szabásra szolgáló személynevet cseréld erre: {{ertek}}. Pontosan őrizd meg a megadott név betűit, ékezeteit, szóközeit és kötőjeleit; ne fordítsd, ne rövidítsd és ne egészítsd ki más névvel. Ha a mintán a név toldalékolva szerepel, a megadott alapnévhez illeszd a nyelvtanilag helyes magyar toldalékot. Az ugyanahhoz a személyhez tartozó ismétlődő névhelyeket következetesen módosítsd. Más személy nevét ne változtasd meg.';
        $year = 'A mintán szereplő, személyre szabásra szolgáló naptári évszámot cseréld erre: {{ertek}}. A megadott évszám számjegyeit pontosan használd. Tartsd meg az eredeti szöveg jelentését (például születési év, alapítás éve vagy évforduló éve), valamint a hozzá kapcsolódó írásjeleket és nyelvtanilag helyes toldalékot. Az ugyanazt az egyedi évet jelölő ismétlődő helyeket következetesen módosítsd. Más évszámot, életkort, napot vagy sorszámot ebből az értékből ne számolj ki és ne módosíts.';
        $month = 'A mintán szereplő, személyre szabásra szolgáló hónapot cseréld erre: {{ertek}}. A kép alapján azonosítsd a lecserélendő példahónapot. Őrizd meg a hónap eredeti megjelenítési módját (kiírt név, rövidítés vagy szám), kis- és nagybetűzését és nyelvtani szerepét. Kiírt magyar hónapnál az új hónapnak megfelelő helyes toldalékot használd: például MÁJUSBAN helyett SZEPTEMBERBEN, ha az új érték szeptember. Az ugyanahhoz az egyedi hónaphoz tartozó ismétlődő helyeket következetesen módosítsd. Ebből az értékből ne módosíts évszámot vagy napot.';
        $age = 'A mintán szereplő, személyre szabásra szolgáló életkort vagy eltelt évek számát cseréld erre: {{ertek}}. A kép szövegkörnyezetéből állapítsd meg, hogy életkorról vagy évfordulóról van szó; ezt a jelentést őrizd meg. A megadott számot pontosan használd, és tartsd meg az eredeti megfogalmazást, például „éves”, „éve” vagy a sorszám jelölését, helyes magyar toldalékolással. Az ugyanazt a személyre szabott értéket jelölő ismétlődéseket következetesen módosítsd. Ne kezeld naptári évszámként, és ne számolj belőle születési vagy aktuális évet.';
        return array(
            'month' => array('label' => 'Hónap választó — Hónap mező', 'prompt' => $month),
            'name' => array('label' => 'Név — Név mező', 'prompt' => $name),
            'name_year_name' => array('label' => 'Név + évszám — Név mező', 'prompt' => $name),
            'name_year_year' => array('label' => 'Név + évszám — Évszám mező', 'prompt' => $year),
            'age_year_age' => array('label' => 'Év + évszám — Év mező (életkor / eltelt évek)', 'prompt' => $age),
            'age_year_year' => array('label' => 'Év + évszám — Évszám mező', 'prompt' => $year),
            'year' => array('label' => 'Évszám — Évszám mező', 'prompt' => $year),
            'year_month_year' => array('label' => 'évszám hónap — Évszám mező', 'prompt' => $year),
            'year_month_month' => array('label' => 'évszám hónap — Hónap mező', 'prompt' => $month),
        );
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
        $linked_product_variants = !empty($field['linked_product_variants']);
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
        echo '<tr><th scope="row">' . esc_html__('AI egyedi nyomat', 'mgcf') . '</th><td><label><input type="checkbox" name="field_ai_print_enabled" value="1"' . checked(!empty($field['ai_print_enabled']), true, false) . ' /> ' . esc_html__('Nyomat módosítása AI-val a rendelés ZIP-exportjakor', 'mgcf') . '</label></td></tr>';
        echo '<tr><th scope="row">' . esc_html__('Előre megírt AI-utasítás', 'mgcf') . '</th><td><select class="mgcf-ai-prompt-template" aria-label="' . esc_attr__('Preset és mező szerinti AI-utasítás', 'mgcf') . '"><option value="">' . esc_html__('Válassz a preset és a mező neve alapján…', 'mgcf') . '</option>';
        foreach (self::get_ai_print_prompt_templates() as $template_id => $template) {
            echo '<option value="' . esc_attr($template_id) . '" data-prompt="' . esc_attr($template['prompt']) . '">' . esc_html($template['label']) . '</option>';
        }
        echo '</select> <button type="button" class="button mgcf-apply-ai-prompt">' . esc_html__('Utasítás betöltése és AI bekapcsolása', 'mgcf') . '</button>';
        echo '<p class="description">' . esc_html__('A betöltés lecseréli ennek a mezőnek az alábbi utasítását, amelyet utána szabadon szerkeszthetsz. Véglegesítéshez mentsd a mezőt. Kétmezős presetnél mindkét mezőhöz külön válaszd ki a megfelelő utasítást.', 'mgcf') . '</p><p class="mgcf-ai-prompt-status" role="status" aria-live="polite"></p></td></tr>';
        echo '<tr><th scope="row">' . esc_html__('Képmódosítási utasítás', 'mgcf') . '</th><td><textarea name="field_ai_print_prompt" rows="6" class="large-text" placeholder="' . esc_attr__('A képen látható hónapot cseréld erre: {{ertek}}. Kövesd az eredeti ragozást, helyes magyar toldalékolással.', 'mgcf') . '">' . esc_textarea(isset($field['ai_print_prompt']) ? $field['ai_print_prompt'] : '') . '</textarea>';
        echo '<p class="description">' . esc_html__('A {{ertek}} helyére a vevő rendelt értéke kerül. Nem kell megadni a képen szereplő példaértéket. Több mező módosítása egyetlen képszerkesztésben történik. A sablon aktuális utasítása a régebbi rendelések exportjára is érvényes.', 'mgcf') . '</p>';
        echo '<p class="description">' . esc_html__('Választható GPT Image modell · low · PNG · képarányhoz igazított felbontás, majd automatikus 3×-os felnagyítás. Egy exporton belül egy tételhez csak egy kép készül; új export új generálást indít.', 'mgcf') . ' <a href="' . esc_url(admin_url('admin.php?page=mockup-generator&mg_tab=ai_seo#mg-ai-print-settings')) . '">' . esc_html__('Nyomatmodell és OpenAI API-kulcs beállítása', 'mgcf') . '</a></p></td></tr>';
        echo '<tr><th scope="row"><label>' . esc_html__('Választólista értékek', 'mgcf') . '</label></th><td><textarea name="field_options" rows="3" class="large-text" placeholder="Érték1&#10;Érték2">' . esc_textarea($options_text) . '</textarea><p class="description">' . esc_html__('Választólista típusnál soronként egy opció.', 'mgcf') . '</p></td></tr>';
        echo '<tr><th scope="row">' . esc_html__('Minta variációk', 'mgcf') . '</th><td><label><input type="checkbox" name="field_linked_product_variants" value="1"' . checked($linked_product_variants, true, false) . ' /> ' . esc_html__('Külön termék a népszerű értékekre (pl. hónapok, 50 éves, 1966)', 'mgcf') . '</label>';
        echo '<p class="description">' . esc_html__('Választólista, szöveg és szám mezőnél használható; presetenként egy mezőnél. A termékenkénti PNG-ket és a generálást a preset terméklistájában, a „Minta variációk” gombbal kezeled.', 'mgcf') . '</p>';
        $variation_values = isset($field['variation_values']) ? $field['variation_values'] : array();
        $variation_text = is_array($variation_values) ? implode("\n", $variation_values) : (string) $variation_values;
        echo '<p><label><strong>' . esc_html__('Variációk listája', 'mgcf') . '</strong><br /><textarea name="field_variation_values" rows="4" class="large-text" placeholder="30&#10;40&#10;50&#10;60&#10;70">' . esc_textarea($variation_text) . '</textarea></label></p>';
        echo '<p class="description">' . esc_html__('Soronként egy érték, mindegyikből külön termék lesz. Beírós (szöveg/szám) mezőnél kötelező; választólistánál üresen hagyva a lista értékei számítanak.', 'mgcf') . '</p>';
        $variation_prompt = isset($field['variation_ai_prompt']) && trim($field['variation_ai_prompt']) !== '' ? $field['variation_ai_prompt'] : self::get_variation_prompt_templates()['general']['prompt'];
        echo '<p><strong>' . esc_html__('Variáció AI-utasítás', 'mgcf') . '</strong><br /><select class="mgcf-variation-prompt-template" aria-label="' . esc_attr__('Kész variáció-utasítás', 'mgcf') . '"><option value="">' . esc_html__('Kész utasítás betöltése…', 'mgcf') . '</option>';
        foreach (self::get_variation_prompt_templates() as $template_id => $template) {
            echo '<option value="' . esc_attr($template_id) . '" data-prompt="' . esc_attr($template['prompt']) . '">' . esc_html($template['label']) . '</option>';
        }
        echo '</select> <button type="button" class="button mgcf-apply-variation-prompt">' . esc_html__('Betöltés', 'mgcf') . '</button></p>';
        echo '<textarea name="field_variation_ai_prompt" rows="6" class="large-text">' . esc_textarea($variation_prompt) . '</textarea>';
        echo '<p class="description">' . esc_html__('Ezzel az utasítással készíti az AI a variációk PNG-it a minta alapképéből. {{alap}} = ami most a mintán szerepel (pl. Február, 60), {{ertek}} = az új érték. A {{ertek}} kötelező.', 'mgcf') . '</p>';
        if ($linked_product_variants && class_exists('MG_Custom_Field_Product_Variants')) {
            $variations = MG_Custom_Field_Product_Variants::get_field_variations($field);
            $categories = MG_Custom_Field_Product_Variants::get_option_categories($field);
            if (!empty($variations)) {
                echo '<p><strong>' . esc_html__('Alkategória értékenként', 'mgcf') . '</strong></p>';
                echo '<table class="mgcf-variation-categories">';
                foreach ($variations as $variation) {
                    $dropdown = wp_dropdown_categories(array(
                        'taxonomy' => 'product_cat',
                        'hide_empty' => 0,
                        'hierarchical' => 1,
                        'name' => 'field_linked_categories[' . $variation['slug'] . ']',
                        'id' => 'mgcf-cat-' . sanitize_key($field_id) . '-' . sanitize_key($variation['slug']),
                        'selected' => isset($categories[$variation['slug']]) ? $categories[$variation['slug']] : 0,
                        'show_option_none' => __('— nincs —', 'mgcf'),
                        'option_none_value' => 0,
                        'echo' => 0,
                    ));
                    echo '<tr><td>' . esc_html($variation['label']) . '</td><td>' . $dropdown . '</td></tr>';
                }
                echo '</table>';
                echo '<p class="description">' . esc_html__('Generáláskor minden termék a saját értékének alkategóriájába kerül, a többi érték alkategóriájából kikerül. Új értékhez előbb mentsd a mezőt, utána választhatsz kategóriát.', 'mgcf') . '</p>';
            }
        }
        echo '</td></tr>';
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

    /** Return the one eligible linked select field in a preset, if present. */
    protected static function get_linked_variant_field($preset) {
        $fields = isset($preset['fields']) && is_array($preset['fields']) ? $preset['fields'] : array();
        $eligible = array();
        foreach ($fields as $field) {
            if (!is_array($field) || !in_array(sanitize_key(isset($field['type']) ? $field['type'] : ''), array('select', 'text', 'number'), true) || empty($field['linked_product_variants'])) {
                continue;
            }
            $field_id = sanitize_key(isset($field['id']) ? $field['id'] : '');
            if ($field_id === '') {
                continue;
            }
            $field['id'] = $field_id;
            $eligible[] = $field;
        }
        return count($eligible) === 1 ? $eligible[0] : array();
    }

    /**
     * Validate the admin target and ensure it belongs to the selected preset.
     * This keeps the mapping endpoint from being used for arbitrary products
     * or fields by changing query arguments manually.
     */
    protected static function get_variant_context($preset_id, $product_id, $field_id) {
        self::ensure_variant_service();
        if (!class_exists('MG_Custom_Field_Product_Variants')) {
            return new WP_Error('variant_service_missing', __('A kapcsolt termék szolgáltatás nem érhető el.', 'mgcf'));
        }
        $preset_id = sanitize_key($preset_id);
        $product_id = absint($product_id);
        $field_id = sanitize_key($field_id);
        $preset = $preset_id !== '' ? MG_Custom_Fields_Manager::get_preset($preset_id) : false;
        if (!$preset) {
            return new WP_Error('variant_preset_missing', __('A preset nem található.', 'mgcf'));
        }
        $field = self::get_linked_variant_field($preset);
        if (empty($field) || sanitize_key($field['id']) !== $field_id) {
            return new WP_Error('variant_field_invalid', __('A presetnek pontosan egy kapcsolt választómezővel kell rendelkeznie.', 'mgcf'));
        }
        if ($product_id <= 0 || get_post_type($product_id) !== 'product') {
            return new WP_Error('variant_product_invalid', __('A termék nem található.', 'mgcf'));
        }
        $assigned = array_map('absint', (array) MG_Custom_Fields_Manager::get_products_for_preset($preset_id));
        if (!in_array($product_id, $assigned, true)) {
            return new WP_Error('variant_product_unassigned', __('A termék nincs ehhez a presethez rendelve.', 'mgcf'));
        }
        $parent_id = self::get_group_parent_id($product_id);
        if ($parent_id > 0 && $parent_id !== $product_id) {
            return new WP_Error('variant_product_member', sprintf(__('Ez a termék egy variációs csoport tagja. A csoportot az eredeti terméknél kezeld: %s.', 'mgcf'), get_the_title($parent_id)));
        }
        return array('preset' => $preset, 'field' => $field, 'product_id' => $product_id, 'field_id' => $field_id);
    }

    /** Source product of the variation group this product belongs to, or 0. */
    protected static function get_group_parent_id($product_id) {
        self::ensure_variant_service();
        if (get_post_meta($product_id, MG_Custom_Field_Product_Variants::META_GROUP, true) === '') {
            return 0;
        }
        return absint(get_post_meta($product_id, MG_Custom_Field_Product_Variants::META_PARENT, true));
    }

    protected static function ensure_variant_service() {
        if (class_exists('MG_Custom_Field_Product_Variants')) {
            return;
        }
        $file = dirname(__DIR__) . '/includes/class-custom-field-product-variants.php';
        if (file_exists($file)) {
            require_once $file;
        }
    }

    /** Build a stable slug/label list from the field's variation values. */
    protected static function get_variant_options($field) {
        self::ensure_variant_service();
        return MG_Custom_Field_Product_Variants::get_field_variations($field);
    }

    protected static function read_variant_mapping_from_request($field, $existing = array()) {
        $posted = isset($_POST['variant_mapping']) && is_array($_POST['variant_mapping']) ? wp_unslash($_POST['variant_mapping']) : array();
        $approved = isset($_POST['variant_approved']) && is_array($_POST['variant_approved']) ? wp_unslash($_POST['variant_approved']) : array();
        $mapping = array();
        foreach (self::get_variant_options($field) as $option) {
            $slug = $option['slug'];
            $attachment_id = isset($posted[$slug]) ? absint($posted[$slug]) : 0;
            if ($attachment_id > 0 && !self::is_png_attachment($attachment_id)) {
                $attachment_id = 0;
            }
            // Only the stored AI image keeps its AI status; a PNG picked by
            // hand is the admin's own choice and needs no approval.
            $ai_generated = $attachment_id > 0
                && !empty($existing[$slug]['ai_generated'])
                && absint($existing[$slug]['attachment_id'] ?? 0) === $attachment_id;
            $mapping[$slug] = array(
                'slug' => $slug,
                'label' => $option['label'],
                'attachment_id' => $attachment_id,
                'ai_generated' => $ai_generated,
                'approved' => !$ai_generated || !empty($approved[$slug]),
            );
        }
        return $mapping;
    }

    protected static function get_variant_mapping_counts($product_id, $field_id, $field) {
        $options = self::get_variant_options($field);
        $mapping = class_exists('MG_Custom_Field_Product_Variants') ? MG_Custom_Field_Product_Variants::get_mapping($product_id, $field_id) : array();
        $mapped = 0;
        foreach ($options as $option) {
            $slug = $option['slug'];
            if (!empty($mapping[$slug]['attachment_id']) && self::is_png_attachment($mapping[$slug]['attachment_id'])) {
                $mapped++;
            }
        }
        return array('mapped' => $mapped, 'total' => count($options));
    }

    protected static function get_variant_mapping_url($preset_id, $product_id, $field_id) {
        return add_query_arg(
            array(
                'page' => 'mockup-generator-custom-fields',
                'preset_id' => sanitize_key($preset_id),
                'variant_product_id' => absint($product_id),
                'field_id' => sanitize_key($field_id),
            ),
            admin_url('admin.php')
        );
    }

    /** Server-side counterpart to the PNG-only media picker restriction. */
    protected static function is_png_attachment($attachment_id) {
        $attachment_id = absint($attachment_id);
        if ($attachment_id <= 0 || get_post_type($attachment_id) !== 'attachment') {
            return false;
        }
        $mime = (string) get_post_mime_type($attachment_id);
        $path = function_exists('get_attached_file') ? (string) get_attached_file($attachment_id) : '';
        $url = function_exists('wp_get_attachment_url') ? (string) wp_get_attachment_url($attachment_id) : '';
        if (stripos($mime, 'image/png') === 0) {
            return true;
        }
        return (bool) preg_match('/\.png(?:\?|$)/i', $path !== '' ? $path : $url);
    }

    /**
     * Validate, upload and map one complete AI/JSON PNG batch.
     *
     * Validation deliberately happens before the first sideload. The browser
     * preview is only a convenience; this method re-parses every JSON file and
     * derives every target from the current field definition on the server.
     */
    protected static function import_variant_ai_pngs($context) {
        $validated = self::validate_variant_ai_uploads($context['field']);
        if (is_wp_error($validated)) {
            return $validated;
        }

        if (!function_exists('media_handle_sideload')) {
            require_once ABSPATH . 'wp-admin/includes/file.php';
            require_once ABSPATH . 'wp-admin/includes/media.php';
            require_once ABSPATH . 'wp-admin/includes/image.php';
        }
        if (!function_exists('media_handle_sideload')) {
            return new WP_Error('mgcf_variant_ai_media_missing', __('A WordPress média-feltöltő nem érhető el.', 'mgcf'));
        }

        $created = array();
        $uploaded_rows = array();
        foreach ($validated['rows'] as $row) {
            $png = $row['png'];
            $attachment_id = media_handle_sideload(
                array(
                    'name' => sanitize_file_name($png['name']),
                    'type' => 'image/png',
                    'tmp_name' => $png['tmp_name'],
                    'error' => UPLOAD_ERR_OK,
                    'size' => absint($png['size']),
                ),
                absint($context['product_id']),
                null,
                array(
                    'post_title' => $row['option']['label'],
                    'post_excerpt' => '',
                )
            );
            if (is_wp_error($attachment_id) || absint($attachment_id) <= 0) {
                self::delete_variant_ai_attachments($created);
                $message = is_wp_error($attachment_id)
                    ? $attachment_id->get_error_message()
                    : __('A PNG médiaelem létrehozása sikertelen.', 'mgcf');
                return new WP_Error('mgcf_variant_ai_upload_failed', sprintf(__('A(z) %1$s PNG importja sikertelen: %2$s', 'mgcf'), $png['name'], $message));
            }
            $attachment_id = absint($attachment_id);
            if (!self::is_png_attachment($attachment_id)) {
                self::delete_variant_ai_attachments(array_merge($created, array($attachment_id)));
                return new WP_Error('mgcf_variant_ai_upload_invalid', sprintf(__('A(z) %s feltöltött média nem PNG-ként került felismerésre.', 'mgcf'), $png['name']));
            }
            $created[] = $attachment_id;
            $row['attachment_id'] = $attachment_id;
            $uploaded_rows[] = $row;
        }

        $existing = class_exists('MG_Custom_Field_Product_Variants')
            ? MG_Custom_Field_Product_Variants::get_mapping($context['product_id'], $context['field_id'])
            : array();
        $mapping = is_array($existing) ? $existing : array();
        foreach (self::get_variant_options($context['field']) as $option) {
            $slug = $option['slug'];
            $entry = isset($mapping[$slug]) && is_array($mapping[$slug]) ? $mapping[$slug] : array();
            $entry['slug'] = $slug;
            $entry['label'] = $option['label'];
            $entry['attachment_id'] = !empty($entry['attachment_id']) ? absint($entry['attachment_id']) : 0;
            $mapping[$slug] = $entry;
        }
        foreach ($uploaded_rows as $row) {
            $slug = $row['option']['slug'];
            if (!isset($mapping[$slug]) || !is_array($mapping[$slug])) {
                $mapping[$slug] = array('slug' => $slug, 'label' => $row['option']['label']);
            }
            $mapping[$slug]['slug'] = $slug;
            $mapping[$slug]['label'] = $row['option']['label'];
            $mapping[$slug]['attachment_id'] = absint($row['attachment_id']);
        }

        $saved = MG_Custom_Field_Product_Variants::save_mapping($context['product_id'], $context['field_id'], $mapping);
        if (is_wp_error($saved)) {
            self::delete_variant_ai_attachments($created);
            return $saved;
        }

        return array('imported' => count($uploaded_rows));
    }

    protected static function delete_variant_ai_attachments($attachment_ids) {
        foreach ((array) $attachment_ids as $attachment_id) {
            $attachment_id = absint($attachment_id);
            if ($attachment_id > 0 && function_exists('wp_delete_attachment')) {
                wp_delete_attachment($attachment_id, true);
            }
        }
    }

    protected static function validate_variant_ai_uploads($field) {
        $all_uploads = self::normalise_variant_uploads(isset($_FILES['variant_ai_files']) ? $_FILES['variant_ai_files'] : array());
        $png_uploads = array();
        $json_uploads = array();
        foreach ($all_uploads as $upload) {
            $extension = strtolower(pathinfo($upload['name'], PATHINFO_EXTENSION));
            if ($extension === 'png') {
                $png_uploads[] = $upload;
            } elseif ($extension === 'json') {
                $json_uploads[] = $upload;
            } else {
                return new WP_Error('mgcf_variant_ai_bad_extension', sprintf(__('Csak PNG és JSON fájl fogadható el: %s.', 'mgcf'), $upload['name']));
            }
        }
        if (empty($png_uploads) || empty($json_uploads)) {
            return new WP_Error('mgcf_variant_ai_missing_files', __('Válassz legalább egy PNG és a hozzá tartozó JSON fájlt.', 'mgcf'));
        }

        $png_by_base = array();
        foreach ($png_uploads as $upload) {
            $validation = self::validate_variant_ai_upload_record($upload, 'png');
            if (is_wp_error($validation)) {
                return $validation;
            }
            $key = self::variant_ai_basename_key($upload['name']);
            if ($key === '') {
                return new WP_Error('mgcf_variant_ai_bad_basename', __('Egy PNG fájlnak nincs használható neve.', 'mgcf'));
            }
            if (isset($png_by_base[$key])) {
                return new WP_Error('mgcf_variant_ai_duplicate_png', sprintf(__('Több PNG ugyanahhoz a fájlnév-alaphoz tartozik: %s.', 'mgcf'), $upload['name']));
            }
            $png_by_base[$key] = $upload;
        }

        $json_by_base = array();
        $json_payloads = array();
        foreach ($json_uploads as $upload) {
            $validation = self::validate_variant_ai_upload_record($upload, 'json');
            if (is_wp_error($validation)) {
                return $validation;
            }
            $key = self::variant_ai_basename_key($upload['name']);
            if ($key === '') {
                return new WP_Error('mgcf_variant_ai_bad_basename', __('Egy JSON fájlnak nincs használható neve.', 'mgcf'));
            }
            if (isset($json_by_base[$key])) {
                return new WP_Error('mgcf_variant_ai_duplicate_json', sprintf(__('Több JSON ugyanahhoz a fájlnév-alaphoz tartozik: %s.', 'mgcf'), $upload['name']));
            }
            $raw = file_get_contents($upload['tmp_name']);
            if (!is_string($raw) || $raw === '') {
                return new WP_Error('mgcf_variant_ai_json_read', sprintf(__('A(z) %s JSON nem olvasható.', 'mgcf'), $upload['name']));
            }
            if (substr($raw, 0, 3) === "\xEF\xBB\xBF") {
                $raw = substr($raw, 3);
            }
            $payload = json_decode($raw, true);
            if (!is_array($payload) || json_last_error() !== JSON_ERROR_NONE) {
                return new WP_Error('mgcf_variant_ai_json_invalid', sprintf(__('A(z) %s JSON hibás.', 'mgcf'), $upload['name']));
            }
            $json_by_base[$key] = $upload;
            $json_payloads[$key] = $payload;
        }

        $options = self::get_variant_options($field);
        if (empty($options)) {
            return new WP_Error('mgcf_variant_ai_options_missing', __('A kapcsolt választómezőnek nincsenek értékei.', 'mgcf'));
        }
        $rows = array();
        $seen_targets = array();
        $keys = array_unique(array_merge(array_keys($png_by_base), array_keys($json_by_base)));
        sort($keys);
        foreach ($keys as $key) {
            if (!isset($png_by_base[$key]) || !isset($json_by_base[$key])) {
                return new WP_Error('mgcf_variant_ai_unpaired', sprintf(__('Hiányzó PNG vagy JSON pár a(z) %s fájlnév-alaphoz.', 'mgcf'), $key));
            }
            $target = self::match_variant_ai_target($json_payloads[$key], $options);
            if (is_wp_error($target)) {
                return new WP_Error($target->get_error_code(), sprintf('%s (%s)', $png_by_base[$key]['name'], $target->get_error_message()));
            }
            if (isset($seen_targets[$target['slug']])) {
                return new WP_Error('mgcf_variant_ai_duplicate_target', sprintf(__('Több PNG ugyanahhoz a választási értékhez tartozik: %s.', 'mgcf'), $target['label']));
            }
            $seen_targets[$target['slug']] = true;
            $rows[] = array('png' => $png_by_base[$key], 'json' => $json_by_base[$key], 'option' => $target);
        }
        return array('rows' => $rows);
    }

    protected static function normalise_variant_uploads($raw) {
        if (!is_array($raw) || !isset($raw['name'])) {
            return array();
        }
        $names = is_array($raw['name']) ? $raw['name'] : array($raw['name']);
        $uploads = array();
        foreach ($names as $index => $name) {
            $uploads[] = array(
                'name' => is_array($name) ? '' : (string) $name,
                'type' => is_array($raw['type']) && isset($raw['type'][$index]) ? (string) $raw['type'][$index] : (isset($raw['type']) && !is_array($raw['type']) ? (string) $raw['type'] : ''),
                'tmp_name' => is_array($raw['tmp_name']) && isset($raw['tmp_name'][$index]) ? (string) $raw['tmp_name'][$index] : (isset($raw['tmp_name']) && !is_array($raw['tmp_name']) ? (string) $raw['tmp_name'] : ''),
                'error' => is_array($raw['error']) && isset($raw['error'][$index]) ? (int) $raw['error'][$index] : (isset($raw['error']) && !is_array($raw['error']) ? (int) $raw['error'] : UPLOAD_ERR_NO_FILE),
                'size' => is_array($raw['size']) && isset($raw['size'][$index]) ? (int) $raw['size'][$index] : (isset($raw['size']) && !is_array($raw['size']) ? (int) $raw['size'] : 0),
            );
        }
        return $uploads;
    }

    protected static function variant_ai_basename_key($name) {
        $base = (string) pathinfo((string) $name, PATHINFO_FILENAME);
        return function_exists('mb_strtolower') ? mb_strtolower($base, 'UTF-8') : strtolower($base);
    }

    protected static function validate_variant_ai_upload_record($upload, $kind) {
        if (!is_array($upload) || !isset($upload['name']) || (int) $upload['error'] !== UPLOAD_ERR_OK || empty($upload['tmp_name']) || !is_readable($upload['tmp_name'])) {
            return new WP_Error('mgcf_variant_ai_upload_error', sprintf(__('A(z) %s feltöltés nem olvasható.', 'mgcf'), isset($upload['name']) ? $upload['name'] : __('ismeretlen fájl', 'mgcf')));
        }
        $extension = strtolower(pathinfo($upload['name'], PATHINFO_EXTENSION));
        if ($kind === 'png') {
            if ($extension !== 'png') {
                return new WP_Error('mgcf_variant_ai_not_png', sprintf(__('Csak PNG fogadható el: %s.', 'mgcf'), $upload['name']));
            }
            $checked = function_exists('wp_check_filetype_and_ext') ? wp_check_filetype_and_ext($upload['tmp_name'], $upload['name'], array('png' => 'image/png')) : array();
            if (!empty($checked['type']) && strtolower($checked['type']) !== 'image/png') {
                return new WP_Error('mgcf_variant_ai_not_png', sprintf(__('A(z) %s fájl nem érvényes PNG.', 'mgcf'), $upload['name']));
            }
            $image_info = @getimagesize($upload['tmp_name']);
            if (!is_array($image_info) || empty($image_info['mime']) || strtolower($image_info['mime']) !== 'image/png') {
                return new WP_Error('mgcf_variant_ai_not_png', sprintf(__('A(z) %s fájl nem érvényes PNG.', 'mgcf'), $upload['name']));
            }
        } elseif ($extension !== 'json') {
            return new WP_Error('mgcf_variant_ai_not_json', sprintf(__('Csak JSON fogadható el: %s.', 'mgcf'), $upload['name']));
        }
        return true;
    }

    protected static function match_variant_ai_target($payload, $options) {
        if (!is_array($payload) || !isset($payload['categories']) || !is_array($payload['categories'])) {
            return new WP_Error('mgcf_variant_ai_categories_missing', __('Hiányzik vagy hibás a categories objektum.', 'mgcf'));
        }
        $categories = $payload['categories'];
        foreach (array('main', 'sub') as $key) {
            if (array_key_exists($key, $categories) && $categories[$key] !== '' && !is_string($categories[$key])) {
                return new WP_Error('mgcf_variant_ai_categories_invalid', sprintf(__('A categories.%s mezőnek szövegnek kell lennie.', 'mgcf'), $key));
            }
        }
        $matches = array();
        foreach (array('main', 'sub') as $key) {
            $value = isset($categories[$key]) && is_string($categories[$key]) ? trim($categories[$key]) : '';
            if ($value === '') {
                continue;
            }
            $needle = sanitize_title($value);
            $field_matches = array();
            foreach ((array) $options as $option) {
                $label_slug = sanitize_title($option['label']);
                $option_slug = sanitize_title($option['slug']);
                if ($needle !== '' && ($needle === $label_slug || $needle === $option_slug)) {
                    $field_matches[$option['slug']] = $option;
                }
            }
            if (count($field_matches) > 1) {
                return new WP_Error('mgcf_variant_ai_ambiguous', sprintf(__('A categories.%1$s érték több választási értékre illeszkedik: %2$s.', 'mgcf'), $key, $value));
            }
            foreach ($field_matches as $slug => $option) {
                $matches[$slug] = $option;
            }
        }
        if (count($matches) > 1) {
            return new WP_Error('mgcf_variant_ai_ambiguous', __('A categories.main és categories.sub eltérő választási értéket azonosít.', 'mgcf'));
        }
        if (empty($matches)) {
            return new WP_Error('mgcf_variant_ai_unmatched', __('A categories.main/sub egyik értéke sem egyezik pontosan egy választási értékkel.', 'mgcf'));
        }
        return reset($matches);
    }

    protected static function render_product_search_section($preset_id) {
        echo '<section class="mgcf-section mgcf-search-section">';
        echo '<div class="mgcf-section__header">';
        echo '<h2>' . esc_html__('Keresés minta alapján', 'mgcf') . '</h2>';
        echo '<p>' . esc_html__('Keress termékeket név vagy SKU alapján (minta), és rendeld hozzájuk ezt a presetet utólag.', 'mgcf') . '</p>';
        echo '</div>';

        echo '<div class="mgcf-search-controls">';
        echo '<input type="text" id="mgcf-search-input" class="regular-text" placeholder="' . esc_attr__('Minta neve vagy azonosítója...', 'mgcf') . '" />';
        echo '<button type="button" id="mgcf-search-btn" class="button button-secondary" data-preset="' . esc_attr($preset_id) . '">' . esc_html__('Keresés', 'mgcf') . '</button>';
        echo '<span class="spinner" id="mgcf-search-spinner"></span>';
        echo '</div>';

        echo '<div id="mgcf-search-results-container" style="display:none; margin-top:20px;">';
        echo '<div class="mgcf-table-wrap">';
        echo '<table class="widefat striped mgcf-table" id="mgcf-search-results-table">';
        echo '<thead><tr>';
        echo '<th class="mgcf-table__check"><input type="checkbox" id="mgcf-search-select-all" /></th>';
        echo '<th class="mgcf-table__image">' . esc_html__('Kép', 'mgcf') . '</th>';
        echo '<th>' . esc_html__('Termék', 'mgcf') . '</th>';
        echo '<th>' . esc_html__('Állapot', 'mgcf') . '</th>';
        echo '</tr></thead>';
        echo '<tbody></tbody>';
        echo '</table>';
        echo '</div>';

        echo '<p class="submit">';
        echo '<button type="button" id="mgcf-search-assign-btn" class="button button-primary" data-preset="' . esc_attr($preset_id) . '">' . esc_html__('Kiválasztottak hozzárendelése', 'mgcf') . '</button>';
        echo '</p>';
        echo '</div>';
        echo '</section>';
    }

    protected static function render_product_assignment_section($preset_id, $assigned_product_ids) {
        $preset = MG_Custom_Fields_Manager::get_preset($preset_id);
        $variant_field = $preset ? self::get_linked_variant_field($preset) : array();
        $has_variant_mapping = !empty($variant_field);
        $page         = isset($_GET['paged']) ? max(1, intval($_GET['paged'])) : 1;
        $per_page     = isset($_GET['per_page']) ? intval($_GET['per_page']) : 25;
        $only_assigned = !empty($_GET['only_assigned']);
        if (!in_array($per_page, array(25, 50, 100), true)) {
            $per_page = 25;
        }

        $base_url = add_query_arg(array('page' => 'mockup-generator-custom-fields', 'preset_id' => $preset_id), admin_url('admin.php'));

        if ($only_assigned) {
            // Csak a hozzárendelt termékek
            if (empty($assigned_product_ids)) {
                $products = array();
                $total    = 0;
                $pages    = 1;
            } else {
                $offset = ($page - 1) * $per_page;
                $slice  = array_slice($assigned_product_ids, $offset, $per_page);
                $posts  = get_posts(array(
                    'post_type'      => 'product',
                    'post__in'       => $slice,
                    'orderby'        => 'post__in',
                    'posts_per_page' => $per_page,
                    'post_status'    => 'any',
                ));
                $products = $posts;
                $total    = count($assigned_product_ids);
                $pages    = max(1, ceil($total / $per_page));
            }
        } else {
            $data     = MG_Custom_Fields_Manager::get_custom_products_paginated($page, $per_page);
            $products = $data['products'];
            $total    = $data['total'];
            $pages    = $data['pages'];
        }

        echo '<section class="mgcf-section">';
        echo '<div class="mgcf-section__header">';
        echo '<h2>' . esc_html__('Termék hozzárendelés', 'mgcf') . '</h2>';
        echo '<p>' . esc_html__('Jelöld be azokat a termékeket, amelyekre ez a preset vonatkozzon.', 'mgcf') . '</p>';
        echo '</div>';

        if (!$only_assigned && empty($products) && $total === 0) {
            echo '<div class="mgcf-empty">';
            echo '<p>' . esc_html__('Nincsenek egyedi termékek. Először jelölj ki termékeket egyediként a bulk feltöltő oldalon.', 'mgcf') . '</p>';
            echo '</div>';
            echo '</section>';
            return;
        }

        // Per-page + toggle szűrő
        echo '<div class="mgcf-pagination-controls">';
        echo '<form method="get" class="mgcf-per-page-form">';
        echo '<input type="hidden" name="page" value="mockup-generator-custom-fields" />';
        echo '<input type="hidden" name="preset_id" value="' . esc_attr($preset_id) . '" />';
        if ($only_assigned) {
            echo '<input type="hidden" name="only_assigned" value="1" />';
        }
        echo '<label>' . esc_html__('Elemek oldalanként:', 'mgcf') . ' ';
        echo '<select name="per_page" onchange="this.form.submit()">';
        foreach (array(25, 50, 100) as $opt) {
            echo '<option value="' . $opt . '"' . selected($per_page, $opt, false) . '>' . $opt . '</option>';
        }
        echo '</select></label>';
        echo '</form>';

        // Toggle gomb
        if ($only_assigned) {
            $toggle_url   = add_query_arg(array('paged' => 1, 'per_page' => $per_page, 'only_assigned' => 0), $base_url);
            $toggle_label = __('Összes mutatása', 'mgcf');
            $toggle_class = 'button button-primary';
        } else {
            $toggle_url   = add_query_arg(array('paged' => 1, 'per_page' => $per_page, 'only_assigned' => 1), $base_url);
            $toggle_label = sprintf(__('Csak hozzárendeltek (%d)', 'mgcf'), count($assigned_product_ids));
            $toggle_class = 'button';
        }
        echo '<a href="' . esc_url($toggle_url) . '" class="' . esc_attr($toggle_class) . '">' . esc_html($toggle_label) . '</a>';

        echo '<span class="mgcf-total">' . esc_html(sprintf(__('Összesen: %d termék', 'mgcf'), $total)) . '</span>';
        echo '</div>';

        // Product list form
        echo '<form method="post" id="mgcf-product-assignment-form">';
        wp_nonce_field(self::NONCE_ACTION, self::NONCE_FIELD);
        echo '<input type="hidden" name="mg_custom_fields_action" value="save_product_assignments" />';
        echo '<input type="hidden" name="preset_id" value="' . esc_attr($preset_id) . '" />';

        // Termék → más presetek térképe
        $all_presets = MG_Custom_Fields_Manager::get_all_presets_public();
        $product_other_presets = array();
        foreach ($all_presets as $pid => $pdata) {
            if ($pid === $preset_id) continue;
            $pname = isset($pdata['name']) ? $pdata['name'] : $pid;
            foreach ((array)(isset($pdata['product_ids']) ? $pdata['product_ids'] : array()) as $prod_id) {
                $product_other_presets[intval($prod_id)][] = $pname;
            }
        }

        echo '<div class="mgcf-table-wrap">';
        echo '<table class="widefat striped mgcf-table">';
        echo '<thead><tr>';
        echo '<th class="mgcf-table__check"><input type="checkbox" id="mgcf-select-all" /></th>';
        echo '<th class="mgcf-table__image">' . esc_html__('Kép', 'mgcf') . '</th>';
        echo '<th>' . esc_html__('Termék', 'mgcf') . '</th>';
        echo '<th>' . esc_html__('Állapot', 'mgcf') . '</th>';
        echo '<th>' . esc_html__('Más preset', 'mgcf') . '</th>';
        if ($has_variant_mapping) {
            echo '<th>' . esc_html__('Minta variációk', 'mgcf') . '</th>';
        }
        echo '</tr></thead>';
        echo '<tbody>';
        foreach ($products as $product) {
            $is_assigned  = in_array($product->ID, $assigned_product_ids, true);
            $status       = get_post_status_object($product->post_status);
            $status_label = $status ? $status->label : $product->post_status;
            $thumbnail    = get_the_post_thumbnail($product->ID, array(80, 80), array('class' => 'mgcf-product-thumb'));
            if (empty($thumbnail)) {
                $thumbnail = '<span class="mgcf-no-image">—</span>';
            }
            $other_presets = isset($product_other_presets[$product->ID]) ? $product_other_presets[$product->ID] : array();

            echo '<input type="hidden" name="visible_product_ids[]" value="' . esc_attr($product->ID) . '" />';

            $row_class = !empty($other_presets) ? ' class="mgcf-row--other-preset"' : '';
            echo '<tr' . $row_class . '>';
            echo '<td class="mgcf-table__check"><input type="checkbox" name="product_ids[]" value="' . esc_attr($product->ID) . '"' . checked($is_assigned, true, false) . ' /></td>';
            echo '<td class="mgcf-table__image">' . $thumbnail . '</td>';
            echo '<td>' . esc_html(get_the_title($product)) . '</td>';
            echo '<td>' . esc_html($status_label) . '</td>';
            echo '<td>';
            if (!empty($other_presets)) {
                foreach ($other_presets as $oname) {
                    echo '<span class="mgcf-other-preset-badge" title="' . esc_attr(__('Már hozzárendelve ehhez a presethez', 'mgcf')) . '">' . esc_html($oname) . '</span> ';
                }
            } else {
                echo '<span style="color:#aaa;">—</span>';
            }
            echo '</td>';
            if ($has_variant_mapping) {
                echo '<td>';
                $group_parent = $is_assigned ? self::get_group_parent_id($product->ID) : 0;
                if ($is_assigned && $group_parent > 0 && $group_parent !== (int) $product->ID) {
                    $parent_url = self::get_variant_mapping_url($preset_id, $group_parent, $variant_field['id']);
                    echo '<a href="' . esc_url($parent_url) . '" class="button button-small">' . esc_html__('Csoport: eredeti termék', 'mgcf') . '</a>';
                } elseif ($is_assigned) {
                    $mapping_url = self::get_variant_mapping_url($preset_id, $product->ID, $variant_field['id']);
                    echo '<a href="' . esc_url($mapping_url) . '" class="button button-small">' . esc_html__('Minta variációk', 'mgcf') . '</a>';
                } else {
                    echo '<span style="color:#aaa;">—</span>';
                }
                echo '</td>';
            }
            echo '</tr>';
        }
        echo '</tbody></table>';
        echo '</div>';

        // Pagination
        if ($pages > 1) {
            echo '<div class="mgcf-pagination">';
            for ($i = 1; $i <= $pages; $i++) {
                $url = add_query_arg(array('paged' => $i, 'per_page' => $per_page, 'only_assigned' => $only_assigned ? 1 : 0), $base_url);
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

        $preset_id   = isset($_POST['preset_id']) ? sanitize_key($_POST['preset_id']) : '';
        $product_ids = isset($_POST['product_ids']) && is_array($_POST['product_ids']) ? array_map('intval', $_POST['product_ids']) : array();
        $visible_ids = isset($_POST['visible_product_ids']) && is_array($_POST['visible_product_ids']) ? array_map('intval', $_POST['visible_product_ids']) : array();

        if ($preset_id === '') {
            wp_send_json_error(array('message' => __('Hiányzó preset azonosító.', 'mgcf')));
        }

        if (MG_Custom_Fields_Manager::assign_products_to_preset_merge($preset_id, $product_ids, $visible_ids)) {
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

    /** Admin-only AI request for one variation PNG, run in the open tab's request. */
    public static function ajax_variant_ai_generate() {
        check_ajax_referer(self::NONCE_ACTION, 'nonce');
        if (!current_user_can('edit_products')) {
            wp_send_json_error(array('message' => __('Nincs jogosultság.', 'mgcf')), 403);
        }
        $target = self::read_variant_ai_target();
        if (is_wp_error($target)) {
            wp_send_json_error(array('message' => $target->get_error_message()));
        }
        $state_key = self::variant_ai_state_key($target['product_id'], $target['field_id'], $target['slug']);
        $lock = $state_key . '_lock';
        $locked_at = (int) get_option($lock, 0);
        if ($locked_at > 0 && time() - $locked_at > 360) {
            delete_option($lock);
        }
        if (!add_option($lock, time(), '', false)) {
            wp_send_json_success(array('status' => 'running'));
        }

        // The browser may give up on a slow request (proxy timeout); the work
        // continues and the page reads the outcome from the status endpoint.
        ignore_user_abort(true);
        @set_time_limit(300);
        @ini_set('memory_limit', '512M');
        set_transient($state_key, array('status' => 'running', 'started' => time()), HOUR_IN_SECONDS);
        $tmp = '';
        try {
            $prompt = MG_AI_Print_Generator::build_prompt(self::variation_instructions($target['field'], $target['base_label'], $target['label']));
            $tmp = MG_AI_Print_Generator::edit_variation_image($target['base_path'], $prompt, hash('sha256', $state_key . '|' . microtime(true)));
            $product_slug = (string) get_post_field('post_name', $target['product_id']);
            $filename = ($product_slug !== '' ? $product_slug : 'minta') . '-' . $target['slug'] . '-ai.png';
            $attachment_id = MG_Custom_Field_Product_Variants::create_png_attachment($tmp, $filename, $target['name']);
            if (is_wp_error($attachment_id)) {
                throw new RuntimeException($attachment_id->get_error_message());
            }
            self::store_variant_ai_png($target['product_id'], $target['field_id'], $target['slug'], $target['label'], $attachment_id);
            $state = array(
                'status' => 'done',
                'attachment_id' => $attachment_id,
                'preview' => (string) wp_get_attachment_image_url($attachment_id, 'medium'),
                'full' => (string) wp_get_attachment_url($attachment_id),
            );
        } catch (Throwable $e) {
            $state = array('status' => 'error', 'message' => $e->getMessage());
        } finally {
            if ($tmp !== '' && is_file($tmp)) {
                @unlink($tmp);
            }
            delete_option($lock);
        }
        set_transient($state_key, $state, HOUR_IN_SECONDS);
        if ($state['status'] === 'error') {
            wp_send_json_error($state);
        }
        wp_send_json_success($state);
    }

    public static function ajax_variant_ai_status() {
        check_ajax_referer(self::NONCE_ACTION, 'nonce');
        if (!current_user_can('edit_products')) {
            wp_send_json_error(array('message' => __('Nincs jogosultság.', 'mgcf')), 403);
        }
        $target = self::read_variant_ai_target();
        if (is_wp_error($target)) {
            wp_send_json_error(array('message' => $target->get_error_message()));
        }
        $state = get_transient(self::variant_ai_state_key($target['product_id'], $target['field_id'], $target['slug']));
        if (!is_array($state)) {
            wp_send_json_success(array('status' => 'none'));
        }
        if ($state['status'] === 'running' && time() - (int) $state['started'] > 360) {
            wp_send_json_success(array('status' => 'error', 'message' => __('Az AI-kép 6 percen belül nem készült el. Próbáld újra.', 'mgcf')));
        }
        wp_send_json_success($state);
    }

    /** Validate an AI request against the preset, the saved base value and its PNG. */
    protected static function read_variant_ai_target() {
        $preset_id = isset($_POST['preset_id']) ? sanitize_key(wp_unslash($_POST['preset_id'])) : '';
        $product_id = isset($_POST['product_id']) ? absint($_POST['product_id']) : 0;
        $field_id = isset($_POST['field_id']) ? sanitize_key(wp_unslash($_POST['field_id'])) : '';
        $slug = isset($_POST['slug']) ? sanitize_title(wp_unslash($_POST['slug'])) : '';
        $context = self::get_variant_context($preset_id, $product_id, $field_id);
        if (is_wp_error($context)) {
            return $context;
        }
        $options = self::get_variant_options($context['field']);
        $config = MG_Custom_Field_Product_Variants::get_config($product_id, $field_id);
        if (empty($config['base_slug']) && get_post_meta($product_id, MG_Custom_Field_Product_Variants::META_GROUP, true) === '') {
            return new WP_Error('base_missing', __('Előbb válaszd ki és mentsd az alapbeállítást.', 'mgcf'));
        }
        $base_slug = MG_Custom_Field_Product_Variants::get_base_slug($product_id, $field_id, $options);
        $labels = wp_list_pluck($options, 'label', 'slug');
        if (!isset($labels[$slug]) || $slug === $base_slug) {
            return new WP_Error('slug_invalid', __('Ehhez az értékhez nem készíthető AI-kép.', 'mgcf'));
        }
        $mapping = MG_Custom_Field_Product_Variants::get_mapping($product_id, $field_id);
        $base_attachment = !empty($mapping[$base_slug]['attachment_id']) ? absint($mapping[$base_slug]['attachment_id']) : 0;
        $base_path = $base_attachment > 0 ? (string) get_attached_file($base_attachment) : '';
        if ($base_path === '' || !is_file($base_path) || !self::is_png_attachment($base_attachment)) {
            return new WP_Error('base_png_missing', __('Az alapérték PNG-je hiányzik. Mentsd újra az alapbeállítást, vagy válaszd ki kézzel a PNG-t.', 'mgcf'));
        }
        $names = MG_Custom_Field_Product_Variants::preview_names($product_id, $field_id, $options, $base_slug);
        return array(
            'product_id' => $product_id,
            'field_id' => $field_id,
            'field' => $context['field'],
            'slug' => $slug,
            'label' => $labels[$slug],
            'base_label' => $labels[$base_slug],
            'base_path' => $base_path,
            'name' => isset($names[$slug]) ? $names[$slug] : $labels[$slug],
        );
    }

    protected static function variant_ai_state_key($product_id, $field_id, $slug) {
        return 'mgcf_vai_' . md5(absint($product_id) . '|' . sanitize_key($field_id) . '|' . sanitize_title($slug));
    }

    /** The preset's variation prompt with the base and new value filled in. */
    protected static function variation_instructions($field, $base_label, $label) {
        $template = isset($field['variation_ai_prompt']) ? trim((string) $field['variation_ai_prompt']) : '';
        if ($template === '' || strpos($template, '{{ertek}}') === false) {
            $template = self::get_variation_prompt_templates()['general']['prompt'];
        }
        return str_replace(array('{{alap}}', '{{ertek}}'), array($base_label, $label), $template);
    }

    /**
     * Put a new AI PNG into the mapping as unapproved. Requests for several
     * values run in parallel, so the read-modify-write is serialised.
     */
    protected static function store_variant_ai_png($product_id, $field_id, $slug, $label, $attachment_id) {
        $lock = 'mgcf_variant_map_lock_' . absint($product_id);
        for ($i = 0; $i < 100 && !add_option($lock, time(), '', false); $i++) {
            if (time() - (int) get_option($lock, 0) > 30) {
                delete_option($lock);
            }
            usleep(200000);
        }
        try {
            wp_cache_delete($product_id, 'post_meta');
            $mapping = MG_Custom_Field_Product_Variants::get_mapping($product_id, $field_id);
            $previous = isset($mapping[$slug]) ? $mapping[$slug] : array();
            $mapping[$slug] = array_merge($previous, array(
                'slug' => $slug,
                'label' => $label,
                'attachment_id' => absint($attachment_id),
                'ai_generated' => true,
                'approved' => false,
            ));
            MG_Custom_Field_Product_Variants::save_mapping($product_id, $field_id, $mapping);
        } finally {
            delete_option($lock);
        }
        // A rejected AI image is replaced, so it should not pile up in the media library.
        $old_id = !empty($previous['attachment_id']) ? absint($previous['attachment_id']) : 0;
        if ($old_id > 0 && $old_id !== absint($attachment_id) && !empty($previous['ai_generated']) && empty($previous['approved'])) {
            wp_delete_attachment($old_id, true);
        }
    }

    public static function ajax_search_products_by_minta() {
        check_ajax_referer(self::NONCE_ACTION, 'nonce');

        if (!current_user_can('edit_products')) {
            wp_send_json_error(array('message' => __('Nincs jogosultság.', 'mgcf')));
        }

        $query = isset($_POST['query']) ? sanitize_text_field($_POST['query']) : '';
        if ($query === '') {
            wp_send_json_success(array('products' => array()));
        }

        $args = array(
            'post_type'      => 'product',
            'posts_per_page' => 100, // Reasonable limit
            'post_status'    => array('publish', 'draft', 'pending', 'private'),
            's'              => $query,
        );
        $products = get_posts($args);
        $results = array();

        if (is_array($products)) {
            foreach ($products as $post) {
                $status = get_post_status_object($post->post_status);
                $status_label = $status ? $status->label : $post->post_status;
                $thumbnail = get_the_post_thumbnail($post->ID, array(80, 80), array('class' => 'mgcf-product-thumb'));
                if (empty($thumbnail)) {
                    $thumbnail = '<span class="mgcf-no-image">—</span>';
                }
                $results[] = array(
                    'id' => $post->ID,
                    'title' => get_the_title($post->ID),
                    'status' => $status_label,
                    'thumbnail' => $thumbnail
                );
            }
        }

        wp_send_json_success(array('products' => $results));
    }

    public static function ajax_assign_searched_products() {
        check_ajax_referer(self::NONCE_ACTION, 'nonce');

        if (!current_user_can('edit_products')) {
            wp_send_json_error(array('message' => __('Nincs jogosultság.', 'mgcf')));
        }

        $preset_id = isset($_POST['preset_id']) ? sanitize_key($_POST['preset_id']) : '';
        $product_ids = isset($_POST['product_ids']) && is_array($_POST['product_ids']) ? array_map('intval', $_POST['product_ids']) : array();

        if ($preset_id === '') {
            wp_send_json_error(array('message' => __('Hiányzó preset azonosító.', 'mgcf')));
        }
        if (empty($product_ids)) {
            wp_send_json_error(array('message' => __('Nincs kiválasztott termék.', 'mgcf')));
        }

        $success_count = 0;
        foreach ($product_ids as $pid) {
            if (MG_Custom_Fields_Manager::apply_preset_to_product($pid, $preset_id)) {
                $success_count++;
            }
        }

        if ($success_count > 0) {
            wp_send_json_success(array('message' => sprintf(__('%d termék sikeresen hozzárendelve.', 'mgcf'), $success_count)));
        } else {
            wp_send_json_error(array('message' => __('Hiba történt a hozzárendelés során.', 'mgcf')));
        }
    }
}
