<?php
if (!defined('ABSPATH')) exit;

class MG_Supplier_Export {

    /** Rejtett admin oldal, ahol a levonás előtti előnézet megjelenik. */
    const PREVIEW_SLUG = 'mg-nagyker-preview';

    /** Az előnézetre átadott rendeléslista transient előtagja. */
    const PREVIEW_TRANSIENT = 'mg_nagyker_preview_';

    public static function init() {
        // Register custom order status
        add_action('init', [self::class, 'register_order_status']);
        add_filter('wc_order_statuses', [self::class, 'add_to_status_list']);

        // Legacy (Post type) Orders
        add_filter('bulk_actions-edit-shop_order', [self::class, 'register_bulk_action'], 99);
        add_filter('handle_bulk_actions-edit-shop_order', [self::class, 'handle_bulk_action'], 10, 3);

        // HPOS Orders
        add_filter('bulk_actions-woocommerce_page_wc-orders', [self::class, 'register_bulk_action'], 99);
        add_filter('handle_bulk_actions-woocommerce_page_wc-orders', [self::class, 'handle_bulk_action'], 10, 3);

        // Error notices for export
        add_action('admin_notices', [self::class, 'show_export_notices']);

        // Fallback Javascript injection for aggressive themes/plugins
        add_action('admin_footer', [self::class, 'inject_bulk_action_js'], 999);

        // Levonás előtti megerősítő képernyő (rejtett oldal + POST kezelő).
        // A menüpontot a regisztráció után elrejtjük: az oldal csak a bulk
        // actionből, tokennel érhető el, a sávban nincs helye.
        add_action('admin_menu', [self::class, 'register_preview_page']);
        add_action('admin_menu', [self::class, 'hide_preview_page'], 1000);
        add_action('admin_post_mg_nagyker_export_confirm', [self::class, 'handle_preview_confirm']);
    }

    public static function register_order_status() {
        register_post_status('wc-manufacturing', array(
            'label'                     => 'Gyártás alatt',
            'public'                    => true,
            'exclude_from_search'       => false,
            'show_in_admin_all_list'    => true,
            'show_in_admin_status_list' => true,
            'label_count'               => _n_noop('Gyártás alatt (%s)', 'Gyártás alatt (%s)', 'mockup-generator'),
        ));
    }

    public static function add_to_status_list($statuses) {
        $statuses['wc-manufacturing'] = 'Gyártás alatt';
        return $statuses;
    }

    public static function register_bulk_action($bulk_actions) {
        $bulk_actions['mg_export_supplier_csv'] = __('Nagyker CSV Export (UTT)', 'mgdtp');
        return $bulk_actions;
    }

    public static function inject_bulk_action_js() {
        if (!is_admin()) return;
        $page = isset($_GET['page']) ? sanitize_key($_GET['page']) : '';
        $post_type = isset($_GET['post_type']) ? sanitize_key($_GET['post_type']) : '';

        if ($page !== 'wc-orders' && $post_type !== 'shop_order') {
            return;
        }
        ?>
        <script type="text/javascript">
            jQuery(document).ready(function($) {
                console.log('MG Export JS initialized on wc-orders page');
                var attempts = 0;
                var interval = setInterval(function() {
                    attempts++;
                    var option = '<option value="mg_export_supplier_csv">Nagyker CSV Export (UTT)</option>';
                    var added = false;

                    ['select[name="action"]', 'select[name="action2"]', '#bulk-action-selector-top', '#bulk-action-selector-bottom'].forEach(function(selector) {
                        var $select = $(selector);
                        if ($select.length > 0 && $select.find('option[value="mg_export_supplier_csv"]').length === 0) {
                            $select.append(option);
                            added = true;
                        }
                    });

                    if (added || attempts > 20) { // Stop after 10 seconds (20 * 500ms)
                        clearInterval(interval);
                        console.log('MG Export option added or timed out');
                    }
                }, 500);
            });
        </script>
        <?php
    }

