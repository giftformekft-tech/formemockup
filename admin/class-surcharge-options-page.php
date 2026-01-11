<?php
if (!defined('ABSPATH')) {
    exit;
}

class MG_Surcharge_Options_Page {
    const MENU_SLUG = 'mockup-generator-surcharges';

    public static function add_submenu_page() {
        add_submenu_page(
            'mockup-generator',
            __('Feláras opciók', 'mockup-generator'),
            __('Feláras opciók', 'mockup-generator'),
            'manage_woocommerce',
            self::MENU_SLUG,
            [__CLASS__, 'render']
        );
    }

    public static function render() {
        if (!current_user_can('manage_woocommerce')) {
            wp_die(__('Nincs jogosultság.', 'mockup-generator'));
        }
        $action = isset($_REQUEST['mg_action']) ? sanitize_key($_REQUEST['mg_action']) : '';
        if ($action === 'delete' && !empty($_GET['surcharge_id'])) {
            check_admin_referer('mg_delete_surcharge');
            MG_Surcharge_Manager::delete_surcharge(sanitize_key(wp_unslash($_GET['surcharge_id'])));
            add_settings_error('mg_surcharges', 'deleted', __('Felár opció törölve.', 'mockup-generator'), 'updated');
        }
        if (!empty($_POST['mg_surcharge_settings_nonce'])) {
            self::handle_settings_save();
        }
        if (!empty($_POST['mg_surcharge_nonce'])) {
            self::handle_save();
        }
        settings_errors('mg_surcharges');
        if ($action === 'edit') {
            $id = isset($_GET['surcharge_id']) ? sanitize_key(wp_unslash($_GET['surcharge_id'])) : '';
            self::render_form($id);
        } else {
            self::render_list();
        }
    }

    private static function handle_save() {
        check_admin_referer('mg_save_surcharge', 'mg_surcharge_nonce');
        $id = isset($_POST['surcharge_id']) ? sanitize_key(wp_unslash($_POST['surcharge_id'])) : '';
        $data = [
            'id' => $id,
            'name' => isset($_POST['name']) ? sanitize_text_field(wp_unslash($_POST['name'])) : '',
            'description' => isset($_POST['description']) ? wp_kses_post(wp_unslash($_POST['description'])) : '',
            'amount' => isset($_POST['amount']) ? floatval(wp_unslash($_POST['amount'])) : 0,
            'active' => !empty($_POST['active']),
            'priority' => isset($_POST['priority']) ? intval(wp_unslash($_POST['priority'])) : 10,
            'require_choice' => !empty($_POST['require_choice']),
            'mandatory' => !empty($_POST['require_choice']),
            'default_enabled' => !empty($_POST['default_enabled']),
            'cart_lock' => !empty($_POST['cart_lock']),
            'frontend_display' => isset($_POST['frontend_display']) ? sanitize_text_field(wp_unslash($_POST['frontend_display'])) : 'product',
            'conditions' => [
                'product_types' => isset($_POST['conditions']['product_types']) ? array_map('sanitize_text_field', wp_unslash((array)$_POST['conditions']['product_types'])) : [],
                'colors' => isset($_POST['conditions']['colors']) ? array_map('sanitize_text_field', wp_unslash((array)$_POST['conditions']['colors'])) : [],
                'sizes' => isset($_POST['conditions']['sizes']) ? array_map('sanitize_text_field', wp_unslash((array)$_POST['conditions']['sizes'])) : [],
                'categories' => isset($_POST['conditions']['categories']) ? array_map('intval', (array)$_POST['conditions']['categories']) : [],
                'products' => isset($_POST['conditions']['products']) ? array_map('intval', array_filter(array_map('trim', explode(',', wp_unslash($_POST['conditions']['products']))))) : [],
                'min_cart_total' => isset($_POST['conditions']['min_cart_total']) ? floatval(wp_unslash($_POST['conditions']['min_cart_total'])) : '',
            ],
        ];
        MG_Surcharge_Manager::upsert_surcharge($data);
        add_settings_error('mg_surcharges', 'saved', __('Felár opció mentve.', 'mockup-generator'), 'updated');
    }

