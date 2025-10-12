<?php
if (!defined('ABSPATH')) {
    exit;
}

class MG_Mockup_Maintenance_Page {
    private static $notices = [];

    public static function add_submenu_page() {
        add_submenu_page(
            'mockup-generator',
            __('Mockup karbantartás', 'mgdtp'),
            __('Mockup karbantartás', 'mgdtp'),
            'edit_products',
            'mockup-generator-maintenance',
            [__CLASS__, 'render_page']
        );
    }

    private static function add_notice($message, $type = 'updated') {
        self::$notices[] = ['message' => $message, 'type' => $type];
    }

    private static function render_notices() {
        foreach (self::$notices as $notice) {
            printf(
                '<div class="notice notice-%1$s"><p>%2$s</p></div>',
                esc_attr($notice['type']),
                wp_kses_post($notice['message'])
            );
        }
        self::$notices = [];
    }

    private static function handle_actions() {
        if (empty($_POST['mg_mockup_action'])) {
            return;
        }
        if (!current_user_can('edit_products')) {
            self::add_notice(__('Nincs jogosultság a művelethez.', 'mgdtp'), 'error');
            return;
        }
        if (empty($_POST['mg_mockup_nonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['mg_mockup_nonce'])), 'mg_mockup_maintenance')) {
            self::add_notice(__('Érvénytelen kérés (nonce).', 'mgdtp'), 'error');
            return;
        }
        $action = sanitize_text_field(wp_unslash($_POST['mg_mockup_action']));
        $selected = isset($_POST['mockup_keys']) ? array_map('sanitize_text_field', wp_unslash((array) $_POST['mockup_keys'])) : [];
        if (!empty($_POST['mg_single_key'])) {
            $selected[] = sanitize_text_field(wp_unslash($_POST['mg_single_key']));
        }
        $selected = array_values(array_unique(array_filter($selected)));
        switch ($action) {
            case 'bulk_regenerate':
                if (empty($selected)) {
                    self::add_notice(__('Nem jelöltél ki mockupot.', 'mgdtp'), 'warning');
                    break;
                }
                foreach ($selected as $key) {
                    $parts = explode('|', $key);
                    if (count($parts) !== 3) {
                        continue;
                    }
                    list($product_id, $type_slug, $color_slug) = $parts;
                    MG_Mockup_Maintenance::queue_for_regeneration($product_id, $type_slug, $color_slug, __('Admin újragenerálás kérve.', 'mgdtp'));
                }
                self::add_notice(__('A kijelölt mockupok felkerültek a sorba.', 'mgdtp'));
                break;
            case 'process_queue_now':
                MG_Mockup_Maintenance::process_queue();
                self::add_notice(__('A háttérsor feldolgozását lefuttattuk.', 'mgdtp'));
                break;
            case 'clear_log':
                update_option(MG_Mockup_Maintenance::OPTION_ACTIVITY_LOG, [], false);
                self::add_notice(__('A napló ürítve lett.', 'mgdtp'));
                break;
        }
    }

    private static function status_label($status) {
        $map = [
            'ok'       => __('rendben', 'mgdtp'),
            'pending'  => __('frissítendő', 'mgdtp'),
            'error'    => __('hibás', 'mgdtp'),
            'missing'  => __('hiányzik', 'mgdtp'),
        ];
        return $map[$status] ?? $status;
    }

    private static function status_class($status) {
        $map = [
            'ok'       => 'status-ok',
            'pending'  => 'status-pending',
            'error'    => 'status-error',
            'missing'  => 'status-missing',
        ];
        return $map[$status] ?? 'status-unknown';
    }

    private static function collect_products($entries) {
        $products = [];
        foreach ($entries as $entry) {
            $pid = isset($entry['product_id']) ? (int) $entry['product_id'] : 0;
            if ($pid <= 0 || isset($products[$pid])) {
                continue;
            }
            $name = $pid;
            if (function_exists('wc_get_product')) {
                $product = wc_get_product($pid);
                if ($product) {
                    $name = $product->get_name();
                }
            }
            $products[$pid] = $name;
        }
        asort($products);
        return $products;
    }

    private static function render_filters($active_filters, $entries) {
        $statuses = ['' => __('Összes állapot', 'mgdtp'), 'pending' => __('Frissítendő', 'mgdtp'), 'error' => __('Hibás', 'mgdtp'), 'missing' => __('Hiányzik', 'mgdtp'), 'ok' => __('Rendben', 'mgdtp')];
        $products = self::collect_products($entries);
        $types = ['' => __('Összes variáns', 'mgdtp')];
        foreach ($entries as $entry) {
            if (!empty($entry['type_slug'])) {
                $types[$entry['type_slug']] = $entry['source']['type_label'] ?? $entry['type_slug'];
            }
        }
        asort($types);
        ?>
        <form method="get" class="mg-filters">
            <input type="hidden" name="page" value="mockup-generator-maintenance" />
            <label>
                <span><?php esc_html_e('Állapot', 'mgdtp'); ?></span>
                <select name="mg_status">
                    <?php foreach ($statuses as $value => $label) : ?>
                        <option value="<?php echo esc_attr($value); ?>" <?php selected($active_filters['status'], $value); ?>><?php echo esc_html($label); ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label>
                <span><?php esc_html_e('Termék', 'mgdtp'); ?></span>
                <select name="mg_product">
                    <option value=""><?php esc_html_e('Összes termék', 'mgdtp'); ?></option>
                    <?php foreach ($products as $pid => $label) : ?>
                        <option value="<?php echo esc_attr($pid); ?>" <?php selected($active_filters['product_id'], $pid); ?>><?php echo esc_html($label); ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label>
                <span><?php esc_html_e('Variáns', 'mgdtp'); ?></span>
                <select name="mg_type">
                    <?php foreach ($types as $value => $label) : ?>
                        <option value="<?php echo esc_attr($value); ?>" <?php selected($active_filters['type_slug'], $value); ?>><?php echo esc_html($label); ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label>
                <span><?php esc_html_e('Szín', 'mgdtp'); ?></span>
                <input type="text" name="mg_color" value="<?php echo esc_attr($active_filters['color_slug']); ?>" placeholder="pl. fekete" />
            </label>
            <button type="submit" class="button button-primary"><?php esc_html_e('Szűrés', 'mgdtp'); ?></button>
        </form>
        <?php
    }

    private static function render_summary($entries, $queue) {
        $counts = ['ok' => 0, 'pending' => 0, 'error' => 0, 'missing' => 0];
        foreach ($entries as $entry) {
            $status = $entry['status'] ?? 'ok';
            if (isset($counts[$status])) {
                $counts[$status]++;
            }
        }
        $total = max(1, array_sum($counts));
        $pending = isset($counts['pending']) ? (int) $counts['pending'] : 0;
        $percent = min(100, max(0, round((($total - $pending) / $total) * 100)));
        ?>
        <div class="mg-maintenance-summary">
            <div class="summary-item">
                <strong><?php echo esc_html($counts['pending']); ?></strong>
                <span><?php esc_html_e('Frissítendő mockup', 'mgdtp'); ?></span>
            </div>
            <div class="summary-item">
                <strong><?php echo esc_html(count($queue)); ?></strong>
                <span><?php esc_html_e('Sorban álló feladat', 'mgdtp'); ?></span>
            </div>
            <div class="summary-progress">
                <span><?php esc_html_e('Előrehaladás', 'mgdtp'); ?></span>
                <div class="progress-bar"><span style="width: <?php echo esc_attr($percent); ?>%"></span></div>
            </div>
        </div>
        <?php
    }

    private static function render_table($entries) {
        ?>
        <form method="post" class="mg-maintenance-table">
            <?php wp_nonce_field('mg_mockup_maintenance', 'mg_mockup_nonce'); ?>
            <input type="hidden" name="mg_mockup_action" value="bulk_regenerate" />
            <table class="widefat fixed striped">
                <thead>
                    <tr>
                        <th class="check-column"><input type="checkbox" class="mg-select-all" /></th>
                        <th><?php esc_html_e('Termék', 'mgdtp'); ?></th>
                        <th><?php esc_html_e('Variáns', 'mgdtp'); ?></th>
                        <th><?php esc_html_e('Szín', 'mgdtp'); ?></th>
                        <th><?php esc_html_e('Állapot', 'mgdtp'); ?></th>
                        <th><?php esc_html_e('Utolsó frissítés', 'mgdtp'); ?></th>
                        <th><?php esc_html_e('Megjegyzés', 'mgdtp'); ?></th>
                        <th><?php esc_html_e('Műveletek', 'mgdtp'); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($entries)) : ?>
                        <tr>
                            <td colspan="8" class="no-items"><?php esc_html_e('Nincs találat a megadott szűrőkkel.', 'mgdtp'); ?></td>
                        </tr>
                    <?php else : ?>
                        <?php foreach ($entries as $entry) :
                            $key = $entry['key'];
                            $product_id = (int) ($entry['product_id'] ?? 0);
                            $product_label = $product_id;
                            if ($product_id && function_exists('wc_get_product')) {
                                $product_obj = wc_get_product($product_id);
                                if ($product_obj) {
                                    $product_label = $product_obj->get_name();
                                }
                            }
                            $updated_at = !empty($entry['updated_at']) ? date_i18n(get_option('date_format') . ' ' . get_option('time_format'), $entry['updated_at']) : __('nincs adat', 'mgdtp');
                            $status = $entry['status'] ?? 'ok';
                            $pending_reason = $entry['pending_reason'] ?? '';
                            $message = $entry['last_message'] ?? '';
                            ?>
                            <tr>
                                <th scope="row" class="check-column"><input type="checkbox" name="mockup_keys[]" value="<?php echo esc_attr($key); ?>" /></th>
                                <td>
                                    <?php if ($product_id) : ?>
                                        <a href="<?php echo esc_url(get_edit_post_link($product_id)); ?>" target="_blank" rel="noopener noreferrer">#<?php echo esc_html($product_id); ?></a>
                                        <div class="meta-label"><?php echo esc_html($product_label); ?></div>
                                    <?php else : ?>
                                        <?php echo esc_html($product_label); ?>
                                    <?php endif; ?>
                                </td>
                                <td><?php echo esc_html($entry['source']['type_label'] ?? $entry['type_slug']); ?></td>
                                <td><?php echo esc_html($entry['source']['color_label'] ?? $entry['color_slug']); ?></td>
                                <td><span class="status-pill <?php echo esc_attr(self::status_class($status)); ?>"><?php echo esc_html(self::status_label($status)); ?></span></td>
                                <td><?php echo esc_html($updated_at); ?></td>
                                <td>
                                    <?php if ($status === 'pending' && $pending_reason) : ?>
                                        <div><?php echo esc_html($pending_reason); ?></div>
                                    <?php endif; ?>
                                    <?php if ($message) : ?>
                                        <div class="last-message"><?php echo esc_html($message); ?></div>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <button type="submit" name="mg_single_key" value="<?php echo esc_attr($key); ?>" class="button-link mg-row-action" onclick="this.form.mg_mockup_action.value='bulk_regenerate';"><?php esc_html_e('Újragenerálás', 'mgdtp'); ?></button>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
            <div class="tablenav bottom">
                <div class="alignleft actions bulkactions">
                    <input type="hidden" name="mg_mockup_action" value="bulk_regenerate" />
                    <button type="submit" class="button action"><?php esc_html_e('Újragenerálás kijelöltekkel', 'mgdtp'); ?></button>
                </div>
            </div>
        </form>
        <?php
    }

    private static function render_queue_controls() {
        ?>
        <form method="post" class="mg-queue-controls">
            <?php wp_nonce_field('mg_mockup_maintenance', 'mg_mockup_nonce'); ?>
            <input type="hidden" name="mg_mockup_action" value="process_queue_now" />
            <button type="submit" class="button button-secondary"><?php esc_html_e('Sor feldolgozása most', 'mgdtp'); ?></button>
        </form>
        <?php
    }

    private static function render_activity_log() {
        $log = MG_Mockup_Maintenance::get_activity_log();
        ?>
        <div class="mg-activity-log">
            <h2><?php esc_html_e('Legutóbbi események', 'mgdtp'); ?></h2>
            <form method="post" class="mg-log-clear">
                <?php wp_nonce_field('mg_mockup_maintenance', 'mg_mockup_nonce'); ?>
                <input type="hidden" name="mg_mockup_action" value="clear_log" />
                <button type="submit" class="button button-link-delete"><?php esc_html_e('Napló törlése', 'mgdtp'); ?></button>
            </form>
            <ul>
                <?php if (empty($log)) : ?>
                    <li><?php esc_html_e('Még nincs naplóbejegyzés.', 'mgdtp'); ?></li>
                <?php else : ?>
                    <?php foreach (array_slice($log, 0, 20) as $row) :
                        $time = !empty($row['time']) ? date_i18n(get_option('date_format') . ' ' . get_option('time_format'), $row['time']) : '';
                        $status = !empty($row['status']) ? self::status_label($row['status']) : '';
                        $message = !empty($row['message']) ? $row['message'] : '';
                        ?>
                        <li>
                            <strong><?php echo esc_html($time); ?></strong>
                            <span class="status-pill <?php echo esc_attr(self::status_class($row['status'] ?? '')); ?>"><?php echo esc_html($status); ?></span>
                            <span class="log-message"><?php echo esc_html($message); ?></span>
                        </li>
                    <?php endforeach; ?>
                <?php endif; ?>
            </ul>
        </div>
        <?php
    }

    public static function render_page() {
        if (!class_exists('MG_Mockup_Maintenance')) {
            echo '<div class="notice notice-error"><p>' . esc_html__('Hiányzik a Mockup karbantartó modul.', 'mgdtp') . '</p></div>';
            return;
        }
        self::handle_actions();
        $filters = [
            'status' => isset($_GET['mg_status']) ? sanitize_text_field(wp_unslash($_GET['mg_status'])) : '',
            'product_id' => isset($_GET['mg_product']) ? absint($_GET['mg_product']) : 0,
            'type_slug' => isset($_GET['mg_type']) ? sanitize_text_field(wp_unslash($_GET['mg_type'])) : '',
            'color_slug' => isset($_GET['mg_color']) ? sanitize_title(wp_unslash($_GET['mg_color'])) : '',
        ];
        $entries = MG_Mockup_Maintenance::get_status_entries($filters);
        $queue = MG_Mockup_Maintenance::get_queue();
        ?>
        <div class="wrap mg-mockup-maintenance">
            <h1><?php esc_html_e('Mockup karbantartás', 'mgdtp'); ?></h1>
            <?php self::render_notices(); ?>
            <?php self::render_filters($filters, $entries); ?>
            <?php self::render_summary($entries, $queue); ?>
            <?php self::render_queue_controls(); ?>
            <?php self::render_table($entries); ?>
            <?php self::render_activity_log(); ?>
        </div>
        <?php
    }
}
