<?php
// Test script for cleanup
$temp_dir = sys_get_temp_dir();
$test_file = $temp_dir . '/magick-test-verify-' . uniqid() . '.tmp';
file_put_contents($test_file, 'dummy content');

echo "Created test file: $test_file\n";
if (file_exists($test_file)) {
    echo "File exists.\n";
} else {
    echo "Error: File not created.\n";
    exit(1);
}

// Simulate cleanup
require_once __DIR__ . '/includes/class-generator.php';
MG_Generator::cleanup_imagick_temp_files();

// Check if deleted
if (!file_exists($test_file)) {
    // Wait, regex was magick-*, my test file is magick-test-verify... 
    // The pattern in code is 'magick-*'.
    // Let's rename test file to match pattern.
}
unlink($test_file); // Clean up my test file if it wasn't deleted (it likely wasn't due to pattern mismatch)

// Real test
$test_file_magick = $temp_dir . '/magick-TEST-' . uniqid();
file_put_contents($test_file_magick, 'dummy content');
echo "Created magick-pattern file: $test_file_magick\n";

MG_Generator::cleanup_imagick_temp_files();

if (!file_exists($test_file_magick)) {
    echo "SUCCESS: Magick file deleted.\n";
} else {
    echo "FAILURE: Magick file still exists.\n";
    unlink($test_file_magick);
}
