<?php
if (!defined('ABSPATH')) exit;
class MG_Product_Settings_Page {
    /**
     * Cache for normalized mockup paths during a single request to avoid
     * repeating expensive filesystem checks while saving large product
     * configurations.
     *
     * @var array<string,string>
     */
    private static $normalized_mockup_path_cache = array();
    private static function normalize_size_color_matrix_for_product($sizes, $colors, $input) {
        $size_values = array();
        if (is_array($sizes)) {
            foreach ($sizes as $size_label) {
                if (!is_string($size_label)) { continue; }
                $size_label = trim($size_label);
                if ($size_label === '') { continue; }
                $size_values[] = $size_label;
            }
        }
        $size_values = array_values(array_unique($size_values));
        $color_slugs = array();
        if (is_array($colors)) {
            foreach ($colors as $c) {
                if (is_array($c) && isset($c['slug'])) {
                    $slug = sanitize_title($c['slug']);
                    if ($slug !== '' && !in_array($slug, $color_slugs, true)) {
                        $color_slugs[] = $slug;
                    }
                }
            }
        }
        $normalized = array();
        if (is_array($input)) {
            foreach ($input as $size_key => $color_list) {
                if (!is_string($size_key)) { continue; }
                $size_key = trim($size_key);
                if ($size_key === '' || !in_array($size_key, $size_values, true)) { continue; }
                $clean = array();
                if (is_array($color_list)) {
                    foreach ($color_list as $slug) {
                        $slug = sanitize_title($slug);
                        if ($slug === '' || !in_array($slug, $color_slugs, true)) { continue; }
                        if (!in_array($slug, $clean, true)) { $clean[] = $slug; }
                    }
                }
                $normalized[$size_key] = $clean;
            }
        }
        return $normalized;
    }

    private static function normalize_single_mockup_path($path) {
        if (!is_string($path)) {
            return '';
        }

        $path = trim($path);
        if ($path === '') {
            return '';
        }

        $normalized_input = wp_normalize_path($path);
        $cache_key = md5($normalized_input);

        if (isset(self::$normalized_mockup_path_cache[$cache_key])) {
            return self::$normalized_mockup_path_cache[$cache_key];
        }

        $path = $normalized_input;

        $candidates = array();
        $uploads = wp_upload_dir();
        $basedir = !empty($uploads['basedir']) ? wp_normalize_path($uploads['basedir']) : '';
        $baseurl = !empty($uploads['baseurl']) ? rtrim($uploads['baseurl'], '/') : '';

        $is_url = filter_var($path, FILTER_VALIDATE_URL);
        if ($is_url) {
            if ($baseurl && $basedir && strpos($path, $baseurl) === 0) {
                $relative = ltrim(substr($path, strlen($baseurl)), '/');
                $candidates[] = wp_normalize_path(trailingslashit($basedir) . $relative);
            } else {
                self::$normalized_mockup_path_cache[$cache_key] = '';
                return '';
            }
        } else {
            $candidates[] = $path;
            if ($basedir !== '') {
                $candidates[] = wp_normalize_path(trailingslashit($basedir) . ltrim($path, '/'));
            }
            $candidates[] = wp_normalize_path(ABSPATH . ltrim($path, '/'));
            $plugin_root = wp_normalize_path(trailingslashit(dirname(__DIR__)));
            $candidates[] = wp_normalize_path($plugin_root . ltrim($path, '/'));
        }

        $checked = array();
        foreach ($candidates as $candidate) {
            if (!is_string($candidate) || $candidate === '') {
                continue;
            }
            $candidate = wp_normalize_path($candidate);
            if (in_array($candidate, $checked, true)) {
                continue;
            }
            $checked[] = $candidate;
            if (file_exists($candidate) && is_file($candidate) && is_readable($candidate)) {
                self::$normalized_mockup_path_cache[$cache_key] = $candidate;
                return $candidate;
            }
        }

        self::$normalized_mockup_path_cache[$cache_key] = '';
        return '';
    }

    private static function count_mockup_paths($structure) {
        $count = 0;
        if (!is_array($structure)) {
            return 0;
        }
        foreach ($structure as $color_entry) {
            if (is_array($color_entry)) {
                foreach ($color_entry as $paths) {
                    if (is_array($paths)) {
                        foreach ($paths as $path) {
                            if (is_string($path) && trim($path) !== '') {
                                $count++;
                            }
                        }
                    } elseif (is_string($paths) && trim($paths) !== '') {
                        $count++;
                    }
                }
            } elseif (is_string($color_entry) && trim($color_entry) !== '') {
                $count++;
            }
        }
        return $count;
    }

    private static function coerce_mockup_path_list($value) {
        $paths = array();
        if (is_array($value)) {
            foreach ($value as $item) {
                if (!is_string($item)) {
                    continue;
                }
                $normalized = trim(wp_normalize_path($item));
                if ($normalized === '' || in_array($normalized, $paths, true)) {
                    continue;
                }
                $paths[] = $normalized;
            }
        } elseif (is_string($value)) {
            $normalized = trim(wp_normalize_path($value));
            if ($normalized !== '') {
                $paths[] = $normalized;
            }
        }
        return array_values($paths);
    }

    private static function coerce_mockup_overrides_structure($input) {
        $result = array();
        if (!is_array($input)) {
            return $result;
        }
        foreach ($input as $color_slug => $views) {
            if (!is_string($color_slug)) { continue; }
            $color_slug = trim($color_slug);
            if ($color_slug === '') { continue; }
            if (!is_array($views)) { $views = array(); }
            foreach ($views as $view_key => $paths) {
                if (!is_string($view_key)) { continue; }
                $view_key = trim($view_key);
                if ($view_key === '') { continue; }
                $list = self::coerce_mockup_path_list($paths);
                if (empty($list)) { continue; }
                $result[$color_slug][$view_key] = $list;
            }
        }
        return $result;
    }

