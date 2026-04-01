<?php
/**
 * ER Scanner API
 * ──────────────
 * Single PHP endpoint for the vanilla scanner.
 * Works on any shared hosting with PHP 7.4+.
 *
 * Routes (via ?action= parameter):
 *   POST ?action=save-species     → upsert species in scanner-species.json
 *   POST ?action=download-image   → download Wikimedia image to scanned/
 *   GET  ?action=get-species      → return scanner-species.json contents
 */

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

// Handle CORS preflight
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

// ── Config ────────────────────────────────────────────────────────────────
define('SCANNER_DB',  __DIR__ . '/scanner-species.json');
define('SCANNED_DIR', __DIR__ . '/scanned');

// ── Helpers ───────────────────────────────────────────────────────────────

function json_response($data, $code = 200) {
    http_response_code($code);
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    exit;
}

function read_json_body() {
    $raw = file_get_contents('php://input');
    $data = json_decode($raw, true);
    if (!is_array($data)) {
        json_response(['error' => 'Invalid JSON body'], 400);
    }
    return $data;
}

function load_db() {
    if (!file_exists(SCANNER_DB)) return [];
    $raw = file_get_contents(SCANNER_DB);
    $data = json_decode($raw, true);
    return is_array($data) ? $data : [];
}

function save_db($db) {
    // Atomic-ish write: write to temp, then rename
    $tmp = SCANNER_DB . '.tmp';
    file_put_contents($tmp, json_encode($db, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
    rename($tmp, SCANNER_DB);
}

// ── .env loader ───────────────────────────────────────────────────────────
function load_env() {
    $path = __DIR__ . '/.env';
    if (!file_exists($path)) return [];
    $vars = [];
    foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        $line = trim($line);
        if ($line === '' || $line[0] === '#') continue;
        if (strpos($line, '=') === false) continue;
        [$k, $v] = explode('=', $line, 2);
        $vars[trim($k)] = trim($v);
    }
    return $vars;
}

// ── Routes ────────────────────────────────────────────────────────────────

$action = $_GET['action'] ?? '';

switch ($action) {

    // ── GET: return public config (API keys safe to expose to browser) ──
    case 'config':
        $env = load_env();
        $key = $env['GEMINI_API_KEY'] ?? '';
        if (!$key) {
            json_response(['error' => 'GEMINI_API_KEY not set in .env'], 500);
        }
        json_response(['gemini_api_key' => $key]);
        break;

    // ── GET: return scanner DB contents ──
    case 'get-species':
        json_response(load_db());
        break;

    // ── POST: upsert a species entry ──
    case 'save-species':
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            json_response(['error' => 'POST required'], 405);
        }
        $species = read_json_body();
        $sciName = trim($species['scientific_name'] ?? '');
        if (!$sciName) {
            json_response(['error' => 'scientific_name required'], 400);
        }

        $db = load_db();
        $key = strtolower($sciName);
        $found = false;

        foreach ($db as $i => $entry) {
            if (strtolower(trim($entry['scientific_name'] ?? '')) === $key) {
                $db[$i] = array_merge($entry, $species);
                $found = true;
                break;
            }
        }

        if (!$found) {
            if (!isset($species['scanned_at'])) {
                $species['scanned_at'] = date('c');
            }
            $db[] = $species;
        }

        save_db($db);
        json_response(['ok' => true, 'total' => count($db)]);
        break;

    // ── GET: stream external image through PHP (avoids hotlink 403s) ──
    case 'proxy-image':
        $url = $_GET['url'] ?? '';
        if (!$url || !filter_var($url, FILTER_VALIDATE_URL)) {
            http_response_code(400); echo 'Bad url'; exit;
        }
        // Only allow known open image hosts
        $allowed = ['upload.wikimedia.org', 'inaturalist-open-data.s3.amazonaws.com',
                    'static.inaturalist.org', 'commons.wikimedia.org'];
        $host = parse_url($url, PHP_URL_HOST);
        if (!in_array($host, $allowed, true)) {
            http_response_code(403); echo 'Host not allowed'; exit;
        }

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS      => 5,
            CURLOPT_TIMEOUT        => 15,
            CURLOPT_USERAGENT      => 'ERScanner/1.0 (museum kiosk; contact jhave2@gmail.com)',
            CURLOPT_REFERER        => 'https://en.wikipedia.org/',
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_HEADER         => true,
        ]);
        $response  = curl_exec($ch);
        $httpCode  = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $headerSize= curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        $curlError = curl_error($ch);
        curl_close($ch);

        if ($httpCode !== 200 || !$response) {
            http_response_code(502);
            echo "Upstream {$httpCode}: {$curlError}"; exit;
        }

        $body = substr($response, $headerSize);
        // Sniff MIME from magic bytes
        $magic = substr($body, 0, 4);
        if (substr($magic, 0, 2) === "\xff\xd8") $mime = 'image/jpeg';
        elseif (substr($magic, 0, 4) === "\x89PNG") $mime = 'image/png';
        elseif (substr($magic, 0, 4) === 'GIF8') $mime = 'image/gif';
        elseif (substr($magic, 0, 4) === 'RIFF') $mime = 'image/webp';
        else $mime = 'image/jpeg';

        header("Content-Type: {$mime}");
        header('Cache-Control: public, max-age=604800'); // 1 week
        header('Access-Control-Allow-Origin: *');
        echo $body;
        exit;

    // ── POST: download image from URL to scanned/ ──
    case 'download-image':
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            json_response(['error' => 'POST required'], 405);
        }
        $body = read_json_body();
        $url      = $body['url'] ?? '';
        $filename = $body['filename'] ?? 'image.jpg';

        if (!$url) {
            json_response(['error' => 'url required'], 400);
        }

        // Sanitize filename
        $safe = preg_replace('/[^\w\-.]/', '_', $filename);
        if (!$safe) $safe = 'image.jpg';

        // Ensure scanned/ directory exists
        if (!is_dir(SCANNED_DIR)) {
            mkdir(SCANNED_DIR, 0755, true);
        }

        $dest = SCANNED_DIR . '/' . $safe;

        // Skip if already downloaded
        if (file_exists($dest) && filesize($dest) > 0) {
            json_response(['path' => 'scanned/' . $safe, 'cached' => true]);
        }

        // Download with cURL (more reliable than file_get_contents on shared hosting)
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS      => 5,
            CURLOPT_TIMEOUT        => 15,
            CURLOPT_USERAGENT      => 'ERScanner/1.0 (museum kiosk; contact jhave2@gmail.com)',
            CURLOPT_SSL_VERIFYPEER => true,
        ]);
        $imageData = curl_exec($ch);
        $httpCode  = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error     = curl_error($ch);
        curl_close($ch);

        if ($httpCode !== 200 || !$imageData) {
            json_response([
                'error' => 'Download failed',
                'http_code' => $httpCode,
                'curl_error' => $error
            ], 502);
        }

        file_put_contents($dest, $imageData);
        json_response(['path' => 'scanned/' . $safe, 'size' => strlen($imageData)]);
        break;

    // ── POST: save base64 scan photo to scanned/ ──
    case 'save-scan-photo':
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            json_response(['error' => 'POST required'], 405);
        }
        $body     = read_json_body();
        $b64      = $body['b64'] ?? '';
        $filename = $body['filename'] ?? 'scan.jpg';

        if (!$b64) {
            json_response(['error' => 'b64 required'], 400);
        }

        // Strip data URL prefix if present (e.g. "data:image/jpeg;base64,...")
        if (strpos($b64, ',') !== false) {
            $parts = explode(',', $b64, 2);
            $b64 = $parts[1];
        }

        $imageData = base64_decode($b64);
        if (!$imageData) {
            json_response(['error' => 'Invalid base64 data'], 400);
        }

        // Sanitize filename
        $safe = preg_replace('/[^\w\-.]/', '_', $filename);
        if (!$safe) $safe = 'scan.jpg';

        if (!is_dir(SCANNED_DIR)) {
            @mkdir(SCANNED_DIR, 0755, true);
        }

        $dest  = SCANNED_DIR . '/' . $safe;
        $wrote = @file_put_contents($dest, $imageData);

        if ($wrote === false) {
            json_response(['error' => 'Failed to write file'], 500);
        }

        json_response(['path' => 'scanned/' . $safe, 'size' => $wrote]);
        break;

    // ── GET: auto-setup + writability test ──
    case 'setup':
        $results = ['ok' => true, 'checks' => []];

        // Try to create scanner-species.json if missing
        if (!file_exists(SCANNER_DB)) {
            $wrote = @file_put_contents(SCANNER_DB, '[]');
            $results['checks']['scanner_db_create'] = $wrote !== false;
        } else {
            $results['checks']['scanner_db_exists'] = true;
        }

        // Test write to scanner-species.json
        $testWrite = @file_put_contents(SCANNER_DB, @file_get_contents(SCANNER_DB) ?: '[]');
        $results['checks']['scanner_db_writable'] = $testWrite !== false;

        // Try to create scanned/ directory
        if (!is_dir(SCANNED_DIR)) {
            $made = @mkdir(SCANNED_DIR, 0755, true);
            $results['checks']['scanned_dir_create'] = $made;
        } else {
            $results['checks']['scanned_dir_exists'] = true;
        }

        // Test write to scanned/
        $testFile = SCANNED_DIR . '/.write_test';
        $dirWrite = @file_put_contents($testFile, 'ok');
        $results['checks']['scanned_dir_writable'] = $dirWrite !== false;
        if ($dirWrite !== false) @unlink($testFile);

        // Summary
        $results['writable'] = ($results['checks']['scanner_db_writable'] ?? false)
                            && ($results['checks']['scanned_dir_writable'] ?? false);
        $results['php_user'] = function_exists('posix_getpwuid')
            ? posix_getpwuid(posix_geteuid())['name'] ?? 'unknown'
            : get_current_user();

        json_response($results);
        break;

    default:
        json_response(['error' => 'Unknown action. Use: get-species, save-species, download-image, save-scan-photo, setup'], 400);
}
