<?php
if (!defined('ABSPATH')) {
    exit;
}

class MG_Design_Gallery {
    const OPTION_KEY = 'mg_design_gallery';

    /**
     * Initialize hooks, shortcode, block registration and automatic output.
     */
    public static function init() {
        add_shortcode('mg_design_gallery', array(__CLASS__, 'render_shortcode'));
        add_action('init', array(__CLASS__, 'register_block'));
        add_action('wp_enqueue_scripts', array(__CLASS__, 'register_assets'));
        add_action('init', array(__CLASS__, 'maybe_hook_auto_output'));
    }

    /**
     * Register front-end stylesheet for the gallery.
     */
    public static function register_assets() {
        $base_file = dirname(__DIR__) . '/mockup-generator.php';
        $style_path = dirname(__DIR__) . '/assets/css/design-gallery.css';
        $script_path = dirname(__DIR__) . '/assets/js/design-gallery.js';

        wp_register_style(
            'mg-design-gallery',
            plugins_url('assets/css/design-gallery.css', $base_file),
            array(),
            file_exists($style_path) ? filemtime($style_path) : '1.0.0'
        );

        wp_register_script(
            'mg-design-gallery',
            plugins_url('assets/js/design-gallery.js', $base_file),
            array(),
            file_exists($script_path) ? filemtime($script_path) : '1.0.0',
            true
        );
    }

    /**
     * Hook automatic output if enabled in settings.
     */
    public static function maybe_hook_auto_output() {
        $settings = self::get_settings();
        if (empty($settings['enabled'])) {
            return;
        }

        $position = isset($settings['position']) ? $settings['position'] : '';
        $hook = self::map_position_to_hook($position);
        if (!$hook) {
            return;
        }

        add_action($hook['name'], function () use ($settings) {
            if (!function_exists('is_product') || !is_product()) {
                return;
            }
            echo self::render_gallery(array(), $settings);
        }, $hook['priority']);
    }

    /**
     * Render shortcode output.
     *
     * @param array $atts
     * @return string
     */
    public static function render_shortcode($atts = array()) {
        $settings = self::get_settings();
        $atts = shortcode_atts(
            array(
                'title' => isset($settings['title']) ? $settings['title'] : '',
                'max_items' => isset($settings['max_items']) ? $settings['max_items'] : 0,
                'layout' => isset($settings['layout']) ? $settings['layout'] : 'grid',
                'product_id' => 0,
                'show_title' => true,
            ),
            $atts,
            'mg_design_gallery'
        );

        $merged_settings = array_merge($settings, array(
            'title' => sanitize_text_field($atts['title']),
            'max_items' => absint($atts['max_items']),
            'layout' => sanitize_key($atts['layout']),
            'show_title' => filter_var($atts['show_title'], FILTER_VALIDATE_BOOLEAN),
        ));

        $product_id = absint($atts['product_id']);
        $product_id = $product_id > 0 ? $product_id : null;

        return self::render_gallery(array('product_id' => $product_id), $merged_settings, true);
    }

    /**
     * Render callback for the Gutenberg block.
     *
     * @param array $attributes
     * @return string
     */
    public static function render_block($attributes) {
        $settings = self::get_settings();
        $attributes = is_array($attributes) ? $attributes : array();

        $merged_settings = array_merge($settings, array(
            'title' => isset($attributes['title']) ? sanitize_text_field($attributes['title']) : (isset($settings['title']) ? $settings['title'] : ''),
            'max_items' => isset($attributes['maxItems']) ? absint($attributes['maxItems']) : (isset($settings['max_items']) ? absint($settings['max_items']) : 0),
            'layout' => isset($attributes['layout']) ? sanitize_key($attributes['layout']) : (isset($settings['layout']) ? $settings['layout'] : 'grid'),
            'show_title' => array_key_exists('showTitle', $attributes) ? (bool) $attributes['showTitle'] : (isset($settings['show_title']) ? (bool) $settings['show_title'] : true),
        ));

        return self::render_gallery(array(), $merged_settings, true);
    }