    private static function sanitize_mockup_path_list($value) {
        $paths = array();
        if (is_array($value)) {
            foreach ($value as $item) {
                $normalized = self::normalize_single_mockup_path($item);
                if ($normalized === '' || in_array($normalized, $paths, true)) {
                    continue;
                }
                $paths[] = $normalized;
            }
        } elseif (is_string($value)) {
            $normalized = self::normalize_single_mockup_path($value);
            if ($normalized !== '') {
                $paths[] = $normalized;
            }
        }
        return array_values($paths);
    }

    private static function sanitize_mockup_overrides_structure($input) {
        $result = array();
        if (!is_array($input)) {
            return $result;
        }
        foreach ($input as $color_slug => $views) {
            if (!is_string($color_slug)) { continue; }
            $color_slug = trim($color_slug);
            if ($color_slug === '') { continue; }
            if (!is_array($views)) { $views = array(); }
            foreach ($views as $view_key => $paths) {
                if (!is_string($view_key)) { continue; }
                $view_key = trim($view_key);
                if ($view_key === '') { continue; }
                $list = self::sanitize_mockup_path_list($paths);
                if (empty($list)) { continue; }
                $result[$color_slug][$view_key] = $list;
            }
            if (empty($result[$color_slug])) {
                unset($result[$color_slug]);
            }
        }
        return $result;
    }
    public static function register_dynamic_product_submenus() {
        add_submenu_page('mockup-generator','Termék – szerkesztés','Termék: szerkesztés','manage_options','mockup-generator-product',[self::class,'render_product'],20);
    }
    private static function generate_unique_key($base, $products) {
        $base = sanitize_title($base);
        if ($base === '') {
            $base = 'termek';
        }
        $existing = array();
        if (is_array($products)) {
            foreach ($products as $product) {
                if (is_array($product) && !empty($product['key'])) {
                    $existing[] = sanitize_title($product['key']);
                }
            }
        }
        $candidate = $base;
        $suffix = 1;
        while (in_array($candidate, $existing, true)) {
            $candidate = $base . '-' . $suffix;
            $suffix++;
        }
        return $candidate;
    }
    private static function get_product_by_key($key) {
        $products = get_option('mg_products', array());
        
// --- Törlés kezelése ---
if (isset($_GET['mg_delete_key']) && current_user_can('manage_options')) {
    $del_key = sanitize_text_field($_GET['mg_delete_key']);
    $all = get_option('mg_products', array());
    $all = array_filter($all, function($p) use ($del_key) {
        return isset($p['key']) && $p['key'] !== $del_key;
    });
    update_option('mg_products', array_values($all));
    wp_safe_redirect(admin_url('admin.php?page=mockup-generator-settings&deleted=1'));
    exit;
}
if (isset($_GET['mg_duplicate_key']) && current_user_can('manage_options')) {
    $dup_key = sanitize_text_field($_GET['mg_duplicate_key']);
    $nonce = isset($_GET['_wpnonce']) ? sanitize_text_field($_GET['_wpnonce']) : '';
    if ($dup_key && wp_verify_nonce($nonce, 'mg_duplicate_product_' . $dup_key)) {
        $original = null;
        foreach ($products as $product) {
            if (is_array($product) && isset($product['key']) && $product['key'] === $dup_key) {
                $original = $product;
                break;
            }
        }
        if (is_array($original)) {
            $new_key = self::generate_unique_key($dup_key . '-copy', $products);
            $new_label = trim((string) ($original['label'] ?? $dup_key));
            $new_label = $new_label !== '' ? $new_label . ' (másolat)' : $new_key;
            $copy = $original;
            $copy['key'] = $new_key;
            $copy['label'] = $new_label;
            $copy['sku_prefix'] = strtoupper($new_key);
            $copy['is_primary'] = 0;
            $products[] = $copy;
            update_option('mg_products', $products);
            wp_safe_redirect(admin_url('admin.php?page=mockup-generator-settings&product=' . $new_key));
            exit;
        }
    }
}
foreach ($products as $p) if ($p['key']===$key) return $p;
        return null;
    }
    private static function save_product($prod) {
        $products = get_option('mg_products', array());
        $is_primary = !empty($prod['is_primary']);
        foreach ($products as $i=>$p) {
            if (!is_array($p) || !isset($p['key'])) continue;
            if ($p['key']===$prod['key']) {
                $products[$i]=$prod;
            } elseif ($is_primary) {
                $products[$i]['is_primary'] = 0;
            }
        }
        update_option('mg_products',$products);
        self::sync_variant_display_colors($prod);
        if (class_exists('MG_Mockup_Maintenance')) {
            MG_Mockup_Maintenance::sync_type_label($prod['key'], $prod['label'] ?? '');
        }
    }

    private static function sync_variant_display_colors($prod) {
        if (!is_array($prod) || empty($prod['key'])) {
            return;
        }

        $type_slug = sanitize_title($prod['key']);
        if ($type_slug === '') {
            return;
        }

        $store = get_option('mg_variant_display', array());
        if (!is_array($store)) {
            $store = array();
        }

        if (!isset($store['colors']) || !is_array($store['colors'])) {
            $store['colors'] = array();
        }

        $synced = array();
        if (!empty($prod['colors']) && is_array($prod['colors'])) {
            foreach ($prod['colors'] as $color) {
                if (!is_array($color) || empty($color['slug'])) {
                    continue;
                }
                $slug = sanitize_title($color['slug']);
                if ($slug === '') {
                    continue;
                }
                $hex = '';
                if (!empty($color['hex'])) {
                    $candidate = sanitize_hex_color($color['hex']);
                    if ($candidate) {
                        $hex = $candidate;
                    }
                }
                $synced[$slug] = array('swatch' => $hex);
            }
        }

        if (!empty($synced)) {
            $store['colors'][$type_slug] = $synced;
        } else {
            unset($store['colors'][$type_slug]);
        }

        update_option('mg_variant_display', $store);
    }

