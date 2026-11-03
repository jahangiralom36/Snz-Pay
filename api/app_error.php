<?php
// =========================================================================
// 📲 App → Server: অ্যাপের error রিপোর্ট (Admin → Errors → App Error এ দেখা যায়)
// অ্যাপ নিজে থেকেই পাঠায় — login ব্যর্থ, SMS আপলোড ব্যর্থ, crash ইত্যাদি।
// =========================================================================
require_once __DIR__ . '/../config.php';
if (is_file(__DIR__ . '/../snz_errors.php')) require_once __DIR__ . '/../snz_errors.php';
// 🔐 অ্যাপ পুরো ডাটা এনকোড করে পাঠায় (p=...) — হোস্টিং ফায়ারওয়াল ভেতরের লেখা দেখে যেন ভুল করে আটকাতে না পারে
if (isset($_REQUEST['p']) && is_string($_REQUEST['p']) && !isset($_REQUEST['license_key'])) {
    $snzDec = json_decode((string) base64_decode(strtr($_REQUEST['p'], '-_', '+/')), true);
    if (is_array($snzDec)) {
        $_POST = array_merge($_POST, $snzDec);
        $_REQUEST = array_merge($_REQUEST, $snzDec);
        if (!headers_sent()) header('X-SNZ-Enc: 1');
    }
}

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

function appErrOut($code, $arr) {
    http_response_code($code);
    echo json_encode($arr, JSON_UNESCAPED_UNICODE);
    exit;
}

if (!function_exists('snz_log_error')) appErrOut(200, ['status' => 'ignored', 'message' => 'Error log not installed.']);

$raw = (string) file_get_contents('php://input');
$data = json_decode($raw, true);
if (!is_array($data)) { parse_str($raw, $data); }
$data = array_merge($_GET, $_POST, is_array($data) ? $data : []);

$items = $data['errors'] ?? null;
if (is_string($items)) $items = json_decode($items, true);
if (!is_array($items)) $items = [['title' => $data['title'] ?? '', 'message' => $data['message'] ?? '', 'detail' => $data['detail'] ?? '']];

$pdo = function_exists('getDBConnection') ? getDBConnection() : null;

// স্প্যাম ঠেকাতে: এক IP থেকে ঘণ্টায় সর্বোচ্চ ১২০টা
$ip = $_SERVER['REMOTE_ADDR'] ?? '';
$rateFile = sys_get_temp_dir() . '/snz_apperr_' . md5($ip . date('YmdH'));
$used = (int) @file_get_contents($rateFile);
if ($used > 120) appErrOut(429, ['status' => 'error', 'message' => 'Too many reports.']);

$extra = [
    'device'      => (string) ($data['device'] ?? ''),
    'license_key' => (string) ($data['license_key'] ?? ''),
    'app_version' => (string) ($data['app_version'] ?? ''),
    'ip'          => $ip,
];
$saved = 0;
foreach (array_slice($items, 0, 30) as $it) {
    if (!is_array($it)) continue;
    $title = trim((string) ($it['title'] ?? ''));
    $msg = trim((string) ($it['message'] ?? ''));
    if ($title === '' && $msg === '') continue;
    $e = $extra;
    $e['where'] = (string) ($it['where'] ?? 'Android app');
    snz_log_error('app', $title !== '' ? $title : 'App error', $msg, (string) ($it['detail'] ?? ''), $e, $pdo);
    $saved++;
}
@file_put_contents($rateFile, (string) ($used + max(1, $saved)));
appErrOut(200, ['status' => 'success', 'saved' => $saved]);