    /**
     * Register block type.
     */
    public static function register_block() {
        if (!function_exists('register_block_type')) {
            return;
        }

        self::register_assets();

        $base_file = dirname(__DIR__) . '/mockup-generator.php';
        $script_path = dirname(__DIR__) . '/assets/js/design-gallery-block.js';

        wp_register_script(
            'mg-design-gallery-block',
            plugins_url('assets/js/design-gallery-block.js', $base_file),
            array('wp-blocks', 'wp-element', 'wp-components', 'wp-i18n', 'wp-block-editor'),
            file_exists($script_path) ? filemtime($script_path) : '1.0.0',
            true
        );

        register_block_type('mockup-generator/design-gallery', array(
            'editor_script' => 'mg-design-gallery-block',
            'style' => 'mg-design-gallery',
            'render_callback' => array(__CLASS__, 'render_block'),
            'attributes' => array(
                'title' => array(
                    'type' => 'string',
                    'default' => '',
                ),
                'maxItems' => array(
                    'type' => 'number',
                    'default' => 0,
                ),
                'layout' => array(
                    'type' => 'string',
                    'default' => 'grid',
                ),
                'showTitle' => array(
                    'type' => 'boolean',
                    'default' => true,
                ),
            ),
        ));
    }

    /**
     * Render the gallery markup.
     *
     * @param array $context Optional context such as product_id
     * @param array $settings Render settings
     * @param bool $force Whether to return markup even if outside product loop
     * @return string
     */
    protected static function render_gallery($context = array(), $settings = array(), $force = false) {
        $product_id = isset($context['product_id']) && $context['product_id'] ? absint($context['product_id']) : 0;

        if ($product_id <= 0 && function_exists('is_product') && is_product()) {
            global $post;
            $product_id = $post ? $post->ID : 0;
        }

        if ($product_id <= 0 && !$force) {
            return '';
        }

        $items = self::collect_items($product_id, isset($settings['max_items']) ? absint($settings['max_items']) : 0);
        if (empty($items)) {
            return '';
        }

        wp_enqueue_style('mg-design-gallery');
        wp_enqueue_script('mg-design-gallery');

        $layout = isset($settings['layout']) ? sanitize_key($settings['layout']) : 'grid';
        $layout_class = 'mg-design-gallery--' . $layout;
        $title = isset($settings['title']) ? $settings['title'] : '';
        $show_title = isset($settings['show_title']) ? (bool) $settings['show_title'] : true;

        ob_start();
        ?>
        <section class="mg-design-gallery <?php echo esc_attr($layout_class); ?>" data-mg-gallery="true">
            <?php if ($show_title && $title !== '') : ?>
                <header class="mg-design-gallery__header">
                    <h2 class="mg-design-gallery__title"><?php echo esc_html($title); ?></h2>
                </header>
            <?php endif; ?>
            <div class="mg-design-gallery__viewport">
                <button type="button" class="mg-design-gallery__nav mg-design-gallery__nav--prev" aria-label="<?php esc_attr_e('Előző termék', 'mgdg'); ?>" data-mg-gallery-prev>
                    <span aria-hidden="true">‹</span>
                </button>
                <div class="mg-design-gallery__items" role="list">
                    <?php foreach ($items as $item) : ?>
                        <article class="mg-design-gallery__item" role="button" tabindex="0" data-type="<?php echo esc_attr($item['type_slug']); ?>" data-color="<?php echo esc_attr($item['color_slug']); ?>" aria-label="<?php echo esc_attr($item['alt']); ?>">
                            <div class="mg-design-gallery__thumb">
                                <img src="<?php echo esc_url($item['image']); ?>" alt="<?php echo esc_attr($item['alt']); ?>" loading="lazy" decoding="async" />
                            </div>
                            <div class="mg-design-gallery__meta">
                                <div class="mg-design-gallery__type"><?php echo esc_html($item['type_label']); ?></div>
                            </div>
                        </article>
                    <?php endforeach; ?>
                </div>
                <button type="button" class="mg-design-gallery__nav mg-design-gallery__nav--next" aria-label="<?php esc_attr_e('Következő termék', 'mgdg'); ?>" data-mg-gallery-next>
                    <span aria-hidden="true">›</span>
                </button>
            </div>
        </section>
        <?php
        return trim(ob_get_clean());
    }