    private static function handle_settings_save() {
        check_admin_referer('mg_save_surcharge_settings', 'mg_surcharge_settings_nonce');
        $message = isset($_POST['cart_lock_message']) ? wp_kses_post(wp_unslash($_POST['cart_lock_message'])) : '';
        update_option(MG_Surcharge_Manager::LOCK_MESSAGE_OPTION, $message, false);
        add_settings_error('mg_surcharges', 'settings_saved', __('Feláras opciók beállításai elmentve.', 'mockup-generator'), 'updated');
    }

    private static function render_list() {
        $surcharges = MG_Surcharge_Manager::sort_surcharges(MG_Surcharge_Manager::get_surcharges(false));
        $cart_lock_message = get_option(MG_Surcharge_Manager::LOCK_MESSAGE_OPTION, '');
        if ($cart_lock_message === '') {
            $cart_lock_message = __('A kosárban már van olyan termék, amely feltételhez kötött feláras opciót tartalmaz, ezért most csak ilyen opcióval rendelkező terméket adhatsz hozzá. Ha más terméket szeretnél, külön vásárlásban teheted meg.', 'mockup-generator');
        }
        echo '<div class="wrap">';
        echo '<h1 class="wp-heading-inline">' . esc_html__('Feláras opciók', 'mockup-generator') . '</h1> ';
        echo '<a href="' . esc_url(self::get_form_url()) . '" class="page-title-action">' . esc_html__('Új opció', 'mockup-generator') . '</a>';
        echo '<hr class="wp-header-end" />';
        echo '<form method="post" action="">';
        wp_nonce_field('mg_save_surcharge_settings', 'mg_surcharge_settings_nonce');
        echo '<table class="form-table" role="presentation">';
        echo '<tr><th scope="row"><label for="mg-cart-lock-message">' . esc_html__('Kosár korlátozás üzenet', 'mockup-generator') . '</label></th><td>';
        echo '<textarea id="mg-cart-lock-message" name="cart_lock_message" rows="3" class="large-text">' . esc_textarea($cart_lock_message) . '</textarea>';
        echo '<p class="description">' . esc_html__('A feláras opció által korlátozott kosár esetén megjelenő hibaüzenet.', 'mockup-generator') . '</p>';
        echo '</td></tr>';
        echo '</table>';
        submit_button(__('Üzenet mentése', 'mockup-generator'));
        echo '</form>';
        if (empty($surcharges)) {
            echo '<p>' . esc_html__('Még nincs felár opció.', 'mockup-generator') . '</p>';
        } else {
            echo '<table class="widefat striped">';
            echo '<thead><tr>';
            $columns = [
                __('Név', 'mockup-generator'),
                __('Rövid leírás', 'mockup-generator'),
                __('Felár összege', 'mockup-generator'),
                __('Állapot', 'mockup-generator'),
                __('Prioritás', 'mockup-generator'),
                __('Feltételek', 'mockup-generator'),
                __('Műveletek', 'mockup-generator'),
            ];
            foreach ($columns as $column) {
                echo '<th>' . esc_html($column) . '</th>';
            }
            echo '</tr></thead><tbody>';
            foreach ($surcharges as $surcharge) {
                echo '<tr>';
                echo '<td>' . esc_html($surcharge['name']) . '</td>';
                echo '<td>' . esc_html(wp_strip_all_tags($surcharge['description'])) . '</td>';
                echo '<td>' . esc_html(wp_strip_all_tags(wc_price($surcharge['amount']))) . '</td>';
                echo '<td>' . ($surcharge['active'] ? '<span class="status-enabled">' . esc_html__('Aktív', 'mockup-generator') . '</span>' : esc_html__('Inaktív', 'mockup-generator')) . '</td>';
                echo '<td>' . intval($surcharge['priority']) . '</td>';
                echo '<td>' . esc_html(self::format_conditions($surcharge['conditions'])) . '</td>';
                $edit_url = add_query_arg(['page' => self::MENU_SLUG, 'mg_action' => 'edit', 'surcharge_id' => $surcharge['id']], admin_url('admin.php'));
                $delete_url = wp_nonce_url(add_query_arg(['page' => self::MENU_SLUG, 'mg_action' => 'delete', 'surcharge_id' => $surcharge['id']], admin_url('admin.php')), 'mg_delete_surcharge');
                echo '<td><a href="' . esc_url($edit_url) . '">' . esc_html__('Szerkesztés', 'mockup-generator') . '</a> | <a href="' . esc_url($delete_url) . '" onclick="return confirm(\'' . esc_js(__('Biztosan törlöd?', 'mockup-generator')) . '\');">' . esc_html__('Törlés', 'mockup-generator') . '</a></td>';
                echo '</tr>';
            }
            echo '</tbody></table>';
        }
        echo '</div>';
    }