    public static function handle_bulk_action($redirect_to, $action, $post_ids) {
        if ($action !== 'mg_export_supplier_csv') {
            return $redirect_to;
        }

        if (empty($post_ids)) {
            set_transient('mg_export_notice', 'Nem voltak rendelések kiválasztva.', 60);
            return add_query_arg('mg_export_error', 'no_orders', $redirect_to);
        }

        $post_ids = array_values(array_filter(array_map('absint', (array) $post_ids)));

        // Megerősítő előnézet: a levonás csak a jóváhagyás után történik meg.
        if (self::local_stock_active() && MG_Local_Stock::preview_required()) {
            $token = wp_generate_password(20, false, false);
            set_transient(self::PREVIEW_TRANSIENT . $token, array(
                'orders'      => $post_ids,
                'redirect_to' => $redirect_to,
            ), 30 * MINUTE_IN_SECONDS);

            wp_safe_redirect(add_query_arg(
                array('page' => self::PREVIEW_SLUG, 'token' => $token),
                admin_url('admin.php')
            ));
            exit;
        }

        self::run_export($post_ids, $redirect_to);
        return $redirect_to;
    }

    /** A helyi készlet modul betöltődött és be van kapcsolva. */
    private static function local_stock_active() {
        return class_exists('MG_Local_Stock') && MG_Local_Stock::is_enabled();
    }

    /**
     * Összeállítja a nagyker rendelést a kiválasztott rendelésekből.
     *
     * A tételek igényéből először a helyi készlet fogy (ha be van kapcsolva),
     * és csak a maradék kerül a nagyker CSV-be. `$commit === false` esetén
     * semmit nem írunk – ugyanez a számítás fut le szárazon az előnézethez.
     *
     * @param int[] $order_ids
     * @param bool  $commit
     * @return array{lines:array,local:array,missing_sku:array,skipped:array,debug:string[],order_count:int}
     */
    public static function build_plan($order_ids, $commit = false) {
        $debug = array();
        $debug[] = '=== MG Supplier Export Debug ===';
        $debug[] = 'Mode: ' . ($commit ? 'COMMIT' : 'DRY RUN');
        $debug[] = 'Order IDs: ' . implode(', ', $order_ids);

        $use_stock = self::local_stock_active();
        $debug[] = 'Local stock deduction: ' . ($use_stock ? 'ON' : 'OFF');

        $product_lookup = class_exists('MG_Local_Stock')
            ? MG_Local_Stock::product_lookup()
            : self::legacy_product_lookup();
        $debug[] = 'Product types with UTT: ' . implode(', ', array_keys($product_lookup));

        $lines = array();
        $local = array();
        $missing_sku = array();
        $skipped = array();
        // Szárazon futtatva egy cellából többször is vennénk: itt tartjuk
        // számon, mennyit "foglaltunk" már el ebben a körben.
        $reserved = array();

        foreach ($order_ids as $order_id) {
            $order = wc_get_order($order_id);
            if (!$order) {
                $debug[] = "Order #{$order_id}: NOT FOUND";
                continue;
            }

            $debug[] = "Order #{$order_id}: " . count($order->get_items()) . " items";

            foreach ($order->get_items() as $item_id => $item) {
                if (!$item instanceof WC_Order_Item_Product) {
                    continue;
                }

                $qty = $item->get_quantity();
                if ($qty <= 0) {
                    continue;
                }

                $variant = class_exists('MG_Local_Stock')
                    ? MG_Local_Stock::describe_item($item)
                    : self::legacy_describe_item($item);

                $product_type = $variant['type'];
                $color_slug = $variant['color'];
                $size_val = $variant['size'];

                $debug[] = "  Item #{$item_id} ({$item->get_name()}) qty={$qty} => type={$product_type} color={$color_slug} size={$size_val}";

                if ($product_type === '' || $color_slug === '' || $size_val === '') {
                    $debug[] = "    SKIPPED: missing type/color/size";
                    $skipped[] = array(
                        'order_id' => $order_id,
                        'item_id'  => $item_id,
                        'name'     => $item->get_name(),
                        'qty'      => $qty,
                    );
                    continue;
                }

                $variant_key = $product_type . '|' . $color_slug . '|' . $size_val;

                // Amit egy korábbi exportkor már levontunk erre a tételre,
                // azt nem vonjuk le újra és nem is rendeljük meg újra.
                $already = class_exists('MG_Local_Stock')
                    ? (int) $item->get_meta(MG_Local_Stock::ITEM_META_TAKEN)
                    : 0;
                if ($already < 0) {
                    $already = 0;
                }
                if ($already > $qty) {
                    $already = $qty;
                }
                $need = $qty - $already;
                $taken = 0;

                if ($use_stock && $need > 0) {
                    if ($commit) {
                        $taken = MG_Local_Stock::take($product_type, $color_slug, $size_val, $need, array(
                            'reason'        => 'order',
                            'order_id'      => $order_id,
                            'order_item_id' => $item_id,
                            'note'          => 'Nagyker export',
                        ));
                        if ($taken > 0) {
                            $item->update_meta_data(MG_Local_Stock::ITEM_META_TAKEN, $already + $taken);
                            $item->save_meta_data();
                        }
                    } else {
                        $free = MG_Local_Stock::available($product_type, $color_slug, $size_val);
                        $free -= isset($reserved[$variant_key]) ? $reserved[$variant_key] : 0;
                        $taken = max(0, min($need, $free));
                        if ($taken > 0) {
                            $reserved[$variant_key] = (isset($reserved[$variant_key]) ? $reserved[$variant_key] : 0) + $taken;
                        }
                    }
                }

                $to_order = $need - $taken;
                $debug[] = "    need={$need} (already covered {$already}) local={$taken} to_order={$to_order}";

                if ($taken > 0) {
                    if (!isset($local[$variant_key])) {
                        $local[$variant_key] = array(
                            'type'  => $product_type,
                            'color' => $color_slug,
                            'size'  => $size_val,
                            'qty'   => 0,
                        );
                    }
                    $local[$variant_key]['qty'] += $taken;
                }

                $base_sku = isset($product_lookup[$product_type]['utt_skus'][$color_slug])
                    ? trim((string) $product_lookup[$product_type]['utt_skus'][$color_slug])
                    : '';

                if ($base_sku === '') {
                    $debug[] = "    NO UTT SKU for [{$product_type}][{$color_slug}] – not orderable";
                    if ($to_order > 0) {
                        if (!isset($missing_sku[$variant_key])) {
                            $missing_sku[$variant_key] = array(
                                'type'  => $product_type,
                                'color' => $color_slug,
                                'size'  => $size_val,
                                'qty'   => 0,
                            );
                        }
                        $missing_sku[$variant_key]['qty'] += $to_order;
                    }
                    continue;
                }

                $final_sku = $base_sku . '-' . $size_val;
                if (!isset($lines[$final_sku])) {
                    $lines[$final_sku] = array(
                        'sku'   => $final_sku,
                        'need'  => 0,
                        'local' => 0,
                        'order' => 0,
                    );
                }
                $lines[$final_sku]['need'] += $need;
                $lines[$final_sku]['local'] += $taken;
                $lines[$final_sku]['order'] += $to_order;
            }
        }

        // Amit teljes egészében a helyi készlet fedez, azt nem rendeljük meg.
        $lines = array_filter($lines, static function ($line) {
            return $line['order'] > 0;
        });
        ksort($lines);

        $debug[] = 'Order lines: ' . count($lines);
        $debug[] = 'Covered from local stock: ' . count($local) . ' variant(s)';

        return array(
            'lines'       => $lines,
            'local'       => $local,
            'missing_sku' => $missing_sku,
            'skipped'     => $skipped,
            'debug'       => $debug,
            'order_count' => count($order_ids),
        );
    }