    /**
     * Collect gallery items for the given product.
     *
     * @param int $product_id
     * @param int $limit
     * @return array
     */
    protected static function collect_items($product_id, $limit = 0) {
        if (!function_exists('wc_get_product')) {
            return array();
        }

        $product = $product_id > 0 ? wc_get_product($product_id) : null;
        if (!$product || !method_exists($product, 'get_id')) {
            return array();
        }

        $index = array();
        if (class_exists('MG_Mockup_Maintenance')) {
            $index = MG_Mockup_Maintenance::get_index();
        }

        if (empty($index) || !is_array($index)) {
            return array();
        }

        $entries = array();
        foreach ($index as $entry) {
            if (!is_array($entry)) {
                continue;
            }
            if ((int) ($entry['product_id'] ?? 0) !== $product->get_id()) {
                continue;
            }
            $type_slug = sanitize_title($entry['type_slug'] ?? '');
            $color_slug = sanitize_title($entry['color_slug'] ?? '');
            if ($type_slug === '' || $color_slug === '') {
                continue;
            }
            if (!isset($entries[$type_slug])) {
                $entries[$type_slug] = array();
            }
            $entries[$type_slug][$color_slug] = $entry;
        }

        if (empty($entries)) {
            return array();
        }

        $catalog = class_exists('MG_Variant_Display_Manager') ? MG_Variant_Display_Manager::get_catalog_index() : array();
        $defaults = $product->get_default_attributes();
        $default_type = sanitize_title($defaults['pa_termektipus'] ?? '');
        $default_color = sanitize_title($defaults['pa_szin'] ?? '');

        $items = array();
        foreach ($entries as $type_slug => $by_color) {
            if (!is_array($by_color) || empty($by_color)) {
                continue;
            }

            $type_meta = isset($catalog[$type_slug]) ? $catalog[$type_slug] : array();
            $color_slug = self::resolve_color_slug_for_type($type_slug, $by_color, $default_type, $default_color, $type_meta);
            if (!isset($by_color[$color_slug])) {
                $color_slug = key($by_color);
            }
            if (!isset($by_color[$color_slug])) {
                continue;
            }

            $image = self::resolve_image_url($by_color[$color_slug], $product);
            if ($image === '') {
                continue;
            }

            $type_label = self::resolve_type_label($type_slug, $type_meta);
            $alt = $type_label;

            $items[] = array(
                'image' => $image,
                'type_label' => $type_label,
                'type_slug' => $type_slug,
                'color_slug' => $color_slug,
                'alt' => $alt,
            );

            if ($limit > 0 && count($items) >= $limit) {
                break;
            }
        }

        return $items;
    }

    /**
     * Resolve the preferred color slug for a given type.
     *
     * @param string $type_slug
     * @param array $by_color
     * @param string $default_type
     * @param string $default_color
     * @param array $type_meta
     * @return string
     */
    protected static function resolve_color_slug_for_type($type_slug, $by_color, $default_type, $default_color, $type_meta) {
        $type_slug = sanitize_title($type_slug);
        $primary_color = '';

        if ($default_type === $type_slug && $default_color !== '' && isset($by_color[$default_color])) {
            return $default_color;
        }

        foreach ($by_color as $color_slug => $entry) {
            $source_defaults = isset($entry['source']['defaults']) ? $entry['source']['defaults'] : array();
            if (is_array($source_defaults) && !empty($source_defaults['pa_szin'])) {
                $candidate = sanitize_title($source_defaults['pa_szin']);
                if ($candidate !== '' && isset($by_color[$candidate])) {
                    return $candidate;
                }
            }
        }

        if (!empty($type_meta['primary_color'])) {
            $primary_color = sanitize_title($type_meta['primary_color']);
            if ($primary_color !== '' && isset($by_color[$primary_color])) {
                return $primary_color;
            }
        }

        $first = array_keys($by_color);
        return sanitize_title(reset($first));
    }

