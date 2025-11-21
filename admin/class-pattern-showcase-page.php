<?php
/**
 * Pattern Showcase Admin Page
 *
 * Admin interface for managing pattern showcases
 *
 * @package MockupGenerator
 * @since 1.3.0
 */

if (!defined('ABSPATH')) {
    exit;
}

class MG_Pattern_Showcase_Page {

    /**
     * Add submenu page
     */
    public static function add_submenu_page() {
        add_submenu_page(
            'mockup-generator',
            __('Pattern Showcases', 'mockup-generator'),
            __('Pattern Showcases', 'mockup-generator'),
            'manage_options',
            'mg-pattern-showcases',
            array(__CLASS__, 'render_page')
        );
    }

    /**
     * Enqueue admin assets
     */
    public static function enqueue_assets($hook) {
        if ($hook !== 'mockup-generator_page_mg-pattern-showcases') {
            return;
        }

        wp_enqueue_media();
        wp_enqueue_style('mg-pattern-showcase-admin', plugins_url('../assets/css/pattern-showcase-admin.css', __FILE__), array(), '1.3.0');
        wp_enqueue_script('mg-pattern-showcase-admin', plugins_url('../assets/js/pattern-showcase-admin.js', __FILE__), array('jquery'), '1.3.0', true);

        wp_localize_script('mg-pattern-showcase-admin', 'MG_PATTERN_SHOWCASE_ADMIN', array(
            'ajax_url' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('mg_pattern_showcase'),
            'strings' => array(
                'confirm_delete' => __('Are you sure you want to delete this showcase? Generated mockups will also be deleted.', 'mockup-generator'),
                'generating' => __('Generating mockups...', 'mockup-generator'),
                'generation_complete' => __('Mockup generation complete!', 'mockup-generator'),
                'generation_error' => __('Error generating mockups. Please try again.', 'mockup-generator'),
                'select_design' => __('Select Design File', 'mockup-generator'),
                'use_this_file' => __('Use This File', 'mockup-generator')
            )
        ));
    }

    /**
     * Handle AJAX requests
     */
    public static function handle_ajax() {
        // Save showcase
        add_action('wp_ajax_mg_save_pattern_showcase', array(__CLASS__, 'ajax_save_showcase'));

        // Delete showcase
        add_action('wp_ajax_mg_delete_pattern_showcase', array(__CLASS__, 'ajax_delete_showcase'));

        // Generate mockups
        add_action('wp_ajax_mg_generate_showcase_mockups', array(__CLASS__, 'ajax_generate_mockups'));

        // Get showcase data
        add_action('wp_ajax_mg_get_pattern_showcase', array(__CLASS__, 'ajax_get_showcase'));
    }

