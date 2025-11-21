<?php
/**
 * Pattern Showcase Frontend Class
 *
 * Handles frontend display of pattern showcases
 *
 * @package MockupGenerator
 * @since 1.3.0
 */

if (!defined('ABSPATH')) {
    exit;
}

class MG_Pattern_Showcase_Frontend {

    /**
     * Initialize frontend hooks
     */
    public static function init() {
        // Register shortcode
        add_shortcode('mg_pattern_showcase', array(__CLASS__, 'shortcode_handler'));

        // Enqueue assets on pages with shortcode
        add_action('wp_enqueue_scripts', array(__CLASS__, 'enqueue_assets'));
    }

    /**
     * Shortcode handler
     *
     * @param array $atts Shortcode attributes
     * @return string HTML output
     */
    public static function shortcode_handler($atts) {
        $atts = shortcode_atts(array(
            'id' => '',
            'layout' => '', // Override layout
            'columns' => '', // Override columns
        ), $atts);

        if (empty($atts['id'])) {
            return '<p>' . __('Pattern Showcase ID is required', 'mockup-generator') . '</p>';
        }

        $showcase = MG_Pattern_Showcase_Manager::get_showcase($atts['id']);

        if (!$showcase) {
            return '<p>' . __('Pattern Showcase not found', 'mockup-generator') . '</p>';
        }

        if (empty($showcase['mockups'])) {
            return '<p>' . __('No mockups generated for this showcase yet', 'mockup-generator') . '</p>';
        }

        // Override layout/columns if specified in shortcode
        $layout = !empty($atts['layout']) ? $atts['layout'] : $showcase['layout'];
        $columns = !empty($atts['columns']) ? intval($atts['columns']) : $showcase['columns'];

        return self::render_showcase($showcase, $layout, $columns);
    }

    /**
     * Render showcase HTML
     *
     * @param array $showcase Showcase data
     * @param string $layout Layout type
     * @param int $columns Number of columns (for grid)
     * @return string HTML output
     */
    public static function render_showcase($showcase, $layout, $columns) {
        ob_start();

        $showcase_id = $showcase['id'];
        $group_by_category = !empty($showcase['group_by_category']);

        // Get mockup data
        if ($group_by_category) {
            $mockup_data = MG_Pattern_Showcase_Manager::get_grouped_mockups($showcase_id);
        } else {
            $mockup_data = self::get_ungrouped_mockups($showcase);
        }

        if (empty($mockup_data)) {
            echo '<p>' . __('No mockups to display', 'mockup-generator') . '</p>';
            return ob_get_clean();
        }

        // Wrapper classes
        $wrapper_classes = array(
            'mg-pattern-showcase',
            'mg-layout-' . $layout,
            $group_by_category ? 'mg-grouped' : 'mg-ungrouped'
        );

        ?>
        <div class="<?php echo esc_attr(implode(' ', $wrapper_classes)); ?>"
             data-showcase-id="<?php echo esc_attr($showcase_id); ?>"
             data-layout="<?php echo esc_attr($layout); ?>"
             data-columns="<?php echo esc_attr($columns); ?>">

            <?php if ($group_by_category): ?>
                <?php foreach ($mockup_data as $category => $items): ?>
                    <div class="mg-showcase-category">
                        <h3 class="mg-category-title"><?php echo esc_html($category); ?></h3>

                        <?php if ($layout === 'carousel'): ?>
                            <?php self::render_carousel($items, $category); ?>
                        <?php else: ?>
                            <?php self::render_grid($items, $columns, $category); ?>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
            <?php else: ?>
                <?php if ($layout === 'carousel'): ?>
                    <?php self::render_carousel($mockup_data); ?>
                <?php else: ?>
                    <?php self::render_grid($mockup_data, $columns); ?>
                <?php endif; ?>
            <?php endif; ?>

        </div>
        <?php

        return ob_get_clean();
    }

    /**
     * Render carousel layout
     *
     * @param array $items Mockup items
     * @param string $category Category slug (optional)
     */
    private static function render_carousel($items, $category = '') {
        $carousel_id = 'mg-carousel-' . uniqid();
        ?>
        <div class="mg-carousel-wrapper" id="<?php echo esc_attr($carousel_id); ?>">
            <div class="mg-carousel-container">
                <div class="mg-carousel-track">
                    <?php foreach ($items as $index => $item): ?>
                        <div class="mg-carousel-item" data-index="<?php echo $index; ?>">
                            <?php self::render_mockup_item($item); ?>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>

            <?php if (count($items) > 1): ?>
                <button class="mg-carousel-nav mg-carousel-prev" aria-label="<?php _e('Previous', 'mockup-generator'); ?>">
                    <span class="mg-nav-icon">&lt;</span>
                </button>
                <button class="mg-carousel-nav mg-carousel-next" aria-label="<?php _e('Next', 'mockup-generator'); ?>">
                    <span class="mg-nav-icon">&gt;</span>
                </button>

                <div class="mg-carousel-dots">
                    <?php foreach ($items as $index => $item): ?>
                        <button class="mg-carousel-dot <?php echo $index === 0 ? 'active' : ''; ?>"
                                data-index="<?php echo $index; ?>"
                                aria-label="<?php printf(__('Go to slide %d', 'mockup-generator'), $index + 1); ?>">
                        </button>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
        <?php
    }

