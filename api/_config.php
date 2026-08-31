<?php
// Loads secrets from a file kept OUTSIDE the web root, so nothing sensitive
// is ever served or committed. Create it on the host as
// /home3/hevycom/nox_config.php (chmod 600) from config.sample.php.

$candidates = [
    dirname(__DIR__, 3) . '/nox_config.php',   // /home3/hevycom/nox_config.php
    dirname(__DIR__, 2) . '/nox_config.php',
    dirname(__DIR__) . '/../nox_config.php',
];
foreach ($candidates as $path) {
    if (is_readable($path)) { require_once $path; break; }
}

if (!defined('RESEND_API_KEY')) define('RESEND_API_KEY', '');
if (!defined('MAIL_FROM'))      define('MAIL_FROM', '');
if (!defined('MAIL_TO'))        define('MAIL_TO', 'e.pyvovar@gmail.com');

// Writable scratch dir for request logs and throttling.
if (!defined('NOX_DATA_DIR')) define('NOX_DATA_DIR', dirname(__DIR__, 3) . '/nox_data');

function nox_log(string $line): void {
    $dir = NOX_DATA_DIR;
    if (!is_dir($dir)) @mkdir($dir, 0750, true);
    @file_put_contents($dir . '/site.log',
        date('c') . ' ' . $line . "\n", FILE_APPEND | LOCK_EX);
}

// Crude per-IP throttle: at most $max submissions per $windowSec.
// Enough to stop a bot hammering a public form on shared hosting.
function nox_throttle(string $bucket, int $max = 5, int $windowSec = 600): bool {
    $dir = NOX_DATA_DIR . '/throttle';
    if (!is_dir($dir)) @mkdir($dir, 0750, true);
    $ip   = $_SERVER['REMOTE_ADDR'] ?? 'cli';
    $file = $dir . '/' . $bucket . '_' . md5($ip) . '.json';

    $now  = time();
    $hits = [];
    if (is_readable($file)) {
        $hits = json_decode((string)file_get_contents($file), true) ?: [];
    }
    $hits = array_values(array_filter($hits, fn($t) => $t > $now - $windowSec));
    if (count($hits) >= $max) return false;

    $hits[] = $now;
    @file_put_contents($file, json_encode($hits), LOCK_EX);
    return true;
}
