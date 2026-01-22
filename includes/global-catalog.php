<?php
if (!defined('ABSPATH')) {
    exit;
}

if (!class_exists('MG_Global_Attributes')) {
    class MG_Global_Attributes {
        protected static $config = null;
        protected static $catalog = null;

        public static function load_config() {
            if (self::$config !== null) {
                return self::$config;
            }

            $path = dirname(__DIR__) . '/includes/config/global-attributes.php';
            if (!file_exists($path)) {
                // Fallback to example file if main doesn't exist
                // This prevents Git updates from breaking the site
                $example_path = dirname(__DIR__) . '/includes/config/global-attributes.example.php';
                if (file_exists($example_path)) {
                    $path = $example_path;
                } else {
                    self::$config = array();
                    return self::$config;
                }
            }

            $config = require $path;
            self::$config = is_array($config) ? $config : array();
            return self::$config;
        }

        public static function get_catalog() {
            if (self::$catalog !== null) {
                return self::$catalog;
            }

            $config = self::load_config();
            $products = isset($config['products']) && is_array($config['products']) ? $config['products'] : array();

            $defaults = array(
                'enabled' => true,
                'price' => 0,
                'sizes' => array(),
                'colors' => array(),
                'primary_color' => '',
                'primary_size' => '',
                'size_color_matrix' => array(),
                'size_surcharges' => array(),
                'type_description' => '',
            );

            $normalized = self::normalize_products($products, $defaults);
            self::$catalog = $normalized;
            return self::$catalog;
        }

        protected static function normalize_products($products, $defaults) {
            $out = array();

            if (!is_array($products)) {
                return $out;
            }

            foreach ($products as $product) {
                if (!is_array($product)) {
                    continue;
                }

                $key = isset($product['key']) ? sanitize_title($product['key']) : '';
                if ($key === '') {
                    continue;
                }

                $entry = array_merge($defaults, $product);
                $entry['key'] = $key;
                $entry['label'] = isset($entry['label']) ? wp_kses_post($entry['label']) : $key;
                $entry['price'] = intval($entry['price']);
                $entry['sizes'] = self::normalize_sizes($entry['sizes']);
                $entry['colors'] = self::normalize_colors($entry['colors']);
                $entry['primary_color'] = sanitize_title($entry['primary_color']);
                $entry['primary_size'] = sanitize_text_field($entry['primary_size']);
                $entry['size_color_matrix'] = self::normalize_size_color_matrix(
                    $entry['sizes'],
                    $entry['colors'],
                    $entry['size_color_matrix']
                );
                $entry['size_surcharges'] = self::normalize_size_surcharges($entry['size_surcharges'], $entry['sizes']);
                $entry['type_description'] = isset($entry['type_description']) ? wp_kses_post($entry['type_description']) : '';

                if (!self::is_valid_primary_color($entry['primary_color'], $entry['colors'])) {
                    $entry['primary_color'] = '';
                }
                if ($entry['primary_size'] !== '' && !in_array($entry['primary_size'], $entry['sizes'], true)) {
                    $entry['primary_size'] = '';
                }

                $out[$key] = $entry;
            }

            return $out;
        }

        protected static function normalize_colors($colors) {
            $normalized = array();
            $seen = array();

            if (!is_array($colors)) {
                return $normalized;
            }

            foreach ($colors as $color) {
                if (is_string($color)) {
                    $color = array('name' => $color, 'slug' => $color);
                }
                if (!is_array($color)) {
                    continue;
                }

                $name = isset($color['name']) ? sanitize_text_field($color['name']) : '';
                $slug = isset($color['slug']) ? sanitize_title($color['slug']) : sanitize_title($name);
                if ($slug === '') {
                    continue;
                }
                if (isset($seen[$slug])) {
                    continue;
                }

                $entry = array(
                    'name' => $name !== '' ? $name : $slug,
                    'slug' => $slug,
                );
                if (!empty($color['hex'])) {
                    $hex = sanitize_hex_color($color['hex']);
                    if ($hex) {
                        $entry['hex'] = $hex;
                    }
                }
                if (isset($color['surcharge'])) {
                    $entry['surcharge'] = floatval($color['surcharge']);
                }

                $normalized[] = $entry;
                $seen[$slug] = true;
            }

            return $normalized;
        }

        protected static function normalize_sizes($sizes) {
            if (!is_array($sizes)) {
                return array();
            }

            $normalized = array();
            foreach ($sizes as $size) {
                if (!is_string($size)) {
                    continue;
                }
                $size = trim($size);
                if ($size === '' || in_array($size, $normalized, true)) {
                    continue;
                }
                $normalized[] = $size;
            }

            return array_values($normalized);
        }

        protected static function normalize_size_color_matrix($sizes, $colors, $matrix) {
            $size_values = self::normalize_sizes($sizes);
            $color_slugs = array();
            foreach ($colors as $color) {
                if (is_array($color) && !empty($color['slug'])) {
                    $slug = sanitize_title($color['slug']);
                    if ($slug !== '' && !in_array($slug, $color_slugs, true)) {
                        $color_slugs[] = $slug;
                    }
                }
            }

            $normalized = array();
            if (!is_array($matrix)) {
                return $normalized;
            }

            foreach ($matrix as $size_key => $color_list) {
                if (!is_string($size_key)) {
                    continue;
                }
                $size_key = trim($size_key);
                if ($size_key === '' || !in_array($size_key, $size_values, true)) {
                    continue;
                }
                $clean = array();
                if (is_array($color_list)) {
                    foreach ($color_list as $slug) {
                        $slug = sanitize_title($slug);
                        if ($slug === '' || !in_array($slug, $color_slugs, true)) {
                            continue;
                        }
                        if (!in_array($slug, $clean, true)) {
                            $clean[] = $slug;
                        }
                    }
                }
                $normalized[$size_key] = $clean;
            }

            return $normalized;
        }

        protected static function normalize_size_surcharges($surcharges, $sizes) {
            if (!is_array($surcharges) || empty($sizes)) {
                return array();
            }

            $normalized = array();
            foreach ($surcharges as $size_label => $value) {
                $size_label = sanitize_text_field($size_label);
                if ($size_label === '' || !in_array($size_label, $sizes, true)) {
                    continue;
                }
                $normalized[$size_label] = floatval($value);
            }

            return $normalized;
        }

        protected static function is_valid_primary_color($primary_color, $colors) {
            if ($primary_color === '') {
                return true;
            }
            foreach ($colors as $color) {
                if (!empty($color['slug']) && $color['slug'] === $primary_color) {
                    return true;
                }
            }
            return false;
        }
    }
}