    /**
     * Végrehajtja az exportot: levonja a helyi készletet, státuszt vált és
     * letölti a CSV-t. A metódus letöltéssel vagy átirányítással zárul.
     *
     * @param int[]  $order_ids
     * @param string $redirect_to Ide megy vissza, ha nincs mit letölteni.
     */
    public static function run_export($order_ids, $redirect_to = '') {
        $plan = self::build_plan($order_ids, true);
        self::write_debug_log($plan['debug']);

        if (empty($plan['lines']) && empty($plan['local'])) {
            $upload_dir = wp_upload_dir();
            set_transient('mg_export_notice', 'Nem találtam exportálható tételt. Ellenőrizd, hogy az UTT cikkszámok be vannak-e állítva a terméktípusoknál, és a rendelések tartalmazzák-e a szükséges metaadatokat (típus, szín, méret). Debug log: ' . $upload_dir['baseurl'] . '/mg-supplier-export-debug.log', 120);
            if ($redirect_to !== '') {
                wp_safe_redirect(add_query_arg('mg_export_error', 'no_skus', $redirect_to));
                exit;
            }
            return;
        }

        self::mark_orders_manufacturing($order_ids);

        // Minden tételt fedez a helyi készlet – nincs mit megrendelni.
        if (empty($plan['lines'])) {
            $covered = 0;
            foreach ($plan['local'] as $row) {
                $covered += $row['qty'];
            }
            set_transient('mg_export_notice_success', sprintf(
                'Minden tétel fedezve a helyi készletből (%d db), nincs nagyker rendelnivaló. A rendelések „Gyártás alatt” státuszba kerültek.',
                $covered
            ), 120);
            $target = $redirect_to !== '' ? $redirect_to : admin_url('admin.php?page=wc-orders');
            wp_safe_redirect($target);
            exit;
        }

        $items = array();
        foreach ($plan['lines'] as $line) {
            $items[] = array($line['sku'], $line['order']);
        }

        $chunks = array_chunk($items, 100);

        // Clear any output buffers
        while (ob_get_level() > 0) {
            ob_end_clean();
        }

        if (count($chunks) === 1) {
            $filename = 'nagyker-rendeles-' . gmdate('Y-m-d-H-i-s') . '.csv';
            self::download_csv($filename, $chunks[0]);
        } else {
            $zip_filename = 'nagyker-rendelesek-' . gmdate('Y-m-d-H-i-s') . '.zip';
            self::download_zip($zip_filename, $chunks);
        }
        exit;
    }