    private static function render_form($id = '') {
        $surcharge = $id ? MG_Surcharge_Manager::get_surcharge($id) : null;
        $surcharge = $surcharge ? $surcharge : MG_Surcharge_Manager::normalize_surcharge([]);
        if (!$id) {
            $surcharge['active'] = true;
        }
        $action_url = esc_url(self::get_form_url($id));
        $terms = [
            'product_types' => self::unique_terms(self::get_terms('pa_termektipus')),
            'colors' => self::unique_terms(array_merge(self::get_terms('pa_szin'), self::get_terms('pa_color'))),
            'sizes' => self::unique_terms(array_merge(self::get_terms('pa_meret'), self::get_terms('pa_size'), self::get_plugin_sizes())),
            'categories' => self::unique_terms(self::get_terms('product_cat', true)),
        ];
        echo '<div class="wrap">';
        echo '<h1>' . esc_html($id ? __('Opció szerkesztése', 'mockup-generator') : __('Új opció', 'mockup-generator')) . '</h1>';
        echo '<form method="post" action="' . $action_url . '">';
        wp_nonce_field('mg_save_surcharge', 'mg_surcharge_nonce');
        echo '<table class="form-table" role="presentation">';
        self::render_input_row(__('Név', 'mockup-generator'), '<input type="text" name="name" class="regular-text" value="' . esc_attr($surcharge['name']) . '" required />');
        self::render_input_row(__('Leírás', 'mockup-generator'), '<textarea name="description" rows="3" class="large-text">' . esc_textarea($surcharge['description']) . '</textarea>');
        self::render_input_row(__('Felár összege (HUF)', 'mockup-generator'), '<input type="number" step="0.01" name="amount" value="' . esc_attr($surcharge['amount']) . '" required />');
        self::render_input_row(__('Alapértelmezett állapot', 'mockup-generator'), '<label><input type="checkbox" name="active" value="1" ' . checked($surcharge['active'], true, false) . ' /> ' . esc_html__('Aktív', 'mockup-generator') . '</label>');
        self::render_input_row(__('Vásárlói választás kötelező?', 'mockup-generator'), '<label><input type="checkbox" name="require_choice" value="1" ' . checked($surcharge['require_choice'], true, false) . ' /> ' . esc_html__('Igen', 'mockup-generator') . '</label>');
        self::render_input_row(__('Alapértelmezett érték', 'mockup-generator'), '<label><input type="checkbox" name="default_enabled" value="1" ' . checked($surcharge['default_enabled'], true, false) . ' /> ' . esc_html__('Bekapcsolva', 'mockup-generator') . '</label>');
        self::render_input_row(__('Kosár korlátozás', 'mockup-generator'), '<label><input type="checkbox" name="cart_lock" value="1" ' . checked($surcharge['cart_lock'], true, false) . ' /> ' . esc_html__('Csak azonos feláras opcióval rendelkező termékek lehetnek a kosárban.', 'mockup-generator') . '</label>');
        self::render_input_row(__('Frontend megjelenítés', 'mockup-generator'), self::render_display_select($surcharge['frontend_display']));
        self::render_input_row(__('Prioritás', 'mockup-generator'), '<input type="number" name="priority" value="' . esc_attr($surcharge['priority']) . '" />');
        self::render_conditions_section($surcharge['conditions'], $terms);
        echo '</table>';
        echo '<input type="hidden" name="surcharge_id" value="' . esc_attr($surcharge['id']) . '" />';
        submit_button(__('Mentés', 'mockup-generator'));
        echo '</form>';
        echo '</div>';
    }