    public static function update_product_color_swatches($type_slug, $color_settings) {
        $type_slug = sanitize_title($type_slug);
        if ($type_slug === '') {
            return;
        }

        $products = get_option('mg_products', array());
        if (!is_array($products)) {
            return;
        }

        $updated = false;

        foreach ($products as $index => $product) {
            if (!is_array($product) || empty($product['key'])) {
                continue;
            }

            if (sanitize_title($product['key']) !== $type_slug) {
                continue;
            }

            if (empty($product['colors']) || !is_array($product['colors'])) {
                $product['colors'] = array();
            }

            $updated_colors = array();
            foreach ($product['colors'] as $color) {
                if (!is_array($color) || empty($color['slug'])) {
                    $updated_colors[] = $color;
                    continue;
                }

                $slug = sanitize_title($color['slug']);
                if ($slug === '') {
                    $updated_colors[] = $color;
                    continue;
                }

                $hex = '';
                if (is_array($color_settings) && isset($color_settings[$slug]['swatch'])) {
                    $candidate = sanitize_hex_color($color_settings[$slug]['swatch']);
                    if ($candidate) {
                        $hex = $candidate;
                    }
                }

                if ($hex !== '') {
                    $color['hex'] = $hex;
                } else {
                    unset($color['hex']);
                }

                $updated_colors[] = $color;
            }

            $product['colors'] = $updated_colors;
            $products[$index] = $product;
            $updated = true;
            break;
        }

        if ($updated) {
            update_option('mg_products', $products);
        }
    }
    public static function render_product() {
        // Enqueue assets for Print Area modal
        wp_enqueue_script('mg-printarea', plugin_dir_url(__FILE__).'../assets/js/printarea.js', array('jquery'), '1.0.0', true);
        wp_enqueue_style('mg-printarea', plugin_dir_url(__FILE__).'../assets/css/printarea.css', array(), '1.0.0');

        $key = sanitize_text_field($_GET['product'] ?? '');
        $prod = self::get_product_by_key($key);
        if (!$prod) { echo '<div class="notice notice-error"><p>Ismeretlen termék.</p></div>'; return; }
        if (!isset($prod['size_color_matrix']) || !is_array($prod['size_color_matrix'])) { $prod['size_color_matrix'] = array(); }

        $sanitized_overrides = null;
        $working_overrides   = null;

        if (isset($_POST['mg_save_product_nonce']) && wp_verify_nonce($_POST['mg_save_product_nonce'],'mg_save_product')) {
            $update_existing_prices = !empty($_POST['update_existing_prices']);
            $label = sanitize_text_field($_POST['label'] ?? '');
            if ($label !== '') {
                $prod['label'] = $label;
            }
            $sizes = array_filter(array_map('trim', explode(',', sanitize_text_field($_POST['sizes'] ?? ''))));
            if (!empty($sizes)) $prod['sizes']=$sizes;

            $colors_text = sanitize_textarea_field($_POST['colors'] ?? '');
            $color_lines = array_filter(array_map('trim', explode(PHP_EOL, $colors_text)));
            $colors = array();
            foreach ($color_lines as $line) {
                if (strpos($line, ':') === false) {
                    continue;
                }
                list($name_part, $slug_part) = array_map('trim', explode(':', $line, 2));
                if ($name_part === '' || $slug_part === '') {
                    continue;
                }
                $name_only = $name_part;
                $hex_value = '';
                if (strpos($name_part, '|') !== false) {
                    list($name_only, $hex_value) = array_map('trim', explode('|', $name_part, 2));
                    if ($name_only === '') {
                        $name_only = $name_part;
                    }
                }
                $hex_clean = '';
                if ($hex_value !== '') {
                    $hex_candidate = sanitize_hex_color($hex_value);
                    if ($hex_candidate) {
                        $hex_clean = $hex_candidate;
                    }
                }
                $slug_clean = sanitize_title($slug_part);
                if ($slug_clean === '') {
                    continue;
                }
                $color_entry = array(
                    'name' => $name_only,
                    'slug' => $slug_clean,
                );
                if ($hex_clean !== '') {
                    $color_entry['hex'] = $hex_clean;
                }
                $colors[] = $color_entry;
            }
            if (!empty($colors)) {
                $prod['colors'] = $colors;
            }

            $is_primary = !empty($_POST['is_primary']) ? 1 : 0;
            $chosen_color = isset($_POST['primary_color']) ? sanitize_title($_POST['primary_color']) : ($prod['primary_color'] ?? '');
            $chosen_size = isset($_POST['primary_size']) ? sanitize_text_field($_POST['primary_size']) : ($prod['primary_size'] ?? '');
            $color_slugs = array();
            if (!empty($prod['colors']) && is_array($prod['colors'])) {
                foreach ($prod['colors'] as $c) {
                    if (isset($c['slug'])) { $color_slugs[] = $c['slug']; }
                }
            }
            $size_values = isset($prod['sizes']) && is_array($prod['sizes']) ? array_values(array_filter(array_map('trim', $prod['sizes']), function($s){ return $s !== ''; })) : array();
            if ($chosen_color && !in_array($chosen_color, $color_slugs, true)) {
                $chosen_color = '';
            }
            if ($chosen_size && !in_array($chosen_size, $size_values, true)) {
                $chosen_size = '';
            }
            if ($is_primary && !$chosen_color && !empty($color_slugs)) {
                $chosen_color = $color_slugs[0];
            }
            if ($is_primary && !$chosen_size && !empty($size_values)) {
                $chosen_size = $size_values[0];
            }
            $prod['is_primary'] = $is_primary;
            $prod['primary_color'] = $chosen_color;
            $prod['primary_size'] = $chosen_size;

            $matrix_input = isset($_POST['size_color_matrix']) ? $_POST['size_color_matrix'] : array();
            $prod['size_color_matrix'] = self::normalize_size_color_matrix_for_product($prod['sizes'], $prod['colors'], $matrix_input);

            $views_json = stripslashes($_POST['views'] ?? '');
            $views = json_decode($views_json, true);
            if (is_array($views)) $prod['views']=$views;

            $base = sanitize_text_field($_POST['template_base'] ?? $prod['template_base']);
            $prod['template_base']=$base;

            $price = intval($_POST['price'] ?? $prod['price'] ?? 0);
            $prod['price'] = $price;

            $prod['sku_prefix'] = strtoupper(sanitize_text_field($_POST['sku_prefix'] ?? ($prod['sku_prefix'] ?? '')));

            
// -- ÚJ: termék leírás mentése --
if (isset($_POST['type_description'])) {
    $prod['type_description'] = wp_kses_post(stripslashes($_POST['type_description']));
}
// -- ÚJ: méret felárak mentése --
if (isset($_POST['size_surcharges']) && is_array($_POST['size_surcharges'])) {
    $ss = array();
    foreach ($_POST['size_surcharges'] as $size => $val) {
        $size_key = sanitize_text_field($size);
        $amount = intval($val);
        if ($size_key !== '') { $ss[$size_key] = $amount; }
    }
    $prod['size_surcharges'] = $ss;
}
            $removed_overrides = 0;
            $overrides_dirty = false;

            $raw_overrides = isset($prod['mockup_overrides']) && is_array($prod['mockup_overrides']) ? $prod['mockup_overrides'] : array();
            $sanitized_overrides = is_array($raw_overrides) ? $raw_overrides : array();

            $remove_requests = isset($_POST['mockup_remove']) && is_array($_POST['mockup_remove']) ? $_POST['mockup_remove'] : array();
            if (!empty($remove_requests)) {
                $did_remove = false;
                if ($working_overrides === null) {
                    $working_overrides = self::coerce_mockup_overrides_structure($raw_overrides);
                }
                foreach ($remove_requests as $color_slug => $views) {
                    $color_key = sanitize_text_field($color_slug);
                    if ($color_key !== $color_slug || $color_key === '' || $working_overrides === null || empty($working_overrides[$color_key]) || !is_array($views)) {
                        continue;
                    }
                    foreach ($views as $file_key => $indexes) {
                        $view_key = sanitize_text_field($file_key);
                        if ($view_key !== $file_key || $view_key === '' || !isset($working_overrides[$color_key][$view_key])) {
                            continue;
                        }
                        $current = self::coerce_mockup_path_list($working_overrides[$color_key][$view_key]);
                        $indexes = array_unique(array_map('intval', (array) $indexes));
                        if (empty($indexes)) {
                            continue;
                        }
                        $filtered = array();
                        foreach ($current as $idx => $path) {
                            if (!in_array($idx, $indexes, true)) {
                                $filtered[] = $path;
                            }
                        }
                        if (count($filtered) !== count($current)) {
                            if (!empty($filtered)) {
                                $working_overrides[$color_key][$view_key] = array_values($filtered);
                            } else {
                                unset($working_overrides[$color_key][$view_key]);
                            }
                            $did_remove = true;
                        }
                    }
                    if (empty($working_overrides[$color_key]) || !is_array($working_overrides[$color_key])) {
                        unset($working_overrides[$color_key]);
                        $did_remove = true;
                    }
                }
                if ($did_remove) {
                    $overrides_dirty = true;
                }
            }

            if (!empty($_FILES['mockup_files']['name'])) {
                if ($working_overrides === null) {
                    $working_overrides = self::coerce_mockup_overrides_structure($raw_overrides);
                }
                if (!function_exists('wp_handle_upload')) {
                    require_once ABSPATH . 'wp-admin/includes/file.php';
                }
                foreach ($_FILES['mockup_files']['name'] as $color_slug => $files) {
                    $color_key = sanitize_text_field($color_slug);
                    if ($color_key !== $color_slug || $color_key === '') { continue; }
                    foreach ($files as $file_key => $names) {
                        $view_key = sanitize_text_field($file_key);
                        if ($view_key !== $file_key || $view_key === '') { continue; }
                        $name_list = is_array($names) ? $names : array($names);
                        foreach ($name_list as $idx => $name) {
                            if (empty($name)) { continue; }
                            $tmp_name_source = $_FILES['mockup_files']['tmp_name'][$color_slug][$file_key] ?? '';
                            $type_source = $_FILES['mockup_files']['type'][$color_slug][$file_key] ?? '';
                            $error_source = $_FILES['mockup_files']['error'][$color_slug][$file_key] ?? 0;
                            $size_source = $_FILES['mockup_files']['size'][$color_slug][$file_key] ?? 0;
                            $tmp_name = is_array($tmp_name_source) ? ($tmp_name_source[$idx] ?? '') : $tmp_name_source;
                            if (empty($tmp_name)) { continue; }
                            $type = is_array($type_source) ? ($type_source[$idx] ?? '') : $type_source;
                            $error = is_array($error_source) ? ($error_source[$idx] ?? 0) : $error_source;
                            $size = is_array($size_source) ? ($size_source[$idx] ?? 0) : $size_source;
                            if ((int)$error !== UPLOAD_ERR_OK) {
                                continue;
                            }
                            $file = array(
                                'name' => $name,
                                'type' => $type,
                                'tmp_name' => $tmp_name,
                                'error' => $error,
                                'size' => $size,
                            );
                            $uploaded = wp_handle_upload($file, array('test_form'=>false, 'mimes'=>array('png'=>'image/png','jpg'=>'image/jpeg','jpeg'=>'image/jpeg','webp'=>'image/webp')));
                            if (!empty($uploaded['error'])) { continue; }
                            if (!isset($working_overrides[$color_key]) || !is_array($working_overrides[$color_key])) {
                                $working_overrides[$color_key] = array();
                            }
                            $existing = isset($working_overrides[$color_key][$view_key]) ? $working_overrides[$color_key][$view_key] : array();
                            $existing = self::coerce_mockup_path_list($existing);
                            $existing[] = $uploaded['file'];
                            $new_list = self::sanitize_mockup_path_list($existing);
                            if (!isset($working_overrides[$color_key][$view_key]) || $working_overrides[$color_key][$view_key] !== $new_list) {
                                $working_overrides[$color_key][$view_key] = $new_list;
                                $overrides_dirty = true;
                            }
                        }
                    }
                }
            }

            if ($working_overrides !== null && !$overrides_dirty) {
                $sanitized_overrides = $working_overrides;
            }

            if ($working_overrides !== null) {
                $prod['mockup_overrides'] = $working_overrides;
            }

            if ($overrides_dirty) {
                $before_final_clean = self::count_mockup_paths($prod['mockup_overrides']);
                $prod['mockup_overrides'] = self::sanitize_mockup_overrides_structure($prod['mockup_overrides']);
                $after_final_clean = self::count_mockup_paths($prod['mockup_overrides']);
                if ($after_final_clean < $before_final_clean) {
                    $removed_overrides += ($before_final_clean - $after_final_clean);
                }
                $sanitized_overrides = $prod['mockup_overrides'];
            }

            self::save_product($prod);
            echo '<div class="notice notice-success is-dismissible"><p>Termék beállításai elmentve.</p></div>';
            if ($removed_overrides > 0) {
                echo '<div class="notice notice-warning is-dismissible"><p>';
                printf(esc_html__('%d mockup háttér nem volt elérhető, ezért eltávolítottuk a listából. Ellenőrizd a mockup feltöltéseket.', 'mgdtp'), $removed_overrides);
                echo '</p></div>';
            }
            if ($update_existing_prices) {
                $update_result = self::enqueue_price_update_job($prod);
                if (is_wp_error($update_result)) {
                    echo '<div class="notice notice-error is-dismissible"><p>' . esc_html($update_result->get_error_message()) . '</p></div>';
                } else {
                    echo '<div class="notice notice-info is-dismissible"><p>';
                    printf(esc_html__('Árfrissítés ütemezve. A frissítés háttérben, több lépésben fog lefutni (job: %s).', 'mgdtp'), esc_html($update_result));
                    echo '</p></div>';
                }
            }
        }

        $sizes = $prod['sizes'];
        $colors = $prod['colors'];
        $size_color_matrix = self::normalize_size_color_matrix_for_product($sizes, $colors, $prod['size_color_matrix']);
        $views  = $prod['views'];
        $template_base = $prod['template_base'];
        $sku_prefix = $prod['sku_prefix'] ?? '';
        $price = intval($prod['price'] ?? 0);
        if ($sanitized_overrides === null) {
            if ($working_overrides !== null) {
                $sanitized_overrides = $working_overrides;
            } else {
                $sanitized_overrides = is_array($prod['mockup_overrides'] ?? null)
                    ? $prod['mockup_overrides']
                    : array();
            }
        }
        $prod['mockup_overrides'] = $sanitized_overrides;
        $over = $sanitized_overrides;

        $colors_text = implode(
            PHP_EOL,
            array_filter(
                array_map(
                    function ($color) {
                        if (!is_array($color) || !isset($color['name'], $color['slug'])) {
                            return '';
                        }
                        $name = (string) $color['name'];
                        $slug = (string) $color['slug'];
                        $hex  = '';
                        if (!empty($color['hex'])) {
                            $hex_candidate = sanitize_hex_color($color['hex']);
                            if ($hex_candidate) {
                                $hex = $hex_candidate;
                            }
                        }
                        if ($hex !== '') {
                            $name .= '|' . $hex;
                        }
                        return $name . ':' . $slug;
                    },
                    $colors
                )
            )
        );
        $is_primary = !empty($prod['is_primary']);
        $primary_color = isset($prod['primary_color']) ? $prod['primary_color'] : '';
        $primary_size = isset($prod['primary_size']) ? $prod['primary_size'] : '';

        // Helper: path -> URL in uploads
        $uploads = wp_upload_dir();
        $uploads_base = trailingslashit($uploads['basedir']);
        $uploads_url  = trailingslashit($uploads['baseurl']);

        ?>
        <div class="wrap">
            <h1>Termék beállítások – <?php echo esc_html($prod['label']); ?>
<a href="<?php echo esc_url(add_query_arg('mg_delete_key', $prod['key'])); ?>"
   class="button button-link-delete"
   onclick="return confirm('Biztosan törlöd ezt a terméktípust?');">Törlés</a> (<code><?php echo esc_html($prod['key']); ?></code>)</h1>
            <p>
                <a
                    href="<?php echo esc_url(wp_nonce_url(add_query_arg('mg_duplicate_key', $prod['key']), 'mg_duplicate_product_' . $prod['key'])); ?>"
                    class="button"
                ><?php esc_html_e('Terméktípus duplikálása', 'mgdtp'); ?></a>
            </p>
            <form method="post" enctype="multipart/form-data">
                <?php wp_nonce_field('mg_save_product','mg_save_product_nonce'); ?>
                <h2>Megjelenített név</h2>
                <p><input type="text" name="label" class="regular-text" value="<?php echo esc_attr($prod['label']); ?>" /></p>
                <h2>Alap ár (HUF)</h2>
                <p><input type="number" name="price" class="small-text" min="0" step="1" value="<?php echo esc_attr($price); ?>" /></p>
                <p>
                    <label>
                        <input type="checkbox" name="update_existing_prices" value="1" />
                        <?php esc_html_e('Már legenerált termékek/variációk árainak frissítése (alapár + felárak).', 'mgdtp'); ?>
                    </label>
                </p>

                <h2>SKU prefix</h2>
                <p><input type="text" name="sku_prefix" class="regular-text" value="<?php echo esc_attr($sku_prefix); ?>" /></p>

                <h2>Elsődleges beállítások</h2>
                <p><label><input type="checkbox" name="is_primary" value="1" <?php checked($is_primary); ?> /> Ez legyen az alapértelmezett terméktípus</label></p>
                <p>
                    <label>Elsődleges szín<br>
                        <select name="primary_color" style="min-width:220px">
                            <option value="">— Válassz színt —</option>
                            <?php foreach ($colors as $c): if (!isset($c['slug'], $c['name'])) continue; ?>
                                <option value="<?php echo esc_attr($c['slug']); ?>" <?php selected($primary_color, $c['slug']); ?>><?php echo esc_html($c['name']); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </label>
                    <span class="description" style="display:block;margin-top:4px;">Csak az aktuális terméktípus színei választhatók. A kiválasztott páros jelenik meg alapértelmezettként a bulk feltöltésben.</span>
                </p>
                <p>
                    <label>Elsődleges méret<br>
                        <select name="primary_size" style="min-width:220px">
                            <option value="">— Válassz méretet —</option>
                            <?php foreach ($sizes as $size_option): if (!is_string($size_option) || $size_option === '') continue; ?>
                                <option value="<?php echo esc_attr($size_option); ?>" <?php selected($primary_size, $size_option); ?>><?php echo esc_html($size_option); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </label>
                    <span class="description" style="display:block;margin-top:4px;">A kiválasztott méret jelenik meg alapértelmezésként a WooCommerce termékvariációknál.</span>
                </p>

                <h2>Méretek</h2>
                <p><input type="text" name="sizes" class="regular-text" value="<?php echo esc_attr(implode(',', $sizes)); ?>" /></p>

                <h2>Méret elérhetőség színenként</h2>
                <?php
                $available_sizes = is_array($sizes) ? array_values(array_filter(array_map('trim', $sizes), function($s){ return $s !== ''; })) : array();
                $available_colors = array();
                if (is_array($colors)) {
                    foreach ($colors as $c) {
                        if (isset($c['slug'], $c['name'])) {
                            $available_colors[] = array('slug'=>sanitize_title($c['slug']), 'name'=>$c['name']);
                        }
                    }
                }
                if (empty($available_sizes) || empty($available_colors)) {
                    echo '<p class="description">Adj meg legalább egy méretet és színt, hogy korlátozni tudd a kombinációkat.</p>';
                } else {
                    echo '<table class="widefat striped" style="max-width:100%;width:auto">';
                    echo '<thead><tr><th>Méret</th>';
                    foreach ($available_colors as $color) {
                        echo '<th>'.esc_html($color['name']).'<br><code>'.esc_html($color['slug']).'</code></th>';
                    }
                    echo '</tr></thead><tbody>';
                    $has_matrix = !empty($size_color_matrix);
                    foreach ($available_sizes as $size_label) {
                        $current = isset($size_color_matrix[$size_label]) ? $size_color_matrix[$size_label] : array();
                        $row_checked = $has_matrix ? $current : wp_list_pluck($available_colors, 'slug');
                        echo '<tr><td><strong>'.esc_html($size_label).'</strong></td>';
                        foreach ($available_colors as $color) {
                            $checked = in_array($color['slug'], $row_checked, true) ? ' checked' : '';
                            echo '<td><label><input type="checkbox" name="size_color_matrix['.esc_attr($size_label).'][]" value="'.esc_attr($color['slug']).'"'.$checked.'> '.esc_html($color['name']).'</label></td>';
                        }
                        echo '</tr>';
                    }
                    echo '</tbody></table>';
                    echo '<p class="description">Csak a kijelölt szín–méret párosokból készülnek WooCommerce variációk. Ha mindent üresen hagysz, minden kombináció engedélyezett.</p>';
                }
                ?>


<h2>Méret felárak</h2>
<p class="description">Pozitív vagy negatív érték (HUF). Végső variáns ár = Alap ár + Méret felár.</p>
<table class="widefat striped">
    <thead><tr><th>Méret</th><th>Felár (HUF)</th></tr></thead>
    <tbody>
    <?php
    $sizes_list = is_array($sizes) ? $sizes : array();
    $saved_ss = isset($prod['size_surcharges']) && is_array($prod['size_surcharges']) ? $prod['size_surcharges'] : array();
    foreach ($sizes_list as $s):
        $val = isset($saved_ss[$s]) ? intval($saved_ss[$s]) : 0;
    ?>
        <tr>
            <td><code><?php echo esc_html($s); ?></code></td>
            <td><input type="number" name="size_surcharges[<?php echo esc_attr($s); ?>]" class="small-text" step="1" value="<?php echo esc_attr($val); ?>" /></td>
        </tr>
    <?php endforeach; ?>
    </tbody>
</table>
<h2>Színek</h2>

                <div class="mg-color-field" data-mg-color-manager>
                    <div class="mg-color-field__chips" role="list"></div>
                    <button type="button" class="button button-secondary mg-color-field__add">
                        <?php esc_html_e('Új szín hozzáadása', 'mockup-generator'); ?>
                    </button>
                    <textarea
                        id="mg-color-field-input"
                        name="colors"
                        class="mg-color-field__input large-text code"
                        rows="6"
                    ><?php echo esc_textarea($colors_text); ?></textarea>
                    <p class="description mg-color-field__description">
                        <?php esc_html_e('A színek neve, slugja és opcionális hex értéke kerül mentésre. A sorrend drag and drop módban módosítható.', 'mockup-generator'); ?>
                    </p>
                    <noscript>
                        <p class="description">
                            <?php esc_html_e('A vizuális színkezelő JavaScriptet igényel. Engedélyezd a böngésződben, vagy szerkeszd a listát közvetlenül a mezőben.', 'mockup-generator'); ?>
                        </p>
                    </noscript>
                </div>

                <h2>Nézetek (views)</h2>
                <p><textarea id="mg-views-json" name="views" rows="12" class="large-text code"><?php echo esc_textarea(json_encode($views, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)); ?></textarea></p>

                <h2>Template alap mappa</h2>
                <p><input type="text" name="template_base" class="regular-text" value="<?php echo esc_attr($template_base); ?>" /></p>

                
<h2>Termék leírás</h2>
<p class="description">Ez kerül a WooCommerce termék hosszú leírásába; a rövid leírást automatikusan egy kivonatból generáljuk.</p>
<?php
$curr_desc = isset($prod['type_description']) ? $prod['type_description'] : '';
$seo_vars = function_exists('mgtd__get_description_variables') ? mgtd__get_description_variables() : array();
$placeholder_tokens = array('{product_name}', '{product_category}', '{product_categories}', '{category_seo}', '{category_seos}', '{category_seo:slug}');
if (!empty($seo_vars)) {
    foreach (array_keys($seo_vars) as $slug) {
        $placeholder_tokens[] = '{seo:' . $slug . '}';
    }
}
echo '<p class="description">Elérhető változók: ';
foreach ($placeholder_tokens as $index => $token) {
    if ($index > 0) {
        echo ' ';
    }
    echo '<code>' . esc_html($token) . '</code>';
}
echo '</p>';
if (function_exists('wp_editor')) {
    wp_editor(
        $curr_desc,
        'mg_type_description',
        array(
            'textarea_name' => 'type_description',
            'textarea_rows' => 8,
            'media_buttons' => false,
            'teeny' => true,
            'tinymce' => true,
            'quicktags' => true,
        )
    );
} else {
    echo '<textarea name="type_description" rows="8" class="large-text">'.esc_textarea($curr_desc).'</textarea>';
}
?>
                <h2>Mockup feltöltés (szín × nézet)</h2>
                <p class="description">Színenként és nézetenként több mockup is tárolható; a generáláskor a rendszer véletlenszerűen választ közülük.</p>
                <p class="description">Feltöltés után katt a <em>Print area jelölése</em> gombra: megnyílik egy jelölőréteg, ahol húzással/átméretezéssel állítod a nyomtatási területet. Az eredmény a fenti „Nézetek (views)” JSON-ba íródik (x,y,w,h).</p>

                <table class="widefat striped">
                    <thead>
                        <tr>
                            <th>Szín</th>
                            <?php foreach ($views as $v): ?>
                                <th style="vertical-align:top">
                                    <?php echo esc_html($v['label']); ?><br><code><?php echo esc_html($v['file']); ?></code><br>
                                    <button type="button"
                                        class="button button-secondary mg-open-printarea"
                                        data-viewkey="<?php echo esc_attr($v['key']); ?>"
                                        data-viewfile="<?php echo esc_attr($v['file']); ?>"
                                        data-productkey="<?php echo esc_attr($prod['key']); ?>"
                                        data-images='<?php
                                            $map = array();
                                            foreach ($colors as $c) {
                                                $slug = $c['slug'];
                                                $stored = isset($over[$slug][$v['file']]) ? $over[$slug][$v['file']] : array();
                                                $candidates = is_array($stored) ? $stored : array($stored);
                                                foreach ($candidates as $path) {
                                                    if ($path && strpos($path, $uploads_base) === 0) {
                                                        $rel = substr($path, strlen($uploads_base));
                                                        $url = $uploads_url . str_replace(DIRECTORY_SEPARATOR, '/', $rel);
                                                        $map[$slug] = $url;
                                                        break;
                                                    }
                                                }
                                            }
                                            echo esc_attr(json_encode($map));
                                        ?>'>Print area jelölése</button>
                                </th>
                            <?php endforeach; ?>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($colors as $c): $slug = $c['slug']; ?>
                        <tr>
                            <td><strong><?php echo esc_html($c['name']); ?></strong><br><code><?php echo esc_html($slug); ?></code></td>
                            <?php foreach ($views as $v):
                                $file_key = $v['file'];
                                $existing = isset($over[$slug][$file_key]) ? $over[$slug][$file_key] : array();
                                if (!is_array($existing)) {
                                    $existing = $existing ? array($existing) : array();
                                }
                                $existing = array_values(array_filter(array_map(function($path) {
                                    return is_string($path) ? trim($path) : '';
                                }, $existing), function($path) {
                                    return $path !== '';
                                }));
                            ?>
                                <td>
                                    <?php if (!empty($existing)): ?>
                                        <div class="mg-mockup-existing-list">
                                            <?php foreach ($existing as $idx => $path):
                                                $display = '';
                                                if ($path && strpos($path, $uploads_base) === 0) {
                                                    $rel = substr($path, strlen($uploads_base));
                                                    $display = $uploads_url . str_replace(DIRECTORY_SEPARATOR, '/', $rel);
                                                }
                                            ?>
                                                <div class="mg-mockup-existing-item">
                                                    <div>Mockup #<?php echo esc_html($idx + 1); ?>: <code><?php echo esc_html(basename($path)); ?></code></div>
                                                    <?php if ($display): ?>
                                                        <div><a href="<?php echo esc_url($display); ?>" target="_blank">Megnyitás</a></div>
                                                    <?php endif; ?>
                                                    <label><input type="checkbox" name="mockup_remove[<?php echo esc_attr($slug); ?>][<?php echo esc_attr($file_key); ?>][]" value="<?php echo esc_attr($idx); ?>" /> Törlés</label>
                                                </div>
                                            <?php endforeach; ?>
                                        </div>
                                    <?php endif; ?>
                                    <input type="file" name="mockup_files[<?php echo esc_attr($slug); ?>][<?php echo esc_attr($file_key); ?>][]" accept=".png,.jpg,.jpeg,.webp" multiple />
                                    <p class="description">Új fájlok hozzáadásához jelöld ki egyszerre a feltölteni kívánt képeket.</p>
                                </td>
                            <?php endforeach; ?>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>

                <?php submit_button('Mentés'); ?>
            </form>
        </div>

        <!-- Modal UI for print area -->
        <div id="mg-printarea-modal" class="mg-pa-hidden">
          <div class="mg-pa-backdrop"></div>
          <div class="mg-pa-dialog">
            <div class="mg-pa-header">
              <strong>Print area kijelölése</strong>
              <button type="button" class="button-link mg-pa-close">×</button>
            </div>
            <div class="mg-pa-toolbar">
              <label>Szín:
                <select id="mg-pa-color"></select>
              </label>
              <span class="mg-pa-hint">Húzd a keretet, vagy a sarkain fogd meg a méretezéshez. Mentés: „Alkalmaz”.</span>
            </div>
            <div class="mg-pa-canvas-wrap">
              <img id="mg-pa-image" src="" alt="" />
              <div id="mg-pa-rect">
                <div class="mg-pa-handle tl"></div>
                <div class="mg-pa-handle tr"></div>
                <div class="mg-pa-handle bl"></div>
                <div class="mg-pa-handle br"></div>
              </div>
            </div>
            <div class="mg-pa-footer">
              <button type="button" class="button button-secondary mg-pa-cancel">Mégse</button>
              <button type="button" class="button button-primary mg-pa-apply">Alkalmaz</button>
            </div>
          </div>
        </div>

        <script>
        window.MG_PA_DEFAULTS = <?php echo json_encode($views, JSON_UNESCAPED_UNICODE); ?>;
        </script>
        <?php
    }