    /**
     * AJAX: Save showcase
     */
    public static function ajax_save_showcase() {
        check_ajax_referer('mg_pattern_showcase', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => __('Insufficient permissions', 'mockup-generator')));
        }

        $data = array(
            'id' => !empty($_POST['id']) ? sanitize_text_field($_POST['id']) : '',
            'name' => !empty($_POST['name']) ? sanitize_text_field($_POST['name']) : '',
            'design_file' => !empty($_POST['design_file']) ? intval($_POST['design_file']) : 0,
            'product_types' => !empty($_POST['product_types']) ? array_map('sanitize_text_field', $_POST['product_types']) : array(),
            'color_strategy' => !empty($_POST['color_strategy']) ? sanitize_text_field($_POST['color_strategy']) : 'first',
            'custom_colors' => !empty($_POST['custom_colors']) ? $_POST['custom_colors'] : array(),
            'layout' => !empty($_POST['layout']) ? sanitize_text_field($_POST['layout']) : 'carousel',
            'columns' => !empty($_POST['columns']) ? intval($_POST['columns']) : 4,
            'group_by_category' => isset($_POST['group_by_category']) ? (bool)$_POST['group_by_category'] : true
        );

        $result = MG_Pattern_Showcase_Manager::save_showcase($data);

        if (is_wp_error($result)) {
            wp_send_json_error(array('message' => $result->get_error_message()));
        }

        wp_send_json_success(array(
            'showcase' => $result,
            'message' => __('Showcase saved successfully!', 'mockup-generator')
        ));
    }

    /**
     * AJAX: Delete showcase
     */
    public static function ajax_delete_showcase() {
        check_ajax_referer('mg_pattern_showcase', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => __('Insufficient permissions', 'mockup-generator')));
        }

        $id = !empty($_POST['id']) ? sanitize_text_field($_POST['id']) : '';

        $result = MG_Pattern_Showcase_Manager::delete_showcase($id);

        if (!$result) {
            wp_send_json_error(array('message' => __('Failed to delete showcase', 'mockup-generator')));
        }

        wp_send_json_success(array('message' => __('Showcase deleted successfully!', 'mockup-generator')));
    }

    /**
     * AJAX: Generate mockups
     */
    public static function ajax_generate_mockups() {
        check_ajax_referer('mg_pattern_showcase', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => __('Insufficient permissions', 'mockup-generator')));
        }

        $id = !empty($_POST['id']) ? sanitize_text_field($_POST['id']) : '';

        $result = MG_Pattern_Showcase_Manager::generate_mockups($id);

        if (is_wp_error($result)) {
            wp_send_json_error(array('message' => $result->get_error_message()));
        }

        wp_send_json_success($result);
    }

    /**
     * AJAX: Get showcase data
     */
    public static function ajax_get_showcase() {
        check_ajax_referer('mg_pattern_showcase', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => __('Insufficient permissions', 'mockup-generator')));
        }

        $id = !empty($_GET['id']) ? sanitize_text_field($_GET['id']) : '';

        $showcase = MG_Pattern_Showcase_Manager::get_showcase($id);

        if (!$showcase) {
            wp_send_json_error(array('message' => __('Showcase not found', 'mockup-generator')));
        }

        wp_send_json_success(array('showcase' => $showcase));
    }

    /**
     * Render admin page
     */
    public static function render_page() {
        $action = isset($_GET['action']) ? sanitize_text_field($_GET['action']) : 'list';
        $showcase_id = isset($_GET['id']) ? sanitize_text_field($_GET['id']) : '';

        ?>
        <div class="wrap mg-pattern-showcase-admin">
            <h1 class="wp-heading-inline"><?php _e('Pattern Showcases', 'mockup-generator'); ?></h1>

            <?php if ($action === 'list'): ?>
                <a href="<?php echo admin_url('admin.php?page=mg-pattern-showcases&action=edit'); ?>" class="page-title-action">
                    <?php _e('Add New', 'mockup-generator'); ?>
                </a>
                <hr class="wp-header-end">
                <?php self::render_list_view(); ?>
            <?php elseif ($action === 'edit'): ?>
                <hr class="wp-header-end">
                <?php self::render_edit_view($showcase_id); ?>
            <?php endif; ?>
        </div>
        <?php
    }

    /**
     * Render list view
     */
    private static function render_list_view() {
        $showcases = MG_Pattern_Showcase_Manager::get_showcases();
        ?>
        <div class="mg-showcase-list">
            <?php if (empty($showcases)): ?>
                <div class="mg-empty-state">
                    <p><?php _e('No pattern showcases yet.', 'mockup-generator'); ?></p>
                    <a href="<?php echo admin_url('admin.php?page=mg-pattern-showcases&action=edit'); ?>" class="button button-primary">
                        <?php _e('Create Your First Showcase', 'mockup-generator'); ?>
                    </a>
                </div>
            <?php else: ?>
                <table class="wp-list-table widefat fixed striped">
                    <thead>
                        <tr>
                            <th><?php _e('Name', 'mockup-generator'); ?></th>
                            <th><?php _e('Design', 'mockup-generator'); ?></th>
                            <th><?php _e('Products', 'mockup-generator'); ?></th>
                            <th><?php _e('Layout', 'mockup-generator'); ?></th>
                            <th><?php _e('Mockups', 'mockup-generator'); ?></th>
                            <th><?php _e('Shortcode', 'mockup-generator'); ?></th>
                            <th><?php _e('Actions', 'mockup-generator'); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($showcases as $showcase): ?>
                            <tr>
                                <td>
                                    <strong><?php echo esc_html($showcase['name']); ?></strong>
                                </td>
                                <td>
                                    <?php if (!empty($showcase['design_file'])): ?>
                                        <?php echo wp_get_attachment_image($showcase['design_file'], array(50, 50)); ?>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php echo count($showcase['product_types']); ?> <?php _e('types', 'mockup-generator'); ?>
                                </td>
                                <td>
                                    <?php echo esc_html(ucfirst($showcase['layout'])); ?>
                                </td>
                                <td>
                                    <?php
                                    $mockup_count = !empty($showcase['mockups']) ? count($showcase['mockups']) : 0;
                                    echo $mockup_count . ' ' . __('generated', 'mockup-generator');
                                    ?>
                                </td>
                                <td>
                                    <code>[mg_pattern_showcase id="<?php echo esc_attr($showcase['id']); ?>"]</code>
                                </td>
                                <td>
                                    <a href="<?php echo admin_url('admin.php?page=mg-pattern-showcases&action=edit&id=' . $showcase['id']); ?>" class="button button-small">
                                        <?php _e('Edit', 'mockup-generator'); ?>
                                    </a>
                                    <button class="button button-small mg-generate-mockups" data-id="<?php echo esc_attr($showcase['id']); ?>">
                                        <?php _e('Generate Mockups', 'mockup-generator'); ?>
                                    </button>
                                    <button class="button button-small button-link-delete mg-delete-showcase" data-id="<?php echo esc_attr($showcase['id']); ?>">
                                        <?php _e('Delete', 'mockup-generator'); ?>
                                    </button>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>
        <?php
    }

    /**
     * Render edit view
     */
    private static function render_edit_view($showcase_id) {
        $showcase = null;
        $is_new = true;

        if (!empty($showcase_id)) {
            $showcase = MG_Pattern_Showcase_Manager::get_showcase($showcase_id);
            $is_new = false;
        }

        // Get all product types
        $products = get_option('mg_products', array());

        ?>
        <div class="mg-showcase-edit">
            <form id="mg-showcase-form" class="mg-showcase-form">
                <input type="hidden" name="id" value="<?php echo $showcase ? esc_attr($showcase['id']) : ''; ?>">

                <table class="form-table">
                    <tr>
                        <th scope="row">
                            <label for="showcase_name"><?php _e('Showcase Name', 'mockup-generator'); ?> <span class="required">*</span></label>
                        </th>
                        <td>
                            <input type="text" id="showcase_name" name="name" class="regular-text"
                                   value="<?php echo $showcase ? esc_attr($showcase['name']) : ''; ?>" required>
                            <p class="description"><?php _e('A descriptive name for this showcase', 'mockup-generator'); ?></p>
                        </td>
                    </tr>

                    <tr>
                        <th scope="row">
                            <label for="showcase_design"><?php _e('Design File', 'mockup-generator'); ?> <span class="required">*</span></label>
                        </th>
                        <td>
                            <div class="mg-design-file-selector">
                                <input type="hidden" id="showcase_design" name="design_file"
                                       value="<?php echo $showcase && isset($showcase['design_file']) ? esc_attr($showcase['design_file']) : ''; ?>">
                                <button type="button" class="button mg-select-design">
                                    <?php _e('Select Design File', 'mockup-generator'); ?>
                                </button>
                                <div class="mg-design-preview">
                                    <?php if ($showcase && !empty($showcase['design_file'])): ?>
                                        <?php echo wp_get_attachment_image($showcase['design_file'], 'thumbnail'); ?>
                                    <?php endif; ?>
                                </div>
                            </div>
                            <p class="description"><?php _e('The pattern/design to apply to all product mockups', 'mockup-generator'); ?></p>
                        </td>
                    </tr>

                    <tr>
                        <th scope="row">
                            <label><?php _e('Product Types', 'mockup-generator'); ?> <span class="required">*</span></label>
                        </th>
                        <td>
                            <div class="mg-product-types-selector">
                                <?php if (empty($products)): ?>
                                    <p><?php _e('No product types available. Please configure product types first.', 'mockup-generator'); ?></p>
                                <?php else: ?>
                                    <?php foreach ($products as $key => $product): ?>
                                        <label class="mg-checkbox-label">
                                            <input type="checkbox" name="product_types[]" value="<?php echo esc_attr($key); ?>"
                                                   <?php echo ($showcase && in_array($key, $showcase['product_types'])) ? 'checked' : ''; ?>>
                                            <?php echo esc_html($product['label']); ?>
                                        </label>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </div>
                            <p class="description"><?php _e('Select which product types to include in this showcase', 'mockup-generator'); ?></p>
                        </td>
                    </tr>

                    <tr>
                        <th scope="row">
                            <label><?php _e('Color Strategy', 'mockup-generator'); ?></label>
                        </th>
                        <td>
                            <fieldset>
                                <label>
                                    <input type="radio" name="color_strategy" value="first"
                                           <?php echo (!$showcase || $showcase['color_strategy'] === 'first') ? 'checked' : ''; ?>>
                                    <?php _e('First color (default)', 'mockup-generator'); ?>
                                </label><br>
                                <label>
                                    <input type="radio" name="color_strategy" value="custom"
                                           <?php echo ($showcase && $showcase['color_strategy'] === 'custom') ? 'checked' : ''; ?>>
                                    <?php _e('Custom color per product', 'mockup-generator'); ?>
                                </label><br>
                                <label>
                                    <input type="radio" name="color_strategy" value="all"
                                           <?php echo ($showcase && $showcase['color_strategy'] === 'all') ? 'checked' : ''; ?>>
                                    <?php _e('All colors', 'mockup-generator'); ?>
                                </label>
                            </fieldset>
                            <p class="description"><?php _e('How to select colors for each product type', 'mockup-generator'); ?></p>

                            <div id="mg-custom-colors-container" style="display: none; margin-top: 20px;">
                                <h4><?php _e('Custom Colors', 'mockup-generator'); ?></h4>
                                <div id="mg-custom-colors-list"></div>
                            </div>
                        </td>
                    </tr>

                    <tr>
                        <th scope="row">
                            <label><?php _e('Layout', 'mockup-generator'); ?></label>
                        </th>
                        <td>
                            <fieldset>
                                <label>
                                    <input type="radio" name="layout" value="carousel"
                                           <?php echo (!$showcase || $showcase['layout'] === 'carousel') ? 'checked' : ''; ?>>
                                    <?php _e('Carousel (swipeable)', 'mockup-generator'); ?>
                                </label><br>
                                <label>
                                    <input type="radio" name="layout" value="grid"
                                           <?php echo ($showcase && $showcase['layout'] === 'grid') ? 'checked' : ''; ?>>
                                    <?php _e('Grid', 'mockup-generator'); ?>
                                </label>
                            </fieldset>
                        </td>
                    </tr>

                    <tr id="mg-columns-row" style="<?php echo ($showcase && $showcase['layout'] === 'grid') ? '' : 'display: none;'; ?>">
                        <th scope="row">
                            <label for="showcase_columns"><?php _e('Grid Columns', 'mockup-generator'); ?></label>
                        </th>
                        <td>
                            <input type="number" id="showcase_columns" name="columns" min="2" max="6"
                                   value="<?php echo $showcase ? esc_attr($showcase['columns']) : '4'; ?>">
                            <p class="description"><?php _e('Number of columns in grid layout', 'mockup-generator'); ?></p>
                        </td>
                    </tr>

                    <tr>
                        <th scope="row">
                            <label for="group_by_category"><?php _e('Group by Category', 'mockup-generator'); ?></label>
                        </th>
                        <td>
                            <label>
                                <input type="checkbox" id="group_by_category" name="group_by_category" value="1"
                                       <?php echo (!$showcase || $showcase['group_by_category']) ? 'checked' : ''; ?>>
                                <?php _e('Group products by category (Men/Women)', 'mockup-generator'); ?>
                            </label>
                            <p class="description"><?php _e('Display products in separate sections based on category', 'mockup-generator'); ?></p>
                        </td>
                    </tr>
                </table>

                <p class="submit">
                    <button type="submit" class="button button-primary">
                        <?php echo $is_new ? __('Create Showcase', 'mockup-generator') : __('Update Showcase', 'mockup-generator'); ?>
                    </button>
                    <a href="<?php echo admin_url('admin.php?page=mg-pattern-showcases'); ?>" class="button">
                        <?php _e('Cancel', 'mockup-generator'); ?>
                    </a>
                    <?php if (!$is_new): ?>
                        <button type="button" class="button mg-generate-mockups-single" data-id="<?php echo esc_attr($showcase['id']); ?>">
                            <?php _e('Generate Mockups Now', 'mockup-generator'); ?>
                        </button>
                    <?php endif; ?>
                </p>
            </form>

            <?php if (!$is_new && !empty($showcase['mockups'])): ?>
                <div class="mg-mockup-preview">
                    <h2><?php _e('Generated Mockups', 'mockup-generator'); ?></h2>
                    <div class="mg-mockup-grid">
                        <?php foreach ($showcase['mockups'] as $mockup_key => $attachment_id): ?>
                            <div class="mg-mockup-item">
                                <?php echo wp_get_attachment_image($attachment_id, 'medium'); ?>
                                <p><?php echo esc_html($mockup_key); ?></p>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            <?php endif; ?>
        </div>

        <script type="text/javascript">
            jQuery(document).ready(function($) {
                var products = <?php echo json_encode($products); ?>;
            });
        </script>
        <?php
    }
}
