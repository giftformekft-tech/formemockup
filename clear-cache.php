<?php
// Temporary file to clear PHP opcache
// Visit this file once in a browser, then delete it

if (function_exists('opcache_reset')) {
    opcache_reset();
    echo "OPcache cleared successfully!<br>";
} else {
    echo "OPcache is not enabled.<br>";
}

// Also try to clear any WP object cache
if (function_exists('wp_cache_flush')) {
    wp_cache_flush();
    echo "WP Object cache cleared successfully!<br>";
}

echo "<br>Now reload the cart page and check the logs again.";
?>