    /**
     * Rendelések „Gyártás alatt” státuszba léptetése, agresszív cache
     * ürítés nélkül (a tömeges státuszváltás crawler-vihart okozna).
     *
     * @param int[] $order_ids
     */
    private static function mark_orders_manufacturing($order_ids) {
        if (!defined('LITESPEED_DISABLE_ALL')) {
            define('LITESPEED_DISABLE_ALL', true);
        }

        if (class_exists('LiteSpeed\Purge')) {
            remove_action('woocommerce_order_status_changed', 'LiteSpeed\Purge::purge_woo');
        }

        foreach ($order_ids as $order_id) {
            $order = wc_get_order($order_id);
            if ($order) {
                // By using third param true we prevent certain aggressive webhooks
                $order->update_status('manufacturing', 'Rendelés exportálva a Nagyker CSV-be.', true);
            }
        }
    }

    private static function write_debug_log($debug) {
        $upload_dir = wp_upload_dir();
        if (empty($upload_dir['basedir'])) {
            return;
        }
        file_put_contents($upload_dir['basedir'] . '/mg-supplier-export-debug.log', implode("\n", $debug));
    }

    /* -------------------------------------------------------------- preview */

    public static function register_preview_page() {
        add_submenu_page(
            'mockup-generator',
            __('Nagyker rendelés előnézet', 'mockup-generator'),
            __('Nagyker rendelés előnézet', 'mockup-generator'),
            'manage_woocommerce',
            self::PREVIEW_SLUG,
            array(self::class, 'render_preview_page')
        );
    }

    /** Az oldal regisztrálva marad, csak a sidebarból tűnik el. */
    public static function hide_preview_page() {
        remove_submenu_page('mockup-generator', self::PREVIEW_SLUG);
    }

