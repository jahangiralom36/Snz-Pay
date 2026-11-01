<?php
// =========================================================================
// 📲 App → Server SMS Gateway
// অ্যাপ এখানে bKash / Nagad / Rocket SMS পাঠায়, সেটা payments টেবিলে সেভ হয়
// =========================================================================
require_once __DIR__ . '/../config.php';

// -------------------------------------------------------------------------
// পুরনো config.php দিয়েও যেন চলে — যা নেই শুধু সেটাই এখানে যোগ হবে
// -------------------------------------------------------------------------
if (!defined('MASTER_KEY')) define('MASTER_KEY', 'CHANGE_ME_MASTER_KEY');
if (!defined('MASTER_USER_ID')) define('MASTER_USER_ID', 1);
// অ্যাপের ভেতরে যে token বসানো আছে (config.php তে অন্য কিছু থাকলেও এটা গ্রহণ করা হবে)
if (!defined('SNZ_APP_BUILTIN_TOKEN')) define('SNZ_APP_BUILTIN_TOKEN', 'CHANGE_ME_APP_TOKEN');
if (!defined('APP_SECRET_TOKEN')) define('APP_SECRET_TOKEN', SNZ_APP_BUILTIN_TOKEN);
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

sendApiHeaders('POST, OPTIONS');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonResponse(405, ["status" => "error", "message" => "Method not allowed."]);
}

// JSON না হলেও (form-data / x-www-form-urlencoded) এখন কাজ করবে
$data = readRequestData();
if (empty($data)) {
    jsonResponse(400, ["status" => "error", "message" => "Invalid JSON payload."]);
}

$secret = trim((string) ($data['secret_key'] ?? $data['secret'] ?? $data['token'] ?? ''));
if ($secret === '' || (!hash_equals((string) APP_SECRET_TOKEN, $secret) && !hash_equals(SNZ_APP_BUILTIN_TOKEN, $secret))) {
    jsonResponse(403, ["status" => "error", "message" => "Unauthorized access."]);
}

// অ্যাপ বিভিন্ন নামে ফিল্ড পাঠালেও ধরতে পারবে
$method     = strtoupper(trim((string) ($data['method'] ?? $data['provider'] ?? 'UNKNOWN')));
$trxId      = strtoupper(trim((string) ($data['trx_id'] ?? $data['trxid'] ?? $data['transaction_id'] ?? '')));
$amount     = parseAmount($data['amount'] ?? 0);   // "Tk 1,000.00" → 1000.00
$sender     = trim((string) ($data['sender_number'] ?? $data['sender'] ?? $data['phone'] ?? ''));
$rawMsg     = trim((string) ($data['raw_message'] ?? $data['message'] ?? $data['sms'] ?? ''));
$origin     = trim((string) ($data['origin_address'] ?? $data['origin'] ?? $data['address'] ?? ''));
$licenseKey = strtoupper(trim((string) ($data['license_key'] ?? $data['api_key'] ?? $data['key'] ?? '')));

// TrxID থেকে স্পেস / অপ্রয়োজনীয় চিহ্ন বাদ
$trxId = preg_replace('/[^A-Z0-9]/', '', $trxId);

if ($trxId === '' || $amount <= 0) {
    jsonResponse(422, ["status" => "error", "message" => "Transaction ID or Amount is invalid."]);
}
if ($licenseKey === '') {
    jsonResponse(403, ["status" => "error", "message" => "License key is required."]);
}

$pdo = getDBConnection();
if (!$pdo) {
    jsonResponse(500, ["status" => "error", "message" => "Database connection error."]);
}
ensureDatabaseIndexes($pdo);

try {
    // ----- লাইসেন্স থেকে মালিক (user) বের করা -----
    $ownerUserId = null;
    if (strcasecmp($licenseKey, MASTER_KEY) === 0) {
        $ownerUserId = MASTER_USER_ID;
    } else {
        // UPPER(TRIM(col)) দিলে ইনডেক্স কাজ করে না → সরাসরি = (MySQL collation এমনিতেই case-insensitive)
        $uStmt = $pdo->prepare("SELECT user_id, expires_at FROM user_keys WHERE license_key = ? AND status = 'active' ORDER BY expires_at DESC LIMIT 1");
        $uStmt->execute([$licenseKey]);
        $kRow = $uStmt->fetch();
        if ($kRow && strtotime($kRow['expires_at']) >= time()) {
            $ownerUserId = (int) $kRow['user_id'];
        }

        if (!$ownerUserId) {
            $bStmt = $pdo->prepare("SELECT user_id, expires_at FROM brand_keys WHERE api_key = ? AND status != 'revoked' LIMIT 1");
            $bStmt->execute([$licenseKey]);
            $bRow = $bStmt->fetch();
            if ($bRow && (empty($bRow['expires_at']) || strtotime($bRow['expires_at']) >= time())) {
                $ownerUserId = (int) $bRow['user_id'];
            }
        }
    }

    if (!$ownerUserId) {
        jsonResponse(403, ["status" => "error", "message" => "Invalid or expired license key."]);
    }

    // ----- ডুপ্লিকেট চেক (UNIQUE ইনডেক্স না থাকলেও ডাবল সেভ হবে না) -----
    $dup = $pdo->prepare("SELECT id FROM payments WHERE trx_id = ? LIMIT 1");
    $dup->execute([$trxId]);
    if ($dup->fetchColumn()) {
        // 200 দিচ্ছি যাতে অ্যাপ বারবার retry না করে
        jsonResponse(200, ["status" => "already_exists", "message" => "TrxID already exists in SQL.", "trx_id" => $trxId]);
    }

    // নেট বন্ধ থাকায় অ্যাপ পরে SMS পাঠালে — আসল SMS আসার সময়টাই রাখা হয় (৭ দিনের মধ্যে হলে)
    $smsTimeMs = (float) ($data['sms_time'] ?? 0);
    $nowMs = microtime(true) * 1000;
    if ($smsTimeMs > $nowMs - 7 * 86400000 && $smsTimeMs < $nowMs + 300000) {
        $stmt = $pdo->prepare("INSERT INTO payments (trx_id, amount, method, sender, raw_sms, origin, user_id, status, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, 'pending', FROM_UNIXTIME(?))");
        $stmt->execute([$trxId, $amount, $method, $sender, $rawMsg, $origin, $ownerUserId, (int) floor($smsTimeMs / 1000)]);
    } else {
        $stmt = $pdo->prepare("INSERT INTO payments (trx_id, amount, method, sender, raw_sms, origin, user_id, status, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, 'pending', NOW())");
        $stmt->execute([$trxId, $amount, $method, $sender, $rawMsg, $origin, $ownerUserId]);
    }

    jsonResponse(200, [
        "status"  => "success",
        "message" => "Payment saved successfully.",
        "trx_id"  => $trxId,
        "amount"  => $amount
    ]);
} catch (PDOException $e) {
    if ($e->getCode() == 23000) {
        jsonResponse(200, ["status" => "already_exists", "message" => "TrxID already exists in SQL.", "trx_id" => $trxId]);
    }
    error_log("app_gateway: " . $e->getMessage());
    jsonResponse(500, ["status" => "error", "message" => "Database error."]);
}
