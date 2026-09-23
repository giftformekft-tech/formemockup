<?php
/** Real PHP child-process fatal/exit checks; no WordPress server or paid API calls. */
if (($argv[1] ?? '') === '--child') {
    define('ABSPATH', __DIR__);
    define('HOUR_IN_SECONDS', 3600);
    $mode = $argv[2];
    $result_file = $argv[3];
    $options = $transients = $logs = array();
    function __($message, $domain = '') { return $message; }
    function snapshot() {
        file_put_contents($GLOBALS['result_file'], json_encode(array(
            'options' => $GLOBALS['options'], 'transients' => $GLOBALS['transients'], 'logs' => $GLOBALS['logs'],
        )));
    }
    function get_transient($key) { return $GLOBALS['transients'][$key] ?? false; }
    function set_transient($key, $value, $ttl) { $GLOBALS['transients'][$key] = $value; snapshot(); }
    function add_option($key, $value, ...$args) { if (isset($GLOBALS['options'][$key])) return false; $GLOBALS['options'][$key] = $value; snapshot(); return true; }
    function delete_option($key) { unset($GLOBALS['options'][$key]); snapshot(); }
    function user_can($user_id, $capability) { return true; }
    function wc_get_logger() { return new class { public function error($message, $context) { $GLOBALS['logs'][] = array($message, $context); snapshot(); } }; }
    class MG_Order_Design_Download { const JOB_TRANSIENT_PREFIX = 'job_'; }
    class MG_AI_SEO_Generator {
        public static function get_settings() {
            if ($GLOBALS['mode'] === 'fatal') trigger_error('Simulated fatal: private details must stay out of diagnostics', E_USER_ERROR);
            if ($GLOBALS['mode'] === 'exit') exit(9);
            throw new RuntimeException('Ordinary caught failure');
        }
    }
    require dirname(__DIR__) . '/includes/class-ai-print-generator.php';
    $task = array('item_id' => 11, 'ai_prompt' => 'private customer value', 'design_path' => 'unused.png');
    $key = MG_AI_Print_Generator::task_key('shutdown-job', $task);
    set_transient('job_shutdown-job', array('tasks' => array($task), 'status' => 'processing', 'user_id' => 7), 3600);
    set_transient(MG_AI_Print_Generator::PREFIX . $key, array('status' => 'queued', 'created' => time(), 'job_id' => 'shutdown-job', 'task' => $task), 3600);
    MG_AI_Print_Generator::run($key);
    exit(0);
}

foreach (array('fatal', 'exit', 'caught') as $mode) {
    $result_file = tempnam(sys_get_temp_dir(), 'mg_shutdown_test_');
    try {
        $process = proc_open(array(PHP_BINARY, '-d', 'display_errors=0', '-d', 'log_errors=0', __FILE__, '--child', $mode, $result_file),
            array(0 => array('pipe', 'r'), 1 => array('pipe', 'w'), 2 => array('pipe', 'w')), $pipes);
        if (!is_resource($process)) throw new RuntimeException('Could not start child PHP process');
        fclose($pipes[0]);
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exit_code = proc_close($process);
        $result = json_decode(file_get_contents($result_file), true);
        $state = $result['transients']['mg_ai_print_' . hash('sha256', 'shutdown-job|11')] ?? array();
        $expected = $mode === 'caught' ? 'generation_error' : 'worker_interrupted';
        if (($mode === 'caught' ? $exit_code !== 0 : $exit_code === 0) || ($state['status'] ?? '') !== 'error'
            || ($state['error_kind'] ?? '') !== $expected || !str_contains($state['message'] ?? '', 'Alapminta előkészítése')
            || !empty($result['options']) || count($result['logs'] ?? array()) !== 1 || isset($state['task'])
            || str_contains($state['message'] ?? '', 'private details')) {
            throw new RuntimeException('Shutdown recovery failed for ' . $mode . ': ' . $stdout . $stderr);
        }
        echo 'ok - ' . $mode . ': persisted error and stage, released lock, one safe log entry' . PHP_EOL;
    } finally {
        if (is_file($result_file)) unlink($result_file);
    }
}
echo "PHP fatal/exit recovery checks passed in real child processes.\n";
