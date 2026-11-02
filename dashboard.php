<?php
// Gzip compression (পেজ দ্রুত লোড)
if (!ob_start("ob_gzhandler")) ob_start();

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/config.php';
if (is_file(__DIR__ . '/snz_errors.php')) require_once __DIR__ . '/snz_errors.php';   // 🧯 Error log (Admin → Errors)

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
// Site Settings (অ্যাডমিন প্যানেল → Site Settings থেকে বদলানো যায়)
// -------------------------------------------------------------------------
$siteSettingsFile = __DIR__ . '/site_settings.json';
$SITE_SETTINGS = [];
if (is_file($siteSettingsFile)) {
    $tmpSet = json_decode((string) @file_get_contents($siteSettingsFile), true);
    if (is_array($tmpSet)) $SITE_SETTINGS = $tmpSet;
}
if (!empty($SITE_SETTINGS['site_name']))    $SITE_NAME    = $SITE_SETTINGS['site_name'];
if (!empty($SITE_SETTINGS['site_tagline'])) $SITE_TAGLINE = $SITE_SETTINGS['site_tagline'];
if (!empty($SITE_SETTINGS['site_logo']))    $SITE_LOGO    = $SITE_SETTINGS['site_logo'];
$SUPPORT_LINK = !empty($SITE_SETTINGS['support_link']) ? $SITE_SETTINGS['support_link'] : 'https://t.me/YOUR_TELEGRAM_USERNAME';

// সাইটের নাম দুই রঙে দেখানো: "SNZPay" → SNZ + (নীল)Pay, "My Shop" → My + (নীল)Shop
if (!function_exists('snzBrandHtml')) {
    function snzBrandHtml($name) {
        $name = trim((string) $name);
        if (preg_match('/^(.+?)(pay)$/i', $name, $m)) {
            return htmlspecialchars($m[1]) . '<span class="text-[#00D9FF]">' . htmlspecialchars($m[2]) . '</span>';
        }
        $pos = strrpos($name, ' ');
        if ($pos !== false) {
            return htmlspecialchars(substr($name, 0, $pos)) . ' <span class="text-[#00D9FF]">' . htmlspecialchars(substr($name, $pos + 1)) . '</span>';
        }
        return htmlspecialchars($name);
    }
}


// Logout সবার আগে (আগে পুরো পেজের সব কাজ শেষ করে তারপর logout হত)
if (isset($_GET['logout'])) {
    $_SESSION = [];
    session_destroy();
    header("Location: index.php");
    exit;
}

if (!isset($_SESSION['user_id'])) {
    if (isset($_GET['ajax_live_feed'])) {
        http_response_code(401);
        header('Content-Type: application/json; charset=UTF-8');
        echo json_encode(['error' => 'logged_out']);
        exit;
    }
    header("Location: index.php");
    exit;
}

$userId = (int) $_SESSION['user_id'];

// CSRF টোকেন — অন্য সাইট থেকে ইউজারের হয়ে ফর্ম সাবমিট ঠেকাতে
if (empty($_SESSION['csrf'])) {
    $_SESSION['csrf'] = bin2hex(random_bytes(16));
}
$CSRF = $_SESSION['csrf'];

// Live feed এর সময় session lock ছেড়ে দেওয়া — না হলে প্রতি ৩.৫ সেকেন্ডের AJAX পুরো সাইট আটকে রাখত (lag এর বড় কারণ)
if (isset($_GET['ajax_live_feed'])) {
    $lastBinanceSync = (int) ($_SESSION['last_binance_sync'] ?? 0);
    $doBinanceSync = (time() - $lastBinanceSync) >= 30;   // Binance API প্রতি ৩.৫ সেকেন্ডে না, ৩০ সেকেন্ডে একবার
    if ($doBinanceSync) $_SESSION['last_binance_sync'] = time();
    session_write_close();
}

$pdo = getDBConnection();
if (!$pdo) {
    if (function_exists('snz_log_error')) snz_log_error('web', 'Database connection failed', 'Database connection error — could not connect to MySQL with the details in config.php', '', ['where' => basename(__FILE__)]);
    die("Database Connection Error! Please check config.php");
}

// Auto-healing Database Columns — আগে প্রতিটা পেজ লোড + প্রতিটা AJAX এ ৬টা ALTER TABLE চলত (টেবিল লক → ভয়াবহ lag)
// এখন শুধু একবার চলে
$healFlag = __DIR__ . '/.db_heal_v2';
if (!is_file($healFlag)) {
    $healSql = [
        "ALTER TABLE `user_wallets` ADD COLUMN `api_key` TEXT NULL",
        "ALTER TABLE `user_wallets` ADD COLUMN `secret_key` TEXT NULL",
        "ALTER TABLE `user_wallets` ADD COLUMN `qr_image` VARCHAR(255) NULL",
        "ALTER TABLE `user_wallets` ADD COLUMN `dollar_rate` DECIMAL(10,2) DEFAULT 125.00",
        "ALTER TABLE `user_wallets` ADD COLUMN `category` VARCHAR(32) NULL",
        "ALTER TABLE `user_wallets` ADD COLUMN `account_type` VARCHAR(32) NULL",
        "ALTER TABLE `user_wallets` ADD COLUMN `account_number` VARCHAR(128) NULL",
        "ALTER TABLE `user_wallets` ADD COLUMN `account_name` VARCHAR(255) NULL",
        "ALTER TABLE `user_wallets` ADD COLUMN `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP",
        "ALTER TABLE `payments` ADD COLUMN `user_id` INT NULL",
        "ALTER TABLE `payments` ADD COLUMN `used_at` DATETIME NULL",
        "ALTER TABLE `users` ADD COLUMN `has_used_trial` TINYINT(1) NOT NULL DEFAULT 0",
        "ALTER TABLE `brand_keys` ADD COLUMN `brand_name` VARCHAR(128) NULL",
    ];
    foreach ($healSql as $sql) {
        try { $pdo->exec($sql); } catch (Throwable $e) {}
    }
    @file_put_contents($healFlag, date('c'));
}
ensureDatabaseIndexes($pdo);
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
snzEnsureDeviceColumns($pdo);
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
            // একটা প্ল্যান একাধিক ফিল্টারে: "Monthly, 1 Device"
            foreach (explode(',', (string) ($p['category'] ?? '')) as $c) {
                $c = trim($c);
                if ($c !== '' && !in_array($c, $cats, true)) $cats[] = $c;
            }
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
snzEnsurePlanSchema($pdo);

// =========================================================================
// Binance Pay সিঙ্ক (একটা ফাংশনে — live feed আর Add Fund দুই জায়গাতেই লাগে)
// =========================================================================
function syncBinanceOrders(PDO $pdo, $ownerId) {
    if (!function_exists('curl_init')) return;
    try {
        $bWalletStmt = $pdo->prepare("SELECT api_key, secret_key FROM user_wallets WHERE user_id = ? AND provider = 'Binance' AND status = 'active' LIMIT 1");
        $bWalletStmt->execute([$ownerId]);
        $bWallet = $bWalletStmt->fetch();
        if (!$bWallet || empty($bWallet['api_key']) || empty($bWallet['secret_key'])) return;

        $nowMs = (int) round(microtime(true) * 1000);
        $queryString = "startTime=" . ($nowMs - 86400000) . "&recvWindow=60000&timestamp=" . $nowMs;
        $signature = hash_hmac('sha256', $queryString, trim($bWallet['secret_key']));

        $ch = curl_init("https://api.binance.com/sapi/v1/pay/transactions?" . $queryString . "&signature=" . $signature);
        curl_setopt($ch, CURLOPT_HTTPHEADER, ["X-MBX-APIKEY: " . trim($bWallet['api_key'])]);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 3);
        curl_setopt($ch, CURLOPT_TIMEOUT, 4);
        $res = curl_exec($ch);
        curl_close($ch);
        if (!$res) return;

        $json = json_decode($res, true);
        $txList = is_array($json) ? ($json['data'] ?? []) : [];
        if (!is_array($txList)) return;

        $chk = $pdo->prepare("SELECT id FROM payments WHERE trx_id = ? LIMIT 1");
        $ins = $pdo->prepare("INSERT INTO payments (trx_id, amount, method, sender, raw_sms, user_id, status, created_at) VALUES (?, ?, 'BINANCE', ?, ?, ?, 'pending', FROM_UNIXTIME(? / 1000))");
        foreach ($txList as $tx) {
            $orderId = strtoupper(trim((string) ($tx['orderId'] ?? ($tx['transactionId'] ?? ''))));
            $amount = parseAmount($tx['amount'] ?? 0);
            if ((float) ($tx['amount'] ?? 0) < 0) continue;   // পাঠানো (outgoing) পেমেন্ট বাদ
            $payer = $tx['payerInfo']['name'] ?? ($tx['payerInfo']['binanceId'] ?? 'Binance User');
            $txTime = intval($tx['orderTime'] ?? ($tx['transactionTime'] ?? $nowMs));

            if ($orderId !== '' && $amount > 0) {
                $chk->execute([$orderId]);
                if (!$chk->fetchColumn()) {
                    try {
                        $ins->execute([$orderId, $amount, $payer, "Binance Pay: $amount USDT from $payer", $ownerId, $txTime]);
                    } catch (Throwable $e) {}
                }
            }
        }
    } catch (Throwable $e) {}
}

// =========================================================================
// Live metrics — আগে ৫টা আলাদা query (DATE() দিয়ে, ইনডেক্স ছাড়া) → এখন ১টা দ্রুত query
// =========================================================================
function getLiveMetrics(PDO $pdo, $userId) {
    $q = $pdo->prepare("SELECT
            COALESCE(SUM(CASE WHEN status = 'used' AND used_at >= CURDATE() THEN amount ELSE 0 END), 0) AS today_deposit,
            COALESCE(SUM(CASE WHEN status = 'used' AND used_at >= CURDATE() THEN 1 ELSE 0 END), 0) AS approved_count,
            COALESCE(SUM(CASE WHEN status = 'used' THEN amount ELSE 0 END), 0) AS lifetime_deposit,
            COALESCE(SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END), 0) AS pending_count,
            COALESCE(SUM(CASE WHEN status = 'pending' THEN amount ELSE 0 END), 0) AS pending_amount
        FROM payments WHERE user_id = ?");
    $q->execute([$userId]);
    $r = $q->fetch() ?: [];

    $sStmt = $pdo->prepare("SELECT id, trx_id, amount, method, sender, created_at FROM payments WHERE user_id = ? AND status = 'pending' AND created_at >= CURDATE() ORDER BY id DESC LIMIT 100");
    $sStmt->execute([$userId]);

    return [
        'today_deposit'    => floatval($r['today_deposit'] ?? 0),
        'approved_count'   => intval($r['approved_count'] ?? 0),
        'lifetime_deposit' => floatval($r['lifetime_deposit'] ?? 0),
        'pending_count'    => intval($r['pending_count'] ?? 0),
        'pending_amount'   => floatval($r['pending_amount'] ?? 0),
        'pending_sms'      => $sStmt->fetchAll(),
    ];
}

