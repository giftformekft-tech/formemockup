<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Small, optional bookkeeping layer for bulk uploads.
 *
 * Older products do not have this metadata. The maintenance rename tool can
 * therefore also select products by a date range.
 */
if (!class_exists('MG_Bulk_Batch')) {
    class MG_Bulk_Batch {
        const META_BATCH_ID = '_mg_bulk_batch_id';
        const META_BATCH_CREATED_AT = '_mg_bulk_batch_created_at';

        public static function sanitize_batch_id($value) {
            $value = strtolower(sanitize_text_field((string) $value));
            $value = preg_replace('/[^a-z0-9_-]+/', '-', $value);
            $value = trim((string) $value, '-_');
            return substr($value, 0, 80);
        }

        public static function register_product($product_id, $batch_id) {
            $product_id = absint($product_id);
            $batch_id = self::sanitize_batch_id($batch_id);
            if ($product_id <= 0 || $batch_id === '') {
                return false;
            }

            update_post_meta($product_id, self::META_BATCH_ID, $batch_id);
            update_post_meta($product_id, self::META_BATCH_CREATED_AT, time());
            return true;
        }

        public static function get_recent_batches($limit = 50) {
            global $wpdb;

            $limit = max(1, min(100, absint($limit)));
            $sql = $wpdb->prepare(
                "SELECT pm.meta_value AS batch_id,
                        COUNT(DISTINCT pm.post_id) AS product_count,
                        MAX(p.post_date) AS latest_date
                 FROM {$wpdb->postmeta} pm
                 INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
                 WHERE pm.meta_key = %s
                   AND pm.meta_value <> ''
                   AND p.post_type = 'product'
                   AND p.post_status NOT IN ('trash', 'auto-draft')
                 GROUP BY pm.meta_value
                 ORDER BY latest_date DESC
                 LIMIT %d",
                self::META_BATCH_ID,
                $limit
            );

            $rows = $wpdb->get_results($sql, ARRAY_A);
            return is_array($rows) ? $rows : array();
        }

        public static function get_product_ids($batch_id, $limit = 500) {
            $batch_id = self::sanitize_batch_id($batch_id);
            $limit = max(1, min(1000, absint($limit)));
            if ($batch_id === '') {
                return array();
            }

            $ids = get_posts(array(
                'post_type'      => 'product',
                'post_status'    => array('publish', 'draft', 'pending', 'private'),
                'posts_per_page' => $limit,
                'fields'         => 'ids',
                'orderby'        => 'date',
                'order'          => 'DESC',
                'meta_query'     => array(
                    array(
                        'key'     => self::META_BATCH_ID,
                        'value'   => $batch_id,
                        'compare' => '=',
                    ),
                ),
            ));

            return array_values(array_filter(array_map('absint', (array) $ids)));
        }
    }
}
