<?php
if (!defined('ABSPATH')) exit;

class MG_Maintenance {

    public static function get_mockup_dir() {
        $upload_dir = wp_upload_dir();
        return trailingslashit($upload_dir['basedir']) . 'mockup-generator';
    }

    public static function scan_orphaned_files() {
        $dir = self::get_mockup_dir();
        if (!file_exists($dir)) {
            return array();
        }

        $all_files = array();
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, RecursiveDirectoryIterator::SKIP_DOTS));
        foreach ($iterator as $file) {
            if ($file->isFile()) {
                $all_files[] = $file->getPathname();
            }
        }

        // Get all attached images
        global $wpdb;
        $attached_files = $wpdb->get_col("SELECT meta_value FROM $wpdb->postmeta WHERE meta_key = '_wp_attached_file' AND meta_value LIKE '%mockup-generator%'");
        
        $attached_absolutes = array();
        $upload_dir = wp_upload_dir();
        $basedir = wp_normalize_path($upload_dir['basedir']);
        
        foreach ($attached_files as $rel_path) {
            $attached_absolutes[] = wp_normalize_path($basedir . '/' . $rel_path);
        }

        $orphans = array();
        foreach ($all_files as $file) {
            $norm_file = wp_normalize_path($file);
            if (!in_array($norm_file, $attached_absolutes)) {
                $orphans[] = array(
                    'path' => $file,
                    'name' => basename($file),
                    'size' => size_format(filesize($file)),
                    'date' => date('Y-m-d H:i:s', filemtime($file))
                );
            }
        }

        return $orphans;
    }

    public static function delete_files($files) {
        $count = 0;
        foreach ($files as $file) {
            if (file_exists($file) && is_writable($file)) {
                @unlink($file);
                $count++;
            }
        }
        return $count;
    }
}