    /**
     * Resolve image URL from an index entry.
     *
     * @param array $entry
     * @return string
     */
    protected static function resolve_image_url($entry, $product = null) {
        if (!is_array($entry)) {
            return '';
        }
        $source = isset($entry['source']) && is_array($entry['source']) ? $entry['source'] : array();

        if (!empty($source['attachment_ids']) && is_array($source['attachment_ids'])) {
            foreach ($source['attachment_ids'] as $attachment_id) {
                $attachment_id = absint($attachment_id);
                if ($attachment_id <= 0) {
                    continue;
                }
                $url = wp_get_attachment_image_url($attachment_id, 'large');
                if ($url) {
                    return $url;
                }
            }
        }

        if (!empty($source['images']) && is_array($source['images'])) {
            foreach ($source['images'] as $path) {
                if (!is_string($path) || $path === '') {
                    continue;
                }
                $url = self::convert_path_to_url($path);
                if ($url !== '') {
                    return $url;
                }
            }
        }

        $variation_url = self::resolve_variation_image_url($entry, $product);
        if ($variation_url !== '') {
            return $variation_url;
        }

        return '';
    }

    /**
     * Resolve variation image URL from the product based on type/color attributes.
     *
     * @param array $entry
     * @param WC_Product|null $product
     * @return string
     */
    protected static function resolve_variation_image_url($entry, $product = null) {
        if (!function_exists('wc_get_product')) {
            return '';
        }
        $product_id = absint($entry['product_id'] ?? 0);
        if (!$product || !is_object($product) || !method_exists($product, 'get_id')) {
            if ($product_id <= 0) {
                return '';
            }
            $product = wc_get_product($product_id);
        }
        if (!$product || !method_exists($product, 'get_children') || !$product->get_id()) {
            return '';
        }
        if (method_exists($product, 'is_type') && !$product->is_type('variable')) {
            return '';
        }
        $type_slug = sanitize_title($entry['type_slug'] ?? '');
        $color_slug = sanitize_title($entry['color_slug'] ?? '');
        if ($type_slug === '' || $color_slug === '') {
            return '';
        }
        $children = $product->get_children();
        foreach ((array) $children as $child_id) {
            $variation = wc_get_product($child_id);
            if (!$variation || !method_exists($variation, 'get_attributes')) {
                continue;
            }
            $attrs = $variation->get_attributes();
            $var_type = sanitize_title($attrs['pa_termektipus'] ?? '');
            $var_color = sanitize_title($attrs['pa_szin'] ?? '');
            if ($var_type !== $type_slug || $var_color !== $color_slug) {
                continue;
            }
            if (method_exists($variation, 'get_image_id')) {
                $image_id = (int) $variation->get_image_id();
                if ($image_id > 0 && function_exists('wp_get_attachment_image_url')) {
                    $url = wp_get_attachment_image_url($image_id, 'large');
                    if ($url) {
                        return $url;
                    }
                }
            }
        }
        return '';
    }

    /**
     * Convert a filesystem path to a URL within the uploads directory.
     *
     * @param string $path
     * @return string
     */
    protected static function convert_path_to_url($path) {
        $uploads = function_exists('wp_get_upload_dir') ? wp_get_upload_dir() : wp_upload_dir();
        if (empty($uploads['basedir']) || empty($uploads['baseurl'])) {
            return '';
        }
        $normalized_base = wp_normalize_path($uploads['basedir']);
        $normalized_path = wp_normalize_path($path);
        if (strpos($normalized_path, $normalized_base) !== 0) {
            return '';
        }
        $relative = ltrim(str_replace($normalized_base, '', $normalized_path), '/');
        return trailingslashit($uploads['baseurl']) . str_replace('\\', '/', $relative);
    }