    private static function enqueue_price_update_job($prod) {
        if (!function_exists('wc_get_product')) {
            return new WP_Error('woocommerce_missing', __('A WooCommerce nem elérhető az árfrissítéshez.', 'mgdtp'));
        }
        if (!is_array($prod) || empty($prod['key'])) {
            return new WP_Error('invalid_product', __('Hiányzó terméktípus kulcs.', 'mgdtp'));
        }
        $type_key = sanitize_title($prod['key']);
        if ($type_key === '') {
            return new WP_Error('invalid_product', __('Érvénytelen terméktípus kulcs.', 'mgdtp'));
        }

        $jobs = get_option('mg_price_update_jobs', array());
        if (!is_array($jobs)) {
            $jobs = array();
        }
        $job_id = uniqid('mg_price_update_', false);
        $jobs[$job_id] = array(
            'type_key'        => $type_key,
            'base_price'      => intval($prod['price'] ?? 0),
            'size_surcharges' => isset($prod['size_surcharges']) && is_array($prod['size_surcharges']) ? $prod['size_surcharges'] : array(),
            'color_surcharges'=> isset($prod['color_surcharges']) && is_array($prod['color_surcharges']) ? $prod['color_surcharges'] : array(),
            'page'            => 1,
            'per_page'        => 200,
            'updated'         => 0,
            'total'           => 0,
            'done'            => false,
            'started_at'      => time(),
            'last_run'        => 0,
        );
        update_option('mg_price_update_jobs', $jobs);
        if (!wp_next_scheduled('mg_price_update_job_process', array($job_id))) {
            wp_schedule_single_event(time() + 5, 'mg_price_update_job_process', array($job_id));
        }
        return $job_id;
    }