    public static function render_preview_page() {
        if (!current_user_can('manage_woocommerce')) {
            wp_die(esc_html__('Nincs jogosultságod a nagyker exporthoz.', 'mockup-generator'));
        }

        $token = isset($_GET['token']) ? sanitize_text_field(wp_unslash($_GET['token'])) : '';
        $payload = $token !== '' ? get_transient(self::PREVIEW_TRANSIENT . $token) : false;

        echo '<div class="wrap mg-nagyker-preview">';
        echo '<h1>' . esc_html__('Nagyker rendelés előnézet', 'mockup-generator') . '</h1>';

        if (!is_array($payload) || empty($payload['orders'])) {
            echo '<div class="notice notice-error"><p>' . esc_html__('Az előnézet lejárt vagy érvénytelen. Indítsd újra az exportot a rendelések listájáról.', 'mockup-generator') . '</p></div>';
            echo '<p><a class="button" href="' . esc_url(admin_url('admin.php?page=wc-orders')) . '">' . esc_html__('Vissza a rendelésekhez', 'mockup-generator') . '</a></p>';
            echo '</div>';
            return;
        }

        $order_ids = array_map('absint', (array) $payload['orders']);
        $plan = self::build_plan($order_ids, false);

        $types = class_exists('MG_Local_Stock') ? MG_Local_Stock::get_types() : array();

        $order_total = 0;
        foreach ($plan['lines'] as $line) {
            $order_total += $line['order'];
        }
        $local_total = 0;
        foreach ($plan['local'] as $row) {
            $local_total += $row['qty'];
        }

        echo '<p>' . sprintf(
            /* translators: 1: order count, 2: pieces to order, 3: pieces covered locally */
            esc_html__('%1$d rendelés feldolgozva: %2$d db megrendelendő, %3$d db fedezve a helyi készletből.', 'mockup-generator'),
            count($order_ids),
            $order_total,
            $local_total
        ) . '</p>';
        echo '<p class="description">' . esc_html__('A helyi készlet levonása és a rendelések „Gyártás alatt” státuszba léptetése csak a megerősítés után történik meg.', 'mockup-generator') . '</p>';

        // Megrendelendő sorok
        echo '<h2>' . esc_html__('Nagyker rendelés (CSV tartalma)', 'mockup-generator') . '</h2>';
        if (empty($plan['lines'])) {
            echo '<div class="notice notice-success inline"><p>' . esc_html__('Nincs megrendelendő tétel – mindent fedez a helyi készlet.', 'mockup-generator') . '</p></div>';
        } else {
            echo '<table class="widefat striped"><thead><tr>';
            echo '<th>' . esc_html__('Cikkszám', 'mockup-generator') . '</th>';
            echo '<th class="mg-num">' . esc_html__('Igény', 'mockup-generator') . '</th>';
            echo '<th class="mg-num">' . esc_html__('Helyi készletből', 'mockup-generator') . '</th>';
            echo '<th class="mg-num">' . esc_html__('Rendelendő', 'mockup-generator') . '</th>';
            echo '</tr></thead><tbody>';
            foreach ($plan['lines'] as $line) {
                echo '<tr>';
                echo '<td><code>' . esc_html($line['sku']) . '</code></td>';
                echo '<td class="mg-num">' . esc_html($line['need']) . '</td>';
                echo '<td class="mg-num">' . esc_html($line['local']) . '</td>';
                echo '<td class="mg-num"><strong>' . esc_html($line['order']) . '</strong></td>';
                echo '</tr>';
            }
            echo '</tbody></table>';
        }

        // Helyi készletből fedezett sorok
        if (!empty($plan['local'])) {
            echo '<h2>' . esc_html__('Helyi készletből levonva', 'mockup-generator') . '</h2>';
            echo '<table class="widefat striped"><thead><tr>';
            echo '<th>' . esc_html__('Terméktípus', 'mockup-generator') . '</th>';
            echo '<th>' . esc_html__('Szín', 'mockup-generator') . '</th>';
            echo '<th>' . esc_html__('Méret', 'mockup-generator') . '</th>';
            echo '<th class="mg-num">' . esc_html__('Levonandó', 'mockup-generator') . '</th>';
            echo '<th class="mg-num">' . esc_html__('Marad', 'mockup-generator') . '</th>';
            echo '</tr></thead><tbody>';
            foreach ($plan['local'] as $row) {
                $available = MG_Local_Stock::available($row['type'], $row['color'], $row['size']);
                echo '<tr>';
                echo '<td>' . esc_html(isset($types[$row['type']]) ? $types[$row['type']] : $row['type']) . '</td>';
                echo '<td>' . esc_html($row['color']) . '</td>';
                echo '<td>' . esc_html(strtoupper($row['size'])) . '</td>';
                echo '<td class="mg-num">' . esc_html($row['qty']) . '</td>';
                echo '<td class="mg-num">' . esc_html(max(0, $available - $row['qty'])) . '</td>';
                echo '</tr>';
            }
            echo '</tbody></table>';
        }

        // Figyelmeztetések
        if (!empty($plan['missing_sku'])) {
            echo '<h2>' . esc_html__('Nem rendelhető (hiányzó UTT cikkszám)', 'mockup-generator') . '</h2>';
            echo '<div class="notice notice-warning inline"><p>' . esc_html__('Ezekhez a variánsokhoz nincs beállítva nagyker cikkszám, ezért nem kerülnek a CSV-be. A helyi készletet viszont fogyasztják, mert fizikailag elmennek a polcról.', 'mockup-generator') . '</p></div>';
            echo '<table class="widefat striped"><thead><tr>';
            echo '<th>' . esc_html__('Terméktípus', 'mockup-generator') . '</th>';
            echo '<th>' . esc_html__('Szín', 'mockup-generator') . '</th>';
            echo '<th>' . esc_html__('Méret', 'mockup-generator') . '</th>';
            echo '<th class="mg-num">' . esc_html__('Darab', 'mockup-generator') . '</th>';
            echo '</tr></thead><tbody>';
            foreach ($plan['missing_sku'] as $row) {
                echo '<tr>';
                echo '<td>' . esc_html(isset($types[$row['type']]) ? $types[$row['type']] : $row['type']) . '</td>';
                echo '<td>' . esc_html($row['color']) . '</td>';
                echo '<td>' . esc_html(strtoupper($row['size'])) . '</td>';
                echo '<td class="mg-num">' . esc_html($row['qty']) . '</td>';
                echo '</tr>';
            }
            echo '</tbody></table>';
        }

        if (!empty($plan['skipped'])) {
            echo '<h2>' . esc_html__('Kihagyott tételek', 'mockup-generator') . '</h2>';
            echo '<div class="notice notice-warning inline"><p>' . esc_html__('Ezekből a tételekből nem sikerült kiolvasni a terméktípust, színt vagy méretet.', 'mockup-generator') . '</p></div>';
            echo '<table class="widefat striped"><thead><tr>';
            echo '<th>' . esc_html__('Rendelés', 'mockup-generator') . '</th>';
            echo '<th>' . esc_html__('Tétel', 'mockup-generator') . '</th>';
            echo '<th class="mg-num">' . esc_html__('Darab', 'mockup-generator') . '</th>';
            echo '</tr></thead><tbody>';
            foreach ($plan['skipped'] as $row) {
                echo '<tr>';
                echo '<td>#' . esc_html($row['order_id']) . '</td>';
                echo '<td>' . esc_html($row['name']) . '</td>';
                echo '<td class="mg-num">' . esc_html($row['qty']) . '</td>';
                echo '</tr>';
            }
            echo '</tbody></table>';
        }

        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" class="mg-nagyker-preview__actions">';
        wp_nonce_field('mg_nagyker_export_confirm_' . $token);
        echo '<input type="hidden" name="action" value="mg_nagyker_export_confirm" />';
        echo '<input type="hidden" name="token" value="' . esc_attr($token) . '" />';
        echo '<p class="submit">';
        echo '<button type="submit" class="button button-primary button-hero">' . esc_html__('Megerősítés és letöltés', 'mockup-generator') . '</button> ';
        echo '<a class="button button-hero" href="' . esc_url(admin_url('admin.php?page=wc-orders')) . '">' . esc_html__('Mégse', 'mockup-generator') . '</a>';
        echo '</p>';
        echo '</form>';

        echo '<style>.mg-nagyker-preview table{max-width:900px;margin-bottom:24px}.mg-nagyker-preview .mg-num{text-align:right;width:120px}</style>';
        echo '</div>';
    }