    /**
     * Render grid layout
     *
     * @param array $items Mockup items
     * @param int $columns Number of columns
     * @param string $category Category slug (optional)
     */
    private static function render_grid($items, $columns, $category = '') {
        ?>
        <div class="mg-grid-container" style="--mg-grid-columns: <?php echo esc_attr($columns); ?>;">
            <?php foreach ($items as $item): ?>
                <div class="mg-grid-item">
                    <?php self::render_mockup_item($item); ?>
                </div>
            <?php endforeach; ?>
        </div>
        <?php
    }

    /**
     * Render single mockup item
     *
     * @param array $item Mockup item data
     */
    private static function render_mockup_item($item) {
        ?>
        <div class="mg-mockup-card">
            <div class="mg-mockup-image">
                <img src="<?php echo esc_url($item['image_url']); ?>"
                     data-thumb="<?php echo esc_url($item['thumb_url']); ?>"
                     alt="<?php echo esc_attr($item['type_label'] . ' - ' . $item['color_name']); ?>"
                     loading="lazy">
            </div>
            <div class="mg-mockup-info">
                <h4 class="mg-mockup-title"><?php echo esc_html($item['type_label']); ?></h4>
                <p class="mg-mockup-color"><?php echo esc_html($item['color_name']); ?></p>
            </div>
        </div>
        <?php
    }

    /**
     * Get ungrouped mockups (flat array)
     *
     * @param array $showcase Showcase data
     * @return array Mockup items
     */
    private static function get_ungrouped_mockups($showcase) {
        $products = get_option('mg_products', array());
        $items = array();

        if (empty($showcase['mockups'])) {
            return $items;
        }

        foreach ($showcase['mockups'] as $mockup_key => $attachment_id) {
            // Parse mockup key: type_key_color_slug
            $parts = explode('_', $mockup_key);
            $color_slug = array_pop($parts);
            $type_key = implode('_', $parts);

            if (!isset($products[$type_key])) {
                continue;
            }

            $product = $products[$type_key];
            $color_data = MG_Pattern_Showcase_Manager::find_color_data($product, $color_slug);

            $items[] = array(
                'type_key' => $type_key,
                'type_label' => $product['label'],
                'color_slug' => $color_slug,
                'color_name' => $color_data ? $color_data['name'] : $color_slug,
                'attachment_id' => $attachment_id,
                'image_url' => wp_get_attachment_image_url($attachment_id, 'large'),
                'thumb_url' => wp_get_attachment_image_url($attachment_id, 'medium')
            );
        }

        return $items;
    }

    /**
     * Enqueue frontend assets
     */
    public static function enqueue_assets() {
        // Only enqueue if shortcode is present
        global $post;

        if (!is_a($post, 'WP_Post') && !has_shortcode($post->post_content, 'mg_pattern_showcase')) {
            return;
        }

        wp_enqueue_style('mg-pattern-showcase',
            plugins_url('../assets/css/pattern-showcase.css', __FILE__),
            array(),
            '1.3.0'
        );

        wp_enqueue_script('mg-pattern-showcase',
            plugins_url('../assets/js/pattern-showcase.js', __FILE__),
            array('jquery'),
            '1.3.0',
            true
        );

        wp_localize_script('mg-pattern-showcase', 'MG_PATTERN_SHOWCASE', array(
            'strings' => array(
                'prev' => __('Previous', 'mockup-generator'),
                'next' => __('Next', 'mockup-generator'),
            )
        ));
    }

    /**
     * Render Gutenberg block
     *
     * @param array $attributes Block attributes
     * @return string HTML output
     */
    public static function render_block($attributes) {
        $showcase_id = !empty($attributes['showcaseId']) ? $attributes['showcaseId'] : '';
        $layout = !empty($attributes['layout']) ? $attributes['layout'] : '';
        $columns = !empty($attributes['columns']) ? $attributes['columns'] : '';

        if (empty($showcase_id)) {
            return '<p>' . __('Please select a Pattern Showcase', 'mockup-generator') . '</p>';
        }

        // Use shortcode handler
        return self::shortcode_handler(array(
            'id' => $showcase_id,
            'layout' => $layout,
            'columns' => $columns
        ));
    }
}
