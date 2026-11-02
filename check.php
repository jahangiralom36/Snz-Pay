<?php
// =========================================================================
// 🩺 SNZPay সার্ভার চেক — নতুন হোস্টিং ঠিকমতো সেটআপ হয়েছে কিনা দেখায়।
// ব্রাউজারে খুলুন: https://আপনার-ডোমেইন/check.php
// সব ঠিক থাকলে এই ফাইলটা ডিলিট করে দিন।
// =========================================================================
header('Content-Type: text/html; charset=utf-8');
header('Cache-Control: no-store');

$rows = [];
function row($ok, $title, $detail = '') {
    global $rows;
    $rows[] = [$ok, $title, $detail];
}

row(version_compare(PHP_VERSION, '7.2', '>='), 'PHP version', PHP_VERSION . ' (7.4+ recommended)');
row(extension_loaded('pdo_mysql'), 'PDO MySQL extension');
row(function_exists('curl_init'), 'cURL extension', 'needed for Binance');

$cfg = __DIR__ . '/config.php';
row(is_file($cfg), 'config.php found', $cfg);

$pdo = null;
if (is_file($cfg)) {
    require_once $cfg;
    $pdo = function_exists('getDBConnection') ? getDBConnection() : null;
    row((bool) $pdo, 'Database connection', $pdo ? 'OK (' . DB_NAME . ')' : 'FAILED — check DB_NAME / DB_USER / DB_PASS in config.php');
    row(defined('APP_SECRET_TOKEN'), 'APP_SECRET_TOKEN in config.php', defined('APP_SECRET_TOKEN') ? 'set' : 'missing (app_gateway uses the built-in token)');
    if (isset($ADMIN_PASSWORD)) row($ADMIN_PASSWORD !== 'admin123', 'Admin password changed', $ADMIN_PASSWORD === 'admin123' ? 'still admin123 — change it in config.php' : 'OK');
}

if ($pdo) {
    foreach (['users', 'plans', 'user_keys', 'key_devices', 'brand_keys', 'payments', 'user_wallets', 'deposit_requests'] as $t) {
        try {
            $n = (int) $pdo->query("SELECT COUNT(*) FROM `$t`")->fetchColumn();
            row(true, "Table: $t", "$n rows");
        } catch (Throwable $e) {
            row(false, "Table: $t", 'missing — import database.sql in phpMyAdmin');
        }
    }
    try {
        $u = $pdo->query("SELECT id, username FROM users ORDER BY id ASC LIMIT 1")->fetch();
        row((bool) $u, 'Account #1 (master key account)', $u ? '#' . $u['id'] . ' ' . htmlspecialchars($u['username']) : 'no users yet — register your own account first');
    } catch (Throwable $e) {}
}

foreach (['api/app_gateway.php', 'api/verify_key.php', 'api/verify_payment.php', 'api/app_config.php', 'index.php', 'dashboard.php', 'admin.php'] as $f) {
    row(is_file(__DIR__ . '/' . $f), "File: $f");
}
row(is_writable(__DIR__), 'Website folder writable', 'needed for settings files');
row(is_dir(__DIR__ . '/uploads') && is_writable(__DIR__ . '/uploads'), 'uploads/ folder writable', 'logo / QR uploads');

$scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https') ? 'https' : 'http';
$base = $scheme . '://' . ($_SERVER['HTTP_HOST'] ?? '') . rtrim(dirname($_SERVER['SCRIPT_NAME']), '/');
row($scheme === 'https', 'HTTPS (SSL)', $scheme === 'https' ? 'OK' : 'Not HTTPS — the app uses https://, enable SSL for this domain');
?>
<!doctype html>
<html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>SNZPay Server Check</title>
<style>
body{margin:0;background:#061535;color:#e2e8f0;font-family:system-ui,Segoe UI,Roboto,sans-serif;padding:16px}
.box{max-width:640px;margin:0 auto;background:#0b2559;border:1px solid #1e4aa3;border-radius:20px;padding:20px}
h1{margin:0 0 4px;font-size:20px;color:#fff}.sub{color:#94a3b8;font-size:12px;margin-bottom:14px}
.r{display:flex;gap:10px;align-items:flex-start;padding:10px 0;border-top:1px solid #12306b;font-size:14px}
.i{width:22px;flex-shrink:0;text-align:center}.ok{color:#00D89A}.no{color:#f87171}.d{color:#94a3b8;font-size:12px;word-break:break-all}
code{color:#00D9FF}
</style></head><body><div class="box">
<h1>🩺 SNZPay Server Check</h1>
<div class="sub">Site URL: <code><?= htmlspecialchars($base) ?></code><br>App gateway: <code><?= htmlspecialchars($base) ?>/api/app_gateway.php</code></div>
<?php foreach ($rows as [$ok, $t, $d]): ?>
<div class="r"><div class="i <?= $ok ? 'ok' : 'no' ?>"><?= $ok ? '✔' : '✘' ?></div><div><div><?= htmlspecialchars($t) ?></div><?php if ($d !== ''): ?><div class="d"><?= $d ?></div><?php endif; ?></div></div>
<?php endforeach; ?>
<div class="sub" style="margin-top:14px">⚠️ Delete check.php after everything is ✔.</div>
</div></body></html>
