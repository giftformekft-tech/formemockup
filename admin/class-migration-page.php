<?php
/**
 * Admin Page: Migrate to Global Config
 * 
 * This admin page displays the current mg_products data and provides
 * a button to migrate it to the global-attributes.php file.
 * 
 * Access: WordPress Admin > Tools > Migrate to Global Config
 */

if (!defined('ABSPATH')) {
    exit;
}

class MG_Migration_Admin_Page {
    public static function init() {
        add_action('admin_menu', array(__CLASS__, 'add_menu_page'));
        add_action('admin_post_mg_migrate_to_global', array(__CLASS__, 'handle_migration'));
    }

    public static function init_auto_migration() {
        // Real-time update when option changes
        add_action('updated_option_mg_products', array(__CLASS__, 'run_auto_migration'), 10, 3);
        // Check consistency on admin init (e.g. login/plugin load in admin)
        add_action('admin_init', array(__CLASS__, 'check_consistency'), 20);
    }

    public static function check_consistency() {
        // Optimized: Only run on our own plugin pages to avoid overhead on dashboard/other plugins
        $page = isset($_GET['page']) ? sanitize_key($_GET['page']) : '';
        if (strpos($page, 'mockup-generator') === false && $page !== 'mg-migration') {
            return;
        }

        // Only run if we have data to sync
        $products = get_option('mg_products', array());
        if (empty($products)) {
            return;
        }
        // Force a check/sync
        self::run_auto_migration(null, $products, 'mg_products');
    }

    public static function run_auto_migration($old_value, $value, $option) {
        // Ensure we have the logic available
        if (!is_array($value)) {
            return;
        }

        $content = self::format_migration_output($value);
        
        // Determine path safely
        $plugin_root = dirname(dirname(__FILE__));
        $file_path = $plugin_root . '/includes/config/global-attributes.php';
        
        // Check if update is needed
        if (file_exists($file_path)) {
            $current_content = file_get_contents($file_path);
            // Ignore whitespace differences in generated code
            if (trim($current_content) === trim($content)) {
                return;
            }
        }
        
        // Try to write
        if (is_writable(dirname($file_path)) || (file_exists($file_path) && is_writable($file_path))) {
            file_put_contents($file_path, $content);
        }
    }

    public static function add_menu_page() {
        add_management_page(
            'Migrate to Global Config',
            'MG Migration',
            'manage_options',
            'mg-migration',
            array(__CLASS__, 'render_page')
        );
    }