    private static function render_conditions_section($conditions, $terms) {
        echo '<tr><th scope="row">' . esc_html__('Feltételek', 'mockup-generator') . '</th><td>';
        echo '<fieldset class="mg-conditions">';
        echo self::render_multiselect(__('Terméktípusok', 'mockup-generator'), 'conditions[product_types][]', $terms['product_types'], $conditions['product_types']);
        echo self::render_multiselect(__('Színek', 'mockup-generator'), 'conditions[colors][]', $terms['colors'], $conditions['colors']);
        echo self::render_multiselect(__('Méret', 'mockup-generator'), 'conditions[sizes][]', $terms['sizes'], $conditions['sizes']);
        echo self::render_multiselect(__('Kategóriák', 'mockup-generator'), 'conditions[categories][]', $terms['categories'], $conditions['categories'], true);
        echo '<p><label>' . esc_html__('Termék ID-k (vesszővel elválasztva)', 'mockup-generator') . '<br /><input type="text" name="conditions[products]" class="regular-text" value="' . esc_attr(implode(',', $conditions['products'])) . '" /></label></p>';
        echo '<p><label>' . esc_html__('Minimum kosárérték (opcionális)', 'mockup-generator') . '<br /><input type="number" step="0.01" name="conditions[min_cart_total]" value="' . esc_attr($conditions['min_cart_total']) . '" /></label></p>';
        echo '</fieldset>';
        echo '</td></tr>';
    }

    private static function render_multiselect($label, $name, $options, $selected, $is_terms_with_id = false) {
        $selected = is_array($selected) ? $selected : [];
        $html = '<p><label>' . esc_html($label) . '<br />';
        $html .= '<select name="' . esc_attr($name) . '" multiple size="5" style="min-width:250px;">';
        foreach ($options as $option) {
            $value = $is_terms_with_id ? $option['id'] : $option['slug'];
            $text = $option['name'];
            $html .= '<option value="' . esc_attr($value) . '" ' . selected(in_array($value, $selected, false), true, false) . '>' . esc_html($text) . '</option>';
        }
        $html .= '</select></label></p>';
        return $html;
    }

    private static function render_display_select($current) {
        $options = [
            'product' => __('Product page', 'mockup-generator'),
            'cart' => __('Cart page', 'mockup-generator'),
            'both' => __('Mindkettő', 'mockup-generator'),
        ];
        $html = '<select name="frontend_display">';
        foreach ($options as $value => $label) {
            $html .= '<option value="' . esc_attr($value) . '" ' . selected($current, $value, false) . '>' . esc_html($label) . '</option>';
        }
        $html .= '</select>';
        return $html;
    }

    private static function render_input_row($label, $field_html) {
        echo '<tr><th scope="row"><label>' . esc_html($label) . '</label></th><td>' . $field_html . '</td></tr>';
    }

    private static function get_form_url($id = '') {
        $args = ['page' => self::MENU_SLUG, 'mg_action' => 'edit'];
        if ($id) {
            $args['surcharge_id'] = $id;
        }
        return add_query_arg($args, admin_url('admin.php'));
    }