    public static function process_price_update_job($job_id) {
        $jobs = get_option('mg_price_update_jobs', array());
        if (!is_array($jobs) || empty($jobs[$job_id]) || !is_array($jobs[$job_id])) {
            return;
        }
        $job = $jobs[$job_id];
        if (!empty($job['done'])) {
            return;
        }
        $type_key = sanitize_title($job['type_key'] ?? '');
        if ($type_key === '') {
            $job['done'] = true;
            $jobs[$job_id] = $job;
            update_option('mg_price_update_jobs', $jobs);
            return;
        }
        $per_page = max(1, min(500, intval($job['per_page'] ?? 200)));
        $page = max(1, intval($job['page'] ?? 1));

        $query = new WP_Query(array(
            'post_type'      => 'product_variation',
            'post_status'    => array('publish', 'private', 'draft', 'inherit'),
            'fields'         => 'ids',
            'posts_per_page' => $per_page,
            'paged'          => $page,
            'meta_query'     => array(
                array(
                    'key'   => 'attribute_pa_termektipus',
                    'value' => $type_key,
                ),
            ),
        ));

        if (!empty($job['total']) || empty($query->found_posts)) {
            $job['total'] = intval($job['total'] ?? 0);
        } else {
            $job['total'] = intval($query->found_posts);
        }

        $variation_ids = $query->posts;
        if (empty($variation_ids)) {
            $job['done'] = true;
            $job['last_run'] = time();
            $jobs[$job_id] = $job;
            update_option('mg_price_update_jobs', $jobs);
            return;
        }

        $size_surcharges = isset($job['size_surcharges']) && is_array($job['size_surcharges']) ? $job['size_surcharges'] : array();
        $color_surcharges = isset($job['color_surcharges']) && is_array($job['color_surcharges']) ? $job['color_surcharges'] : array();
        $base_price = intval($job['base_price'] ?? 0);
        $parent_ids = array();

        foreach ($variation_ids as $variation_id) {
            $variation = wc_get_product($variation_id);
            if (!$variation) {
                continue;
            }
            $color_slug = sanitize_title($variation->get_attribute('pa_szin'));
            $size_value = $variation->get_attribute('meret');
            if ($size_value === '') {
                $size_value = $variation->get_attribute('pa_meret');
            }
            $size_value = sanitize_text_field($size_value);

            if (function_exists('mgsc_compute_variant_price')) {
                $new_price = mgsc_compute_variant_price($type_key, $size_value, $color_slug);
            } else {
                $size_extra = isset($size_surcharges[$size_value]) ? intval($size_surcharges[$size_value]) : 0;
                $color_extra = isset($color_surcharges[$color_slug]) ? intval($color_surcharges[$color_slug]) : 0;
                $new_price = max(0, $base_price + $size_extra + $color_extra);
            }

            if ($new_price === null) {
                continue;
            }
            $variation->set_regular_price((string) $new_price);
            $variation->save();
            $job['updated'] = intval($job['updated'] ?? 0) + 1;
            $parent_id = $variation->get_parent_id();
            if ($parent_id) {
                $parent_ids[] = $parent_id;
            }
        }

        $parent_ids = array_values(array_unique(array_filter($parent_ids)));
        foreach ($parent_ids as $parent_id) {
            if (function_exists('wc_delete_product_transients')) {
                wc_delete_product_transients($parent_id);
            }
            if (function_exists('wc_update_product_lookup_tables')) {
                wc_update_product_lookup_tables($parent_id);
            }
        }

        if (count($variation_ids) < $per_page) {
            $job['done'] = true;
        } else {
            $job['page'] = $page + 1;
        }
        $job['last_run'] = time();
        $jobs[$job_id] = $job;
        update_option('mg_price_update_jobs', $jobs);

        if (empty($job['done'])) {
            wp_schedule_single_event(time() + 5, 'mg_price_update_job_process', array($job_id));
        }
    }
}

add_action('mg_price_update_job_process', array('MG_Product_Settings_Page', 'process_price_update_job'), 10, 1);