    public static function handle_preview_confirm() {
        if (!current_user_can('manage_woocommerce')) {
            wp_die(esc_html__('Nincs jogosultságod a nagyker exporthoz.', 'mockup-generator'));
        }

        $token = isset($_POST['token']) ? sanitize_text_field(wp_unslash($_POST['token'])) : '';
        check_admin_referer('mg_nagyker_export_confirm_' . $token);

        $payload = $token !== '' ? get_transient(self::PREVIEW_TRANSIENT . $token) : false;
        if (!is_array($payload) || empty($payload['orders'])) {
            set_transient('mg_export_notice', 'Az előnézet lejárt, indítsd újra az exportot.', 60);
            wp_safe_redirect(admin_url('admin.php?page=wc-orders'));
            exit;
        }

        // A token egyszer használatos: a dupla beküldés nem vonhat le kétszer.
        delete_transient(self::PREVIEW_TRANSIENT . $token);

        $redirect_to = !empty($payload['redirect_to']) ? $payload['redirect_to'] : admin_url('admin.php?page=wc-orders');
        self::run_export(array_map('absint', (array) $payload['orders']), $redirect_to);
    }

    public static function show_export_notices() {
        $notice = get_transient('mg_export_notice');
        if ($notice) {
            delete_transient('mg_export_notice');
            echo '<div class="notice notice-error is-dismissible"><p><strong>Nagyker CSV Export:</strong> ' . esc_html($notice) . '</p></div>';
        }

        $success = get_transient('mg_export_notice_success');
        if ($success) {
            delete_transient('mg_export_notice_success');
            echo '<div class="notice notice-success is-dismissible"><p><strong>Nagyker CSV Export:</strong> ' . esc_html($success) . '</p></div>';
        }
    }

