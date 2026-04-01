<?php
/**
 * Quick writability diagnostic for ER Scanner.
 * Visit: https://glia.ca/2026/extinct/scanner/test.php
 */
header('Content-Type: text/plain; charset=utf-8');

$dir  = __DIR__;
$db   = $dir . '/scanner-species.json';
$sdir = $dir . '/scanned';

echo "ER Scanner — Write Test\n";
echo "========================\n\n";
echo "PHP user:    " . (function_exists('posix_getpwuid') ? (posix_getpwuid(posix_geteuid())['name'] ?? 'unknown') : get_current_user()) . "\n";
echo "PHP version: " . phpversion() . "\n";
echo "Doc root:    " . $dir . "\n\n";

// Test scanner-species.json
echo "── scanner-species.json ──\n";
if (file_exists($db)) {
    echo "  exists:   YES\n";
    echo "  owner:    " . fileowner($db) . "\n";
    echo "  perms:    " . substr(sprintf('%o', fileperms($db)), -4) . "\n";
    echo "  writable: " . (is_writable($db) ? 'YES' : 'NO') . "\n";
} else {
    echo "  exists:   NO — attempting create... ";
    $ok = @file_put_contents($db, '[]');
    echo ($ok !== false ? "OK" : "FAILED") . "\n";
    if ($ok !== false) {
        echo "  perms:    " . substr(sprintf('%o', fileperms($db)), -4) . "\n";
        echo "  writable: " . (is_writable($db) ? 'YES' : 'NO') . "\n";
    }
}

// Test scanned/ directory
echo "\n── scanned/ directory ──\n";
if (is_dir($sdir)) {
    echo "  exists:   YES\n";
    echo "  owner:    " . fileowner($sdir) . "\n";
    echo "  perms:    " . substr(sprintf('%o', fileperms($sdir)), -4) . "\n";
    echo "  writable: " . (is_writable($sdir) ? 'YES' : 'NO') . "\n";
    // test actual file write inside it
    $tf = $sdir . '/.write_test_' . time();
    $tw = @file_put_contents($tf, 'ok');
    echo "  file write test: " . ($tw !== false ? 'OK' : 'FAILED') . "\n";
    if ($tw !== false) @unlink($tf);
} else {
    echo "  exists:   NO — attempting mkdir... ";
    $ok = @mkdir($sdir, 0755, true);
    echo ($ok ? "OK" : "FAILED") . "\n";
    if ($ok) {
        echo "  perms:    " . substr(sprintf('%o', fileperms($sdir)), -4) . "\n";
        echo "  writable: " . (is_writable($sdir) ? 'YES' : 'NO') . "\n";
    }
}

// Test cURL (needed for image downloads)
echo "\n── cURL ──\n";
echo "  available: " . (function_exists('curl_init') ? 'YES' : 'NO') . "\n";

// Summary
echo "\n========================\n";
$dbOk  = file_exists($db) && is_writable($db);
$dirOk = is_dir($sdir) && is_writable($sdir);
if ($dbOk && $dirOk) {
    echo "RESULT: ALL GOOD — server persistence will work.\n";
} else {
    echo "RESULT: PROBLEMS DETECTED\n";
    if (!$dbOk)  echo "  - scanner-species.json not writable\n";
    if (!$dirOk) echo "  - scanned/ dir not writable\n";
    echo "\nIf PHP created the files just now, try reloading this page.\n";
    echo "If still failing, ask your host to ensure the web user\n";
    echo "owns this directory, or upload files via FTP (which often\n";
    echo "sets ownership to match the PHP process).\n";
}