    private static function get_terms($taxonomy, $with_ids = false) {
        $terms = get_terms(['taxonomy' => $taxonomy, 'hide_empty' => false]);
        $result = [];
        if (is_wp_error($terms)) {
            return $result;
        }
        foreach ($terms as $term) {
            $result[] = [
                'id' => (int)$term->term_id,
                'slug' => $term->slug,
                'name' => $term->name,
            ];
        }
        return $result;
    }

    private static function get_plugin_sizes() {
        $sizes = [];
        $products = get_option('mg_products', []);
        if (!is_array($products)) {
            return $sizes;
        }
        foreach ($products as $product) {
            if (!is_array($product)) {
                continue;
            }
            $candidates = [];
            if (!empty($product['sizes']) && is_array($product['sizes'])) {
                $candidates = array_merge($candidates, $product['sizes']);
            }
            if (!empty($product['size_color_matrix']) && is_array($product['size_color_matrix'])) {
                $candidates = array_merge($candidates, array_keys($product['size_color_matrix']));
            }
            if (!empty($product['size_surcharges']) && is_array($product['size_surcharges'])) {
                $candidates = array_merge($candidates, array_keys($product['size_surcharges']));
            }
            foreach ($candidates as $size_label) {
                if (!is_string($size_label)) {
                    continue;
                }
                $name = trim(wp_strip_all_tags($size_label));
                if ($name === '') {
                    continue;
                }
                $slug = sanitize_title($name);
                if ($slug === '' || isset($sizes[$slug])) {
                    continue;
                }
                $sizes[$slug] = [
                    'slug' => $slug,
                    'name' => $name,
                ];
            }
        }
        if (!empty($sizes)) {
            uasort($sizes, function ($a, $b) {
                return strcasecmp($a['name'], $b['name']);
            });
        }
        return array_values($sizes);
    }

    private static function unique_terms($terms) {
        $seen = [];
        $unique = [];
        foreach ($terms as $term) {
            $key = isset($term['id']) ? $term['id'] : $term['slug'];
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $unique[] = $term;
        }
        return $unique;
    }

    private static function format_conditions($conditions) {
        if (empty($conditions) || !is_array($conditions)) {
            return __('Nincs', 'mockup-generator');
        }
        $parts = [];
        $map = [
            'product_types' => __('Típus', 'mockup-generator'),
            'colors' => __('Szín', 'mockup-generator'),
            'sizes' => __('Méret', 'mockup-generator'),
            'categories' => __('Kategória', 'mockup-generator'),
            'products' => __('Termék ID', 'mockup-generator'),
            'min_cart_total' => __('Min. kosár', 'mockup-generator'),
        ];
        foreach ($map as $key => $label) {
            if ($key === 'min_cart_total') {
                if (!empty($conditions[$key])) {
                    $parts[] = $label . ': ' . wp_strip_all_tags(wc_price($conditions[$key]));
                }
                continue;
            }
            if (empty($conditions[$key])) {
                continue;
            }
            if ($key === 'categories') {
                $parts[] = $label . ': ' . implode(', ', self::resolve_term_names((array)$conditions[$key], 'product_cat'));
                continue;
            }
            if ($key === 'products') {
                $parts[] = $label . ': ' . implode(', ', self::resolve_product_titles((array)$conditions[$key]));
                continue;
            }
            $parts[] = $label . ': ' . implode(', ', (array)$conditions[$key]);
        }
        return empty($parts) ? __('Nincs', 'mockup-generator') : implode(' | ', $parts);
    }

    private static function resolve_term_names($ids, $taxonomy) {
        $names = [];
        foreach ($ids as $id) {
            $term = get_term((int)$id, $taxonomy);
            if ($term && !is_wp_error($term)) {
                $names[] = $term->name;
            } else {
                $names[] = (string)$id;
            }
        }
        return $names;
    }

    private static function resolve_product_titles($ids) {
        $titles = [];
        foreach ($ids as $id) {
            $title = get_the_title((int)$id);
            if ($title) {
                $titles[] = $title;
            } else {
                $titles[] = (string)$id;
            }
        }
        return $titles;
    }
}