    /* ------------------------------------------------------- legacy fallback */

    /**
     * Ha a helyi készlet modul valamiért nem töltődött be, az export a régi
     * úton is működik – csak levonás nélkül.
     */
    private static function legacy_product_lookup() {
        $products = get_option('mg_products', array());
        $lookup = array();
        foreach ((array) $products as $p) {
            if (is_array($p) && !empty($p['key'])) {
                $lookup[$p['key']] = $p;
            }
        }
        return $lookup;
    }

    private static function legacy_describe_item($item) {
        $product_type = $item->get_meta('mg_product_type') ?: $item->get_meta('product_type') ?: $item->get_meta('termektipus') ?: $item->get_meta('pa_termektipus');
        $color_slug = $item->get_meta('mg_color') ?: $item->get_meta('color') ?: $item->get_meta('pa_szin') ?: $item->get_meta('szin');
        $size_val = $item->get_meta('mg_size') ?: $item->get_meta('size') ?: $item->get_meta('pa_meret') ?: $item->get_meta('meret');

        if (empty($product_type) || empty($color_slug) || empty($size_val)) {
            $variation_id = $item->get_variation_id();
            $wc_product = $variation_id > 0 ? wc_get_product($variation_id) : wc_get_product($item->get_product_id());
            if ($wc_product) {
                $attributes = $wc_product->get_attributes();
                if (empty($product_type)) {
                    $product_type = $attributes['pa_termektipus'] ?? ($attributes['pa_product_type'] ?? '');
                }
                if (empty($color_slug)) {
                    $color_slug = $attributes['pa_szin'] ?? ($attributes['pa_color'] ?? '');
                }
                if (empty($size_val)) {
                    $size_val = $attributes['pa_meret'] ?? ($attributes['pa_size'] ?? ($attributes['meret'] ?? ''));
                }
            }
        }

        $size_map = array(
            'xxl' => '2xl', 'xxxl' => '3xl', 'xxxxl' => '4xl',
            '2' => '2a', '4' => '4a', '6' => '6a', '8' => '8a', '10' => '10a', '12' => '12a',
            '3-6-ho' => '3/6m', '6-12-ho' => '6/12m', '12-18-ho' => '12/18m',
        );
        $size_val = sanitize_title($size_val);
        if (isset($size_map[$size_val])) {
            $size_val = $size_map[$size_val];
        }

        return array(
            'type'  => sanitize_title($product_type),
            'color' => sanitize_title($color_slug),
            'size'  => $size_val,
        );
    }

    /* -------------------------------------------------------------- download */

    private static function download_csv($filename, $data) {
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Pragma: no-cache');
        header('Expires: 0');

        $output = fopen('php://output', 'w');
        // No header required
        foreach ($data as $row) {
            fputcsv($output, $row, ',');
        }
        fclose($output);
        exit;
    }

    private static function download_zip($zip_filename, $chunks) {
        $temp_file = tempnam(sys_get_temp_dir(), 'mg_zip_');
        $zip = new ZipArchive();

        if ($zip->open($temp_file, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            wp_die('Nem sikerült létrehozni a ZIP fájlt.');
        }

        $count = 1;
        foreach ($chunks as $chunk) {
            // Generate valid CSV content in memory
            $fd = fopen('php://memory', 'r+');
            foreach ($chunk as $row) {
                fputcsv($fd, $row, ',');
            }
            rewind($fd);
            $csv_content = stream_get_contents($fd);
            fclose($fd);

            $zip->addFromString('export-' . $count . '.csv', $csv_content);
            $count++;
        }

        $zip->close();

        header('Content-Type: application/zip');
        header('Content-Disposition: attachment; filename="' . $zip_filename . '"');
        header('Content-Length: ' . filesize($temp_file));
        header('Pragma: no-cache');
        header('Expires: 0');

        readfile($temp_file);
        unlink($temp_file);
        exit;
    }
}
