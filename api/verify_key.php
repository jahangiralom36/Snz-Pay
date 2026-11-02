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

// -------------------------------------------------------------------------
// 📦 Plans / Devices / Brand Keys — টেবিল একবারই তৈরি হয় (.db_plans_v1)
// -------------------------------------------------------------------------
if (!function_exists('snzEnsurePlanSchema')) {
    function snzRootDir() {
        return basename(__DIR__) === 'api' ? dirname(__DIR__) : __DIR__;
    }

    function snzEnsurePlanSchema(PDO $pdo) {
        $flag = snzRootDir() . '/.db_plans_v1';
        if (is_file($flag)) { snzEnsurePlanSchemaV2($pdo); return; }
        $sqls = [
            "CREATE TABLE IF NOT EXISTS `plans` (
                `id` INT AUTO_INCREMENT PRIMARY KEY,
                `name` VARCHAR(100) NOT NULL,
                `price` DECIMAL(10,2) NOT NULL DEFAULT 0,
                `discount_percent` DECIMAL(5,2) NOT NULL DEFAULT 0,
                `duration_hours` INT NOT NULL DEFAULT 720,
                `max_devices` INT NOT NULL DEFAULT 1,
                `max_brand_keys` INT NOT NULL DEFAULT 1,
                `badge` VARCHAR(40) NULL,
                `description` VARCHAR(255) NULL,
                `is_trial` TINYINT(1) NOT NULL DEFAULT 0,
                `status` VARCHAR(20) NOT NULL DEFAULT 'active',
                `sort_order` INT NOT NULL DEFAULT 0,
                `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP
            ) DEFAULT CHARSET=utf8mb4",
            "CREATE TABLE IF NOT EXISTS `key_devices` (
                `id` INT AUTO_INCREMENT PRIMARY KEY,
                `key_id` INT NOT NULL,
                `device_id` VARCHAR(100) NOT NULL,
                `device_name` VARCHAR(150) NULL,
                `bound_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
                `last_seen_at` DATETIME NULL,
                UNIQUE KEY `uq_key_device` (`key_id`, `device_id`),
                KEY `idx_kd_key` (`key_id`)
            ) DEFAULT CHARSET=utf8mb4",
            "ALTER TABLE `user_keys` ADD COLUMN `plan_id` INT NULL",
            "ALTER TABLE `user_keys` ADD COLUMN `max_devices` INT NOT NULL DEFAULT 1",
            "ALTER TABLE `user_keys` ADD COLUMN `max_brand_keys` INT NOT NULL DEFAULT 1",
            "ALTER TABLE `user_keys` ADD COLUMN `device_id` VARCHAR(100) NULL",
            "ALTER TABLE `user_keys` ADD COLUMN `device_name` VARCHAR(150) NULL",
            "ALTER TABLE `user_keys` ADD COLUMN `device_bound_at` DATETIME NULL",
            "ALTER TABLE `user_keys` ADD COLUMN `last_seen_at` DATETIME NULL",
            "ALTER TABLE `brand_keys` ADD COLUMN `brand_name` VARCHAR(128) NULL",
            "ALTER TABLE `brand_keys` ADD COLUMN `brand_type` VARCHAR(20) NULL",
            "ALTER TABLE `brand_keys` ADD COLUMN `brand_link` VARCHAR(255) NULL",
            "ALTER TABLE `brand_keys` ADD COLUMN `whatsapp` VARCHAR(30) NULL",
            "ALTER TABLE `brand_keys` ADD COLUMN `bound_fingerprint` VARCHAR(255) NULL",
            "ALTER TABLE `brand_keys` ADD COLUMN `bound_label` VARCHAR(255) NULL",
            "ALTER TABLE `brand_keys` ADD COLUMN `bound_at` DATETIME NULL",
            "ALTER TABLE `brand_keys` ADD COLUMN `last_used_at` DATETIME NULL",
            "ALTER TABLE `brand_keys` ADD COLUMN `created_at` DATETIME NULL",
        ];
        foreach ($sqls as $sql) {
            try { $pdo->exec($sql); } catch (Throwable $e) {}
        }
        // আগের ৩টা প্ল্যান
        try {
            if ((int) $pdo->query("SELECT COUNT(*) FROM plans")->fetchColumn() === 0) {
                $ins = $pdo->prepare("INSERT INTO plans (name, price, discount_percent, duration_hours, max_devices, max_brand_keys, badge, description, is_trial, status, sort_order) VALUES (?, ?, 0, ?, 1, 1, ?, ?, ?, 'active', ?)");
                $ins->execute(['Free Trial (30 Hours)', 0, 30, '1st Time Free', 'Test all automated features completely free of charge.', 1, 1]);
                $ins->execute(['Standard Plan (30 BDT)', 30, 168, 'Standard', '7 days of uninterrupted SMS & Binance automation.', 0, 2]);
                $ins->execute(['Premium Plan (50 BDT)', 50, 720, 'Popular Choice', 'Full 1 month of uninterrupted high-priority processing.', 0, 3]);
            }
        } catch (Throwable $e) {}
        // আগে এক-ফোন লক করা কী গুলো নতুন ডিভাইস টেবিলে
        try {
            $pdo->exec("INSERT IGNORE INTO key_devices (key_id, device_id, device_name, bound_at, last_seen_at)
                        SELECT id, device_id, device_name, COALESCE(device_bound_at, NOW()), last_seen_at
                        FROM user_keys WHERE device_id IS NOT NULL AND device_id <> ''");
        } catch (Throwable $e) {}
        // পুরনো কী গুলোর plan_id (নাম মিলিয়ে)
        try {
            $pdo->exec("UPDATE user_keys uk JOIN plans p ON p.name = uk.plan_name SET uk.plan_id = p.id WHERE uk.plan_id IS NULL");
        } catch (Throwable $e) {}
        @file_put_contents($flag, date('c'));
        snzEnsurePlanSchemaV2($pdo);
        snzWritePlansCache($pdo);
    }

    /** v2: প্ল্যানের ক্যাটাগরি (ফিল্টার বাটন) + Pin, আর ডিপোজিট রিকোয়েস্ট টেবিল */
    function snzEnsurePlanSchemaV2(PDO $pdo) {
        $flag2 = snzRootDir() . '/.db_plans_v2';
        if (is_file($flag2)) return;
        foreach ([
            "ALTER TABLE `plans` ADD COLUMN `category` VARCHAR(40) NULL",
            "ALTER TABLE `plans` ADD COLUMN `is_pinned` TINYINT(1) NOT NULL DEFAULT 0",
            "CREATE TABLE IF NOT EXISTS `deposit_requests` (
                `id` INT AUTO_INCREMENT PRIMARY KEY,
                `user_id` INT NOT NULL,
                `trx_id` VARCHAR(64) NOT NULL,
                `provider` VARCHAR(30) NULL,
                `amount` DECIMAL(12,2) NOT NULL DEFAULT 0,
                `attempts` INT NOT NULL DEFAULT 0,
                `last_error` VARCHAR(255) NULL,
                `status` VARCHAR(20) NOT NULL DEFAULT 'trying',
                `admin_note` VARCHAR(255) NULL,
                `credited_amount` DECIMAL(12,2) NULL,
                `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
                `updated_at` DATETIME NULL,
                UNIQUE KEY `uq_user_trx` (`user_id`, `trx_id`),
                KEY `idx_dr_status` (`status`)
            ) DEFAULT CHARSET=utf8mb4",
        ] as $sql) {
            try { $pdo->exec($sql); } catch (Throwable $e) {}
        }
        @file_put_contents($flag2, date('c'));
        @unlink(snzRootDir() . '/plans_cache.json');
    }

    function snzGetPlans(PDO $pdo, $onlyActive = true) {
        try {
            $sql = "SELECT * FROM plans " . ($onlyActive ? "WHERE status = 'active' " : "") . "ORDER BY is_pinned DESC, sort_order ASC, price ASC, id ASC";
            return $pdo->query($sql)->fetchAll();
        } catch (Throwable $e) {
            try {   // পুরনো টেবিলে is_pinned না থাকলে
                $sql = "SELECT * FROM plans " . ($onlyActive ? "WHERE status = 'active' " : "") . "ORDER BY sort_order ASC, price ASC, id ASC";
                return $pdo->query($sql)->fetchAll();
            } catch (Throwable $e2) {
                return [];
            }
        }
    }

    /** ফিল্টার বাটনের জন্য ক্যাটাগরি তালিকা */
    function snzPlanCategories(array $plans) {
        $cats = [];
        foreach ($plans as $p) {
            $c = trim((string) ($p['category'] ?? ''));
            if ($c !== '' && !in_array($c, $cats, true)) $cats[] = $c;
        }
        return $cats;
    }

    function snzGetPlan(PDO $pdo, $id) {
        try {
            $st = $pdo->prepare("SELECT * FROM plans WHERE id = ? LIMIT 1");
            $st->execute([(int) $id]);
            return $st->fetch() ?: null;
        } catch (Throwable $e) {
            return null;
        }
    }

    /** ডিসকাউন্টের পর আসল দাম */
    function snzPlanFinalPrice(array $p) {
        $price = (float) ($p['price'] ?? 0);
        $disc = max(0, min(100, (float) ($p['discount_percent'] ?? 0)));
        return round($price * (100 - $disc) / 100, 2);
    }

    function snzDurationLabel($hours) {
        $hours = (int) $hours;
        if ($hours > 0 && $hours % 24 === 0) {
            $d = $hours / 24;
            return $d . ($d == 1 ? ' Day' : ' Days');
        }
        return $hours . ($hours == 1 ? ' Hour' : ' Hours');
    }

    /** হোমপেজ যেন ডাটাবেজ ছাড়াই দ্রুত প্ল্যান দেখাতে পারে */
    function snzWritePlansCache(PDO $pdo) {
        @file_put_contents(snzRootDir() . '/plans_cache.json', json_encode(snzGetPlans($pdo, true), JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
    }

    function snzReadPlansCache() {
        $f = snzRootDir() . '/plans_cache.json';
        if (!is_file($f)) return null;
        $d = json_decode((string) @file_get_contents($f), true);
        return is_array($d) ? $d : null;
    }

    /** কী অনুযায়ী কানেক্টেড ফোনের তালিকা: [key_id => [rows]] */
    function snzKeyDevices(PDO $pdo, array $keyIds) {
        $out = [];
        $keyIds = array_values(array_filter(array_map('intval', $keyIds)));
        if (!$keyIds) return $out;
        try {
            $in = implode(',', array_fill(0, count($keyIds), '?'));
            $st = $pdo->prepare("SELECT * FROM key_devices WHERE key_id IN ($in) ORDER BY bound_at ASC");
            $st->execute($keyIds);
            foreach ($st->fetchAll() as $r) $out[(int) $r['key_id']][] = $r;
        } catch (Throwable $e) {}
        return $out;
    }

    /** ইউজার কয়টা Brand Key বানাতে পারবে (চালু প্ল্যান গুলোর যোগফল) আর কয়টা বানিয়েছে */
    function snzBrandQuota(PDO $pdo, $userId) {
        $limit = 0; $used = 0;
        try {
            $st = $pdo->prepare("SELECT COALESCE(SUM(GREATEST(max_brand_keys, 0)), 0) FROM user_keys WHERE user_id = ? AND status = 'active' AND expires_at > NOW()");
            $st->execute([$userId]);
            $limit = (int) $st->fetchColumn();
            $st = $pdo->prepare("SELECT COUNT(*) FROM brand_keys WHERE user_id = ? AND status != 'revoked'");
            $st->execute([$userId]);
            $used = (int) $st->fetchColumn();
        } catch (Throwable $e) {}
        return ['limit' => $limit, 'used' => $used];
    }

    /** ইউজারের চালু প্ল্যানের সবচেয়ে দূরের মেয়াদ (না থাকলে null) */
    function snzActivePlanExpiry(PDO $pdo, $userId) {
        try {
            $st = $pdo->prepare("SELECT MAX(expires_at) FROM user_keys WHERE user_id = ? AND status = 'active' AND expires_at > NOW()");
            $st->execute([$userId]);
            $v = $st->fetchColumn();
            return $v ?: null;
        } catch (Throwable $e) {
            return null;
        }
    }
}
// -------------------------------------------------------------------------

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
snzEnsurePlanSchema($pdo);

// অ্যাপ যে ফোন থেকে লগইন করছে
$deviceId = substr(preg_replace('/[^A-Za-z0-9_.:-]/', '', (string) ($data['device_id'] ?? '')), 0, 100);
$deviceName = trim(mb_substr(strip_tags((string) ($data['device_name'] ?? '')), 0, 150));

// ইনডেক্স ব্যবহার করে দ্রুত সার্চ; একই key এর একাধিক row থাকলে সবচেয়ে বেশি মেয়াদিটা
$stmt = $pdo->prepare("SELECT id, plan_name, expires_at, device_id, device_name, max_devices, status FROM user_keys WHERE license_key = ? ORDER BY (status = 'active') DESC, expires_at DESC LIMIT 1");
$stmt->execute([$key]);
$keyRow = $stmt->fetch();

if (!$keyRow) {
    jsonResponse(403, ["status" => "error", "message" => "This license key was not found (it may have been deleted).", "reason" => "not_found"]);
}
if ($keyRow['status'] !== 'active') {
    jsonResponse(403, ["status" => "error", "message" => "This license key has been banned by admin.", "reason" => "banned"]);
}

$expireTime = strtotime($keyRow['expires_at']);
if ($expireTime === false || $expireTime < time()) {
    jsonResponse(403, ["status" => "error", "message" => "Your license key has expired. Please renew your plan.", "reason" => "expired"]);
}

// ---------------------------------------------------------------------
// 📱 প্ল্যান অনুযায়ী কয়টা ফোনে কী চলবে (max_devices)
//  - নতুন ফোন লগইন করলে জায়গা থাকলে যুক্ত হয়, না থাকলে আটকে যায়
//  - অ্যাডমিন প্যানেল থেকে কোনো ফোন রিমুভ করলে সেই জায়গায় নতুন ফোন লগইন করা যায়
// ---------------------------------------------------------------------
if ($deviceId !== '') {
    $maxDevices = max(1, (int) ($keyRow['max_devices'] ?? 1));
    $dv = $pdo->prepare("SELECT id, device_name FROM key_devices WHERE key_id = ? AND device_id = ? LIMIT 1");
    $dv->execute([$keyRow['id'], $deviceId]);
    $known = $dv->fetch();

    if ($known) {
        $pdo->prepare("UPDATE key_devices SET last_seen_at = NOW()" . ($deviceName !== '' ? ", device_name = ?" : "") . " WHERE id = ?")
            ->execute($deviceName !== '' ? [$deviceName, $known['id']] : [$known['id']]);
    } else {
        $cnt = $pdo->prepare("SELECT COUNT(*) FROM key_devices WHERE key_id = ?");
        $cnt->execute([$keyRow['id']]);
        $used = (int) $cnt->fetchColumn();
        if ($used >= $maxDevices) {
            $names = $pdo->prepare("SELECT device_name FROM key_devices WHERE key_id = ? ORDER BY bound_at ASC");
            $names->execute([$keyRow['id']]);
            $list = implode(', ', array_filter(array_map('strval', $names->fetchAll(PDO::FETCH_COLUMN)))) ?: 'another phone';
            jsonResponse(403, [
                "status" => "error",
                "message" => "This key is already connected to " . $list . " (" . $used . "/" . $maxDevices . " phones). Ask admin to remove a phone or upgrade your plan.",
                "reason" => "device_locked",
            ]);
        }
        $pdo->prepare("INSERT INTO key_devices (key_id, device_id, device_name, bound_at, last_seen_at) VALUES (?, ?, ?, NOW(), NOW())")
            ->execute([$keyRow['id'], $deviceId, $deviceName !== '' ? $deviceName : 'Android Phone']);
    }
    // পুরনো কলাম (প্রথম ফোন) আপডেট রাখা
    $pdo->prepare("UPDATE user_keys SET device_id = COALESCE(NULLIF(device_id, ''), ?), device_name = COALESCE(NULLIF(device_name, ''), ?), last_seen_at = NOW() WHERE id = ?")
        ->execute([$deviceId, $deviceName !== '' ? $deviceName : 'Android Phone', $keyRow['id']]);
}

jsonResponse(200, [
    "status" => "valid",
    "plan" => $keyRow['plan_name'],
    // আগে float (1.7E+12 / ".0") যেত → অ্যাপে Long parse সমস্যা; এখন integer
    "expires_timestamp" => $expireTime * 1000
]);