function mg_get_global_catalog() {
    return MG_Global_Attributes::get_catalog();
}

function mg_global_catalog_enabled() {
    $catalog = mg_get_global_catalog();
    return !empty($catalog);
}

function mg_apply_global_catalog_to_product($product, $catalog = null) {
    if (!is_array($product)) {
        return $product;
    }
    if ($catalog === null) {
        $catalog = mg_get_global_catalog();
    }
    $key = isset($product['key']) ? sanitize_title($product['key']) : '';
    if ($key === '' || empty($catalog[$key]) || !is_array($catalog[$key])) {
        return $product;
    }

    $global = $catalog[$key];
    $product['price'] = $global['price'];
    $product['sizes'] = $global['sizes'];
    $product['colors'] = $global['colors'];
    $product['primary_color'] = $global['primary_color'];
    $product['primary_size'] = $global['primary_size'];
    $product['size_color_matrix'] = $global['size_color_matrix'];
    $product['size_surcharges'] = $global['size_surcharges'];
    $product['type_description'] = $global['type_description'];

    return $product;
}

function mg_get_catalog_products($products = null) {
    $catalog = mg_get_global_catalog();
    if (!empty($catalog)) {
        return $catalog;
    }

    if ($products === null) {
        $products = get_option('mg_products', array());
    }
    if (!is_array($products)) {
        $products = array();
    }
    return $products;
}