    public static function render_page() {
        if (!current_user_can('manage_options')) {
            wp_die('Unauthorized access');
        }

        $mg_products = get_option('mg_products', array());
        
        // FIX: Use __FILE__ based path to work with any plugin folder name
        $plugin_root = dirname(dirname(__FILE__));  // Go up from admin/ to plugin root
        $global_config_path = $plugin_root . '/includes/config/global-attributes.php';
        
        $global_config_exists = file_exists($global_config_path);
        $global_config_writable = is_writable($global_config_path) || is_writable(dirname($global_config_path));

        ?>
        <div class="wrap">
            <h1>Migrate mg_products to Global Config</h1>
            
            <?php if (isset($_GET['migrated']) && $_GET['migrated'] === 'success'): ?>
                <div class="notice notice-success">
                    <p><strong>Migration successful!</strong> The data has been written to global-attributes.php</p>
                </div>
            <?php endif; ?>

            <?php if (isset($_GET['error'])): ?>
                <div class="notice notice-error">
                    <p><strong>Error:</strong> <?php echo esc_html($_GET['error']); ?></p>
                </div>
            <?php endif; ?>

            <div class="card">
                <h2>Current Database Data</h2>
                <p>Found <strong><?php echo count($mg_products); ?></strong> product type(s) in <code>wp_options.mg_products</code></p>
                
                <?php if (!empty($mg_products)): ?>
                    <table class="wp-list-table widefat fixed striped">
                        <thead>
                            <tr>
                                <th>Product Type</th>
                                <th>Label</th>
                                <th>Colors</th>
                                <th>Sizes</th>
                                <th>Price</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($mg_products as $product): ?>
                                <?php
                                $key = isset($product['key']) ? $product['key'] : '—';
                                $label = isset($product['label']) ? $product['label'] : '—';
                                $color_count = isset($product['colors']) && is_array($product['colors']) ? count($product['colors']) : 0;
                                $size_count = isset($product['sizes']) && is_array($product['sizes']) ? count($product['sizes']) : 0;
                                $price = isset($product['price']) ? $product['price'] : 0;
                                ?>
                                <tr>
                                    <td><code><?php echo esc_html($key); ?></code></td>
                                    <td><?php echo esc_html($label); ?></td>
                                    <td><?php echo $color_count; ?> colors</td>
                                    <td><?php echo $size_count; ?> sizes</td>
                                    <td><?php echo number_format($price, 0, ',', ' '); ?> Ft</td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php else: ?>
                    <p><em>No data found in the database.</em></p>
                <?php endif; ?>
            </div>

            <div class="card" style="margin-top: 20px;">
                <h2>Global Config File</h2>
                <p><code><?php echo esc_html($global_config_path); ?></code></p>
                <p>
                    Status: 
                    <?php if (!$global_config_exists): ?>
                        <span style="color: red;">❌ File not found</span>
                    <?php elseif (!$global_config_writable): ?>
                        <span style="color: orange;">⚠️ File not writable</span>
                    <?php else: ?>
                        <span style="color: green;">✅ File exists and is writable</span>
                    <?php endif; ?>
                </p>
            </div>

            <?php if (!empty($mg_products)): ?>
                <div style="margin-top: 30px;">
                    <h2>Migration Options</h2>
                    
                    <?php if ($global_config_writable): ?>
                        <form method="post" action="<?php echo admin_url('admin-post.php'); ?>" onsubmit="return confirm('Are you sure you want to migrate the data? This will overwrite global-attributes.php');">
                            <?php wp_nonce_field('mg_migrate_to_global'); ?>
                            <input type="hidden" name="action" value="mg_migrate_to_global">
                            <p>
                                <button type="submit" class="button button-primary button-large">
                                    🔄 Migrate to Global Config
                                </button>
                            </p>
                            <p class="description">
                                This will automatically write the data to <code>global-attributes.php</code>.
                            </p>
                        </form>
                    <?php else: ?>
                        <p style="color: orange;">
                            ⚠️ Automatic migration not available. Please copy the data manually:
                        </p>
                        <button type="button" class="button" onclick="document.getElementById('manual-output').style.display='block'">
                            Show Manual Migration Code
                        </button>
                        <div id="manual-output" style="display:none; margin-top: 20px;">
                            <p>Copy this content to <code>includes/config/global-attributes.php</code>:</p>
                            <textarea readonly style="width: 100%; height: 400px; font-family: monospace; font-size: 12px;"><?php
                                echo self::format_migration_output($mg_products);
                            ?></textarea>
                        </div>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
        </div>
        <?php
    }

    public static function handle_migration() {
        check_admin_referer('mg_migrate_to_global');

        if (!current_user_can('manage_options')) {
            wp_die('Unauthorized');
        }

        $mg_products = get_option('mg_products', array());
        if (empty($mg_products)) {
            wp_redirect(admin_url('tools.php?page=mg-migration&error=' . urlencode('No data to migrate')));
            exit;
        }

        // FIX: Use __FILE__ based path
        $plugin_root = dirname(dirname(__FILE__));
        $global_config_path = $plugin_root . '/includes/config/global-attributes.php';
        
        $content = self::format_migration_output($mg_products);

        $result = file_put_contents($global_config_path, $content);

        if ($result === false) {
            wp_redirect(admin_url('tools.php?page=mg-migration&error=' . urlencode('Failed to write file')));
            exit;
        }

        wp_redirect(admin_url('tools.php?page=mg-migration&migrated=success'));
        exit;
    }

    public static function format_migration_output($mg_products) {
        $output = "<?php\n";
        $output .= "/**\n";
        $output .= " * Global attributes source of truth.\n";
        $output .= " *\n";
        $output .= " * IMPORTANT:\n";
        $output .= " * - This file contains all product types, colors, sizes, and pricing.\n";
        $output .= " * - All generated WooCommerce products use these same configurations.\n";
        $output .= " * - Migrated from database on: " . date('Y-m-d H:i:s') . "\n";
        $output .= " */\n\n";
        $output .= "return array(\n";
        $output .= "    'products' => " . var_export($mg_products, true) . ",\n";
        $output .= ");\n";

        return $output;
    }
}

MG_Migration_Admin_Page::init();
