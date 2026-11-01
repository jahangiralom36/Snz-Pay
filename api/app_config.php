<?php
// =========================================================================
// 📱 App Remote Config — অ্যাপ এখান থেকে নাম, লোগো, নোটিশ, On/Off,
// আপডেট তথ্য আর অ্যাডমিনের বানানো SMS Rule গুলো পড়ে।
// (অ্যাডমিন প্যানেল → App Settings থেকে বদলানো হয়)
// =========================================================================
header("Content-Type: application/json; charset=UTF-8");
header("Access-Control-Allow-Origin: *");
header("Cache-Control: no-store");

$root = dirname(__DIR__);

$isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
    || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https')
    || (($_SERVER['SERVER_PORT'] ?? '') == 443)
    || (stripos($_SERVER['HTTP_CF_VISITOR'] ?? '', 'https') !== false);
$baseUrl = ($isHttps ? "https://" : "http://") . ($_SERVER['HTTP_HOST'] ?? 'localhost')
    . rtrim(str_replace('\\', '/', dirname(dirname($_SERVER['SCRIPT_NAME'] ?? '/api/app_config.php'))), '/');

function snzAbsUrl($url, $baseUrl) {
    $url = trim((string) $url);
    if ($url === '') return '';
    if (preg_match('#^https?://#i', $url)) return $url;
    return $baseUrl . '/' . ltrim($url, '/');
}

$site = [];
if (is_file($root . '/site_settings.json')) {
    $tmp = json_decode((string) @file_get_contents($root . '/site_settings.json'), true);
    if (is_array($tmp)) $site = $tmp;
}
$app = [];
if (is_file($root . '/app_settings.json')) {
    $tmp = json_decode((string) @file_get_contents($root . '/app_settings.json'), true);
    if (is_array($tmp)) $app = $tmp;
}

// শুধু চালু (enabled) রুলগুলো অ্যাপে যাবে
$rules = [];
foreach (($app['sms_rules'] ?? []) as $r) {
    if (empty($r['enabled'])) continue;
    $rules[] = [
        'id'            => (string) ($r['id'] ?? ''),
        'method'        => strtoupper((string) ($r['method'] ?? 'CUSTOM')),
        'senders'       => (string) ($r['senders'] ?? ''),
        'must_contain'  => (string) ($r['must_contain'] ?? ''),
        'trx_before'    => (string) ($r['trx_before'] ?? ''),
        'amount_before' => (string) ($r['amount_before'] ?? ''),
        'number_before' => (string) ($r['number_before'] ?? ''),
    ];
}

$apkUrl = !empty($app['apk_url']) ? $app['apk_url'] : (is_file($root . '/app-release.apk') ? 'app-release.apk' : '');

echo json_encode([
    'status'              => 'ok',
    'app_name'            => (string) (!empty($app['app_name']) ? $app['app_name'] : ($site['site_name'] ?? 'SNZ Pay')),
    'app_tagline'         => (string) (!empty($app['app_tagline']) ? $app['app_tagline'] : ($site['site_tagline'] ?? 'Faster • Safer • Smarter')),
    'logo_url'            => snzAbsUrl(!empty($app['app_logo']) ? $app['app_logo'] : ($site['site_logo'] ?? ''), $baseUrl),
    'notice'              => (string) ($app['notice'] ?? ''),
    'app_enabled'         => !isset($app['app_enabled']) || (bool) $app['app_enabled'],
    'disabled_message'    => (string) ($app['disabled_message'] ?? 'Service is temporarily unavailable. Please try again later.'),
    'latest_version_code' => (int) ($app['latest_version_code'] ?? 0),
    'latest_version_name' => (string) ($app['latest_version_name'] ?? ''),
    'update_message'      => (string) ($app['update_message'] ?? 'A new version is available. Please update the app.'),
    'force_update'        => !empty($app['force_update']),
    'apk_url'             => snzAbsUrl($apkUrl, $baseUrl),
    'strict_sender'       => !isset($app['strict_sender']) || (bool) $app['strict_sender'],
    'sms_rules'           => $rules,
    'server_time'         => round(microtime(true) * 1000),
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