// =========================================================================
// SILENT REAL-TIME AUTO-FEED JSON ENDPOINT (NO PAGE RELOAD REQUIRED)
// =========================================================================
if (isset($_GET['ajax_live_feed'])) {
    header('Content-Type: application/json; charset=UTF-8');
    header('Cache-Control: no-store');

    if ($doBinanceSync) {
        syncBinanceOrders($pdo, $userId);
    }

    $m = getLiveMetrics($pdo, $userId);
    $pendingSms = array_map(function ($r) {
        $r['time_fmt'] = date("h:i A", strtotime($r['created_at']));
        return $r;
    }, $m['pending_sms']);

    echo json_encode([
        'today_deposit' => number_format($m['today_deposit'], 2),
        'pending_count' => $m['pending_count'],
        'pending_amount' => number_format($m['pending_amount'], 2),
        'approved_count' => $m['approved_count'],
        'lifetime_deposit' => number_format($m['lifetime_deposit'], 2),
        'pending_sms' => $pendingSms
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

// Fetch user data
$uStmt = $pdo->prepare("SELECT * FROM users WHERE id = ?");
$uStmt->execute([$userId]);
$user = $uStmt->fetch();
if (!$user) {
    // ইউজার ডিলিট হয়ে গেলে আগে পুরো পেজে error আসত
    $_SESSION = [];
    session_destroy();
    header("Location: index.php");
    exit;
}

$msg = "";
$error = "";
$activeTabTarget = "view-dashboard";
$modalData = null;

// Dynamic Public API Endpoint
// Cloudflare / proxy এর পেছনে থাকলেও https ঠিকমতো ধরবে।
// আগে http:// URL দেখাত → ব্র্যান্ড POST করলে https এ redirect হয়ে body হারিয়ে যেত → "Failed Transaction"
$isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
    || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https')
    || (($_SERVER['SERVER_PORT'] ?? '') == 443)
    || (stripos($_SERVER['HTTP_CF_VISITOR'] ?? '', 'https') !== false);
$siteHost = ($isHttps ? "https://" : "http://") . ($_SERVER['HTTP_HOST'] ?? 'localhost');
$publicApiUrl = $siteHost . rtrim(dirname($_SERVER['SCRIPT_NAME']), '/') . '/api/verify_payment.php';

// Notice Banner Text
$noticeFile = __DIR__ . '/notice.txt';
if (!file_exists($noticeFile)) {
    @file_put_contents($noticeFile, "⚡ Welcome to " . $SITE_NAME . "! Automated Payment Gateway & SMS Verifier. Add funds and activate your license plan to connect bots and websites.");
}
$noticeText = file_exists($noticeFile) ? trim(file_get_contents($noticeFile)) : "Welcome to SNZPay!";

// Admin Bangla QR Image & Payment Numbers
$botSettingsFile = __DIR__ . '/bot_settings.json';
$adminSettings = file_exists($botSettingsFile) ? json_decode(file_get_contents($botSettingsFile), true) : [];
$banglaQrImage = $adminSettings['BANGLA_QR_IMAGE'] ?? 'https://i.ibb.co.com/zHs2jjw3/app-logo-png.png';
// 💳 Admin → Payment Setup এর মেথডগুলো (ON থাকা গুলোই ইউজার দেখবে)
require_once __DIR__ . '/snz_paylib.php';
$bkashNumber = ($PAYMENT_INFO['bkash'] ?? '') !== '' ? $PAYMENT_INFO['bkash'] : ($adminSettings['BKASH'] ?? '');
$binancePayId = $PAYMENT_INFO['binance'] ?? '';
$fundMethods = [];
foreach (snz_pay_methods(is_array($PAYMENT_INFO ?? null) ? $PAYMENT_INFO : [], is_array($adminSettings) ? $adminSettings : []) as $pm) {
    if (!$pm['enabled']) continue;
    $pm['action'] = snz_pay_action($pm);
    $pm['type_label'] = snz_pay_type_label($pm);
    $pm['logo_html'] = snz_pay_logo_html($pm, 46);
    if ($pm['kind'] === 'crypto' && $pm['min'] < $MIN_BINANCE_USDT) $pm['min'] = $MIN_BINANCE_USDT;
    $fundMethods[$pm['id']] = $pm;
}

// সব POST অ্যাকশনে CSRF যাচাই
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !hash_equals($CSRF, (string) ($_POST['csrf'] ?? ''))) {
    $error = "Session expired. Please try again.";
    $_POST = [];
}

// =========================================================================
// 1. Delete & Clear Pending SMS
// =========================================================================
if (isset($_POST['action_delete_pending_sms'])) {
    $delId = intval($_POST['delete_payment_id']);
    $pdo->prepare("DELETE FROM payments WHERE id = ? AND user_id = ? AND status = 'pending'")->execute([$delId, $userId]);
    $msg = "SMS removed successfully!";
    $activeTabTarget = "view-dashboard";
}

if (isset($_POST['action_clear_all_fake_sms'])) {
    $pdo->prepare("DELETE FROM payments WHERE user_id = ? AND status = 'pending'")->execute([$userId]);
    $msg = "All your pending transactions cleared!";
    $activeTabTarget = "view-dashboard";
}

// =========================================================================
// 2. Add Fund Processing (Supports bKash, Nagad, Rocket, Bangla QR, Binance)
// =========================================================================
if (isset($_POST['action_add_fund'])) {
    $activeTabTarget = "view-add-fund";
    $amount = floatval(str_replace(',', '', (string)($_POST['deposit_amount'] ?? 0)));
    $trxInput = strtoupper(trim($_POST['trx_id'] ?? ''));
    $providerId = strtolower(trim($_POST['provider'] ?? ''));
    $selMethod = $fundMethods[$providerId] ?? null;
    // ক্রিপ্টো (Binance) হলে USDT → BDT কনভার্সন
    $isCryptoSel = $selMethod ? $selMethod['kind'] === 'crypto' : ($providerId === 'binance');
    $provider = $isCryptoSel ? 'binance' : $providerId;
    $providerLabel = mb_substr($selMethod['name'] ?? $providerId, 0, 30);
    $minAmt = $selMethod ? (float) $selMethod['min'] : ($isCryptoSel ? $MIN_BINANCE_USDT : 10);

    if (!$selMethod) {
        $error = "Please choose a payment method.";
    } elseif ($amount < $minAmt) {
        $error = "Minimum deposit for " . $selMethod['name'] . " is " . ($isCryptoSel ? $minAmt . " USDT" : "৳" . $minAmt) . "!";
    } elseif (empty($trxInput)) {
        $error = "Please provide a valid Transaction ID or Last 4 Digits!";
    } else {
        $payment = null;
        // ইউজার টাকা পাঠায় অ্যাডমিনের নাম্বারে → তাই অ্যাডমিনের অ্যাপে আসা SMS থেকে খুঁজতে হবে।
        // আগে নিজের (user_id = নিজে) পেমেন্ট খুঁজত → নিজের কাস্টমারের টাকা বা Custom SMS দিয়ে ফ্রি ব্যালেন্স নেওয়া যেত।
        // কোন অ্যাকাউন্টের অ্যাপে আসা SMS দিয়ে সাইটের ডিপোজিট মিলবে (Admin → Payment Setup)
        $fundOwnerId = MASTER_USER_ID;
        $appSetFile = __DIR__ . '/app_settings.json';
        $appSetTmp = is_file($appSetFile) ? (json_decode((string) @file_get_contents($appSetFile), true) ?: []) : [];
        if (!empty($appSetTmp['site_receiver_user_id'])) $fundOwnerId = (int) $appSetTmp['site_receiver_user_id'];
        $cleanTrx = preg_replace('/[^A-Z0-9]/', '', $trxInput);

        // ⚡ Auto Pay (Admin → Auto Pay): Brand Key বসানো থাকলে সেই কী এর মালিকের অ্যাপের SMS দিয়ে verify
        $autoPayOn = !isset($appSetTmp['autopay_enabled']) || !empty($appSetTmp['autopay_enabled']);
        $autoPayKeyId = 0;
        if ($autoPayOn && !empty($appSetTmp['autopay_brand_key'])) {
            $apk = $pdo->prepare("SELECT id, user_id FROM brand_keys WHERE api_key = ? AND status != 'revoked' LIMIT 1");
            $apk->execute([strtoupper(trim($appSetTmp['autopay_brand_key']))]);
            if (($apkRow = $apk->fetch()) && snzActivePlanExpiry($pdo, (int) $apkRow['user_id'])) {
                $fundOwnerId = (int) $apkRow['user_id'];
                $autoPayKeyId = (int) $apkRow['id'];
            } else {
                $autoPayOn = false;   // কী ভুল / প্ল্যান শেষ → অ্যাডমিনের কাছে রিকোয়েস্ট যাবে
            }
        }

        // এই TrxID আগে অ্যাডমিন অনুমোদন করে থাকলে
        $doneReq = $pdo->prepare("SELECT status FROM deposit_requests WHERE trx_id = ? AND status = 'approved' LIMIT 1");
        $doneReq->execute([$cleanTrx]);
        $alreadyApproved = (bool) $doneReq->fetchColumn();

        // A. Match TrxID directly
        $chk = $pdo->prepare("SELECT * FROM payments WHERE trx_id IN (?, ?) AND user_id = ? ORDER BY (status = 'pending') DESC LIMIT 1");
        $chk->execute([$trxInput, $cleanTrx, $fundOwnerId]);
        $payment = $chk->fetch();

        // A2. Binance হলে এবং না পেলে — অ্যাডমিনের Binance থেকে লাইভ সিঙ্ক করে আবার খোঁজা
        if (!$payment && $provider === 'binance') {
            syncBinanceOrders($pdo, $fundOwnerId);
            $chk->execute([$trxInput, $cleanTrx, $fundOwnerId]);
            $payment = $chk->fetch();
        }

        // B. ফোন নাম্বার / শেষ ৪ ডিজিট দিয়ে খোঁজা — শুধু যখন ইনপুটটা আসলেই নাম্বার
        // (আগে যেকোনো TrxID এর ভেতরের সংখ্যা দিয়ে অন্যের পেমেন্ট মিলে যেত)
        if (!$payment && $provider !== 'binance' && preg_match('/^\+?[0-9\s-]{3,15}$/', $trxInput)) {
            $digits = preg_replace('/[^0-9]/', '', $trxInput);
            $suffix = strlen($digits) >= 10 ? substr($digits, -10) : substr($digits, -4);
            $chkPhone = $pdo->prepare("SELECT * FROM payments WHERE status = 'pending' AND user_id = ? AND sender LIKE ? AND UPPER(method) NOT LIKE '%BINANCE%' AND amount >= ? ORDER BY id DESC LIMIT 1");
            $chkPhone->execute([$fundOwnerId, '%' . $suffix, $amount - 0.01]);
            $payment = $chkPhone->fetch();
        }

        if ($alreadyApproved) {
            $payment = null;
            $error = "This Transaction ID has already been verified and used!";
        } elseif (!$autoPayOn) {
            // Auto Pay বন্ধ → সরাসরি অ্যাডমিনের কাছে রিকোয়েস্ট
            $payment = null;
            $pdo->prepare("INSERT INTO deposit_requests (user_id, trx_id, provider, amount, attempts, last_error, status, created_at, updated_at)
                           VALUES (?, ?, ?, ?, 1, 'Auto Pay is off', 'review', NOW(), NOW())
                           ON DUPLICATE KEY UPDATE amount = VALUES(amount), provider = VALUES(provider), attempts = attempts + 1,
                           status = IF(status IN ('approved','rejected'), status, 'review'), updated_at = NOW()")
                ->execute([$userId, $cleanTrx, $providerLabel, $amount]);
            $msg = "Your deposit request (TrxID: {$cleanTrx}) was sent to admin. Balance will be added after approval.";
        }

        if ($alreadyApproved || !$autoPayOn) {
            // উপরে মেসেজ সেট হয়েছে
        } elseif (!$payment) {
            $error = "Payment record not found in database! Please ensure your phone app has synced the SMS.";
        } elseif ($payment['status'] === 'used') {
            $error = "This Transaction ID has already been verified and used!";
        } else {
            $receivedAmount = floatval($payment['amount']);
            // কনভার্সন নির্ভর করবে আসল পেমেন্টের মেথডের উপর, ইউজার কোনটা সিলেক্ট করল তার উপর না
            // (আগে bKash TrxID দিয়ে "Binance" সিলেক্ট করলে টাকা ১২৫ গুণ হয়ে যেত!)
            $isBinancePayment = stripos((string) $payment['method'], 'BINANCE') !== false;

            if ($isBinancePayment !== ($provider === 'binance')) {
                $error = "Payment method mismatch! This transaction is " . strtoupper($payment['method']) . ".";
            } elseif ($receivedAmount + 0.0001 < $amount) {
                $error = $isBinancePayment
                    ? "Amount mismatch! Verified amount in database: " . $receivedAmount . " USDT"
                    : "Amount mismatch! Verified amount in database: ৳" . $receivedAmount;
            } else {
                // Atomic: একসাথে দুইবার সাবমিট করলেও একবারই ক্রেডিট হবে
                $mark = $pdo->prepare("UPDATE payments SET status = 'used', used_by = ?, used_at = NOW(), claimed_via = 'Web Add Fund (Auto Pay)' WHERE id = ? AND status <> 'used'");
                try {
                    $mark->execute([$user['email'], $payment['id']]);
                } catch (Throwable $e) {
                    // used_by কলাম INT হলে
                    $mark->execute([$userId, $payment['id']]);
                }

                if ($mark->rowCount() === 0) {
                    $error = "This Transaction ID has already been verified and used!";
                } else {
                    $bdtCredited = $isBinancePayment ? round($receivedAmount * $USDT_RATE, 2) : $receivedAmount;
                    // balance = balance + ? → একসাথে দুই রিকোয়েস্টেও ব্যালেন্স হারাবে না
                    $pdo->prepare("UPDATE users SET balance = balance + ? WHERE id = ?")->execute([$bdtCredited, $userId]);
                    $user['balance'] = floatval($user['balance']) + $bdtCredited;

                    $msg = $isBinancePayment
                        ? "$receivedAmount USDT verified! Added ৳" . number_format($bdtCredited, 2) . " BDT to your balance."
                        : "৳" . number_format($bdtCredited, 2) . " BDT successfully credited to your wallet!";

                    if ($autoPayKeyId) {
                        $pdo->prepare("UPDATE brand_keys SET last_used_at = NOW(), is_connected = 1 WHERE id = ?")->execute([$autoPayKeyId]);
                    }
                    $pdo->prepare("UPDATE deposit_requests SET status = 'approved', credited_amount = ?, admin_note = 'Auto Pay', updated_at = NOW() WHERE user_id = ? AND trx_id = ?")
                        ->execute([$bdtCredited, $userId, $cleanTrx]);

                    // ⚡ প্ল্যান কেনার জন্য টাকা দিলে → এখনই প্ল্যান চালু
                    $pendingPlanId = intval($_POST['pending_plan_id'] ?? 0);
                    if ($pendingPlanId > 0) {
                        $_POST['action_buy_plan'] = 1;
                        $_POST['plan_id'] = $pendingPlanId;
                        $fundMsgBefore = $msg;
                    }
                }
            }
        }

        // ❌ Auto Pay তে ব্যর্থ → চেষ্টা গোনা; ৩ বার হলে অ্যাডমিন প্যানেলে যাবে
        if ($error && $autoPayOn && !$alreadyApproved && $cleanTrx !== '') {
            $pdo->prepare("INSERT INTO deposit_requests (user_id, trx_id, provider, amount, attempts, last_error, status, created_at, updated_at)
                           VALUES (?, ?, ?, ?, 1, ?, 'trying', NOW(), NOW())
                           ON DUPLICATE KEY UPDATE attempts = attempts + 1, last_error = VALUES(last_error), amount = VALUES(amount), provider = VALUES(provider),
                           status = IF(status IN ('approved','rejected','review'), status, IF(attempts >= 3, 'review', 'trying')), updated_at = NOW()")
                ->execute([$userId, $cleanTrx, $providerLabel, $amount, mb_substr($error, 0, 250)]);
            $att = $pdo->prepare("SELECT attempts, status FROM deposit_requests WHERE user_id = ? AND trx_id = ?");
            $att->execute([$userId, $cleanTrx]);
            $attRow = $att->fetch();
            if ($attRow && $attRow['status'] === 'review') {
                $error = "We could not verify this TrxID after 3 tries. It has been sent to admin for manual review.";
            } elseif ($attRow && $attRow['status'] === 'rejected') {
                $error = "This TrxID was rejected by admin.";
            } elseif ($attRow) {
                $error .= " (Attempt " . (int) $attRow['attempts'] . "/3)";
            }
        }
    }
}

// =========================================================================
// 3. Buy Plan & Auto-Expiry Lifecycle
// =========================================================================
if (isset($_POST['action_buy_plan'])) {
    $activeTabTarget = "view-plans";
    $buyPlan = snzGetPlan($pdo, intval($_POST['plan_id'] ?? 0));

    if (!$buyPlan || $buyPlan['status'] !== 'active') {
        $error = "This plan is not available.";
    } else {
        $price = snzPlanFinalPrice($buyPlan);
        $hours = max(1, (int) $buyPlan['duration_hours']);
        $planName = $buyPlan['name'];
        $canCreate = false;

        if (!empty($buyPlan['is_trial']) || $price <= 0) {
            // ফ্রি ট্রায়াল — প্রতি ইউজার একবারই (Atomic)
            $trialUpd = $pdo->prepare("UPDATE users SET has_used_trial = 1 WHERE id = ? AND (has_used_trial = 0 OR has_used_trial IS NULL)");
            $trialUpd->execute([$userId]);
            if ($trialUpd->rowCount() === 0) {
                $modalData = [
                    "title" => "Free Trial Already Used",
                    "message" => "You have already enjoyed your free trial access. Please select a paid plan to continue.",
                    "type" => "warning",
                    "btn_text" => "Choose Plan",
                    "btn_action" => "switchView('view-plans')"
                ];
            } else {
                $user['has_used_trial'] = 1;
                $canCreate = true;
            }
        } else {
            // Atomic: ব্যালেন্স থাকলেই কাটবে (ডাবল ক্লিকে নেগেটিভ ব্যালেন্স হবে না)
            $deduct = $pdo->prepare("UPDATE users SET balance = balance - ? WHERE id = ? AND balance >= ?");
            $deduct->execute([$price, $userId, $price]);
            if ($deduct->rowCount() === 0) {
                $shortfall = max(0, $price - floatval($user['balance']));
                $modalData = [
                    "title" => "Insufficient Balance",
                    "message" => "Your wallet balance is ৳ " . number_format(floatval($user['balance']), 2) . ". You need ৳ " . number_format($price, 2) . " to activate this plan. Pay the rest now and the plan activates instantly.",
                    "type" => "balance",
                    "btn_text" => "⚡ Pay ৳" . number_format($shortfall, 2) . " & Activate",
                    "btn_action" => "startPlanPay(" . (int) $buyPlan['id'] . ", " . json_encode((string) $planName, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT) . ", " . round($price, 2) . ")"
                ];
            } else {
                $user['balance'] = floatval($user['balance']) - $price;
                $canCreate = true;
            }
        }

        if ($canCreate) {
            $licenseKey = generateLicenseKey();
            $expiresAt = date("Y-m-d H:i:s", time() + $hours * 3600);
            $pdo->prepare("INSERT INTO user_keys (user_id, license_key, plan_name, expires_at, status, plan_id, max_devices, max_brand_keys) VALUES (?, ?, ?, ?, 'active', ?, ?, ?)")
                ->execute([$userId, $licenseKey, $planName, $expiresAt, $buyPlan['id'], max(1, (int) $buyPlan['max_devices']), max(0, (int) $buyPlan['max_brand_keys'])]);
            $msg = (!empty($fundMsgBefore) ? $fundMsgBefore . " " : "") . "Plan activated: $licenseKey ($planName)";
            $activeTabTarget = "view-my-plan";
        }
    }
}

if (isset($_POST['action_manual_renew'])) {
    $activeTabTarget = "view-my-plan";
    $renewKeyId = intval($_POST['renew_key_id']);
    $rStmt = $pdo->prepare("SELECT * FROM user_keys WHERE id = ? AND user_id = ?");
    $rStmt->execute([$renewKeyId, $userId]);
    $targetKey = $rStmt->fetch();

    if ($targetKey) {
        $planDetails = getPlanDetails($targetKey['plan_name']);
        $renewPlan = !empty($targetKey['plan_id']) ? snzGetPlan($pdo, $targetKey['plan_id']) : null;
        if ($renewPlan) {
            $planDetails = ['price' => !empty($renewPlan['is_trial']) ? 0 : snzPlanFinalPrice($renewPlan), 'hours' => (int) $renewPlan['duration_hours'], 'name' => $renewPlan['name']];
        }
        $renewCost = $planDetails['price'];

        $renewOk = true;
        if ($renewCost <= 0) {
            // আগে Free Trial কী বারবার ফ্রিতে Renew করা যেত
            $renewOk = false;
            $modalData = [
                "title" => "Free Trial Cannot Be Renewed",
                "message" => "Free trial is a one-time offer. Please choose a paid plan to continue.",
                "type" => "warning",
                "btn_text" => "Choose Plan",
                "btn_action" => "switchView('view-plans')"
            ];
        } else {
            $deduct = $pdo->prepare("UPDATE users SET balance = balance - ? WHERE id = ? AND balance >= ?");
            $deduct->execute([$renewCost, $userId, $renewCost]);
            $renewOk = $deduct->rowCount() > 0;
        }

        if (!$renewOk && $renewCost > 0) {
            $modalData = [
                "title" => "Insufficient Balance for Renewal",
                "message" => "You need ৳ " . number_format($renewCost, 2) . " to renew this plan. Please add funds.",
                "type" => "balance",
                "btn_text" => "+ Add Funds",
                "btn_action" => "switchView('view-add-fund')"
            ];
        } elseif ($renewOk) {
            $user['balance'] = floatval($user['balance']) - $renewCost;
            $newExpiresAt = date("Y-m-d H:i:s", time() + ($planDetails['hours'] * 3600));
            $pdo->prepare("UPDATE user_keys SET expires_at = ?, status = 'active' WHERE id = ? AND user_id = ?")->execute([$newExpiresAt, $renewKeyId, $userId]);
            if ($renewPlan) {
                $pdo->prepare("UPDATE user_keys SET max_devices = ?, max_brand_keys = ? WHERE id = ? AND user_id = ?")
                    ->execute([max(1, (int) $renewPlan['max_devices']), max(0, (int) $renewPlan['max_brand_keys']), $renewKeyId, $userId]);
            }
            $msg = "Plan renewed successfully! Key is now Active.";
        }
    }
}

// =========================================================================
// 4. Custom SMS Inserter
// =========================================================================
if (isset($_POST['action_add_custom_sms'])) {
    $activeTabTarget = "view-custom-sms";
    $rawSms = trim($_POST['custom_sms_text'] ?? '');
    $selectedProvider = trim($_POST['custom_provider'] ?? 'BKASH');

    if (empty($rawSms)) {
        $error = "Please paste the received SMS text!";
    } else {
        $trxId = ""; $amount = 0.0; $sender = "";

        if (preg_match('/TrxID\s+([A-Za-z0-9]+)/i', $rawSms, $m)) $trxId = $m[1];
        if (empty($trxId) && preg_match('/TxnID:\s*([A-Za-z0-9]+)/i', $rawSms, $m)) $trxId = $m[1];
        if (empty($trxId) && preg_match('/TxnId:([A-Za-z0-9]+)/i', $rawSms, $m)) $trxId = $m[1];

        if (preg_match('/Tk\s*([\d,.]+)/i', $rawSms, $m)) $amount = parseAmount($m[1]);
        if ($amount <= 0 && preg_match('/Amount:\s*Tk\s*([\d,.]+)/i', $rawSms, $m)) $amount = floatval(str_replace(',', '', $m[1]));
        if ($amount <= 0 && preg_match('/Tk([\d,.]+)\s*received/i', $rawSms, $m)) $amount = floatval(str_replace(',', '', $m[1]));

        if (preg_match('/from\s*([0-9*]+)/i', $rawSms, $m)) $sender = $m[1];
        if (empty($sender) && preg_match('/Sender:\s*([0-9*]+)/i', $rawSms, $m)) $sender = $m[1];

        // অন্য যেকোনো ব্যাংক/MFS এর SMS এর জন্য সাধারণ নিয়ম
        if (empty($trxId) && preg_match('/(?:Trx\s*ID|Txn\s*ID|Transaction\s*ID|Ref(?:erence)?(?:\s*No)?)\s*[:#.]?\s*([A-Za-z0-9]{4,})/i', $rawSms, $m)) $trxId = $m[1];
        if ($amount <= 0 && preg_match('/(?:Tk|BDT|৳)\.?\s*([\d,]+(?:\.\d+)?)/iu', $rawSms, $m)) $amount = parseAmount($m[1]);
        if (empty($sender) && preg_match('/(?:A\/C|Account)\s*[:#]?\s*([*0-9]{3,})/i', $rawSms, $m)) $sender = $m[1];

        $trxId = strtoupper($trxId);
        // শুধু নিজের Wallet Setup এ যোগ করা প্রোভাইডার
        $wantedCode = strtoupper(preg_replace('/[^A-Za-z0-9]+/', '_', $selectedProvider));
        $allowedCodes = [];
        try {
            $wq = $pdo->prepare("SELECT provider FROM user_wallets WHERE user_id = ?");
            $wq->execute([$userId]);
            foreach ($wq->fetchAll(PDO::FETCH_COLUMN) as $pv) {
                if (strcasecmp(trim($pv), 'Binance') !== 0) $allowedCodes[] = strtoupper(preg_replace('/[^A-Za-z0-9]+/', '_', trim($pv)));
            }
        } catch (Throwable $e) {}
        $selectedProvider = $wantedCode;

        if (empty($allowedCodes)) {
            $error = "Please add a wallet in Wallet Setup first (bKash, Nagad, etc).";
        } elseif (!in_array($wantedCode, $allowedCodes, true)) {
            $error = "Please select one of your own wallets.";
        } elseif (empty($trxId) || $amount <= 0) {
            $error = "Could not parse TrxID or Amount from this SMS.";
        } else {
            $dupChk = $pdo->prepare("SELECT id FROM payments WHERE trx_id = ? LIMIT 1");
            $dupChk->execute([$trxId]);
            if ($dupChk->fetchColumn()) {
                $error = "Transaction ID ({$trxId}) already exists in database!";
            } else try {
                $stmt = $pdo->prepare("INSERT INTO payments (trx_id, amount, method, sender, raw_sms, user_id, status, created_at) VALUES (?, ?, ?, ?, ?, ?, 'pending', NOW())");
                $stmt->execute([$trxId, $amount, strtoupper($selectedProvider), $sender ?: 'N/A', $rawSms, $userId]);
                $msg = "Custom SMS added! TrxID: {$trxId} (৳{$amount}) is now in pending verification.";
                $activeTabTarget = "view-dashboard";
            } catch (Throwable $e) {
                $error = "Transaction ID ({$trxId}) already exists in database!";
            }
        }
    }
}

// =========================================================================
// 5. Brands / Bot Key Generator
// =========================================================================
// প্ল্যান অনুযায়ী যতগুলো Brand Key (যেমন ৫টা কী এর প্ল্যান হলে ৫টা)
if (isset($_POST['action_generate_brand_key'])) {
    $activeTabTarget = "view-brands";
    $quota = snzBrandQuota($pdo, $userId);
    $brandName = mb_substr(trim($_POST['brand_name'] ?? ''), 0, 100);
    $brandType = in_array($_POST['brand_type'] ?? '', ['website', 'app', 'bot'], true) ? $_POST['brand_type'] : '';
    $brandLink = mb_substr(trim($_POST['brand_link'] ?? ''), 0, 255);
    $whatsapp = preg_replace('/[^0-9+]/', '', (string) ($_POST['brand_whatsapp'] ?? ''));

    if ($quota['limit'] <= 0) {
        $modalData = [
            "title" => "No Active Plan Found",
            "message" => "You must purchase an active subscription plan first before creating a Brand Key.",
            "type" => "warning",
            "btn_text" => "View Plans",
            "btn_action" => "switchView('view-plans')"
        ];
    } elseif ($quota['used'] >= $quota['limit']) {
        $modalData = [
            "title" => "Brand Key Limit Reached",
            "message" => "Your plan allows " . $quota['limit'] . " brand key(s) and you have used all of them. Revoke an old key or buy a bigger plan.",
            "type" => "warning",
            "btn_text" => "View Plans",
            "btn_action" => "switchView('view-plans')"
        ];
    } elseif ($brandName === '' || $brandType === '' || $brandLink === '' || strlen($whatsapp) < 10) {
        $error = "Please fill Brand Name, Type, Link/Username and a valid WhatsApp number.";
    } else {
        $newBotKey = "SNZ-BOT-" . strtoupper(bin2hex(random_bytes(6)));
        $pdo->prepare("INSERT INTO brand_keys (user_id, brand_name, brand_type, brand_link, whatsapp, api_key, plan_duration_hours, status, created_at) VALUES (?, ?, ?, ?, ?, ?, 720, 'unlinked', NOW())")
            ->execute([$userId, $brandName, $brandType, $brandLink, $whatsapp, $newBotKey]);
        $msg = "Brand key created for {$brandName}! It locks to the first website/app/bot that uses it.";
    }
}

// অন্য জায়গায় লাগানোর জন্য কানেকশন খুলে দেওয়া
if (isset($_POST['action_disconnect_brand_key'])) {
    $activeTabTarget = "view-brands";
    $pdo->prepare("UPDATE brand_keys SET bound_fingerprint = NULL, bound_label = NULL, bound_at = NULL, is_connected = 0 WHERE id = ? AND user_id = ?")
        ->execute([intval($_POST['brand_key_id'] ?? 0), $userId]);
    $msg = "Brand key disconnected. Use it in your new website/app/bot — it will connect there automatically.";
}

if (isset($_POST['action_revoke_brand_key'])) {
    $activeTabTarget = "view-brands";
    $rKeyId = intval($_POST['revoke_key_id']);
    $pdo->prepare("UPDATE brand_keys SET status = 'revoked' WHERE id = ? AND user_id = ?")->execute([$rKeyId, $userId]);
    $msg = "Brand key revoked successfully.";
}

$bkStmt = $pdo->prepare("SELECT * FROM brand_keys WHERE user_id = ? AND status != 'revoked' ORDER BY id DESC");
$bkStmt->execute([$userId]);
$userBrandKeys = $bkStmt->fetchAll();
$userBrandKey = $userBrandKeys[0] ?? null;   // পুরনো কোডের সাথে মিল রাখতে
$brandQuota = snzBrandQuota($pdo, $userId);

// =========================================================================
// 6. Wallet Setup Actions (MFS, Bank, QR Direct Upload, Binance)
// =========================================================================
if (isset($_POST['action_save_wallet'])) {
    $activeTabTarget = "view-wallet-setup";
    $category    = trim($_POST['wallet_category'] ?? 'mfs');
    $provider    = trim($_POST['wallet_provider'] ?? '');
    $accType     = trim($_POST['account_type'] ?? 'Personal');
    $accNumber   = trim($_POST['account_number'] ?? '');
    $accName     = trim($_POST['account_name'] ?? '');
    $apiKey      = trim($_POST['api_key'] ?? '');
    $secretKey   = trim($_POST['secret_key'] ?? '');
    $dollarRate  = floatval($_POST['dollar_rate'] ?? 125.00);
    $status      = isset($_POST['wallet_status']) ? 'active' : 'inactive';
    $qrImagePath = '';

    if ($dollarRate <= 0) $dollarRate = 125.00;

    if ($category === 'qr' && isset($_FILES['qr_file']) && $_FILES['qr_file']['error'] === UPLOAD_ERR_OK) {
        $ext = strtolower(pathinfo($_FILES['qr_file']['name'], PATHINFO_EXTENSION));
        // এক্সটেনশন + আসল ছবি কিনা দুটোই চেক
        if (in_array($ext, ['png', 'jpg', 'jpeg', 'webp']) && @getimagesize($_FILES['qr_file']['tmp_name']) !== false && $_FILES['qr_file']['size'] <= 5 * 1024 * 1024) {
            $uploadDir = __DIR__ . '/uploads/qr/';
            if (!is_dir($uploadDir)) @mkdir($uploadDir, 0755, true);
            $fileName = 'qr_' . $userId . '_' . time() . '.' . $ext;
            if (@move_uploaded_file($_FILES['qr_file']['tmp_name'], $uploadDir . $fileName)) {
                $qrImagePath = 'uploads/qr/' . $fileName;
                if (empty($accNumber)) $accNumber = 'QR Code Ready';
            }
        }
    }

    // একই প্রোভাইডার আগে যোগ করা থাকলে → নতুন না বানিয়ে সেটাই এডিট
    $existingWallet = null;
    $editId = intval($_POST['wallet_id'] ?? 0);
    try {
        if ($editId > 0) {
            $ew = $pdo->prepare("SELECT * FROM user_wallets WHERE id = ? AND user_id = ? LIMIT 1");
            $ew->execute([$editId, $userId]);
        } else {
            $ew = $pdo->prepare("SELECT * FROM user_wallets WHERE user_id = ? AND LOWER(provider) = LOWER(?) ORDER BY id DESC LIMIT 1");
            $ew->execute([$userId, $provider]);
        }
        $existingWallet = $ew->fetch() ?: null;
    } catch (Throwable $e) {}
    if ($existingWallet) {
        // এডিটে খালি রাখলে আগের Secret / QR ছবি থেকে যাবে
        if ($secretKey === '') $secretKey = (string) ($existingWallet['secret_key'] ?? '');
        if ($apiKey === '' && $provider === 'Binance') $apiKey = (string) ($existingWallet['api_key'] ?? '');
        if ($qrImagePath === '' && $category === 'qr') {
            $qrImagePath = (string) ($existingWallet['qr_image'] ?? '');
            if ($accNumber === '' && $qrImagePath !== '') $accNumber = 'QR Code Ready';
        }
    }

    if ($provider === 'Binance' && (empty($apiKey) || empty($secretKey))) {
        $error = "Please provide both Binance API Key and Secret Key!";
    } elseif ($category === 'qr' && empty($qrImagePath) && empty($accNumber)) {
        $error = "Please upload a QR Code image!";
    } elseif ($category !== 'qr' && $provider !== 'Binance' && empty($accNumber) && empty($accName)) {
        $error = "Please provide an Account Number!";
    } else {
        try {
            if ($provider === 'Binance') $accNumber = 'Binance Pay Active';

            if ($existingWallet) {
                $pdo->prepare("UPDATE user_wallets SET category = ?, provider = ?, account_type = ?, account_number = ?, account_name = ?, api_key = ?, secret_key = ?, qr_image = ?, dollar_rate = ?, status = ? WHERE id = ? AND user_id = ?")
                    ->execute([$category, $provider, $accType, $accNumber, $accName, $apiKey, $secretKey, $qrImagePath, $dollarRate, $status, $existingWallet['id'], $userId]);
                // পুরনো ডুপ্লিকেট থাকলে মুছে ফেলা (একটা প্রোভাইডার = একটা wallet)
                $pdo->prepare("DELETE FROM user_wallets WHERE user_id = ? AND LOWER(provider) = LOWER(?) AND id <> ?")
                    ->execute([$userId, $provider, $existingWallet['id']]);
                $msg = ($provider === 'Binance') ? "Binance updated! (Rate: 1 USDT = ৳{$dollarRate} BDT)" : "{$provider} updated successfully!";
            } else {
                $pdo->prepare("INSERT INTO user_wallets (user_id, category, provider, account_type, account_number, account_name, api_key, secret_key, qr_image, dollar_rate, status) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)")
                    ->execute([$userId, $category, $provider, $accType, $accNumber, $accName, $apiKey, $secretKey, $qrImagePath, $dollarRate, $status]);
                $msg = ($provider === 'Binance') ? "Binance Connected! (Rate: 1 USDT = ৳{$dollarRate} BDT)" : "{$provider} saved successfully!";
            }
        } catch (Throwable $e) {
            error_log("dashboard wallet: " . $e->getMessage());
            $error = "Could not save wallet. Please try again.";
        }
    }
}

if (isset($_POST['action_toggle_wallet'])) {
    $activeTabTarget = "view-wallet-setup";
    $walletId = intval($_POST['toggle_wallet_id']);
    $currentSt = ($_POST['current_status'] ?? '') === 'active' ? 'inactive' : 'active';
    $pdo->prepare("UPDATE user_wallets SET status = ? WHERE id = ? AND user_id = ?")->execute([$currentSt, $walletId, $userId]);
    $msg = "Wallet status set to " . strtoupper($currentSt);
}

if (isset($_POST['action_delete_wallet'])) {
    $activeTabTarget = "view-wallet-setup";
    $delId = intval($_POST['delete_wallet_id']);
    $pdo->prepare("DELETE FROM user_wallets WHERE id = ? AND user_id = ?")->execute([$delId, $userId]);
    $msg = "Wallet deleted successfully!";
}

// Fetch Wallets
$userWallets = [];
try {
    $wStmt = $pdo->prepare("SELECT * FROM user_wallets WHERE user_id = ? ORDER BY id DESC");
    $wStmt->execute([$userId]);
    $userWallets = $wStmt->fetchAll();
} catch (Throwable $e) {}

// প্রোভাইডার অনুযায়ী wallet (Wallet Setup এ "Added ✓ / Edit" দেখানোর জন্য)
$walletsByProvider = [];
foreach ($userWallets as $uw) {
    $pk = strtolower(trim((string) $uw['provider']));
    if (!isset($walletsByProvider[$pk])) $walletsByProvider[$pk] = $uw;
}
function snzWalletBadge($name) {
    global $walletsByProvider;
    $w = $walletsByProvider[strtolower($name)] ?? null;
    if (!$w) return '';
    $on = ($w['status'] ?? '') === 'active';
    return '<span class="ml-auto shrink-0 text-[9px] font-black px-1.5 py-0.5 rounded-md ' . ($on ? 'bg-[#00D89A]/20 text-[#00D89A]' : 'bg-slate-700 text-slate-300') . '">' . ($on ? '✓ EDIT' : 'OFF') . '</span>';
}

// Custom SMS এ শুধু ইউজারের যোগ করা wallet গুলো (Binance বাদে — ওটার SMS আসে না)
$customSmsProviders = [];
foreach ($userWallets as $uw) {
    $prov = trim((string) $uw['provider']);
    if ($prov === '' || strcasecmp($prov, 'Binance') === 0) continue;
    $code = strtoupper(preg_replace('/[^A-Za-z0-9]+/', '_', $prov));
    $customSmsProviders[$code] = $prov;
}

$myDepositRequests = [];
try {
    $drq = $pdo->prepare("SELECT trx_id, provider, amount, status, credited_amount, admin_note, updated_at FROM deposit_requests WHERE user_id = ? AND status IN ('review','approved','rejected') ORDER BY id DESC LIMIT 10");
    $drq->execute([$userId]);
    $myDepositRequests = $drq->fetchAll();
} catch (Throwable $e) {}

$allUserKeys = [];
$keyDevicesMap = [];
$allPlans = snzGetPlans($pdo, true);
$activeKeys = [];
$deactivatedKeys = [];
try {
    $kStmt = $pdo->prepare("SELECT * FROM user_keys WHERE user_id = ? ORDER BY id DESC");
    $kStmt->execute([$userId]);
    $allUserKeys = $kStmt->fetchAll();
    $keyDevicesMap = snzKeyDevices($pdo, array_column($allUserKeys, 'id'));

    foreach ($allUserKeys as $k) {
        if (strtotime($k['expires_at']) >= time() && ($k['status'] ?? 'active') === 'active') {
            $activeKeys[] = $k;
        } else {
            $deactivatedKeys[] = $k;
        }
    }
} catch (Throwable $e) {}

// Safe Live Analytics Initial Load
$todayDeposit = 0.0;
$pendingTodayCount = 0;
$pendingTodayAmount = 0.0;
$approvedTodayCount = 0;
$lifetimeDeposit = 0.0;
$todayPendingSms = [];
$allTransactionsHistory = [];

try {
    $m = getLiveMetrics($pdo, $userId);
    $todayDeposit       = $m['today_deposit'];
    $pendingTodayCount  = $m['pending_count'];
    $pendingTodayAmount = $m['pending_amount'];
    $approvedTodayCount = $m['approved_count'];
    $lifetimeDeposit    = $m['lifetime_deposit'];
    $todayPendingSms    = $m['pending_sms'];

    $allHistoryStmt = $pdo->prepare("SELECT id, trx_id, amount, method, sender, status, created_at FROM payments WHERE user_id = ? ORDER BY id DESC LIMIT 150");
    $allHistoryStmt->execute([$userId]);
    $allTransactionsHistory = $allHistoryStmt->fetchAll();
} catch (Throwable $e) {}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title><?= htmlspecialchars($SITE_NAME) ?> - Dashboard</title>

    <!-- Pre-built Tailwind CSS (CDN script এর বদলে — অনেক দ্রুত) -->
    <style>*,:after,:before{--tw-border-spacing-x:0;--tw-border-spacing-y:0;--tw-translate-x:0;--tw-translate-y:0;--tw-rotate:0;--tw-skew-x:0;--tw-skew-y:0;--tw-scale-x:1;--tw-scale-y:1;--tw-pan-x: ;--tw-pan-y: ;--tw-pinch-zoom: ;--tw-scroll-snap-strictness:proximity;--tw-gradient-from-position: ;--tw-gradient-via-position: ;--tw-gradient-to-position: ;--tw-ordinal: ;--tw-slashed-zero: ;--tw-numeric-figure: ;--tw-numeric-spacing: ;--tw-numeric-fraction: ;--tw-ring-inset: ;--tw-ring-offset-width:0px;--tw-ring-offset-color:#fff;--tw-ring-color:rgba(59,130,246,.5);--tw-ring-offset-shadow:0 0 #0000;--tw-ring-shadow:0 0 #0000;--tw-shadow:0 0 #0000;--tw-shadow-colored:0 0 #0000;--tw-blur: ;--tw-brightness: ;--tw-contrast: ;--tw-grayscale: ;--tw-hue-rotate: ;--tw-invert: ;--tw-saturate: ;--tw-sepia: ;--tw-drop-shadow: ;--tw-backdrop-blur: ;--tw-backdrop-brightness: ;--tw-backdrop-contrast: ;--tw-backdrop-grayscale: ;--tw-backdrop-hue-rotate: ;--tw-backdrop-invert: ;--tw-backdrop-opacity: ;--tw-backdrop-saturate: ;--tw-backdrop-sepia: ;--tw-contain-size: ;--tw-contain-layout: ;--tw-contain-paint: ;--tw-contain-style: }::backdrop{--tw-border-spacing-x:0;--tw-border-spacing-y:0;--tw-translate-x:0;--tw-translate-y:0;--tw-rotate:0;--tw-skew-x:0;--tw-skew-y:0;--tw-scale-x:1;--tw-scale-y:1;--tw-pan-x: ;--tw-pan-y: ;--tw-pinch-zoom: ;--tw-scroll-snap-strictness:proximity;--tw-gradient-from-position: ;--tw-gradient-via-position: ;--tw-gradient-to-position: ;--tw-ordinal: ;--tw-slashed-zero: ;--tw-numeric-figure: ;--tw-numeric-spacing: ;--tw-numeric-fraction: ;--tw-ring-inset: ;--tw-ring-offset-width:0px;--tw-ring-offset-color:#fff;--tw-ring-color:rgba(59,130,246,.5);--tw-ring-offset-shadow:0 0 #0000;--tw-ring-shadow:0 0 #0000;--tw-shadow:0 0 #0000;--tw-shadow-colored:0 0 #0000;--tw-blur: ;--tw-brightness: ;--tw-contrast: ;--tw-grayscale: ;--tw-hue-rotate: ;--tw-invert: ;--tw-saturate: ;--tw-sepia: ;--tw-drop-shadow: ;--tw-backdrop-blur: ;--tw-backdrop-brightness: ;--tw-backdrop-contrast: ;--tw-backdrop-grayscale: ;--tw-backdrop-hue-rotate: ;--tw-backdrop-invert: ;--tw-backdrop-opacity: ;--tw-backdrop-saturate: ;--tw-backdrop-sepia: ;--tw-contain-size: ;--tw-contain-layout: ;--tw-contain-paint: ;--tw-contain-style: }/*! tailwindcss v3.4.17 | MIT License | https://tailwindcss.com*/*,:after,:before{box-sizing:border-box;border:0 solid #e5e7eb}:after,:before{--tw-content:""}:host,html{line-height:1.5;-webkit-text-size-adjust:100%;-moz-tab-size:4;-o-tab-size:4;tab-size:4;font-family:ui-sans-serif,system-ui,sans-serif,Apple Color Emoji,Segoe UI Emoji,Segoe UI Symbol,Noto Color Emoji;font-feature-settings:normal;font-variation-settings:normal;-webkit-tap-highlight-color:transparent}body{margin:0;line-height:inherit}hr{height:0;color:inherit;border-top-width:1px}abbr:where([title]){-webkit-text-decoration:underline dotted;text-decoration:underline dotted}h1,h2,h3,h4,h5,h6{font-size:inherit;font-weight:inherit}a{color:inherit;text-decoration:inherit}b,strong{font-weight:bolder}code,kbd,pre,samp{font-family:ui-monospace,SFMono-Regular,Menlo,Monaco,Consolas,Liberation Mono,Courier New,monospace;font-feature-settings:normal;font-variation-settings:normal;font-size:1em}small{font-size:80%}sub,sup{font-size:75%;line-height:0;position:relative;vertical-align:baseline}sub{bottom:-.25em}sup{top:-.5em}table{text-indent:0;border-color:inherit;border-collapse:collapse}button,input,optgroup,select,textarea{font-family:inherit;font-feature-settings:inherit;font-variation-settings:inherit;font-size:100%;font-weight:inherit;line-height:inherit;letter-spacing:inherit;color:inherit;margin:0;padding:0}button,select{text-transform:none}button,input:where([type=button]),input:where([type=reset]),input:where([type=submit]){-webkit-appearance:button;background-color:transparent;background-image:none}:-moz-focusring{outline:auto}:-moz-ui-invalid{box-shadow:none}progress{vertical-align:baseline}::-webkit-inner-spin-button,::-webkit-outer-spin-button{height:auto}[type=search]{-webkit-appearance:textfield;outline-offset:-2px}::-webkit-search-decoration{-webkit-appearance:none}::-webkit-file-upload-button{-webkit-appearance:button;font:inherit}summary{display:list-item}blockquote,dd,dl,figure,h1,h2,h3,h4,h5,h6,hr,p,pre{margin:0}fieldset{margin:0}fieldset,legend{padding:0}menu,ol,ul{list-style:none;margin:0;padding:0}dialog{padding:0}textarea{resize:vertical}input::-moz-placeholder,textarea::-moz-placeholder{opacity:1;color:#9ca3af}input::placeholder,textarea::placeholder{opacity:1;color:#9ca3af}[role=button],button{cursor:pointer}:disabled{cursor:default}audio,canvas,embed,iframe,img,object,svg,video{display:block;vertical-align:middle}img,video{max-width:100%;height:auto}[hidden]:where(:not([hidden=until-found])){display:none}.\!container{width:100%!important}.container{width:100%}@media (min-width:640px){.\!container{max-width:640px!important}.container{max-width:640px}}@media (min-width:768px){.\!container{max-width:768px!important}.container{max-width:768px}}@media (min-width:1024px){.\!container{max-width:1024px!important}.container{max-width:1024px}}@media (min-width:1280px){.\!container{max-width:1280px!important}.container{max-width:1280px}}@media (min-width:1536px){.\!container{max-width:1536px!important}.container{max-width:1536px}}.sr-only{position:absolute;width:1px;height:1px;padding:0;margin:-1px;overflow:hidden;clip:rect(0,0,0,0);white-space:nowrap;border-width:0}.pointer-events-none{pointer-events:none}.visible{visibility:visible}.static{position:static}.fixed{position:fixed}.absolute{position:absolute}.relative{position:relative}.sticky{position:sticky}.inset-0{inset:0}.-top-3{top:-.75rem}.bottom-0{bottom:0}.left-0{left:0}.left-1{left:.25rem}.left-1\/2{left:50%}.left-4{left:1rem}.left-5{left:1.25rem}.right-0{right:0}.right-3{right:.75rem}.right-4{right:1rem}.right-5{right:1.25rem}.right-6{right:1.5rem}.top-0{top:0}.top-1{top:.25rem}.top-1\/2{top:50%}.top-3{top:.75rem}.top-3\.5{top:.875rem}.top-5{top:1.25rem}.z-30{z-index:30}.z-40{z-index:40}.z-50{z-index:50}.z-\[60\]{z-index:60}.z-\[70\]{z-index:70}.z-\[80\]{z-index:80}.mx-auto{margin-left:auto;margin-right:auto}.my-3{margin-top:.75rem;margin-bottom:.75rem}.my-4{margin-top:1rem;margin-bottom:1rem}.-mt-0{margin-top:0}.-mt-0\.5{margin-top:-.125rem}.mb-1{margin-bottom:.25rem}.mb-1\.5{margin-bottom:.375rem}.mb-10{margin-bottom:2.5rem}.mb-2{margin-bottom:.5rem}.mb-3{margin-bottom:.75rem}.mb-4{margin-bottom:1rem}.mb-5{margin-bottom:1.25rem}.mb-6{margin-bottom:1.5rem}.ml-auto{margin-left:auto}.mr-1{margin-right:.25rem}.mr-1\.5{margin-right:.375rem}.mt-0{margin-top:0}.mt-0\.5{margin-top:.125rem}.mt-1{margin-top:.25rem}.mt-1\.5{margin-top:.375rem}.mt-2{margin-top:.5rem}.mt-3{margin-top:.75rem}.mt-4{margin-top:1rem}.mt-5{margin-top:1.25rem}.mt-6{margin-top:1.5rem}.block{display:block}.inline-block{display:inline-block}.flex{display:flex}.inline-flex{display:inline-flex}.table{display:table}.grid{display:grid}.hidden{display:none}.h-10{height:2.5rem}.h-11{height:2.75rem}.h-12{height:3rem}.h-14{height:3.5rem}.h-16{height:4rem}.h-2{height:.5rem}.h-2\.5{height:.625rem}.h-20{height:5rem}.h-3{height:.75rem}.h-36{height:9rem}.h-4{height:1rem}.h-48{height:12rem}.h-5{height:1.25rem}.h-52{height:13rem}.h-6{height:1.5rem}.h-7{height:1.75rem}.h-8{height:2rem}.h-9{height:2.25rem}.h-\[46px\]{height:46px}.h-full{height:100%}.max-h-48{max-height:12rem}.max-h-\[90vh\]{max-height:90vh}.max-h-\[92vh\]{max-height:92vh}.min-h-screen{min-height:100vh}.w-1{width:.25rem}.w-1\/2{width:50%}.w-10{width:2.5rem}.w-11{width:2.75rem}.w-12{width:3rem}.w-14{width:3.5rem}.w-16{width:4rem}.w-2{width:.5rem}.w-2\.5{width:.625rem}.w-20{width:5rem}.w-3{width:.75rem}.w-4{width:1rem}.w-48{width:12rem}.w-5{width:1.25rem}.w-52{width:13rem}.w-6{width:1.5rem}.w-7{width:1.75rem}.w-72{width:18rem}.w-8{width:2rem}.w-9{width:2.25rem}.w-\[90\%\]{width:90%}.w-auto{width:auto}.w-full{width:100%}.min-w-0{min-width:0}.max-w-2xl{max-width:42rem}.max-w-3xl{max-width:48rem}.max-w-4xl{max-width:56rem}.max-w-6xl{max-width:72rem}.max-w-lg{max-width:32rem}.max-w-md{max-width:28rem}.max-w-sm{max-width:24rem}.max-w-xl{max-width:36rem}.max-w-xs{max-width:20rem}.flex-1{flex:1 1 0%}.flex-shrink{flex-shrink:1}.shrink-0{flex-shrink:0}.-translate-x-1{--tw-translate-x:-0.25rem}.-translate-x-1,.-translate-x-1\/2{transform:translate(var(--tw-translate-x),var(--tw-translate-y)) rotate(var(--tw-rotate)) skewX(var(--tw-skew-x)) skewY(var(--tw-skew-y)) scaleX(var(--tw-scale-x)) scaleY(var(--tw-scale-y))}.-translate-x-1\/2{--tw-translate-x:-50%}.-translate-x-full{--tw-translate-x:-100%}.-translate-x-full,.-translate-y-1{transform:translate(var(--tw-translate-x),var(--tw-translate-y)) rotate(var(--tw-rotate)) skewX(var(--tw-skew-x)) skewY(var(--tw-skew-y)) scaleX(var(--tw-scale-x)) scaleY(var(--tw-scale-y))}.-translate-y-1{--tw-translate-y:-0.25rem}.-translate-y-1\/2{--tw-translate-y:-50%}.-translate-y-1\/2,.-translate-y-8{transform:translate(var(--tw-translate-x),var(--tw-translate-y)) rotate(var(--tw-rotate)) skewX(var(--tw-skew-x)) skewY(var(--tw-skew-y)) scaleX(var(--tw-scale-x)) scaleY(var(--tw-scale-y))}.-translate-y-8{--tw-translate-y:-2rem}.translate-x-full{--tw-translate-x:100%}.translate-x-full,.translate-y-0{transform:translate(var(--tw-translate-x),var(--tw-translate-y)) rotate(var(--tw-rotate)) skewX(var(--tw-skew-x)) skewY(var(--tw-skew-y)) scaleX(var(--tw-scale-x)) scaleY(var(--tw-scale-y))}.translate-y-0{--tw-translate-y:0px}.transform{transform:translate(var(--tw-translate-x),var(--tw-translate-y)) rotate(var(--tw-rotate)) skewX(var(--tw-skew-x)) skewY(var(--tw-skew-y)) scaleX(var(--tw-scale-x)) scaleY(var(--tw-scale-y))}@keyframes bounce{0%,to{transform:translateY(-25%);animation-timing-function:cubic-bezier(.8,0,1,1)}50%{transform:none;animation-timing-function:cubic-bezier(0,0,.2,1)}}.animate-bounce{animation:bounce 1s infinite}@keyframes pulse{50%{opacity:.5}}.animate-pulse{animation:pulse 2s cubic-bezier(.4,0,.6,1) infinite}.cursor-not-allowed{cursor:not-allowed}.cursor-pointer{cursor:pointer}.select-none{-webkit-user-select:none;-moz-user-select:none;user-select:none}.select-all{-webkit-user-select:all;-moz-user-select:all;user-select:all}.grid-cols-1{grid-template-columns:repeat(1,minmax(0,1fr))}.grid-cols-2{grid-template-columns:repeat(2,minmax(0,1fr))}.grid-cols-3{grid-template-columns:repeat(3,minmax(0,1fr))}.grid-cols-4{grid-template-columns:repeat(4,minmax(0,1fr))}.grid-cols-\[1fr_auto\]{grid-template-columns:1fr auto}.flex-col{flex-direction:column}.flex-wrap{flex-wrap:wrap}.items-start{align-items:flex-start}.items-end{align-items:flex-end}.items-center{align-items:center}.items-stretch{align-items:stretch}.justify-center{justify-content:center}.justify-between{justify-content:space-between}.gap-1{gap:.25rem}.gap-1\.5{gap:.375rem}.gap-10{gap:2.5rem}.gap-2{gap:.5rem}.gap-2\.5{gap:.625rem}.gap-3{gap:.75rem}.gap-3\.5{gap:.875rem}.gap-4{gap:1rem}.gap-5{gap:1.25rem}.gap-6{gap:1.5rem}.gap-8{gap:2rem}.gap-x-4{-moz-column-gap:1rem;column-gap:1rem}.gap-y-1{row-gap:.25rem}.space-y-0>:not([hidden])~:not([hidden]){--tw-space-y-reverse:0;margin-top:calc(0px*(1 - var(--tw-space-y-reverse)));margin-bottom:calc(0px*var(--tw-space-y-reverse))}.space-y-0\.5>:not([hidden])~:not([hidden]){--tw-space-y-reverse:0;margin-top:calc(.125rem*(1 - var(--tw-space-y-reverse)));margin-bottom:calc(.125rem*var(--tw-space-y-reverse))}.space-y-1>:not([hidden])~:not([hidden]){--tw-space-y-reverse:0;margin-top:calc(.25rem*(1 - var(--tw-space-y-reverse)));margin-bottom:calc(.25rem*var(--tw-space-y-reverse))}.space-y-1\.5>:not([hidden])~:not([hidden]){--tw-space-y-reverse:0;margin-top:calc(.375rem*(1 - var(--tw-space-y-reverse)));margin-bottom:calc(.375rem*var(--tw-space-y-reverse))}.space-y-2>:not([hidden])~:not([hidden]){--tw-space-y-reverse:0;margin-top:calc(.5rem*(1 - var(--tw-space-y-reverse)));margin-bottom:calc(.5rem*var(--tw-space-y-reverse))}.space-y-2\.5>:not([hidden])~:not([hidden]){--tw-space-y-reverse:0;margin-top:calc(.625rem*(1 - var(--tw-space-y-reverse)));margin-bottom:calc(.625rem*var(--tw-space-y-reverse))}.space-y-3>:not([hidden])~:not([hidden]){--tw-space-y-reverse:0;margin-top:calc(.75rem*(1 - var(--tw-space-y-reverse)));margin-bottom:calc(.75rem*var(--tw-space-y-reverse))}.space-y-3\.5>:not([hidden])~:not([hidden]){--tw-space-y-reverse:0;margin-top:calc(.875rem*(1 - var(--tw-space-y-reverse)));margin-bottom:calc(.875rem*var(--tw-space-y-reverse))}.space-y-4>:not([hidden])~:not([hidden]){--tw-space-y-reverse:0;margin-top:calc(1rem*(1 - var(--tw-space-y-reverse)));margin-bottom:calc(1rem*var(--tw-space-y-reverse))}.space-y-5>:not([hidden])~:not([hidden]){--tw-space-y-reverse:0;margin-top:calc(1.25rem*(1 - var(--tw-space-y-reverse)));margin-bottom:calc(1.25rem*var(--tw-space-y-reverse))}.space-y-6>:not([hidden])~:not([hidden]){--tw-space-y-reverse:0;margin-top:calc(1.5rem*(1 - var(--tw-space-y-reverse)));margin-bottom:calc(1.5rem*var(--tw-space-y-reverse))}.divide-y>:not([hidden])~:not([hidden]){--tw-divide-y-reverse:0;border-top-width:calc(1px*(1 - var(--tw-divide-y-reverse)));border-bottom-width:calc(1px*var(--tw-divide-y-reverse))}.divide-\[\#0d265c\]>:not([hidden])~:not([hidden]){--tw-divide-opacity:1;border-color:rgb(13 38 92/var(--tw-divide-opacity,1))}.divide-\[\#0e275c\]>:not([hidden])~:not([hidden]){--tw-divide-opacity:1;border-color:rgb(14 39 92/var(--tw-divide-opacity,1))}.overflow-hidden{overflow:hidden}.overflow-x-auto{overflow-x:auto}.overflow-y-auto{overflow-y:auto}.overflow-x-hidden{overflow-x:hidden}.truncate{overflow:hidden;text-overflow:ellipsis}.truncate,.whitespace-nowrap{white-space:nowrap}.whitespace-pre-line{white-space:pre-line}.whitespace-pre-wrap{white-space:pre-wrap}.break-words{overflow-wrap:break-word}.break-all{word-break:break-all}.rounded-2xl{border-radius:1rem}.rounded-3xl{border-radius:1.5rem}.rounded-full{border-radius:9999px}.rounded-lg{border-radius:.5rem}.rounded-md{border-radius:.375rem}.rounded-xl{border-radius:.75rem}.rounded-t-3xl{border-top-left-radius:1.5rem;border-top-right-radius:1.5rem}.border{border-width:1px}.border-2{border-width:2px}.border-4{border-width:4px}.border-b{border-bottom-width:1px}.border-l{border-left-width:1px}.border-r{border-right-width:1px}.border-t{border-top-width:1px}.border-dashed{border-style:dashed}.border-\[\#007BFF\]\/30{border-color:rgba(0,123,255,.3)}.border-\[\#00D89A\]\/20{border-color:rgba(0,216,154,.2)}.border-\[\#00D89A\]\/30{border-color:rgba(0,216,154,.3)}.border-\[\#00D89A\]\/40{border-color:rgba(0,216,154,.4)}.border-\[\#00D89A\]\/50{border-color:rgba(0,216,154,.5)}.border-\[\#00D89A\]\/60{border-color:rgba(0,216,154,.6)}.border-\[\#00D9FF\]{--tw-border-opacity:1;border-color:rgb(0 217 255/var(--tw-border-opacity,1))}.border-\[\#00D9FF\]\/20{border-color:rgba(0,217,255,.2)}.border-\[\#00D9FF\]\/30{border-color:rgba(0,217,255,.3)}.border-\[\#00D9FF\]\/40{border-color:rgba(0,217,255,.4)}.border-\[\#00D9FF\]\/50{border-color:rgba(0,217,255,.5)}.border-\[\#0d265c\]{--tw-border-opacity:1;border-color:rgb(13 38 92/var(--tw-border-opacity,1))}.border-\[\#0d2a63\]{--tw-border-opacity:1;border-color:rgb(13 42 99/var(--tw-border-opacity,1))}.border-\[\#0e275c\]{--tw-border-opacity:1;border-color:rgb(14 39 92/var(--tw-border-opacity,1))}.border-\[\#0e306e\]{--tw-border-opacity:1;border-color:rgb(14 48 110/var(--tw-border-opacity,1))}.border-\[\#10326f\]{--tw-border-opacity:1;border-color:rgb(16 50 111/var(--tw-border-opacity,1))}.border-\[\#123982\]{--tw-border-opacity:1;border-color:rgb(18 57 130/var(--tw-border-opacity,1))}.border-\[\#143985\]{--tw-border-opacity:1;border-color:rgb(20 57 133/var(--tw-border-opacity,1))}.border-\[\#16418f\]{--tw-border-opacity:1;border-color:rgb(22 65 143/var(--tw-border-opacity,1))}.border-\[\#174396\]{--tw-border-opacity:1;border-color:rgb(23 67 150/var(--tw-border-opacity,1))}.border-\[\#1e4aa3\]{--tw-border-opacity:1;border-color:rgb(30 74 163/var(--tw-border-opacity,1))}.border-amber-400{--tw-border-opacity:1;border-color:rgb(251 191 36/var(--tw-border-opacity,1))}.border-amber-400\/30{border-color:rgba(251,191,36,.3)}.border-amber-500{--tw-border-opacity:1;border-color:rgb(245 158 11/var(--tw-border-opacity,1))}.border-amber-500\/30{border-color:rgba(245,158,11,.3)}.border-amber-500\/40{border-color:rgba(245,158,11,.4)}.border-rose-500{--tw-border-opacity:1;border-color:rgb(244 63 94/var(--tw-border-opacity,1))}.border-rose-500\/20{border-color:rgba(244,63,94,.2)}.border-rose-500\/30{border-color:rgba(244,63,94,.3)}.border-rose-500\/40{border-color:rgba(244,63,94,.4)}.border-slate-700{--tw-border-opacity:1;border-color:rgb(51 65 85/var(--tw-border-opacity,1))}.border-white{--tw-border-opacity:1;border-color:rgb(255 255 255/var(--tw-border-opacity,1))}.border-white\/10{border-color:hsla(0,0%,100%,.1)}.bg-\[\#007BFF\]{--tw-bg-opacity:1;background-color:rgb(0 123 255/var(--tw-bg-opacity,1))}.bg-\[\#007BFF\]\/20{background-color:rgba(0,123,255,.2)}.bg-\[\#00D89A\]{--tw-bg-opacity:1;background-color:rgb(0 216 154/var(--tw-bg-opacity,1))}.bg-\[\#00D89A\]\/10{background-color:rgba(0,216,154,.1)}.bg-\[\#00D89A\]\/15{background-color:rgba(0,216,154,.15)}.bg-\[\#00D89A\]\/20{background-color:rgba(0,216,154,.2)}.bg-\[\#00D89A\]\/5{background-color:rgba(0,216,154,.05)}.bg-\[\#00D9FF\]\/10{background-color:rgba(0,217,255,.1)}.bg-\[\#00D9FF\]\/15{background-color:rgba(0,217,255,.15)}.bg-\[\#00D9FF\]\/20{background-color:rgba(0,217,255,.2)}.bg-\[\#020817\]{--tw-bg-opacity:1;background-color:rgb(2 8 23/var(--tw-bg-opacity,1))}.bg-\[\#020817\]\/80{background-color:rgba(2,8,23,.8)}.bg-\[\#020817\]\/85{background-color:rgba(2,8,23,.85)}.bg-\[\#030d24\]\/95{background-color:rgba(3,13,36,.95)}.bg-\[\#03132e\]{--tw-bg-opacity:1;background-color:rgb(3 19 46/var(--tw-bg-opacity,1))}.bg-\[\#040e24\]{--tw-bg-opacity:1;background-color:rgb(4 14 36/var(--tw-bg-opacity,1))}.bg-\[\#04122d\]{--tw-bg-opacity:1;background-color:rgb(4 18 45/var(--tw-bg-opacity,1))}.bg-\[\#05112b\]\/95{background-color:rgba(5,17,43,.95)}.bg-\[\#051433\]{--tw-bg-opacity:1;background-color:rgb(5 20 51/var(--tw-bg-opacity,1))}.bg-\[\#051433\]\/95{background-color:rgba(5,20,51,.95)}.bg-\[\#061535\]{--tw-bg-opacity:1;background-color:rgb(6 21 53/var(--tw-bg-opacity,1))}.bg-\[\#061535\]\/80{background-color:rgba(6,21,53,.8)}.bg-\[\#061535\]\/85{background-color:rgba(6,21,53,.85)}.bg-\[\#061535\]\/90{background-color:rgba(6,21,53,.9)}.bg-\[\#071638\]{--tw-bg-opacity:1;background-color:rgb(7 22 56/var(--tw-bg-opacity,1))}.bg-\[\#081b42\]\/95{background-color:rgba(8,27,66,.95)}.bg-\[\#081e4b\]{--tw-bg-opacity:1;background-color:rgb(8 30 75/var(--tw-bg-opacity,1))}.bg-\[\#09204e\]{--tw-bg-opacity:1;background-color:rgb(9 32 78/var(--tw-bg-opacity,1))}.bg-\[\#092257\]{--tw-bg-opacity:1;background-color:rgb(9 34 87/var(--tw-bg-opacity,1))}.bg-\[\#0a1e4a\]{--tw-bg-opacity:1;background-color:rgb(10 30 74/var(--tw-bg-opacity,1))}.bg-\[\#0a2356\]{--tw-bg-opacity:1;background-color:rgb(10 35 86/var(--tw-bg-opacity,1))}.bg-\[\#0c2454\]{--tw-bg-opacity:1;background-color:rgb(12 36 84/var(--tw-bg-opacity,1))}.bg-\[\#35F58A\]\/15{background-color:rgba(53,245,138,.15)}.bg-\[\#35F58A\]\/20{background-color:rgba(53,245,138,.2)}.bg-\[\#8B5CF6\]\/20{background-color:rgba(139,92,246,.2)}.bg-amber-400{--tw-bg-opacity:1;background-color:rgb(251 191 36/var(--tw-bg-opacity,1))}.bg-amber-400\/10{background-color:rgba(251,191,36,.1)}.bg-amber-500{--tw-bg-opacity:1;background-color:rgb(245 158 11/var(--tw-bg-opacity,1))}.bg-amber-500\/10{background-color:rgba(245,158,11,.1)}.bg-amber-500\/15{background-color:rgba(245,158,11,.15)}.bg-amber-500\/20{background-color:rgba(245,158,11,.2)}.bg-amber-500\/5{background-color:rgba(245,158,11,.05)}.bg-black{--tw-bg-opacity:1;background-color:rgb(0 0 0/var(--tw-bg-opacity,1))}.bg-black\/30{background-color:rgba(0,0,0,.3)}.bg-black\/60{background-color:rgba(0,0,0,.6)}.bg-black\/80{background-color:rgba(0,0,0,.8)}.bg-rose-500{--tw-bg-opacity:1;background-color:rgb(244 63 94/var(--tw-bg-opacity,1))}.bg-rose-500\/10{background-color:rgba(244,63,94,.1)}.bg-rose-500\/15{background-color:rgba(244,63,94,.15)}.bg-rose-500\/20{background-color:rgba(244,63,94,.2)}.bg-slate-700{--tw-bg-opacity:1;background-color:rgb(51 65 85/var(--tw-bg-opacity,1))}.bg-slate-800{--tw-bg-opacity:1;background-color:rgb(30 41 59/var(--tw-bg-opacity,1))}.bg-transparent{background-color:transparent}.bg-white{--tw-bg-opacity:1;background-color:rgb(255 255 255/var(--tw-bg-opacity,1))}.bg-white\/10{background-color:hsla(0,0%,100%,.1)}.bg-gradient-to-b{background-image:linear-gradient(to bottom,var(--tw-gradient-stops))}.bg-gradient-to-br{background-image:linear-gradient(to bottom right,var(--tw-gradient-stops))}.bg-gradient-to-r{background-image:linear-gradient(to right,var(--tw-gradient-stops))}.from-\[\#00D89A\]\/15{--tw-gradient-from:rgba(0,216,154,.15) var(--tw-gradient-from-position);--tw-gradient-to:rgba(0,216,154,0) var(--tw-gradient-to-position);--tw-gradient-stops:var(--tw-gradient-from),var(--tw-gradient-to)}.from-\[\#06303a\]{--tw-gradient-from:#06303a var(--tw-gradient-from-position);--tw-gradient-to:rgba(6,48,58,0) var(--tw-gradient-to-position);--tw-gradient-stops:var(--tw-gradient-from),var(--tw-gradient-to)}.from-\[\#071a44\]{--tw-gradient-from:#071a44 var(--tw-gradient-from-position);--tw-gradient-to:rgba(7,26,68,0) var(--tw-gradient-to-position);--tw-gradient-stops:var(--tw-gradient-from),var(--tw-gradient-to)}.from-\[\#081d49\]{--tw-gradient-from:#081d49 var(--tw-gradient-from-position);--tw-gradient-to:rgba(8,29,73,0) var(--tw-gradient-to-position);--tw-gradient-stops:var(--tw-gradient-from),var(--tw-gradient-to)}.from-\[\#0b2559\]{--tw-gradient-from:#0b2559 var(--tw-gradient-from-position);--tw-gradient-to:rgba(11,37,89,0) var(--tw-gradient-to-position);--tw-gradient-stops:var(--tw-gradient-from),var(--tw-gradient-to)}.via-\[\#092257\]{--tw-gradient-to:rgba(9,34,87,0) var(--tw-gradient-to-position);--tw-gradient-stops:var(--tw-gradient-from),#092257 var(--tw-gradient-via-position),var(--tw-gradient-to)}.to-\[\#00D9FF\]\/10{--tw-gradient-to:rgba(0,217,255,.1) var(--tw-gradient-to-position)}.to-\[\#020817\]{--tw-gradient-to:#020817 var(--tw-gradient-to-position)}.to-\[\#04112c\]{--tw-gradient-to:#04112c var(--tw-gradient-to-position)}.to-\[\#04122d\]{--tw-gradient-to:#04122d var(--tw-gradient-to-position)}.to-\[\#05112b\]{--tw-gradient-to:#05112b var(--tw-gradient-to-position)}.to-\[\#061535\]{--tw-gradient-to:#061535 var(--tw-gradient-to-position)}.to-\[\#081e4b\]{--tw-gradient-to:#081e4b var(--tw-gradient-to-position)}.object-contain{-o-object-fit:contain;object-fit:contain}.p-0{padding:0}.p-0\.5{padding:.125rem}.p-1{padding:.25rem}.p-1\.5{padding:.375rem}.p-2{padding:.5rem}.p-3{padding:.75rem}.p-3\.5{padding:.875rem}.p-4{padding:1rem}.p-5{padding:1.25rem}.p-6{padding:1.5rem}.p-8{padding:2rem}.px-1{padding-left:.25rem;padding-right:.25rem}.px-1\.5{padding-left:.375rem;padding-right:.375rem}.px-2{padding-left:.5rem;padding-right:.5rem}.px-2\.5{padding-left:.625rem;padding-right:.625rem}.px-3{padding-left:.75rem;padding-right:.75rem}.px-3\.5{padding-left:.875rem;padding-right:.875rem}.px-4{padding-left:1rem;padding-right:1rem}.px-5{padding-left:1.25rem;padding-right:1.25rem}.px-7{padding-left:1.75rem;padding-right:1.75rem}.py-0{padding-top:0;padding-bottom:0}.py-0\.5{padding-top:.125rem;padding-bottom:.125rem}.py-1{padding-top:.25rem;padding-bottom:.25rem}.py-1\.5{padding-top:.375rem;padding-bottom:.375rem}.py-10{padding-top:2.5rem;padding-bottom:2.5rem}.py-12{padding-top:3rem;padding-bottom:3rem}.py-2{padding-top:.5rem;padding-bottom:.5rem}.py-2\.5{padding-top:.625rem;padding-bottom:.625rem}.py-3{padding-top:.75rem;padding-bottom:.75rem}.py-3\.5{padding-top:.875rem;padding-bottom:.875rem}.py-8{padding-top:2rem;padding-bottom:2rem}.pb-3{padding-bottom:.75rem}.pb-4{padding-bottom:1rem}.pb-5{padding-bottom:1.25rem}.pl-11{padding-left:2.75rem}.pr-11{padding-right:2.75rem}.pr-2{padding-right:.5rem}.pr-4{padding-right:1rem}.pr-6{padding-right:1.5rem}.pt-0{padding-top:0}.pt-0\.5{padding-top:.125rem}.pt-1{padding-top:.25rem}.pt-2{padding-top:.5rem}.pt-2\.5{padding-top:.625rem}.pt-3{padding-top:.75rem}.pt-5{padding-top:1.25rem}.pt-6{padding-top:1.5rem}.text-left{text-align:left}.text-center{text-align:center}.text-right{text-align:right}.font-mono{font-family:ui-monospace,SFMono-Regular,Menlo,Monaco,Consolas,Liberation Mono,Courier New,monospace}.font-sans{font-family:ui-sans-serif,system-ui,sans-serif,Apple Color Emoji,Segoe UI Emoji,Segoe UI Symbol,Noto Color Emoji}.text-2xl{font-size:1.5rem;line-height:2rem}.text-3xl{font-size:1.875rem;line-height:2.25rem}.text-4xl{font-size:2.25rem;line-height:2.5rem}.text-\[10px\]{font-size:10px}.text-\[11px\]{font-size:11px}.text-\[12px\]{font-size:12px}.text-\[13px\]{font-size:13px}.text-\[6px\]{font-size:6px}.text-\[9px\]{font-size:9px}.text-base{font-size:1rem;line-height:1.5rem}.text-lg{font-size:1.125rem;line-height:1.75rem}.text-sm{font-size:.875rem;line-height:1.25rem}.text-xl{font-size:1.25rem;line-height:1.75rem}.text-xs{font-size:.75rem;line-height:1rem}.font-black{font-weight:900}.font-bold{font-weight:700}.font-medium{font-weight:500}.font-normal{font-weight:400}.font-semibold{font-weight:600}.uppercase{text-transform:uppercase}.leading-relaxed{line-height:1.625}.leading-tight{line-height:1.25}.tracking-wide{letter-spacing:.025em}.tracking-wider{letter-spacing:.05em}.tracking-widest{letter-spacing:.1em}.text-\[\#007BFF\]{--tw-text-opacity:1;color:rgb(0 123 255/var(--tw-text-opacity,1))}.text-\[\#00D89A\]{--tw-text-opacity:1;color:rgb(0 216 154/var(--tw-text-opacity,1))}.text-\[\#00D89A\]\/20{color:rgba(0,216,154,.2)}.text-\[\#00D9FF\]{--tw-text-opacity:1;color:rgb(0 217 255/var(--tw-text-opacity,1))}.text-\[\#00D9FF\]\/20{color:rgba(0,217,255,.2)}.text-\[\#020817\]{--tw-text-opacity:1;color:rgb(2 8 23/var(--tw-text-opacity,1))}.text-\[\#061535\]{--tw-text-opacity:1;color:rgb(6 21 53/var(--tw-text-opacity,1))}.text-\[\#25D366\]{--tw-text-opacity:1;color:rgb(37 211 102/var(--tw-text-opacity,1))}.text-\[\#35F58A\]{--tw-text-opacity:1;color:rgb(53 245 138/var(--tw-text-opacity,1))}.text-\[\#35F58A\]\/20{color:rgba(53,245,138,.2)}.text-\[\#38BDF8\]{--tw-text-opacity:1;color:rgb(56 189 248/var(--tw-text-opacity,1))}.text-\[\#8B5CF6\]{--tw-text-opacity:1;color:rgb(139 92 246/var(--tw-text-opacity,1))}.text-\[\#F5F8FC\]{--tw-text-opacity:1;color:rgb(245 248 252/var(--tw-text-opacity,1))}.text-\[\#FBBF24\]{--tw-text-opacity:1;color:rgb(251 191 36/var(--tw-text-opacity,1))}.text-amber-300{--tw-text-opacity:1;color:rgb(252 211 77/var(--tw-text-opacity,1))}.text-amber-400{--tw-text-opacity:1;color:rgb(251 191 36/var(--tw-text-opacity,1))}.text-cyan-400{--tw-text-opacity:1;color:rgb(34 211 238/var(--tw-text-opacity,1))}.text-emerald-400{--tw-text-opacity:1;color:rgb(52 211 153/var(--tw-text-opacity,1))}.text-rose-300{--tw-text-opacity:1;color:rgb(253 164 175/var(--tw-text-opacity,1))}.text-rose-400{--tw-text-opacity:1;color:rgb(251 113 133/var(--tw-text-opacity,1))}.text-slate-100{--tw-text-opacity:1;color:rgb(241 245 249/var(--tw-text-opacity,1))}.text-slate-200{--tw-text-opacity:1;color:rgb(226 232 240/var(--tw-text-opacity,1))}.text-slate-300{--tw-text-opacity:1;color:rgb(203 213 225/var(--tw-text-opacity,1))}.text-slate-400{--tw-text-opacity:1;color:rgb(148 163 184/var(--tw-text-opacity,1))}.text-slate-500{--tw-text-opacity:1;color:rgb(100 116 139/var(--tw-text-opacity,1))}.text-white{--tw-text-opacity:1;color:rgb(255 255 255/var(--tw-text-opacity,1))}.line-through{text-decoration-line:line-through}.accent-\[\#00D89A\]{accent-color:#00d89a}.accent-rose-500{accent-color:#f43f5e}.opacity-0{opacity:0}.opacity-100{opacity:1}.opacity-50{opacity:.5}.opacity-60{opacity:.6}.opacity-75{opacity:.75}.shadow-2xl{--tw-shadow:0 25px 50px -12px rgba(0,0,0,.25);--tw-shadow-colored:0 25px 50px -12px var(--tw-shadow-color);box-shadow:var(--tw-ring-offset-shadow,0 0 #0000),var(--tw-ring-shadow,0 0 #0000),var(--tw-shadow)}.shadow-\[0_0_10px_rgba\(0\2c 123\2c 255\2c 0\.4\)\]{--tw-shadow:0 0 10px rgba(0,123,255,.4);--tw-shadow-colored:0 0 10px var(--tw-shadow-color);box-shadow:var(--tw-ring-offset-shadow,0 0 #0000),var(--tw-ring-shadow,0 0 #0000),var(--tw-shadow)}.shadow-\[0_0_10px_rgba\(0\2c 216\2c 154\2c 0\.4\)\]{--tw-shadow:0 0 10px rgba(0,216,154,.4);--tw-shadow-colored:0 0 10px var(--tw-shadow-color);box-shadow:var(--tw-ring-offset-shadow,0 0 #0000),var(--tw-ring-shadow,0 0 #0000),var(--tw-shadow)}.shadow-\[0_0_10px_rgba\(0\2c 217\2c 255\2c 0\.2\)\]{--tw-shadow:0 0 10px rgba(0,217,255,.2);--tw-shadow-colored:0 0 10px var(--tw-shadow-color);box-shadow:var(--tw-ring-offset-shadow,0 0 #0000),var(--tw-ring-shadow,0 0 #0000),var(--tw-shadow)}.shadow-\[0_0_10px_rgba\(0\2c 217\2c 255\2c 0\.4\)\]{--tw-shadow:0 0 10px rgba(0,217,255,.4);--tw-shadow-colored:0 0 10px var(--tw-shadow-color);box-shadow:var(--tw-ring-offset-shadow,0 0 #0000),var(--tw-ring-shadow,0 0 #0000),var(--tw-shadow)}.shadow-\[0_0_10px_rgba\(139\2c 92\2c 246\2c 0\.4\)\]{--tw-shadow:0 0 10px rgba(139,92,246,.4);--tw-shadow-colored:0 0 10px var(--tw-shadow-color);box-shadow:var(--tw-ring-offset-shadow,0 0 #0000),var(--tw-ring-shadow,0 0 #0000),var(--tw-shadow)}.shadow-\[0_0_15px_rgba\(0\2c 217\2c 255\2c 0\.1\)\]{--tw-shadow:0 0 15px rgba(0,217,255,.1);--tw-shadow-colored:0 0 15px var(--tw-shadow-color);box-shadow:var(--tw-ring-offset-shadow,0 0 #0000),var(--tw-ring-shadow,0 0 #0000),var(--tw-shadow)}.shadow-\[0_0_15px_rgba\(0\2c 217\2c 255\2c 0\.15\)\]{--tw-shadow:0 0 15px rgba(0,217,255,.15);--tw-shadow-colored:0 0 15px var(--tw-shadow-color);box-shadow:var(--tw-ring-offset-shadow,0 0 #0000),var(--tw-ring-shadow,0 0 #0000),var(--tw-shadow)}.shadow-\[0_0_18px_rgba\(0\2c 216\2c 154\2c 0\.15\)\]{--tw-shadow:0 0 18px rgba(0,216,154,.15);--tw-shadow-colored:0 0 18px var(--tw-shadow-color);box-shadow:var(--tw-ring-offset-shadow,0 0 #0000),var(--tw-ring-shadow,0 0 #0000),var(--tw-shadow)}.shadow-\[0_0_20px_rgba\(0\2c 217\2c 255\2c 0\.12\)\]{--tw-shadow:0 0 20px rgba(0,217,255,.12);--tw-shadow-colored:0 0 20px var(--tw-shadow-color);box-shadow:var(--tw-ring-offset-shadow,0 0 #0000),var(--tw-ring-shadow,0 0 #0000),var(--tw-shadow)}.shadow-\[0_0_20px_rgba\(0\2c 217\2c 255\2c 0\.4\)\]{--tw-shadow:0 0 20px rgba(0,217,255,.4);--tw-shadow-colored:0 0 20px var(--tw-shadow-color);box-shadow:var(--tw-ring-offset-shadow,0 0 #0000),var(--tw-ring-shadow,0 0 #0000),var(--tw-shadow)}.shadow-\[0_0_25px_rgba\(0\2c 217\2c 255\2c 0\.3\)\]{--tw-shadow:0 0 25px rgba(0,217,255,.3);--tw-shadow-colored:0 0 25px var(--tw-shadow-color);box-shadow:var(--tw-ring-offset-shadow,0 0 #0000),var(--tw-ring-shadow,0 0 #0000),var(--tw-shadow)}.shadow-\[0_0_8px_rgba\(0\2c 216\2c 154\2c 0\.8\)\]{--tw-shadow:0 0 8px rgba(0,216,154,.8);--tw-shadow-colored:0 0 8px var(--tw-shadow-color)}.shadow-\[0_0_8px_rgba\(0\2c 216\2c 154\2c 0\.8\)\],.shadow-lg{box-shadow:var(--tw-ring-offset-shadow,0 0 #0000),var(--tw-ring-shadow,0 0 #0000),var(--tw-shadow)}.shadow-lg{--tw-shadow:0 10px 15px -3px rgba(0,0,0,.1),0 4px 6px -4px rgba(0,0,0,.1);--tw-shadow-colored:0 10px 15px -3px var(--tw-shadow-color),0 4px 6px -4px var(--tw-shadow-color)}.shadow-md{--tw-shadow:0 4px 6px -1px rgba(0,0,0,.1),0 2px 4px -2px rgba(0,0,0,.1);--tw-shadow-colored:0 4px 6px -1px var(--tw-shadow-color),0 2px 4px -2px var(--tw-shadow-color)}.shadow-md,.shadow-sm{box-shadow:var(--tw-ring-offset-shadow,0 0 #0000),var(--tw-ring-shadow,0 0 #0000),var(--tw-shadow)}.shadow-sm{--tw-shadow:0 1px 2px 0 rgba(0,0,0,.05);--tw-shadow-colored:0 1px 2px 0 var(--tw-shadow-color)}.shadow-xl{--tw-shadow:0 20px 25px -5px rgba(0,0,0,.1),0 8px 10px -6px rgba(0,0,0,.1);--tw-shadow-colored:0 20px 25px -5px var(--tw-shadow-color),0 8px 10px -6px var(--tw-shadow-color);box-shadow:var(--tw-ring-offset-shadow,0 0 #0000),var(--tw-ring-shadow,0 0 #0000),var(--tw-shadow)}.shadow-black{--tw-shadow-color:#000;--tw-shadow:var(--tw-shadow-colored)}.shadow-black\/40{--tw-shadow-color:rgba(0,0,0,.4);--tw-shadow:var(--tw-shadow-colored)}.shadow-cyan-500{--tw-shadow-color:#06b6d4;--tw-shadow:var(--tw-shadow-colored)}.shadow-cyan-500\/10{--tw-shadow-color:rgba(6,182,212,.1);--tw-shadow:var(--tw-shadow-colored)}.shadow-cyan-500\/20{--tw-shadow-color:rgba(6,182,212,.2);--tw-shadow:var(--tw-shadow-colored)}.shadow-cyan-900{--tw-shadow-color:#164e63;--tw-shadow:var(--tw-shadow-colored)}.shadow-cyan-900\/10{--tw-shadow-color:rgba(22,78,99,.1);--tw-shadow:var(--tw-shadow-colored)}.outline{outline-style:solid}.blur{--tw-blur:blur(8px)}.blur,.filter{filter:var(--tw-blur) var(--tw-brightness) var(--tw-contrast) var(--tw-grayscale) var(--tw-hue-rotate) var(--tw-invert) var(--tw-saturate) var(--tw-sepia) var(--tw-drop-shadow)}.backdrop-blur-2xl{--tw-backdrop-blur:blur(40px)}.backdrop-blur-2xl,.backdrop-blur-lg{-webkit-backdrop-filter:var(--tw-backdrop-blur) var(--tw-backdrop-brightness) var(--tw-backdrop-contrast) var(--tw-backdrop-grayscale) var(--tw-backdrop-hue-rotate) var(--tw-backdrop-invert) var(--tw-backdrop-opacity) var(--tw-backdrop-saturate) var(--tw-backdrop-sepia);backdrop-filter:var(--tw-backdrop-blur) var(--tw-backdrop-brightness) var(--tw-backdrop-contrast) var(--tw-backdrop-grayscale) var(--tw-backdrop-hue-rotate) var(--tw-backdrop-invert) var(--tw-backdrop-opacity) var(--tw-backdrop-saturate) var(--tw-backdrop-sepia)}.backdrop-blur-lg{--tw-backdrop-blur:blur(16px)}.backdrop-blur-md{--tw-backdrop-blur:blur(12px)}.backdrop-blur-md,.backdrop-blur-sm{-webkit-backdrop-filter:var(--tw-backdrop-blur) var(--tw-backdrop-brightness) var(--tw-backdrop-contrast) var(--tw-backdrop-grayscale) var(--tw-backdrop-hue-rotate) var(--tw-backdrop-invert) var(--tw-backdrop-opacity) var(--tw-backdrop-saturate) var(--tw-backdrop-sepia);backdrop-filter:var(--tw-backdrop-blur) var(--tw-backdrop-brightness) var(--tw-backdrop-contrast) var(--tw-backdrop-grayscale) var(--tw-backdrop-hue-rotate) var(--tw-backdrop-invert) var(--tw-backdrop-opacity) var(--tw-backdrop-saturate) var(--tw-backdrop-sepia)}.backdrop-blur-sm{--tw-backdrop-blur:blur(4px)}.backdrop-blur-xl{--tw-backdrop-blur:blur(24px)}.backdrop-blur-xl,.backdrop-filter{-webkit-backdrop-filter:var(--tw-backdrop-blur) var(--tw-backdrop-brightness) var(--tw-backdrop-contrast) var(--tw-backdrop-grayscale) var(--tw-backdrop-hue-rotate) var(--tw-backdrop-invert) var(--tw-backdrop-opacity) var(--tw-backdrop-saturate) var(--tw-backdrop-sepia);backdrop-filter:var(--tw-backdrop-blur) var(--tw-backdrop-brightness) var(--tw-backdrop-contrast) var(--tw-backdrop-grayscale) var(--tw-backdrop-hue-rotate) var(--tw-backdrop-invert) var(--tw-backdrop-opacity) var(--tw-backdrop-saturate) var(--tw-backdrop-sepia)}.transition{transition-property:color,background-color,border-color,text-decoration-color,fill,stroke,opacity,box-shadow,transform,filter,-webkit-backdrop-filter;transition-property:color,background-color,border-color,text-decoration-color,fill,stroke,opacity,box-shadow,transform,filter,backdrop-filter;transition-property:color,background-color,border-color,text-decoration-color,fill,stroke,opacity,box-shadow,transform,filter,backdrop-filter,-webkit-backdrop-filter;transition-timing-function:cubic-bezier(.4,0,.2,1);transition-duration:.15s}.transition-all{transition-property:all;transition-timing-function:cubic-bezier(.4,0,.2,1);transition-duration:.15s}.transition-opacity{transition-property:opacity;transition-timing-function:cubic-bezier(.4,0,.2,1);transition-duration:.15s}.transition-transform{transition-property:transform;transition-timing-function:cubic-bezier(.4,0,.2,1);transition-duration:.15s}.duration-200{transition-duration:.2s}.duration-300{transition-duration:.3s}.ease-in-out{transition-timing-function:cubic-bezier(.4,0,.2,1)}.selection\:bg-\[\#00D9FF\] ::-moz-selection{--tw-bg-opacity:1;background-color:rgb(0 217 255/var(--tw-bg-opacity,1))}.selection\:bg-\[\#00D9FF\] ::selection{--tw-bg-opacity:1;background-color:rgb(0 217 255/var(--tw-bg-opacity,1))}.selection\:text-\[\#020817\] ::-moz-selection{--tw-text-opacity:1;color:rgb(2 8 23/var(--tw-text-opacity,1))}.selection\:text-\[\#020817\] ::selection{--tw-text-opacity:1;color:rgb(2 8 23/var(--tw-text-opacity,1))}.selection\:text-\[\#061535\] ::-moz-selection{--tw-text-opacity:1;color:rgb(6 21 53/var(--tw-text-opacity,1))}.selection\:text-\[\#061535\] ::selection{--tw-text-opacity:1;color:rgb(6 21 53/var(--tw-text-opacity,1))}.selection\:bg-\[\#00D9FF\]::-moz-selection{--tw-bg-opacity:1;background-color:rgb(0 217 255/var(--tw-bg-opacity,1))}.selection\:bg-\[\#00D9FF\]::selection{--tw-bg-opacity:1;background-color:rgb(0 217 255/var(--tw-bg-opacity,1))}.selection\:text-\[\#020817\]::-moz-selection{--tw-text-opacity:1;color:rgb(2 8 23/var(--tw-text-opacity,1))}.selection\:text-\[\#020817\]::selection{--tw-text-opacity:1;color:rgb(2 8 23/var(--tw-text-opacity,1))}.selection\:text-\[\#061535\]::-moz-selection{--tw-text-opacity:1;color:rgb(6 21 53/var(--tw-text-opacity,1))}.selection\:text-\[\#061535\]::selection{--tw-text-opacity:1;color:rgb(6 21 53/var(--tw-text-opacity,1))}.file\:mr-4::file-selector-button{margin-right:1rem}.file\:rounded-xl::file-selector-button{border-radius:.75rem}.file\:border-0::file-selector-button{border-width:0}.file\:bg-\[\#007BFF\]::file-selector-button{--tw-bg-opacity:1;background-color:rgb(0 123 255/var(--tw-bg-opacity,1))}.file\:px-4::file-selector-button{padding-left:1rem;padding-right:1rem}.file\:py-2::file-selector-button{padding-top:.5rem;padding-bottom:.5rem}.file\:py-2\.5::file-selector-button{padding-top:.625rem;padding-bottom:.625rem}.file\:text-xs::file-selector-button{font-size:.75rem;line-height:1rem}.file\:font-semibold::file-selector-button{font-weight:600}.file\:text-white::file-selector-button{--tw-text-opacity:1;color:rgb(255 255 255/var(--tw-text-opacity,1))}.after\:absolute:after{content:var(--tw-content);position:absolute}.after\:left-\[2px\]:after{content:var(--tw-content);left:2px}.after\:top-\[2px\]:after{content:var(--tw-content);top:2px}.after\:h-5:after{content:var(--tw-content);height:1.25rem}.after\:w-5:after{content:var(--tw-content);width:1.25rem}.after\:rounded-full:after{content:var(--tw-content);border-radius:9999px}.after\:border:after{content:var(--tw-content);border-width:1px}.after\:border-gray-300:after{content:var(--tw-content);--tw-border-opacity:1;border-color:rgb(209 213 219/var(--tw-border-opacity,1))}.after\:bg-white:after{content:var(--tw-content);--tw-bg-opacity:1;background-color:rgb(255 255 255/var(--tw-bg-opacity,1))}.after\:transition-all:after{content:var(--tw-content);transition-property:all;transition-timing-function:cubic-bezier(.4,0,.2,1);transition-duration:.15s}.after\:content-\[\'\'\]:after{--tw-content:"";content:var(--tw-content)}.hover\:border-\[\#00D9FF\]:hover{--tw-border-opacity:1;border-color:rgb(0 217 255/var(--tw-border-opacity,1))}.hover\:border-\[\#00D9FF\]\/40:hover{border-color:rgba(0,217,255,.4)}.hover\:border-amber-400:hover{--tw-border-opacity:1;border-color:rgb(251 191 36/var(--tw-border-opacity,1))}.hover\:bg-\[\#007BFF\]:hover{--tw-bg-opacity:1;background-color:rgb(0 123 255/var(--tw-bg-opacity,1))}.hover\:bg-\[\#00D9FF\]\/20:hover{background-color:rgba(0,217,255,.2)}.hover\:bg-\[\#051433\]\/60:hover{background-color:rgba(5,20,51,.6)}.hover\:bg-\[\#092257\]:hover{--tw-bg-opacity:1;background-color:rgb(9 34 87/var(--tw-bg-opacity,1))}.hover\:bg-\[\#0a1e4a\]:hover{--tw-bg-opacity:1;background-color:rgb(10 30 74/var(--tw-bg-opacity,1))}.hover\:bg-\[\#0c245c\]:hover{--tw-bg-opacity:1;background-color:rgb(12 36 92/var(--tw-bg-opacity,1))}.hover\:bg-\[\#0c2e75\]:hover{--tw-bg-opacity:1;background-color:rgb(12 46 117/var(--tw-bg-opacity,1))}.hover\:bg-\[\#0f2d6e\]:hover{--tw-bg-opacity:1;background-color:rgb(15 45 110/var(--tw-bg-opacity,1))}.hover\:bg-rose-500\/30:hover{background-color:rgba(244,63,94,.3)}.hover\:bg-slate-800:hover{--tw-bg-opacity:1;background-color:rgb(30 41 59/var(--tw-bg-opacity,1))}.hover\:text-\[\#00D89A\]:hover{--tw-text-opacity:1;color:rgb(0 216 154/var(--tw-text-opacity,1))}.hover\:text-\[\#00D9FF\]:hover{--tw-text-opacity:1;color:rgb(0 217 255/var(--tw-text-opacity,1))}.hover\:text-rose-300:hover{--tw-text-opacity:1;color:rgb(253 164 175/var(--tw-text-opacity,1))}.hover\:text-rose-400:hover{--tw-text-opacity:1;color:rgb(251 113 133/var(--tw-text-opacity,1))}.hover\:text-white:hover{--tw-text-opacity:1;color:rgb(255 255 255/var(--tw-text-opacity,1))}.hover\:underline:hover{text-decoration-line:underline}.hover\:file\:bg-\[\#0056b3\]::file-selector-button:hover{--tw-bg-opacity:1;background-color:rgb(0 86 179/var(--tw-bg-opacity,1))}.focus\:border-\[\#00D89A\]:focus{--tw-border-opacity:1;border-color:rgb(0 216 154/var(--tw-border-opacity,1))}.focus\:border-\[\#00D9FF\]:focus{--tw-border-opacity:1;border-color:rgb(0 217 255/var(--tw-border-opacity,1))}.focus\:border-amber-400:focus{--tw-border-opacity:1;border-color:rgb(251 191 36/var(--tw-border-opacity,1))}.focus\:outline-none:focus{outline:2px solid transparent;outline-offset:2px}.active\:scale-95:active{--tw-scale-x:.95;--tw-scale-y:.95;transform:translate(var(--tw-translate-x),var(--tw-translate-y)) rotate(var(--tw-rotate)) skewX(var(--tw-skew-x)) skewY(var(--tw-skew-y)) scaleX(var(--tw-scale-x)) scaleY(var(--tw-scale-y))}.group:hover .group-hover\:text-\[\#00D89A\]{--tw-text-opacity:1;color:rgb(0 216 154/var(--tw-text-opacity,1))}.group:hover .group-hover\:text-\[\#00D9FF\]{--tw-text-opacity:1;color:rgb(0 217 255/var(--tw-text-opacity,1))}.group:hover .group-hover\:text-amber-400{--tw-text-opacity:1;color:rgb(251 191 36/var(--tw-text-opacity,1))}.group:hover .group-hover\:text-white{--tw-text-opacity:1;color:rgb(255 255 255/var(--tw-text-opacity,1))}.peer:checked~.peer-checked\:border-\[\#00D9FF\]{--tw-border-opacity:1;border-color:rgb(0 217 255/var(--tw-border-opacity,1))}.peer:checked~.peer-checked\:bg-\[\#00D89A\]{--tw-bg-opacity:1;background-color:rgb(0 216 154/var(--tw-bg-opacity,1))}.peer:checked~.peer-checked\:bg-\[\#00D9FF\]\/10{background-color:rgba(0,217,255,.1)}.peer:checked~.peer-checked\:text-\[\#00D9FF\]{--tw-text-opacity:1;color:rgb(0 217 255/var(--tw-text-opacity,1))}.peer:checked~.peer-checked\:after\:translate-x-full:after{content:var(--tw-content);--tw-translate-x:100%;transform:translate(var(--tw-translate-x),var(--tw-translate-y)) rotate(var(--tw-rotate)) skewX(var(--tw-skew-x)) skewY(var(--tw-skew-y)) scaleX(var(--tw-scale-x)) scaleY(var(--tw-scale-y))}.peer:checked~.peer-checked\:after\:border-white:after{content:var(--tw-content);--tw-border-opacity:1;border-color:rgb(255 255 255/var(--tw-border-opacity,1))}.peer:focus~.peer-focus\:outline-none{outline:2px solid transparent;outline-offset:2px}@media (min-width:640px){.sm\:col-span-2{grid-column:span 2/span 2}.sm\:inline{display:inline}.sm\:w-auto{width:auto}.sm\:max-w-lg{max-width:32rem}.sm\:grid-cols-2{grid-template-columns:repeat(2,minmax(0,1fr))}.sm\:grid-cols-3{grid-template-columns:repeat(3,minmax(0,1fr))}.sm\:grid-cols-4{grid-template-columns:repeat(4,minmax(0,1fr))}.sm\:flex-row{flex-direction:row}.sm\:items-center{align-items:center}.sm\:justify-end{justify-content:flex-end}.sm\:rounded-3xl{border-radius:1.5rem}.sm\:border-t-0{border-top-width:0}.sm\:p-4{padding:1rem}.sm\:p-8{padding:2rem}.sm\:px-4{padding-left:1rem;padding-right:1rem}.sm\:pt-0{padding-top:0}.sm\:text-right{text-align:right}.sm\:text-sm{font-size:.875rem;line-height:1.25rem}}@media (min-width:768px){.md\:col-span-3{grid-column:span 3/span 3}.md\:mx-0{margin-left:0;margin-right:0}.md\:ml-72{margin-left:18rem}.md\:flex{display:flex}.md\:hidden{display:none}.md\:w-1\/2{width:50%}.md\:w-auto{width:auto}.md\:flex-none{flex:none}.md\:translate-x-0{--tw-translate-x:0px;transform:translate(var(--tw-translate-x),var(--tw-translate-y)) rotate(var(--tw-rotate)) skewX(var(--tw-skew-x)) skewY(var(--tw-skew-y)) scaleX(var(--tw-scale-x)) scaleY(var(--tw-scale-y))}.md\:grid-cols-2{grid-template-columns:repeat(2,minmax(0,1fr))}.md\:grid-cols-3{grid-template-columns:repeat(3,minmax(0,1fr))}.md\:grid-cols-4{grid-template-columns:repeat(4,minmax(0,1fr))}.md\:flex-row{flex-direction:row}.md\:items-center{align-items:center}.md\:justify-start{justify-content:flex-start}.md\:gap-4{gap:1rem}.md\:p-10{padding:2.5rem}.md\:p-12{padding:3rem}.md\:p-5{padding:1.25rem}.md\:p-6{padding:1.5rem}.md\:p-7{padding:1.75rem}.md\:p-8{padding:2rem}.md\:px-10{padding-left:2.5rem;padding-right:2.5rem}.md\:px-8{padding-left:2rem;padding-right:2rem}.md\:py-16{padding-top:4rem;padding-bottom:4rem}.md\:text-left{text-align:left}.md\:text-2xl{font-size:1.5rem;line-height:2rem}.md\:text-3xl{font-size:1.875rem;line-height:2.25rem}.md\:text-5xl{font-size:3rem;line-height:1}.md\:text-\[11px\]{font-size:11px}.md\:text-base{font-size:1rem;line-height:1.5rem}.md\:text-lg{font-size:1.125rem;line-height:1.75rem}.md\:text-sm{font-size:.875rem;line-height:1.25rem}.md\:text-xl{font-size:1.25rem;line-height:1.75rem}}</style>
    <style>
        .pm-tile{box-shadow:inset 0 0 0 0 var(--c)}
        .pm-tile:hover{border-color:var(--c);transform:translateY(-2px);box-shadow:0 12px 30px -14px var(--c)}
        .pm-tile-glow{position:absolute;inset:auto -30% -60% auto;width:120px;height:120px;border-radius:50%;background:var(--c);opacity:.12;filter:blur(20px)}
        .pm-frame{--c:#00D9FF;padding:2px;border-radius:26px;background:linear-gradient(135deg,var(--c),#00D9FF 55%,var(--c));box-shadow:0 20px 50px -20px var(--c)}
        .pm-frame-in{border-radius:24px;padding:18px;background:radial-gradient(120% 80% at 0% 0%,color-mix(in srgb,var(--c) 28%,#04122d) 0%,#04122d 55%,#020817 100%)}
        .pm-badge{font-size:10px;font-weight:900;padding:5px 10px;border-radius:999px;background:var(--c);color:#fff;white-space:nowrap;box-shadow:0 6px 18px -6px var(--c)}
        .pm-copy{font-size:12px;font-weight:800;color:#fff;padding:9px 14px;border-radius:14px;background:var(--c);box-shadow:0 8px 20px -8px var(--c)}
        .pm-qr{display:inline-block;background:#fff;padding:12px;border-radius:22px;border:4px solid var(--c);box-shadow:0 16px 40px -16px var(--c)}
        .pm-qr img{width:220px;height:220px;object-fit:contain;display:block;margin:auto}
        .pm-field{width:100%;background:#020817cc;border:1px solid rgba(255,255,255,.12);border-radius:16px;padding:12px 14px;color:#fff;font-size:14px;outline:none}
        .pm-field:focus{border-color:var(--c)}
        .pm-verify{width:100%;display:flex;align-items:center;justify-content:center;gap:8px;padding:14px;border-radius:18px;font-weight:900;font-size:13px;color:#fff;background:linear-gradient(135deg,var(--c),color-mix(in srgb,var(--c) 55%,#000));box-shadow:0 14px 30px -12px var(--c)}
        .pm-verify:disabled{opacity:.6}
        /* 🎬 animation banner */
        .pm-anim{--c:#00D9FF;position:relative;display:flex;gap:16px;align-items:center;min-height:200px;padding:14px 16px;border-radius:24px;overflow:hidden;border:1px solid color-mix(in srgb,var(--c) 45%,transparent);background:radial-gradient(90% 120% at 100% 0%,color-mix(in srgb,var(--c) 35%,#04122d) 0%,#04122d 60%)}
        .pa-phone{position:relative;flex-shrink:0;width:112px;height:186px;border-radius:22px;background:#0b1220;border:3px solid #1e293b;box-shadow:0 14px 30px -10px #000,0 0 0 1px color-mix(in srgb,var(--c) 40%,transparent)}
        .pa-notch{position:absolute;top:5px;left:50%;transform:translateX(-50%);width:34px;height:5px;border-radius:9px;background:#1e293b;z-index:2}
        .pa-screen{position:absolute;inset:4px;border-radius:17px;overflow:hidden;background:#f8fafc;color:#0f172a;font-size:8px}
        .pa-screen.in{animation:paIn .45s ease}
        @keyframes paIn{from{opacity:0;transform:translateX(14px)}to{opacity:1;transform:none}}
        .sc-head{background:var(--c);color:#fff;font-weight:900;font-size:9px;padding:14px 8px 6px;text-align:center}
        .sc-center{display:flex;flex-direction:column;align-items:center;justify-content:center;height:130px;gap:8px}
        .sc-logo{width:46px;height:46px;border-radius:14px;background:var(--c);color:#fff;display:flex;align-items:center;justify-content:center;font-size:22px;animation:paPulse 1.2s ease infinite;overflow:hidden}
        .sc-logo img{width:100%;height:100%;object-fit:contain;background:#fff}
        .sc-cap{font-weight:800;font-size:9px}
        @keyframes paPulse{50%{transform:scale(1.1);box-shadow:0 0 0 8px color-mix(in srgb,var(--c) 20%,transparent)}}
        .sc-grid{display:grid;grid-template-columns:1fr 1fr;gap:6px;padding:10px 8px}
        .sc-tile{background:#fff;border:1px solid #e2e8f0;border-radius:9px;padding:8px 2px;text-align:center;font-weight:800;font-size:7px;line-height:1.1}
        .sc-tile i{display:block;width:16px;height:16px;border-radius:6px;margin:0 auto 4px;background:#cbd5e1}
        .sc-tile.hit{border-color:var(--c);animation:paHit 2.6s ease}
        .sc-tile.hit i{background:var(--c)}
        @keyframes paHit{0%,45%{background:#fff}55%,100%{background:color-mix(in srgb,var(--c) 18%,#fff);transform:scale(1.04)}}
        .sc-lbl{font-size:8px;color:#64748b;font-weight:700;padding:14px 10px 4px}
        .sc-input{margin:0 8px;border:1.5px solid var(--c);border-radius:8px;padding:8px 6px;font-weight:900;font-size:9px;background:#fff;white-space:nowrap;overflow:hidden}
        .sc-input.mono{font-family:ui-monospace,monospace;font-size:8.5px}
        .sc-type{display:inline-block;overflow:hidden;white-space:nowrap;vertical-align:bottom;border-right:1.5px solid var(--c);width:0;animation:paType 1.4s steps(var(--n)) .3s forwards,paCaret .6s step-end infinite}
        @keyframes paType{to{width:calc(var(--n) * 1ch + 2px)}}
        @keyframes paCaret{50%{border-color:transparent}}
        .sc-pin{display:flex;justify-content:center;gap:7px;padding:34px 0 14px}
        .sc-pin i{width:10px;height:10px;border-radius:50%;border:1.5px solid var(--c);animation:paPin 2s steps(1) forwards}
        .sc-pin i:nth-child(1){animation-delay:.2s}.sc-pin i:nth-child(2){animation-delay:.5s}.sc-pin i:nth-child(3){animation-delay:.8s}.sc-pin i:nth-child(4){animation-delay:1.1s}.sc-pin i:nth-child(5){animation-delay:1.4s}
        @keyframes paPin{to{background:var(--c)}}
        .sc-hold{margin:0 10px;text-align:center;background:var(--c);color:#fff;border-radius:999px;padding:6px;font-weight:900;font-size:7.5px}
        .sc-scan{position:relative;margin:16px auto;width:80px;height:80px;border:2px solid var(--c);border-radius:10px;overflow:hidden}
        .sc-qr{position:absolute;inset:10px;background:repeating-linear-gradient(90deg,#0f172a 0 4px,transparent 4px 8px),repeating-linear-gradient(0deg,#0f172a 0 4px,transparent 4px 8px);opacity:.75}
        .sc-line{position:absolute;left:0;right:0;height:2px;background:var(--c);box-shadow:0 0 8px var(--c);animation:paScan 1.3s ease-in-out infinite alternate}
        @keyframes paScan{from{top:6%}to{top:92%}}
        .sc-done{display:flex;flex-direction:column;align-items:center;justify-content:center;height:100%;gap:6px;background:linear-gradient(180deg,color-mix(in srgb,var(--c) 14%,#fff),#fff)}
        .sc-check{width:40px;height:40px;border-radius:50%;background:#10b981;color:#fff;font-size:22px;font-weight:900;display:flex;align-items:center;justify-content:center;animation:paPop .5s cubic-bezier(.3,1.6,.5,1)}
        @keyframes paPop{from{transform:scale(0)}}
        .sc-ok{font-weight:900;font-size:10px}
        .sc-trx{font-size:7.5px;background:#fff;border:1px dashed var(--c);border-radius:7px;padding:4px 6px;animation:paPulse 1.2s ease infinite}
        .pa-finger{position:absolute;font-size:22px;opacity:0;transform:translate(-50%,10px);transition:opacity .3s,transform .3s;z-index:3;pointer-events:none;filter:drop-shadow(0 3px 4px rgba(0,0,0,.4))}
        .pa-finger.show{opacity:1;transform:translate(-50%,0);animation:paTap 2.6s ease}
        @keyframes paTap{0%{transform:translate(-50%,30px);opacity:0}35%{transform:translate(-50%,0);opacity:1}45%{transform:translate(-50%,0) scale(.85)}55%,100%{transform:translate(-50%,0) scale(1)}}
        .pa-side{min-width:0;flex:1}
        .pa-kicker{font-size:10px;font-weight:900;letter-spacing:.12em;color:var(--c);filter:brightness(1.3)}
        .pa-title{margin-top:8px;font-size:15px;font-weight:800;color:#fff;line-height:1.35;min-height:42px;animation:paIn .45s ease}
        .pa-title b{color:var(--c);filter:brightness(1.4);font-family:ui-monospace,monospace;word-break:break-all}
        .pa-num{display:inline-flex;align-items:center;justify-content:center;width:22px;height:22px;border-radius:50%;background:var(--c);color:#fff;font-size:11px;font-weight:900;margin-right:8px;vertical-align:1px}
        .pa-dots{display:flex;gap:5px;margin-top:12px}
        .pa-dots i{height:6px;flex:1;max-width:26px;border-radius:9px;background:rgba(255,255,255,.15);transition:.3s}
        .pa-dots i.on{background:var(--c)}
        .pa-bar{margin-top:10px;height:3px;border-radius:9px;background:rgba(255,255,255,.08);overflow:hidden}
        .pa-bar i{display:block;height:100%;width:0;background:linear-gradient(90deg,var(--c),#fff)}
        @media (max-width:380px){.pa-title{font-size:13px}.pm-anim{gap:12px;padding:12px}}
    </style>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <style>
        body { 
            touch-action: manipulation; 
            background: #020817; 
            background-image: radial-gradient(circle at 50% 100%, rgba(0, 217, 255, 0.12) 0%, transparent 60%);
            min-height: 100vh;
        }

        .card-glow-cyan {
            background: linear-gradient(145deg, #051433 0%, #030d22 100%);
            border: 1.5px solid #00D9FF;
            box-shadow: 0 0 16px rgba(0, 217, 255, 0.22), inset 0 0 12px rgba(0, 217, 255, 0.04);
            border-radius: 1.5rem;
            transition: all 0.3s cubic-bezier(0.16, 1, 0.3, 1);
        }
        .card-glow-green {
            background: linear-gradient(145deg, #041c2c 0%, #02101a 100%);
            border: 1.5px solid #00D89A;
            box-shadow: 0 0 16px rgba(0, 216, 154, 0.22), inset 0 0 12px rgba(0, 216, 154, 0.04);
            border-radius: 1.5rem;
            transition: all 0.3s cubic-bezier(0.16, 1, 0.3, 1);
        }
        .card-glow-blue {
            background: linear-gradient(145deg, #05173d 0%, #020d24 100%);
            border: 1.5px solid #007BFF;
            box-shadow: 0 0 16px rgba(0, 123, 255, 0.22), inset 0 0 12px rgba(0, 123, 255, 0.04);
            border-radius: 1.5rem;
            transition: all 0.3s cubic-bezier(0.16, 1, 0.3, 1);
        }
        .card-glow-purple {
            background: linear-gradient(145deg, #100b33 0%, #08051c 100%);
            border: 1.5px solid #8B5CF6;
            box-shadow: 0 0 16px rgba(139, 92, 246, 0.22), inset 0 0 12px rgba(139, 92, 246, 0.04);
            border-radius: 1.5rem;
            transition: all 0.3s cubic-bezier(0.16, 1, 0.3, 1);
        }

        .header-menu-btn {
            background: #041433;
            border: 1.5px solid #00D9FF;
            box-shadow: 0 0 12px rgba(0, 217, 255, 0.35);
            border-radius: 1rem;
        }

        .header-balance-pill {
            background: linear-gradient(90deg, #061a3d 0%, #082454 100%);
            border: 1px solid #00D9FF;
            box-shadow: 0 0 10px rgba(0, 217, 255, 0.2);
            border-radius: 9999px;
        }

        .snz-gradient {
            background: linear-gradient(135deg, #007BFF 0%, #00D9FF 50%, #00D89A 100%);
        }

        .active-tab { 
            background: linear-gradient(135deg, #007BFF 0%, #00D9FF 50%, #00D89A 100%) !important; 
            color: #020817 !important; 
            font-weight: 800 !important;
            box-shadow: 0 0 15px rgba(0, 217, 255, 0.4);
        }

        @keyframes pulseGlow {
            0%, 100% { transform: scale(1); opacity: 0.9; }
            50% { transform: scale(1.06); opacity: 1; }
        }
        .pulse-icon { animation: pulseGlow 2.5s infinite ease-in-out; }
        .plan-filter-btn { background: #04122d; color: #94a3b8; }
        .plan-filter-btn.active-filter { background: linear-gradient(135deg, #007BFF 0%, #00D9FF 50%, #00D89A 100%); color: #061535; border-color: transparent; box-shadow: 0 0 14px rgba(0,217,255,.35); }


        /* Sidebar: গ্রুপ টাইটেল + ছোট ফোনে মেনু স্ক্রল */
        .sidebar-top { display: flex; flex-direction: column; flex: 1 1 auto; min-height: 0; }
        .sidebar-scroll { overflow-y: auto; min-height: 0; flex: 1 1 auto; scrollbar-width: none; }
        .sidebar-scroll::-webkit-scrollbar { display: none; }
        .nav-group-label {
            font-size: 10px; font-weight: 800; letter-spacing: 0.14em; text-transform: uppercase;
            color: #4b6a9b; padding: 14px 16px 4px;
        }
        .nav-group-label:first-child { padding-top: 4px; }
    </style>
</head>
<body class="text-[#F5F8FC] font-sans overflow-x-hidden selection:bg-[#00D9FF] selection:text-[#020817]">

    <div id="universalToast" class="fixed top-5 left-1/2 -translate-x-1/2 z-50 w-[90%] max-w-sm bg-[#051433]/95 backdrop-blur-md border border-[#00D9FF]/50 shadow-2xl rounded-2xl p-4 flex items-center gap-3.5 transition-all duration-300 opacity-0 -translate-y-8 pointer-events-none">
        <div id="toastIconContainer" class="w-10 h-10 rounded-xl flex items-center justify-center text-lg shrink-0"></div>
        <div class="flex-1 overflow-hidden">
            <h4 id="toastTitle" class="text-[11px] font-bold uppercase tracking-wider text-[#00D9FF]"></h4>
            <p id="toastMessage" class="text-sm font-medium text-white truncate"></p>
        </div>
    </div>

    <!-- REUSABLE SLEEK MODAL DIALOG -->
    <?php if ($modalData): ?>
    <div id="sleekActionModal" class="fixed inset-0 bg-black/80 backdrop-blur-md z-50 flex items-center justify-center p-4">
        <div class="bg-[#04122d] border border-[#00D9FF] rounded-3xl p-6 md:p-8 max-w-md w-full shadow-[0_0_25px_rgba(0,217,255,0.3)] space-y-4 text-center">
            <div class="w-14 h-14 rounded-full mx-auto flex items-center justify-center text-2xl <?= $modalData['type'] === 'balance' ? 'bg-amber-500/20 text-amber-400 border border-amber-500/40' : 'bg-[#00D9FF]/20 text-[#00D9FF] border border-[#00D9FF]/40' ?>">
                <i class="fa-solid <?= $modalData['type'] === 'balance' ? 'fa-wallet' : 'fa-triangle-exclamation' ?>"></i>
            </div>
            <h3 class="text-base md:text-lg font-bold text-white"><?= htmlspecialchars($modalData['title']) ?></h3>
            <p class="text-xs text-slate-300 leading-relaxed"><?= htmlspecialchars($modalData['message']) ?></p>
            <div class="pt-2 flex flex-col gap-2">
                <button onclick="<?= htmlspecialchars($modalData['btn_action'], ENT_QUOTES) ?>; document.getElementById('sleekActionModal').remove();" class="w-full snz-gradient text-[#020817] font-black py-3 rounded-2xl text-xs transition">
                    <?= htmlspecialchars($modalData['btn_text']) ?>
                </button>
                <button onclick="document.getElementById('sleekActionModal').remove();" class="w-full bg-[#020817] text-slate-400 hover:text-white py-2.5 rounded-2xl text-xs transition border border-[#0e275c]">
                    Close
                </button>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <div id="drawerOverlay" onclick="toggleDrawer()" class="fixed inset-0 bg-[#020817]/85 backdrop-blur-sm z-40 hidden transition-opacity"></div>

    <!-- SIDEBAR DRAWER -->
    <aside id="sidebar" class="fixed top-0 left-0 bottom-0 w-72 bg-[#030d24]/95 backdrop-blur-2xl border-r border-[#0e275c] z-50 transform -translate-x-full md:translate-x-0 transition-transform duration-300 flex flex-col justify-between">
        <div class="sidebar-top">
            <div class="p-5 border-b border-[#0e275c] flex justify-between items-center">
                <div class="flex items-center gap-2.5">
                    <img src="<?= htmlspecialchars($SITE_LOGO) ?>" onerror="this.onerror=null;this.src='data:image/svg+xml,%3Csvg xmlns=%22http://www.w3.org/2000/svg%22 viewBox=%220 0 64 64%22%3E%3Cdefs%3E%3ClinearGradient id=%22g%22 x1=%220%22 y1=%220%22 x2=%221%22 y2=%221%22%3E%3Cstop offset=%220%22 stop-color=%22%23007BFF%22/%3E%3Cstop offset=%22.5%22 stop-color=%22%2300D9FF%22/%3E%3Cstop offset=%221%22 stop-color=%22%2300D89A%22/%3E%3C/linearGradient%3E%3C/defs%3E%3Crect width=%2264%22 height=%2264%22 rx=%2216%22 fill=%22url(%23g)%22/%3E%3Ctext x=%2232%22 y=%2244%22 font-family=%22Arial%22 font-weight=%22900%22 font-size=%2234%22 text-anchor=%22middle%22 fill=%22%23061535%22%3ES%3C/text%3E%3C/svg%3E'" alt="Logo" class="w-9 h-9 object-contain rounded-xl shadow-lg shadow-cyan-500/20">
                    <div>
                        <h2 class="text-lg font-black tracking-wider text-white"><?= snzBrandHtml($SITE_NAME) ?></h2>
                        <p class="text-[9px] text-[#00D89A] font-bold tracking-widest uppercase"><?= htmlspecialchars($SITE_TAGLINE) ?></p>
                    </div>
                </div>
                <button onclick="toggleDrawer()" class="md:hidden text-slate-400 hover:text-white text-lg">
                    <i class="fa-solid fa-xmark"></i>
                </button>
            </div>

            <nav class="p-3 space-y-1 text-sm font-medium sidebar-scroll">
                <div class="nav-group-label">Main</div>
                <button onclick="switchView('view-dashboard')" id="nav-dashboard" class="nav-item w-full flex items-center gap-3.5 px-4 py-2.5 rounded-2xl hover:bg-[#092257] text-slate-300 active-tab transition">
                    <i class="fa-solid fa-chart-pie w-5 text-center text-base"></i> Dashboard
                </button>
                <button onclick="switchView('view-add-fund')" id="nav-add-fund" class="nav-item w-full flex items-center gap-3.5 px-4 py-2.5 rounded-2xl hover:bg-[#092257] text-slate-300 transition">
                    <i class="fa-solid fa-wallet w-5 text-center text-base text-[#00D89A]"></i> Add Funds
                </button>
                <button onclick="switchView('view-history')" id="nav-history" class="nav-item w-full flex items-center justify-between px-4 py-2.5 rounded-2xl hover:bg-[#092257] text-slate-300 transition">
                    <span class="flex items-center gap-3.5">
                        <i class="fa-solid fa-clock-rotate-left w-5 text-center text-base text-[#00D9FF]"></i> History
                    </span>
                    <span class="text-[10px] font-bold px-2 py-0.5 rounded-full bg-[#00D9FF]/20 text-[#00D9FF] border border-[#00D9FF]/30"><?= count($allTransactionsHistory) ?></span>
                </button>
                <div class="nav-group-label">Plans & License</div>
                <button onclick="switchView('view-plans')" id="nav-plans" class="nav-item w-full flex items-center gap-3.5 px-4 py-2.5 rounded-2xl hover:bg-[#092257] text-slate-300 transition">
                    <i class="fa-solid fa-layer-group w-5 text-center text-base text-[#007BFF]"></i> Pricing Plans
                </button>
                <button onclick="switchView('view-my-plan')" id="nav-my-plan" class="nav-item w-full flex items-center justify-between px-4 py-2.5 rounded-2xl hover:bg-[#092257] text-slate-300 transition">
                    <span class="flex items-center gap-3.5">
                        <i class="fa-solid fa-key w-5 text-center text-base text-[#00D9FF]"></i> My Plans
                    </span>
                    <span class="text-[10px] font-bold px-2 py-0.5 rounded-full bg-[#00D89A]/20 text-[#00D89A] border border-[#00D89A]/30"><?= count($activeKeys) ?></span>
                </button>
                <div class="nav-group-label">Payment Setup</div>
                <button onclick="switchView('view-wallet-setup')" id="nav-wallet-setup" class="nav-item w-full flex items-center justify-between px-4 py-2.5 rounded-2xl hover:bg-[#092257] text-slate-300 transition">
                    <span class="flex items-center gap-3.5">
                        <i class="fa-solid fa-sliders w-5 text-center text-base text-[#00D9FF]"></i> Wallet Setup
                    </span>
                    <span class="text-[9px] font-black px-2 py-0.5 rounded-full bg-[#00D9FF]/20 text-[#00D9FF] border border-[#00D9FF]/50 uppercase tracking-widest animate-pulse">NEW</span>
                </button>
                <button onclick="switchView('view-brands')" id="nav-brands" class="nav-item w-full flex items-center justify-between px-4 py-2.5 rounded-2xl hover:bg-[#092257] text-slate-300 transition">
                    <span class="flex items-center gap-3.5">
                        <i class="fa-solid fa-robot w-5 text-center text-base text-[#FBBF24]"></i> Brands (Bot Key)
                    </span>
                    <span class="text-[9px] font-bold px-2 py-0.5 rounded-full <?= $userBrandKeys ? 'bg-[#00D89A]/20 text-[#00D89A] border border-[#00D89A]/40' : 'bg-slate-800 text-slate-400' ?>"><?= count($userBrandKeys) ?>/<?= (int) $brandQuota['limit'] ?></span>
                </button>
                <button onclick="switchView('view-custom-sms')" id="nav-custom-sms" class="nav-item w-full flex items-center gap-3.5 px-4 py-2.5 rounded-2xl hover:bg-[#092257] text-slate-300 transition">
                    <i class="fa-solid fa-envelope-open-text w-5 text-center text-base text-[#38BDF8]"></i> Custom SMS
                </button>
                <div class="nav-group-label">Help & Tools</div>
                <button onclick="switchView('view-app')" id="nav-app" class="nav-item w-full flex items-center gap-3.5 px-4 py-2.5 rounded-2xl hover:bg-[#092257] text-slate-300 transition">
                    <i class="fa-brands fa-android w-5 text-center text-base text-[#35F58A]"></i> Download App
                </button>
                <button onclick="switchView('view-support')" id="nav-support" class="nav-item w-full flex items-center gap-3.5 px-4 py-2.5 rounded-2xl hover:bg-[#092257] text-slate-300 transition">
                    <i class="fa-solid fa-headset w-5 text-center text-base text-[#00D9FF]"></i> Support
                </button>
                <button onclick="switchView('view-developer')" id="nav-developer" class="nav-item w-full flex items-center justify-between px-4 py-2.5 rounded-2xl hover:bg-[#092257] text-slate-300 transition">
                    <span class="flex items-center gap-3.5">
                        <i class="fa-solid fa-code w-5 text-center text-base text-[#00D9FF]"></i> Developer API
                    </span>
                    <span class="text-[9px] font-black px-2 py-0.5 rounded-full bg-[#00D9FF]/20 text-[#00D9FF] border border-[#00D9FF]/40 uppercase tracking-wider">PRO</span>
                </button>
            </nav>
        </div>

        <div class="p-4 border-t border-[#0e275c]">
            <a href="?logout=1" class="w-full flex items-center justify-center gap-2 py-3 bg-[#071638] hover:bg-[#0c245c] text-slate-300 border border-[#143985] rounded-2xl text-xs font-bold transition shadow-lg shadow-black/40">
                <i class="fa-solid fa-arrow-right-from-bracket"></i> Sign Out
            </a>
        </div>
    </aside>

    <!-- Main Container -->
    <div class="md:ml-72 min-h-screen flex flex-col min-w-0">
        <header class="bg-[#020817]/80 backdrop-blur-md px-4 md:px-8 py-3.5 flex justify-between items-center sticky top-0 z-30">
            <div class="flex items-center gap-3">
                <button onclick="toggleDrawer()" class="header-menu-btn w-11 h-11 flex items-center justify-center text-[#00D9FF] text-xl transition active:scale-95">
                    <i class="fa-solid fa-bars"></i>
                </button>
                <div class="flex items-center gap-2.5">
                    <img src="<?= htmlspecialchars($SITE_LOGO) ?>" onerror="this.onerror=null;this.src='data:image/svg+xml,%3Csvg xmlns=%22http://www.w3.org/2000/svg%22 viewBox=%220 0 64 64%22%3E%3Cdefs%3E%3ClinearGradient id=%22g%22 x1=%220%22 y1=%220%22 x2=%221%22 y2=%221%22%3E%3Cstop offset=%220%22 stop-color=%22%23007BFF%22/%3E%3Cstop offset=%22.5%22 stop-color=%22%2300D9FF%22/%3E%3Cstop offset=%221%22 stop-color=%22%2300D89A%22/%3E%3C/linearGradient%3E%3C/defs%3E%3Crect width=%2264%22 height=%2264%22 rx=%2216%22 fill=%22url(%23g)%22/%3E%3Ctext x=%2232%22 y=%2244%22 font-family=%22Arial%22 font-weight=%22900%22 font-size=%2234%22 text-anchor=%22middle%22 fill=%22%23061535%22%3ES%3C/text%3E%3C/svg%3E'" alt="SNZPay Logo" class="w-9 h-9 object-contain rounded-xl">
                    <div>
                        <h1 class="text-base md:text-lg font-black tracking-wide text-white"><?= snzBrandHtml($SITE_NAME) ?></h1>
                        <p class="text-[9px] text-[#00D89A] font-bold tracking-widest uppercase"><?= htmlspecialchars($SITE_TAGLINE) ?></p>
                    </div>
                </div>
            </div>

            <div class="header-balance-pill flex items-center gap-2 px-3 sm:px-4 py-2 shadow-lg shadow-cyan-500/10 shrink-0">
                <i class="fa-solid fa-coins text-[#00D9FF] text-sm"></i>
                <span class="text-xs text-slate-300 font-medium">Balance:</span>
                <span class="text-xs md:text-sm font-black text-[#00D89A]">৳ <?= number_format(floatval($user['balance']), 2) ?></span>
            </div>
        </header>

        <main class="p-4 md:p-8 flex-1 max-w-4xl w-full mx-auto space-y-6 min-w-0">

            <!-- ========================================================================= -->
            <!-- 1. DASHBOARD VIEW (SILENT REALTIME FEED) -->
            <!-- ========================================================================= -->
            <section id="view-dashboard" class="content-view space-y-6">
                <!-- Notice Banner -->
                <div class="flex items-center gap-3 bg-[#04122d] border border-[#00D9FF]/40 rounded-2xl px-4 py-3 shadow-[0_0_15px_rgba(0,217,255,0.15)] overflow-hidden">
                    <div class="flex items-center gap-1.5 text-[#00D9FF] font-bold text-xs uppercase shrink-0">
                        <i class="fa-solid fa-bullhorn text-[#00D9FF] animate-bounce"></i> Notice:
                    </div>
                    <div class="overflow-hidden w-full">
                        <marquee behavior="scroll" direction="left" scrollamount="6" class="text-xs text-slate-200 font-medium tracking-wide">
                            <?= htmlspecialchars($noticeText) ?>
                        </marquee>
                    </div>
                </div>

                <!-- 4 Live Cards -->
                <div class="grid grid-cols-2 md:grid-cols-4 gap-3.5 md:gap-4">
                    <div class="card-glow-cyan p-4 flex flex-col justify-between h-36">
                        <div class="flex items-center justify-between">
                            <span class="text-[10px] md:text-[11px] font-bold text-slate-300 uppercase tracking-wider">Today Deposit</span>
                            <div class="w-8 h-8 rounded-full bg-[#00D9FF]/20 text-[#00D9FF] flex items-center justify-center text-xs shadow-[0_0_10px_rgba(0,217,255,0.4)]">
                                <i class="fa-solid fa-wallet"></i>
                            </div>
                        </div>
                        <div id="metric-today-deposit" class="text-xl md:text-2xl font-black text-[#00D9FF]">৳ <?= number_format($todayDeposit, 2) ?></div>
                        <div class="flex items-center gap-1.5 text-[10px] text-slate-400 font-medium">
                            <i class="fa-solid fa-arrow-trend-up text-[#00D9FF]"></i> Live Active
                        </div>
                    </div>

                    <div class="card-glow-green p-4 flex flex-col justify-between h-36">
                        <div class="flex items-center justify-between">
                            <span class="text-[10px] md:text-[11px] font-bold text-slate-300 uppercase tracking-wider">Pending</span>
                            <div class="w-8 h-8 rounded-full bg-[#00D89A]/20 text-[#00D89A] flex items-center justify-center text-xs shadow-[0_0_10px_rgba(0,216,154,0.4)]">
                                <i class="fa-solid fa-coins"></i>
                            </div>
                        </div>
                        <div id="metric-pending-count" class="text-xl md:text-2xl font-black text-[#00D89A]">
                            <?= $pendingTodayCount ?> <span class="text-xs font-semibold text-slate-400">(৳<?= number_format($pendingTodayAmount, 2) ?>)</span>
                        </div>
                        <div class="flex items-center gap-1.5 text-[10px] text-slate-400 font-medium">
                            <i class="fa-solid fa-clock text-[#00D89A]"></i> Processing
                        </div>
                    </div>

                    <div class="card-glow-blue p-4 flex flex-col justify-between h-36">
                        <div class="flex items-center justify-between">
                            <span class="text-[10px] md:text-[11px] font-bold text-slate-300 uppercase tracking-wider">Approved</span>
                            <div class="w-8 h-8 rounded-full bg-[#007BFF]/20 text-[#007BFF] flex items-center justify-center text-xs shadow-[0_0_10px_rgba(0,123,255,0.4)]">
                                <i class="fa-solid fa-circle-check"></i>
                            </div>
                        </div>
                        <div id="metric-approved-count" class="text-xl md:text-2xl font-black text-[#00D9FF]"><?= $approvedTodayCount ?> Items</div>
                        <div class="flex items-center gap-1.5 text-[10px] text-slate-400 font-medium">
                            <i class="fa-solid fa-check text-[#00D89A]"></i> Verified
                        </div>
                    </div>

                    <div class="card-glow-purple p-4 flex flex-col justify-between h-36">
                        <div class="flex items-center justify-between">
                            <span class="text-[10px] md:text-[11px] font-bold text-slate-300 uppercase tracking-wider">Lifetime</span>
                            <div class="w-8 h-8 rounded-full bg-[#8B5CF6]/20 text-[#8B5CF6] flex items-center justify-center text-xs shadow-[0_0_10px_rgba(139,92,246,0.4)]">
                                <i class="fa-solid fa-hourglass-half"></i>
                            </div>
                        </div>
                        <div id="metric-lifetime-deposit" class="text-xl md:text-2xl font-black text-white">৳ <?= number_format($lifetimeDeposit, 2) ?></div>
                        <div class="flex items-center gap-1.5 text-[10px] text-slate-400 font-medium">
                            <i class="fa-solid fa-chart-column text-[#8B5CF6]"></i> Total earnings
                        </div>
                    </div>
                </div>

                <!-- TODAY'S RECEIVED TRANSACTIONS -->
                <div class="space-y-3.5 pt-2">
                    <div class="flex justify-between items-center">
                        <h3 class="text-sm md:text-base font-bold text-white flex items-center gap-2">
                            <i class="fa-solid fa-comment-sms text-[#00D9FF]"></i> Today's Received SMS &amp; Binance Orders
                        </h3>
                        
                        <div class="flex items-center gap-2">
                            <span id="live-pending-badge" class="text-xs bg-[#03132e] text-amber-400 font-bold px-3 py-1 rounded-full border border-[#174396] shadow-sm">
                                <?= count($todayPendingSms) ?> Pending
                            </span>
                            
                            <?php if (count($todayPendingSms) > 0): ?>
                                <form method="POST" onsubmit="return confirm('Clear your pending transactions?');">
                                <input type="hidden" name="csrf" value="<?= $CSRF ?>">
                                    <input type="hidden" name="action_clear_all_fake_sms" value="1">
                                    <button type="submit" class="text-xs bg-rose-500/20 hover:bg-rose-500/30 text-rose-400 border border-rose-500/40 px-3 py-1 rounded-full font-bold transition">
                                        <i class="fa-solid fa-trash-can"></i> Clear All
                                    </button>
                                </form>
                            <?php endif; ?>
                        </div>
                    </div>

                    <div id="live-pending-list">
                        <?php if (count($todayPendingSms) > 0): ?>
                            <div class="space-y-2.5">
                                <?php foreach ($todayPendingSms as $sms): 
                                    $method = strtoupper($sms['method']);
                                    $badgeColor = "#00D9FF";
                                    $currencySign = "৳";
                                    $unitText = "";
                                    if (stripos($method, 'BKASH') !== false) $badgeColor = "#E2136E";
                                    elseif (stripos($method, 'NAGAD') !== false) $badgeColor = "#EA580C";
                                    elseif (stripos($method, 'QR') !== false) $badgeColor = "#0284C7";
                                    elseif (stripos($method, 'BINANCE') !== false) {
                                        $badgeColor = "#F59E0B";
                                        $currencySign = "$";
                                        $unitText = "USDT";
                                    }
                                    elseif (stripos($method, 'ROCKET') !== false) $badgeColor = "#7C3AED";
                                ?>
                                    <div class="bg-[#04122d] border border-[#00D9FF]/40 p-4 rounded-2xl flex flex-col sm:flex-row justify-between items-start sm:items-center gap-3 shadow-lg shadow-cyan-900/10">
                                        <div class="flex items-center gap-3 min-w-0 w-full sm:w-auto">
                                            <span class="text-[10px] font-black px-2.5 py-1 rounded-lg text-white shrink-0" style="background-color: <?= $badgeColor ?>;">
                                                <?= htmlspecialchars($method) ?>
                                            </span>
                                            <div class="min-w-0 flex-1">
                                                <div class="flex items-start gap-2 min-w-0">
                                                    <span class="font-mono text-sm font-bold text-white tracking-wider select-all break-all min-w-0"><?= htmlspecialchars($sms['trx_id']) ?></span>
                                                    <button onclick="copyText(<?= htmlspecialchars(json_encode($sms['trx_id']), ENT_QUOTES) ?>)" class="text-slate-400 hover:text-[#00D9FF] text-xs shrink-0 pt-0.5">
                                                        <i class="fa-regular fa-copy"></i>
                                                    </button>
                                                </div>
                                                <p class="text-[11px] text-slate-400">
                                                    From: <b class="text-slate-300"><?= htmlspecialchars($sms['sender'] ?: 'N/A') ?></b> • <?= date("h:i A", strtotime($sms['created_at'])) ?>
                                                </p>
                                            </div>
                                        </div>

                                        <div class="flex items-center justify-between sm:justify-end w-full sm:w-auto gap-4 border-t sm:border-t-0 border-[#0e275c] pt-2 sm:pt-0">
                                            <div class="text-left sm:text-right">
                                                <div class="text-base font-black text-[#00D9FF]"><?= $currencySign ?> <?= number_format(floatval($sms['amount']), 2) ?> <?= $unitText ?></div>
                                                <span class="text-[10px] bg-amber-500/15 text-amber-400 px-2 py-0.5 rounded-full font-bold border border-amber-500/30">
                                                    Pending Verification
                                                </span>
                                            </div>

                                            <form method="POST" onsubmit="return confirm('Delete this transaction?');">
                                <input type="hidden" name="csrf" value="<?= $CSRF ?>">
                                                <input type="hidden" name="action_delete_pending_sms" value="1">
                                                <input type="hidden" name="delete_payment_id" value="<?= $sms['id'] ?>">
                                                <button type="submit" class="text-slate-400 hover:text-rose-400 p-2 text-xs transition">
                                                    <i class="fa-solid fa-trash-can"></i>
                                                </button>
                                            </form>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php else: ?>
                            <div class="bg-[#04122d] border border-[#00D9FF]/40 rounded-3xl p-8 md:p-10 text-center flex flex-col items-center justify-center shadow-[0_0_20px_rgba(0,217,255,0.12)]">
                                <div class="w-16 h-16 rounded-full bg-[#00D9FF]/15 border border-[#00D9FF]/50 flex items-center justify-center text-2xl text-[#00D9FF] shadow-[0_0_20px_rgba(0,217,255,0.4)] mb-4 pulse-icon">
                                    <i class="fa-solid fa-envelope"></i>
                                </div>
                                <h4 class="text-sm md:text-base font-bold text-white">No pending transactions right now.</h4>
                                <p class="text-xs text-slate-400 mt-1">Your received SMS and Binance Pay orders will automatically appear here.</p>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- ACTIVE LICENSES SECTION -->
                <div class="space-y-3.5 pt-2">
                    <div class="flex justify-between items-center">
                        <h3 class="text-sm md:text-base font-bold text-white flex items-center gap-2">
                            <i class="fa-solid fa-key text-[#00D9FF]"></i> Active Licenses (<?= count($activeKeys) ?>)
                        </h3>
                        <button onclick="switchView('view-plans')" class="text-xs font-bold text-[#00D9FF] bg-[#00D9FF]/10 hover:bg-[#00D9FF]/20 border border-[#00D9FF]/40 px-3.5 py-1.5 rounded-full flex items-center gap-1.5 transition shadow-[0_0_10px_rgba(0,217,255,0.2)]">
                            <i class="fa-solid fa-circle-plus"></i> Add Plan
                        </button>
                    </div>

                    <?php if (count($activeKeys) > 0): ?>
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                            <?php foreach ($activeKeys as $k): ?>
                                <div class="bg-[#04122d] border border-[#00D9FF]/40 p-5 rounded-3xl shadow-xl flex flex-col justify-between">
                                    <div>
                                        <div class="flex justify-between items-start mb-3">
                                            <span class="text-[10px] uppercase font-bold text-[#00D9FF] bg-[#00D9FF]/10 border border-[#00D9FF]/30 px-2.5 py-0.5 rounded-full">
                                                <?= htmlspecialchars($k['plan_name']) ?>
                                            </span>
                                        </div>
                                        <div class="bg-[#020817] border border-[#0e275c] p-3 rounded-2xl flex items-center justify-between gap-2 mb-3">
                                            <span class="text-sm font-mono font-black text-[#00D89A] tracking-wider select-all break-all min-w-0"><?= htmlspecialchars($k['license_key']) ?></span>
                                            <button onclick="copyText(<?= htmlspecialchars(json_encode($k['license_key']), ENT_QUOTES) ?>)" class="text-xs bg-[#092257] hover:bg-[#007BFF] text-white px-3 py-1.5 rounded-xl border border-[#174396] transition">Copy</button>
                                        </div>
                                    </div>

                                    <div class="text-[11px] text-slate-300 border-t border-[#0e275c] pt-2 flex justify-between">
                                        <span>Expires On:</span>
                                        <b class="text-white"><?= date("d M Y, h:i A", strtotime($k['expires_at'])) ?></b>
                                    </div>
                                        <?php $kDevs = $keyDevicesMap[(int) $k['id']] ?? []; $kMax = max(1, (int) ($k['max_devices'] ?? 1)); ?>
                                        <div class="mt-3 rounded-2xl border border-[#0e275c] bg-[#04122d] p-3 space-y-2">
                                            <div class="flex items-center justify-between text-[10px] font-black uppercase tracking-wider">
                                                <span class="text-slate-400"><i class="fa-solid fa-mobile-screen text-[#00D9FF]"></i> Phones</span>
                                                <span class="<?= count($kDevs) >= $kMax ? 'text-amber-400' : 'text-[#00D89A]' ?>"><?= count($kDevs) ?> / <?= $kMax ?> used</span>
                                            </div>
                                            <?php foreach ($kDevs as $dvc): ?>
                                                <div class="flex items-center gap-2.5">
                                                    <span class="w-7 h-7 rounded-lg bg-[#00D89A]/15 text-[#00D89A] flex items-center justify-center text-xs shrink-0"><i class="fa-solid fa-mobile-screen-button"></i></span>
                                                    <div class="min-w-0">
                                                        <div class="text-xs font-bold text-white truncate"><?= htmlspecialchars($dvc['device_name'] ?: 'Android Phone') ?></div>
                                                        <div class="text-[10px] text-slate-400"><?= !empty($dvc['last_seen_at']) ? 'Active ' . date("d M, h:i A", strtotime($dvc['last_seen_at'])) : 'Connected' ?></div>
                                                    </div>
                                                    <span class="ml-auto w-2 h-2 rounded-full bg-[#00D89A] shadow-[0_0_8px_rgba(0,216,154,0.8)] shrink-0"></span>
                                                </div>
                                            <?php endforeach; ?>
                                            <?php if (!$kDevs): ?>
                                                <div class="text-[11px] text-slate-400">Not connected yet — login with this key in the app</div>
                                            <?php endif; ?>
                                            <div class="text-[10px] text-slate-500 pt-1 border-t border-[#0e275c]"><i class="fa-solid fa-link text-amber-400"></i> <?= (int) ($k['max_brand_keys'] ?? 1) ?> brand key<?= (int) ($k['max_brand_keys'] ?? 1) != 1 ? 's' : '' ?> included</div>
                                        </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php else: ?>
                        <div onclick="switchView('view-plans')" class="bg-[#04122d] border border-[#00D9FF]/40 hover:border-[#00D9FF] p-4 md:p-5 rounded-2xl flex items-center justify-between cursor-pointer transition shadow-[0_0_15px_rgba(0,217,255,0.1)] group">
                            <div class="flex items-center gap-3.5">
                                <div class="w-11 h-11 rounded-full bg-[#00D89A]/15 border border-[#00D89A]/40 flex items-center justify-center text-lg text-[#00D89A] shrink-0">
                                    <i class="fa-solid fa-shield-halved"></i>
                                </div>
                                <div>
                                    <h4 class="text-xs md:text-sm font-bold text-white group-hover:text-[#00D9FF] transition">No active licenses</h4>
                                    <p class="text-[11px] text-slate-400 mt-0.5">Purchase a plan with balance to get started and unlock all features.</p>
                                </div>
                            </div>
                            <div class="text-slate-400 group-hover:text-[#00D9FF] text-sm pr-2 transition">
                                <i class="fa-solid fa-chevron-right"></i>
                            </div>
                        </div>
                    <?php endif; ?>
                </div>
            </section>

            <!-- ========================================================================= -->
            <!-- 2. ALL TRANSACTION HISTORY VIEW -->
            <!-- ========================================================================= -->
            <section id="view-history" class="content-view hidden space-y-6">
                <div class="bg-[#04122d] border border-[#00D9FF]/40 rounded-3xl p-6 md:p-8 shadow-2xl space-y-6">
                    <div class="pb-4 border-b border-[#0e275c] flex flex-col sm:flex-row justify-between items-start sm:items-center gap-3">
                        <div>
                            <h2 class="text-base md:text-lg font-bold text-white flex items-center gap-2">
                                <i class="fa-solid fa-clock-rotate-left text-[#00D9FF]"></i> All Transaction History
                            </h2>
                            <p class="text-xs text-slate-400 mt-0.5">View all your received, approved, and pending payments.</p>
                        </div>

                        <div class="flex items-center gap-2 bg-[#020817] p-1.5 rounded-2xl border border-[#0e275c] text-xs">
                            <button onclick="filterHistoryItems('all')" id="hist-tab-all" class="px-3 py-1.5 rounded-xl font-bold bg-[#007BFF] text-white transition">All</button>
                            <button onclick="filterHistoryItems('used')" id="hist-tab-used" class="px-3 py-1.5 rounded-xl font-semibold text-slate-400 hover:text-white transition">Approved</button>
                            <button onclick="filterHistoryItems('pending')" id="hist-tab-pending" class="px-3 py-1.5 rounded-xl font-semibold text-slate-400 hover:text-white transition">Pending</button>
                        </div>
                    </div>

                    <?php if (count($allTransactionsHistory) > 0): ?>
                        <div class="space-y-3">
                            <?php foreach ($allTransactionsHistory as $th): 
                                $isUsed = ($th['status'] === 'used');
                                $mColor = "#00D9FF";
                                $curr = "৳";
                                $uText = "";
                                if (stripos($th['method'], 'BKASH') !== false) $mColor = "#E2136E";
                                elseif (stripos($th['method'], 'NAGAD') !== false) $mColor = "#EA580C";
                                elseif (stripos($th['method'], 'QR') !== false) $mColor = "#0284C7";
                                elseif (stripos($th['method'], 'BINANCE') !== false) {
                                    $mColor = "#F59E0B";
                                    $curr = "$";
                                    $uText = "USDT";
                                }
                                elseif (stripos($th['method'], 'ROCKET') !== false) $mColor = "#7C3AED";
                            ?>
                                <div class="history-item bg-[#020817] border border-[#0e275c] hover:border-[#00D9FF]/40 p-4 rounded-2xl flex flex-col sm:flex-row justify-between items-start sm:items-center gap-3 transition" data-status="<?= htmlspecialchars($th['status']) ?>">
                                    <div class="flex items-center gap-3 min-w-0 w-full sm:w-auto">
                                        <span class="text-[10px] font-black px-2.5 py-1 rounded-lg text-white shrink-0" style="background-color: <?= $mColor ?>;">
                                            <?= htmlspecialchars($th['method']) ?>
                                        </span>
                                        <div class="min-w-0 flex-1">
                                            <div class="flex items-start gap-2 min-w-0">
                                                <span class="font-mono text-sm font-bold text-white tracking-wider select-all break-all min-w-0"><?= htmlspecialchars($th['trx_id']) ?></span>
                                                <button onclick="copyText(<?= htmlspecialchars(json_encode($th['trx_id']), ENT_QUOTES) ?>)" class="text-slate-400 hover:text-[#00D9FF] text-xs shrink-0 pt-0.5">
                                                    <i class="fa-regular fa-copy"></i>
                                                </button>
                                            </div>
                                            <p class="text-[11px] text-slate-400 mt-0.5">
                                                From: <b class="text-slate-300"><?= htmlspecialchars($th['sender'] ?: 'N/A') ?></b> • <?= date("d M Y, h:i A", strtotime($th['created_at'])) ?>
                                            </p>
                                        </div>
                                    </div>

                                    <div class="flex items-center justify-between sm:justify-end w-full sm:w-auto gap-4 border-t sm:border-t-0 border-[#0e275c] pt-2.5 sm:pt-0">
                                        <div class="text-base md:text-lg font-black text-white">
                                            <?= $curr ?> <?= number_format(floatval($th['amount']), 2) ?> <?= $uText ?>
                                        </div>

                                        <div>
                                            <?php if ($isUsed): ?>
                                                <span class="inline-flex items-center gap-1.5 text-[11px] font-bold px-3 py-1 rounded-full bg-[#00D89A]/15 text-[#00D89A] border border-[#00D89A]/30">
                                                    <i class="fa-solid fa-circle-check text-[9px]"></i> Approved
                                                </span>
                                            <?php else: ?>
                                                <span class="inline-flex items-center gap-1.5 text-[11px] font-bold px-3 py-1 rounded-full bg-amber-500/15 text-amber-400 border border-amber-500/30">
                                                    <i class="fa-solid fa-clock text-[9px]"></i> Pending
                                                </span>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php else: ?>
                        <div class="bg-[#020817] border border-[#0e275c] rounded-2xl p-8 text-center">
                            <i class="fa-solid fa-clock-rotate-left text-3xl text-slate-500 mb-2"></i>
                            <p class="text-xs text-slate-400">No transaction history found yet.</p>
                        </div>
                    <?php endif; ?>
                </div>
            </section>

            <!-- ========================================================================= -->
            <!-- 3. WALLET SETUP VIEW -->
            <!-- ========================================================================= -->
            <section id="view-wallet-setup" class="content-view hidden space-y-6">
                <div class="bg-[#04122d] border border-[#00D9FF]/40 rounded-3xl p-6 md:p-8 shadow-2xl space-y-6">
                    <div class="pb-3 border-b border-[#0e275c] flex flex-col sm:flex-row justify-between items-start sm:items-center gap-3">
                        <h2 class="text-base md:text-lg font-bold text-white flex items-center gap-2">
                            <i class="fa-solid fa-sliders text-[#00D9FF]"></i> Wallet Setup
                        </h2>

                        <div class="flex items-center gap-2 p-1.5 bg-[#020817] border border-[#0e275c] rounded-2xl">
                            <button type="button" onclick="filterWalletTab('mfs')" id="tab-mfs" class="wallet-cat-btn px-4 py-2 rounded-xl text-xs font-black bg-[#007BFF] text-white transition shadow-md">MFS</button>
                            <button type="button" onclick="filterWalletTab('bank')" id="tab-bank" class="wallet-cat-btn px-4 py-2 rounded-xl text-xs font-bold text-slate-300 hover:text-white transition">Bank</button>
                            <button type="button" onclick="filterWalletTab('qr')" id="tab-qr" class="wallet-cat-btn px-4 py-2 rounded-xl text-xs font-bold text-slate-300 hover:text-white transition">QR</button>
                            <button type="button" onclick="filterWalletTab('international')" id="tab-international" class="wallet-cat-btn px-4 py-2 rounded-xl text-xs font-bold text-slate-300 hover:text-white transition">International</button>
                        </div>
                    </div>

                    <div id="grid-mfs" class="wallet-grid grid grid-cols-2 sm:grid-cols-4 gap-3">
                        <?php 
                        $mfsList = [
                            ['name' => 'Bkash', 'color' => '#E2136E', 'icon' => 'fa-paper-plane'],
                            ['name' => 'Nagad', 'color' => '#F7941D', 'icon' => 'fa-coins'],
                            ['name' => 'Rocket', 'color' => '#8C3494', 'icon' => 'fa-rocket'],
                            ['name' => 'Upay', 'color' => '#FFCC00', 'icon' => 'fa-mobile-screen'],
                            ['name' => 'Cellfin', 'color' => '#00843D', 'icon' => 'fa-building-columns'],
                            ['name' => 'Tap', 'color' => '#E50914', 'icon' => 'fa-hand-pointer'],
                            ['name' => 'Easy Paisa', 'color' => '#00A651', 'icon' => 'fa-wallet'],
                            ['name' => 'Ipay', 'color' => '#00A859', 'icon' => 'fa-credit-card']
                        ];
                        foreach ($mfsList as $b): ?>
                            <button type="button" onclick="openWalletModal('mfs', '<?= $b['name'] ?>')" class="bg-[#020817] border border-[#0e275c] hover:border-[#00D9FF] p-3.5 rounded-2xl flex items-center gap-2.5 text-left transition group">
                                <div class="w-8 h-8 rounded-xl flex items-center justify-center text-white text-xs shrink-0" style="background-color: <?= $b['color'] ?>;">
                                    <i class="fa-solid <?= $b['icon'] ?>"></i>
                                </div>
                                <span class="text-xs font-bold text-white group-hover:text-[#00D9FF] truncate"><?= $b['name'] ?></span><?= snzWalletBadge($b['name']) ?>
                            </button>
                        <?php endforeach; ?>
                    </div>

                    <div id="grid-bank" class="wallet-grid hidden grid grid-cols-2 sm:grid-cols-4 gap-3">
                        <?php 
                        $banks = ['Islamic Bank', 'AB Bank', 'City Bank', 'Sonali Bank', 'DBBL Bank', 'Basic Bank', 'EBL Bank', 'Brac Bank', 'Bank Asia', 'Agrani Bank', 'Jamuna Bank', 'IFIC Bank'];
                        foreach ($banks as $bnk): ?>
                            <button type="button" onclick="openWalletModal('bank', '<?= $bnk ?>')" class="bg-[#020817] border border-[#0e275c] hover:border-[#00D9FF] p-3.5 rounded-2xl flex items-center gap-2.5 text-left transition group">
                                <div class="w-8 h-8 rounded-xl bg-[#007BFF]/20 text-[#00D9FF] flex items-center justify-center text-xs shrink-0">
                                    <i class="fa-solid fa-building-columns"></i>
                                </div>
                                <span class="text-xs font-bold text-white group-hover:text-[#00D9FF] truncate"><?= $bnk ?></span><?= snzWalletBadge($bnk) ?>
                            </button>
                        <?php endforeach; ?>
                    </div>

                    <div id="grid-qr" class="wallet-grid hidden grid grid-cols-2 sm:grid-cols-4 gap-3">
                        <button type="button" onclick="openWalletModal('qr', 'Bangla QR')" class="bg-[#020817] border border-[#0e275c] hover:border-[#00D9FF] p-3.5 rounded-2xl flex items-center gap-2.5 text-left transition group">
                            <div class="w-8 h-8 rounded-xl bg-[#00D89A]/20 text-[#00D89A] flex items-center justify-center text-xs shrink-0">
                                <i class="fa-solid fa-qrcode"></i>
                            </div>
                            <span class="text-xs font-bold text-white group-hover:text-[#00D89A] truncate">Bangla QR</span><?= snzWalletBadge('Bangla QR') ?>
                        </button>
                    </div>

                    <div id="grid-international" class="wallet-grid hidden grid grid-cols-2 sm:grid-cols-4 gap-3">
                        <button type="button" onclick="openWalletModal('international', 'Binance')" class="bg-[#020817] border border-[#0e275c] hover:border-amber-400 p-3.5 rounded-2xl flex items-center gap-2.5 text-left transition group">
                            <div class="w-8 h-8 rounded-xl bg-amber-500/20 text-amber-400 flex items-center justify-center text-xs shrink-0">
                                <i class="fa-brands fa-bitcoin"></i>
                            </div>
                            <span class="text-xs font-bold text-white group-hover:text-amber-400 truncate">Binance</span><?= snzWalletBadge('Binance') ?>
                        </button>
                    </div>

                    <div id="walletSetupForm" class="hidden mt-6 pt-6 border-t border-[#0e275c] bg-[#020817] p-6 rounded-3xl border border-[#00D9FF]/30">
                        <div class="flex justify-between items-center mb-4">
                            <h3 id="formTitle" class="text-sm font-bold text-white">Configure Account</h3>
                            <button type="button" onclick="closeWalletModal()" class="text-slate-400 hover:text-white text-sm">
                                <i class="fa-solid fa-xmark"></i>
                            </button>
                        </div>

                        <form method="POST" enctype="multipart/form-data" class="space-y-4">
                                <input type="hidden" name="csrf" value="<?= $CSRF ?>">
                            <input type="hidden" name="action_save_wallet" value="1">
                            <input type="hidden" id="input_category" name="wallet_category" value="mfs">
                            <input type="hidden" id="input_provider" name="wallet_provider" value="Bkash">
                            <input type="hidden" id="input_wallet_id" name="wallet_id" value="0">

                            <div id="accTypeSection" class="space-y-1.5">
                                <label class="text-xs font-semibold text-slate-200 block">Account Classification:</label>
                                <div id="accTypeOptions" class="grid grid-cols-3 gap-3">
                                    <label class="flex items-center justify-center gap-2 p-3 rounded-xl border border-[#0e275c] bg-[#04122d] cursor-pointer hover:border-[#00D9FF] text-xs font-bold text-white">
                                        <input type="radio" name="account_type" value="Personal" checked class="text-[#00D9FF]">
                                        <span>Personal</span>
                                    </label>
                                    <label class="flex items-center justify-center gap-2 p-3 rounded-xl border border-[#0e275c] bg-[#04122d] cursor-pointer hover:border-[#00D9FF] text-xs font-bold text-white">
                                        <input type="radio" name="account_type" value="Agent" class="text-[#00D9FF]">
                                        <span>Agent</span>
                                    </label>
                                    <label class="flex items-center justify-center gap-2 p-3 rounded-xl border border-[#0e275c] bg-[#04122d] cursor-pointer hover:border-[#00D9FF] text-xs font-bold text-white">
                                        <input type="radio" name="account_type" value="Merchant" class="text-[#00D9FF]">
                                        <span>Merchant</span>
                                    </label>
                                </div>
                            </div>

                            <div id="standardFields" class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                                <div>
                                    <label id="lblAccNumber" class="text-xs font-semibold text-slate-200 block mb-1.5">Account / Phone Number:</label>
                                    <input type="text" id="accNumberInput" name="account_number" placeholder="01XXXXXXXXX" class="w-full bg-[#04122d] border border-[#0e275c] rounded-2xl px-4 py-3 text-white text-sm focus:outline-none focus:border-[#00D9FF]">
                                </div>
                                <div>
                                    <label id="lblAccName" class="text-xs font-semibold text-slate-200 block mb-1.5">Account Details / Name:</label>
                                    <input type="text" id="accNameInput" name="account_name" placeholder="Optional details" class="w-full bg-[#04122d] border border-[#0e275c] rounded-2xl px-4 py-3 text-white text-sm focus:outline-none focus:border-[#00D9FF]">
                                </div>
                            </div>

                            <div id="qrUploadSection" class="hidden space-y-2">
                                <label class="text-xs font-semibold text-slate-200 block">Upload Bangla QR Image (Direct Upload):</label>
                                <input type="file" name="qr_file" accept="image/*" class="w-full bg-[#04122d] border border-[#0e275c] rounded-2xl p-3 text-white text-xs file:mr-4 file:py-2 file:px-4 file:rounded-xl file:border-0 file:text-xs file:font-semibold file:bg-[#007BFF] file:text-white">
                                <p class="text-[11px] text-slate-400">No phone number required. Customers can scan directly.</p>
                            </div>

                            <div id="binanceFields" class="hidden space-y-4">
                                <div>
                                    <label class="text-xs font-semibold text-slate-200 block mb-1.5">Binance API Key:</label>
                                    <input type="text" id="apiKeyInput" name="api_key" placeholder="Enter Binance API key" class="w-full bg-[#04122d] border border-[#0e275c] rounded-2xl px-4 py-3 text-white font-mono text-xs focus:outline-none focus:border-amber-400">
                                </div>
                                <div>
                                    <label class="text-xs font-semibold text-slate-200 block mb-1.5">Binance Secret Key:</label>
                                    <input type="password" id="secretKeyInput" name="secret_key" placeholder="Enter Binance Secret key" class="w-full bg-[#04122d] border border-[#0e275c] rounded-2xl px-4 py-3 text-white font-mono text-xs focus:outline-none focus:border-amber-400">
                                </div>
                                <div>
                                    <label class="text-xs font-semibold text-slate-200 block mb-1.5">Dollar Exchange Rate (1 USDT = ? BDT):</label>
                                    <input type="number" step="any" id="dollarRateInput" name="dollar_rate" value="125.00" placeholder="125.00" class="w-full bg-[#04122d] border border-[#0e275c] rounded-2xl px-4 py-3 text-white font-mono text-xs focus:outline-none focus:border-amber-400">
                                    <span class="text-[10px] text-slate-400 mt-1 block">Customer's USDT deposit will be multiplied by this rate to add BDT balance.</span>
                                </div>
                            </div>

                            <div class="flex items-center justify-between pt-2">
                                <span class="text-xs font-semibold text-white">Enable Gateway (Status):</span>
                                <label class="relative inline-flex items-center cursor-pointer">
                                    <input type="checkbox" id="walletStatusInput" name="wallet_status" checked class="sr-only peer">
                                    <div class="w-11 h-6 bg-[#0a2356] peer-focus:outline-none rounded-full peer peer-checked:after:translate-x-full peer-checked:after:border-white after:content-[''] after:absolute after:top-[2px] after:left-[2px] after:bg-white after:border-gray-300 after:border after:rounded-full after:h-5 after:w-5 after:transition-all peer-checked:bg-[#00D89A]"></div>
                                </label>
                            </div>

                            <button type="submit" id="walletSubmitBtn" class="w-full snz-gradient text-[#061535] font-black py-3 rounded-2xl text-xs transition flex items-center justify-center gap-2">
                                <i class="fa-solid fa-circle-check"></i> <span id="walletSubmitText">Save Gateway</span>
                            </button>
                            <p id="walletEditNote" class="hidden text-[11px] text-slate-400 text-center">Already added — you are editing it. Leave Secret Key / QR empty to keep the current one.</p>
                        </form>
                    </div>
                </div>

                <!-- Configured Wallets List -->
                <div class="space-y-4">
                    <h3 class="text-sm font-bold text-white flex items-center gap-2">
                        <i class="fa-solid fa-list-check text-[#00D89A]"></i> Configured Wallets (<?= count($userWallets) ?>)
                    </h3>

                    <?php if (count($userWallets) > 0): ?>
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                            <?php foreach ($userWallets as $uw): 
                                $isAct = ($uw['status'] === 'active');
                            ?>
                                <div class="bg-[#04122d] border <?= $isAct ? 'border-[#00D89A]/40' : 'border-[#0e275c]' ?> p-5 rounded-3xl relative shadow-xl flex flex-col justify-between">
                                    <div>
                                        <div class="flex justify-between items-start mb-2">
                                            <div class="flex items-center gap-2">
                                                <span class="text-[10px] font-black px-2 py-0.5 rounded-full bg-[#00D9FF]/20 text-[#00D9FF] border border-[#00D9FF]/30">
                                                    <?= htmlspecialchars($uw['provider']) ?>
                                                </span>
                                                <span class="text-[10px] font-bold text-slate-400 uppercase">
                                                    <?= htmlspecialchars($uw['account_type'] ?? '') ?>
                                                </span>
                                            </div>

                                            <form method="POST">
                                <input type="hidden" name="csrf" value="<?= $CSRF ?>">
                                                <input type="hidden" name="action_toggle_wallet" value="1">
                                                <input type="hidden" name="toggle_wallet_id" value="<?= $uw['id'] ?>">
                                                <input type="hidden" name="current_status" value="<?= $uw['status'] ?>">
                                                <button type="submit" class="px-2.5 py-1 rounded-full text-[10px] font-black transition flex items-center gap-1.5 <?= $isAct ? 'bg-[#00D89A]/20 text-[#00D89A] border border-[#00D89A]/40' : 'bg-[#0c2454] text-slate-400 border border-[#143985]' ?>">
                                                    <i class="fa-solid <?= $isAct ? 'fa-toggle-on text-sm' : 'fa-toggle-off text-sm' ?>"></i>
                                                    <?= $isAct ? 'ACTIVE' : 'OFF' ?>
                                                </button>
                                            </form>
                                        </div>

                                        <div class="my-3 bg-[#020817] p-3 rounded-2xl border border-[#0e275c]">
                                            <?php if ($uw['category'] === 'qr' && !empty($uw['qr_image'])): ?>
                                                <div class="flex items-center gap-3">
                                                    <img src="<?= htmlspecialchars($uw['qr_image']) ?>" class="w-14 h-14 object-contain rounded-xl border border-[#10326f] bg-white p-1">
                                                    <div>
                                                        <span class="text-xs font-bold text-white block">Bangla QR Code Ready</span>
                                                        <a href="<?= htmlspecialchars($uw['qr_image']) ?>" target="_blank" class="text-[10px] text-[#00D9FF] hover:underline">View Full Image</a>
                                                    </div>
                                                </div>
                                            <?php elseif ($uw['provider'] === 'Binance'): ?>
                                                <span class="text-xs font-mono font-bold text-amber-400 flex items-center gap-1.5">
                                                    <i class="fa-brands fa-bitcoin"></i> Binance API &amp; Secret Connected
                                                </span>
                                                <span class="text-[10px] text-slate-400 block truncate mt-1">Rate: 1 USDT = ৳ <?= number_format(floatval($uw['dollar_rate'] ?: 125.00), 2) ?> BDT</span>
                                            <?php else: ?>
                                                <span class="text-sm font-mono font-black text-white tracking-wider select-all">
                                                    <?= htmlspecialchars($uw['account_number'] ?? 'N/A') ?>
                                                </span>
                                                <?php if (!empty($uw['account_name'])): ?>
                                                    <span class="text-[11px] text-slate-400 block mt-0.5 truncate"><?= htmlspecialchars($uw['account_name']) ?></span>
                                                <?php endif; ?>
                                            <?php endif; ?>
                                        </div>
                                    </div>

                                    <div class="flex justify-between items-center border-t border-[#0e275c] pt-2 text-[11px]">
                                        <span class="text-slate-400"><?= !empty($uw['created_at']) ? date("d M Y", strtotime($uw['created_at'])) : '' ?></span>
                                        <div class="flex items-center gap-3">
                                        <button type="button" onclick="openWalletModal(<?= htmlspecialchars(json_encode((string) ($uw['category'] ?: 'mfs')), ENT_QUOTES) ?>, <?= htmlspecialchars(json_encode((string) $uw['provider']), ENT_QUOTES) ?>)" class="text-[#00D9FF] hover:text-white font-bold transition flex items-center gap-1">
                                            <i class="fa-solid fa-pen-to-square"></i> Edit
                                        </button>
                                        <form method="POST" onsubmit="return confirm('Delete this wallet?');">
                                <input type="hidden" name="csrf" value="<?= $CSRF ?>">
                                            <input type="hidden" name="action_delete_wallet" value="1">
                                            <input type="hidden" name="delete_wallet_id" value="<?= $uw['id'] ?>">
                                            <button type="submit" class="text-rose-400 hover:text-rose-300 font-bold transition flex items-center gap-1">
                                                <i class="fa-regular fa-trash-can"></i> Delete
                                            </button>
                                        </form>
                                        </div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php else: ?>
                        <div class="bg-[#04122d] border border-[#00D9FF]/40 rounded-3xl p-8 text-center">
                            <i class="fa-solid fa-wallet text-3xl text-slate-500 mb-2"></i>
                            <p class="text-xs text-slate-300">No wallets configured yet.</p>
                        </div>
                    <?php endif; ?>
                </div>
            </section>

            <!-- ========================================================================= -->
            <!-- 4. CUSTOM SMS VIEW -->
            <!-- ========================================================================= -->
            <section id="view-custom-sms" class="content-view hidden space-y-6">
                <div class="bg-[#04122d] border border-[#00D9FF]/40 rounded-3xl p-6 md:p-8 shadow-2xl space-y-4">
                    <h2 class="text-base md:text-lg font-bold text-white flex items-center gap-2">
                        <i class="fa-solid fa-envelope-open-text text-[#38BDF8]"></i> Custom SMS Verification
                    </h2>
                    <?php if (empty($customSmsProviders)): ?>
                        <div class="bg-[#020817] border border-amber-500/30 rounded-2xl p-6 text-center space-y-3">
                            <div class="w-12 h-12 rounded-full bg-amber-500/15 text-amber-400 flex items-center justify-center text-xl mx-auto"><i class="fa-solid fa-wallet"></i></div>
                            <p class="text-sm font-bold text-white">No wallet added yet</p>
                            <p class="text-xs text-slate-400">Add your bKash / Nagad / Rocket (or any wallet) in Wallet Setup first. Then you can add its SMS here.</p>
                            <button type="button" onclick="switchView('view-wallet-setup')" class="snz-gradient text-[#061535] font-black px-5 py-2.5 rounded-xl text-xs">Go to Wallet Setup</button>
                        </div>
                    <?php else: ?>
                    <form method="POST" class="space-y-4">
                                <input type="hidden" name="csrf" value="<?= $CSRF ?>">
                        <input type="hidden" name="action_add_custom_sms" value="1">
                        <div>
                            <label class="text-xs font-semibold text-slate-200 block mb-1.5">Select Provider:</label>
                            <select name="custom_provider" class="w-full bg-[#020817] border border-[#0e275c] rounded-2xl px-4 py-3 text-white text-sm focus:outline-none focus:border-[#00D9FF]">
                                <?php foreach ($customSmsProviders as $code => $label): ?>
                                    <option value="<?= htmlspecialchars($code) ?>"><?= htmlspecialchars($label) ?></option>
                                <?php endforeach; ?>
                            </select>
                            <span class="text-[10px] text-slate-400 mt-1 block">Only wallets you added in Wallet Setup are shown here.</span>
                        </div>
                        <div>
                            <label class="text-xs font-semibold text-slate-200 block mb-1.5">Paste Raw Received SMS:</label>
                            <textarea name="custom_sms_text" rows="4" required placeholder="Paste original SMS here..." class="w-full bg-[#020817] border border-[#0e275c] rounded-2xl p-4 text-white font-mono text-xs focus:outline-none focus:border-[#00D9FF]"></textarea>
                        </div>
                        <button type="submit" class="w-full snz-gradient text-[#061535] font-black py-3.5 rounded-2xl text-xs transition">Add to Verification Queue</button>
                    </form>
                    <?php endif; ?>
                </div>
            </section>

            <!-- ========================================================================= -->
            <!-- 5. BRANDS (BOT KEY) VIEW -->
            <!-- ========================================================================= -->
            <section id="view-brands" class="content-view hidden space-y-6">
                <!-- Header + quota -->
                <div class="bg-gradient-to-br from-[#0b2559] to-[#04122d] border border-[#00D9FF]/40 rounded-3xl p-6 md:p-7 shadow-2xl">
                    <div class="flex items-center justify-between gap-3">
                        <div class="flex items-center gap-3">
                            <div class="w-11 h-11 rounded-2xl bg-amber-500/15 text-[#FBBF24] flex items-center justify-center text-lg border border-amber-500/30"><i class="fa-solid fa-robot"></i></div>
                            <div>
                                <h2 class="text-base md:text-lg font-bold text-white">Brands &amp; API Keys</h2>
                                <p class="text-[11px] text-slate-400">One key works in one place — website, app or bot.</p>
                            </div>
                        </div>
                        <div class="text-right shrink-0">
                            <div class="text-xl font-black text-white"><?= count($userBrandKeys) ?><span class="text-slate-500 text-sm">/<?= (int) $brandQuota['limit'] ?></span></div>
                            <div class="text-[10px] text-slate-400 font-bold uppercase">Keys used</div>
                        </div>
                    </div>
                    <?php $qPct = $brandQuota['limit'] > 0 ? min(100, round(count($userBrandKeys) / $brandQuota['limit'] * 100)) : 0; ?>
                    <div class="mt-4 h-2 rounded-full bg-[#020817] overflow-hidden"><div class="h-2 snz-gradient rounded-full" style="width: <?= $qPct ?>%"></div></div>
                </div>

                <!-- Keys -->
                <?php if ($userBrandKeys): ?>
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                        <?php foreach ($userBrandKeys as $bk):
                            $bConnected = strncmp((string) ($bk['bound_fingerprint'] ?? ''), 'name:', 5) === 0;
                            $suggest = strtolower(trim((string) ($bk['brand_link'] ?? '')));
                            $suggest = preg_replace('#^[a-z][a-z0-9+.-]*://#', '', $suggest);
                            $suggest = preg_replace('#^(www\.|t\.me/|telegram\.me/)#', '', $suggest);
                            $suggest = ltrim(preg_replace('#[/?\#].*$#', '', $suggest), '@');
                            if ($suggest === '') $suggest = strtolower(preg_replace('/[^a-z0-9]+/i', '-', (string) $bk['brand_name']));
                            $bType = $bk['brand_type'] ?? '';
                            $typeIcon = $bType === 'website' ? 'fa-globe' : ($bType === 'app' ? 'fa-mobile-screen' : ($bType === 'bot' ? 'fa-robot' : 'fa-key'));
                            $typeLabel = $bType ? ucfirst($bType) : 'Brand'; ?>
                            <div class="relative rounded-3xl p-5 border <?= $bConnected ? 'border-[#00D89A]/50 shadow-[0_0_18px_rgba(0,216,154,0.15)]' : 'border-amber-500/40' ?> bg-gradient-to-br from-[#071a44] to-[#020817] space-y-3">
                                <div class="flex items-start justify-between gap-3">
                                    <div class="flex items-center gap-3 min-w-0">
                                        <div class="w-10 h-10 rounded-2xl bg-[#00D9FF]/10 text-[#00D9FF] border border-[#00D9FF]/30 flex items-center justify-center shrink-0"><i class="fa-solid <?= $typeIcon ?>"></i></div>
                                        <div class="min-w-0">
                                            <div class="text-sm font-black text-white truncate"><?= htmlspecialchars($bk['brand_name'] ?: 'My Brand') ?></div>
                                            <div class="text-[10px] font-bold text-slate-400 uppercase"><?= $typeLabel ?></div>
                                        </div>
                                    </div>
                                    <span class="shrink-0 text-[9px] font-black px-2.5 py-1 rounded-full <?= $bConnected ? 'bg-[#00D89A]/15 text-[#00D89A] border border-[#00D89A]/40' : 'bg-amber-500/15 text-amber-400 border border-amber-500/40' ?>">
                                        <?= $bConnected ? '● CONNECTED' : '○ NOT CONNECTED' ?>
                                    </span>
                                </div>

                                <div class="bg-[#020817] border border-[#0e275c] p-3 rounded-2xl flex items-center justify-between gap-2">
                                    <span class="text-xs font-mono font-black text-[#00D89A] tracking-wider select-all break-all min-w-0"><?= htmlspecialchars($bk['api_key']) ?></span>
                                    <button onclick="copyText(<?= htmlspecialchars(json_encode($bk['api_key']), ENT_QUOTES) ?>)" class="text-xs bg-[#092257] hover:bg-[#007BFF] text-white px-3 py-1.5 rounded-xl border border-[#174396] transition shrink-0"><i class="fa-regular fa-copy"></i></button>
                                </div>

                                <div class="text-[11px] space-y-1.5">
                                    <?php if ($bConnected): ?>
                                        <div class="flex items-center gap-2 text-[#00D89A]"><i class="fa-solid fa-plug-circle-check w-4"></i> <span class="text-slate-400">Connected to:</span> <b class="text-white break-all"><?= htmlspecialchars($bk['bound_label']) ?></b></div>
                                        <?php if (!empty($bk['last_used_at'])): ?><div class="flex items-center gap-2 text-slate-400"><i class="fa-regular fa-clock w-4"></i> Last used <?= date("d M, h:i A", strtotime($bk['last_used_at'])) ?></div><?php endif; ?>
                                    <?php else: ?>
                                        <div class="flex items-center gap-2 text-amber-400"><i class="fa-solid fa-plug w-4"></i> Waiting for first use</div>
                                        <div class="text-slate-400">Send <code class="text-[#00D9FF]">"client_name": "<?= htmlspecialchars($suggest) ?>"</code> with your API request — the key connects to that name.</div>
                                    <?php endif; ?>
                                    <?php if (!empty($bk['brand_link'])): ?><div class="flex items-center gap-2 text-slate-400"><i class="fa-solid fa-link w-4"></i> <span class="break-all"><?= htmlspecialchars($bk['brand_link']) ?></span></div><?php endif; ?>
                                    <?php if (!empty($bk['whatsapp'])): ?><div class="flex items-center gap-2 text-slate-400"><i class="fa-brands fa-whatsapp w-4 text-[#25D366]"></i> <?= htmlspecialchars($bk['whatsapp']) ?></div><?php endif; ?>
                                </div>

                                <div class="flex gap-2 pt-1">
                                    <?php if ($bConnected): ?>
                                        <form method="POST" class="flex-1" onsubmit="return confirm('Disconnect this key so you can use it on a new website/app/bot?');">
                                            <input type="hidden" name="csrf" value="<?= $CSRF ?>">
                                            <input type="hidden" name="action_disconnect_brand_key" value="1">
                                            <input type="hidden" name="brand_key_id" value="<?= (int) $bk['id'] ?>">
                                            <button type="submit" class="w-full text-[11px] font-bold text-[#00D9FF] bg-[#00D9FF]/10 border border-[#00D9FF]/30 py-2.5 rounded-xl"><i class="fa-solid fa-right-left"></i> Move to new place</button>
                                        </form>
                                    <?php endif; ?>
                                    <form method="POST" class="<?= $bConnected ? '' : 'flex-1' ?>" onsubmit="return confirm('Revoke (delete) this key? Anything using it will stop working.');">
                                        <input type="hidden" name="csrf" value="<?= $CSRF ?>">
                                        <input type="hidden" name="action_revoke_brand_key" value="1">
                                        <input type="hidden" name="revoke_key_id" value="<?= (int) $bk['id'] ?>">
                                        <button type="submit" class="w-full text-[11px] font-bold text-rose-300 bg-rose-500/10 border border-rose-500/30 px-4 py-2.5 rounded-xl"><i class="fa-solid fa-ban"></i> Revoke</button>
                                    </form>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>

                <!-- Create -->
                <div class="bg-[#04122d] border border-[#00D9FF]/40 rounded-3xl p-6 md:p-7 shadow-2xl">
                    <?php if ($brandQuota['limit'] <= 0): ?>
                        <div class="text-center space-y-3 py-2">
                            <div class="w-12 h-12 rounded-full bg-amber-500/15 text-amber-400 flex items-center justify-center text-xl mx-auto"><i class="fa-solid fa-lock"></i></div>
                            <p class="text-sm font-bold text-white">Buy a plan to create brand keys</p>
                            <button type="button" onclick="switchView('view-plans')" class="snz-gradient text-[#061535] font-black px-5 py-2.5 rounded-xl text-xs">View Plans</button>
                        </div>
                    <?php elseif ($brandQuota['used'] >= $brandQuota['limit']): ?>
                        <div class="text-center space-y-2 py-2">
                            <p class="text-sm font-bold text-white">All <?= (int) $brandQuota['limit'] ?> brand keys used</p>
                            <p class="text-xs text-slate-400">Revoke an old key or upgrade your plan to create more.</p>
                        </div>
                    <?php else: ?>
                        <h3 class="text-sm font-bold text-white mb-4 flex items-center gap-2"><i class="fa-solid fa-plus text-[#00D9FF]"></i> Create Brand Key <span class="text-[10px] text-slate-400 font-semibold">(<?= (int) ($brandQuota['limit'] - $brandQuota['used']) ?> left)</span></h3>
                        <form method="POST" class="space-y-4">
                            <input type="hidden" name="csrf" value="<?= $CSRF ?>">
                            <input type="hidden" name="action_generate_brand_key" value="1">
                            <div>
                                <label class="text-xs font-semibold text-slate-200 block mb-1.5">Brand Name</label>
                                <input type="text" name="brand_name" required maxlength="100" placeholder="e.g., My Store" class="w-full bg-[#020817] border border-[#0e275c] rounded-2xl px-4 py-3 text-white text-sm focus:outline-none focus:border-[#00D9FF]">
                            </div>
                            <div>
                                <label class="text-xs font-semibold text-slate-200 block mb-1.5">Where will you use it?</label>
                                <div class="grid grid-cols-3 gap-2">
                                    <?php foreach (['website' => ['fa-globe', 'Website'], 'app' => ['fa-mobile-screen', 'App'], 'bot' => ['fa-robot', 'Bot']] as $tv => $tl): ?>
                                        <label class="cursor-pointer">
                                            <input type="radio" name="brand_type" value="<?= $tv ?>" class="peer sr-only" required <?= $tv === 'website' ? 'checked' : '' ?> onchange="brandTypeHint()">
                                            <div class="text-center py-3 rounded-2xl border border-[#0e275c] bg-[#020817] text-slate-300 text-xs font-bold peer-checked:border-[#00D9FF] peer-checked:text-[#00D9FF] peer-checked:bg-[#00D9FF]/10 transition">
                                                <i class="fa-solid <?= $tl[0] ?> block text-base mb-1"></i><?= $tl[1] ?>
                                            </div>
                                        </label>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                            <div>
                                <label id="brandLinkLabel" class="text-xs font-semibold text-slate-200 block mb-1.5">Website Link</label>
                                <input type="text" id="brandLinkInput" name="brand_link" required maxlength="255" placeholder="https://mystore.com" class="w-full bg-[#020817] border border-[#0e275c] rounded-2xl px-4 py-3 text-white text-sm focus:outline-none focus:border-[#00D9FF]">
                            </div>
                            <div>
                                <label class="text-xs font-semibold text-slate-200 block mb-1.5"><i class="fa-brands fa-whatsapp text-[#25D366]"></i> WhatsApp Number</label>
                                <input type="tel" name="brand_whatsapp" required maxlength="20" placeholder="01XXXXXXXXX" class="w-full bg-[#020817] border border-[#0e275c] rounded-2xl px-4 py-3 text-white text-sm focus:outline-none focus:border-[#00D9FF]">
                            </div>
                            <button type="submit" class="w-full snz-gradient text-[#061535] font-black py-3.5 rounded-2xl text-xs transition flex items-center justify-center gap-2">
                                <i class="fa-solid fa-key"></i> Generate Brand Key
                            </button>
                        </form>
                    <?php endif; ?>
                </div>
            </section>

            <!-- ========================================================================= -->
            <!-- 6. ADD FUND VIEW (OVERHAULED WITH 5 PAYMENT METHODS & BANGLA QR) -->
            <!-- ========================================================================= -->
            <section id="view-add-fund" class="content-view hidden space-y-6">
                <div class="bg-[#04122d] border border-[#00D9FF]/40 rounded-3xl p-6 md:p-8 shadow-2xl space-y-6">
                    <div class="flex items-center justify-between pb-3 border-b border-[#0e275c]">
                        <h2 class="text-base md:text-lg font-bold text-white flex items-center gap-2">
                            <i class="fa-solid fa-wallet text-[#00D89A]"></i> Add Wallet Balance
                        </h2>
                        <span class="bg-[#00D9FF]/10 text-[#00D9FF] text-xs font-bold px-3 py-1 rounded-full border border-[#00D9FF]/30">Instant Credit</span>
                    </div>

                    <!-- ⚡ প্ল্যান কেনার জন্য পেমেন্ট হলে -->
                    <div id="planPayBanner" class="hidden rounded-2xl p-4 border border-[#00D89A]/40 bg-gradient-to-r from-[#00D89A]/15 to-[#00D9FF]/10">
                        <div class="flex items-start gap-3">
                            <span class="w-10 h-10 rounded-xl snz-gradient text-[#061535] flex items-center justify-center text-lg shrink-0">⚡</span>
                            <div class="min-w-0 flex-1">
                                <div class="text-sm font-black text-white">Buying plan: <span id="planPayName"></span></div>
                                <div class="text-[11px] text-slate-300 mt-0.5">Plan price ৳<span id="planPayPrice"></span> • your balance ৳<?= number_format((float) $user['balance'], 2) ?> • pay at least <b class="text-[#00D89A]">৳<span id="planPayNeed"></span></b>. After verify the plan activates automatically.</div>
                            </div>
                            <button type="button" onclick="cancelPlanPay()" class="text-slate-400 hover:text-white text-xs shrink-0"><i class="fa-solid fa-xmark"></i></button>
                        </div>
                    </div>

                    <?php if (!$fundMethods): ?>
                        <div class="text-center py-12 text-sm text-slate-400">No payment method is available right now. Please contact support.</div>
                    <?php else: ?>
                    <!-- ১) মেথড বাছাই -->
                    <div id="pmPick" class="space-y-3">
                        <div class="text-xs font-semibold text-slate-200">Choose a payment method:</div>
                        <div class="grid grid-cols-2 sm:grid-cols-3 gap-3">
                            <?php foreach ($fundMethods as $mk => $fm): ?>
                                <button type="button" onclick="openPayMethod('<?= htmlspecialchars($mk, ENT_QUOTES) ?>')" class="pm-tile group relative overflow-hidden rounded-2xl p-3.5 pr-6 text-left border border-[#0e275c] bg-[#020817] transition" style="--c:<?= $fm['color'] ?>">
                                    <span class="pm-tile-glow"></span>
                                    <div class="relative flex items-center gap-3">
                                        <?= $fm['logo_html'] ?>
                                        <div class="min-w-0">
                                            <div class="text-sm font-black text-white leading-tight break-words"><?= htmlspecialchars($fm['name']) ?></div>
                                            <div class="text-[10px] font-bold leading-tight mt-0.5" style="color:<?= $fm['color'] ?>"><?= htmlspecialchars($fm['type_label']) ?><br><?= htmlspecialchars($fm['action']) ?></div>
                                        </div>
                                    </div>
                                    <i class="fa-solid fa-chevron-right absolute right-3 top-1/2 -translate-y-1/2 text-[10px] text-slate-500 group-hover:text-white"></i>
                                </button>
                            <?php endforeach; ?>
                        </div>
                    </div>

                    <!-- ২) নির্বাচিত মেথডের পেমেন্ট পেজ -->
                    <div id="pmPay" class="hidden space-y-4">
                        <button type="button" onclick="backToMethods()" class="text-xs font-bold text-slate-300 hover:text-white flex items-center gap-1.5"><i class="fa-solid fa-arrow-left"></i> All methods</button>

                        <!-- 🎬 কিভাবে টাকা পাঠাবেন (অ্যানিমেশন ব্যানার) -->
                        <div id="pmAnim" class="pm-anim"></div>

                        <!-- 🖼️ মেথডের রঙের ফ্রেম কার্ড -->
                        <div id="pmFrame" class="pm-frame">
                            <div class="pm-frame-in space-y-4">
                                <div class="flex items-center gap-3">
                                    <span id="pmFLogo"></span>
                                    <div class="min-w-0 flex-1">
                                        <div id="pmFName" class="text-base font-black text-white"></div>
                                        <div id="pmFType" class="text-[11px] font-bold"></div>
                                    </div>
                                    <span id="pmFBadge" class="pm-badge"></span>
                                </div>
                                <div id="pmFNumBox" class="rounded-2xl p-3.5 bg-black/30 border border-white/10 flex items-center justify-between gap-3">
                                    <div class="min-w-0">
                                        <div id="pmFNumLbl" class="text-[10px] text-slate-300 uppercase tracking-wider font-bold"></div>
                                        <div id="pmFNum" class="text-lg md:text-xl font-mono font-black text-white break-all"></div>
                                    </div>
                                    <button type="button" onclick="copyText(document.getElementById('pmFNum').innerText)" class="pm-copy shrink-0"><i class="fa-regular fa-copy"></i> Copy</button>
                                </div>
                                <div id="pmFQrBox" class="hidden text-center">
                                    <div class="pm-qr"><img id="pmFQr" src="" alt="QR"></div>
                                    <div class="text-[11px] text-slate-300 mt-2"><i class="fa-solid fa-qrcode"></i> Scan to pay</div>
                                </div>
                                <div id="pmFNoteBox" class="hidden rounded-2xl p-3.5 bg-amber-400/10 border border-amber-400/30">
                                    <div class="text-[11px] font-black text-amber-300 mb-1"><i class="fa-solid fa-circle-info"></i> Instructions</div>
                                    <p id="pmFNote" class="text-xs text-slate-100 leading-relaxed whitespace-pre-line break-words"></p>
                                </div>

                                <form method="POST" class="space-y-3 pt-1" onsubmit="this.querySelector('button[type=submit]').disabled=true">
                                    <input type="hidden" name="csrf" value="<?= $CSRF ?>">
                                    <input type="hidden" name="action_add_fund" value="1">
                                    <input type="hidden" id="fund_hidden_provider" name="provider" value="">
                                    <input type="hidden" id="pending_plan_id" name="pending_plan_id" value="">
                                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                                        <div>
                                            <label id="fundAmountLabel" class="text-[11px] font-bold text-slate-200 block mb-1.5">Amount (BDT)</label>
                                            <input type="number" step="any" name="deposit_amount" id="fundAmount" required placeholder="Min 10" class="pm-field">
                                        </div>
                                        <div>
                                            <label id="fundTrxLabel" class="text-[11px] font-bold text-slate-200 block mb-1.5">Transaction ID (TrxID)</label>
                                            <input type="text" name="trx_id" required placeholder="e.g. 8N7A6D5EKL" class="pm-field font-mono uppercase">
                                        </div>
                                    </div>
                                    <button type="submit" id="pmVerifyBtn" class="pm-verify"><i class="fa-solid fa-shield-halved"></i> <span>Verify &amp; Add Balance</span></button>
                                </form>
                            </div>
                        </div>
                    </div>
                    <?php endif; ?>

                    <?php if ($myDepositRequests): ?>
                        <div class="pt-2 space-y-2">
                            <h3 class="text-xs font-bold text-slate-300 uppercase tracking-wider"><i class="fa-solid fa-clock-rotate-left text-[#00D9FF]"></i> My deposit requests</h3>
                            <?php foreach ($myDepositRequests as $dr):
                                $st = $dr['status'];
                                $stCls = $st === 'approved' ? 'bg-[#00D89A]/15 text-[#00D89A] border-[#00D89A]/30' : ($st === 'rejected' ? 'bg-rose-500/15 text-rose-300 border-rose-500/30' : 'bg-amber-500/15 text-amber-400 border-amber-500/30');
                                $stTxt = $st === 'approved' ? '✓ Approved' : ($st === 'rejected' ? '✕ Rejected' : '⏳ Admin reviewing'); ?>
                                <div class="bg-[#020817] border border-[#0e275c] rounded-2xl p-3 flex items-center justify-between gap-3">
                                    <div class="min-w-0">
                                        <div class="font-mono text-xs font-bold text-white break-all"><?= htmlspecialchars($dr['trx_id']) ?></div>
                                        <div class="text-[10px] text-slate-400"><?= htmlspecialchars(strtoupper((string) $dr['provider'])) ?> • ৳<?= number_format((float) ($dr['credited_amount'] ?? $dr['amount']), 2) ?><?= !empty($dr['admin_note']) && $dr['admin_note'] !== 'Auto Pay' ? ' • ' . htmlspecialchars($dr['admin_note']) : '' ?></div>
                                    </div>
                                    <span class="shrink-0 text-[10px] font-black px-2.5 py-1 rounded-full border <?= $stCls ?>"><?= $stTxt ?></span>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>
            </section>

            <!-- ========================================================================= -->
            <!-- 7. MY PLANS VIEW -->
            <!-- ========================================================================= -->
            <section id="view-my-plan" class="content-view hidden space-y-6">
                <div class="bg-[#04122d] border border-[#00D9FF]/40 rounded-3xl p-6 md:p-8">
                    <div class="flex justify-between items-center gap-3 mb-6 pb-4 border-b border-[#0e275c]">
                        <div class="min-w-0">
                            <h2 class="text-base md:text-lg font-bold text-white flex items-center gap-2">
                                <i class="fa-solid fa-key text-[#00D9FF]"></i> My Active &amp; Past Licenses
                            </h2>
                            <p class="text-xs text-slate-300 mt-0.5">Manage your active plans, license keys, and renewal dates.</p>
                        </div>
                        <span class="text-xs bg-[#03132e] text-[#00D9FF] px-3 py-1 rounded-full whitespace-nowrap shrink-0 border border-[#174396]">Total: <?= count($allUserKeys) ?></span>
                    </div>

                    <?php if (count($allUserKeys) > 0): ?>
                        <div class="space-y-4">
                            <?php foreach ($allUserKeys as $k): 
                                $isExp = strtotime($k['expires_at']) < time();
                                $pDetails = getPlanDetails($k['plan_name']);
                            ?>
                                <div class="bg-[#020817] border <?= $isExp ? 'border-[#00D9FF]/30' : 'border-[#00D89A]/30' ?> p-4 rounded-2xl flex flex-col md:flex-row justify-between items-start md:items-center gap-3">
                                    <div class="space-y-1 min-w-0 w-full md:w-auto">
                                        <div class="flex flex-wrap items-center gap-2">
                                            <span class="text-xs font-bold text-white"><?= htmlspecialchars($k['plan_name']) ?></span>
                                            <?php if (($k['status'] ?? 'active') !== 'active'): ?>
                                                <span class="text-[10px] font-bold px-2 py-0.5 rounded-full bg-rose-500/15 text-rose-300 border border-rose-500/30">BANNED</span>
                                            <?php elseif ($isExp): ?>
                                                <span class="text-[10px] font-bold px-2 py-0.5 rounded-full bg-[#0c2454] text-[#00D9FF] border border-[#16418f]">EXPIRED</span>
                                            <?php else: ?>
                                                <span class="text-[10px] font-bold px-2 py-0.5 rounded-full bg-[#00D89A]/15 text-[#00D89A] border border-[#00D89A]/30">ACTIVE</span>
                                            <?php endif; ?>
                                        </div>
                                        <div class="font-mono text-sm md:text-base font-black <?= $isExp ? 'text-slate-300' : 'text-[#00D89A]' ?> select-all tracking-wider break-all">
                                            <?= htmlspecialchars($k['license_key']) ?>
                                        </div>
                                        <div class="text-[11px] text-slate-400">
                                            Expires: <b class="text-slate-200"><?= date("d M Y, h:i A", strtotime($k['expires_at'])) ?></b>
                                        </div>
                                        <?php $kDevs = $keyDevicesMap[(int) $k['id']] ?? []; $kMax = max(1, (int) ($k['max_devices'] ?? 1)); ?>
                                        <div class="mt-3 rounded-2xl border border-[#0e275c] bg-[#04122d] p-3 space-y-2">
                                            <div class="flex items-center justify-between text-[10px] font-black uppercase tracking-wider">
                                                <span class="text-slate-400"><i class="fa-solid fa-mobile-screen text-[#00D9FF]"></i> Phones</span>
                                                <span class="<?= count($kDevs) >= $kMax ? 'text-amber-400' : 'text-[#00D89A]' ?>"><?= count($kDevs) ?> / <?= $kMax ?> used</span>
                                            </div>
                                            <?php foreach ($kDevs as $dvc): ?>
                                                <div class="flex items-center gap-2.5">
                                                    <span class="w-7 h-7 rounded-lg bg-[#00D89A]/15 text-[#00D89A] flex items-center justify-center text-xs shrink-0"><i class="fa-solid fa-mobile-screen-button"></i></span>
                                                    <div class="min-w-0">
                                                        <div class="text-xs font-bold text-white truncate"><?= htmlspecialchars($dvc['device_name'] ?: 'Android Phone') ?></div>
                                                        <div class="text-[10px] text-slate-400"><?= !empty($dvc['last_seen_at']) ? 'Active ' . date("d M, h:i A", strtotime($dvc['last_seen_at'])) : 'Connected' ?></div>
                                                    </div>
                                                    <span class="ml-auto w-2 h-2 rounded-full bg-[#00D89A] shadow-[0_0_8px_rgba(0,216,154,0.8)] shrink-0"></span>
                                                </div>
                                            <?php endforeach; ?>
                                            <?php if (!$kDevs): ?>
                                                <div class="text-[11px] text-slate-400">Not connected yet — login with this key in the app</div>
                                            <?php endif; ?>
                                            <div class="text-[10px] text-slate-500 pt-1 border-t border-[#0e275c]"><i class="fa-solid fa-link text-amber-400"></i> <?= (int) ($k['max_brand_keys'] ?? 1) ?> brand key<?= (int) ($k['max_brand_keys'] ?? 1) != 1 ? 's' : '' ?> included</div>
                                        </div>
                                    </div>

                                    <div class="flex items-stretch gap-2 w-full md:w-auto">
                                        <?php if ($isExp && $pDetails['price'] > 0): ?>
                                            <form method="POST" class="flex-1 md:flex-none">
                                <input type="hidden" name="csrf" value="<?= $CSRF ?>">
                                                <input type="hidden" name="action_manual_renew" value="1">
                                                <input type="hidden" name="renew_key_id" value="<?= $k['id'] ?>">
                                                <button type="submit" class="w-full h-full whitespace-nowrap snz-gradient text-[#061535] text-xs font-black px-4 py-2.5 rounded-xl transition flex items-center justify-center gap-1.5 shadow-lg shadow-cyan-500/20">
                                                    <i class="fa-solid fa-arrows-rotate"></i> Renew (৳<?= $pDetails['price'] ?>)
                                                </button>
                                            </form>
                                        <?php endif; ?>
                                        <button onclick="copyText(<?= htmlspecialchars(json_encode($k['license_key']), ENT_QUOTES) ?>)" class="flex-1 md:flex-none whitespace-nowrap bg-[#092257] hover:bg-[#007BFF] text-white text-xs font-bold px-4 py-2.5 rounded-xl border border-[#174396] transition flex items-center justify-center gap-1.5">
                                            <i class="fa-regular fa-copy"></i> Copy
                                        </button>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php else: ?>
                        <div class="text-center py-8">
                            <i class="fa-solid fa-key text-3xl text-slate-500 mb-2"></i>
                            <p class="text-xs text-slate-400">You currently do not have any license keys. Select a plan below to activate.</p>
                        </div>
                    <?php endif; ?>
                </div>
            </section>

            <!-- ========================================================================= -->
            <!-- 8. PRICING PLANS VIEW -->
            <!-- ========================================================================= -->
            <section id="view-plans" class="content-view hidden space-y-6">
                <div>
                    <h2 class="text-base md:text-lg font-bold text-white flex items-center gap-2">
                        <i class="fa-solid fa-layer-group text-[#00D9FF]"></i> Subscription Plans
                    </h2>
                    <p class="text-xs text-slate-300 mt-0.5">Pay from your wallet — or pay live with bKash / Nagad / any method and the plan activates instantly.</p>
                </div>

<?php $planCats = snzPlanCategories($allPlans); if ($planCats): ?>
                    <div class="flex flex-wrap items-center justify-center gap-2 mb-5" id="planFilters">
                        <button type="button" onclick="filterPlans('all', this)" class="plan-filter-btn active-filter px-4 py-2 rounded-full text-xs font-bold border border-[#00D9FF]/40 transition">All</button>
                        <?php foreach ($planCats as $pc): ?>
                            <button type="button" onclick="filterPlans(<?= htmlspecialchars(json_encode($pc), ENT_QUOTES) ?>, this)" class="plan-filter-btn px-4 py-2 rounded-full text-xs font-bold border border-[#0e275c] transition"><?= htmlspecialchars($pc) ?></button>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
                                <div class="grid grid-cols-1 md:grid-cols-3 gap-5">
                    <?php foreach ($allPlans as $i => $pl):
                        $final = snzPlanFinalPrice($pl);
                        $disc = (float) $pl['discount_percent'];
                        $isTrial = !empty($pl['is_trial']) || $final <= 0;
                        $trialUsed = $isTrial && !empty($user['has_used_trial']);
                        $featured = !empty($pl['badge']) && stripos($pl['badge'], 'popular') !== false; ?>
                        <div data-cat="<?= htmlspecialchars('|' . implode('|', array_map('trim', explode(',', (string) ($pl['category'] ?? '')))) . '|') ?>" data-price="<?= $isTrial ? 0 : $final ?>" class="plan-card relative rounded-3xl p-6 flex flex-col justify-between shadow-xl border <?= $featured ? 'border-[#00D89A]/60 bg-gradient-to-br from-[#06303a] to-[#04122d]' : 'border-[#00D9FF]/30 bg-gradient-to-br from-[#0b2559] to-[#04122d]' ?>">
<?php if (!empty($pl['is_pinned'])): ?><span class="absolute -top-3 left-5 text-[10px] font-black px-3 py-1 rounded-full bg-amber-400 text-[#061535] shadow-lg">📌 PINNED</span><?php endif; ?>
                            <?php if ($disc > 0): ?><span class="absolute -top-3 right-5 text-[10px] font-black px-3 py-1 rounded-full bg-rose-500 text-white shadow-lg"><?= rtrim(rtrim(number_format($disc, 2), '0'), '.') ?>% OFF</span><?php endif; ?>
                            <div>
                                <?php if (!empty($pl['badge'])): ?><span class="<?= $featured ? 'snz-gradient text-[#061535]' : 'bg-[#00D9FF]/10 text-[#00D9FF] border border-[#00D9FF]/30' ?> text-[11px] font-black px-3 py-1 rounded-full"><?= htmlspecialchars($pl['badge']) ?></span><?php endif; ?>
                                <h3 class="text-base font-bold text-white mt-3"><?= htmlspecialchars($pl['name']) ?></h3>
                                <?php if (!empty($pl['description'])): ?><p class="text-slate-300 text-xs mt-1"><?= htmlspecialchars($pl['description']) ?></p><?php endif; ?>
                                <div class="my-4 flex items-end gap-2">
                                    <span class="text-3xl font-black text-white">৳ <?= rtrim(rtrim(number_format($final, 2), '0'), '.') ?></span>
                                    <?php if ($disc > 0): ?><span class="text-sm text-slate-500 line-through mb-1">৳ <?= rtrim(rtrim(number_format((float) $pl['price'], 2), '0'), '.') ?></span><?php endif; ?>
                                </div>
                                <ul class="space-y-2 text-xs text-slate-200 mb-5">
                                    <li class="flex items-center gap-2"><i class="fa-regular fa-clock text-[#00D9FF] w-4"></i> <?= snzDurationLabel($pl['duration_hours']) ?> access</li>
                                    <li class="flex items-center gap-2"><i class="fa-solid fa-mobile-screen text-[#00D89A] w-4"></i> <?= (int) $pl['max_devices'] ?> phone<?= (int) $pl['max_devices'] > 1 ? 's' : '' ?> per key</li>
                                    <li class="flex items-center gap-2"><i class="fa-solid fa-link text-amber-400 w-4"></i> <?= (int) $pl['max_brand_keys'] ?> brand key<?= (int) $pl['max_brand_keys'] != 1 ? 's' : '' ?></li>
                                    <li class="flex items-center gap-2"><i class="fa-solid fa-check text-[#00D89A] w-4"></i> bKash, Nagad, Rocket, Upay, Binance &amp; QR SMS</li>
                                </ul>
                            </div>
                            <?php $needPay = !$isTrial && (float) $user['balance'] + 0.0001 < $final; ?>
                            <form method="POST" <?= $needPay ? 'onsubmit="startPlanPay(' . (int) $pl['id'] . ', ' . htmlspecialchars(json_encode((string) $pl['name'], JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT), ENT_QUOTES) . ', ' . round($final, 2) . '); return false;"' : '' ?>>
                                <input type="hidden" name="csrf" value="<?= $CSRF ?>">
                                <input type="hidden" name="action_buy_plan" value="1">
                                <input type="hidden" name="plan_id" value="<?= (int) $pl['id'] ?>">
                                <button type="submit" <?= $trialUsed ? 'disabled' : '' ?> class="w-full <?= $trialUsed ? 'bg-slate-700 text-slate-400 cursor-not-allowed' : 'snz-gradient text-[#061535]' ?> font-black py-3 rounded-2xl text-xs transition">
                                    <?= $trialUsed ? 'Claimed (1-Time Only)' : ($isTrial ? 'Claim Free Trial' : ($needPay ? '⚡ Pay Now & Activate' : 'Purchase with Wallet')) ?>
                                </button>
                                <?php if ($needPay && !$trialUsed): ?><div class="text-[10px] text-center text-slate-400 mt-1.5">Pay live with <?= htmlspecialchars(implode(', ', array_slice(array_column($fundMethods, 'name'), 0, 3))) ?><?= count($fundMethods) > 3 ? ' & more' : '' ?></div><?php endif; ?>
                            </form>
                        </div>
                    <?php endforeach; ?>
                    <?php if (!$allPlans): ?>
                        <div class="md:col-span-3 text-center text-xs text-slate-400 py-10">No plans available right now.</div>
                    <?php endif; ?>
                </div>
            </section>

            <!-- ========================================================================= -->
            <!-- 9. DEVELOPER API VIEW (100% PURE ENGLISH INTEGRATION GUIDE) -->
            <!-- ========================================================================= -->
            <section id="view-developer" class="content-view hidden space-y-6">
                <!-- Endpoint Box -->
                <div class="bg-[#04122d] border border-[#00D9FF]/40 rounded-3xl p-6 md:p-8 shadow-2xl space-y-3">
                    <span class="text-[11px] font-bold text-[#00D9FF] uppercase tracking-wider bg-[#00D9FF]/10 border border-[#00D9FF]/30 px-3 py-1 rounded-full">
                        Public API Verification Endpoint
                    </span>
                    <div class="bg-[#020817] border border-[#0e275c] p-3.5 rounded-2xl flex items-center justify-between gap-3">
                        <input id="publicApiUrlInput" type="text" readonly value="<?= htmlspecialchars($publicApiUrl) ?>" class="bg-transparent text-xs sm:text-sm font-mono text-[#00D9FF] w-full focus:outline-none select-all truncate">
                        <button onclick="copyText(<?= htmlspecialchars(json_encode($publicApiUrl), ENT_QUOTES) ?>)" class="text-xs bg-[#092257] hover:bg-[#007BFF] text-white px-3.5 py-2 rounded-xl border border-[#174396] transition font-bold">Copy</button>
                    </div>
                </div>

                <!-- Parameters & Documentation -->
                <div class="bg-[#04122d] border border-[#00D9FF]/40 rounded-3xl p-6 md:p-8 shadow-2xl space-y-5">
                    <h3 class="text-base font-bold text-white flex items-center gap-2">
                        <i class="fa-solid fa-book-open text-[#00D9FF]"></i> API Specifications &amp; Request Parameters
                    </h3>

                    <div class="overflow-x-auto">
                        <table class="w-full text-left text-xs text-slate-300">
                            <thead class="bg-[#020817] text-[#00D9FF] uppercase font-bold">
                                <tr>
                                    <th class="p-3">Field</th>
                                    <th class="p-3">Type</th>
                                    <th class="p-3">Requirement</th>
                                    <th class="p-3">Description</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-[#0e275c]">
                                <tr>
                                    <td class="p-3 font-mono font-bold text-white">license_key</td>
                                    <td class="p-3 font-mono text-slate-400">string</td>
                                    <td class="p-3 text-[#00D89A] font-bold">Required</td>
                                    <td class="p-3">Your active plan license key or Bot connection key.</td>
                                </tr>
                                <tr>
                                    <td class="p-3 font-mono font-bold text-white">trx_id</td>
                                    <td class="p-3 font-mono text-slate-400">string</td>
                                    <td class="p-3 text-[#00D89A] font-bold">Required</td>
                                    <td class="p-3">Transaction ID, Binance Order ID, or last 4 digits of sender phone.</td>
                                </tr>
                                <tr>
                                    <td class="p-3 font-mono font-bold text-white">method</td>
                                    <td class="p-3 font-mono text-slate-400">string</td>
                                    <td class="p-3 text-[#00D89A] font-bold">Required</td>
                                    <td class="p-3">Target gateway: <code class="text-[#00D9FF]">BKASH</code>, <code class="text-[#00D9FF]">NAGAD</code>, <code class="text-[#00D9FF]">ROCKET</code>, <code class="text-[#00D9FF]">BINANCE</code>, or <code class="text-[#00D9FF]">BANGLA_QR</code>.</td>
                                </tr>
                                <tr>
                                    <td class="p-3 font-mono font-bold text-white">client_name</td>
                                    <td class="p-3 font-mono text-slate-400">string</td>
                                    <td class="p-3 text-amber-400 font-bold">Brand keys</td>
                                    <td class="p-3">Your website domain, bot username or app name (e.g. <code class="text-[#00D9FF]">mystore.com</code>, <code class="text-[#00D9FF]">@mystore_bot</code>). A brand key connects to the first name it receives and only works there. No IP check — changing server or network never blocks you.</td>
                                </tr>
                                <tr>
                                    <td class="p-3 font-mono font-bold text-white">amount</td>
                                    <td class="p-3 font-mono text-slate-400">float</td>
                                    <td class="p-3 text-slate-400 font-semibold">Optional</td>
                                    <td class="p-3">Expected amount to match. Pass 0 to verify any incoming amount.</td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>

                <!-- Code Examples in Multi-Languages -->
                <div class="bg-[#04122d] border border-[#00D9FF]/40 rounded-3xl p-6 md:p-8 shadow-2xl">
                    <div class="flex flex-col sm:flex-row justify-between items-start sm:items-center gap-3 mb-4">
                        <h2 class="text-base font-bold text-white flex items-center gap-2">
                            <i class="fa-solid fa-code text-[#00D9FF]"></i> Code Integration Samples
                        </h2>

                        <div class="flex bg-[#020817] p-1 rounded-xl border border-[#0e275c] text-xs">
                            <button onclick="switchCodeLang('python')" id="code-btn-python" class="px-3 py-1.5 rounded-lg font-bold bg-[#007BFF] text-white transition">Python</button>
                            <button onclick="switchCodeLang('php')" id="code-btn-php" class="px-3 py-1.5 rounded-lg font-bold text-slate-400 hover:text-white transition">PHP</button>
                            <button onclick="switchCodeLang('js')" id="code-btn-js" class="px-3 py-1.5 rounded-lg font-bold text-slate-400 hover:text-white transition">Node.js</button>
                            <button onclick="switchCodeLang('java')" id="code-btn-java" class="px-3 py-1.5 rounded-lg font-bold text-slate-400 hover:text-white transition">Java</button>
                        </div>
                    </div>

                    <!-- Python Code Snippet -->
                    <div id="code-block-python" class="relative bg-[#020817] border border-[#0e275c] rounded-2xl p-4 font-mono text-xs text-slate-300 overflow-x-auto">
                        <pre id="pythonSnippet">import requests

API_URL = "<?= htmlspecialchars($publicApiUrl) ?>"
LICENSE_KEY = "YOUR_BRAND_KEY"          # SNZ-BOT-...
CLIENT_NAME = "@mystore_bot"            # your bot username / website domain / app name

def verify_payment(trx_id: str, method: str = "BKASH", amount: float = 0):
    payload = {
        "license_key": LICENSE_KEY,
        "client_name": CLIENT_NAME,
        "trx_id": trx_id,
        "method": method,  # 'BKASH', 'NAGAD', 'ROCKET', 'BINANCE', 'BANGLA_QR'
        "amount": amount
    }
    try:
        response = requests.post(API_URL, json=payload, timeout=10)
        data = response.json()
        if data.get("status") == "success":
            print("Payment Verified! Amount BDT:", data["data"]["amount"])
            return True
        else:
            print("Verification Failed:", data.get("reason") or data.get("message"))
            return False
    except Exception as e:
        print("Network Error:", e)
        return False

# Example:
verify_payment("BL7A90XYZ", "BKASH", 500.0)</pre>
                    </div>

                    <!-- PHP Code Snippet -->
                    <div id="code-block-php" class="hidden relative bg-[#020817] border border-[#0e275c] rounded-2xl p-4 font-mono text-xs text-slate-300 overflow-x-auto">
                        <pre id="phpSnippet">&lt;?php
$apiUrl = "<?= htmlspecialchars($publicApiUrl) ?>";
$licenseKey = "YOUR_BRAND_KEY";      // SNZ-BOT-...
$clientName = "mystore.com";         // your website domain / bot username / app name

$payload = [
    "license_key" => $licenseKey,
    "client_name" => $clientName,
    "trx_id"      => "BL7A90XYZ",
    "method"      => "BKASH", // 'BKASH', 'NAGAD', 'ROCKET', 'BINANCE', 'BANGLA_QR'
    "amount"      => 500.0
];

$ch = curl_init($apiUrl);
curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
$res = curl_exec($ch);
curl_close($ch);

$result = json_decode($res, true);
if ($result && $result['status'] === 'success') {
    echo "Payment Verified! Amount: " . $result['data']['amount'] . " BDT";
} else {
    echo "Verification Failed: " . ($result['reason'] ?? $result['message'] ?? 'Error');
}</pre>
                    </div>

                    <!-- Node.js / JavaScript -->
                    <div id="code-block-js" class="hidden relative bg-[#020817] border border-[#0e275c] rounded-2xl p-4 font-mono text-xs text-slate-300 overflow-x-auto">
                        <pre id="jsSnippet">const axios = require('axios');

async function verifyPayment(trxId, method = 'BKASH', amount = 0) {
    const url = '<?= htmlspecialchars($publicApiUrl) ?>';
    const payload = {
        license_key: 'YOUR_BRAND_KEY',
        client_name: 'mystore.com',
        trx_id: trxId,
        method: method,
        amount: amount
    };

    try {
        const response = await axios.post(url, payload);
        if (response.data.status === 'success') {
            console.log('Payment Verified!', response.data.data.amount);
            return true;
        } else {
            console.log('Failed:', response.data.message);
            return false;
        }
    } catch (error) {
        console.error('Error:', error.response ? error.response.data : error.message);
        return false;
    }
}

verifyPayment('BL7A90XYZ', 'BKASH', 500.0);</pre>
                    </div>

                    <!-- Java Code Snippet -->
                    <div id="code-block-java" class="hidden relative bg-[#020817] border border-[#0e275c] rounded-2xl p-4 font-mono text-xs text-slate-300 overflow-x-auto">
                        <pre id="javaSnippet">import java.net.URI;
import java.net.http.HttpClient;
import java.net.http.HttpRequest;
import java.net.http.HttpResponse;

public class PaymentVerifier {
    private static final String API_URL = "<?= htmlspecialchars($publicApiUrl) ?>";

    public static void main(String[] args) throws Exception {
        String json = "{\"license_key\":\"YOUR_BRAND_KEY\",\"client_name\":\"com.mystore.app\",\"trx_id\":\"BL7A90XYZ\",\"method\":\"BKASH\",\"amount\":500.0}";

        HttpClient client = HttpClient.newHttpClient();
        HttpRequest request = HttpRequest.newBuilder()
            .uri(URI.create(API_URL))
            .header("Content-Type", "application/json")
            .POST(HttpRequest.BodyPublishers.ofString(json))
            .build();

        HttpResponse&lt;String&gt; response = client.send(request, HttpResponse.BodyHandlers.ofString());
        System.out.println("Response: " + response.body());
    }
}</pre>
                    </div>

                    <button onclick="copyCurrentCodeSnippet()" class="mt-4 w-full bg-[#092257] hover:bg-[#007BFF] text-white text-xs font-bold py-3 rounded-xl transition flex items-center justify-center gap-2 border border-[#174396]">
                        <i class="fa-regular fa-copy"></i> Copy Selected Code Snippet
                    </button>
                </div>
            </section>

            <!-- ========================================================================= -->
            <!-- 10. DOWNLOAD APP VIEW -->
            <!-- ========================================================================= -->
            <section id="view-app" class="content-view hidden space-y-6">
                <div class="bg-[#04122d] border border-[#00D9FF]/40 rounded-3xl p-8 text-center max-w-lg mx-auto shadow-2xl">
                    <img src="<?= htmlspecialchars($SITE_LOGO) ?>" onerror="this.onerror=null;this.src='data:image/svg+xml,%3Csvg xmlns=%22http://www.w3.org/2000/svg%22 viewBox=%220 0 64 64%22%3E%3Cdefs%3E%3ClinearGradient id=%22g%22 x1=%220%22 y1=%220%22 x2=%221%22 y2=%221%22%3E%3Cstop offset=%220%22 stop-color=%22%23007BFF%22/%3E%3Cstop offset=%22.5%22 stop-color=%22%2300D9FF%22/%3E%3Cstop offset=%221%22 stop-color=%22%2300D89A%22/%3E%3C/linearGradient%3E%3C/defs%3E%3Crect width=%2264%22 height=%2264%22 rx=%2216%22 fill=%22url(%23g)%22/%3E%3Ctext x=%2232%22 y=%2244%22 font-family=%22Arial%22 font-weight=%22900%22 font-size=%2234%22 text-anchor=%22middle%22 fill=%22%23061535%22%3ES%3C/text%3E%3C/svg%3E'" alt="App Logo" class="w-20 h-20 object-contain mx-auto mb-4">
                    <h2 class="text-lg font-bold text-white"><?= htmlspecialchars($SITE_NAME) ?> Official Android App</h2>
                    <p class="text-xs text-slate-300 mt-2 mb-6">Install our Android application on your phone. Log in with your license key to automatically sync incoming SMS with zero manual effort.</p>
                    <a href="app-release.apk" download class="inline-flex items-center justify-center gap-2 w-full snz-gradient text-[#061535] font-black py-3.5 rounded-2xl shadow-lg shadow-cyan-500/20 transition text-xs">
                        <i class="fa-solid fa-download"></i> Download Official APK
                    </a>
                </div>
            </section>

            <!-- ========================================================================= -->
            <!-- 11. SUPPORT VIEW -->
            <!-- ========================================================================= -->
            <section id="view-support" class="content-view hidden space-y-6">
                <div class="bg-[#04122d] border border-[#00D9FF]/40 rounded-3xl p-8 text-center max-w-lg mx-auto shadow-2xl">
                    <div class="w-16 h-16 rounded-3xl bg-[#00D9FF]/15 border border-[#00D9FF]/30 text-[#00D9FF] flex items-center justify-center text-3xl mx-auto mb-4">
                        <i class="fa-solid fa-headset"></i>
                    </div>
                    <h2 class="text-lg font-bold text-white">24/7 Dedicated Support</h2>
                    <p class="text-xs text-slate-300 mt-2 mb-6">Need assistance with your account, payment verification, bot integration, or app setup?</p>
                    <a href="<?= htmlspecialchars($SUPPORT_LINK) ?>" target="_blank" class="inline-flex items-center justify-center gap-2 w-full snz-gradient text-[#061535] font-black py-3.5 rounded-2xl shadow-lg shadow-cyan-500/20 transition text-xs">
                        <i class="fa-brands fa-telegram text-base"></i> Contact via Telegram
                    </a>
                </div>
            </section>

        </main>
    </div>

    <script>
        let currentLang = 'python';

        function showNotification(type, title, message) {
            const toast = document.getElementById('universalToast');
            const iconContainer = document.getElementById('toastIconContainer');
            const titleEl = document.getElementById('toastTitle');
            const msgEl = document.getElementById('toastMessage');

            if (type === 'success') {
                iconContainer.className = 'w-10 h-10 rounded-xl flex items-center justify-center text-lg shrink-0 bg-[#00D89A]/20 text-[#00D89A] border border-[#00D89A]/40';
                iconContainer.innerHTML = '<i class="fa-solid fa-circle-check"></i>';
            } else {
                iconContainer.className = 'w-10 h-10 rounded-xl flex items-center justify-center text-lg shrink-0 bg-[#00D9FF]/20 text-[#00D9FF] border border-[#00D9FF]/40';
                iconContainer.innerHTML = '<i class="fa-solid fa-triangle-exclamation"></i>';
            }

            titleEl.innerText = title;
            msgEl.innerText = message;
            toast.classList.remove('opacity-0', '-translate-y-8', 'pointer-events-none');
            toast.classList.add('opacity-100', 'translate-y-0');

            setTimeout(() => {
                toast.classList.add('opacity-0', '-translate-y-8', 'pointer-events-none');
                toast.classList.remove('opacity-100', 'translate-y-0');
            }, 2500);
        }

        if (window.history.replaceState) {
            window.history.replaceState(null, null, window.location.href);
        }

        <?php if ($msg): ?>
            showNotification('success', 'SUCCESS', <?= json_encode($msg, JSON_HEX_TAG | JSON_HEX_APOS | JSON_UNESCAPED_UNICODE) ?>);
        <?php endif; ?>
        <?php if ($error): ?>
            showNotification('error', 'ATTENTION', <?= json_encode($error, JSON_HEX_TAG | JSON_HEX_APOS | JSON_UNESCAPED_UNICODE) ?>);
        <?php endif; ?>

        function toggleDrawer() {
            const sidebar = document.getElementById('sidebar');
            const overlay = document.getElementById('drawerOverlay');
            sidebar.classList.toggle('-translate-x-full');
            overlay.classList.toggle('hidden');
        }

        function closeDrawer() {
            const sidebar = document.getElementById('sidebar');
            const overlay = document.getElementById('drawerOverlay');
            if (sidebar) sidebar.classList.add('-translate-x-full');
            if (overlay) overlay.classList.add('hidden');
        }

        function switchView(viewId, saveState = true) {
            document.querySelectorAll('.content-view').forEach(el => el.classList.add('hidden'));
            const target = document.getElementById(viewId);
            if (target) target.classList.remove('hidden');

            document.querySelectorAll('.nav-item').forEach(el => el.classList.remove('active-tab'));
            const activeNav = document.getElementById('nav-' + viewId.replace('view-', ''));
            if (activeNav) activeNav.classList.add('active-tab');

            if (saveState) {
                try { sessionStorage.setItem('snz_active_view', viewId); } catch (e) {}
            }
            closeDrawer();
        }

        window.addEventListener('DOMContentLoaded', () => {
            const serverTarget = <?= json_encode($activeTabTarget) ?>;
            let savedView = null;
            try { savedView = sessionStorage.getItem('snz_active_view'); } catch (e) {}
            const finalView = (serverTarget !== 'view-dashboard') ? serverTarget : (savedView || 'view-dashboard');
            switchView(finalView, false);
        });

        // ==========================================
        // SILENT REALTIME LIVE FEED (EVERY 3.5 SECONDS)
        // ==========================================
        // HTML এ বসানোর আগে escape (SMS এর sender/trx দিয়ে স্ক্রিপ্ট ঢোকানো ঠেকাতে)
        function esc(v) {
            return String(v == null ? '' : v).replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
        }
        let feedBusy = false;
        let lastFeedHash = '';

        async function fetchLiveFeed() {
            // ট্যাব লুকানো থাকলে বা আগের রিকোয়েস্ট চলছে থাকলে স্কিপ → সার্ভার আর ফোন দুটোই হালকা
            if (feedBusy || document.hidden) return;
            feedBusy = true;
            try {
                const res = await fetch('dashboard.php?ajax_live_feed=1', { cache: 'no-store', credentials: 'same-origin' });
                if (res.status === 401) { window.location.href = 'index.php'; return; }
                if (!res.ok) return;
                const text = await res.text();
                if (text === lastFeedHash) return;   // কিছু বদলায়নি → DOM আবার আঁকার দরকার নেই (flicker/lag বন্ধ)
                lastFeedHash = text;
                const d = JSON.parse(text);
                
                document.getElementById('metric-today-deposit').innerText = '৳ ' + d.today_deposit;
                document.getElementById('metric-pending-count').innerHTML = d.pending_count + ' <span class="text-xs font-semibold text-slate-400">(৳' + d.pending_amount + ')</span>';
                document.getElementById('metric-approved-count').innerText = d.approved_count + ' Items';
                document.getElementById('metric-lifetime-deposit').innerText = '৳ ' + d.lifetime_deposit;
                document.getElementById('live-pending-badge').innerText = (d.pending_sms ? d.pending_sms.length : 0) + ' Pending';

                const container = document.getElementById('live-pending-list');
                if (!container) return;

                if (!d.pending_sms || d.pending_sms.length === 0) {
                    container.innerHTML = `
                        <div class="bg-[#04122d] border border-[#00D9FF]/40 rounded-3xl p-8 md:p-10 text-center flex flex-col items-center justify-center shadow-[0_0_20px_rgba(0,217,255,0.12)]">
                            <div class="w-16 h-16 rounded-full bg-[#00D9FF]/15 border border-[#00D9FF]/50 flex items-center justify-center text-2xl text-[#00D9FF] shadow-[0_0_20px_rgba(0,217,255,0.4)] mb-4 pulse-icon">
                                <i class="fa-solid fa-envelope"></i>
                            </div>
                            <h4 class="text-sm md:text-base font-bold text-white">No pending transactions right now.</h4>
                            <p class="text-xs text-slate-400 mt-1">Your received SMS and Binance Pay orders will automatically appear here.</p>
                        </div>
                    `;
                } else {
                    let html = '<div class="space-y-2.5">';
                    d.pending_sms.forEach(sms => {
                        let m = String(sms.method || '').toUpperCase();
                        let badgeColor = "#00D9FF";
                        let curr = "৳";
                        let unit = "";
                        if (m.includes('BKASH')) badgeColor = "#E2136E";
                        else if (m.includes('NAGAD')) badgeColor = "#EA580C";
                        else if (m.includes('QR')) badgeColor = "#0284C7";
                        else if (m.includes('BINANCE')) { badgeColor = "#F59E0B"; curr = "$"; unit = "USDT"; }
                        else if (m.includes('ROCKET')) badgeColor = "#7C3AED";

                        html += `
                            <div class="bg-[#04122d] border border-[#00D9FF]/40 p-4 rounded-2xl flex flex-col sm:flex-row justify-between items-start sm:items-center gap-3 shadow-lg shadow-cyan-900/10">
                                <div class="flex items-center gap-3 min-w-0 w-full sm:w-auto">
                                    <span class="text-[10px] font-black px-2.5 py-1 rounded-lg text-white shrink-0" style="background-color: ${badgeColor};">
                                        ${esc(sms.method)}
                                    </span>
                                    <div class="min-w-0 flex-1">
                                        <div class="flex items-start gap-2 min-w-0">
                                            <span class="font-mono text-sm font-bold text-white tracking-wider select-all break-all min-w-0">${esc(sms.trx_id)}</span>
                                            <button onclick="copyText(this.dataset.v)" data-v="${esc(sms.trx_id)}" class="text-slate-400 hover:text-[#00D9FF] text-xs shrink-0 pt-0.5">
                                                <i class="fa-regular fa-copy"></i>
                                            </button>
                                        </div>
                                        <p class="text-[11px] text-slate-400">
                                            From: <b class="text-slate-300">${esc(sms.sender || 'N/A')}</b> • ${esc(sms.time_fmt || sms.created_at)}
                                        </p>
                                    </div>
                                </div>
                                <div class="flex items-center justify-between sm:justify-end w-full sm:w-auto gap-4 border-t sm:border-t-0 border-[#0e275c] pt-2 sm:pt-0">
                                    <div class="text-left sm:text-right">
                                        <div class="text-base font-black text-[#00D9FF]">${curr} ${parseFloat(sms.amount).toFixed(2)} ${unit}</div>
                                        <span class="text-[10px] bg-amber-500/15 text-amber-400 px-2 py-0.5 rounded-full font-bold border border-amber-500/30">
                                            Pending Verification
                                        </span>
                                    </div>
                                    <form method="POST" onsubmit="return confirm('Delete this transaction?');">
                                <input type="hidden" name="csrf" value="<?= $CSRF ?>">
                                        <input type="hidden" name="action_delete_pending_sms" value="1">
                                        <input type="hidden" name="delete_payment_id" value="${parseInt(sms.id, 10)}">
                                        <button type="submit" class="text-slate-400 hover:text-rose-400 p-2 text-xs transition">
                                            <i class="fa-solid fa-trash-can"></i>
                                        </button>
                                    </form>
                                </div>
                            </div>
                        `;
                    });
                    html += '</div>';
                    container.innerHTML = html;
                }
            } catch (err) {
            } finally {
                feedBusy = false;
            }
        }
        setInterval(fetchLiveFeed, 3500);
        document.addEventListener('visibilitychange', () => { if (!document.hidden) fetchLiveFeed(); });

        function filterHistoryItems(status) {
            document.querySelectorAll('#hist-tab-all, #hist-tab-used, #hist-tab-pending').forEach(btn => {
                btn.className = "px-3 py-1.5 rounded-xl font-semibold text-slate-400 hover:text-white transition";
            });
            const activeBtn = document.getElementById('hist-tab-' + status);
            if (activeBtn) {
                activeBtn.className = "px-3 py-1.5 rounded-xl font-bold bg-[#007BFF] text-white transition";
            }

            document.querySelectorAll('.history-item').forEach(item => {
                if (status === 'all' || item.getAttribute('data-status') === status) {
                    item.classList.remove('hidden');
                } else {
                    item.classList.add('hidden');
                }
            });
        }

        // ==========================================
        // DYNAMIC ADD FUND GATEWAY SWITCHER
        // ==========================================
        // ==========================================
        // 💳 ADD FUND — মেথড পেজ + "কিভাবে পাঠাবেন" অ্যানিমেশন + প্ল্যান লাইভ পে
        // ==========================================
        const fundMethods = <?= json_encode($fundMethods, JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
        const userBalance = <?= json_encode(round((float) $user['balance'], 2)) ?>;
        const usdtRate = <?= json_encode((float) $USDT_RATE) ?>;
        let planPay = null, curMethod = null, animTimer = null;

        function escH(t) { return String(t == null ? '' : t).replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c])); }

        function needAmount(m) {
            if (!planPay) return '';
            const need = Math.max(0, planPay.price - userBalance);
            let v = m.currency === 'USDT' ? Math.ceil(need / usdtRate * 100) / 100 : Math.ceil(need);
            return Math.max(v, +m.min || 0);
        }

        function openPayMethod(id) {
            const m = fundMethods[id];
            if (!m) return;
            curMethod = m;
            document.getElementById('pmPick').classList.add('hidden');
            document.getElementById('pmPay').classList.remove('hidden');
            document.getElementById('fund_hidden_provider').value = id;

            const fr = document.getElementById('pmFrame');
            fr.style.setProperty('--c', m.color);
            document.getElementById('pmFLogo').innerHTML = m.logo_html;
            document.getElementById('pmFName').innerText = m.name;
            const ft = document.getElementById('pmFType');
            ft.innerText = (m.type_label ? m.type_label + ' • ' : '') + 'use "' + m.action + '"';
            ft.style.color = m.color;
            document.getElementById('pmFBadge').innerText = m.action;
            const numLbl = m.kind === 'crypto' ? 'Pay ID / Address' : m.kind === 'bank' ? 'Account' : (m.kind === 'qr' ? 'Account' : m.action + ' to this number');
            document.getElementById('pmFNumLbl').innerText = numLbl;
            document.getElementById('pmFNum').innerText = m.number;
            document.getElementById('pmFNumBox').style.display = m.number ? '' : 'none';
            const qrBox = document.getElementById('pmFQrBox');
            if (m.qr) { document.getElementById('pmFQr').src = m.qr; qrBox.classList.remove('hidden'); } else qrBox.classList.add('hidden');
            document.getElementById('pmFNote').textContent = m.note || '';
            document.getElementById('pmFNoteBox').classList.toggle('hidden', !m.note);

            const usd = m.currency === 'USDT';
            document.getElementById('fundAmountLabel').innerText = usd ? 'Amount (USDT)' : 'Amount (BDT)';
            document.getElementById('fundTrxLabel').innerText = m.kind === 'crypto' ? 'Order ID / TxID' : (m.kind === 'qr' ? 'TrxID or sender last 4 digits' : 'Transaction ID (TrxID)');
            const amt = document.getElementById('fundAmount');
            amt.placeholder = 'Min ' + m.min + (usd ? ' USDT' : ' ৳');
            amt.min = m.min;
            if (planPay) amt.value = needAmount(m);
            document.querySelector('#pmVerifyBtn span').innerText = planPay ? 'Verify & Activate Plan' : 'Verify & Add Balance';

            buildPayAnim(m);
            window.scrollTo({ top: 0, behavior: 'smooth' });
        }

        function backToMethods() {
            clearInterval(animTimer);
            document.getElementById('pmPay').classList.add('hidden');
            document.getElementById('pmPick').classList.remove('hidden');
        }

        function startPlanPay(id, name, price) {
            planPay = { id: id, name: name, price: +price };
            switchView('view-add-fund');
            document.getElementById('planPayBanner').classList.remove('hidden');
            document.getElementById('planPayName').innerText = name;
            document.getElementById('planPayPrice').innerText = (+price).toFixed(2);
            document.getElementById('planPayNeed').innerText = Math.max(0, price - userBalance).toFixed(2);
            const pp = document.getElementById('pending_plan_id'); if (pp) pp.value = id;
            if (curMethod && !document.getElementById('pmPay').classList.contains('hidden')) openPayMethod(curMethod.id);
            const keys = Object.keys(fundMethods);
            if (keys.length === 1) openPayMethod(keys[0]);
            window.scrollTo({ top: 0, behavior: 'smooth' });
        }

        function cancelPlanPay() {
            planPay = null;
            document.getElementById('planPayBanner').classList.add('hidden');
            const pp = document.getElementById('pending_plan_id'); if (pp) pp.value = '';
            const b = document.querySelector('#pmVerifyBtn span'); if (b) b.innerText = 'Verify & Add Balance';
        }

        // 🎬 ফোনের ভেতরে ধাপে ধাপে দেখানো: অ্যাপ খোলা → অপশন → নম্বর → টাকা → PIN → TrxID
        function buildPayAnim(m) {
            clearInterval(animTimer);
            const box = document.getElementById('pmAnim');
            box.style.setProperty('--c', m.color);
            const nm = escH(m.name), act = escH(m.action);
            const usd = m.currency === 'USDT';
            const demoAmt = planPay ? needAmount(m) : (usd ? 5 : 500);
            const amtTxt = (usd ? '' : '৳ ') + demoAmt + (usd ? ' USDT' : '');
            const num = escH(m.number || (m.kind === 'qr' ? '' : '01XXXXXXXXX'));
            const logo = m.logo ? '<img src="' + escH(m.logo) + '" alt="">' : '<b>' + escH(m.name.charAt(0)) + '</b>';
            const head = t => '<div class="sc-head">' + t + '</div>';
            const typing = (t, mono) => '<div class="sc-input' + (mono ? ' mono' : '') + '"><span class="sc-type" style="--n:' + String(t).replace(/<[^>]+>|&[a-z]+;/g, 'x').length + '">' + t + '</span></div>';

            let tiles;
            if (m.kind === 'crypto') tiles = ['Pay', 'Send', 'P2P', 'Wallet'];
            else if (m.kind === 'qr') tiles = ['Scan QR', 'Send Money', 'Payment', 'Top Up'];
            else if (m.kind === 'bank') tiles = ['Transfer', 'Bills', 'Cards', 'Accounts'];
            else tiles = ['Send Money', 'Cash Out', 'Payment', 'Recharge'];
            let hit = tiles.findIndex(t => t.toLowerCase() === m.action.toLowerCase());
            if (hit < 0) hit = 0;
            const grid = '<div class="sc-grid">' + tiles.map((t, i) => '<div class="sc-tile' + (i === hit ? ' hit' : '') + '"><i></i>' + escH(t) + '</div>').join('') + '</div>';

            const steps = [
                { t: 'Open the ' + nm + ' app', s: head(nm) + '<div class="sc-center"><div class="sc-logo">' + logo + '</div><div class="sc-cap">' + nm + '</div></div>' },
                { t: 'Tap “' + act + '”', s: head(nm) + grid, tap: hit },
            ];
            if (m.kind === 'qr') {
                steps.push({ t: 'Scan the QR code shown below', s: head('Scan QR') + '<div class="sc-scan"><div class="sc-qr"></div><div class="sc-line"></div></div>' });
            } else {
                steps.push({ t: (m.kind === 'crypto' ? 'Enter the Pay ID' : 'Enter this number') + (m.number ? ': <b>' + num + '</b>' : ''), s: head(act) + '<div class="sc-lbl">' + (m.kind === 'crypto' ? 'Pay ID' : 'To') + '</div>' + typing(num, true) });
            }
            steps.push({ t: 'Enter amount: <b>' + escH(amtTxt) + '</b>', s: head(act) + '<div class="sc-lbl">Amount</div>' + typing(escH(amtTxt)) });
            steps.push({ t: m.kind === 'crypto' ? 'Confirm the payment' : 'Enter your PIN & confirm', s: head('Confirm') + '<div class="sc-pin"><i></i><i></i><i></i><i></i><i></i></div><div class="sc-hold">Tap & hold to confirm</div>' });
            steps.push({ t: 'Copy the ' + (m.kind === 'crypto' ? 'Order ID' : 'TrxID') + ' & paste it below ⬇', s: '<div class="sc-done"><div class="sc-check">✓</div><div class="sc-ok">Successful</div><div class="sc-trx">' + (m.kind === 'crypto' ? 'Order ID' : 'TrxID') + ' <b>8N7A6D5EKL</b></div></div>' });

            box.innerHTML = '<div class="pa-phone"><div class="pa-notch"></div><div class="pa-screen" id="paScreen"></div><div class="pa-finger" id="paFinger">👆</div></div>'
                + '<div class="pa-side"><div class="pa-kicker">HOW TO PAY • ' + act.toUpperCase() + '</div><div class="pa-title" id="paTitle"></div>'
                + '<div class="pa-dots">' + steps.map(() => '<i></i>').join('') + '</div><div class="pa-bar"><i id="paBar"></i></div></div>';
            let i = 0;
            const show = () => {
                const st = steps[i];
                const sc = document.getElementById('paScreen'), ti = document.getElementById('paTitle'), fg = document.getElementById('paFinger');
                if (!sc) return clearInterval(animTimer);
                sc.classList.remove('in'); void sc.offsetWidth;
                sc.innerHTML = st.s; sc.classList.add('in');
                ti.innerHTML = '<span class="pa-num">' + (i + 1) + '</span>' + st.t;
                box.querySelectorAll('.pa-dots i').forEach((d, k) => d.classList.toggle('on', k <= i));
                const bar = document.getElementById('paBar'); bar.style.transition = 'none'; bar.style.width = '0'; void bar.offsetWidth; bar.style.transition = 'width 2.5s linear'; bar.style.width = '100%';
                if (st.tap !== undefined) { fg.style.left = (st.tap % 2 ? 66 : 28) + '%'; fg.style.top = (st.tap < 2 ? 47 : 70) + '%'; fg.classList.add('show'); }
                else fg.classList.remove('show');
                i = (i + 1) % steps.length;
            };
            show();
            animTimer = setInterval(show, 2600);
        }

        <?php if (isset($_POST['action_add_fund']) && $error && isset($fundMethods[strtolower(trim($_POST['provider'] ?? ''))])): ?>
        document.addEventListener('DOMContentLoaded', () => {
            <?php $ppId = intval($_POST['pending_plan_id'] ?? 0); $ppPlan = $ppId ? snzGetPlan($pdo, $ppId) : null; if ($ppPlan): ?>
            startPlanPay(<?= (int) $ppPlan['id'] ?>, <?= json_encode((string) $ppPlan['name'], JSON_HEX_TAG) ?>, <?= round(snzPlanFinalPrice($ppPlan), 2) ?>);
            <?php endif; ?>
            openPayMethod(<?= json_encode(strtolower(trim($_POST['provider'] ?? ''))) ?>);
            const a = document.getElementById('fundAmount'); if (a) a.value = <?= json_encode((string) ($_POST['deposit_amount'] ?? '')) ?>;
        });
        <?php endif; ?>

// প্ল্যান ফিল্টার বাটন
        function filterPlans(cat, btn) {
            document.querySelectorAll('.plan-card').forEach(c => {
                c.style.display = (cat === 'all' || (c.dataset.cat || '').indexOf('|' + cat + '|') !== -1) ? '' : 'none';
            });
            document.querySelectorAll('.plan-filter-btn').forEach(b => b.classList.remove('active-filter'));
            if (btn) btn.classList.add('active-filter');
        }

                function brandTypeHint() {
            const t = (document.querySelector('input[name="brand_type"]:checked') || {}).value;
            const lbl = document.getElementById('brandLinkLabel'), inp = document.getElementById('brandLinkInput');
            if (!lbl || !inp) return;
            if (t === 'bot') { lbl.innerText = 'Bot Username / Link'; inp.placeholder = '@mystore_bot or https://t.me/mystore_bot'; }
            else if (t === 'app') { lbl.innerText = 'App Name / Play Store Link'; inp.placeholder = 'https://play.google.com/store/apps/details?id=...'; }
            else { lbl.innerText = 'Website Link'; inp.placeholder = 'https://mystore.com'; }
        }

        function copyText(str) {
            str = String(str);
            const done = () => showNotification('success', 'COPIED', 'Copied: ' + str);
            if (navigator.clipboard && window.isSecureContext) {
                navigator.clipboard.writeText(str).then(done).catch(() => fallbackCopy(str, done));
            } else {
                fallbackCopy(str, done);
            }
        }
        // http বা পুরনো ফোনে navigator.clipboard থাকে না → আগে Copy বাটন কাজ করত না
        function fallbackCopy(str, done) {
            const ta = document.createElement('textarea');
            ta.value = str; ta.style.position = 'fixed'; ta.style.opacity = '0';
            document.body.appendChild(ta); ta.select();
            try { document.execCommand('copy'); done(); } catch (e) {}
            ta.remove();
        }

        function filterWalletTab(cat) {
            document.querySelectorAll('.wallet-grid').forEach(el => el.classList.add('hidden'));
            document.querySelectorAll('.wallet-cat-btn').forEach(btn => {
                btn.className = "wallet-cat-btn px-4 py-2 rounded-xl text-xs font-bold text-slate-300 hover:text-white transition";
            });

            document.getElementById('grid-' + cat).classList.remove('hidden');
            const activeBtn = document.getElementById('tab-' + cat);
            activeBtn.className = "wallet-cat-btn px-4 py-2 rounded-xl text-xs font-black bg-[#007BFF] text-white transition shadow-md";
        }

        // ইউজারের আগে যোগ করা wallet (Secret Key পাঠানো হয় না)
        const SAVED_WALLETS = <?= json_encode(array_map(function ($w) {
            return [
                'id' => (int) $w['id'],
                'category' => (string) ($w['category'] ?? ''),
                'account_type' => (string) ($w['account_type'] ?? ''),
                'account_number' => (string) ($w['account_number'] ?? ''),
                'account_name' => (string) ($w['account_name'] ?? ''),
                'api_key' => (string) ($w['api_key'] ?? ''),
                'dollar_rate' => (string) ($w['dollar_rate'] ?? '125.00'),
                'active' => ($w['status'] ?? '') === 'active',
                'has_qr' => !empty($w['qr_image']),
            ];
        }, $walletsByProvider), JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE) ?>;

        function openWalletModal(category, provider) {
            const formBox = document.getElementById('walletSetupForm');
            formBox.classList.remove('hidden');

            const saved = SAVED_WALLETS[String(provider).toLowerCase()] || null;
            document.getElementById('input_category').value = category;
            document.getElementById('input_provider').value = provider;
            document.getElementById('input_wallet_id').value = saved ? saved.id : 0;
            document.getElementById('formTitle').innerText = (saved ? "Edit " : "Add ") + provider;
            document.getElementById('walletSubmitText').innerText = saved ? "Update Gateway" : "Save Gateway";
            document.getElementById('walletEditNote').classList.toggle('hidden', !saved);

            // ফর্ম পূরণ (এডিট) বা খালি করা (নতুন)
            const accNum = document.getElementById('accNumberInput');
            const accName = document.getElementById('accNameInput');
            const apiKey = document.getElementById('apiKeyInput');
            const secret = document.getElementById('secretKeyInput');
            const rate = document.getElementById('dollarRateInput');
            const statusBox = document.getElementById('walletStatusInput');
            if (accNum) accNum.value = saved && saved.account_number !== 'QR Code Ready' && saved.account_number !== 'Binance Pay Active' ? saved.account_number : '';
            if (accName) accName.value = saved ? saved.account_name : '';
            if (apiKey) apiKey.value = saved ? saved.api_key : '';
            if (secret) { secret.value = ''; secret.placeholder = saved ? 'Leave empty to keep current secret' : 'Enter Binance Secret key'; }
            if (rate) rate.value = saved && parseFloat(saved.dollar_rate) > 0 ? saved.dollar_rate : '125.00';
            if (statusBox) statusBox.checked = saved ? saved.active : true;
            const wantType = saved && saved.account_type ? saved.account_type : 'Personal';
            document.querySelectorAll('input[name="account_type"]').forEach(r => { r.checked = (r.value === wantType); });

            const accTypeSection = document.getElementById('accTypeSection');
            const standardFields = document.getElementById('standardFields');
            const qrUploadSection = document.getElementById('qrUploadSection');
            const binanceFields = document.getElementById('binanceFields');
            const lblAccNumber = document.getElementById('lblAccNumber');

            if (provider === 'Binance') {
                accTypeSection.classList.add('hidden');
                standardFields.classList.add('hidden');
                qrUploadSection.classList.add('hidden');
                binanceFields.classList.remove('hidden');
            } else if (category === 'qr') {
                accTypeSection.classList.add('hidden');
                standardFields.classList.add('hidden');
                binanceFields.classList.add('hidden');
                qrUploadSection.classList.remove('hidden');
            } else {
                accTypeSection.classList.remove('hidden');
                standardFields.classList.remove('hidden');
                qrUploadSection.classList.add('hidden');
                binanceFields.classList.add('hidden');
                lblAccNumber.innerText = provider + " Number / Account:";
            }

            formBox.scrollIntoView({ behavior: 'smooth' });
        }

        function closeWalletModal() {
            document.getElementById('walletSetupForm').classList.add('hidden');
        }

        function switchCodeLang(lang) {
            currentLang = lang;
            ['python', 'php', 'js', 'java'].forEach(l => {
                const el = document.getElementById('code-block-' + l);
                if (el) el.classList.add('hidden');
                const btn = document.getElementById('code-btn-' + l);
                if (btn) {
                    btn.classList.remove('bg-[#007BFF]', 'text-white');
                    btn.classList.add('text-slate-400');
                }
            });

            const activeBlock = document.getElementById('code-block-' + lang);
            if (activeBlock) activeBlock.classList.remove('hidden');
            const activeBtn = document.getElementById('code-btn-' + lang);
            if (activeBtn) {
                activeBtn.classList.add('bg-[#007BFF]', 'text-white');
                activeBtn.classList.remove('text-slate-400');
            }
        }

        function copyCurrentCodeSnippet() {
            let elId = 'pythonSnippet';
            if (currentLang === 'php') elId = 'phpSnippet';
            else if (currentLang === 'js') elId = 'jsSnippet';
            else if (currentLang === 'java') elId = 'javaSnippet';

            const snippet = document.getElementById(elId).innerText;
            const done = () => showNotification('success', 'COPIED', currentLang.toUpperCase() + ' code copied!');
            if (navigator.clipboard && window.isSecureContext) {
                navigator.clipboard.writeText(snippet).then(done).catch(() => fallbackCopy(snippet, done));
            } else {
                fallbackCopy(snippet, done);
            }
        }
    </script>
</body>
</html>