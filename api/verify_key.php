<?php
// =========================================================================
// 🔑 App License Key Verify
// =========================================================================
require_once __DIR__ . '/../config.php';

// -------------------------------------------------------------------------
// পুরনো config.php দিয়েও যেন চলে — যা নেই শুধু সেটাই এখানে যোগ হবে
// -------------------------------------------------------------------------
if (!defined('MASTER_KEY')) define('MASTER_KEY', 'CHANGE_ME_MASTER_KEY');
if (!defined('MASTER_USER_ID')) define('MASTER_USER_ID', 1);
if (!defined('APP_SECRET_TOKEN')) define('APP_SECRET_TOKEN', 'CHANGE_ME_APP_TOKEN');
if (!isset($FIREBASE_URL)) $FIREBASE_URL = "";
if (!isset($USDT_RATE)) $USDT_RATE = 125.00;
if (!isset($MIN_BINANCE_USDT)) $MIN_BINANCE_USDT = 0.25;
if (!isset($settingsFile)) {
    $settingsFile = (basename(__DIR__) === 'api' ? dirname(__DIR__) : __DIR__) . '/payment_settings.json';
    if (is_file($settingsFile)) {
        $savedPay = json_decode((string) @file_get_contents($settingsFile), true);
        if (is_array($savedPay)) $PAYMENT_INFO = array_merge($PAYMENT_INFO ?? [], $savedPay);
    }
}
if (!function_exists('readRequestData')) {
    function readRequestData() {
        $data = [];
        $raw = file_get_contents("php://input");
        if ($raw !== '' && $raw !== false) {
            $json = json_decode($raw, true);
            if (is_array($json)) { $data = $json; } else { parse_str($raw, $form); if (is_array($form)) $data = $form; }
        }
        return array_merge($_GET, $_POST, $data);
    }
}
if (!function_exists('parseAmount')) {
    function parseAmount($value) {
        $s = str_replace(',', '', (string) $value);
        $s = strtr($s, ['০'=>'0','১'=>'1','২'=>'2','৩'=>'3','৪'=>'4','৫'=>'5','৬'=>'6','৭'=>'7','৮'=>'8','৯'=>'9']);
        return preg_match('/\d+(?:\.\d+)?/', $s, $m) ? floatval($m[0]) : 0.0;
    }
}
if (!function_exists('sendApiHeaders')) {
    function sendApiHeaders($methods = 'GET, POST, OPTIONS') {
        header("Content-Type: application/json; charset=UTF-8");
        header("Access-Control-Allow-Origin: *");
        header("Access-Control-Allow-Methods: " . $methods);
        header("Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With, X-API-KEY");
        header("Cache-Control: no-store");
        if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') { http_response_code(204); exit; }
    }
}
if (!function_exists('jsonResponse')) {
    function jsonResponse($code, array $payload) {
        http_response_code($code);
        echo json_encode($payload, JSON_UNESCAPED_UNICODE);
        exit;
    }
}
if (!function_exists('pushToFirebase')) {
    function pushToFirebase($url, $method = "PUT", $data = null) {
        global $FIREBASE_URL;
        if (empty($FIREBASE_URL) || !function_exists('curl_init')) return false;
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 3);
        curl_setopt($ch, CURLOPT_TIMEOUT, 5);
        if ($data !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
            curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
        }
        $res = curl_exec($ch);
        curl_close($ch);
        return $res !== false;
    }
}
if (!function_exists('ensureDatabaseIndexes')) {
    // পুরনো config এ এটা ছিল না — এখানে MySQL টাইমজোন মিলানো + একবার ইনডেক্স তৈরি
    function ensureDatabaseIndexes(PDO $pdo) {
        static $done = false;
        if ($done) return;
        $done = true;
        try { $pdo->exec("SET time_zone = '" . date('P') . "'"); } catch (Throwable $e) {}
        $root = basename(__DIR__) === 'api' ? dirname(__DIR__) : __DIR__;
        $flag = $root . '/.db_indexes_v1';
        if (is_file($flag)) return;
        $indexes = [
            ['user_keys', 'idx_uk_license', 'license_key'], ['user_keys', 'idx_uk_user', 'user_id'],
            ['brand_keys', 'idx_bk_api', 'api_key'], ['payments', 'idx_pay_trx', 'trx_id'],
            ['payments', 'idx_pay_status_user', 'status, user_id'], ['payments', 'idx_pay_sender', 'sender'],
            ['user_wallets', 'idx_uw_provider', 'provider, status, user_id'],
        ];
        foreach ($indexes as [$table, $name, $cols]) {
            try {
                $firstCol = trim(explode(',', $cols)[0]);
                $chk = $pdo->prepare("SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ? AND SEQ_IN_INDEX = 1");
                $chk->execute([$table, $firstCol]);
                if ((int) $chk->fetchColumn() > 0) continue;
                $pdo->exec("ALTER TABLE `$table` ADD INDEX `$name` ($cols)");
            } catch (Throwable $e) {}
        }
        @file_put_contents($flag, date('c'));
    }
}
// -------------------------------------------------------------------------

