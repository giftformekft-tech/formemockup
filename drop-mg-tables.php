<?php
if (!defined('ABSPATH')) {
    require_once(__DIR__ . '/wp-load.php');
}

global $wpdb;
$tables = [
    $wpdb->prefix . 'mg_mockup_index',
    $wpdb->prefix . 'mg_variant_queue'
];

foreach ($tables as $table) {
    echo "Dropping table: $table ... ";
    $wpdb->query("DROP TABLE IF EXISTS $table");
    echo "DONE\n";
}

// Clean up options
delete_option('mg_mockup_status_index');
delete_option('mg_mockup_status_index_meta');
delete_option('mg_variant_sync_queue');
delete_option('mg_variant_sync_queue_meta');
echo "Options cleaned.\n";