    /**
     * Resolve the type label for a given type slug.
     *
     * @param string $type_slug
     * @param array $type_meta
     * @return string
     */
    protected static function resolve_type_label($type_slug, $type_meta) {
        if (!empty($type_meta['label'])) {
            return sanitize_text_field($type_meta['label']);
        }
        return self::get_attribute_term_label('pa_termektipus', $type_slug);
    }

    /**
     * Get label for attribute term.
     *
     * @param string $taxonomy
     * @param string $slug
     * @return string
     */
    protected static function get_attribute_term_label($taxonomy, $slug) {
        $taxonomy = sanitize_key($taxonomy);
        $slug = sanitize_title($slug);
        if ($slug === '') {
            return '';
        }
        if (!taxonomy_exists($taxonomy)) {
            return $slug;
        }
        $term = get_term_by('slug', $slug, $taxonomy);
        if ($term && !is_wp_error($term)) {
            return $term->name;
        }
        return $slug;
    }

    /**
     * Get saved settings with defaults.
     *
     * @return array
     */
    public static function get_settings() {
        $defaults = array(
            'enabled' => false,
            'position' => 'after_summary',
            'max_items' => 0,
            'layout' => 'grid',
            'title' => __('Minta az összes terméken', 'mgdg'),
            'show_title' => true,
        );
        $stored = get_option(self::OPTION_KEY, array());
        if (!is_array($stored)) {
            $stored = array();
        }
        $sanitized = self::sanitize_settings($stored);
        return wp_parse_args($sanitized, $defaults);
    }

    /**
     * Sanitize and persist settings from admin page.
     *
     * @param array $input
     * @return array
     */
    public static function sanitize_settings($input) {
        $clean = array(
            'enabled' => !empty($input['enabled']),
            'position' => isset($input['position']) ? sanitize_key($input['position']) : 'after_summary',
            'max_items' => isset($input['max_items']) ? absint($input['max_items']) : 0,
            'layout' => isset($input['layout']) ? sanitize_key($input['layout']) : 'grid',
            'title' => isset($input['title']) ? sanitize_text_field($input['title']) : '',
            'show_title' => isset($input['show_title']) ? (bool) $input['show_title'] : true,
        );

        $allowed_positions = array_keys(self::position_map());
        if (!in_array($clean['position'], $allowed_positions, true)) {
            $clean['position'] = 'after_summary';
        }

        if ($clean['max_items'] < 0) {
            $clean['max_items'] = 0;
        }

        if ($clean['layout'] === '') {
            $clean['layout'] = 'grid';
        }

        update_option(self::OPTION_KEY, $clean);
        return $clean;
    }

    /**
     * Map position value to WooCommerce hook data.
     *
     * @param string $position
     * @return array|null
     */
    protected static function map_position_to_hook($position) {
        $map = self::position_map();
        return isset($map[$position]) ? $map[$position] : null;
    }

    /**
     * Available positions for automatic output.
     *
     * @return array
     */
    protected static function position_map() {
        return array(
            'after_summary' => array(
                'name' => 'woocommerce_after_single_product_summary',
                'priority' => 15,
                'label' => __('Termékoldal tartalom után', 'mgdg'),
            ),
            'after_cart' => array(
                'name' => 'woocommerce_single_product_summary',
                'priority' => 35,
                'label' => __('Kosár gomb után', 'mgdg'),
            ),
            'after_short_description' => array(
                'name' => 'woocommerce_single_product_summary',
                'priority' => 25,
                'label' => __('Rövid leírás után', 'mgdg'),
            ),
        );
    }

    /**
     * Expose position map for admin UI.
     *
     * @return array
     */
    public static function get_position_choices() {
        return self::position_map();
    }
}