// -------------------------------------------------------------------------
// লাইসেন্স কী → এক ফোনে লক: user_keys টেবিলে ডিভাইস কলাম (একবারই তৈরি হয়)
// -------------------------------------------------------------------------
if (!function_exists('snzEnsureDeviceColumns')) {
    function snzEnsureDeviceColumns(PDO $pdo) {
        $flag = (basename(__DIR__) === 'api' ? dirname(__DIR__) : __DIR__) . '/.db_device_cols_v1';
        if (is_file($flag)) return;
        foreach ([
            "ALTER TABLE `user_keys` ADD COLUMN `device_id` VARCHAR(100) NULL",
            "ALTER TABLE `user_keys` ADD COLUMN `device_name` VARCHAR(150) NULL",
            "ALTER TABLE `user_keys` ADD COLUMN `device_bound_at` DATETIME NULL",
            "ALTER TABLE `user_keys` ADD COLUMN `last_seen_at` DATETIME NULL",
        ] as $sql) {
            try { $pdo->exec($sql); } catch (Throwable $e) {}
        }
        @file_put_contents($flag, date('c'));
    }
}

sendApiHeaders('GET, POST, OPTIONS');

$data = readRequestData();
$key = strtoupper(trim((string) ($data['license_key'] ?? $data['key'] ?? $data['api_key'] ?? '')));

if ($key === '') {
    jsonResponse(400, ["status" => "error", "message" => "Key is required."]);
}

if (strcasecmp($key, MASTER_KEY) === 0) {
    // PHP_INT_MAX অ্যাপে overflow/parse error করত → নিরাপদ ভবিষ্যৎ তারিখ (2100 সাল)
    jsonResponse(200, [
        "status" => "valid",
        "plan" => "VIP Lifetime Master",
        "expires_timestamp" => 4102444800000
    ]);
}

$pdo = getDBConnection();
if (!$pdo) {
    jsonResponse(500, ["status" => "error", "message" => "Database error."]);
}
ensureDatabaseIndexes($pdo);
snzEnsureDeviceColumns($pdo);

// অ্যাপ যে ফোন থেকে লগইন করছে
$deviceId = substr(preg_replace('/[^A-Za-z0-9_.:-]/', '', (string) ($data['device_id'] ?? '')), 0, 100);
$deviceName = trim(mb_substr(strip_tags((string) ($data['device_name'] ?? '')), 0, 150));

// ইনডেক্স ব্যবহার করে দ্রুত সার্চ; একই key এর একাধিক row থাকলে সবচেয়ে বেশি মেয়াদিটা
$stmt = $pdo->prepare("SELECT id, plan_name, expires_at, device_id, device_name FROM user_keys WHERE license_key = ? AND status = 'active' ORDER BY expires_at DESC LIMIT 1");
$stmt->execute([$key]);
$keyRow = $stmt->fetch();

if (!$keyRow) {
    jsonResponse(403, ["status" => "error", "message" => "Invalid or inactive key."]);
}

$expireTime = strtotime($keyRow['expires_at']);
if ($expireTime === false || $expireTime < time()) {
    jsonResponse(403, ["status" => "error", "message" => "Key has expired."]);
}

// ---------------------------------------------------------------------
// 📱 এক কী = এক ফোন
//  - প্রথমবার যে ফোনে লগইন হবে, কী সেই ফোনে লক হয়ে যাবে
//  - অন্য ফোনে লগইন করতে চাইলে আটকে যাবে (অ্যাডমিন "Reset Device" করলে আবার নতুন ফোনে লগইন করা যাবে)
// ---------------------------------------------------------------------
if ($deviceId !== '') {
    $boundId = (string) ($keyRow['device_id'] ?? '');
    if ($boundId === '') {
        $pdo->prepare("UPDATE user_keys SET device_id = ?, device_name = ?, device_bound_at = NOW(), last_seen_at = NOW() WHERE id = ?")
            ->execute([$deviceId, $deviceName !== '' ? $deviceName : 'Android Phone', $keyRow['id']]);
    } elseif (!hash_equals($boundId, $deviceId)) {
        $who = $keyRow['device_name'] ?: 'another phone';
        jsonResponse(403, [
            "status" => "error",
            "message" => "This key is already connected to " . $who . ". One key works on one phone only. Ask admin to reset the device.",
            "reason" => "device_locked",
        ]);
    } else {
        if ($deviceName !== '' && $deviceName !== (string) $keyRow['device_name']) {
            $pdo->prepare("UPDATE user_keys SET device_name = ?, last_seen_at = NOW() WHERE id = ?")->execute([$deviceName, $keyRow['id']]);
        } else {
            $pdo->prepare("UPDATE user_keys SET last_seen_at = NOW() WHERE id = ?")->execute([$keyRow['id']]);
        }
    }
}

jsonResponse(200, [
    "status" => "valid",
    "plan" => $keyRow['plan_name'],
    // আগে float (1.7E+12 / ".0") যেত → অ্যাপে Long parse সমস্যা; এখন integer
    "expires_timestamp" => $expireTime * 1000
]);
