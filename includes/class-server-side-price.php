<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Class MG_Server_Side_Price
 * 
 * DISABLED - All backend price override attempts caused critical errors.
 * 
 * Current solution: Rely on Schema.org structured data (class-product-structured-data.php)
 * which already outputs correct variant prices for Google to read.
 */
class MG_Server_Side_Price {

    public static function init() {
        // DO NOTHING - feature disabled
        // The Schema.org structured data already provides correct prices to Google
    }
}
