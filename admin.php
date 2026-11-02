<?php
// Gzip compression for faster delivery
if (!ob_start("ob_gzhandler")) ob_start();

session_start();
header("X-Content-Type-Options: nosniff");

// Central Configuration File Connection
require_once __DIR__ . '/config.php';

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


// =========================================================================
// 1. Chunked Upload Engine (Allows ANY MB Size APK Upload)
// =========================================================================
if (isset($_POST['action_upload_chunk'])) {
    header('Content-Type: application/json');
    if (!isset($_SESSION['is_admin']) || $_SESSION['is_admin'] !== true) {
        echo json_encode(['status' => 'error', 'message' => 'Unauthorized']);
        exit;
    }
    if (!hash_equals((string) ($_SESSION['csrf'] ?? ''), (string) ($_POST['csrf'] ?? ''))) {
        echo json_encode(['status' => 'error', 'message' => 'Session expired, reload the page']);
        exit;
    }

    @set_time_limit(120);
    $chunkIndex = intval($_POST['chunk_index'] ?? -1);
    $totalChunks = intval($_POST['total_chunks'] ?? 0);
    if ($chunkIndex < 0 || $totalChunks < 1 || $chunkIndex >= $totalChunks) {
        echo json_encode(['status' => 'error', 'message' => 'Invalid chunk data']);
        exit;
    }
    $tempFile = __DIR__ . '/app-release.apk.tmp';
    $finalFile = __DIR__ . '/app-release.apk';

    if ($chunkIndex === 0 && file_exists($tempFile)) {
        @unlink($tempFile);
    }

    if (isset($_FILES['chunk_data']) && $_FILES['chunk_data']['error'] === UPLOAD_ERR_OK) {
        $in = fopen($_FILES['chunk_data']['tmp_name'], 'rb');
        $out = fopen($tempFile, $chunkIndex === 0 ? 'wb' : 'ab');
        if ($in && $out) {
            while (!feof($in) && ($buff = fread($in, 1048576)) !== false && $buff !== '') {
                fwrite($out, $buff);
            }
            fclose($in);
            fclose($out);

            // When final chunk is received
            if ($chunkIndex === $totalChunks - 1) {
                if (file_exists($finalFile)) @unlink($finalFile);
                rename($tempFile, $finalFile);
                clearstatcache();
                echo json_encode([
                    'status' => 'completed',
                    'size' => round(filesize($finalFile) / (1024 * 1024), 2) . ' MB',
                    'time' => date('d M Y, h:i A')
                ]);
                exit;
            }

            echo json_encode(['status' => 'chunk_saved', 'next_chunk' => $chunkIndex + 1]);
            exit;
        }
    }
    echo json_encode(['status' => 'error', 'message' => 'Failed to save chunk']);
    exit;
}

// Database Connection
$pdo = getDBConnection();
if (!$pdo) {
    die("Database Connection Error! Check config.php");
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
snzEnsurePlanSchema($pdo);

// CSRF টোকেন (অন্য সাইট থেকে admin অ্যাকশন চালানো ঠেকাতে)
if (empty($_SESSION['csrf'])) {
    $_SESSION['csrf'] = bin2hex(random_bytes(16));
}
$CSRF = $_SESSION['csrf'];

// Admin Login Check
// মোবাইলে কিবোর্ডের Go/Enter চাপলে বাটনের name যায় না → আগে লগইন হতো না। এখন hidden field দিয়ে ধরা হয়।
if (isset($_POST['admin_login']) || (isset($_POST['password']) && !isset($_POST['csrf']))) {
    if (hash_equals(trim((string) $ADMIN_PASSWORD), trim((string) ($_POST['password'] ?? '')))) {
        @session_regenerate_id(true);
        $_SESSION['is_admin'] = true;
        header("Location: admin.php");
        exit;
    } else {
        $login_error = "Invalid admin password!";
    }
}

// Logout
if (isset($_GET['logout'])) {
    unset($_SESSION['is_admin']);
    header("Location: admin.php");
    exit;
}

// Show login screen if not authenticated
if (!isset($_SESSION['is_admin']) || $_SESSION['is_admin'] !== true):
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title><?= htmlspecialchars($SITE_NAME) ?> - Admin Login</title>
    <style>*,:after,:before{--tw-border-spacing-x:0;--tw-border-spacing-y:0;--tw-translate-x:0;--tw-translate-y:0;--tw-rotate:0;--tw-skew-x:0;--tw-skew-y:0;--tw-scale-x:1;--tw-scale-y:1;--tw-pan-x: ;--tw-pan-y: ;--tw-pinch-zoom: ;--tw-scroll-snap-strictness:proximity;--tw-gradient-from-position: ;--tw-gradient-via-position: ;--tw-gradient-to-position: ;--tw-ordinal: ;--tw-slashed-zero: ;--tw-numeric-figure: ;--tw-numeric-spacing: ;--tw-numeric-fraction: ;--tw-ring-inset: ;--tw-ring-offset-width:0px;--tw-ring-offset-color:#fff;--tw-ring-color:rgba(59,130,246,.5);--tw-ring-offset-shadow:0 0 #0000;--tw-ring-shadow:0 0 #0000;--tw-shadow:0 0 #0000;--tw-shadow-colored:0 0 #0000;--tw-blur: ;--tw-brightness: ;--tw-contrast: ;--tw-grayscale: ;--tw-hue-rotate: ;--tw-invert: ;--tw-saturate: ;--tw-sepia: ;--tw-drop-shadow: ;--tw-backdrop-blur: ;--tw-backdrop-brightness: ;--tw-backdrop-contrast: ;--tw-backdrop-grayscale: ;--tw-backdrop-hue-rotate: ;--tw-backdrop-invert: ;--tw-backdrop-opacity: ;--tw-backdrop-saturate: ;--tw-backdrop-sepia: ;--tw-contain-size: ;--tw-contain-layout: ;--tw-contain-paint: ;--tw-contain-style: }::backdrop{--tw-border-spacing-x:0;--tw-border-spacing-y:0;--tw-translate-x:0;--tw-translate-y:0;--tw-rotate:0;--tw-skew-x:0;--tw-skew-y:0;--tw-scale-x:1;--tw-scale-y:1;--tw-pan-x: ;--tw-pan-y: ;--tw-pinch-zoom: ;--tw-scroll-snap-strictness:proximity;--tw-gradient-from-position: ;--tw-gradient-via-position: ;--tw-gradient-to-position: ;--tw-ordinal: ;--tw-slashed-zero: ;--tw-numeric-figure: ;--tw-numeric-spacing: ;--tw-numeric-fraction: ;--tw-ring-inset: ;--tw-ring-offset-width:0px;--tw-ring-offset-color:#fff;--tw-ring-color:rgba(59,130,246,.5);--tw-ring-offset-shadow:0 0 #0000;--tw-ring-shadow:0 0 #0000;--tw-shadow:0 0 #0000;--tw-shadow-colored:0 0 #0000;--tw-blur: ;--tw-brightness: ;--tw-contrast: ;--tw-grayscale: ;--tw-hue-rotate: ;--tw-invert: ;--tw-saturate: ;--tw-sepia: ;--tw-drop-shadow: ;--tw-backdrop-blur: ;--tw-backdrop-brightness: ;--tw-backdrop-contrast: ;--tw-backdrop-grayscale: ;--tw-backdrop-hue-rotate: ;--tw-backdrop-invert: ;--tw-backdrop-opacity: ;--tw-backdrop-saturate: ;--tw-backdrop-sepia: ;--tw-contain-size: ;--tw-contain-layout: ;--tw-contain-paint: ;--tw-contain-style: }/*! tailwindcss v3.4.17 | MIT License | https://tailwindcss.com*/*,:after,:before{box-sizing:border-box;border:0 solid #e5e7eb}:after,:before{--tw-content:""}:host,html{line-height:1.5;-webkit-text-size-adjust:100%;-moz-tab-size:4;-o-tab-size:4;tab-size:4;font-family:ui-sans-serif,system-ui,sans-serif,Apple Color Emoji,Segoe UI Emoji,Segoe UI Symbol,Noto Color Emoji;font-feature-settings:normal;font-variation-settings:normal;-webkit-tap-highlight-color:transparent}body{margin:0;line-height:inherit}hr{height:0;color:inherit;border-top-width:1px}abbr:where([title]){-webkit-text-decoration:underline dotted;text-decoration:underline dotted}h1,h2,h3,h4,h5,h6{font-size:inherit;font-weight:inherit}a{color:inherit;text-decoration:inherit}b,strong{font-weight:bolder}code,kbd,pre,samp{font-family:ui-monospace,SFMono-Regular,Menlo,Monaco,Consolas,Liberation Mono,Courier New,monospace;font-feature-settings:normal;font-variation-settings:normal;font-size:1em}small{font-size:80%}sub,sup{font-size:75%;line-height:0;position:relative;vertical-align:baseline}sub{bottom:-.25em}sup{top:-.5em}table{text-indent:0;border-color:inherit;border-collapse:collapse}button,input,optgroup,select,textarea{font-family:inherit;font-feature-settings:inherit;font-variation-settings:inherit;font-size:100%;font-weight:inherit;line-height:inherit;letter-spacing:inherit;color:inherit;margin:0;padding:0}button,select{text-transform:none}button,input:where([type=button]),input:where([type=reset]),input:where([type=submit]){-webkit-appearance:button;background-color:transparent;background-image:none}:-moz-focusring{outline:auto}:-moz-ui-invalid{box-shadow:none}progress{vertical-align:baseline}::-webkit-inner-spin-button,::-webkit-outer-spin-button{height:auto}[type=search]{-webkit-appearance:textfield;outline-offset:-2px}::-webkit-search-decoration{-webkit-appearance:none}::-webkit-file-upload-button{-webkit-appearance:button;font:inherit}summary{display:list-item}blockquote,dd,dl,figure,h1,h2,h3,h4,h5,h6,hr,p,pre{margin:0}fieldset{margin:0}fieldset,legend{padding:0}menu,ol,ul{list-style:none;margin:0;padding:0}dialog{padding:0}textarea{resize:vertical}input::-moz-placeholder,textarea::-moz-placeholder{opacity:1;color:#9ca3af}input::placeholder,textarea::placeholder{opacity:1;color:#9ca3af}[role=button],button{cursor:pointer}:disabled{cursor:default}audio,canvas,embed,iframe,img,object,svg,video{display:block;vertical-align:middle}img,video{max-width:100%;height:auto}[hidden]:where(:not([hidden=until-found])){display:none}.\!container{width:100%!important}.container{width:100%}@media (min-width:640px){.\!container{max-width:640px!important}.container{max-width:640px}}@media (min-width:768px){.\!container{max-width:768px!important}.container{max-width:768px}}@media (min-width:1024px){.\!container{max-width:1024px!important}.container{max-width:1024px}}@media (min-width:1280px){.\!container{max-width:1280px!important}.container{max-width:1280px}}@media (min-width:1536px){.\!container{max-width:1536px!important}.container{max-width:1536px}}.sr-only{position:absolute;width:1px;height:1px;padding:0;margin:-1px;overflow:hidden;clip:rect(0,0,0,0);white-space:nowrap;border-width:0}.pointer-events-none{pointer-events:none}.visible{visibility:visible}.static{position:static}.fixed{position:fixed}.absolute{position:absolute}.relative{position:relative}.sticky{position:sticky}.inset-0{inset:0}.-top-3{top:-.75rem}.bottom-0{bottom:0}.left-0{left:0}.left-1\/2{left:50%}.left-4{left:1rem}.left-5{left:1.25rem}.right-0{right:0}.right-4{right:1rem}.right-5{right:1.25rem}.right-6{right:1.5rem}.top-0{top:0}.top-3\.5{top:.875rem}.top-5{top:1.25rem}.z-30{z-index:30}.z-40{z-index:40}.z-50{z-index:50}.z-\[60\]{z-index:60}.z-\[70\]{z-index:70}.mx-auto{margin-left:auto;margin-right:auto}.my-3{margin-top:.75rem;margin-bottom:.75rem}.my-4{margin-top:1rem;margin-bottom:1rem}.-mt-0\.5{margin-top:-.125rem}.mb-1{margin-bottom:.25rem}.mb-1\.5{margin-bottom:.375rem}.mb-10{margin-bottom:2.5rem}.mb-2{margin-bottom:.5rem}.mb-3{margin-bottom:.75rem}.mb-4{margin-bottom:1rem}.mb-5{margin-bottom:1.25rem}.mb-6{margin-bottom:1.5rem}.ml-auto{margin-left:auto}.mr-1{margin-right:.25rem}.mr-1\.5{margin-right:.375rem}.mt-0\.5{margin-top:.125rem}.mt-1{margin-top:.25rem}.mt-1\.5{margin-top:.375rem}.mt-2{margin-top:.5rem}.mt-3{margin-top:.75rem}.mt-4{margin-top:1rem}.mt-5{margin-top:1.25rem}.mt-6{margin-top:1.5rem}.block{display:block}.flex{display:flex}.inline-flex{display:inline-flex}.table{display:table}.grid{display:grid}.hidden{display:none}.h-10{height:2.5rem}.h-11{height:2.75rem}.h-12{height:3rem}.h-14{height:3.5rem}.h-16{height:4rem}.h-2{height:.5rem}.h-2\.5{height:.625rem}.h-20{height:5rem}.h-36{height:9rem}.h-48{height:12rem}.h-5{height:1.25rem}.h-6{height:1.5rem}.h-7{height:1.75rem}.h-8{height:2rem}.h-9{height:2.25rem}.h-full{height:100%}.min-h-screen{min-height:100vh}.w-1\/2{width:50%}.w-10{width:2.5rem}.w-11{width:2.75rem}.w-12{width:3rem}.w-14{width:3.5rem}.w-16{width:4rem}.w-2{width:.5rem}.w-20{width:5rem}.w-4{width:1rem}.w-48{width:12rem}.w-5{width:1.25rem}.w-6{width:1.5rem}.w-7{width:1.75rem}.w-72{width:18rem}.w-8{width:2rem}.w-9{width:2.25rem}.w-\[90\%\]{width:90%}.w-auto{width:auto}.w-full{width:100%}.min-w-0{min-width:0}.max-w-2xl{max-width:42rem}.max-w-3xl{max-width:48rem}.max-w-4xl{max-width:56rem}.max-w-6xl{max-width:72rem}.max-w-lg{max-width:32rem}.max-w-md{max-width:28rem}.max-w-sm{max-width:24rem}.max-w-xl{max-width:36rem}.max-w-xs{max-width:20rem}.flex-1{flex:1 1 0%}.flex-shrink{flex-shrink:1}.shrink-0{flex-shrink:0}.-translate-x-1\/2{--tw-translate-x:-50%}.-translate-x-1\/2,.-translate-x-full{transform:translate(var(--tw-translate-x),var(--tw-translate-y)) rotate(var(--tw-rotate)) skewX(var(--tw-skew-x)) skewY(var(--tw-skew-y)) scaleX(var(--tw-scale-x)) scaleY(var(--tw-scale-y))}.-translate-x-full{--tw-translate-x:-100%}.-translate-y-8{--tw-translate-y:-2rem}.-translate-y-8,.translate-x-full{transform:translate(var(--tw-translate-x),var(--tw-translate-y)) rotate(var(--tw-rotate)) skewX(var(--tw-skew-x)) skewY(var(--tw-skew-y)) scaleX(var(--tw-scale-x)) scaleY(var(--tw-scale-y))}.translate-x-full{--tw-translate-x:100%}.translate-y-0{--tw-translate-y:0px}.transform,.translate-y-0{transform:translate(var(--tw-translate-x),var(--tw-translate-y)) rotate(var(--tw-rotate)) skewX(var(--tw-skew-x)) skewY(var(--tw-skew-y)) scaleX(var(--tw-scale-x)) scaleY(var(--tw-scale-y))}@keyframes bounce{0%,to{transform:translateY(-25%);animation-timing-function:cubic-bezier(.8,0,1,1)}50%{transform:none;animation-timing-function:cubic-bezier(0,0,.2,1)}}.animate-bounce{animation:bounce 1s infinite}@keyframes pulse{50%{opacity:.5}}.animate-pulse{animation:pulse 2s cubic-bezier(.4,0,.6,1) infinite}.cursor-not-allowed{cursor:not-allowed}.cursor-pointer{cursor:pointer}.select-none{-webkit-user-select:none;-moz-user-select:none;user-select:none}.select-all{-webkit-user-select:all;-moz-user-select:all;user-select:all}.grid-cols-1{grid-template-columns:repeat(1,minmax(0,1fr))}.grid-cols-2{grid-template-columns:repeat(2,minmax(0,1fr))}.grid-cols-3{grid-template-columns:repeat(3,minmax(0,1fr))}.flex-col{flex-direction:column}.flex-wrap{flex-wrap:wrap}.items-start{align-items:flex-start}.items-end{align-items:flex-end}.items-center{align-items:center}.items-stretch{align-items:stretch}.justify-center{justify-content:center}.justify-between{justify-content:space-between}.gap-1{gap:.25rem}.gap-1\.5{gap:.375rem}.gap-10{gap:2.5rem}.gap-2{gap:.5rem}.gap-2\.5{gap:.625rem}.gap-3{gap:.75rem}.gap-3\.5{gap:.875rem}.gap-4{gap:1rem}.gap-5{gap:1.25rem}.gap-6{gap:1.5rem}.gap-8{gap:2rem}.gap-x-4{-moz-column-gap:1rem;column-gap:1rem}.gap-y-1{row-gap:.25rem}.space-y-0\.5>:not([hidden])~:not([hidden]){--tw-space-y-reverse:0;margin-top:calc(.125rem*(1 - var(--tw-space-y-reverse)));margin-bottom:calc(.125rem*var(--tw-space-y-reverse))}.space-y-1>:not([hidden])~:not([hidden]){--tw-space-y-reverse:0;margin-top:calc(.25rem*(1 - var(--tw-space-y-reverse)));margin-bottom:calc(.25rem*var(--tw-space-y-reverse))}.space-y-1\.5>:not([hidden])~:not([hidden]){--tw-space-y-reverse:0;margin-top:calc(.375rem*(1 - var(--tw-space-y-reverse)));margin-bottom:calc(.375rem*var(--tw-space-y-reverse))}.space-y-2>:not([hidden])~:not([hidden]){--tw-space-y-reverse:0;margin-top:calc(.5rem*(1 - var(--tw-space-y-reverse)));margin-bottom:calc(.5rem*var(--tw-space-y-reverse))}.space-y-2\.5>:not([hidden])~:not([hidden]){--tw-space-y-reverse:0;margin-top:calc(.625rem*(1 - var(--tw-space-y-reverse)));margin-bottom:calc(.625rem*var(--tw-space-y-reverse))}.space-y-3>:not([hidden])~:not([hidden]){--tw-space-y-reverse:0;margin-top:calc(.75rem*(1 - var(--tw-space-y-reverse)));margin-bottom:calc(.75rem*var(--tw-space-y-reverse))}.space-y-3\.5>:not([hidden])~:not([hidden]){--tw-space-y-reverse:0;margin-top:calc(.875rem*(1 - var(--tw-space-y-reverse)));margin-bottom:calc(.875rem*var(--tw-space-y-reverse))}.space-y-4>:not([hidden])~:not([hidden]){--tw-space-y-reverse:0;margin-top:calc(1rem*(1 - var(--tw-space-y-reverse)));margin-bottom:calc(1rem*var(--tw-space-y-reverse))}.space-y-5>:not([hidden])~:not([hidden]){--tw-space-y-reverse:0;margin-top:calc(1.25rem*(1 - var(--tw-space-y-reverse)));margin-bottom:calc(1.25rem*var(--tw-space-y-reverse))}.space-y-6>:not([hidden])~:not([hidden]){--tw-space-y-reverse:0;margin-top:calc(1.5rem*(1 - var(--tw-space-y-reverse)));margin-bottom:calc(1.5rem*var(--tw-space-y-reverse))}.divide-y>:not([hidden])~:not([hidden]){--tw-divide-y-reverse:0;border-top-width:calc(1px*(1 - var(--tw-divide-y-reverse)));border-bottom-width:calc(1px*var(--tw-divide-y-reverse))}.divide-\[\#0d265c\]>:not([hidden])~:not([hidden]){--tw-divide-opacity:1;border-color:rgb(13 38 92/var(--tw-divide-opacity,1))}.divide-\[\#0e275c\]>:not([hidden])~:not([hidden]){--tw-divide-opacity:1;border-color:rgb(14 39 92/var(--tw-divide-opacity,1))}.overflow-hidden{overflow:hidden}.overflow-x-auto{overflow-x:auto}.overflow-x-hidden{overflow-x:hidden}.truncate{overflow:hidden;text-overflow:ellipsis}.truncate,.whitespace-nowrap{white-space:nowrap}.break-all{word-break:break-all}.rounded-2xl{border-radius:1rem}.rounded-3xl{border-radius:1.5rem}.rounded-full{border-radius:9999px}.rounded-lg{border-radius:.5rem}.rounded-md{border-radius:.375rem}.rounded-xl{border-radius:.75rem}.border{border-width:1px}.border-2{border-width:2px}.border-b{border-bottom-width:1px}.border-l{border-left-width:1px}.border-r{border-right-width:1px}.border-t{border-top-width:1px}.border-dashed{border-style:dashed}.border-\[\#007BFF\]\/30{border-color:rgba(0,123,255,.3)}.border-\[\#00D89A\]\/20{border-color:rgba(0,216,154,.2)}.border-\[\#00D89A\]\/30{border-color:rgba(0,216,154,.3)}.border-\[\#00D89A\]\/40{border-color:rgba(0,216,154,.4)}.border-\[\#00D89A\]\/50{border-color:rgba(0,216,154,.5)}.border-\[\#00D89A\]\/60{border-color:rgba(0,216,154,.6)}.border-\[\#00D9FF\]{--tw-border-opacity:1;border-color:rgb(0 217 255/var(--tw-border-opacity,1))}.border-\[\#00D9FF\]\/20{border-color:rgba(0,217,255,.2)}.border-\[\#00D9FF\]\/30{border-color:rgba(0,217,255,.3)}.border-\[\#00D9FF\]\/40{border-color:rgba(0,217,255,.4)}.border-\[\#00D9FF\]\/50{border-color:rgba(0,217,255,.5)}.border-\[\#0d265c\]{--tw-border-opacity:1;border-color:rgb(13 38 92/var(--tw-border-opacity,1))}.border-\[\#0d2a63\]{--tw-border-opacity:1;border-color:rgb(13 42 99/var(--tw-border-opacity,1))}.border-\[\#0e275c\]{--tw-border-opacity:1;border-color:rgb(14 39 92/var(--tw-border-opacity,1))}.border-\[\#0e306e\]{--tw-border-opacity:1;border-color:rgb(14 48 110/var(--tw-border-opacity,1))}.border-\[\#10326f\]{--tw-border-opacity:1;border-color:rgb(16 50 111/var(--tw-border-opacity,1))}.border-\[\#123982\]{--tw-border-opacity:1;border-color:rgb(18 57 130/var(--tw-border-opacity,1))}.border-\[\#143985\]{--tw-border-opacity:1;border-color:rgb(20 57 133/var(--tw-border-opacity,1))}.border-\[\#16418f\]{--tw-border-opacity:1;border-color:rgb(22 65 143/var(--tw-border-opacity,1))}.border-\[\#174396\]{--tw-border-opacity:1;border-color:rgb(23 67 150/var(--tw-border-opacity,1))}.border-amber-400{--tw-border-opacity:1;border-color:rgb(251 191 36/var(--tw-border-opacity,1))}.border-amber-500\/30{border-color:rgba(245,158,11,.3)}.border-amber-500\/40{border-color:rgba(245,158,11,.4)}.border-rose-500\/20{border-color:rgba(244,63,94,.2)}.border-rose-500\/30{border-color:rgba(244,63,94,.3)}.border-rose-500\/40{border-color:rgba(244,63,94,.4)}.border-slate-700{--tw-border-opacity:1;border-color:rgb(51 65 85/var(--tw-border-opacity,1))}.bg-\[\#007BFF\]{--tw-bg-opacity:1;background-color:rgb(0 123 255/var(--tw-bg-opacity,1))}.bg-\[\#007BFF\]\/20{background-color:rgba(0,123,255,.2)}.bg-\[\#00D89A\]{--tw-bg-opacity:1;background-color:rgb(0 216 154/var(--tw-bg-opacity,1))}.bg-\[\#00D89A\]\/10{background-color:rgba(0,216,154,.1)}.bg-\[\#00D89A\]\/15{background-color:rgba(0,216,154,.15)}.bg-\[\#00D89A\]\/20{background-color:rgba(0,216,154,.2)}.bg-\[\#00D89A\]\/5{background-color:rgba(0,216,154,.05)}.bg-\[\#00D9FF\]\/10{background-color:rgba(0,217,255,.1)}.bg-\[\#00D9FF\]\/15{background-color:rgba(0,217,255,.15)}.bg-\[\#00D9FF\]\/20{background-color:rgba(0,217,255,.2)}.bg-\[\#020817\]{--tw-bg-opacity:1;background-color:rgb(2 8 23/var(--tw-bg-opacity,1))}.bg-\[\#020817\]\/80{background-color:rgba(2,8,23,.8)}.bg-\[\#020817\]\/85{background-color:rgba(2,8,23,.85)}.bg-\[\#030d24\]\/95{background-color:rgba(3,13,36,.95)}.bg-\[\#03132e\]{--tw-bg-opacity:1;background-color:rgb(3 19 46/var(--tw-bg-opacity,1))}.bg-\[\#040e24\]{--tw-bg-opacity:1;background-color:rgb(4 14 36/var(--tw-bg-opacity,1))}.bg-\[\#04122d\]{--tw-bg-opacity:1;background-color:rgb(4 18 45/var(--tw-bg-opacity,1))}.bg-\[\#05112b\]\/95{background-color:rgba(5,17,43,.95)}.bg-\[\#051433\]{--tw-bg-opacity:1;background-color:rgb(5 20 51/var(--tw-bg-opacity,1))}.bg-\[\#051433\]\/95{background-color:rgba(5,20,51,.95)}.bg-\[\#061535\]{--tw-bg-opacity:1;background-color:rgb(6 21 53/var(--tw-bg-opacity,1))}.bg-\[\#061535\]\/80{background-color:rgba(6,21,53,.8)}.bg-\[\#061535\]\/85{background-color:rgba(6,21,53,.85)}.bg-\[\#061535\]\/90{background-color:rgba(6,21,53,.9)}.bg-\[\#071638\]{--tw-bg-opacity:1;background-color:rgb(7 22 56/var(--tw-bg-opacity,1))}.bg-\[\#081b42\]\/95{background-color:rgba(8,27,66,.95)}.bg-\[\#081e4b\]{--tw-bg-opacity:1;background-color:rgb(8 30 75/var(--tw-bg-opacity,1))}.bg-\[\#09204e\]{--tw-bg-opacity:1;background-color:rgb(9 32 78/var(--tw-bg-opacity,1))}.bg-\[\#092257\]{--tw-bg-opacity:1;background-color:rgb(9 34 87/var(--tw-bg-opacity,1))}.bg-\[\#0a1e4a\]{--tw-bg-opacity:1;background-color:rgb(10 30 74/var(--tw-bg-opacity,1))}.bg-\[\#0a2356\]{--tw-bg-opacity:1;background-color:rgb(10 35 86/var(--tw-bg-opacity,1))}.bg-\[\#0c2454\]{--tw-bg-opacity:1;background-color:rgb(12 36 84/var(--tw-bg-opacity,1))}.bg-\[\#35F58A\]\/15{background-color:rgba(53,245,138,.15)}.bg-\[\#35F58A\]\/20{background-color:rgba(53,245,138,.2)}.bg-\[\#8B5CF6\]\/20{background-color:rgba(139,92,246,.2)}.bg-amber-400{--tw-bg-opacity:1;background-color:rgb(251 191 36/var(--tw-bg-opacity,1))}.bg-amber-500\/15{background-color:rgba(245,158,11,.15)}.bg-amber-500\/20{background-color:rgba(245,158,11,.2)}.bg-amber-500\/5{background-color:rgba(245,158,11,.05)}.bg-black\/80{background-color:rgba(0,0,0,.8)}.bg-rose-500{--tw-bg-opacity:1;background-color:rgb(244 63 94/var(--tw-bg-opacity,1))}.bg-rose-500\/10{background-color:rgba(244,63,94,.1)}.bg-rose-500\/15{background-color:rgba(244,63,94,.15)}.bg-rose-500\/20{background-color:rgba(244,63,94,.2)}.bg-slate-700{--tw-bg-opacity:1;background-color:rgb(51 65 85/var(--tw-bg-opacity,1))}.bg-slate-800{--tw-bg-opacity:1;background-color:rgb(30 41 59/var(--tw-bg-opacity,1))}.bg-transparent{background-color:transparent}.bg-white{--tw-bg-opacity:1;background-color:rgb(255 255 255/var(--tw-bg-opacity,1))}.bg-gradient-to-br{background-image:linear-gradient(to bottom right,var(--tw-gradient-stops))}.from-\[\#06303a\]{--tw-gradient-from:#06303a var(--tw-gradient-from-position);--tw-gradient-to:rgba(6,48,58,0) var(--tw-gradient-to-position);--tw-gradient-stops:var(--tw-gradient-from),var(--tw-gradient-to)}.from-\[\#071a44\]{--tw-gradient-from:#071a44 var(--tw-gradient-from-position);--tw-gradient-to:rgba(7,26,68,0) var(--tw-gradient-to-position);--tw-gradient-stops:var(--tw-gradient-from),var(--tw-gradient-to)}.from-\[\#081d49\]{--tw-gradient-from:#081d49 var(--tw-gradient-from-position);--tw-gradient-to:rgba(8,29,73,0) var(--tw-gradient-to-position);--tw-gradient-stops:var(--tw-gradient-from),var(--tw-gradient-to)}.from-\[\#0b2559\]{--tw-gradient-from:#0b2559 var(--tw-gradient-from-position);--tw-gradient-to:rgba(11,37,89,0) var(--tw-gradient-to-position);--tw-gradient-stops:var(--tw-gradient-from),var(--tw-gradient-to)}.via-\[\#092257\]{--tw-gradient-to:rgba(9,34,87,0) var(--tw-gradient-to-position);--tw-gradient-stops:var(--tw-gradient-from),#092257 var(--tw-gradient-via-position),var(--tw-gradient-to)}.to-\[\#020817\]{--tw-gradient-to:#020817 var(--tw-gradient-to-position)}.to-\[\#04112c\]{--tw-gradient-to:#04112c var(--tw-gradient-to-position)}.to-\[\#04122d\]{--tw-gradient-to:#04122d var(--tw-gradient-to-position)}.to-\[\#05112b\]{--tw-gradient-to:#05112b var(--tw-gradient-to-position)}.to-\[\#081e4b\]{--tw-gradient-to:#081e4b var(--tw-gradient-to-position)}.object-contain{-o-object-fit:contain;object-fit:contain}.p-1{padding:.25rem}.p-1\.5{padding:.375rem}.p-2{padding:.5rem}.p-3{padding:.75rem}.p-3\.5{padding:.875rem}.p-4{padding:1rem}.p-5{padding:1.25rem}.p-6{padding:1.5rem}.p-8{padding:2rem}.px-1\.5{padding-left:.375rem;padding-right:.375rem}.px-2{padding-left:.5rem;padding-right:.5rem}.px-2\.5{padding-left:.625rem;padding-right:.625rem}.px-3{padding-left:.75rem;padding-right:.75rem}.px-3\.5{padding-left:.875rem;padding-right:.875rem}.px-4{padding-left:1rem;padding-right:1rem}.px-5{padding-left:1.25rem;padding-right:1.25rem}.px-7{padding-left:1.75rem;padding-right:1.75rem}.py-0\.5{padding-top:.125rem;padding-bottom:.125rem}.py-1{padding-top:.25rem;padding-bottom:.25rem}.py-1\.5{padding-top:.375rem;padding-bottom:.375rem}.py-10{padding-top:2.5rem;padding-bottom:2.5rem}.py-12{padding-top:3rem;padding-bottom:3rem}.py-2{padding-top:.5rem;padding-bottom:.5rem}.py-2\.5{padding-top:.625rem;padding-bottom:.625rem}.py-3{padding-top:.75rem;padding-bottom:.75rem}.py-3\.5{padding-top:.875rem;padding-bottom:.875rem}.py-8{padding-top:2rem;padding-bottom:2rem}.pb-3{padding-bottom:.75rem}.pb-4{padding-bottom:1rem}.pb-5{padding-bottom:1.25rem}.pl-11{padding-left:2.75rem}.pr-11{padding-right:2.75rem}.pr-2{padding-right:.5rem}.pr-4{padding-right:1rem}.pt-0\.5{padding-top:.125rem}.pt-1{padding-top:.25rem}.pt-2{padding-top:.5rem}.pt-2\.5{padding-top:.625rem}.pt-3{padding-top:.75rem}.pt-5{padding-top:1.25rem}.pt-6{padding-top:1.5rem}.text-left{text-align:left}.text-center{text-align:center}.text-right{text-align:right}.font-mono{font-family:ui-monospace,SFMono-Regular,Menlo,Monaco,Consolas,Liberation Mono,Courier New,monospace}.font-sans{font-family:ui-sans-serif,system-ui,sans-serif,Apple Color Emoji,Segoe UI Emoji,Segoe UI Symbol,Noto Color Emoji}.text-2xl{font-size:1.5rem;line-height:2rem}.text-3xl{font-size:1.875rem;line-height:2.25rem}.text-4xl{font-size:2.25rem;line-height:2.5rem}.text-\[10px\]{font-size:10px}.text-\[11px\]{font-size:11px}.text-\[6px\]{font-size:6px}.text-\[9px\]{font-size:9px}.text-base{font-size:1rem;line-height:1.5rem}.text-lg{font-size:1.125rem;line-height:1.75rem}.text-sm{font-size:.875rem;line-height:1.25rem}.text-xl{font-size:1.25rem;line-height:1.75rem}.text-xs{font-size:.75rem;line-height:1rem}.font-black{font-weight:900}.font-bold{font-weight:700}.font-medium{font-weight:500}.font-semibold{font-weight:600}.uppercase{text-transform:uppercase}.leading-relaxed{line-height:1.625}.leading-tight{line-height:1.25}.tracking-wide{letter-spacing:.025em}.tracking-wider{letter-spacing:.05em}.tracking-widest{letter-spacing:.1em}.text-\[\#007BFF\]{--tw-text-opacity:1;color:rgb(0 123 255/var(--tw-text-opacity,1))}.text-\[\#00D89A\]{--tw-text-opacity:1;color:rgb(0 216 154/var(--tw-text-opacity,1))}.text-\[\#00D89A\]\/20{color:rgba(0,216,154,.2)}.text-\[\#00D9FF\]{--tw-text-opacity:1;color:rgb(0 217 255/var(--tw-text-opacity,1))}.text-\[\#00D9FF\]\/20{color:rgba(0,217,255,.2)}.text-\[\#020817\]{--tw-text-opacity:1;color:rgb(2 8 23/var(--tw-text-opacity,1))}.text-\[\#0284C7\]{--tw-text-opacity:1;color:rgb(2 132 199/var(--tw-text-opacity,1))}.text-\[\#061535\]{--tw-text-opacity:1;color:rgb(6 21 53/var(--tw-text-opacity,1))}.text-\[\#25D366\]{--tw-text-opacity:1;color:rgb(37 211 102/var(--tw-text-opacity,1))}.text-\[\#35F58A\]{--tw-text-opacity:1;color:rgb(53 245 138/var(--tw-text-opacity,1))}.text-\[\#35F58A\]\/20{color:rgba(53,245,138,.2)}.text-\[\#38BDF8\]{--tw-text-opacity:1;color:rgb(56 189 248/var(--tw-text-opacity,1))}.text-\[\#7C3AED\]{--tw-text-opacity:1;color:rgb(124 58 237/var(--tw-text-opacity,1))}.text-\[\#8B5CF6\]{--tw-text-opacity:1;color:rgb(139 92 246/var(--tw-text-opacity,1))}.text-\[\#E2136E\]{--tw-text-opacity:1;color:rgb(226 19 110/var(--tw-text-opacity,1))}.text-\[\#EA580C\]{--tw-text-opacity:1;color:rgb(234 88 12/var(--tw-text-opacity,1))}.text-\[\#F5F8FC\]{--tw-text-opacity:1;color:rgb(245 248 252/var(--tw-text-opacity,1))}.text-\[\#FBBF24\],.text-amber-400{--tw-text-opacity:1;color:rgb(251 191 36/var(--tw-text-opacity,1))}.text-cyan-400{--tw-text-opacity:1;color:rgb(34 211 238/var(--tw-text-opacity,1))}.text-emerald-400{--tw-text-opacity:1;color:rgb(52 211 153/var(--tw-text-opacity,1))}.text-rose-300{--tw-text-opacity:1;color:rgb(253 164 175/var(--tw-text-opacity,1))}.text-rose-400{--tw-text-opacity:1;color:rgb(251 113 133/var(--tw-text-opacity,1))}.text-slate-200{--tw-text-opacity:1;color:rgb(226 232 240/var(--tw-text-opacity,1))}.text-slate-300{--tw-text-opacity:1;color:rgb(203 213 225/var(--tw-text-opacity,1))}.text-slate-400{--tw-text-opacity:1;color:rgb(148 163 184/var(--tw-text-opacity,1))}.text-slate-500{--tw-text-opacity:1;color:rgb(100 116 139/var(--tw-text-opacity,1))}.text-white{--tw-text-opacity:1;color:rgb(255 255 255/var(--tw-text-opacity,1))}.line-through{text-decoration-line:line-through}.opacity-0{opacity:0}.opacity-100{opacity:1}.opacity-50{opacity:.5}.opacity-75{opacity:.75}.shadow-2xl{--tw-shadow:0 25px 50px -12px rgba(0,0,0,.25);--tw-shadow-colored:0 25px 50px -12px var(--tw-shadow-color);box-shadow:var(--tw-ring-offset-shadow,0 0 #0000),var(--tw-ring-shadow,0 0 #0000),var(--tw-shadow)}.shadow-\[0_0_10px_rgba\(0\2c 123\2c 255\2c 0\.4\)\]{--tw-shadow:0 0 10px rgba(0,123,255,.4);--tw-shadow-colored:0 0 10px var(--tw-shadow-color);box-shadow:var(--tw-ring-offset-shadow,0 0 #0000),var(--tw-ring-shadow,0 0 #0000),var(--tw-shadow)}.shadow-\[0_0_10px_rgba\(0\2c 216\2c 154\2c 0\.4\)\]{--tw-shadow:0 0 10px rgba(0,216,154,.4);--tw-shadow-colored:0 0 10px var(--tw-shadow-color);box-shadow:var(--tw-ring-offset-shadow,0 0 #0000),var(--tw-ring-shadow,0 0 #0000),var(--tw-shadow)}.shadow-\[0_0_10px_rgba\(0\2c 217\2c 255\2c 0\.2\)\]{--tw-shadow:0 0 10px rgba(0,217,255,.2);--tw-shadow-colored:0 0 10px var(--tw-shadow-color);box-shadow:var(--tw-ring-offset-shadow,0 0 #0000),var(--tw-ring-shadow,0 0 #0000),var(--tw-shadow)}.shadow-\[0_0_10px_rgba\(0\2c 217\2c 255\2c 0\.4\)\]{--tw-shadow:0 0 10px rgba(0,217,255,.4);--tw-shadow-colored:0 0 10px var(--tw-shadow-color);box-shadow:var(--tw-ring-offset-shadow,0 0 #0000),var(--tw-ring-shadow,0 0 #0000),var(--tw-shadow)}.shadow-\[0_0_10px_rgba\(139\2c 92\2c 246\2c 0\.4\)\]{--tw-shadow:0 0 10px rgba(139,92,246,.4);--tw-shadow-colored:0 0 10px var(--tw-shadow-color);box-shadow:var(--tw-ring-offset-shadow,0 0 #0000),var(--tw-ring-shadow,0 0 #0000),var(--tw-shadow)}.shadow-\[0_0_15px_rgba\(0\2c 217\2c 255\2c 0\.1\)\]{--tw-shadow:0 0 15px rgba(0,217,255,.1);--tw-shadow-colored:0 0 15px var(--tw-shadow-color);box-shadow:var(--tw-ring-offset-shadow,0 0 #0000),var(--tw-ring-shadow,0 0 #0000),var(--tw-shadow)}.shadow-\[0_0_15px_rgba\(0\2c 217\2c 255\2c 0\.15\)\]{--tw-shadow:0 0 15px rgba(0,217,255,.15);--tw-shadow-colored:0 0 15px var(--tw-shadow-color);box-shadow:var(--tw-ring-offset-shadow,0 0 #0000),var(--tw-ring-shadow,0 0 #0000),var(--tw-shadow)}.shadow-\[0_0_18px_rgba\(0\2c 216\2c 154\2c 0\.15\)\]{--tw-shadow:0 0 18px rgba(0,216,154,.15);--tw-shadow-colored:0 0 18px var(--tw-shadow-color);box-shadow:var(--tw-ring-offset-shadow,0 0 #0000),var(--tw-ring-shadow,0 0 #0000),var(--tw-shadow)}.shadow-\[0_0_20px_rgba\(0\2c 217\2c 255\2c 0\.12\)\]{--tw-shadow:0 0 20px rgba(0,217,255,.12);--tw-shadow-colored:0 0 20px var(--tw-shadow-color);box-shadow:var(--tw-ring-offset-shadow,0 0 #0000),var(--tw-ring-shadow,0 0 #0000),var(--tw-shadow)}.shadow-\[0_0_20px_rgba\(0\2c 217\2c 255\2c 0\.4\)\]{--tw-shadow:0 0 20px rgba(0,217,255,.4);--tw-shadow-colored:0 0 20px var(--tw-shadow-color);box-shadow:var(--tw-ring-offset-shadow,0 0 #0000),var(--tw-ring-shadow,0 0 #0000),var(--tw-shadow)}.shadow-\[0_0_25px_rgba\(0\2c 217\2c 255\2c 0\.3\)\]{--tw-shadow:0 0 25px rgba(0,217,255,.3);--tw-shadow-colored:0 0 25px var(--tw-shadow-color);box-shadow:var(--tw-ring-offset-shadow,0 0 #0000),var(--tw-ring-shadow,0 0 #0000),var(--tw-shadow)}.shadow-\[0_0_8px_rgba\(0\2c 216\2c 154\2c 0\.8\)\]{--tw-shadow:0 0 8px rgba(0,216,154,.8);--tw-shadow-colored:0 0 8px var(--tw-shadow-color)}.shadow-\[0_0_8px_rgba\(0\2c 216\2c 154\2c 0\.8\)\],.shadow-lg{box-shadow:var(--tw-ring-offset-shadow,0 0 #0000),var(--tw-ring-shadow,0 0 #0000),var(--tw-shadow)}.shadow-lg{--tw-shadow:0 10px 15px -3px rgba(0,0,0,.1),0 4px 6px -4px rgba(0,0,0,.1);--tw-shadow-colored:0 10px 15px -3px var(--tw-shadow-color),0 4px 6px -4px var(--tw-shadow-color)}.shadow-md{--tw-shadow:0 4px 6px -1px rgba(0,0,0,.1),0 2px 4px -2px rgba(0,0,0,.1);--tw-shadow-colored:0 4px 6px -1px var(--tw-shadow-color),0 2px 4px -2px var(--tw-shadow-color)}.shadow-md,.shadow-sm{box-shadow:var(--tw-ring-offset-shadow,0 0 #0000),var(--tw-ring-shadow,0 0 #0000),var(--tw-shadow)}.shadow-sm{--tw-shadow:0 1px 2px 0 rgba(0,0,0,.05);--tw-shadow-colored:0 1px 2px 0 var(--tw-shadow-color)}.shadow-xl{--tw-shadow:0 20px 25px -5px rgba(0,0,0,.1),0 8px 10px -6px rgba(0,0,0,.1);--tw-shadow-colored:0 20px 25px -5px var(--tw-shadow-color),0 8px 10px -6px var(--tw-shadow-color);box-shadow:var(--tw-ring-offset-shadow,0 0 #0000),var(--tw-ring-shadow,0 0 #0000),var(--tw-shadow)}.shadow-black\/40{--tw-shadow-color:rgba(0,0,0,.4);--tw-shadow:var(--tw-shadow-colored)}.shadow-cyan-500\/10{--tw-shadow-color:rgba(6,182,212,.1);--tw-shadow:var(--tw-shadow-colored)}.shadow-cyan-500\/20{--tw-shadow-color:rgba(6,182,212,.2);--tw-shadow:var(--tw-shadow-colored)}.shadow-cyan-900\/10{--tw-shadow-color:rgba(22,78,99,.1);--tw-shadow:var(--tw-shadow-colored)}.blur{--tw-blur:blur(8px);filter:var(--tw-blur) var(--tw-brightness) var(--tw-contrast) var(--tw-grayscale) var(--tw-hue-rotate) var(--tw-invert) var(--tw-saturate) var(--tw-sepia) var(--tw-drop-shadow)}.backdrop-blur-2xl{--tw-backdrop-blur:blur(40px)}.backdrop-blur-2xl,.backdrop-blur-lg{-webkit-backdrop-filter:var(--tw-backdrop-blur) var(--tw-backdrop-brightness) var(--tw-backdrop-contrast) var(--tw-backdrop-grayscale) var(--tw-backdrop-hue-rotate) var(--tw-backdrop-invert) var(--tw-backdrop-opacity) var(--tw-backdrop-saturate) var(--tw-backdrop-sepia);backdrop-filter:var(--tw-backdrop-blur) var(--tw-backdrop-brightness) var(--tw-backdrop-contrast) var(--tw-backdrop-grayscale) var(--tw-backdrop-hue-rotate) var(--tw-backdrop-invert) var(--tw-backdrop-opacity) var(--tw-backdrop-saturate) var(--tw-backdrop-sepia)}.backdrop-blur-lg{--tw-backdrop-blur:blur(16px)}.backdrop-blur-md{--tw-backdrop-blur:blur(12px)}.backdrop-blur-md,.backdrop-blur-sm{-webkit-backdrop-filter:var(--tw-backdrop-blur) var(--tw-backdrop-brightness) var(--tw-backdrop-contrast) var(--tw-backdrop-grayscale) var(--tw-backdrop-hue-rotate) var(--tw-backdrop-invert) var(--tw-backdrop-opacity) var(--tw-backdrop-saturate) var(--tw-backdrop-sepia);backdrop-filter:var(--tw-backdrop-blur) var(--tw-backdrop-brightness) var(--tw-backdrop-contrast) var(--tw-backdrop-grayscale) var(--tw-backdrop-hue-rotate) var(--tw-backdrop-invert) var(--tw-backdrop-opacity) var(--tw-backdrop-saturate) var(--tw-backdrop-sepia)}.backdrop-blur-sm{--tw-backdrop-blur:blur(4px)}.backdrop-blur-xl{--tw-backdrop-blur:blur(24px)}.backdrop-blur-xl,.backdrop-filter{-webkit-backdrop-filter:var(--tw-backdrop-blur) var(--tw-backdrop-brightness) var(--tw-backdrop-contrast) var(--tw-backdrop-grayscale) var(--tw-backdrop-hue-rotate) var(--tw-backdrop-invert) var(--tw-backdrop-opacity) var(--tw-backdrop-saturate) var(--tw-backdrop-sepia);backdrop-filter:var(--tw-backdrop-blur) var(--tw-backdrop-brightness) var(--tw-backdrop-contrast) var(--tw-backdrop-grayscale) var(--tw-backdrop-hue-rotate) var(--tw-backdrop-invert) var(--tw-backdrop-opacity) var(--tw-backdrop-saturate) var(--tw-backdrop-sepia)}.transition{transition-property:color,background-color,border-color,text-decoration-color,fill,stroke,opacity,box-shadow,transform,filter,-webkit-backdrop-filter;transition-property:color,background-color,border-color,text-decoration-color,fill,stroke,opacity,box-shadow,transform,filter,backdrop-filter;transition-property:color,background-color,border-color,text-decoration-color,fill,stroke,opacity,box-shadow,transform,filter,backdrop-filter,-webkit-backdrop-filter;transition-timing-function:cubic-bezier(.4,0,.2,1);transition-duration:.15s}.transition-all{transition-property:all;transition-timing-function:cubic-bezier(.4,0,.2,1);transition-duration:.15s}.transition-opacity{transition-property:opacity;transition-timing-function:cubic-bezier(.4,0,.2,1);transition-duration:.15s}.transition-transform{transition-property:transform;transition-timing-function:cubic-bezier(.4,0,.2,1);transition-duration:.15s}.duration-200{transition-duration:.2s}.duration-300{transition-duration:.3s}.ease-in-out{transition-timing-function:cubic-bezier(.4,0,.2,1)}.selection\:bg-\[\#00D9FF\] ::-moz-selection{--tw-bg-opacity:1;background-color:rgb(0 217 255/var(--tw-bg-opacity,1))}.selection\:bg-\[\#00D9FF\] ::selection{--tw-bg-opacity:1;background-color:rgb(0 217 255/var(--tw-bg-opacity,1))}.selection\:text-\[\#020817\] ::-moz-selection{--tw-text-opacity:1;color:rgb(2 8 23/var(--tw-text-opacity,1))}.selection\:text-\[\#020817\] ::selection{--tw-text-opacity:1;color:rgb(2 8 23/var(--tw-text-opacity,1))}.selection\:text-\[\#061535\] ::-moz-selection{--tw-text-opacity:1;color:rgb(6 21 53/var(--tw-text-opacity,1))}.selection\:text-\[\#061535\] ::selection{--tw-text-opacity:1;color:rgb(6 21 53/var(--tw-text-opacity,1))}.selection\:bg-\[\#00D9FF\]::-moz-selection{--tw-bg-opacity:1;background-color:rgb(0 217 255/var(--tw-bg-opacity,1))}.selection\:bg-\[\#00D9FF\]::selection{--tw-bg-opacity:1;background-color:rgb(0 217 255/var(--tw-bg-opacity,1))}.selection\:text-\[\#020817\]::-moz-selection{--tw-text-opacity:1;color:rgb(2 8 23/var(--tw-text-opacity,1))}.selection\:text-\[\#020817\]::selection{--tw-text-opacity:1;color:rgb(2 8 23/var(--tw-text-opacity,1))}.selection\:text-\[\#061535\]::-moz-selection{--tw-text-opacity:1;color:rgb(6 21 53/var(--tw-text-opacity,1))}.selection\:text-\[\#061535\]::selection{--tw-text-opacity:1;color:rgb(6 21 53/var(--tw-text-opacity,1))}.file\:mr-4::file-selector-button{margin-right:1rem}.file\:rounded-xl::file-selector-button{border-radius:.75rem}.file\:border-0::file-selector-button{border-width:0}.file\:bg-\[\#007BFF\]::file-selector-button{--tw-bg-opacity:1;background-color:rgb(0 123 255/var(--tw-bg-opacity,1))}.file\:px-4::file-selector-button{padding-left:1rem;padding-right:1rem}.file\:py-2::file-selector-button{padding-top:.5rem;padding-bottom:.5rem}.file\:py-2\.5::file-selector-button{padding-top:.625rem;padding-bottom:.625rem}.file\:text-xs::file-selector-button{font-size:.75rem;line-height:1rem}.file\:font-semibold::file-selector-button{font-weight:600}.file\:text-white::file-selector-button{--tw-text-opacity:1;color:rgb(255 255 255/var(--tw-text-opacity,1))}.after\:absolute:after{content:var(--tw-content);position:absolute}.after\:left-\[2px\]:after{content:var(--tw-content);left:2px}.after\:top-\[2px\]:after{content:var(--tw-content);top:2px}.after\:h-5:after{content:var(--tw-content);height:1.25rem}.after\:w-5:after{content:var(--tw-content);width:1.25rem}.after\:rounded-full:after{content:var(--tw-content);border-radius:9999px}.after\:border:after{content:var(--tw-content);border-width:1px}.after\:border-gray-300:after{content:var(--tw-content);--tw-border-opacity:1;border-color:rgb(209 213 219/var(--tw-border-opacity,1))}.after\:bg-white:after{content:var(--tw-content);--tw-bg-opacity:1;background-color:rgb(255 255 255/var(--tw-bg-opacity,1))}.after\:transition-all:after{content:var(--tw-content);transition-property:all;transition-timing-function:cubic-bezier(.4,0,.2,1);transition-duration:.15s}.after\:content-\[\'\'\]:after{--tw-content:"";content:var(--tw-content)}.hover\:border-\[\#00D9FF\]:hover{--tw-border-opacity:1;border-color:rgb(0 217 255/var(--tw-border-opacity,1))}.hover\:border-\[\#00D9FF\]\/40:hover{border-color:rgba(0,217,255,.4)}.hover\:border-\[\#0284C7\]:hover{--tw-border-opacity:1;border-color:rgb(2 132 199/var(--tw-border-opacity,1))}.hover\:border-\[\#7C3AED\]:hover{--tw-border-opacity:1;border-color:rgb(124 58 237/var(--tw-border-opacity,1))}.hover\:border-\[\#E2136E\]:hover{--tw-border-opacity:1;border-color:rgb(226 19 110/var(--tw-border-opacity,1))}.hover\:border-\[\#EA580C\]:hover{--tw-border-opacity:1;border-color:rgb(234 88 12/var(--tw-border-opacity,1))}.hover\:border-amber-400:hover{--tw-border-opacity:1;border-color:rgb(251 191 36/var(--tw-border-opacity,1))}.hover\:bg-\[\#007BFF\]:hover{--tw-bg-opacity:1;background-color:rgb(0 123 255/var(--tw-bg-opacity,1))}.hover\:bg-\[\#00D9FF\]\/20:hover{background-color:rgba(0,217,255,.2)}.hover\:bg-\[\#051433\]\/60:hover{background-color:rgba(5,20,51,.6)}.hover\:bg-\[\#092257\]:hover{--tw-bg-opacity:1;background-color:rgb(9 34 87/var(--tw-bg-opacity,1))}.hover\:bg-\[\#0c245c\]:hover{--tw-bg-opacity:1;background-color:rgb(12 36 92/var(--tw-bg-opacity,1))}.hover\:bg-\[\#0c2e75\]:hover{--tw-bg-opacity:1;background-color:rgb(12 46 117/var(--tw-bg-opacity,1))}.hover\:bg-\[\#0f2d6e\]:hover{--tw-bg-opacity:1;background-color:rgb(15 45 110/var(--tw-bg-opacity,1))}.hover\:bg-rose-500\/30:hover{background-color:rgba(244,63,94,.3)}.hover\:bg-slate-800:hover{--tw-bg-opacity:1;background-color:rgb(30 41 59/var(--tw-bg-opacity,1))}.hover\:text-\[\#00D89A\]:hover{--tw-text-opacity:1;color:rgb(0 216 154/var(--tw-text-opacity,1))}.hover\:text-\[\#00D9FF\]:hover{--tw-text-opacity:1;color:rgb(0 217 255/var(--tw-text-opacity,1))}.hover\:text-rose-300:hover{--tw-text-opacity:1;color:rgb(253 164 175/var(--tw-text-opacity,1))}.hover\:text-rose-400:hover{--tw-text-opacity:1;color:rgb(251 113 133/var(--tw-text-opacity,1))}.hover\:text-white:hover{--tw-text-opacity:1;color:rgb(255 255 255/var(--tw-text-opacity,1))}.hover\:underline:hover{text-decoration-line:underline}.hover\:file\:bg-\[\#0056b3\]::file-selector-button:hover{--tw-bg-opacity:1;background-color:rgb(0 86 179/var(--tw-bg-opacity,1))}.focus\:border-\[\#00D89A\]:focus{--tw-border-opacity:1;border-color:rgb(0 216 154/var(--tw-border-opacity,1))}.focus\:border-\[\#00D9FF\]:focus{--tw-border-opacity:1;border-color:rgb(0 217 255/var(--tw-border-opacity,1))}.focus\:border-amber-400:focus{--tw-border-opacity:1;border-color:rgb(251 191 36/var(--tw-border-opacity,1))}.focus\:outline-none:focus{outline:2px solid transparent;outline-offset:2px}.active\:scale-95:active{--tw-scale-x:.95;--tw-scale-y:.95;transform:translate(var(--tw-translate-x),var(--tw-translate-y)) rotate(var(--tw-rotate)) skewX(var(--tw-skew-x)) skewY(var(--tw-skew-y)) scaleX(var(--tw-scale-x)) scaleY(var(--tw-scale-y))}.group:hover .group-hover\:text-\[\#00D89A\]{--tw-text-opacity:1;color:rgb(0 216 154/var(--tw-text-opacity,1))}.group:hover .group-hover\:text-\[\#00D9FF\]{--tw-text-opacity:1;color:rgb(0 217 255/var(--tw-text-opacity,1))}.group:hover .group-hover\:text-amber-400{--tw-text-opacity:1;color:rgb(251 191 36/var(--tw-text-opacity,1))}.peer:checked~.peer-checked\:border-\[\#00D9FF\]{--tw-border-opacity:1;border-color:rgb(0 217 255/var(--tw-border-opacity,1))}.peer:checked~.peer-checked\:bg-\[\#00D89A\]{--tw-bg-opacity:1;background-color:rgb(0 216 154/var(--tw-bg-opacity,1))}.peer:checked~.peer-checked\:bg-\[\#00D9FF\]\/10{background-color:rgba(0,217,255,.1)}.peer:checked~.peer-checked\:text-\[\#00D9FF\]{--tw-text-opacity:1;color:rgb(0 217 255/var(--tw-text-opacity,1))}.peer:checked~.peer-checked\:after\:translate-x-full:after{content:var(--tw-content);--tw-translate-x:100%;transform:translate(var(--tw-translate-x),var(--tw-translate-y)) rotate(var(--tw-rotate)) skewX(var(--tw-skew-x)) skewY(var(--tw-skew-y)) scaleX(var(--tw-scale-x)) scaleY(var(--tw-scale-y))}.peer:checked~.peer-checked\:after\:border-white:after{content:var(--tw-content);--tw-border-opacity:1;border-color:rgb(255 255 255/var(--tw-border-opacity,1))}.peer:focus~.peer-focus\:outline-none{outline:2px solid transparent;outline-offset:2px}@media (min-width:640px){.sm\:col-span-2{grid-column:span 2/span 2}.sm\:inline{display:inline}.sm\:w-auto{width:auto}.sm\:grid-cols-2{grid-template-columns:repeat(2,minmax(0,1fr))}.sm\:grid-cols-4{grid-template-columns:repeat(4,minmax(0,1fr))}.sm\:grid-cols-5{grid-template-columns:repeat(5,minmax(0,1fr))}.sm\:flex-row{flex-direction:row}.sm\:items-center{align-items:center}.sm\:justify-end{justify-content:flex-end}.sm\:border-t-0{border-top-width:0}.sm\:p-8{padding:2rem}.sm\:px-4{padding-left:1rem;padding-right:1rem}.sm\:pt-0{padding-top:0}.sm\:text-right{text-align:right}.sm\:text-sm{font-size:.875rem;line-height:1.25rem}}@media (min-width:768px){.md\:col-span-3{grid-column:span 3/span 3}.md\:mx-0{margin-left:0;margin-right:0}.md\:ml-72{margin-left:18rem}.md\:flex{display:flex}.md\:hidden{display:none}.md\:w-1\/2{width:50%}.md\:w-auto{width:auto}.md\:flex-none{flex:none}.md\:translate-x-0{--tw-translate-x:0px;transform:translate(var(--tw-translate-x),var(--tw-translate-y)) rotate(var(--tw-rotate)) skewX(var(--tw-skew-x)) skewY(var(--tw-skew-y)) scaleX(var(--tw-scale-x)) scaleY(var(--tw-scale-y))}.md\:grid-cols-2{grid-template-columns:repeat(2,minmax(0,1fr))}.md\:grid-cols-3{grid-template-columns:repeat(3,minmax(0,1fr))}.md\:grid-cols-4{grid-template-columns:repeat(4,minmax(0,1fr))}.md\:flex-row{flex-direction:row}.md\:items-center{align-items:center}.md\:justify-start{justify-content:flex-start}.md\:gap-4{gap:1rem}.md\:p-10{padding:2.5rem}.md\:p-12{padding:3rem}.md\:p-5{padding:1.25rem}.md\:p-7{padding:1.75rem}.md\:p-8{padding:2rem}.md\:px-10{padding-left:2.5rem;padding-right:2.5rem}.md\:px-8{padding-left:2rem;padding-right:2rem}.md\:py-16{padding-top:4rem;padding-bottom:4rem}.md\:text-left{text-align:left}.md\:text-2xl{font-size:1.5rem;line-height:2rem}.md\:text-3xl{font-size:1.875rem;line-height:2.25rem}.md\:text-5xl{font-size:3rem;line-height:1}.md\:text-\[11px\]{font-size:11px}.md\:text-base{font-size:1rem;line-height:1.5rem}.md\:text-lg{font-size:1.125rem;line-height:1.75rem}.md\:text-sm{font-size:.875rem;line-height:1.25rem}}</style>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <style>
        body { touch-action: manipulation; }
        .snz-gradient { background: linear-gradient(135deg, #007BFF 0%, #00D9FF 50%, #00D89A 100%); }
    </style>
</head>
<body class="bg-[#061535] text-[#F5F8FC] min-h-screen flex items-center justify-center p-4 selection:bg-[#00D9FF] selection:text-[#061535] font-sans">
    <div class="bg-[#081e4b] border border-[#143985] p-8 rounded-3xl w-full max-w-md shadow-2xl relative">
        <div class="text-center mb-6">
            <img src="<?= htmlspecialchars($SITE_LOGO) ?>" onerror="this.onerror=null;this.src='data:image/svg+xml,%3Csvg xmlns=%22http://www.w3.org/2000/svg%22 viewBox=%220 0 64 64%22%3E%3Cdefs%3E%3ClinearGradient id=%22g%22 x1=%220%22 y1=%220%22 x2=%221%22 y2=%221%22%3E%3Cstop offset=%220%22 stop-color=%22%23007BFF%22/%3E%3Cstop offset=%22.5%22 stop-color=%22%2300D9FF%22/%3E%3Cstop offset=%221%22 stop-color=%22%2300D89A%22/%3E%3C/linearGradient%3E%3C/defs%3E%3Crect width=%2264%22 height=%2264%22 rx=%2216%22 fill=%22url(%23g)%22/%3E%3Ctext x=%2232%22 y=%2244%22 font-family=%22Arial%22 font-weight=%22900%22 font-size=%2234%22 text-anchor=%22middle%22 fill=%22%23061535%22%3ES%3C/text%3E%3C/svg%3E'" alt="<?= htmlspecialchars($SITE_NAME) ?> Logo" decoding="async" class="w-14 h-14 object-contain mx-auto mb-3">
            <h1 class="text-xl font-black text-white tracking-wide"><?= snzBrandHtml($SITE_NAME) ?> ADMIN</h1>
            <p class="text-xs text-slate-300 mt-0.5">Enter master password to access console</p>
        </div>
        <?php if (!empty($login_error)): ?>
            <div class="bg-[#00D9FF]/10 border border-[#00D9FF]/30 text-[#00D9FF] p-3 rounded-2xl text-xs mb-4 text-center font-medium flex items-center justify-center gap-2">
                <i class="fa-solid fa-circle-exclamation"></i> <?= htmlspecialchars($login_error) ?>
            </div>
        <?php endif; ?>
        <form method="POST" action="admin.php" class="space-y-4">
            <input type="hidden" name="admin_login" value="1">
            <div>
                <label class="text-xs text-slate-200 font-semibold block mb-1.5">Master Password</label>
                <div class="relative">
                    <i class="fa-solid fa-lock absolute left-4 top-3.5 text-slate-400 text-sm"></i>
                    <input type="password" name="password" required autocomplete="current-password" autocapitalize="off" autocorrect="off" spellcheck="false" placeholder="••••••••" class="w-full bg-[#051433] border border-[#0d2a63] rounded-2xl pl-11 pr-4 py-3 text-white text-sm focus:outline-none focus:border-[#00D9FF] transition">
                </div>
            </div>
            <button type="submit" class="w-full snz-gradient text-[#061535] font-black py-3.5 rounded-2xl text-xs tracking-wider uppercase transition shadow-lg shadow-cyan-500/20 flex items-center justify-center gap-2">
                <i class="fa-solid fa-right-to-bracket"></i> Sign In to Admin Console
            </button>
        </form>
    </div>
</body>
</html>
<?php
exit;
endif;

$msg = "";
$error = "";

// সব POST অ্যাকশন ও key ডিলিটের জন্য CSRF যাচাই
$csrfOk = hash_equals($CSRF, (string) ($_POST['csrf'] ?? $_GET['csrf'] ?? ''));
if (($_SERVER['REQUEST_METHOD'] === 'POST' || isset($_GET['delete_key'])) && !$csrfOk) {
    $error = "Session expired. Please try again.";
    unset($_POST['action_create_key'], $_POST['action_add_balance'], $_POST['action_update_payments'], $_POST['action_update_site'], $_POST['action_update_app'], $_POST['action_add_sms_rule'], $_POST['action_toggle_sms_rule'], $_POST['action_delete_sms_rule'], $_POST['action_test_sms_rule'], $_POST['action_reset_device'], $_POST['action_remove_device'], $_POST['action_save_plan'], $_POST['action_delete_plan'], $_POST['action_set_receiver'], $_POST['action_pin_plan'], $_POST['action_move_plan'], $_POST['action_save_autopay'], $_POST['action_deposit_decide'], $_POST['action_ban_key'], $_GET['delete_key']);
}

// =========================================================================
// 2. Create Custom License Key
// =========================================================================
if (isset($_POST['action_create_key'])) {
    $targetUserId = intval($_POST['user_id']);
    $planName = trim($_POST['plan_name']) ?: "Custom Admin Plan";
    $durationType = $_POST['duration_type'];
    $ckPlanId = intval($_POST['ck_plan_id'] ?? 0);
    $ckDevices = max(1, intval($_POST['ck_max_devices'] ?? 1));
    $ckBrands = max(0, intval($_POST['ck_max_brand_keys'] ?? 1));
    if ($ckPlanId > 0 && ($ckPlan = snzGetPlan($pdo, $ckPlanId))) {
        if (trim($_POST['plan_name']) === '') $planName = $ckPlan['name'];
    } else {
        $ckPlanId = null;
    }

    $uStmt = $pdo->prepare("SELECT * FROM users WHERE id = ?");
    $uStmt->execute([$targetUserId]);
    $targetUser = $uStmt->fetch();

    if (!$targetUser) {
        $error = "Selected user not found!";
    } else {
        if ($durationType === 'hours') {
            $hours = floatval($_POST['duration_hours']);
            if ($hours <= 0) $hours = 24;
            $expiresAt = date("Y-m-d H:i:s", time() + (int) round($hours * 3600)); // 1.5 ঘণ্টাও ঠিকমতো কাজ করবে
        } else {
            $customTs = strtotime(trim($_POST['custom_datetime'] ?? ''));
            $expiresAt = $customTs ? date("Y-m-d H:i:s", $customTs) : null;
        }
    }

    if (!$error && empty($expiresAt)) {
        $error = "Please select a valid expiration date & time!";
    } elseif (!$error) {
        $expireTimestamp = strtotime($expiresAt) * 1000;
        $licenseKey = generateLicenseKey();

        $stmt = $pdo->prepare("INSERT INTO user_keys (user_id, license_key, plan_name, expires_at, status, plan_id, max_devices, max_brand_keys) VALUES (?, ?, ?, ?, 'active', ?, ?, ?)");
        $stmt->execute([$targetUserId, $licenseKey, $planName, $expiresAt, $ckPlanId, $ckDevices, $ckBrands]);

        $fbUrl = rtrim($FIREBASE_URL, '/') . "/license_keys/" . $licenseKey . ".json";
        pushToFirebase($fbUrl, "PUT", [
            "key" => $licenseKey,
            "user_email" => $targetUser['email'],
            "plan" => $planName,
            "created_at" => time() * 1000,
            "expires_at" => $expireTimestamp,
            "status" => "active"
        ]);

        $msg = "Key Generated: $licenseKey (Expires: " . date("d M, h:i A", strtotime($expiresAt)) . ")";
    }
}

// =========================================================================
// 3. Add User Balance
// =========================================================================
if (isset($_POST['action_add_balance'])) {
    $targetUserId = intval($_POST['balance_user_id']);
    $amount = floatval($_POST['balance_amount']);

    if ($amount <= 0) {
        $error = "Enter a valid positive amount!";
    } else {
        $uCheck = $pdo->prepare("SELECT * FROM users WHERE id = ?");
        $uCheck->execute([$targetUserId]);
        $targetUser = $uCheck->fetch();

        if (!$targetUser) {
            $error = "User not found!";
        } else {
            $newBal = floatval($targetUser['balance']) + $amount;
            $pdo->prepare("UPDATE users SET balance = ? WHERE id = ?")->execute([$newBal, $targetUserId]);
            $msg = "Added ৳" . number_format($amount, 2) . " BDT to " . htmlspecialchars($targetUser['username']) . "! New Balance: ৳" . number_format($newBal, 2);
        }
    }
}

// =========================================================================
// 4. Update Payment Numbers
// =========================================================================
if (isset($_POST['action_update_payments'])) {
    $PAYMENT_INFO = [
        "bkash" => trim($_POST['bkash_number']),
        "nagad" => trim($_POST['nagad_number']),
        "binance" => trim($_POST['binance_id'])
    ];

    if (@file_put_contents($settingsFile, json_encode($PAYMENT_INFO, JSON_PRETTY_PRINT)) !== false) {
        $msg = "Payment account details updated successfully!";
    } else {
        $error = "Could not save settings file (check folder permission).";
    }
}

// =========================================================================
// 4b. Site Settings (নাম, ট্যাগলাইন, লোগো, সাপোর্ট লিংক, নোটিশ)
// =========================================================================
$activeSection = 'sec-overview';
if (isset($_POST['action_update_site'])) {
    $activeSection = 'sec-site';
    $newSet = $SITE_SETTINGS;
    $newSet['site_name']    = mb_substr(trim($_POST['site_name'] ?? ''), 0, 40);
    $newSet['site_tagline'] = mb_substr(trim($_POST['site_tagline'] ?? ''), 0, 80);
    $newSet['support_link'] = trim($_POST['support_link'] ?? '');
    $logoUrl = trim($_POST['site_logo_url'] ?? '');

    if ($newSet['site_name'] === '') {
        $error = "Site name cannot be empty!";
    } elseif ($newSet['support_link'] !== '' && !preg_match('#^(https?://|tg://|mailto:)#i', $newSet['support_link'])) {
        $error = "Support link must start with https:// (e.g. https://t.me/yourname)";
    } elseif ($logoUrl !== '' && !preg_match('#^(https?://|uploads/)#i', $logoUrl)) {
        $error = "Logo URL must start with https://";
    } else {
        $newSet['site_logo'] = $logoUrl;

        // লোগো আপলোড (দিলে URL এর বদলে এটাই ব্যবহার হবে)
        if (isset($_FILES['site_logo_file']) && $_FILES['site_logo_file']['error'] === UPLOAD_ERR_OK) {
            $ext = strtolower(pathinfo($_FILES['site_logo_file']['name'], PATHINFO_EXTENSION));
            if (!in_array($ext, ['png', 'jpg', 'jpeg', 'webp', 'gif'], true) || @getimagesize($_FILES['site_logo_file']['tmp_name']) === false) {
                $error = "Logo must be a PNG, JPG, WEBP or GIF image!";
            } elseif ($_FILES['site_logo_file']['size'] > 2 * 1024 * 1024) {
                $error = "Logo image must be smaller than 2 MB!";
            } else {
                $logoDir = __DIR__ . '/uploads/logo/';
                if (!is_dir($logoDir)) @mkdir($logoDir, 0755, true);
                $logoName = 'logo_' . time() . '.' . $ext;
                if (@move_uploaded_file($_FILES['site_logo_file']['tmp_name'], $logoDir . $logoName)) {
                    $newSet['site_logo'] = 'uploads/logo/' . $logoName;
                } else {
                    $error = "Could not save logo (check uploads folder permission).";
                }
            }
        }

        if (!$error) {
            if (@file_put_contents($siteSettingsFile, json_encode($newSet, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) === false) {
                $error = "Could not save settings file (check folder permission).";
            } else {
                // নোটিশ (ইউজার ড্যাশবোর্ডের উপরের স্ক্রলিং লেখা)
                $notice = trim($_POST['notice_text'] ?? '');
                if ($notice !== '') @file_put_contents(__DIR__ . '/notice.txt', $notice);

                $SITE_SETTINGS = $newSet;
                $SITE_NAME    = $newSet['site_name'];
                if ($newSet['site_tagline'] !== '') $SITE_TAGLINE = $newSet['site_tagline'];
                if (!empty($newSet['site_logo']))   $SITE_LOGO    = $newSet['site_logo'];
                $SUPPORT_LINK = $newSet['support_link'] ?: $SUPPORT_LINK;
                $msg = "Site settings saved successfully!";
            }
        }
    }
}
// =========================================================================
// 4c. App Settings + SMS Rules (অ্যাপ api/app_config.php থেকে এগুলো পড়ে)
// =========================================================================
$appSettingsFile = __DIR__ . '/app_settings.json';
$APP_SET = [];
if (is_file($appSettingsFile)) {
    $tmpApp = json_decode((string) @file_get_contents($appSettingsFile), true);
    if (is_array($tmpApp)) $APP_SET = $tmpApp;
}
if (!isset($APP_SET['sms_rules']) || !is_array($APP_SET['sms_rules'])) $APP_SET['sms_rules'] = [];

function snzSaveAppSettings($file, array $data) {
    return @file_put_contents($file, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) !== false;
}

// অ্যাপের ভেতরে যেভাবে রুল মেলানো হয়, ঠিক সেভাবেই এখানে টেস্ট করা হয়
function snzRulePattern($before, $kind) {
    $before = trim((string) $before);
    if ($before === '') return null;
    if (stripos($before, 'regex:') === 0) return '~' . str_replace('~', '\~', substr($before, 6)) . '~iu';
    $q = preg_quote($before, '~');
    if ($kind === 'trx')    return '~' . $q . '[\s:.#-]*([A-Za-z0-9]{4,})~iu';
    if ($kind === 'amount') return '~' . $q . '[\s:.]*(?:Tk\.?|BDT|৳)?\s*([\d,]+(?:\.\d+)?)~iu';
    return '~' . $q . '[\s:.]*([+0-9*]{3,})~iu';
}
function snzRuleExtract($before, $kind, $text) {
    $p = snzRulePattern($before, $kind);
    if ($p === null) return '';
    return (@preg_match($p, $text, $m) && isset($m[1])) ? trim($m[1]) : '';
}
function snzRuleTest(array $rule, $sender, $text) {
    $senders = array_filter(array_map('trim', explode(',', (string) ($rule['senders'] ?? ''))), 'strlen');
    if ($senders) {
        $ok = false;
        foreach ($senders as $sd) { if (stripos((string) $sender, $sd) !== false) { $ok = true; break; } }
        if (!$ok) return ['ok' => false, 'why' => 'Sender does not match'];
    }
    foreach (array_filter(array_map('trim', explode(',', (string) ($rule['must_contain'] ?? ''))), 'strlen') as $w) {
        if (stripos($text, $w) === false) return ['ok' => false, 'why' => "Text \"$w\" not found"];
    }
    $trx = strtoupper(snzRuleExtract($rule['trx_before'] ?? '', 'trx', $text));
    $amt = (float) str_replace(',', '', snzRuleExtract($rule['amount_before'] ?? '', 'amount', $text));
    $num = snzRuleExtract($rule['number_before'] ?? '', 'number', $text);
    if ($trx === '') return ['ok' => false, 'why' => 'TrxID not found'];
    if ($amt <= 0)   return ['ok' => false, 'why' => 'Amount not found'];
    return ['ok' => true, 'trx' => $trx, 'amount' => $amt, 'number' => $num];
}

$smsTestResult = null;

if (isset($_POST['action_update_app'])) {
    $activeSection = 'sec-app';
    $APP_SET['app_name']            = mb_substr(trim($_POST['app_name'] ?? ''), 0, 40);
    $APP_SET['app_tagline']         = mb_substr(trim($_POST['app_tagline'] ?? ''), 0, 80);
    $APP_SET['notice']              = mb_substr(trim($_POST['app_notice'] ?? ''), 0, 500);
    $APP_SET['app_enabled']         = isset($_POST['app_enabled']);
    $APP_SET['disabled_message']    = mb_substr(trim($_POST['disabled_message'] ?? ''), 0, 300);
    $APP_SET['latest_version_code'] = max(0, intval($_POST['latest_version_code'] ?? 0));
    $APP_SET['latest_version_name'] = mb_substr(trim($_POST['latest_version_name'] ?? ''), 0, 20);
    $APP_SET['update_message']      = mb_substr(trim($_POST['update_message'] ?? ''), 0, 300);
    $APP_SET['force_update']        = isset($_POST['force_update']);
    $APP_SET['strict_sender']       = isset($_POST['strict_sender']);
    $apkUrlIn = trim($_POST['apk_url'] ?? '');
    $logoUrlIn = trim($_POST['app_logo_url'] ?? '');

    if ($apkUrlIn !== '' && !preg_match('#^https?://#i', $apkUrlIn)) {
        $error = "APK link must start with https:// (leave empty to use the uploaded APK)";
    } elseif ($logoUrlIn !== '' && !preg_match('#^(https?://|uploads/)#i', $logoUrlIn)) {
        $error = "Logo URL must start with https://";
    } else {
        $APP_SET['apk_url'] = $apkUrlIn;
        $APP_SET['app_logo'] = $logoUrlIn;
        if (isset($_FILES['app_logo_file']) && $_FILES['app_logo_file']['error'] === UPLOAD_ERR_OK) {
            $ext = strtolower(pathinfo($_FILES['app_logo_file']['name'], PATHINFO_EXTENSION));
            if (!in_array($ext, ['png', 'jpg', 'jpeg', 'webp'], true) || @getimagesize($_FILES['app_logo_file']['tmp_name']) === false) {
                $error = "App logo must be a PNG, JPG or WEBP image!";
            } elseif ($_FILES['app_logo_file']['size'] > 2 * 1024 * 1024) {
                $error = "App logo must be smaller than 2 MB!";
            } else {
                $logoDir = __DIR__ . '/uploads/logo/';
                if (!is_dir($logoDir)) @mkdir($logoDir, 0755, true);
                $logoName = 'app_logo_' . time() . '.' . $ext;
                if (@move_uploaded_file($_FILES['app_logo_file']['tmp_name'], $logoDir . $logoName)) {
                    $APP_SET['app_logo'] = 'uploads/logo/' . $logoName;
                } else {
                    $error = "Could not save logo (check uploads folder permission).";
                }
            }
        }
        if (!$error) {
            $msg = snzSaveAppSettings($appSettingsFile, $APP_SET) ? "App settings saved! Apps will update within a few minutes." : "";
            if (!$msg) $error = "Could not save app settings file (check folder permission).";
        }
    }
}

if (isset($_POST['action_add_sms_rule'])) {
    $activeSection = 'sec-sms-rules';
    $rule = [
        'id'            => bin2hex(random_bytes(4)),
        'enabled'       => true,
        'method'        => strtoupper(preg_replace('/[^A-Za-z0-9_]/', '', $_POST['rule_method'] ?? '')),
        'senders'       => mb_substr(trim($_POST['rule_senders'] ?? ''), 0, 200),
        'must_contain'  => mb_substr(trim($_POST['rule_must_contain'] ?? ''), 0, 200),
        'trx_before'    => mb_substr(trim($_POST['rule_trx_before'] ?? ''), 0, 200),
        'amount_before' => mb_substr(trim($_POST['rule_amount_before'] ?? ''), 0, 200),
        'number_before' => mb_substr(trim($_POST['rule_number_before'] ?? ''), 0, 200),
        'created'       => date('Y-m-d H:i'),
    ];
    $badRegex = false;
    foreach (['trx_before' => 'trx', 'amount_before' => 'amount', 'number_before' => 'number'] as $f => $k) {
        $p = snzRulePattern($rule[$f], $k);
        if ($p !== null && @preg_match($p, '') === false) $badRegex = true;
    }
    if ($rule['method'] === '') {
        $error = "Method name is required (e.g. BKASH, NAGAD, UPAY, CITYBANK)";
    } elseif ($rule['trx_before'] === '' || $rule['amount_before'] === '') {
        $error = "\"Text before TrxID\" and \"Text before Amount\" are required!";
    } elseif ($badRegex) {
        $error = "One of the regex patterns is invalid!";
    } else {
        $APP_SET['sms_rules'][] = $rule;
        $msg = snzSaveAppSettings($appSettingsFile, $APP_SET) ? "SMS rule added! Apps will start using it within a few minutes." : "";
        if (!$msg) $error = "Could not save app settings file (check folder permission).";
    }
}

if (isset($_POST['action_toggle_sms_rule']) || isset($_POST['action_delete_sms_rule'])) {
    $activeSection = 'sec-sms-rules';
    $rid = (string) ($_POST['rule_id'] ?? '');
    foreach ($APP_SET['sms_rules'] as $i => $r) {
        if (($r['id'] ?? '') !== $rid) continue;
        if (isset($_POST['action_delete_sms_rule'])) {
            array_splice($APP_SET['sms_rules'], $i, 1);
            $msg = "SMS rule deleted.";
        } else {
            $APP_SET['sms_rules'][$i]['enabled'] = empty($r['enabled']);
            $msg = "SMS rule " . (empty($r['enabled']) ? "enabled." : "disabled.");
        }
        snzSaveAppSettings($appSettingsFile, $APP_SET);
        break;
    }
}

if (isset($_POST['action_test_sms_rule'])) {
    $activeSection = 'sec-sms-rules';
    $tSender = trim($_POST['test_sender'] ?? '');
    $tText = trim($_POST['test_sms'] ?? '');
    $smsTestResult = ['sender' => $tSender, 'text' => $tText, 'rows' => []];
    foreach ($APP_SET['sms_rules'] as $r) {
        $smsTestResult['rows'][] = ['rule' => $r, 'res' => snzRuleTest($r, $tSender, $tText)];
    }
}

// সাইটের Add Fund কোন অ্যাকাউন্টের অ্যাপে আসা SMS দিয়ে verify হবে
if (isset($_POST['action_set_receiver'])) {
    $activeSection = 'sec-payments';
    $rcv = intval($_POST['site_receiver_user_id'] ?? 0);
    $APP_SET['site_receiver_user_id'] = $rcv > 0 ? $rcv : MASTER_USER_ID;
    $msg = snzSaveAppSettings($appSettingsFile, $APP_SET) ? "Site deposit receiver saved!" : "";
    if (!$msg) $error = "Could not save settings file (check folder permission).";
}
$siteReceiverId = (int) ($APP_SET['site_receiver_user_id'] ?? MASTER_USER_ID);

// =========================================================================
// ⚡ Auto Pay — শুধু Brand Key বসিয়ে Save দিলেই সাইটের Add Fund অটো verify
// =========================================================================
if (isset($_POST['action_save_autopay'])) {
    $activeSection = 'sec-autopay';
    $apKey = strtoupper(trim($_POST['autopay_brand_key'] ?? ''));
    $apOn = isset($_POST['autopay_enabled']);
    if ($apKey !== '') {
        $kq = $pdo->prepare("SELECT id, user_id, bound_fingerprint FROM brand_keys WHERE api_key = ? AND status != 'revoked' LIMIT 1");
        $kq->execute([$apKey]);
        $kRow = $kq->fetch();
        if (!$kRow) {
            $error = "This Brand Key was not found (or it was revoked). Create one in the user dashboard → Brands.";
        } else {
            // এই কী আমাদের ওয়েবসাইটে কানেক্টেড হিসেবে দেখাবে
            $siteHostName = strtolower(preg_replace('/^www\./', '', (string) ($_SERVER['HTTP_HOST'] ?? 'website')));
            $siteHostName = preg_replace('/:\d+$/', '', $siteHostName);
            if (empty($kRow['bound_fingerprint']) || strncmp((string) $kRow['bound_fingerprint'], 'name:', 5) !== 0) {
                $pdo->prepare("UPDATE brand_keys SET bound_fingerprint = ?, bound_label = ?, bound_at = NOW(), is_connected = 1, connected_at = COALESCE(connected_at, NOW()), status = 'active' WHERE id = ?")
                    ->execute(['name:' . $siteHostName, $siteHostName . ' (Auto Pay)', $kRow['id']]);
            }
        }
    }
    if (!$error) {
        $APP_SET['autopay_brand_key'] = $apKey;
        $APP_SET['autopay_enabled'] = $apOn;
        $msg = snzSaveAppSettings($appSettingsFile, $APP_SET)
            ? ($apOn ? ($apKey !== '' ? "Auto Pay is ON and connected!" : "Auto Pay is ON (using the account selected in Payment Setup).") : "Auto Pay is OFF — all deposits will come to Deposit Requests.")
            : "";
        if (!$msg) $error = "Could not save settings file (check folder permission).";
    }
}
$autoPayKey = (string) ($APP_SET['autopay_brand_key'] ?? '');
$autoPayEnabled = !isset($APP_SET['autopay_enabled']) || !empty($APP_SET['autopay_enabled']);
$autoPayInfo = null;
if ($autoPayKey !== '') {
    $iq = $pdo->prepare("SELECT bk.*, u.username, u.email FROM brand_keys bk JOIN users u ON u.id = bk.user_id WHERE bk.api_key = ? LIMIT 1");
    $iq->execute([$autoPayKey]);
    $autoPayInfo = $iq->fetch() ?: null;
    if ($autoPayInfo) $autoPayInfo['plan_until'] = snzActivePlanExpiry($pdo, (int) $autoPayInfo['user_id']);
}

// =========================================================================
// 🧾 Deposit Requests — ৩ বার ব্যর্থ বা Auto Pay বন্ধ থাকলে এখানে আসে
// =========================================================================
if (isset($_POST['action_deposit_decide'])) {
    $activeSection = 'sec-deposits';
    $rid = intval($_POST['request_id'] ?? 0);
    $dq = $pdo->prepare("SELECT * FROM deposit_requests WHERE id = ? LIMIT 1");
    $dq->execute([$rid]);
    $dRow = $dq->fetch();
    $note = mb_substr(trim($_POST['admin_note'] ?? ''), 0, 250);
    if (!$dRow || !in_array($dRow['status'], ['review', 'trying'], true)) {
        $error = "Request not found or already decided.";
    } elseif (($_POST['decision'] ?? '') === 'approve') {
        $credit = max(0, floatval($_POST['credit_amount'] ?? $dRow['amount']));
        $ok = $pdo->prepare("UPDATE deposit_requests SET status = 'approved', credited_amount = ?, admin_note = ?, updated_at = NOW() WHERE id = ? AND status IN ('review','trying')");
        $ok->execute([$credit, $note !== '' ? $note : 'Approved by admin', $rid]);
        if ($ok->rowCount() > 0) {
            $pdo->prepare("UPDATE users SET balance = balance + ? WHERE id = ?")->execute([$credit, $dRow['user_id']]);
            // একই TrxID এর SMS থাকলে used করা (আবার কেউ ব্যবহার করতে না পারে)
            $pdo->prepare("UPDATE payments SET status = 'used', used_at = NOW(), claimed_via = 'Admin Approved' WHERE trx_id = ? AND status = 'pending'")->execute([$dRow['trx_id']]);
            $msg = "Approved! ৳" . number_format($credit, 2) . " added to the user's balance.";
        }
    } else {
        $pdo->prepare("UPDATE deposit_requests SET status = 'rejected', admin_note = ?, updated_at = NOW() WHERE id = ?")->execute([$note !== '' ? $note : 'Rejected by admin', $rid]);
        $msg = "Request rejected.";
    }
}
$pendingDeposits = $pdo->query("SELECT dr.*, u.username, u.email, u.balance FROM deposit_requests dr JOIN users u ON u.id = dr.user_id WHERE dr.status = 'review' ORDER BY dr.updated_at DESC LIMIT 100")->fetchAll();
$recentDeposits = $pdo->query("SELECT dr.*, u.username FROM deposit_requests dr JOIN users u ON u.id = dr.user_id WHERE dr.status IN ('approved','rejected') ORDER BY dr.updated_at DESC LIMIT 20")->fetchAll();

$currentNotice = is_file(__DIR__ . '/notice.txt') ? trim((string) file_get_contents(__DIR__ . '/notice.txt')) : '';

// =========================================================================
// 4d. Devices — একটা ফোন বা সব ফোন রিমুভ (তারপর নতুন ফোনে লগইন করা যায়)
// =========================================================================
if (isset($_POST['action_reset_device'])) {
    $activeSection = 'sec-licenses';
    $rid = intval($_POST['key_id'] ?? 0);
    $pdo->prepare("UPDATE user_keys SET device_id = NULL, device_name = NULL, device_bound_at = NULL WHERE id = ?")->execute([$rid]);
    $pdo->prepare("DELETE FROM key_devices WHERE key_id = ?")->execute([$rid]);
    $msg = "All phones removed! This key can now be logged in on new phones.";
}
// 🚫 Ban / Unban — Ban করলে ১ মিনিটের মধ্যে অ্যাপ লগআউট হয়ে যায়
if (isset($_POST['action_ban_key'])) {
    $activeSection = 'sec-licenses';
    $bid = intval($_POST['key_id'] ?? 0);
    $pdo->prepare("UPDATE user_keys SET status = IF(status = 'active', 'banned', 'active') WHERE id = ?")->execute([$bid]);
    $st = $pdo->prepare("SELECT status FROM user_keys WHERE id = ?");
    $st->execute([$bid]);
    $msg = $st->fetchColumn() === 'banned' ? "Key banned. The app will log out within a minute." : "Key unbanned.";
}
if (isset($_POST['action_remove_device'])) {
    $activeSection = 'sec-licenses';
    $did = intval($_POST['device_row_id'] ?? 0);
    $dr = $pdo->prepare("SELECT key_id, device_id, device_name FROM key_devices WHERE id = ?");
    $dr->execute([$did]);
    if ($row = $dr->fetch()) {
        $pdo->prepare("DELETE FROM key_devices WHERE id = ?")->execute([$did]);
        $pdo->prepare("UPDATE user_keys SET device_id = NULL, device_name = NULL, device_bound_at = NULL WHERE id = ? AND device_id = ?")->execute([$row['key_id'], $row['device_id']]);
        $msg = "Phone removed: " . ($row['device_name'] ?: 'Android Phone');
    }
}

// =========================================================================
// 4e. Plans — নতুন প্ল্যান / এডিট / লুকানো / ডিলিট
// =========================================================================
if (isset($_POST['action_save_plan'])) {
    $activeSection = 'sec-plans';
    $pid = intval($_POST['plan_id'] ?? 0);
    $pName = mb_substr(trim($_POST['p_name'] ?? ''), 0, 100);
    $pPrice = max(0, floatval($_POST['p_price'] ?? 0));
    $pDisc = max(0, min(100, floatval($_POST['p_discount'] ?? 0)));
    $pDurVal = max(1, floatval($_POST['p_duration'] ?? 30));
    $pDurHours = ($_POST['p_duration_unit'] ?? 'days') === 'hours' ? (int) round($pDurVal) : (int) round($pDurVal * 24);
    $pDevices = max(1, min(100, intval($_POST['p_devices'] ?? 1)));
    $pBrands = max(0, min(100, intval($_POST['p_brands'] ?? 1)));
    $pBadge = mb_substr(trim($_POST['p_badge'] ?? ''), 0, 40);
    $pDesc = mb_substr(trim($_POST['p_desc'] ?? ''), 0, 255);
    $pTrial = isset($_POST['p_trial']) ? 1 : 0;
    $pStatus = isset($_POST['p_active']) ? 'active' : 'hidden';
    $pSort = intval($_POST['p_sort'] ?? 0);
    $pCat = mb_substr(trim($_POST['p_category'] ?? ''), 0, 40);
    $pPin = isset($_POST['p_pinned']) ? 1 : 0;

    if ($pName === '') {
        $error = "Plan name is required!";
    } else {
        if ($pid > 0) {
            $pdo->prepare("UPDATE plans SET name=?, price=?, discount_percent=?, duration_hours=?, max_devices=?, max_brand_keys=?, badge=?, description=?, is_trial=?, status=?, sort_order=?, category=?, is_pinned=? WHERE id=?")
                ->execute([$pName, $pPrice, $pDisc, $pDurHours, $pDevices, $pBrands, $pBadge, $pDesc, $pTrial, $pStatus, $pSort, $pCat, $pPin, $pid]);
            $msg = "Plan updated: $pName";
        } else {
            if ($pSort === 0) $pSort = (int) $pdo->query("SELECT COALESCE(MAX(sort_order), 0) + 1 FROM plans")->fetchColumn();   // নতুন প্ল্যান শেষে
            $pdo->prepare("INSERT INTO plans (name, price, discount_percent, duration_hours, max_devices, max_brand_keys, badge, description, is_trial, status, sort_order, category, is_pinned) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)")
                ->execute([$pName, $pPrice, $pDisc, $pDurHours, $pDevices, $pBrands, $pBadge, $pDesc, $pTrial, $pStatus, $pSort, $pCat, $pPin]);
            $msg = "New plan created: $pName";
        }
        snzWritePlansCache($pdo);
    }
}
// 📌 Pin / ⬆⬇ ক্রম
if (isset($_POST['action_pin_plan'])) {
    $activeSection = 'sec-plans';
    $pdo->prepare("UPDATE plans SET is_pinned = 1 - is_pinned WHERE id = ?")->execute([intval($_POST['plan_id'] ?? 0)]);
    $msg = "Plan pin updated.";
    snzWritePlansCache($pdo);
}
if (isset($_POST['action_move_plan'])) {
    $activeSection = 'sec-plans';
    $pid = intval($_POST['plan_id'] ?? 0);
    $dir = ($_POST['dir'] ?? '') === 'up' ? -1 : 1;
    // বর্তমান ক্রম অনুযায়ী ১,২,৩... নম্বর দিয়ে তারপর পাশের প্ল্যানের সাথে জায়গা বদল
    $ids = $pdo->query("SELECT id FROM plans ORDER BY is_pinned DESC, sort_order ASC, price ASC, id ASC")->fetchAll(PDO::FETCH_COLUMN);
    $pos = array_search((string) $pid, array_map('strval', $ids), true);
    if ($pos !== false && isset($ids[$pos + $dir])) {
        $tmp = $ids[$pos]; $ids[$pos] = $ids[$pos + $dir]; $ids[$pos + $dir] = $tmp;
    }
    $upd = $pdo->prepare("UPDATE plans SET sort_order = ? WHERE id = ?");
    foreach (array_values($ids) as $i => $id) $upd->execute([$i + 1, $id]);
    $msg = "Plan order updated.";
    snzWritePlansCache($pdo);
}
if (isset($_POST['action_delete_plan'])) {
    $activeSection = 'sec-plans';
    $pid = intval($_POST['plan_id'] ?? 0);
    $used = $pdo->prepare("SELECT COUNT(*) FROM user_keys WHERE plan_id = ?");
    $used->execute([$pid]);
    if ((int) $used->fetchColumn() > 0) {
        // কেউ কিনে থাকলে মুছে না ফেলে শুধু লুকানো (তাদের কী ঠিক থাকবে)
        $pdo->prepare("UPDATE plans SET status = 'hidden' WHERE id = ?")->execute([$pid]);
        $msg = "Plan is used by customers, so it was hidden instead of deleted.";
    } else {
        $pdo->prepare("DELETE FROM plans WHERE id = ?")->execute([$pid]);
        $msg = "Plan deleted.";
    }
    snzWritePlansCache($pdo);
}

// =========================================================================
// 5. Delete License Key
// =========================================================================
if (isset($_GET['delete_key'])) {
    $delId = intval($_GET['delete_key']);
    $kCheck = $pdo->prepare("SELECT * FROM user_keys WHERE id = ?");
    $kCheck->execute([$delId]);
    $keyData = $kCheck->fetch();

    if ($keyData) {
        $keyString = $keyData['license_key'];
        $pdo->prepare("DELETE FROM user_keys WHERE id = ?")->execute([$delId]);
        try { $pdo->prepare("DELETE FROM key_devices WHERE key_id = ?")->execute([$delId]); } catch (Throwable $e) {}
        
        $fbDelUrl = rtrim($FIREBASE_URL, '/') . "/license_keys/" . urlencode($keyString) . ".json";
        pushToFirebase($fbDelUrl, "DELETE");

        $msg = "License Key ($keyString) permanently removed!";
    }
}

// Statistics (password সহ সব কলাম আনার দরকার নেই → দ্রুত)
$allUsers = $pdo->query("SELECT id, username, email, balance, created_at FROM users ORDER BY id DESC")->fetchAll();
$allKeys = $pdo->query("SELECT uk.id, uk.license_key, uk.plan_name, uk.expires_at, uk.status, uk.device_id, uk.device_name, uk.device_bound_at, uk.last_seen_at, uk.max_devices, uk.max_brand_keys, u.username, u.email FROM user_keys uk JOIN users u ON uk.user_id = u.id ORDER BY uk.id DESC LIMIT 50")->fetchAll();
$keyDevicesMap = snzKeyDevices($pdo, array_column($allKeys, 'id'));
$allPlans = snzGetPlans($pdo, false);
$totalKeys = (int) $pdo->query("SELECT COUNT(*) FROM user_keys")->fetchColumn();

$totalBalance = array_sum(array_map('floatval', array_column($allUsers, 'balance')));
$apkExists = file_exists(__DIR__ . '/app-release.apk');
$apkSize = $apkExists ? round(filesize(__DIR__ . '/app-release.apk') / (1024 * 1024), 2) . " MB" : "Not uploaded";
$apkTime = $apkExists ? date("d M Y, h:i A", filemtime(__DIR__ . '/app-release.apk')) : "N/A";
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title><?= htmlspecialchars($SITE_NAME) ?> - Master Controller</title>
    <style>*,:after,:before{--tw-border-spacing-x:0;--tw-border-spacing-y:0;--tw-translate-x:0;--tw-translate-y:0;--tw-rotate:0;--tw-skew-x:0;--tw-skew-y:0;--tw-scale-x:1;--tw-scale-y:1;--tw-pan-x: ;--tw-pan-y: ;--tw-pinch-zoom: ;--tw-scroll-snap-strictness:proximity;--tw-gradient-from-position: ;--tw-gradient-via-position: ;--tw-gradient-to-position: ;--tw-ordinal: ;--tw-slashed-zero: ;--tw-numeric-figure: ;--tw-numeric-spacing: ;--tw-numeric-fraction: ;--tw-ring-inset: ;--tw-ring-offset-width:0px;--tw-ring-offset-color:#fff;--tw-ring-color:rgba(59,130,246,.5);--tw-ring-offset-shadow:0 0 #0000;--tw-ring-shadow:0 0 #0000;--tw-shadow:0 0 #0000;--tw-shadow-colored:0 0 #0000;--tw-blur: ;--tw-brightness: ;--tw-contrast: ;--tw-grayscale: ;--tw-hue-rotate: ;--tw-invert: ;--tw-saturate: ;--tw-sepia: ;--tw-drop-shadow: ;--tw-backdrop-blur: ;--tw-backdrop-brightness: ;--tw-backdrop-contrast: ;--tw-backdrop-grayscale: ;--tw-backdrop-hue-rotate: ;--tw-backdrop-invert: ;--tw-backdrop-opacity: ;--tw-backdrop-saturate: ;--tw-backdrop-sepia: ;--tw-contain-size: ;--tw-contain-layout: ;--tw-contain-paint: ;--tw-contain-style: }::backdrop{--tw-border-spacing-x:0;--tw-border-spacing-y:0;--tw-translate-x:0;--tw-translate-y:0;--tw-rotate:0;--tw-skew-x:0;--tw-skew-y:0;--tw-scale-x:1;--tw-scale-y:1;--tw-pan-x: ;--tw-pan-y: ;--tw-pinch-zoom: ;--tw-scroll-snap-strictness:proximity;--tw-gradient-from-position: ;--tw-gradient-via-position: ;--tw-gradient-to-position: ;--tw-ordinal: ;--tw-slashed-zero: ;--tw-numeric-figure: ;--tw-numeric-spacing: ;--tw-numeric-fraction: ;--tw-ring-inset: ;--tw-ring-offset-width:0px;--tw-ring-offset-color:#fff;--tw-ring-color:rgba(59,130,246,.5);--tw-ring-offset-shadow:0 0 #0000;--tw-ring-shadow:0 0 #0000;--tw-shadow:0 0 #0000;--tw-shadow-colored:0 0 #0000;--tw-blur: ;--tw-brightness: ;--tw-contrast: ;--tw-grayscale: ;--tw-hue-rotate: ;--tw-invert: ;--tw-saturate: ;--tw-sepia: ;--tw-drop-shadow: ;--tw-backdrop-blur: ;--tw-backdrop-brightness: ;--tw-backdrop-contrast: ;--tw-backdrop-grayscale: ;--tw-backdrop-hue-rotate: ;--tw-backdrop-invert: ;--tw-backdrop-opacity: ;--tw-backdrop-saturate: ;--tw-backdrop-sepia: ;--tw-contain-size: ;--tw-contain-layout: ;--tw-contain-paint: ;--tw-contain-style: }/*! tailwindcss v3.4.17 | MIT License | https://tailwindcss.com*/*,:after,:before{box-sizing:border-box;border:0 solid #e5e7eb}:after,:before{--tw-content:""}:host,html{line-height:1.5;-webkit-text-size-adjust:100%;-moz-tab-size:4;-o-tab-size:4;tab-size:4;font-family:ui-sans-serif,system-ui,sans-serif,Apple Color Emoji,Segoe UI Emoji,Segoe UI Symbol,Noto Color Emoji;font-feature-settings:normal;font-variation-settings:normal;-webkit-tap-highlight-color:transparent}body{margin:0;line-height:inherit}hr{height:0;color:inherit;border-top-width:1px}abbr:where([title]){-webkit-text-decoration:underline dotted;text-decoration:underline dotted}h1,h2,h3,h4,h5,h6{font-size:inherit;font-weight:inherit}a{color:inherit;text-decoration:inherit}b,strong{font-weight:bolder}code,kbd,pre,samp{font-family:ui-monospace,SFMono-Regular,Menlo,Monaco,Consolas,Liberation Mono,Courier New,monospace;font-feature-settings:normal;font-variation-settings:normal;font-size:1em}small{font-size:80%}sub,sup{font-size:75%;line-height:0;position:relative;vertical-align:baseline}sub{bottom:-.25em}sup{top:-.5em}table{text-indent:0;border-color:inherit;border-collapse:collapse}button,input,optgroup,select,textarea{font-family:inherit;font-feature-settings:inherit;font-variation-settings:inherit;font-size:100%;font-weight:inherit;line-height:inherit;letter-spacing:inherit;color:inherit;margin:0;padding:0}button,select{text-transform:none}button,input:where([type=button]),input:where([type=reset]),input:where([type=submit]){-webkit-appearance:button;background-color:transparent;background-image:none}:-moz-focusring{outline:auto}:-moz-ui-invalid{box-shadow:none}progress{vertical-align:baseline}::-webkit-inner-spin-button,::-webkit-outer-spin-button{height:auto}[type=search]{-webkit-appearance:textfield;outline-offset:-2px}::-webkit-search-decoration{-webkit-appearance:none}::-webkit-file-upload-button{-webkit-appearance:button;font:inherit}summary{display:list-item}blockquote,dd,dl,figure,h1,h2,h3,h4,h5,h6,hr,p,pre{margin:0}fieldset{margin:0}fieldset,legend{padding:0}menu,ol,ul{list-style:none;margin:0;padding:0}dialog{padding:0}textarea{resize:vertical}input::-moz-placeholder,textarea::-moz-placeholder{opacity:1;color:#9ca3af}input::placeholder,textarea::placeholder{opacity:1;color:#9ca3af}[role=button],button{cursor:pointer}:disabled{cursor:default}audio,canvas,embed,iframe,img,object,svg,video{display:block;vertical-align:middle}img,video{max-width:100%;height:auto}[hidden]:where(:not([hidden=until-found])){display:none}.\!container{width:100%!important}.container{width:100%}@media (min-width:640px){.\!container{max-width:640px!important}.container{max-width:640px}}@media (min-width:768px){.\!container{max-width:768px!important}.container{max-width:768px}}@media (min-width:1024px){.\!container{max-width:1024px!important}.container{max-width:1024px}}@media (min-width:1280px){.\!container{max-width:1280px!important}.container{max-width:1280px}}@media (min-width:1536px){.\!container{max-width:1536px!important}.container{max-width:1536px}}.sr-only{position:absolute;width:1px;height:1px;padding:0;margin:-1px;overflow:hidden;clip:rect(0,0,0,0);white-space:nowrap;border-width:0}.pointer-events-none{pointer-events:none}.visible{visibility:visible}.static{position:static}.fixed{position:fixed}.absolute{position:absolute}.relative{position:relative}.sticky{position:sticky}.inset-0{inset:0}.-top-3{top:-.75rem}.bottom-0{bottom:0}.left-0{left:0}.left-1\/2{left:50%}.left-4{left:1rem}.left-5{left:1.25rem}.right-0{right:0}.right-4{right:1rem}.right-5{right:1.25rem}.right-6{right:1.5rem}.top-0{top:0}.top-3\.5{top:.875rem}.top-5{top:1.25rem}.z-30{z-index:30}.z-40{z-index:40}.z-50{z-index:50}.z-\[60\]{z-index:60}.z-\[70\]{z-index:70}.mx-auto{margin-left:auto;margin-right:auto}.my-3{margin-top:.75rem;margin-bottom:.75rem}.my-4{margin-top:1rem;margin-bottom:1rem}.-mt-0\.5{margin-top:-.125rem}.mb-1{margin-bottom:.25rem}.mb-1\.5{margin-bottom:.375rem}.mb-10{margin-bottom:2.5rem}.mb-2{margin-bottom:.5rem}.mb-3{margin-bottom:.75rem}.mb-4{margin-bottom:1rem}.mb-5{margin-bottom:1.25rem}.mb-6{margin-bottom:1.5rem}.ml-auto{margin-left:auto}.mr-1{margin-right:.25rem}.mr-1\.5{margin-right:.375rem}.mt-0\.5{margin-top:.125rem}.mt-1{margin-top:.25rem}.mt-1\.5{margin-top:.375rem}.mt-2{margin-top:.5rem}.mt-3{margin-top:.75rem}.mt-4{margin-top:1rem}.mt-5{margin-top:1.25rem}.mt-6{margin-top:1.5rem}.block{display:block}.flex{display:flex}.inline-flex{display:inline-flex}.table{display:table}.grid{display:grid}.hidden{display:none}.h-10{height:2.5rem}.h-11{height:2.75rem}.h-12{height:3rem}.h-14{height:3.5rem}.h-16{height:4rem}.h-2{height:.5rem}.h-2\.5{height:.625rem}.h-20{height:5rem}.h-36{height:9rem}.h-48{height:12rem}.h-5{height:1.25rem}.h-6{height:1.5rem}.h-7{height:1.75rem}.h-8{height:2rem}.h-9{height:2.25rem}.h-full{height:100%}.min-h-screen{min-height:100vh}.w-1\/2{width:50%}.w-10{width:2.5rem}.w-11{width:2.75rem}.w-12{width:3rem}.w-14{width:3.5rem}.w-16{width:4rem}.w-2{width:.5rem}.w-20{width:5rem}.w-4{width:1rem}.w-48{width:12rem}.w-5{width:1.25rem}.w-6{width:1.5rem}.w-7{width:1.75rem}.w-72{width:18rem}.w-8{width:2rem}.w-9{width:2.25rem}.w-\[90\%\]{width:90%}.w-auto{width:auto}.w-full{width:100%}.min-w-0{min-width:0}.max-w-2xl{max-width:42rem}.max-w-3xl{max-width:48rem}.max-w-4xl{max-width:56rem}.max-w-6xl{max-width:72rem}.max-w-lg{max-width:32rem}.max-w-md{max-width:28rem}.max-w-sm{max-width:24rem}.max-w-xl{max-width:36rem}.max-w-xs{max-width:20rem}.flex-1{flex:1 1 0%}.flex-shrink{flex-shrink:1}.shrink-0{flex-shrink:0}.-translate-x-1\/2{--tw-translate-x:-50%}.-translate-x-1\/2,.-translate-x-full{transform:translate(var(--tw-translate-x),var(--tw-translate-y)) rotate(var(--tw-rotate)) skewX(var(--tw-skew-x)) skewY(var(--tw-skew-y)) scaleX(var(--tw-scale-x)) scaleY(var(--tw-scale-y))}.-translate-x-full{--tw-translate-x:-100%}.-translate-y-8{--tw-translate-y:-2rem}.-translate-y-8,.translate-x-full{transform:translate(var(--tw-translate-x),var(--tw-translate-y)) rotate(var(--tw-rotate)) skewX(var(--tw-skew-x)) skewY(var(--tw-skew-y)) scaleX(var(--tw-scale-x)) scaleY(var(--tw-scale-y))}.translate-x-full{--tw-translate-x:100%}.translate-y-0{--tw-translate-y:0px}.transform,.translate-y-0{transform:translate(var(--tw-translate-x),var(--tw-translate-y)) rotate(var(--tw-rotate)) skewX(var(--tw-skew-x)) skewY(var(--tw-skew-y)) scaleX(var(--tw-scale-x)) scaleY(var(--tw-scale-y))}@keyframes bounce{0%,to{transform:translateY(-25%);animation-timing-function:cubic-bezier(.8,0,1,1)}50%{transform:none;animation-timing-function:cubic-bezier(0,0,.2,1)}}.animate-bounce{animation:bounce 1s infinite}@keyframes pulse{50%{opacity:.5}}.animate-pulse{animation:pulse 2s cubic-bezier(.4,0,.6,1) infinite}.cursor-not-allowed{cursor:not-allowed}.cursor-pointer{cursor:pointer}.select-none{-webkit-user-select:none;-moz-user-select:none;user-select:none}.select-all{-webkit-user-select:all;-moz-user-select:all;user-select:all}.grid-cols-1{grid-template-columns:repeat(1,minmax(0,1fr))}.grid-cols-2{grid-template-columns:repeat(2,minmax(0,1fr))}.grid-cols-3{grid-template-columns:repeat(3,minmax(0,1fr))}.flex-col{flex-direction:column}.flex-wrap{flex-wrap:wrap}.items-start{align-items:flex-start}.items-end{align-items:flex-end}.items-center{align-items:center}.items-stretch{align-items:stretch}.justify-center{justify-content:center}.justify-between{justify-content:space-between}.gap-1{gap:.25rem}.gap-1\.5{gap:.375rem}.gap-10{gap:2.5rem}.gap-2{gap:.5rem}.gap-2\.5{gap:.625rem}.gap-3{gap:.75rem}.gap-3\.5{gap:.875rem}.gap-4{gap:1rem}.gap-5{gap:1.25rem}.gap-6{gap:1.5rem}.gap-8{gap:2rem}.gap-x-4{-moz-column-gap:1rem;column-gap:1rem}.gap-y-1{row-gap:.25rem}.space-y-0\.5>:not([hidden])~:not([hidden]){--tw-space-y-reverse:0;margin-top:calc(.125rem*(1 - var(--tw-space-y-reverse)));margin-bottom:calc(.125rem*var(--tw-space-y-reverse))}.space-y-1>:not([hidden])~:not([hidden]){--tw-space-y-reverse:0;margin-top:calc(.25rem*(1 - var(--tw-space-y-reverse)));margin-bottom:calc(.25rem*var(--tw-space-y-reverse))}.space-y-1\.5>:not([hidden])~:not([hidden]){--tw-space-y-reverse:0;margin-top:calc(.375rem*(1 - var(--tw-space-y-reverse)));margin-bottom:calc(.375rem*var(--tw-space-y-reverse))}.space-y-2>:not([hidden])~:not([hidden]){--tw-space-y-reverse:0;margin-top:calc(.5rem*(1 - var(--tw-space-y-reverse)));margin-bottom:calc(.5rem*var(--tw-space-y-reverse))}.space-y-2\.5>:not([hidden])~:not([hidden]){--tw-space-y-reverse:0;margin-top:calc(.625rem*(1 - var(--tw-space-y-reverse)));margin-bottom:calc(.625rem*var(--tw-space-y-reverse))}.space-y-3>:not([hidden])~:not([hidden]){--tw-space-y-reverse:0;margin-top:calc(.75rem*(1 - var(--tw-space-y-reverse)));margin-bottom:calc(.75rem*var(--tw-space-y-reverse))}.space-y-3\.5>:not([hidden])~:not([hidden]){--tw-space-y-reverse:0;margin-top:calc(.875rem*(1 - var(--tw-space-y-reverse)));margin-bottom:calc(.875rem*var(--tw-space-y-reverse))}.space-y-4>:not([hidden])~:not([hidden]){--tw-space-y-reverse:0;margin-top:calc(1rem*(1 - var(--tw-space-y-reverse)));margin-bottom:calc(1rem*var(--tw-space-y-reverse))}.space-y-5>:not([hidden])~:not([hidden]){--tw-space-y-reverse:0;margin-top:calc(1.25rem*(1 - var(--tw-space-y-reverse)));margin-bottom:calc(1.25rem*var(--tw-space-y-reverse))}.space-y-6>:not([hidden])~:not([hidden]){--tw-space-y-reverse:0;margin-top:calc(1.5rem*(1 - var(--tw-space-y-reverse)));margin-bottom:calc(1.5rem*var(--tw-space-y-reverse))}.divide-y>:not([hidden])~:not([hidden]){--tw-divide-y-reverse:0;border-top-width:calc(1px*(1 - var(--tw-divide-y-reverse)));border-bottom-width:calc(1px*var(--tw-divide-y-reverse))}.divide-\[\#0d265c\]>:not([hidden])~:not([hidden]){--tw-divide-opacity:1;border-color:rgb(13 38 92/var(--tw-divide-opacity,1))}.divide-\[\#0e275c\]>:not([hidden])~:not([hidden]){--tw-divide-opacity:1;border-color:rgb(14 39 92/var(--tw-divide-opacity,1))}.overflow-hidden{overflow:hidden}.overflow-x-auto{overflow-x:auto}.overflow-x-hidden{overflow-x:hidden}.truncate{overflow:hidden;text-overflow:ellipsis}.truncate,.whitespace-nowrap{white-space:nowrap}.break-all{word-break:break-all}.rounded-2xl{border-radius:1rem}.rounded-3xl{border-radius:1.5rem}.rounded-full{border-radius:9999px}.rounded-lg{border-radius:.5rem}.rounded-md{border-radius:.375rem}.rounded-xl{border-radius:.75rem}.border{border-width:1px}.border-2{border-width:2px}.border-b{border-bottom-width:1px}.border-l{border-left-width:1px}.border-r{border-right-width:1px}.border-t{border-top-width:1px}.border-dashed{border-style:dashed}.border-\[\#007BFF\]\/30{border-color:rgba(0,123,255,.3)}.border-\[\#00D89A\]\/20{border-color:rgba(0,216,154,.2)}.border-\[\#00D89A\]\/30{border-color:rgba(0,216,154,.3)}.border-\[\#00D89A\]\/40{border-color:rgba(0,216,154,.4)}.border-\[\#00D89A\]\/50{border-color:rgba(0,216,154,.5)}.border-\[\#00D89A\]\/60{border-color:rgba(0,216,154,.6)}.border-\[\#00D9FF\]{--tw-border-opacity:1;border-color:rgb(0 217 255/var(--tw-border-opacity,1))}.border-\[\#00D9FF\]\/20{border-color:rgba(0,217,255,.2)}.border-\[\#00D9FF\]\/30{border-color:rgba(0,217,255,.3)}.border-\[\#00D9FF\]\/40{border-color:rgba(0,217,255,.4)}.border-\[\#00D9FF\]\/50{border-color:rgba(0,217,255,.5)}.border-\[\#0d265c\]{--tw-border-opacity:1;border-color:rgb(13 38 92/var(--tw-border-opacity,1))}.border-\[\#0d2a63\]{--tw-border-opacity:1;border-color:rgb(13 42 99/var(--tw-border-opacity,1))}.border-\[\#0e275c\]{--tw-border-opacity:1;border-color:rgb(14 39 92/var(--tw-border-opacity,1))}.border-\[\#0e306e\]{--tw-border-opacity:1;border-color:rgb(14 48 110/var(--tw-border-opacity,1))}.border-\[\#10326f\]{--tw-border-opacity:1;border-color:rgb(16 50 111/var(--tw-border-opacity,1))}.border-\[\#123982\]{--tw-border-opacity:1;border-color:rgb(18 57 130/var(--tw-border-opacity,1))}.border-\[\#143985\]{--tw-border-opacity:1;border-color:rgb(20 57 133/var(--tw-border-opacity,1))}.border-\[\#16418f\]{--tw-border-opacity:1;border-color:rgb(22 65 143/var(--tw-border-opacity,1))}.border-\[\#174396\]{--tw-border-opacity:1;border-color:rgb(23 67 150/var(--tw-border-opacity,1))}.border-amber-400{--tw-border-opacity:1;border-color:rgb(251 191 36/var(--tw-border-opacity,1))}.border-amber-500\/30{border-color:rgba(245,158,11,.3)}.border-amber-500\/40{border-color:rgba(245,158,11,.4)}.border-rose-500\/20{border-color:rgba(244,63,94,.2)}.border-rose-500\/30{border-color:rgba(244,63,94,.3)}.border-rose-500\/40{border-color:rgba(244,63,94,.4)}.border-slate-700{--tw-border-opacity:1;border-color:rgb(51 65 85/var(--tw-border-opacity,1))}.bg-\[\#007BFF\]{--tw-bg-opacity:1;background-color:rgb(0 123 255/var(--tw-bg-opacity,1))}.bg-\[\#007BFF\]\/20{background-color:rgba(0,123,255,.2)}.bg-\[\#00D89A\]{--tw-bg-opacity:1;background-color:rgb(0 216 154/var(--tw-bg-opacity,1))}.bg-\[\#00D89A\]\/10{background-color:rgba(0,216,154,.1)}.bg-\[\#00D89A\]\/15{background-color:rgba(0,216,154,.15)}.bg-\[\#00D89A\]\/20{background-color:rgba(0,216,154,.2)}.bg-\[\#00D89A\]\/5{background-color:rgba(0,216,154,.05)}.bg-\[\#00D9FF\]\/10{background-color:rgba(0,217,255,.1)}.bg-\[\#00D9FF\]\/15{background-color:rgba(0,217,255,.15)}.bg-\[\#00D9FF\]\/20{background-color:rgba(0,217,255,.2)}.bg-\[\#020817\]{--tw-bg-opacity:1;background-color:rgb(2 8 23/var(--tw-bg-opacity,1))}.bg-\[\#020817\]\/80{background-color:rgba(2,8,23,.8)}.bg-\[\#020817\]\/85{background-color:rgba(2,8,23,.85)}.bg-\[\#030d24\]\/95{background-color:rgba(3,13,36,.95)}.bg-\[\#03132e\]{--tw-bg-opacity:1;background-color:rgb(3 19 46/var(--tw-bg-opacity,1))}.bg-\[\#040e24\]{--tw-bg-opacity:1;background-color:rgb(4 14 36/var(--tw-bg-opacity,1))}.bg-\[\#04122d\]{--tw-bg-opacity:1;background-color:rgb(4 18 45/var(--tw-bg-opacity,1))}.bg-\[\#05112b\]\/95{background-color:rgba(5,17,43,.95)}.bg-\[\#051433\]{--tw-bg-opacity:1;background-color:rgb(5 20 51/var(--tw-bg-opacity,1))}.bg-\[\#051433\]\/95{background-color:rgba(5,20,51,.95)}.bg-\[\#061535\]{--tw-bg-opacity:1;background-color:rgb(6 21 53/var(--tw-bg-opacity,1))}.bg-\[\#061535\]\/80{background-color:rgba(6,21,53,.8)}.bg-\[\#061535\]\/85{background-color:rgba(6,21,53,.85)}.bg-\[\#061535\]\/90{background-color:rgba(6,21,53,.9)}.bg-\[\#071638\]{--tw-bg-opacity:1;background-color:rgb(7 22 56/var(--tw-bg-opacity,1))}.bg-\[\#081b42\]\/95{background-color:rgba(8,27,66,.95)}.bg-\[\#081e4b\]{--tw-bg-opacity:1;background-color:rgb(8 30 75/var(--tw-bg-opacity,1))}.bg-\[\#09204e\]{--tw-bg-opacity:1;background-color:rgb(9 32 78/var(--tw-bg-opacity,1))}.bg-\[\#092257\]{--tw-bg-opacity:1;background-color:rgb(9 34 87/var(--tw-bg-opacity,1))}.bg-\[\#0a1e4a\]{--tw-bg-opacity:1;background-color:rgb(10 30 74/var(--tw-bg-opacity,1))}.bg-\[\#0a2356\]{--tw-bg-opacity:1;background-color:rgb(10 35 86/var(--tw-bg-opacity,1))}.bg-\[\#0c2454\]{--tw-bg-opacity:1;background-color:rgb(12 36 84/var(--tw-bg-opacity,1))}.bg-\[\#35F58A\]\/15{background-color:rgba(53,245,138,.15)}.bg-\[\#35F58A\]\/20{background-color:rgba(53,245,138,.2)}.bg-\[\#8B5CF6\]\/20{background-color:rgba(139,92,246,.2)}.bg-amber-400{--tw-bg-opacity:1;background-color:rgb(251 191 36/var(--tw-bg-opacity,1))}.bg-amber-500\/15{background-color:rgba(245,158,11,.15)}.bg-amber-500\/20{background-color:rgba(245,158,11,.2)}.bg-amber-500\/5{background-color:rgba(245,158,11,.05)}.bg-black\/80{background-color:rgba(0,0,0,.8)}.bg-rose-500{--tw-bg-opacity:1;background-color:rgb(244 63 94/var(--tw-bg-opacity,1))}.bg-rose-500\/10{background-color:rgba(244,63,94,.1)}.bg-rose-500\/15{background-color:rgba(244,63,94,.15)}.bg-rose-500\/20{background-color:rgba(244,63,94,.2)}.bg-slate-700{--tw-bg-opacity:1;background-color:rgb(51 65 85/var(--tw-bg-opacity,1))}.bg-slate-800{--tw-bg-opacity:1;background-color:rgb(30 41 59/var(--tw-bg-opacity,1))}.bg-transparent{background-color:transparent}.bg-white{--tw-bg-opacity:1;background-color:rgb(255 255 255/var(--tw-bg-opacity,1))}.bg-gradient-to-br{background-image:linear-gradient(to bottom right,var(--tw-gradient-stops))}.from-\[\#06303a\]{--tw-gradient-from:#06303a var(--tw-gradient-from-position);--tw-gradient-to:rgba(6,48,58,0) var(--tw-gradient-to-position);--tw-gradient-stops:var(--tw-gradient-from),var(--tw-gradient-to)}.from-\[\#071a44\]{--tw-gradient-from:#071a44 var(--tw-gradient-from-position);--tw-gradient-to:rgba(7,26,68,0) var(--tw-gradient-to-position);--tw-gradient-stops:var(--tw-gradient-from),var(--tw-gradient-to)}.from-\[\#081d49\]{--tw-gradient-from:#081d49 var(--tw-gradient-from-position);--tw-gradient-to:rgba(8,29,73,0) var(--tw-gradient-to-position);--tw-gradient-stops:var(--tw-gradient-from),var(--tw-gradient-to)}.from-\[\#0b2559\]{--tw-gradient-from:#0b2559 var(--tw-gradient-from-position);--tw-gradient-to:rgba(11,37,89,0) var(--tw-gradient-to-position);--tw-gradient-stops:var(--tw-gradient-from),var(--tw-gradient-to)}.via-\[\#092257\]{--tw-gradient-to:rgba(9,34,87,0) var(--tw-gradient-to-position);--tw-gradient-stops:var(--tw-gradient-from),#092257 var(--tw-gradient-via-position),var(--tw-gradient-to)}.to-\[\#020817\]{--tw-gradient-to:#020817 var(--tw-gradient-to-position)}.to-\[\#04112c\]{--tw-gradient-to:#04112c var(--tw-gradient-to-position)}.to-\[\#04122d\]{--tw-gradient-to:#04122d var(--tw-gradient-to-position)}.to-\[\#05112b\]{--tw-gradient-to:#05112b var(--tw-gradient-to-position)}.to-\[\#081e4b\]{--tw-gradient-to:#081e4b var(--tw-gradient-to-position)}.object-contain{-o-object-fit:contain;object-fit:contain}.p-1{padding:.25rem}.p-1\.5{padding:.375rem}.p-2{padding:.5rem}.p-3{padding:.75rem}.p-3\.5{padding:.875rem}.p-4{padding:1rem}.p-5{padding:1.25rem}.p-6{padding:1.5rem}.p-8{padding:2rem}.px-1\.5{padding-left:.375rem;padding-right:.375rem}.px-2{padding-left:.5rem;padding-right:.5rem}.px-2\.5{padding-left:.625rem;padding-right:.625rem}.px-3{padding-left:.75rem;padding-right:.75rem}.px-3\.5{padding-left:.875rem;padding-right:.875rem}.px-4{padding-left:1rem;padding-right:1rem}.px-5{padding-left:1.25rem;padding-right:1.25rem}.px-7{padding-left:1.75rem;padding-right:1.75rem}.py-0\.5{padding-top:.125rem;padding-bottom:.125rem}.py-1{padding-top:.25rem;padding-bottom:.25rem}.py-1\.5{padding-top:.375rem;padding-bottom:.375rem}.py-10{padding-top:2.5rem;padding-bottom:2.5rem}.py-12{padding-top:3rem;padding-bottom:3rem}.py-2{padding-top:.5rem;padding-bottom:.5rem}.py-2\.5{padding-top:.625rem;padding-bottom:.625rem}.py-3{padding-top:.75rem;padding-bottom:.75rem}.py-3\.5{padding-top:.875rem;padding-bottom:.875rem}.py-8{padding-top:2rem;padding-bottom:2rem}.pb-3{padding-bottom:.75rem}.pb-4{padding-bottom:1rem}.pb-5{padding-bottom:1.25rem}.pl-11{padding-left:2.75rem}.pr-11{padding-right:2.75rem}.pr-2{padding-right:.5rem}.pr-4{padding-right:1rem}.pt-0\.5{padding-top:.125rem}.pt-1{padding-top:.25rem}.pt-2{padding-top:.5rem}.pt-2\.5{padding-top:.625rem}.pt-3{padding-top:.75rem}.pt-5{padding-top:1.25rem}.pt-6{padding-top:1.5rem}.text-left{text-align:left}.text-center{text-align:center}.text-right{text-align:right}.font-mono{font-family:ui-monospace,SFMono-Regular,Menlo,Monaco,Consolas,Liberation Mono,Courier New,monospace}.font-sans{font-family:ui-sans-serif,system-ui,sans-serif,Apple Color Emoji,Segoe UI Emoji,Segoe UI Symbol,Noto Color Emoji}.text-2xl{font-size:1.5rem;line-height:2rem}.text-3xl{font-size:1.875rem;line-height:2.25rem}.text-4xl{font-size:2.25rem;line-height:2.5rem}.text-\[10px\]{font-size:10px}.text-\[11px\]{font-size:11px}.text-\[6px\]{font-size:6px}.text-\[9px\]{font-size:9px}.text-base{font-size:1rem;line-height:1.5rem}.text-lg{font-size:1.125rem;line-height:1.75rem}.text-sm{font-size:.875rem;line-height:1.25rem}.text-xl{font-size:1.25rem;line-height:1.75rem}.text-xs{font-size:.75rem;line-height:1rem}.font-black{font-weight:900}.font-bold{font-weight:700}.font-medium{font-weight:500}.font-semibold{font-weight:600}.uppercase{text-transform:uppercase}.leading-relaxed{line-height:1.625}.leading-tight{line-height:1.25}.tracking-wide{letter-spacing:.025em}.tracking-wider{letter-spacing:.05em}.tracking-widest{letter-spacing:.1em}.text-\[\#007BFF\]{--tw-text-opacity:1;color:rgb(0 123 255/var(--tw-text-opacity,1))}.text-\[\#00D89A\]{--tw-text-opacity:1;color:rgb(0 216 154/var(--tw-text-opacity,1))}.text-\[\#00D89A\]\/20{color:rgba(0,216,154,.2)}.text-\[\#00D9FF\]{--tw-text-opacity:1;color:rgb(0 217 255/var(--tw-text-opacity,1))}.text-\[\#00D9FF\]\/20{color:rgba(0,217,255,.2)}.text-\[\#020817\]{--tw-text-opacity:1;color:rgb(2 8 23/var(--tw-text-opacity,1))}.text-\[\#0284C7\]{--tw-text-opacity:1;color:rgb(2 132 199/var(--tw-text-opacity,1))}.text-\[\#061535\]{--tw-text-opacity:1;color:rgb(6 21 53/var(--tw-text-opacity,1))}.text-\[\#25D366\]{--tw-text-opacity:1;color:rgb(37 211 102/var(--tw-text-opacity,1))}.text-\[\#35F58A\]{--tw-text-opacity:1;color:rgb(53 245 138/var(--tw-text-opacity,1))}.text-\[\#35F58A\]\/20{color:rgba(53,245,138,.2)}.text-\[\#38BDF8\]{--tw-text-opacity:1;color:rgb(56 189 248/var(--tw-text-opacity,1))}.text-\[\#7C3AED\]{--tw-text-opacity:1;color:rgb(124 58 237/var(--tw-text-opacity,1))}.text-\[\#8B5CF6\]{--tw-text-opacity:1;color:rgb(139 92 246/var(--tw-text-opacity,1))}.text-\[\#E2136E\]{--tw-text-opacity:1;color:rgb(226 19 110/var(--tw-text-opacity,1))}.text-\[\#EA580C\]{--tw-text-opacity:1;color:rgb(234 88 12/var(--tw-text-opacity,1))}.text-\[\#F5F8FC\]{--tw-text-opacity:1;color:rgb(245 248 252/var(--tw-text-opacity,1))}.text-\[\#FBBF24\],.text-amber-400{--tw-text-opacity:1;color:rgb(251 191 36/var(--tw-text-opacity,1))}.text-cyan-400{--tw-text-opacity:1;color:rgb(34 211 238/var(--tw-text-opacity,1))}.text-emerald-400{--tw-text-opacity:1;color:rgb(52 211 153/var(--tw-text-opacity,1))}.text-rose-300{--tw-text-opacity:1;color:rgb(253 164 175/var(--tw-text-opacity,1))}.text-rose-400{--tw-text-opacity:1;color:rgb(251 113 133/var(--tw-text-opacity,1))}.text-slate-200{--tw-text-opacity:1;color:rgb(226 232 240/var(--tw-text-opacity,1))}.text-slate-300{--tw-text-opacity:1;color:rgb(203 213 225/var(--tw-text-opacity,1))}.text-slate-400{--tw-text-opacity:1;color:rgb(148 163 184/var(--tw-text-opacity,1))}.text-slate-500{--tw-text-opacity:1;color:rgb(100 116 139/var(--tw-text-opacity,1))}.text-white{--tw-text-opacity:1;color:rgb(255 255 255/var(--tw-text-opacity,1))}.line-through{text-decoration-line:line-through}.opacity-0{opacity:0}.opacity-100{opacity:1}.opacity-50{opacity:.5}.opacity-75{opacity:.75}.shadow-2xl{--tw-shadow:0 25px 50px -12px rgba(0,0,0,.25);--tw-shadow-colored:0 25px 50px -12px var(--tw-shadow-color);box-shadow:var(--tw-ring-offset-shadow,0 0 #0000),var(--tw-ring-shadow,0 0 #0000),var(--tw-shadow)}.shadow-\[0_0_10px_rgba\(0\2c 123\2c 255\2c 0\.4\)\]{--tw-shadow:0 0 10px rgba(0,123,255,.4);--tw-shadow-colored:0 0 10px var(--tw-shadow-color);box-shadow:var(--tw-ring-offset-shadow,0 0 #0000),var(--tw-ring-shadow,0 0 #0000),var(--tw-shadow)}.shadow-\[0_0_10px_rgba\(0\2c 216\2c 154\2c 0\.4\)\]{--tw-shadow:0 0 10px rgba(0,216,154,.4);--tw-shadow-colored:0 0 10px var(--tw-shadow-color);box-shadow:var(--tw-ring-offset-shadow,0 0 #0000),var(--tw-ring-shadow,0 0 #0000),var(--tw-shadow)}.shadow-\[0_0_10px_rgba\(0\2c 217\2c 255\2c 0\.2\)\]{--tw-shadow:0 0 10px rgba(0,217,255,.2);--tw-shadow-colored:0 0 10px var(--tw-shadow-color);box-shadow:var(--tw-ring-offset-shadow,0 0 #0000),var(--tw-ring-shadow,0 0 #0000),var(--tw-shadow)}.shadow-\[0_0_10px_rgba\(0\2c 217\2c 255\2c 0\.4\)\]{--tw-shadow:0 0 10px rgba(0,217,255,.4);--tw-shadow-colored:0 0 10px var(--tw-shadow-color);box-shadow:var(--tw-ring-offset-shadow,0 0 #0000),var(--tw-ring-shadow,0 0 #0000),var(--tw-shadow)}.shadow-\[0_0_10px_rgba\(139\2c 92\2c 246\2c 0\.4\)\]{--tw-shadow:0 0 10px rgba(139,92,246,.4);--tw-shadow-colored:0 0 10px var(--tw-shadow-color);box-shadow:var(--tw-ring-offset-shadow,0 0 #0000),var(--tw-ring-shadow,0 0 #0000),var(--tw-shadow)}.shadow-\[0_0_15px_rgba\(0\2c 217\2c 255\2c 0\.1\)\]{--tw-shadow:0 0 15px rgba(0,217,255,.1);--tw-shadow-colored:0 0 15px var(--tw-shadow-color);box-shadow:var(--tw-ring-offset-shadow,0 0 #0000),var(--tw-ring-shadow,0 0 #0000),var(--tw-shadow)}.shadow-\[0_0_15px_rgba\(0\2c 217\2c 255\2c 0\.15\)\]{--tw-shadow:0 0 15px rgba(0,217,255,.15);--tw-shadow-colored:0 0 15px var(--tw-shadow-color);box-shadow:var(--tw-ring-offset-shadow,0 0 #0000),var(--tw-ring-shadow,0 0 #0000),var(--tw-shadow)}.shadow-\[0_0_18px_rgba\(0\2c 216\2c 154\2c 0\.15\)\]{--tw-shadow:0 0 18px rgba(0,216,154,.15);--tw-shadow-colored:0 0 18px var(--tw-shadow-color);box-shadow:var(--tw-ring-offset-shadow,0 0 #0000),var(--tw-ring-shadow,0 0 #0000),var(--tw-shadow)}.shadow-\[0_0_20px_rgba\(0\2c 217\2c 255\2c 0\.12\)\]{--tw-shadow:0 0 20px rgba(0,217,255,.12);--tw-shadow-colored:0 0 20px var(--tw-shadow-color);box-shadow:var(--tw-ring-offset-shadow,0 0 #0000),var(--tw-ring-shadow,0 0 #0000),var(--tw-shadow)}.shadow-\[0_0_20px_rgba\(0\2c 217\2c 255\2c 0\.4\)\]{--tw-shadow:0 0 20px rgba(0,217,255,.4);--tw-shadow-colored:0 0 20px var(--tw-shadow-color);box-shadow:var(--tw-ring-offset-shadow,0 0 #0000),var(--tw-ring-shadow,0 0 #0000),var(--tw-shadow)}.shadow-\[0_0_25px_rgba\(0\2c 217\2c 255\2c 0\.3\)\]{--tw-shadow:0 0 25px rgba(0,217,255,.3);--tw-shadow-colored:0 0 25px var(--tw-shadow-color);box-shadow:var(--tw-ring-offset-shadow,0 0 #0000),var(--tw-ring-shadow,0 0 #0000),var(--tw-shadow)}.shadow-\[0_0_8px_rgba\(0\2c 216\2c 154\2c 0\.8\)\]{--tw-shadow:0 0 8px rgba(0,216,154,.8);--tw-shadow-colored:0 0 8px var(--tw-shadow-color)}.shadow-\[0_0_8px_rgba\(0\2c 216\2c 154\2c 0\.8\)\],.shadow-lg{box-shadow:var(--tw-ring-offset-shadow,0 0 #0000),var(--tw-ring-shadow,0 0 #0000),var(--tw-shadow)}.shadow-lg{--tw-shadow:0 10px 15px -3px rgba(0,0,0,.1),0 4px 6px -4px rgba(0,0,0,.1);--tw-shadow-colored:0 10px 15px -3px var(--tw-shadow-color),0 4px 6px -4px var(--tw-shadow-color)}.shadow-md{--tw-shadow:0 4px 6px -1px rgba(0,0,0,.1),0 2px 4px -2px rgba(0,0,0,.1);--tw-shadow-colored:0 4px 6px -1px var(--tw-shadow-color),0 2px 4px -2px var(--tw-shadow-color)}.shadow-md,.shadow-sm{box-shadow:var(--tw-ring-offset-shadow,0 0 #0000),var(--tw-ring-shadow,0 0 #0000),var(--tw-shadow)}.shadow-sm{--tw-shadow:0 1px 2px 0 rgba(0,0,0,.05);--tw-shadow-colored:0 1px 2px 0 var(--tw-shadow-color)}.shadow-xl{--tw-shadow:0 20px 25px -5px rgba(0,0,0,.1),0 8px 10px -6px rgba(0,0,0,.1);--tw-shadow-colored:0 20px 25px -5px var(--tw-shadow-color),0 8px 10px -6px var(--tw-shadow-color);box-shadow:var(--tw-ring-offset-shadow,0 0 #0000),var(--tw-ring-shadow,0 0 #0000),var(--tw-shadow)}.shadow-black\/40{--tw-shadow-color:rgba(0,0,0,.4);--tw-shadow:var(--tw-shadow-colored)}.shadow-cyan-500\/10{--tw-shadow-color:rgba(6,182,212,.1);--tw-shadow:var(--tw-shadow-colored)}.shadow-cyan-500\/20{--tw-shadow-color:rgba(6,182,212,.2);--tw-shadow:var(--tw-shadow-colored)}.shadow-cyan-900\/10{--tw-shadow-color:rgba(22,78,99,.1);--tw-shadow:var(--tw-shadow-colored)}.blur{--tw-blur:blur(8px);filter:var(--tw-blur) var(--tw-brightness) var(--tw-contrast) var(--tw-grayscale) var(--tw-hue-rotate) var(--tw-invert) var(--tw-saturate) var(--tw-sepia) var(--tw-drop-shadow)}.backdrop-blur-2xl{--tw-backdrop-blur:blur(40px)}.backdrop-blur-2xl,.backdrop-blur-lg{-webkit-backdrop-filter:var(--tw-backdrop-blur) var(--tw-backdrop-brightness) var(--tw-backdrop-contrast) var(--tw-backdrop-grayscale) var(--tw-backdrop-hue-rotate) var(--tw-backdrop-invert) var(--tw-backdrop-opacity) var(--tw-backdrop-saturate) var(--tw-backdrop-sepia);backdrop-filter:var(--tw-backdrop-blur) var(--tw-backdrop-brightness) var(--tw-backdrop-contrast) var(--tw-backdrop-grayscale) var(--tw-backdrop-hue-rotate) var(--tw-backdrop-invert) var(--tw-backdrop-opacity) var(--tw-backdrop-saturate) var(--tw-backdrop-sepia)}.backdrop-blur-lg{--tw-backdrop-blur:blur(16px)}.backdrop-blur-md{--tw-backdrop-blur:blur(12px)}.backdrop-blur-md,.backdrop-blur-sm{-webkit-backdrop-filter:var(--tw-backdrop-blur) var(--tw-backdrop-brightness) var(--tw-backdrop-contrast) var(--tw-backdrop-grayscale) var(--tw-backdrop-hue-rotate) var(--tw-backdrop-invert) var(--tw-backdrop-opacity) var(--tw-backdrop-saturate) var(--tw-backdrop-sepia);backdrop-filter:var(--tw-backdrop-blur) var(--tw-backdrop-brightness) var(--tw-backdrop-contrast) var(--tw-backdrop-grayscale) var(--tw-backdrop-hue-rotate) var(--tw-backdrop-invert) var(--tw-backdrop-opacity) var(--tw-backdrop-saturate) var(--tw-backdrop-sepia)}.backdrop-blur-sm{--tw-backdrop-blur:blur(4px)}.backdrop-blur-xl{--tw-backdrop-blur:blur(24px)}.backdrop-blur-xl,.backdrop-filter{-webkit-backdrop-filter:var(--tw-backdrop-blur) var(--tw-backdrop-brightness) var(--tw-backdrop-contrast) var(--tw-backdrop-grayscale) var(--tw-backdrop-hue-rotate) var(--tw-backdrop-invert) var(--tw-backdrop-opacity) var(--tw-backdrop-saturate) var(--tw-backdrop-sepia);backdrop-filter:var(--tw-backdrop-blur) var(--tw-backdrop-brightness) var(--tw-backdrop-contrast) var(--tw-backdrop-grayscale) var(--tw-backdrop-hue-rotate) var(--tw-backdrop-invert) var(--tw-backdrop-opacity) var(--tw-backdrop-saturate) var(--tw-backdrop-sepia)}.transition{transition-property:color,background-color,border-color,text-decoration-color,fill,stroke,opacity,box-shadow,transform,filter,-webkit-backdrop-filter;transition-property:color,background-color,border-color,text-decoration-color,fill,stroke,opacity,box-shadow,transform,filter,backdrop-filter;transition-property:color,background-color,border-color,text-decoration-color,fill,stroke,opacity,box-shadow,transform,filter,backdrop-filter,-webkit-backdrop-filter;transition-timing-function:cubic-bezier(.4,0,.2,1);transition-duration:.15s}.transition-all{transition-property:all;transition-timing-function:cubic-bezier(.4,0,.2,1);transition-duration:.15s}.transition-opacity{transition-property:opacity;transition-timing-function:cubic-bezier(.4,0,.2,1);transition-duration:.15s}.transition-transform{transition-property:transform;transition-timing-function:cubic-bezier(.4,0,.2,1);transition-duration:.15s}.duration-200{transition-duration:.2s}.duration-300{transition-duration:.3s}.ease-in-out{transition-timing-function:cubic-bezier(.4,0,.2,1)}.selection\:bg-\[\#00D9FF\] ::-moz-selection{--tw-bg-opacity:1;background-color:rgb(0 217 255/var(--tw-bg-opacity,1))}.selection\:bg-\[\#00D9FF\] ::selection{--tw-bg-opacity:1;background-color:rgb(0 217 255/var(--tw-bg-opacity,1))}.selection\:text-\[\#020817\] ::-moz-selection{--tw-text-opacity:1;color:rgb(2 8 23/var(--tw-text-opacity,1))}.selection\:text-\[\#020817\] ::selection{--tw-text-opacity:1;color:rgb(2 8 23/var(--tw-text-opacity,1))}.selection\:text-\[\#061535\] ::-moz-selection{--tw-text-opacity:1;color:rgb(6 21 53/var(--tw-text-opacity,1))}.selection\:text-\[\#061535\] ::selection{--tw-text-opacity:1;color:rgb(6 21 53/var(--tw-text-opacity,1))}.selection\:bg-\[\#00D9FF\]::-moz-selection{--tw-bg-opacity:1;background-color:rgb(0 217 255/var(--tw-bg-opacity,1))}.selection\:bg-\[\#00D9FF\]::selection{--tw-bg-opacity:1;background-color:rgb(0 217 255/var(--tw-bg-opacity,1))}.selection\:text-\[\#020817\]::-moz-selection{--tw-text-opacity:1;color:rgb(2 8 23/var(--tw-text-opacity,1))}.selection\:text-\[\#020817\]::selection{--tw-text-opacity:1;color:rgb(2 8 23/var(--tw-text-opacity,1))}.selection\:text-\[\#061535\]::-moz-selection{--tw-text-opacity:1;color:rgb(6 21 53/var(--tw-text-opacity,1))}.selection\:text-\[\#061535\]::selection{--tw-text-opacity:1;color:rgb(6 21 53/var(--tw-text-opacity,1))}.file\:mr-4::file-selector-button{margin-right:1rem}.file\:rounded-xl::file-selector-button{border-radius:.75rem}.file\:border-0::file-selector-button{border-width:0}.file\:bg-\[\#007BFF\]::file-selector-button{--tw-bg-opacity:1;background-color:rgb(0 123 255/var(--tw-bg-opacity,1))}.file\:px-4::file-selector-button{padding-left:1rem;padding-right:1rem}.file\:py-2::file-selector-button{padding-top:.5rem;padding-bottom:.5rem}.file\:py-2\.5::file-selector-button{padding-top:.625rem;padding-bottom:.625rem}.file\:text-xs::file-selector-button{font-size:.75rem;line-height:1rem}.file\:font-semibold::file-selector-button{font-weight:600}.file\:text-white::file-selector-button{--tw-text-opacity:1;color:rgb(255 255 255/var(--tw-text-opacity,1))}.after\:absolute:after{content:var(--tw-content);position:absolute}.after\:left-\[2px\]:after{content:var(--tw-content);left:2px}.after\:top-\[2px\]:after{content:var(--tw-content);top:2px}.after\:h-5:after{content:var(--tw-content);height:1.25rem}.after\:w-5:after{content:var(--tw-content);width:1.25rem}.after\:rounded-full:after{content:var(--tw-content);border-radius:9999px}.after\:border:after{content:var(--tw-content);border-width:1px}.after\:border-gray-300:after{content:var(--tw-content);--tw-border-opacity:1;border-color:rgb(209 213 219/var(--tw-border-opacity,1))}.after\:bg-white:after{content:var(--tw-content);--tw-bg-opacity:1;background-color:rgb(255 255 255/var(--tw-bg-opacity,1))}.after\:transition-all:after{content:var(--tw-content);transition-property:all;transition-timing-function:cubic-bezier(.4,0,.2,1);transition-duration:.15s}.after\:content-\[\'\'\]:after{--tw-content:"";content:var(--tw-content)}.hover\:border-\[\#00D9FF\]:hover{--tw-border-opacity:1;border-color:rgb(0 217 255/var(--tw-border-opacity,1))}.hover\:border-\[\#00D9FF\]\/40:hover{border-color:rgba(0,217,255,.4)}.hover\:border-\[\#0284C7\]:hover{--tw-border-opacity:1;border-color:rgb(2 132 199/var(--tw-border-opacity,1))}.hover\:border-\[\#7C3AED\]:hover{--tw-border-opacity:1;border-color:rgb(124 58 237/var(--tw-border-opacity,1))}.hover\:border-\[\#E2136E\]:hover{--tw-border-opacity:1;border-color:rgb(226 19 110/var(--tw-border-opacity,1))}.hover\:border-\[\#EA580C\]:hover{--tw-border-opacity:1;border-color:rgb(234 88 12/var(--tw-border-opacity,1))}.hover\:border-amber-400:hover{--tw-border-opacity:1;border-color:rgb(251 191 36/var(--tw-border-opacity,1))}.hover\:bg-\[\#007BFF\]:hover{--tw-bg-opacity:1;background-color:rgb(0 123 255/var(--tw-bg-opacity,1))}.hover\:bg-\[\#00D9FF\]\/20:hover{background-color:rgba(0,217,255,.2)}.hover\:bg-\[\#051433\]\/60:hover{background-color:rgba(5,20,51,.6)}.hover\:bg-\[\#092257\]:hover{--tw-bg-opacity:1;background-color:rgb(9 34 87/var(--tw-bg-opacity,1))}.hover\:bg-\[\#0c245c\]:hover{--tw-bg-opacity:1;background-color:rgb(12 36 92/var(--tw-bg-opacity,1))}.hover\:bg-\[\#0c2e75\]:hover{--tw-bg-opacity:1;background-color:rgb(12 46 117/var(--tw-bg-opacity,1))}.hover\:bg-\[\#0f2d6e\]:hover{--tw-bg-opacity:1;background-color:rgb(15 45 110/var(--tw-bg-opacity,1))}.hover\:bg-rose-500\/30:hover{background-color:rgba(244,63,94,.3)}.hover\:bg-slate-800:hover{--tw-bg-opacity:1;background-color:rgb(30 41 59/var(--tw-bg-opacity,1))}.hover\:text-\[\#00D89A\]:hover{--tw-text-opacity:1;color:rgb(0 216 154/var(--tw-text-opacity,1))}.hover\:text-\[\#00D9FF\]:hover{--tw-text-opacity:1;color:rgb(0 217 255/var(--tw-text-opacity,1))}.hover\:text-rose-300:hover{--tw-text-opacity:1;color:rgb(253 164 175/var(--tw-text-opacity,1))}.hover\:text-rose-400:hover{--tw-text-opacity:1;color:rgb(251 113 133/var(--tw-text-opacity,1))}.hover\:text-white:hover{--tw-text-opacity:1;color:rgb(255 255 255/var(--tw-text-opacity,1))}.hover\:underline:hover{text-decoration-line:underline}.hover\:file\:bg-\[\#0056b3\]::file-selector-button:hover{--tw-bg-opacity:1;background-color:rgb(0 86 179/var(--tw-bg-opacity,1))}.focus\:border-\[\#00D89A\]:focus{--tw-border-opacity:1;border-color:rgb(0 216 154/var(--tw-border-opacity,1))}.focus\:border-\[\#00D9FF\]:focus{--tw-border-opacity:1;border-color:rgb(0 217 255/var(--tw-border-opacity,1))}.focus\:border-amber-400:focus{--tw-border-opacity:1;border-color:rgb(251 191 36/var(--tw-border-opacity,1))}.focus\:outline-none:focus{outline:2px solid transparent;outline-offset:2px}.active\:scale-95:active{--tw-scale-x:.95;--tw-scale-y:.95;transform:translate(var(--tw-translate-x),var(--tw-translate-y)) rotate(var(--tw-rotate)) skewX(var(--tw-skew-x)) skewY(var(--tw-skew-y)) scaleX(var(--tw-scale-x)) scaleY(var(--tw-scale-y))}.group:hover .group-hover\:text-\[\#00D89A\]{--tw-text-opacity:1;color:rgb(0 216 154/var(--tw-text-opacity,1))}.group:hover .group-hover\:text-\[\#00D9FF\]{--tw-text-opacity:1;color:rgb(0 217 255/var(--tw-text-opacity,1))}.group:hover .group-hover\:text-amber-400{--tw-text-opacity:1;color:rgb(251 191 36/var(--tw-text-opacity,1))}.peer:checked~.peer-checked\:border-\[\#00D9FF\]{--tw-border-opacity:1;border-color:rgb(0 217 255/var(--tw-border-opacity,1))}.peer:checked~.peer-checked\:bg-\[\#00D89A\]{--tw-bg-opacity:1;background-color:rgb(0 216 154/var(--tw-bg-opacity,1))}.peer:checked~.peer-checked\:bg-\[\#00D9FF\]\/10{background-color:rgba(0,217,255,.1)}.peer:checked~.peer-checked\:text-\[\#00D9FF\]{--tw-text-opacity:1;color:rgb(0 217 255/var(--tw-text-opacity,1))}.peer:checked~.peer-checked\:after\:translate-x-full:after{content:var(--tw-content);--tw-translate-x:100%;transform:translate(var(--tw-translate-x),var(--tw-translate-y)) rotate(var(--tw-rotate)) skewX(var(--tw-skew-x)) skewY(var(--tw-skew-y)) scaleX(var(--tw-scale-x)) scaleY(var(--tw-scale-y))}.peer:checked~.peer-checked\:after\:border-white:after{content:var(--tw-content);--tw-border-opacity:1;border-color:rgb(255 255 255/var(--tw-border-opacity,1))}.peer:focus~.peer-focus\:outline-none{outline:2px solid transparent;outline-offset:2px}@media (min-width:640px){.sm\:col-span-2{grid-column:span 2/span 2}.sm\:inline{display:inline}.sm\:w-auto{width:auto}.sm\:grid-cols-2{grid-template-columns:repeat(2,minmax(0,1fr))}.sm\:grid-cols-4{grid-template-columns:repeat(4,minmax(0,1fr))}.sm\:grid-cols-5{grid-template-columns:repeat(5,minmax(0,1fr))}.sm\:flex-row{flex-direction:row}.sm\:items-center{align-items:center}.sm\:justify-end{justify-content:flex-end}.sm\:border-t-0{border-top-width:0}.sm\:p-8{padding:2rem}.sm\:px-4{padding-left:1rem;padding-right:1rem}.sm\:pt-0{padding-top:0}.sm\:text-right{text-align:right}.sm\:text-sm{font-size:.875rem;line-height:1.25rem}}@media (min-width:768px){.md\:col-span-3{grid-column:span 3/span 3}.md\:mx-0{margin-left:0;margin-right:0}.md\:ml-72{margin-left:18rem}.md\:flex{display:flex}.md\:hidden{display:none}.md\:w-1\/2{width:50%}.md\:w-auto{width:auto}.md\:flex-none{flex:none}.md\:translate-x-0{--tw-translate-x:0px;transform:translate(var(--tw-translate-x),var(--tw-translate-y)) rotate(var(--tw-rotate)) skewX(var(--tw-skew-x)) skewY(var(--tw-skew-y)) scaleX(var(--tw-scale-x)) scaleY(var(--tw-scale-y))}.md\:grid-cols-2{grid-template-columns:repeat(2,minmax(0,1fr))}.md\:grid-cols-3{grid-template-columns:repeat(3,minmax(0,1fr))}.md\:grid-cols-4{grid-template-columns:repeat(4,minmax(0,1fr))}.md\:flex-row{flex-direction:row}.md\:items-center{align-items:center}.md\:justify-start{justify-content:flex-start}.md\:gap-4{gap:1rem}.md\:p-10{padding:2.5rem}.md\:p-12{padding:3rem}.md\:p-5{padding:1.25rem}.md\:p-7{padding:1.75rem}.md\:p-8{padding:2rem}.md\:px-10{padding-left:2.5rem;padding-right:2.5rem}.md\:px-8{padding-left:2rem;padding-right:2rem}.md\:py-16{padding-top:4rem;padding-bottom:4rem}.md\:text-left{text-align:left}.md\:text-2xl{font-size:1.5rem;line-height:2rem}.md\:text-3xl{font-size:1.875rem;line-height:2.25rem}.md\:text-5xl{font-size:3rem;line-height:1}.md\:text-\[11px\]{font-size:11px}.md\:text-base{font-size:1rem;line-height:1.5rem}.md\:text-lg{font-size:1.125rem;line-height:1.75rem}.md\:text-sm{font-size:.875rem;line-height:1.25rem}}</style>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <style>
        body { touch-action: manipulation; }
        .snz-gradient { background: linear-gradient(135deg, #007BFF 0%, #00D9FF 50%, #00D89A 100%); }
        .active-tab {
            background: linear-gradient(135deg, #007BFF 0%, #00D9FF 50%, #00D89A 100%) !important;
            color: #061535 !important;
            font-weight: 800 !important;
            box-shadow: 0 4px 15px -3px rgba(0, 217, 255, 0.4);
        }
        .no-scrollbar::-webkit-scrollbar { display: none; }

        /* ☰ Admin drawer */
        .adm-menu-btn { width: 42px; height: 42px; border-radius: 14px; background: #041433; border: 1.5px solid #00D9FF;
            color: #00D9FF; font-size: 18px; display: flex; align-items: center; justify-content: center;
            box-shadow: 0 0 12px rgba(0,217,255,.35); flex-shrink: 0; }
        .adm-menu-btn:active { transform: scale(.95); }
        .adm-overlay { position: fixed; inset: 0; background: rgba(2,8,23,.7); backdrop-filter: blur(4px); -webkit-backdrop-filter: blur(4px);
            z-index: 55; opacity: 0; pointer-events: none; transition: opacity .25s; }
        .adm-overlay.open { opacity: 1; pointer-events: auto; }
        .adm-drawer { position: fixed; top: 0; left: 0; bottom: 0; width: 290px; max-width: 86vw; z-index: 60;
            background: linear-gradient(180deg, #0a1f4d 0%, #040f2a 100%); border-right: 1px solid #143985;
            box-shadow: 10px 0 40px rgba(0,0,0,.5); transform: translateX(-105%); transition: transform .28s cubic-bezier(.16,1,.3,1);
            display: flex; flex-direction: column; }
        .adm-drawer.open { transform: translateX(0); }
        .adm-drawer-head { padding: 18px 16px; border-bottom: 1px solid #0d265c; display: flex; align-items: center; justify-content: space-between; }
        .adm-close { width: 34px; height: 34px; border-radius: 10px; color: #94a3b8; background: #051433; border: 1px solid #143985; }
        .adm-nav { flex: 1; overflow-y: auto; padding: 8px 12px 16px; }
        .adm-nav-label { font-size: 10px; font-weight: 800; letter-spacing: .14em; text-transform: uppercase; color: #4b6a9b; padding: 14px 10px 6px; }
        .adm-nav-item { width: 100%; display: flex; align-items: center; gap: 12px; padding: 11px 14px; margin-bottom: 4px;
            border-radius: 14px; font-size: 13px; font-weight: 700; color: #cbd5e1; text-align: left; transition: background .15s; }
        .adm-nav-item i { width: 20px; text-align: center; font-size: 15px; }
        .adm-nav-item:hover { background: #0b245a; color: #fff; }
        .adm-drawer-foot { padding: 12px; border-top: 1px solid #0d265c; display: grid; grid-template-columns: 1fr 1fr; gap: 8px; }
        .adm-foot-btn { display: flex; align-items: center; justify-content: center; gap: 6px; padding: 10px; border-radius: 12px;
            background: #071a44; border: 1px solid #143985; color: #e2e8f0; font-size: 12px; font-weight: 700; }
        .adm-foot-danger { color: #fca5a5; }
        .no-scrollbar { -ms-overflow-style: none; scrollbar-width: none; }
    </style>
</head>
<body class="bg-[#061535] text-[#F5F8FC] min-h-screen font-sans overflow-x-hidden selection:bg-[#00D9FF] selection:text-[#061535]">

    <!-- 2-Second Floating Toast -->
    <div id="universalToast" class="fixed top-5 left-1/2 -translate-x-1/2 z-50 w-[90%] max-w-sm bg-[#081b42]/95 backdrop-blur-md border border-[#00D9FF]/40 shadow-2xl rounded-2xl p-4 flex items-center gap-3.5 transition-all duration-300 opacity-0 -translate-y-8 pointer-events-none">
        <div id="toastIconContainer" class="w-10 h-10 rounded-xl flex items-center justify-center text-lg shrink-0"></div>
        <div class="flex-1 overflow-hidden">
            <h4 id="toastTitle" class="text-[11px] font-bold uppercase tracking-wider text-[#00D9FF]"></h4>
            <p id="toastMessage" class="text-sm font-medium text-white truncate"></p>
        </div>
    </div>

    <!-- Master Header -->
    <header class="bg-[#061535]/90 backdrop-blur-lg border-b border-[#0d265c] px-4 md:px-8 py-3.5 flex justify-between items-center sticky top-0 z-40 shadow-xl">
        <div class="flex items-center gap-3">
            <button type="button" onclick="toggleAdminDrawer(true)" class="adm-menu-btn" aria-label="Open menu">
                <i class="fa-solid fa-bars"></i>
            </button>
            <img src="<?= htmlspecialchars($SITE_LOGO) ?>" onerror="this.onerror=null;this.src='data:image/svg+xml,%3Csvg xmlns=%22http://www.w3.org/2000/svg%22 viewBox=%220 0 64 64%22%3E%3Cdefs%3E%3ClinearGradient id=%22g%22 x1=%220%22 y1=%220%22 x2=%221%22 y2=%221%22%3E%3Cstop offset=%220%22 stop-color=%22%23007BFF%22/%3E%3Cstop offset=%22.5%22 stop-color=%22%2300D9FF%22/%3E%3Cstop offset=%221%22 stop-color=%22%2300D89A%22/%3E%3C/linearGradient%3E%3C/defs%3E%3Crect width=%2264%22 height=%2264%22 rx=%2216%22 fill=%22url(%23g)%22/%3E%3Ctext x=%2232%22 y=%2244%22 font-family=%22Arial%22 font-weight=%22900%22 font-size=%2234%22 text-anchor=%22middle%22 fill=%22%23061535%22%3ES%3C/text%3E%3C/svg%3E'" alt="<?= htmlspecialchars($SITE_NAME) ?> Logo" class="w-9 h-9 object-contain shrink-0">
            <div>
                <h1 class="text-base font-black text-white tracking-wider flex items-center gap-2">
                    <?= snzBrandHtml($SITE_NAME) ?> <span class="text-[9px] bg-[#00D9FF]/20 text-[#00D9FF] border border-[#00D9FF]/30 px-2 py-0.5 rounded-full font-bold">ADMIN PRO</span>
                </h1>
                <p class="text-[10px] text-slate-400">Master Cloud Controller</p>
            </div>
        </div>
        <div class="flex items-center gap-3">
            <a href="dashboard.php" target="_blank" class="text-xs bg-[#092257] hover:bg-[#0c2e75] text-white px-3.5 py-2 rounded-xl transition flex items-center gap-1.5 border border-[#174396]">
                <i class="fa-solid fa-arrow-up-right-from-square text-[10px] text-[#00D9FF]"></i> <span class="hidden sm:inline">User Portal</span>
            </a>
            <a href="?logout=1" class="text-xs bg-[#0a1e4a] hover:bg-[#0f2d6e] text-slate-300 border border-[#143985] px-3.5 py-2 rounded-xl font-bold transition flex items-center gap-1.5">
                <i class="fa-solid fa-arrow-right-from-bracket"></i> <span class="hidden sm:inline">Logout</span>
            </a>
        </div>
    </header>

    <!-- ☰ মেনু (সাইড ড্রয়ার) — উপরের লম্বা বাটনের সারির বদলে -->
    <div id="admOverlay" class="adm-overlay" onclick="toggleAdminDrawer(false)"></div>
    <aside id="admDrawer" class="adm-drawer">
        <div class="adm-drawer-head">
            <div class="flex items-center gap-2.5">
                <div class="w-9 h-9 rounded-xl snz-gradient flex items-center justify-center text-[#061535] font-black">⚙</div>
                <div>
                    <div class="text-sm font-black text-white">Admin Menu</div>
                    <div class="text-[10px] text-slate-400">Choose a section</div>
                </div>
            </div>
            <button type="button" onclick="toggleAdminDrawer(false)" class="adm-close" aria-label="Close menu"><i class="fa-solid fa-xmark"></i></button>
        </div>
        <nav class="adm-nav">
            <div class="adm-nav-label">Main</div>
                <button onclick="switchSection('sec-overview')" id="tab-sec-overview" class="nav-tab adm-nav-item">
                <i class="fa-solid fa-chart-pie text-sm"></i> Overview
                </button>
                <button onclick="switchSection('sec-deposits')" id="tab-sec-deposits" class="nav-tab adm-nav-item">
                <i class="fa-solid fa-inbox text-sm text-amber-400"></i> Deposit Requests<?php if (count($pendingDeposits)): ?> <span class="ml-auto text-[10px] font-black px-2 py-0.5 rounded-full bg-amber-400 text-[#061535]"><?= count($pendingDeposits) ?></span><?php endif; ?>
            </button>
                <button onclick="switchSection('sec-autopay')" id="tab-sec-autopay" class="nav-tab adm-nav-item">
                <i class="fa-solid fa-bolt text-sm text-[#00D89A]"></i> Auto Pay <span class="ml-auto text-[9px] font-black px-2 py-0.5 rounded-full <?= $autoPayEnabled ? 'bg-[#00D89A]/20 text-[#00D89A]' : 'bg-slate-700 text-slate-300' ?>"><?= $autoPayEnabled ? 'ON' : 'OFF' ?></span>
            </button>
                <button onclick="switchSection('sec-licenses')" id="tab-sec-licenses" class="nav-tab adm-nav-item">
                <i class="fa-solid fa-list-check text-sm text-cyan-400"></i> All Licenses
                </button>
                <button onclick="switchSection('sec-users')" id="tab-sec-users" class="nav-tab adm-nav-item">
                <i class="fa-solid fa-users text-sm text-emerald-400"></i> All Users
                </button>
            <div class="adm-nav-label">Manage</div>
                <button onclick="switchSection('sec-plans')" id="tab-sec-plans" class="nav-tab adm-nav-item">
                <i class="fa-solid fa-layer-group text-sm text-[#00D9FF]"></i> Plans
            </button>
                <button onclick="switchSection('sec-create-key')" id="tab-sec-create-key" class="nav-tab adm-nav-item">
                <i class="fa-solid fa-key text-sm text-[#00D9FF]"></i> Create License
                </button>
                <button onclick="switchSection('sec-balance')" id="tab-sec-balance" class="nav-tab adm-nav-item">
                <i class="fa-solid fa-wallet text-sm text-[#00D89A]"></i> Add Balance
                </button>
                <button onclick="switchSection('sec-payments')" id="tab-sec-payments" class="nav-tab adm-nav-item">
                <i class="fa-solid fa-credit-card text-sm text-[#007BFF]"></i> Payment Setup
                </button>
            <div class="adm-nav-label">Android App</div>
                <button onclick="switchSection('sec-apk')" id="tab-sec-apk" class="nav-tab adm-nav-item">
                <i class="fa-brands fa-android text-sm text-[#35F58A]"></i> APK Manager (Any MB)
                </button>
                <button onclick="switchSection('sec-app')" id="tab-sec-app" class="nav-tab adm-nav-item">
                <i class="fa-solid fa-mobile-screen text-sm text-[#35F58A]"></i> App Settings
                </button>
                <button onclick="switchSection('sec-sms-rules')" id="tab-sec-sms-rules" class="nav-tab adm-nav-item">
                <i class="fa-solid fa-comment-sms text-sm text-[#00D9FF]"></i> SMS Rules
                </button>
            <div class="adm-nav-label">Website</div>
                <button onclick="switchSection('sec-site')" id="tab-sec-site" class="nav-tab adm-nav-item">
                <i class="fa-solid fa-gear text-sm text-amber-400"></i> Site Settings
                </button>
        </nav>
        <div class="adm-drawer-foot">
            <a href="dashboard.php" target="_blank" class="adm-foot-btn"><i class="fa-solid fa-arrow-up-right-from-square text-[#00D9FF]"></i> User Portal</a>
            <a href="?logout=1" class="adm-foot-btn adm-foot-danger"><i class="fa-solid fa-arrow-right-from-bracket"></i> Logout</a>
        </div>
    </aside>

    <!-- বর্তমান পেজের নাম -->
    <div class="max-w-6xl mx-auto px-4 md:px-8 pt-5 flex items-center gap-2.5">
        <span id="admSectionIcon" class="w-8 h-8 rounded-xl bg-[#00D9FF]/15 text-[#00D9FF] flex items-center justify-center text-sm"><i class="fa-solid fa-chart-pie"></i></span>
        <h2 id="admSectionTitle" class="text-base font-black text-white">Overview</h2>
    </div>

    <main class="p-4 md:p-8 max-w-6xl mx-auto space-y-6">

        <!-- SECTION 1: OVERVIEW -->
        <section id="sec-overview" class="admin-section space-y-6">
            <div class="grid grid-cols-2 md:grid-cols-4 gap-4">
                <div class="bg-[#081e4b] border border-[#123982] p-5 rounded-3xl shadow-xl">
                    <div class="w-10 h-10 rounded-2xl bg-[#007BFF]/20 text-[#007BFF] flex items-center justify-center text-lg mb-3">
                        <i class="fa-solid fa-users"></i>
                    </div>
                    <span class="text-xs text-slate-300 block font-medium">Total Registered Users</span>
                    <span class="text-2xl font-black text-white mt-1 block"><?= count($allUsers) ?></span>
                </div>

                <div class="bg-[#081e4b] border border-[#123982] p-5 rounded-3xl shadow-xl">
                    <div class="w-10 h-10 rounded-2xl bg-[#00D9FF]/20 text-[#00D9FF] flex items-center justify-center text-lg mb-3">
                        <i class="fa-solid fa-key"></i>
                    </div>
                    <span class="text-xs text-slate-300 block font-medium">Generated Licenses</span>
                    <span class="text-2xl font-black text-white mt-1 block"><?= $totalKeys ?></span>
                </div>

                <div class="bg-[#081e4b] border border-[#123982] p-5 rounded-3xl shadow-xl">
                    <div class="w-10 h-10 rounded-2xl bg-[#00D89A]/20 text-[#00D89A] flex items-center justify-center text-lg mb-3">
                        <i class="fa-solid fa-coins"></i>
                    </div>
                    <span class="text-xs text-slate-300 block font-medium">Total User Funds</span>
                    <span class="text-2xl font-black text-[#00D89A] mt-1 block">৳ <?= number_format($totalBalance, 2) ?></span>
                </div>

                <div class="bg-[#081e4b] border border-[#123982] p-5 rounded-3xl shadow-xl">
                    <div class="w-10 h-10 rounded-2xl bg-[#35F58A]/20 text-[#35F58A] flex items-center justify-center text-lg mb-3">
                        <i class="fa-brands fa-android"></i>
                    </div>
                    <span class="text-xs text-slate-300 block font-medium">Current APK Size</span>
                    <span id="overviewApkSize" class="text-lg font-black text-white mt-1 block"><?= $apkSize ?></span>
                </div>
            </div>
        </section>

        <!-- SECTION 2: CHUNKED APK UPLOADER (ANY MB SIZE) -->
        <section id="sec-apk" class="admin-section hidden space-y-6">
            <div class="bg-[#081e4b] border border-[#123982] rounded-3xl p-6 md:p-8 shadow-2xl max-w-xl mx-auto">
                <div class="flex items-center justify-between mb-6 pb-4 border-b border-[#0d265c]">
                    <div class="flex items-center gap-3">
                        <div class="w-10 h-10 rounded-2xl bg-[#35F58A]/15 text-[#35F58A] flex items-center justify-center text-lg">
                            <i class="fa-brands fa-android"></i>
                        </div>
                        <div>
                            <h2 class="text-base font-bold text-white">Upload New App (ANY Size APK)</h2>
                            <p class="text-xs text-slate-300">Chunked upload bypasses all hosting limits (5MB, 20MB, 50MB+).</p>
                        </div>
                    </div>
                    <span id="currentApkBadge" class="text-xs bg-[#051433] text-[#00D89A] px-3 py-1 rounded-full border border-[#0d2a63] font-mono"><?= $apkSize ?></span>
                </div>

                <div class="space-y-4">
                    <div class="border-2 border-dashed border-[#143985] hover:border-[#00D9FF] rounded-3xl p-6 text-center cursor-pointer transition">
                        <i class="fa-solid fa-cloud-arrow-up text-3xl text-[#00D9FF] mb-2"></i>
                        <p class="text-xs text-slate-300 mb-2">Select your APK file of any size:</p>
                        <input type="file" id="apkFileInput" accept=".apk" class="w-full text-xs text-slate-400 file:mr-4 file:py-2.5 file:px-4 file:rounded-xl file:border-0 file:text-xs file:font-semibold file:bg-[#007BFF] file:text-white hover:file:bg-[#0056b3]">
                    </div>

                    <!-- Progress Bar Container -->
                    <div id="uploadProgressBox" class="hidden space-y-2 bg-[#051433] p-4 rounded-2xl border border-[#0d2a63]">
                        <div class="flex justify-between text-xs text-slate-300">
                            <span id="uploadStatusText">Uploading chunk...</span>
                            <span id="uploadPercentText" class="font-bold text-[#00D9FF]">0%</span>
                        </div>
                        <div class="w-full bg-[#061535] rounded-full h-2.5 overflow-hidden border border-[#0e306e]">
                            <div id="uploadProgressBar" class="snz-gradient h-2.5 rounded-full transition-all duration-200" style="width: 0%"></div>
                        </div>
                    </div>

                    <div class="bg-[#051433] p-3 rounded-2xl border border-[#0d2a63] text-[11px] text-slate-400 flex justify-between">
                        <span>Last Uploaded:</span>
                        <b id="apkLastTime" class="text-slate-200 font-mono"><?= $apkTime ?></b>
                    </div>

                    <button type="button" id="startUploadBtn" onclick="startChunkedUpload()" class="w-full snz-gradient text-[#061535] font-black py-3.5 rounded-2xl text-xs uppercase tracking-wider transition shadow-lg shadow-cyan-500/20 flex items-center justify-center gap-2">
                        <i class="fa-solid fa-upload"></i> Start Uploading APK
                    </button>
                </div>
            </div>
        </section>

        <!-- SECTION: AUTO PAY -->
        <section id="sec-autopay" class="admin-section hidden space-y-6">
            <div class="bg-gradient-to-br from-[#0b2559] to-[#05112b] border border-[#00D9FF]/40 rounded-3xl p-6 md:p-8 shadow-2xl max-w-xl mx-auto">
                <div class="flex items-center gap-3 mb-5">
                    <div class="w-12 h-12 rounded-2xl snz-gradient text-[#061535] flex items-center justify-center text-xl shrink-0"><i class="fa-solid fa-bolt"></i></div>
                    <div>
                        <h2 class="text-base font-black text-white">Auto Pay</h2>
                        <p class="text-xs text-slate-300">Paste a Brand Key and save — your website's Add Fund verifies payments automatically with your SNZPay app.</p>
                    </div>
                </div>

                <!-- Status -->
                <div class="rounded-2xl p-4 mb-5 border <?= $autoPayEnabled && $autoPayInfo && $autoPayInfo['plan_until'] ? 'border-[#00D89A]/40 bg-[#00D89A]/5' : 'border-amber-500/40 bg-amber-500/5' ?>">
                    <?php if (!$autoPayEnabled): ?>
                        <div class="text-sm font-black text-amber-400"><i class="fa-solid fa-power-off"></i> Auto Pay is OFF</div>
                        <div class="text-[11px] text-slate-300 mt-1">Every Add Fund request goes to <b>Deposit Requests</b> for manual approval.</div>
                    <?php elseif ($autoPayKey === ''): ?>
                        <div class="text-sm font-black text-amber-400"><i class="fa-solid fa-plug"></i> No Brand Key yet</div>
                        <div class="text-[11px] text-slate-300 mt-1">Using the account chosen in Payment Setup. Add a Brand Key below to connect.</div>
                    <?php elseif (!$autoPayInfo || $autoPayInfo['status'] === 'revoked'): ?>
                        <div class="text-sm font-black text-rose-300"><i class="fa-solid fa-triangle-exclamation"></i> Brand Key not found / revoked</div>
                        <div class="text-[11px] text-slate-300 mt-1">Deposits go to Deposit Requests until you save a valid key.</div>
                    <?php elseif (!$autoPayInfo['plan_until']): ?>
                        <div class="text-sm font-black text-rose-300"><i class="fa-solid fa-triangle-exclamation"></i> Key owner's plan expired</div>
                        <div class="text-[11px] text-slate-300 mt-1">Renew the plan of <?= htmlspecialchars($autoPayInfo['username']) ?>. Until then deposits go to Deposit Requests.</div>
                    <?php else: ?>
                        <div class="text-sm font-black text-[#00D89A]"><i class="fa-solid fa-circle-check"></i> Connected &amp; Active</div>
                        <div class="text-[11px] text-slate-300 mt-1 space-y-0.5">
                            <div>Brand: <b class="text-white"><?= htmlspecialchars($autoPayInfo['brand_name'] ?: '—') ?></b> • Owner: <b class="text-white"><?= htmlspecialchars($autoPayInfo['username']) ?></b></div>
                            <div>Payments are matched with SMS from <?= htmlspecialchars($autoPayInfo['username']) ?>'s SNZPay app.</div>
                            <div>Plan active until <?= date("d M Y, h:i A", strtotime($autoPayInfo['plan_until'])) ?><?= !empty($autoPayInfo['last_used_at']) ? ' • last used ' . date("d M, h:i A", strtotime($autoPayInfo['last_used_at'])) : '' ?></div>
                        </div>
                    <?php endif; ?>
                </div>

                <form method="POST" class="space-y-4">
                    <input type="hidden" name="csrf" value="<?= $CSRF ?>">
                    <input type="hidden" name="action_save_autopay" value="1">
                    <div>
                        <label class="text-xs font-semibold text-slate-200 block mb-1.5">Brand Key</label>
                        <input type="text" name="autopay_brand_key" value="<?= htmlspecialchars($autoPayKey) ?>" placeholder="SNZ-BOT-XXXXXXXXXXXX" class="w-full bg-[#051433] border border-[#0d2a63] rounded-2xl px-4 py-3 text-sm text-[#00D89A] font-mono focus:outline-none focus:border-[#00D9FF]">
                        <span class="text-[10px] text-slate-400 mt-1 block">Create it from a user dashboard (Brands) with an active plan — use the account your phone app is logged in with.</span>
                    </div>
                    <label class="flex items-center justify-between gap-3 bg-[#051433] border border-[#0d2a63] rounded-2xl p-4 cursor-pointer">
                        <span class="text-xs font-bold text-white"><i class="fa-solid fa-power-off text-[#00D89A] mr-1.5"></i> Auto Pay ON</span>
                        <input type="checkbox" name="autopay_enabled" <?= $autoPayEnabled ? 'checked' : '' ?> class="w-5 h-5">
                    </label>
                    <div class="text-[11px] text-slate-400 bg-[#051433] border border-[#0d2a63] rounded-xl p-3 leading-relaxed">
                        • ON: customer enters TrxID → balance added instantly if your app received the SMS.<br>
                        • If the same TrxID fails <b class="text-white">3 times</b>, it comes to <b class="text-white">Deposit Requests</b> for you.<br>
                        • OFF: every request comes to Deposit Requests for manual approval.
                    </div>
                    <button type="submit" class="w-full snz-gradient text-[#061535] font-black py-3.5 rounded-2xl text-xs uppercase tracking-wider"><i class="fa-solid fa-floppy-disk"></i> Save Auto Pay</button>
                </form>
            </div>
        </section>

        <!-- SECTION: DEPOSIT REQUESTS -->
        <section id="sec-deposits" class="admin-section hidden space-y-6">
            <div class="bg-[#081e4b] border border-[#123982] rounded-3xl p-6 md:p-8 shadow-2xl max-w-3xl mx-auto">
                <div class="flex items-center justify-between gap-3 mb-5 pb-4 border-b border-[#0d265c]">
                    <div class="flex items-center gap-3">
                        <div class="w-10 h-10 rounded-2xl bg-amber-500/15 text-amber-400 flex items-center justify-center text-lg shrink-0"><i class="fa-solid fa-inbox"></i></div>
                        <div>
                            <h2 class="text-base font-bold text-white">Deposit Requests</h2>
                            <p class="text-xs text-slate-300">TrxIDs that failed 3 times, or all requests when Auto Pay is off.</p>
                        </div>
                    </div>
                    <span class="text-xs font-black text-amber-400 shrink-0"><?= count($pendingDeposits) ?> waiting</span>
                </div>

                <div class="space-y-3">
                    <?php if (!$pendingDeposits): ?>
                        <div class="text-center text-xs text-slate-400 py-8"><i class="fa-solid fa-circle-check text-2xl text-[#00D89A] block mb-2"></i> No requests waiting.</div>
                    <?php endif; ?>
                    <?php foreach ($pendingDeposits as $dr): ?>
                        <div class="rounded-2xl border border-amber-500/30 bg-gradient-to-br from-[#0b2559] to-[#05112b] p-4 space-y-3">
                            <div class="flex flex-wrap items-start justify-between gap-2">
                                <div class="min-w-0">
                                    <div class="font-mono text-sm font-black text-white break-all"><?= htmlspecialchars($dr['trx_id']) ?></div>
                                    <div class="text-[11px] text-slate-400"><?= htmlspecialchars($dr['username']) ?> (<?= htmlspecialchars($dr['email']) ?>) • <?= htmlspecialchars(strtoupper((string) $dr['provider'])) ?></div>
                                </div>
                                <div class="text-right">
                                    <div class="text-lg font-black text-white">৳ <?= number_format((float) $dr['amount'], 2) ?></div>
                                    <div class="text-[10px] font-black text-amber-400"><?= (int) $dr['attempts'] ?> tries</div>
                                </div>
                            </div>
                            <?php if (!empty($dr['last_error'])): ?><div class="text-[11px] text-rose-300 bg-rose-500/10 border border-rose-500/20 rounded-xl px-3 py-2"><?= htmlspecialchars($dr['last_error']) ?></div><?php endif; ?>
                            <div class="text-[10px] text-slate-500"><?= date("d M Y, h:i A", strtotime($dr['updated_at'] ?: $dr['created_at'])) ?></div>
                            <form method="POST" class="grid grid-cols-2 gap-2">
                                <input type="hidden" name="csrf" value="<?= $CSRF ?>">
                                <input type="hidden" name="action_deposit_decide" value="1">
                                <input type="hidden" name="request_id" value="<?= (int) $dr['id'] ?>">
                                <input type="number" step="any" min="0" name="credit_amount" value="<?= htmlspecialchars((string) (float) $dr['amount']) ?>" title="Amount to credit" class="bg-[#020817] border border-[#0d2a63] rounded-xl px-3 py-2.5 text-xs text-white focus:outline-none focus:border-[#00D9FF]">
                                <input type="text" name="admin_note" maxlength="250" placeholder="Note (optional)" class="bg-[#020817] border border-[#0d2a63] rounded-xl px-3 py-2.5 text-xs text-white focus:outline-none focus:border-[#00D9FF]">
                                <button type="submit" name="decision" value="approve" class="snz-gradient text-[#061535] font-black py-2.5 rounded-xl text-xs"><i class="fa-solid fa-check"></i> Approve</button>
                                <button type="submit" name="decision" value="reject" onclick="return confirm('Reject this request?');" class="text-rose-300 bg-rose-500/10 border border-rose-500/30 font-bold py-2.5 rounded-xl text-xs"><i class="fa-solid fa-xmark"></i> Reject</button>
                            </form>
                        </div>
                    <?php endforeach; ?>
                </div>

                <?php if ($recentDeposits): ?>
                    <h3 class="text-xs font-bold text-slate-300 uppercase tracking-wider mt-6 mb-2">Recently decided</h3>
                    <div class="space-y-2">
                        <?php foreach ($recentDeposits as $dr): ?>
                            <div class="flex items-center justify-between gap-3 bg-[#051433] border border-[#0d2a63] rounded-xl px-3 py-2">
                                <div class="min-w-0">
                                    <div class="font-mono text-[11px] font-bold text-white truncate"><?= htmlspecialchars($dr['trx_id']) ?> • <?= htmlspecialchars($dr['username']) ?></div>
                                    <div class="text-[10px] text-slate-400"><?= htmlspecialchars((string) $dr['admin_note']) ?></div>
                                </div>
                                <span class="shrink-0 text-[10px] font-black <?= $dr['status'] === 'approved' ? 'text-[#00D89A]' : 'text-rose-300' ?>"><?= $dr['status'] === 'approved' ? '✓ ৳' . number_format((float) $dr['credited_amount'], 2) : '✕ Rejected' ?></span>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
        </section>

        <!-- SECTION: PLANS -->
        <?php
        $planFormHtml = function ($p) use ($CSRF) {
            $isNew = !$p;
            $p = $p ?: ['id' => 0, 'name' => '', 'price' => '', 'discount_percent' => 0, 'duration_hours' => 720, 'max_devices' => 1, 'max_brand_keys' => 1, 'badge' => '', 'description' => '', 'is_trial' => 0, 'status' => 'active', 'sort_order' => 0];
            $h = (int) $p['duration_hours'];
            $inDays = $h % 24 === 0;
            $in = 'w-full bg-[#020817] border border-[#0d2a63] rounded-xl px-3 py-2.5 text-xs text-white focus:outline-none focus:border-[#00D9FF]';
            ob_start(); ?>
            <form method="POST" class="space-y-3">
                <input type="hidden" name="csrf" value="<?= $CSRF ?>">
                <input type="hidden" name="action_save_plan" value="1">
                <input type="hidden" name="plan_id" value="<?= (int) $p['id'] ?>">
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                    <div class="sm:col-span-2">
                        <label class="text-[11px] text-slate-400 block mb-1">Plan name *</label>
                        <input type="text" name="p_name" required maxlength="100" value="<?= htmlspecialchars($p['name']) ?>" placeholder="e.g. Business Plan" class="<?= $in ?>">
                    </div>
                    <div>
                        <label class="text-[11px] text-slate-400 block mb-1">Price (BDT)</label>
                        <input type="number" step="any" min="0" name="p_price" value="<?= htmlspecialchars((string) $p['price']) ?>" placeholder="100" class="<?= $in ?>">
                    </div>
                    <div>
                        <label class="text-[11px] text-slate-400 block mb-1">Discount (%)</label>
                        <input type="number" step="any" min="0" max="100" name="p_discount" value="<?= htmlspecialchars((string) (float) $p['discount_percent']) ?>" class="<?= $in ?>">
                    </div>
                    <div>
                        <label class="text-[11px] text-slate-400 block mb-1">Duration</label>
                        <div class="flex gap-2">
                            <input type="number" step="any" min="1" name="p_duration" value="<?= $inDays ? $h / 24 : $h ?>" class="<?= $in ?>">
                            <select name="p_duration_unit" class="<?= $in ?> w-auto">
                                <option value="days" <?= $inDays ? 'selected' : '' ?>>Days</option>
                                <option value="hours" <?= !$inDays ? 'selected' : '' ?>>Hours</option>
                            </select>
                        </div>
                    </div>
                    <div>
                        <label class="text-[11px] text-slate-400 block mb-1">Filter button (category)</label>
                        <input type="text" name="p_category" maxlength="40" list="planCatList" value="<?= htmlspecialchars((string) ($p['category'] ?? '')) ?>" placeholder="e.g. Monthly, Business" class="<?= $in ?>">
                    </div>
                    <input type="hidden" name="p_sort" value="<?= (int) $p['sort_order'] ?>">
                    <div>
                        <label class="text-[11px] text-slate-400 block mb-1">📱 Phones per key</label>
                        <input type="number" min="1" max="100" name="p_devices" value="<?= (int) $p['max_devices'] ?>" class="<?= $in ?>">
                    </div>
                    <div>
                        <label class="text-[11px] text-slate-400 block mb-1">🔗 Brand keys</label>
                        <input type="number" min="0" max="100" name="p_brands" value="<?= (int) $p['max_brand_keys'] ?>" class="<?= $in ?>">
                    </div>
                    <div>
                        <label class="text-[11px] text-slate-400 block mb-1">Badge (optional)</label>
                        <input type="text" name="p_badge" maxlength="40" value="<?= htmlspecialchars((string) $p['badge']) ?>" placeholder="Popular Choice" class="<?= $in ?>">
                    </div>
                    <div>
                        <label class="text-[11px] text-slate-400 block mb-1">Short description</label>
                        <input type="text" name="p_desc" maxlength="255" value="<?= htmlspecialchars((string) $p['description']) ?>" class="<?= $in ?>">
                    </div>
                </div>
                <div class="flex flex-wrap items-center gap-4 text-xs text-white font-semibold">
                    <label class="flex items-center gap-2"><input type="checkbox" name="p_active" <?= ($p['status'] ?? 'active') === 'active' ? 'checked' : '' ?>> Show to customers</label>
                    <label class="flex items-center gap-2"><input type="checkbox" name="p_trial" <?= !empty($p['is_trial']) ? 'checked' : '' ?>> Free trial (1 time per user)</label>
                    <label class="flex items-center gap-2"><input type="checkbox" name="p_pinned" <?= !empty($p['is_pinned']) ? 'checked' : '' ?>> 📌 Pin on top</label>
                </div>
                <button type="submit" class="w-full snz-gradient text-[#061535] font-black py-3 rounded-xl text-xs uppercase tracking-wider"><?= $isNew ? 'Create Plan' : 'Save Changes' ?></button>
            </form>
            <?php return ob_get_clean();
        };
        ?>
        <section id="sec-plans" class="admin-section hidden space-y-6">
            <div class="bg-[#081e4b] border border-[#123982] rounded-3xl p-6 md:p-8 shadow-2xl max-w-3xl mx-auto">
                <div class="flex items-center gap-3 mb-5 pb-4 border-b border-[#0d265c]">
                    <div class="w-10 h-10 rounded-2xl bg-[#00D9FF]/20 text-[#00D9FF] flex items-center justify-center text-lg shrink-0"><i class="fa-solid fa-layer-group"></i></div>
                    <div>
                        <h2 class="text-base font-bold text-white">Subscription Plans</h2>
                        <p class="text-xs text-slate-300">Set price, discount, duration, phones per key and brand keys. Shown on the homepage and user dashboard.</p>
                    </div>
                </div>

                <datalist id="planCatList"><?php foreach (snzPlanCategories($allPlans) as $pc): ?><option value="<?= htmlspecialchars($pc) ?>"><?php endforeach; ?></datalist>
                <div class="space-y-4">
                    <?php foreach ($allPlans as $pl):
                        $final = snzPlanFinalPrice($pl);
                        $hasDisc = (float) $pl['discount_percent'] > 0;
                        $visible = $pl['status'] === 'active'; ?>
                        <div class="rounded-2xl border <?= $visible ? 'border-[#00D9FF]/30' : 'border-slate-700 opacity-75' ?> bg-gradient-to-br from-[#0b2559] to-[#05112b] p-4">
                            <div class="flex flex-wrap items-start justify-between gap-3">
                                <div class="min-w-0">
                                    <div class="flex flex-wrap items-center gap-2">
                                        <span class="text-sm font-black text-white"><?= htmlspecialchars($pl['name']) ?></span>
                                        <?php if (!empty($pl['badge'])): ?><span class="text-[9px] font-black px-2 py-0.5 rounded-full bg-[#00D9FF]/15 text-[#00D9FF] border border-[#00D9FF]/30"><?= htmlspecialchars($pl['badge']) ?></span><?php endif; ?>
                                        <?php if (!empty($pl['is_trial'])): ?><span class="text-[9px] font-black px-2 py-0.5 rounded-full bg-[#00D89A]/15 text-[#00D89A]">TRIAL</span><?php endif; ?>
                                        <?php if (!$visible): ?><span class="text-[9px] font-black px-2 py-0.5 rounded-full bg-slate-700 text-slate-300">HIDDEN</span><?php endif; ?>
                                        <?php if (!empty($pl['is_pinned'])): ?><span class="text-[9px] font-black px-2 py-0.5 rounded-full bg-amber-400 text-[#061535]">📌 PINNED</span><?php endif; ?>
                                        <?php if (!empty($pl['category'])): ?><span class="text-[9px] font-bold px-2 py-0.5 rounded-full bg-[#051433] text-slate-300 border border-[#0d2a63]"><?= htmlspecialchars($pl['category']) ?></span><?php endif; ?>
                                    </div>
                                    <div class="flex flex-wrap gap-x-4 gap-y-1 mt-2 text-[11px] text-slate-300">
                                        <span><i class="fa-regular fa-clock text-[#00D9FF]"></i> <?= snzDurationLabel($pl['duration_hours']) ?></span>
                                        <span><i class="fa-solid fa-mobile-screen text-[#00D89A]"></i> <?= (int) $pl['max_devices'] ?> phone<?= (int) $pl['max_devices'] > 1 ? 's' : '' ?></span>
                                        <span><i class="fa-solid fa-link text-amber-400"></i> <?= (int) $pl['max_brand_keys'] ?> brand key<?= (int) $pl['max_brand_keys'] != 1 ? 's' : '' ?></span>
                                    </div>
                                </div>
                                <div class="text-right shrink-0">
                                    <?php if ($hasDisc): ?><div class="text-[11px] text-slate-400 line-through">৳ <?= number_format((float) $pl['price'], 2) ?></div><?php endif; ?>
                                    <div class="text-xl font-black text-white">৳ <?= number_format($final, 2) ?></div>
                                    <?php if ($hasDisc): ?><div class="text-[10px] font-black text-[#00D89A]"><?= rtrim(rtrim(number_format((float) $pl['discount_percent'], 2), '0'), '.') ?>% OFF</div><?php endif; ?>
                                </div>
                            </div>
                            <div class="flex items-center gap-2 mt-3">
                                <?php foreach ([['action_move_plan', 'up', 'fa-arrow-up', 'Move up'], ['action_move_plan', 'down', 'fa-arrow-down', 'Move down'], ['action_pin_plan', '', 'fa-thumbtack', !empty($pl['is_pinned']) ? 'Unpin' : 'Pin']] as $ctl): ?>
                                    <form method="POST">
                                        <input type="hidden" name="csrf" value="<?= $CSRF ?>">
                                        <input type="hidden" name="<?= $ctl[0] ?>" value="1">
                                        <input type="hidden" name="plan_id" value="<?= (int) $pl['id'] ?>">
                                        <?php if ($ctl[1]): ?><input type="hidden" name="dir" value="<?= $ctl[1] ?>"><?php endif; ?>
                                        <button type="submit" title="<?= $ctl[3] ?>" class="h-8 px-3 rounded-lg text-[11px] font-bold border <?= $ctl[0] === 'action_pin_plan' && !empty($pl['is_pinned']) ? 'bg-amber-400 text-[#061535] border-amber-400' : 'bg-[#051433] text-slate-200 border-[#0d2a63]' ?>"><?= $ctl[1] === 'up' ? '↑ Up' : ($ctl[1] === 'down' ? '↓ Down' : '📌 ' . $ctl[3]) ?></button>
                                    </form>
                                <?php endforeach; ?>
                            </div>
                            <details class="mt-3">
                                <summary class="cursor-pointer text-xs font-bold text-[#00D9FF] select-none"><i class="fa-solid fa-pen-to-square"></i> Edit plan</summary>
                                <div class="mt-3"><?= $planFormHtml($pl) ?></div>
                                <form method="POST" class="mt-2" onsubmit="return confirm('Delete this plan? (If customers bought it, it will be hidden instead.)');">
                                    <input type="hidden" name="csrf" value="<?= $CSRF ?>">
                                    <input type="hidden" name="action_delete_plan" value="1">
                                    <input type="hidden" name="plan_id" value="<?= (int) $pl['id'] ?>">
                                    <button type="submit" class="w-full text-xs font-bold text-rose-300 bg-rose-500/10 border border-rose-500/30 py-2.5 rounded-xl"><i class="fa-solid fa-trash-can"></i> Delete plan</button>
                                </form>
                            </details>
                        </div>
                    <?php endforeach; ?>
                </div>

                <div class="mt-6 bg-[#051433] border border-[#0d2a63] rounded-2xl p-4">
                    <span class="text-xs font-bold text-white block mb-3"><i class="fa-solid fa-plus text-[#00D9FF] mr-1"></i> Create New Plan</span>
                    <?= $planFormHtml(null) ?>
                </div>
            </div>
        </section>

        <!-- SECTION 3: CREATE LICENSE -->
        <section id="sec-create-key" class="admin-section hidden space-y-6">
            <div class="bg-[#081e4b] border border-[#123982] rounded-3xl p-6 md:p-8 shadow-2xl max-w-2xl mx-auto">
                <div class="flex items-center gap-3 mb-6 pb-4 border-b border-[#0d265c]">
                    <div class="w-10 h-10 rounded-2xl bg-[#00D9FF]/20 text-[#00D9FF] flex items-center justify-center text-lg">
                        <i class="fa-solid fa-key"></i>
                    </div>
                    <div>
                        <h2 class="text-base font-bold text-white">Generate Custom License Key</h2>
                        <p class="text-xs text-slate-300">Instantly saves to database and Firebase.</p>
                    </div>
                </div>

                <form method="POST" class="space-y-4">
                    <input type="hidden" name="action_create_key" value="1">
                    <input type="hidden" name="csrf" value="<?= $CSRF ?>">

                    <div>
                        <label class="text-xs font-semibold text-slate-200 block mb-1.5">Select User</label>
                        <select name="user_id" required class="w-full bg-[#051433] border border-[#0d2a63] rounded-2xl px-4 py-3 text-sm text-white focus:outline-none focus:border-[#00D9FF]">
                            <?php foreach ($allUsers as $u): ?>
                                <option value="<?= $u['id'] ?>"><?= htmlspecialchars($u['username']) ?> (<?= htmlspecialchars($u['email']) ?>)</option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div>
                        <label class="text-xs font-semibold text-slate-200 block mb-1.5">Choose Plan (fills limits)</label>
                        <select name="ck_plan_id" id="ckPlanSelect" onchange="fillPlanLimits()" class="w-full bg-[#051433] border border-[#0d2a63] rounded-2xl px-4 py-3 text-sm text-white focus:outline-none focus:border-[#00D9FF]">
                            <option value="0" data-d="1" data-b="1" data-h="24">Custom (no plan)</option>
                            <?php foreach ($allPlans as $pl): ?>
                                <option value="<?= (int) $pl['id'] ?>" data-d="<?= (int) $pl['max_devices'] ?>" data-b="<?= (int) $pl['max_brand_keys'] ?>" data-h="<?= (int) $pl['duration_hours'] ?>"><?= htmlspecialchars($pl['name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="grid grid-cols-2 gap-3">
                        <div>
                            <label class="text-xs font-semibold text-slate-200 block mb-1.5">📱 Phones per key</label>
                            <input type="number" min="1" max="100" name="ck_max_devices" id="ckDevices" value="1" class="w-full bg-[#051433] border border-[#0d2a63] rounded-2xl px-4 py-3 text-sm text-white focus:outline-none focus:border-[#00D9FF]">
                        </div>
                        <div>
                            <label class="text-xs font-semibold text-slate-200 block mb-1.5">🔗 Brand keys</label>
                            <input type="number" min="0" max="100" name="ck_max_brand_keys" id="ckBrands" value="1" class="w-full bg-[#051433] border border-[#0d2a63] rounded-2xl px-4 py-3 text-sm text-white focus:outline-none focus:border-[#00D9FF]">
                        </div>
                    </div>

                    <div>
                        <label class="text-xs font-semibold text-slate-200 block mb-1.5">Plan Title (empty = plan name)</label>
                        <input type="text" name="plan_name" placeholder="e.g., VIP 30-Day Pass" class="w-full bg-[#051433] border border-[#0d2a63] rounded-2xl px-4 py-3 text-sm text-white focus:outline-none focus:border-[#00D9FF]">
                    </div>

                    <div>
                        <label class="text-xs font-semibold text-slate-200 block mb-2">Duration Type</label>
                        <div class="grid grid-cols-2 gap-3 mb-3">
                            <label class="flex items-center gap-2 bg-[#051433] p-3 rounded-2xl border border-[#0d2a63] text-xs cursor-pointer">
                                <input type="radio" name="duration_type" value="hours" checked onclick="toggleDurationType('hours')"> By Hours
                            </label>
                            <label class="flex items-center gap-2 bg-[#051433] p-3 rounded-2xl border border-[#0d2a63] text-xs cursor-pointer">
                                <input type="radio" name="duration_type" value="datetime" onclick="toggleDurationType('datetime')"> Specific Date &amp; Time
                            </label>
                        </div>

                        <div id="hoursInputBox">
                            <label class="text-[11px] text-slate-400 block mb-1.5">Total Hours Active:</label>
                            <input type="number" name="duration_hours" value="24" step="any" placeholder="e.g., 24, 72, 720" class="w-full bg-[#051433] border border-[#0d2a63] rounded-2xl px-4 py-3 text-sm text-white focus:outline-none focus:border-[#00D9FF]">
                        </div>

                        <div id="datetimeInputBox" class="hidden">
                            <label class="text-[11px] text-slate-400 block mb-1.5">Expiration Date &amp; Time:</label>
                            <input type="datetime-local" name="custom_datetime" class="w-full bg-[#051433] border border-[#0d2a63] rounded-2xl px-4 py-3 text-sm text-white focus:outline-none focus:border-[#00D9FF]">
                        </div>
                    </div>

                    <button type="submit" class="w-full snz-gradient text-[#061535] font-black py-3.5 rounded-2xl text-xs uppercase tracking-wider transition shadow-lg shadow-cyan-500/20 flex items-center justify-center gap-2">
                        <i class="fa-solid fa-paper-plane"></i> Generate &amp; Sync Key
                    </button>
                </form>
            </div>
        </section>

        <!-- SECTION 4: ADD USER BALANCE -->
        <section id="sec-balance" class="admin-section hidden space-y-6">
            <div class="bg-[#081e4b] border border-[#123982] rounded-3xl p-6 md:p-8 shadow-2xl max-w-xl mx-auto">
                <div class="flex items-center gap-3 mb-6 pb-4 border-b border-[#0d265c]">
                    <div class="w-10 h-10 rounded-2xl bg-[#00D89A]/20 text-[#00D89A] flex items-center justify-center text-lg">
                        <i class="fa-solid fa-wallet"></i>
                    </div>
                    <div>
                        <h2 class="text-base font-bold text-white">Deposit Balance to User</h2>
                        <p class="text-xs text-slate-300">Directly credit funds to any user's dashboard wallet.</p>
                    </div>
                </div>

                <form method="POST" class="space-y-4">
                    <input type="hidden" name="action_add_balance" value="1">
                    <input type="hidden" name="csrf" value="<?= $CSRF ?>">

                    <div>
                        <label class="text-xs font-semibold text-slate-200 block mb-1.5">Select User Account</label>
                        <select name="balance_user_id" required class="w-full bg-[#051433] border border-[#0d2a63] rounded-2xl px-4 py-3 text-sm text-white focus:outline-none focus:border-[#00D89A]">
                            <?php foreach ($allUsers as $u): ?>
                                <option value="<?= $u['id'] ?>"><?= htmlspecialchars($u['username']) ?> - Current: ৳<?= number_format(floatval($u['balance']), 2) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div>
                        <label class="text-xs font-semibold text-slate-200 block mb-1.5">Amount to Credit (BDT)</label>
                        <input type="number" name="balance_amount" min="1" step="any" required placeholder="e.g., 50 or 100" class="w-full bg-[#051433] border border-[#0d2a63] rounded-2xl px-4 py-3 text-sm text-white focus:outline-none focus:border-[#00D89A]">
                    </div>

                    <button type="submit" class="w-full snz-gradient text-[#061535] font-black py-3.5 rounded-2xl text-xs uppercase tracking-wider transition shadow-lg shadow-cyan-500/20 flex items-center justify-center gap-2">
                        <i class="fa-solid fa-plus-circle"></i> Credit Wallet Now
                    </button>
                </form>
            </div>
        </section>

        <!-- SECTION 5: PAYMENT NUMBERS SETUP -->
        <section id="sec-payments" class="admin-section hidden space-y-6">
            <div class="bg-[#081e4b] border border-[#123982] rounded-3xl p-6 md:p-8 shadow-2xl max-w-xl mx-auto">
                <div class="flex items-center gap-3 mb-6 pb-4 border-b border-[#0d265c]">
                    <div class="w-10 h-10 rounded-2xl bg-[#007BFF]/20 text-[#007BFF] flex items-center justify-center text-lg">
                        <i class="fa-solid fa-credit-card"></i>
                    </div>
                    <div>
                        <h2 class="text-base font-bold text-white">Payment Accounts Configuration</h2>
                        <p class="text-xs text-slate-300">Update bKash, Nagad, and Binance payment destinations.</p>
                    </div>
                </div>

                <form method="POST" class="space-y-4">
                    <input type="hidden" name="action_update_payments" value="1">
                    <input type="hidden" name="csrf" value="<?= $CSRF ?>">

                    <div>
                        <label class="text-xs font-semibold text-white block mb-1.5">bKash Number:</label>
                        <input type="text" name="bkash_number" required value="<?= htmlspecialchars($PAYMENT_INFO['bkash']) ?>" class="w-full bg-[#051433] border border-[#0d2a63] rounded-2xl px-4 py-3 text-sm text-white font-mono focus:outline-none focus:border-[#00D9FF]">
                    </div>

                    <div>
                        <label class="text-xs font-semibold text-white block mb-1.5">Nagad Number:</label>
                        <input type="text" name="nagad_number" required value="<?= htmlspecialchars($PAYMENT_INFO['nagad']) ?>" class="w-full bg-[#051433] border border-[#0d2a63] rounded-2xl px-4 py-3 text-sm text-white font-mono focus:outline-none focus:border-[#00D9FF]">
                    </div>

                    <div>
                        <label class="text-xs font-semibold text-[#00D9FF] block mb-1.5">Binance Pay ID / Wallet:</label>
                        <input type="text" name="binance_id" required value="<?= htmlspecialchars($PAYMENT_INFO['binance']) ?>" class="w-full bg-[#051433] border border-[#0d2a63] rounded-2xl px-4 py-3 text-sm text-white font-mono focus:outline-none focus:border-[#00D9FF]">
                    </div>

                    <button type="submit" class="w-full snz-gradient text-[#061535] font-black py-3.5 rounded-2xl text-xs uppercase tracking-wider transition shadow-lg shadow-cyan-500/20 flex items-center justify-center gap-2">
                        <i class="fa-solid fa-floppy-disk"></i> Save Payment Details
                    </button>
                </form>
            </div>
        
            <div class="bg-[#081e4b] border border-[#123982] rounded-3xl p-6 md:p-8 shadow-2xl max-w-xl mx-auto">
                <div class="flex items-center gap-3 mb-4">
                    <div class="w-10 h-10 rounded-2xl bg-[#35F58A]/15 text-[#35F58A] flex items-center justify-center text-lg shrink-0"><i class="fa-solid fa-mobile-screen"></i></div>
                    <div>
                        <h2 class="text-base font-bold text-white">Site Deposit Verification</h2>
                        <p class="text-xs text-slate-300">Your website's Add Fund is verified by YOUR SNZPay app. Pick the account your phone app is logged in with.</p>
                    </div>
                </div>
                <form method="POST" class="space-y-3">
                    <input type="hidden" name="csrf" value="<?= $CSRF ?>">
                    <input type="hidden" name="action_set_receiver" value="1">
                    <select name="site_receiver_user_id" class="w-full bg-[#051433] border border-[#0d2a63] rounded-2xl px-4 py-3 text-sm text-white focus:outline-none focus:border-[#00D9FF]">
                        <?php foreach ($allUsers as $u): ?>
                            <option value="<?= (int) $u['id'] ?>" <?= (int) $u['id'] === $siteReceiverId ? 'selected' : '' ?>>#<?= (int) $u['id'] ?> — <?= htmlspecialchars($u['username']) ?> (<?= htmlspecialchars($u['email']) ?>)<?= (int) $u['id'] === MASTER_USER_ID ? ' • master key account' : '' ?></option>
                        <?php endforeach; ?>
                    </select>
                    <div class="text-[11px] text-slate-400 bg-[#051433] border border-[#0d2a63] rounded-xl p-3 leading-relaxed">
                        1. Install the SNZPay app on the phone that has your bKash / Nagad / Rocket SIM (the numbers above).<br>
                        2. Login in the app with the <b class="text-white">master key</b> (account #<?= MASTER_USER_ID ?>) or with a license key of the account selected here.<br>
                        3. When a customer sends money and enters the TrxID in Add Fund, the site matches it with the SMS your app uploaded.
                    </div>
                    <button type="submit" class="w-full snz-gradient text-[#061535] font-black py-3 rounded-2xl text-xs uppercase tracking-wider"><i class="fa-solid fa-floppy-disk"></i> Save</button>
                </form>
            </div>
        </section>

        <!-- SECTION 6: ALL LICENSES -->
        <section id="sec-licenses" class="admin-section hidden space-y-6">
            <div class="bg-[#081e4b] border border-[#123982] rounded-3xl p-6 shadow-2xl">
                <div class="flex justify-between items-center mb-4 pb-3 border-b border-[#0d265c]">
                    <div class="flex items-center gap-2">
                        <i class="fa-solid fa-key text-[#00D9FF]"></i>
                        <h2 class="text-base font-bold text-white">All Generated License Keys</h2>
                    </div>
                    <span class="text-xs text-[#00D89A] font-bold">Total: <?= $totalKeys ?> Keys (showing latest 50)</span>
                </div>

                <div class="overflow-x-auto">
                    <table class="w-full text-left text-xs text-slate-300">
                        <thead class="bg-[#051433] text-[#00D9FF] uppercase text-[10px]">
                            <tr>
                                <th class="p-3">User</th>
                                <th class="p-3">License Key</th>
                                <th class="p-3">Plan</th>
                                <th class="p-3">Expires At</th>
                                <th class="p-3">Connected Phone</th>
                                <th class="p-3 text-right">Action</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-[#0d265c] font-mono">
                            <?php if (empty($allKeys)): ?>
                                <tr><td colspan="6" class="p-4 text-center text-slate-400 font-sans">No license keys found.</td></tr>
                            <?php else: ?>
                                <?php foreach ($allKeys as $k): 
                                    $expired = strtotime($k['expires_at']) < time();
                                ?>
                                    <tr class="hover:bg-[#051433]/60">
                                        <td class="p-3 font-sans">
                                            <b class="text-white"><?= htmlspecialchars($k['username']) ?></b><br>
                                            <span class="text-[10px] text-slate-400"><?= htmlspecialchars($k['email']) ?></span>
                                        </td>
                                        <td class="p-3 font-bold text-[#00D89A] select-all"><?= htmlspecialchars($k['license_key']) ?></td>
                                        <td class="p-3 font-sans"><?= htmlspecialchars($k['plan_name']) ?></td>
                                        <td class="p-3 font-sans">
                                            <?= date("d M Y, h:i A", strtotime($k['expires_at'])) ?>
                                            <?php if (($k['status'] ?? 'active') !== 'active'): ?>
                                                <span class="text-rose-400 text-[10px] block font-bold">[BANNED]</span>
                                            <?php elseif ($expired): ?>
                                                <span class="text-slate-400 text-[10px] block font-bold">[EXPIRED]</span>
                                            <?php else: ?>
                                                <span class="text-[#00D89A] text-[10px] block font-bold">[ACTIVE]</span>
                                            <?php endif; ?>
                                        </td>
                                        <td class="p-3 font-sans">
                                            <?php $devs = $keyDevicesMap[(int) $k['id']] ?? []; $maxD = max(1, (int) ($k['max_devices'] ?? 1)); ?>
                                            <span class="text-[10px] font-black <?= count($devs) ? 'text-[#00D89A]' : 'text-slate-500' ?>">📱 <?= count($devs) ?>/<?= $maxD ?> phones • 🔗 <?= (int) ($k['max_brand_keys'] ?? 1) ?> brand</span>
                                            <?php foreach ($devs as $dvc): ?>
                                                <div class="mt-1.5 flex items-center gap-2 bg-[#051433] border border-[#0d2a63] rounded-lg px-2 py-1.5">
                                                    <div class="min-w-0">
                                                        <span class="text-white font-bold text-[11px] block truncate"><?= htmlspecialchars($dvc['device_name'] ?: 'Android Phone') ?></span>
                                                        <span class="text-[9px] text-slate-400 block">ID <?= htmlspecialchars(substr($dvc['device_id'], 0, 8)) ?>… <?= !empty($dvc['last_seen_at']) ? '• ' . date("d M, h:i A", strtotime($dvc['last_seen_at'])) : '' ?></span>
                                                    </div>
                                                    <form method="POST" class="ml-auto" onsubmit="return confirm('Remove this phone? A new phone can then login with this key.');">
                                                        <input type="hidden" name="csrf" value="<?= $CSRF ?>">
                                                        <input type="hidden" name="action_remove_device" value="1">
                                                        <input type="hidden" name="device_row_id" value="<?= (int) $dvc['id'] ?>">
                                                        <button type="submit" title="Remove phone" class="w-6 h-6 rounded-md text-rose-300 bg-rose-500/10 border border-rose-500/30 text-[10px]"><i class="fa-solid fa-xmark"></i></button>
                                                    </form>
                                                </div>
                                            <?php endforeach; ?>
                                            <?php if (!$devs): ?><span class="text-slate-500 text-[11px] block mt-1">Not connected</span><?php endif; ?>
                                        </td>
                                        <td class="p-3 font-sans text-right">
                                            <form method="POST" class="mb-1.5" onsubmit="return confirm('<?= ($k['status'] ?? 'active') === 'active' ? 'Ban this key? The app will log out.' : 'Unban this key?' ?>');">
                                                <input type="hidden" name="csrf" value="<?= $CSRF ?>">
                                                <input type="hidden" name="action_ban_key" value="1">
                                                <input type="hidden" name="key_id" value="<?= (int) $k['id'] ?>">
                                                <button type="submit" class="text-xs font-bold px-3 py-1.5 rounded-xl border inline-flex items-center gap-1 <?= ($k['status'] ?? 'active') === 'active' ? 'text-amber-400 border-amber-500/40 bg-amber-500/10' : 'text-[#00D89A] border-[#00D89A]/40 bg-[#00D89A]/10' ?>"><?= ($k['status'] ?? 'active') === 'active' ? '🚫 Ban' : '✓ Unban' ?></button>
                                            </form>
                                            <a href="?delete_key=<?= $k['id'] ?>&amp;csrf=<?= $CSRF ?>" onclick="return confirm('Revoke this key? It will be permanently removed from Firebase & Database!')" class="text-slate-300 bg-[#061535] hover:bg-slate-800 px-3 py-1.5 rounded-xl border border-slate-700 text-xs font-bold inline-flex items-center gap-1">
                                                <i class="fa-solid fa-trash-can text-slate-400"></i> Delete
                                            </a>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </section>

        <!-- SECTION 9: APP SETTINGS -->
        <section id="sec-app" class="admin-section hidden space-y-6">
            <div class="bg-[#081e4b] border border-[#123982] rounded-3xl p-6 md:p-8 shadow-2xl max-w-xl mx-auto">
                <div class="flex items-center gap-3 mb-6 pb-4 border-b border-[#0d265c]">
                    <div class="w-10 h-10 rounded-2xl bg-[#35F58A]/15 text-[#35F58A] flex items-center justify-center text-lg shrink-0">
                        <i class="fa-solid fa-mobile-screen"></i>
                    </div>
                    <div>
                        <h2 class="text-base font-bold text-white">Android App Settings</h2>
                        <p class="text-xs text-slate-300">Name, logo, notice, ON/OFF and update popup inside the app.</p>
                    </div>
                </div>

                <form method="POST" enctype="multipart/form-data" class="space-y-4">
                    <input type="hidden" name="action_update_app" value="1">
                    <input type="hidden" name="csrf" value="<?= $CSRF ?>">

                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                        <div>
                            <label class="text-xs font-semibold text-slate-200 block mb-1.5">App Name (inside app)</label>
                            <input type="text" name="app_name" maxlength="40" value="<?= htmlspecialchars($APP_SET['app_name'] ?? 'SNZ Pay') ?>" class="w-full bg-[#051433] border border-[#0d2a63] rounded-2xl px-4 py-3 text-sm text-white focus:outline-none focus:border-[#00D9FF]">
                        </div>
                        <div>
                            <label class="text-xs font-semibold text-slate-200 block mb-1.5">Tagline</label>
                            <input type="text" name="app_tagline" maxlength="80" value="<?= htmlspecialchars($APP_SET['app_tagline'] ?? 'Faster • Safer • Smarter') ?>" class="w-full bg-[#051433] border border-[#0d2a63] rounded-2xl px-4 py-3 text-sm text-white focus:outline-none focus:border-[#00D9FF]">
                        </div>
                    </div>

                    <div>
                        <label class="text-xs font-semibold text-slate-200 block mb-1.5">App Logo (upload or link)</label>
                        <input type="file" name="app_logo_file" accept="image/*" class="w-full bg-[#051433] border border-[#0d2a63] rounded-2xl p-3 text-white text-xs file:mr-4 file:py-2 file:px-4 file:rounded-xl file:border-0 file:text-xs file:font-semibold file:bg-[#007BFF] file:text-white">
                        <input type="text" name="app_logo_url" value="<?= htmlspecialchars($APP_SET['app_logo'] ?? '') ?>" placeholder="Empty = use website logo" class="mt-2 w-full bg-[#051433] border border-[#0d2a63] rounded-2xl px-4 py-3 text-xs text-white font-mono focus:outline-none focus:border-[#00D9FF]">
                    </div>

                    <div>
                        <label class="text-xs font-semibold text-slate-200 block mb-1.5">Notice inside the app (optional)</label>
                        <textarea name="app_notice" rows="2" maxlength="500" class="w-full bg-[#051433] border border-[#0d2a63] rounded-2xl p-4 text-white text-xs focus:outline-none focus:border-[#00D9FF]"><?= htmlspecialchars($APP_SET['notice'] ?? '') ?></textarea>
                    </div>

                    <div class="bg-[#051433] border border-[#0d2a63] rounded-2xl p-4 space-y-3">
                        <label class="flex items-center justify-between gap-3 text-xs font-bold text-white cursor-pointer">
                            <span><i class="fa-solid fa-power-off text-[#00D89A] mr-1.5"></i> App is ON (users can use the app)</span>
                            <input type="checkbox" name="app_enabled" <?= (!isset($APP_SET['app_enabled']) || $APP_SET['app_enabled']) ? 'checked' : '' ?> class="w-5 h-5">
                        </label>
                        <div>
                            <label class="text-[11px] text-slate-400 block mb-1.5">Message when app is OFF</label>
                            <input type="text" name="disabled_message" maxlength="300" value="<?= htmlspecialchars($APP_SET['disabled_message'] ?? 'Server maintenance is going on. Please try again later.') ?>" class="w-full bg-[#020817] border border-[#0d2a63] rounded-xl px-3 py-2.5 text-xs text-white focus:outline-none focus:border-[#00D9FF]">
                        </div>
                        <span class="text-[10px] text-slate-400 block">SMS that arrive while the app is OFF are still saved on the phone and sent after you turn it ON.</span>
                    </div>

                    <div class="bg-[#051433] border border-[#0d2a63] rounded-2xl p-4 space-y-3">
                        <span class="text-xs font-bold text-white block"><i class="fa-solid fa-cloud-arrow-down text-[#00D9FF] mr-1.5"></i> New Version / Update Popup</span>
                        <div class="grid grid-cols-2 gap-3">
                            <div>
                                <label class="text-[11px] text-slate-400 block mb-1.5">Latest version code</label>
                                <input type="number" min="0" name="latest_version_code" value="<?= intval($APP_SET['latest_version_code'] ?? 0) ?>" class="w-full bg-[#020817] border border-[#0d2a63] rounded-xl px-3 py-2.5 text-xs text-white focus:outline-none focus:border-[#00D9FF]">
                            </div>
                            <div>
                                <label class="text-[11px] text-slate-400 block mb-1.5">Version name</label>
                                <input type="text" name="latest_version_name" maxlength="20" placeholder="e.g. 2.1" value="<?= htmlspecialchars($APP_SET['latest_version_name'] ?? '') ?>" class="w-full bg-[#020817] border border-[#0d2a63] rounded-xl px-3 py-2.5 text-xs text-white focus:outline-none focus:border-[#00D9FF]">
                            </div>
                        </div>
                        <div>
                            <label class="text-[11px] text-slate-400 block mb-1.5">Update message</label>
                            <input type="text" name="update_message" maxlength="300" value="<?= htmlspecialchars($APP_SET['update_message'] ?? 'A new version of the app is available. Please update now.') ?>" class="w-full bg-[#020817] border border-[#0d2a63] rounded-xl px-3 py-2.5 text-xs text-white focus:outline-none focus:border-[#00D9FF]">
                        </div>
                        <div>
                            <label class="text-[11px] text-slate-400 block mb-1.5">APK download link (empty = APK uploaded in APK Manager)</label>
                            <input type="text" name="apk_url" value="<?= htmlspecialchars($APP_SET['apk_url'] ?? '') ?>" placeholder="https://..." class="w-full bg-[#020817] border border-[#0d2a63] rounded-xl px-3 py-2.5 text-xs text-white font-mono focus:outline-none focus:border-[#00D9FF]">
                        </div>
                        <label class="flex items-center justify-between gap-3 text-xs font-bold text-white cursor-pointer">
                            <span>Force update (old app cannot be used)</span>
                            <input type="checkbox" name="force_update" <?= !empty($APP_SET['force_update']) ? 'checked' : '' ?> class="w-5 h-5">
                        </label>
                        <span class="text-[10px] text-slate-400 block">The app shows the popup when its own version code is lower than this number. Increase this number every time you upload a new APK.</span>
                    </div>

                    <div class="bg-[#051433] border border-[#0d2a63] rounded-2xl p-4">
                        <label class="flex items-center justify-between gap-3 text-xs font-bold text-white cursor-pointer">
                            <span><i class="fa-solid fa-shield-halved text-amber-400 mr-1.5"></i> Fake SMS protection (check sender ID)</span>
                            <input type="checkbox" name="strict_sender" <?= (!isset($APP_SET['strict_sender']) || $APP_SET['strict_sender']) ? 'checked' : '' ?> class="w-5 h-5">
                        </label>
                        <span class="text-[10px] text-slate-400 block mt-2">ON = only SMS from the real bKash / NAGAD / 16216 (Rocket) senders are accepted. Keep this ON so nobody can send a fake "You have received Tk" SMS from a normal number.</span>
                    </div>

                    <button type="submit" class="w-full snz-gradient text-[#061535] font-black py-3.5 rounded-2xl text-xs uppercase tracking-wider transition shadow-lg shadow-cyan-500/20 flex items-center justify-center gap-2">
                        <i class="fa-solid fa-floppy-disk"></i> Save App Settings
                    </button>
                </form>
            </div>
        </section>

        <!-- SECTION 10: SMS RULES -->
        <section id="sec-sms-rules" class="admin-section hidden space-y-6">
            <div class="bg-[#081e4b] border border-[#123982] rounded-3xl p-6 md:p-8 shadow-2xl max-w-2xl mx-auto">
                <div class="flex items-center gap-3 mb-4 pb-4 border-b border-[#0d265c]">
                    <div class="w-10 h-10 rounded-2xl bg-[#007BFF]/20 text-[#00D9FF] flex items-center justify-center text-lg shrink-0">
                        <i class="fa-solid fa-comment-sms"></i>
                    </div>
                    <div>
                        <h2 class="text-base font-bold text-white">Custom SMS Rules</h2>
                        <p class="text-xs text-slate-300">Teach the app to read a new payment SMS (new bank, Upay, new number etc.). bKash, Nagad, Rocket, TallyPay are already built in.</p>
                    </div>
                </div>

                <!-- Existing rules -->
                <div class="space-y-3 mb-6">
                    <?php if (empty($APP_SET['sms_rules'])): ?>
                        <div class="bg-[#051433] border border-[#0d2a63] rounded-2xl p-4 text-center text-xs text-slate-400">No custom rules yet.</div>
                    <?php else: foreach ($APP_SET['sms_rules'] as $r): $on = !empty($r['enabled']); ?>
                        <div class="bg-[#051433] border <?= $on ? 'border-[#00D89A]/40' : 'border-[#0d2a63]' ?> rounded-2xl p-4 space-y-2">
                            <div class="flex items-center justify-between gap-2">
                                <span class="text-xs font-black text-white"><?= htmlspecialchars($r['method'] ?? '') ?></span>
                                <div class="flex items-center gap-2">
                                    <form method="POST">
                                        <input type="hidden" name="csrf" value="<?= $CSRF ?>">
                                        <input type="hidden" name="action_toggle_sms_rule" value="1">
                                        <input type="hidden" name="rule_id" value="<?= htmlspecialchars($r['id'] ?? '') ?>">
                                        <button type="submit" class="text-[10px] font-black px-2.5 py-1 rounded-full <?= $on ? 'bg-[#00D89A]/20 text-[#00D89A] border border-[#00D89A]/40' : 'bg-[#0c2454] text-slate-400 border border-[#143985]' ?>"><?= $on ? 'ON' : 'OFF' ?></button>
                                    </form>
                                    <form method="POST" onsubmit="return confirm('Delete this rule?');">
                                        <input type="hidden" name="csrf" value="<?= $CSRF ?>">
                                        <input type="hidden" name="action_delete_sms_rule" value="1">
                                        <input type="hidden" name="rule_id" value="<?= htmlspecialchars($r['id'] ?? '') ?>">
                                        <button type="submit" class="text-[10px] font-bold px-2.5 py-1 rounded-full bg-[#061535] text-slate-300 border border-slate-700"><i class="fa-solid fa-trash-can"></i></button>
                                    </form>
                                </div>
                            </div>
                            <div class="text-[11px] text-slate-400 break-all">
                                Sender: <b class="text-slate-200"><?= htmlspecialchars($r['senders'] ?: 'ANY') ?></b>
                                <?php if (!empty($r['must_contain'])): ?> • Must contain: <b class="text-slate-200"><?= htmlspecialchars($r['must_contain']) ?></b><?php endif; ?><br>
                                TrxID after: <b class="text-slate-200"><?= htmlspecialchars($r['trx_before']) ?></b> • Amount after: <b class="text-slate-200"><?= htmlspecialchars($r['amount_before']) ?></b>
                                <?php if (!empty($r['number_before'])): ?> • Number after: <b class="text-slate-200"><?= htmlspecialchars($r['number_before']) ?></b><?php endif; ?>
                            </div>
                        </div>
                    <?php endforeach; endif; ?>
                </div>

                <!-- Add rule -->
                <form method="POST" class="space-y-3 bg-[#051433] border border-[#0d2a63] rounded-2xl p-4">
                    <input type="hidden" name="csrf" value="<?= $CSRF ?>">
                    <input type="hidden" name="action_add_sms_rule" value="1">
                    <span class="text-xs font-bold text-white block"><i class="fa-solid fa-plus text-[#00D9FF] mr-1"></i> Add New Rule</span>
                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                        <div>
                            <label class="text-[11px] text-slate-400 block mb-1">Method name *</label>
                            <input type="text" name="rule_method" required placeholder="UPAY" class="w-full bg-[#020817] border border-[#0d2a63] rounded-xl px-3 py-2.5 text-xs text-white focus:outline-none focus:border-[#00D9FF]">
                        </div>
                        <div>
                            <label class="text-[11px] text-slate-400 block mb-1">Sender ID (comma separated)</label>
                            <input type="text" name="rule_senders" placeholder="upay, 16268" class="w-full bg-[#020817] border border-[#0d2a63] rounded-xl px-3 py-2.5 text-xs text-white focus:outline-none focus:border-[#00D9FF]">
                        </div>
                        <div>
                            <label class="text-[11px] text-slate-400 block mb-1">Text before TrxID *</label>
                            <input type="text" name="rule_trx_before" required placeholder="TrxID" class="w-full bg-[#020817] border border-[#0d2a63] rounded-xl px-3 py-2.5 text-xs text-white focus:outline-none focus:border-[#00D9FF]">
                        </div>
                        <div>
                            <label class="text-[11px] text-slate-400 block mb-1">Text before Amount *</label>
                            <input type="text" name="rule_amount_before" required placeholder="received" class="w-full bg-[#020817] border border-[#0d2a63] rounded-xl px-3 py-2.5 text-xs text-white focus:outline-none focus:border-[#00D9FF]">
                        </div>
                        <div>
                            <label class="text-[11px] text-slate-400 block mb-1">Text before customer number</label>
                            <input type="text" name="rule_number_before" placeholder="from" class="w-full bg-[#020817] border border-[#0d2a63] rounded-xl px-3 py-2.5 text-xs text-white focus:outline-none focus:border-[#00D9FF]">
                        </div>
                        <div>
                            <label class="text-[11px] text-slate-400 block mb-1">SMS must contain (comma separated)</label>
                            <input type="text" name="rule_must_contain" placeholder="received" class="w-full bg-[#020817] border border-[#0d2a63] rounded-xl px-3 py-2.5 text-xs text-white focus:outline-none focus:border-[#00D9FF]">
                        </div>
                    </div>
                    <span class="text-[10px] text-slate-400 block">Example SMS: "You have received Tk 500.00 from 01712345678. TrxID ABC123XYZ" → Text before TrxID = <b>TrxID</b>, before Amount = <b>received</b>, before number = <b>from</b>. Advanced: start a field with <b>regex:</b> to use your own regex (group 1).</span>
                    <button type="submit" class="w-full snz-gradient text-[#061535] font-black py-3 rounded-xl text-xs uppercase tracking-wider">Add Rule</button>
                </form>

                <!-- Test -->
                <form method="POST" class="space-y-3 bg-[#051433] border border-[#0d2a63] rounded-2xl p-4 mt-4">
                    <input type="hidden" name="csrf" value="<?= $CSRF ?>">
                    <input type="hidden" name="action_test_sms_rule" value="1">
                    <span class="text-xs font-bold text-white block"><i class="fa-solid fa-flask text-amber-400 mr-1"></i> Test a sample SMS against your rules</span>
                    <input type="text" name="test_sender" placeholder="Sender (e.g. upay)" value="<?= htmlspecialchars($smsTestResult['sender'] ?? '') ?>" class="w-full bg-[#020817] border border-[#0d2a63] rounded-xl px-3 py-2.5 text-xs text-white focus:outline-none focus:border-[#00D9FF]">
                    <textarea name="test_sms" rows="3" required placeholder="Paste the full SMS here" class="w-full bg-[#020817] border border-[#0d2a63] rounded-xl p-3 text-xs text-white font-mono focus:outline-none focus:border-[#00D9FF]"><?= htmlspecialchars($smsTestResult['text'] ?? '') ?></textarea>
                    <button type="submit" class="w-full bg-[#092257] hover:bg-[#0c2e75] text-white font-bold py-3 rounded-xl text-xs border border-[#174396]">Run Test</button>
                    <?php if ($smsTestResult !== null): ?>
                        <div class="space-y-2">
                            <?php if (empty($smsTestResult['rows'])): ?>
                                <div class="text-[11px] text-slate-400">No custom rules to test. Add a rule first.</div>
                            <?php endif; foreach ($smsTestResult['rows'] as $row): $res = $row['res']; ?>
                                <div class="text-[11px] rounded-xl p-3 border break-all <?= $res['ok'] ? 'border-[#00D89A]/40 text-[#00D89A]' : 'border-[#0d2a63] text-slate-400' ?>">
                                    <b><?= htmlspecialchars($row['rule']['method'] ?? '') ?>:</b>
                                    <?php if ($res['ok']): ?>
                                        ✔ TrxID <b><?= htmlspecialchars($res['trx']) ?></b> • Amount <b><?= htmlspecialchars((string) $res['amount']) ?></b><?= $res['number'] !== '' ? ' • From <b>' . htmlspecialchars($res['number']) . '</b>' : '' ?>
                                    <?php else: ?>
                                        ✘ <?= htmlspecialchars($res['why']) ?>
                                    <?php endif; ?>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </form>
            </div>
        </section>

        <!-- SECTION 8: SITE SETTINGS -->
        <section id="sec-site" class="admin-section hidden space-y-6">
            <div class="bg-[#081e4b] border border-[#123982] rounded-3xl p-6 md:p-8 shadow-2xl max-w-xl mx-auto">
                <div class="flex items-center gap-3 mb-6 pb-4 border-b border-[#0d265c]">
                    <div class="w-10 h-10 rounded-2xl bg-amber-500/20 text-amber-400 flex items-center justify-center text-lg shrink-0">
                        <i class="fa-solid fa-gear"></i>
                    </div>
                    <div>
                        <h2 class="text-base font-bold text-white">Site Settings</h2>
                        <p class="text-xs text-slate-300">Change site name, tagline, logo, support link and dashboard notice.</p>
                    </div>
                </div>

                <div class="flex items-center gap-3 bg-[#051433] p-4 rounded-2xl border border-[#0d2a63] mb-5">
                    <img src="<?= htmlspecialchars($SITE_LOGO) ?>" onerror="this.onerror=null;this.src='data:image/svg+xml,%3Csvg xmlns=%22http://www.w3.org/2000/svg%22 viewBox=%220 0 64 64%22%3E%3Cdefs%3E%3ClinearGradient id=%22g%22 x1=%220%22 y1=%220%22 x2=%221%22 y2=%221%22%3E%3Cstop offset=%220%22 stop-color=%22%23007BFF%22/%3E%3Cstop offset=%22.5%22 stop-color=%22%2300D9FF%22/%3E%3Cstop offset=%221%22 stop-color=%22%2300D89A%22/%3E%3C/linearGradient%3E%3C/defs%3E%3Crect width=%2264%22 height=%2264%22 rx=%2216%22 fill=%22url(%23g)%22/%3E%3Ctext x=%2232%22 y=%2244%22 font-family=%22Arial%22 font-weight=%22900%22 font-size=%2234%22 text-anchor=%22middle%22 fill=%22%23061535%22%3ES%3C/text%3E%3C/svg%3E'" alt="Current logo" class="w-12 h-12 object-contain rounded-xl shrink-0">
                    <div class="min-w-0">
                        <span class="text-[10px] text-slate-400 uppercase font-bold block">Live Preview</span>
                        <span class="text-lg font-black text-white block truncate"><?= snzBrandHtml($SITE_NAME) ?></span>
                        <span class="text-[10px] text-[#00D89A] font-semibold uppercase tracking-wider block truncate"><?= htmlspecialchars($SITE_TAGLINE) ?></span>
                    </div>
                </div>

                <form method="POST" enctype="multipart/form-data" class="space-y-4">
                    <input type="hidden" name="action_update_site" value="1">
                    <input type="hidden" name="csrf" value="<?= $CSRF ?>">

                    <div>
                        <label class="text-xs font-semibold text-slate-200 block mb-1.5">Site Name</label>
                        <input type="text" name="site_name" required maxlength="40" value="<?= htmlspecialchars($SITE_NAME) ?>" class="w-full bg-[#051433] border border-[#0d2a63] rounded-2xl px-4 py-3 text-sm text-white focus:outline-none focus:border-[#00D9FF]">
                        <span class="text-[10px] text-slate-400 mt-1 block">Names ending with "Pay" show "Pay" in blue, otherwise the last word is blue.</span>
                    </div>

                    <div>
                        <label class="text-xs font-semibold text-slate-200 block mb-1.5">Tagline</label>
                        <input type="text" name="site_tagline" maxlength="80" value="<?= htmlspecialchars($SITE_TAGLINE) ?>" class="w-full bg-[#051433] border border-[#0d2a63] rounded-2xl px-4 py-3 text-sm text-white focus:outline-none focus:border-[#00D9FF]">
                    </div>

                    <div>
                        <label class="text-xs font-semibold text-slate-200 block mb-1.5">Upload Logo (PNG / JPG / WEBP, max 2 MB)</label>
                        <input type="file" name="site_logo_file" accept="image/*" class="w-full bg-[#051433] border border-[#0d2a63] rounded-2xl p-3 text-white text-xs file:mr-4 file:py-2 file:px-4 file:rounded-xl file:border-0 file:text-xs file:font-semibold file:bg-[#007BFF] file:text-white">
                    </div>

                    <div>
                        <label class="text-xs font-semibold text-slate-200 block mb-1.5">Or Logo Image URL</label>
                        <input type="text" name="site_logo_url" value="<?= htmlspecialchars($SITE_SETTINGS['site_logo'] ?? $SITE_LOGO) ?>" placeholder="https://..." class="w-full bg-[#051433] border border-[#0d2a63] rounded-2xl px-4 py-3 text-xs text-white font-mono focus:outline-none focus:border-[#00D9FF]">
                        <span class="text-[10px] text-slate-400 mt-1 block">If you upload a file above, the uploaded logo is used.</span>
                    </div>

                    <div>
                        <label class="text-xs font-semibold text-slate-200 block mb-1.5">Support Link (Telegram / WhatsApp)</label>
                        <input type="text" name="support_link" value="<?= htmlspecialchars($SITE_SETTINGS['support_link'] ?? '') ?>" placeholder="https://t.me/yourusername" class="w-full bg-[#051433] border border-[#0d2a63] rounded-2xl px-4 py-3 text-xs text-white font-mono focus:outline-none focus:border-[#00D9FF]">
                    </div>

                    <div>
                        <label class="text-xs font-semibold text-slate-200 block mb-1.5">Dashboard Notice (scrolling text)</label>
                        <textarea name="notice_text" rows="3" class="w-full bg-[#051433] border border-[#0d2a63] rounded-2xl p-4 text-white text-xs focus:outline-none focus:border-[#00D9FF]"><?= htmlspecialchars($currentNotice) ?></textarea>
                    </div>

                    <button type="submit" class="w-full snz-gradient text-[#061535] font-black py-3.5 rounded-2xl text-xs uppercase tracking-wider transition shadow-lg shadow-cyan-500/20 flex items-center justify-center gap-2">
                        <i class="fa-solid fa-floppy-disk"></i> Save Site Settings
                    </button>
                </form>
            </div>
        </section>

        <!-- SECTION 7: ALL USERS -->
        <section id="sec-users" class="admin-section hidden space-y-6">
            <div class="bg-[#081e4b] border border-[#123982] rounded-3xl p-6 shadow-2xl">
                <div class="flex justify-between items-center mb-4 pb-3 border-b border-[#0d265c]">
                    <div class="flex items-center gap-2">
                        <i class="fa-solid fa-users text-[#00D89A]"></i>
                        <h2 class="text-base font-bold text-white">Registered Users Directory</h2>
                    </div>
                    <span class="text-xs text-[#00D9FF] font-bold">Total: <?= count($allUsers) ?> Users</span>
                </div>

                <div class="overflow-x-auto">
                    <table class="w-full text-left text-xs text-slate-300">
                        <thead class="bg-[#051433] text-[#00D89A] uppercase text-[10px]">
                            <tr>
                                <th class="p-3">ID</th>
                                <th class="p-3">Username</th>
                                <th class="p-3">Email Address</th>
                                <th class="p-3">Balance</th>
                                <th class="p-3">Joined Date</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-[#0d265c] font-mono">
                            <?php foreach ($allUsers as $u): ?>
                                <tr class="hover:bg-[#051433]/60">
                                    <td class="p-3">#<?= $u['id'] ?></td>
                                    <td class="p-3 font-sans font-bold text-white"><?= htmlspecialchars($u['username']) ?></td>
                                    <td class="p-3"><?= htmlspecialchars($u['email']) ?></td>
                                    <td class="p-3 font-bold text-[#00D89A]">৳ <?= number_format(floatval($u['balance']), 2) ?></td>
                                    <td class="p-3 font-sans text-slate-400"><?= date("d M Y", strtotime($u['created_at'])) ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </section>

    </main>

    <script>
        function toggleAdminDrawer(open) {
            document.getElementById('admDrawer').classList.toggle('open', open);
            document.getElementById('admOverlay').classList.toggle('open', open);
            document.body.style.overflow = open ? 'hidden' : '';
        }
        document.addEventListener('keydown', e => { if (e.key === 'Escape') toggleAdminDrawer(false); });

        function switchSection(sectionId) {
            toggleAdminDrawer(false);
            const tab = document.getElementById('tab-' + sectionId);
            if (tab) {
                const icon = tab.querySelector('i');
                document.getElementById('admSectionTitle').innerText = Array.from(tab.childNodes).filter(n => n.nodeType === 3).map(n => n.textContent).join('').trim();
                if (icon) document.getElementById('admSectionIcon').innerHTML = icon.outerHTML;
            }
            try { sessionStorage.setItem('snz_admin_section', sectionId); } catch (e) {}
            window.scrollTo({ top: 0, behavior: 'smooth' });
            document.querySelectorAll('.admin-section').forEach(el => el.classList.add('hidden'));
            document.getElementById(sectionId).classList.remove('hidden');

            document.querySelectorAll('.nav-tab').forEach(el => el.classList.remove('active-tab', 'text-white'));
            document.querySelectorAll('.nav-tab').forEach(el => el.classList.add('text-slate-400'));

            const activeTab = document.getElementById('tab-' + sectionId);
            if (activeTab) {
                activeTab.classList.add('active-tab', 'text-white');
                activeTab.classList.remove('text-slate-400');
            }
        }

        function fillPlanLimits() {
            const o = document.getElementById('ckPlanSelect').selectedOptions[0];
            if (!o) return;
            document.getElementById('ckDevices').value = o.dataset.d;
            document.getElementById('ckBrands').value = o.dataset.b;
            const hrs = document.querySelector('input[name="duration_hours"]');
            if (hrs && o.value !== '0') hrs.value = o.dataset.h;
        }

        function toggleDurationType(type) {
            if (type === 'hours') {
                document.getElementById('hoursInputBox').classList.remove('hidden');
                document.getElementById('datetimeInputBox').classList.add('hidden');
            } else {
                document.getElementById('hoursInputBox').classList.add('hidden');
                document.getElementById('datetimeInputBox').classList.remove('hidden');
            }
        }

        function showNotification(type, title, message) {
            const toast = document.getElementById('universalToast');
            const iconContainer = document.getElementById('toastIconContainer');
            const titleEl = document.getElementById('toastTitle');
            const msgEl = document.getElementById('toastMessage');

            if (type === 'success') {
                iconContainer.className = 'w-10 h-10 rounded-xl flex items-center justify-center text-lg shrink-0 bg-[#00D89A]/20 text-[#00D89A] border border-[#00D89A]/30';
                iconContainer.innerHTML = '<i class="fa-solid fa-circle-check"></i>';
            } else {
                iconContainer.className = 'w-10 h-10 rounded-xl flex items-center justify-center text-lg shrink-0 bg-[#00D9FF]/20 text-[#00D9FF] border border-[#00D9FF]/30';
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

        <?php if ($activeSection !== 'sec-overview'): ?>
            switchSection(<?= json_encode($activeSection) ?>);
        <?php elseif (isset($_GET['delete_key'])): ?>
            switchSection('sec-licenses');
        <?php else: ?>
            // রিলোডের পর শেষবার যে পেজে ছিলেন সেখানেই থাকবে
            (function () {
                let last = null;
                try { last = sessionStorage.getItem('snz_admin_section'); } catch (e) {}
                if (last && document.getElementById(last)) switchSection(last);
                else switchSection('sec-overview');
            })();
        <?php endif; ?>
        <?php if ($msg): ?>
            showNotification('success', 'SUCCESS', <?= json_encode($msg, JSON_HEX_TAG | JSON_HEX_APOS | JSON_UNESCAPED_UNICODE) ?>);
        <?php endif; ?>
        <?php if ($error): ?>
            showNotification('error', 'ATTENTION', <?= json_encode($error, JSON_HEX_TAG | JSON_HEX_APOS | JSON_UNESCAPED_UNICODE) ?>);
        <?php endif; ?>

        // =========================================================================
        // CHUNKED APK UPLOADER (Uploads ANY file size in 1MB pieces)
        // =========================================================================
        async function startChunkedUpload() {
            const fileInput = document.getElementById('apkFileInput');
            const file = fileInput.files[0];

            if (!file) {
                showNotification('error', 'SELECT FILE', 'Please choose an APK file first.');
                return;
            }

            if (!file.name.toLowerCase().endsWith('.apk')) {
                showNotification('error', 'INVALID FORMAT', 'Only .apk files are allowed.');
                return;
            }

            const uploadBtn = document.getElementById('startUploadBtn');
            const progressBox = document.getElementById('uploadProgressBox');
            const progressBar = document.getElementById('uploadProgressBar');
            const percentText = document.getElementById('uploadPercentText');
            const statusText = document.getElementById('uploadStatusText');

            uploadBtn.disabled = true;
            uploadBtn.classList.add('opacity-50', 'cursor-not-allowed');
            progressBox.classList.remove('hidden');

            const CHUNK_SIZE = 1 * 1024 * 1024; // 1MB chunk (always bypasses limits)
            const totalChunks = Math.ceil(file.size / CHUNK_SIZE);

            for (let chunkIndex = 0; chunkIndex < totalChunks; chunkIndex++) {
                const start = chunkIndex * CHUNK_SIZE;
                const end = Math.min(file.size, start + CHUNK_SIZE);
                const chunk = file.slice(start, end);

                const formData = new FormData();
                formData.append('action_upload_chunk', '1');
                formData.append('csrf', <?= json_encode($CSRF) ?>);
                formData.append('chunk_index', chunkIndex);
                formData.append('total_chunks', totalChunks);
                formData.append('chunk_data', chunk, file.name);

                statusText.innerText = `Uploading chunk ${chunkIndex + 1} of ${totalChunks}...`;
                const progress = Math.round(((chunkIndex + 1) / totalChunks) * 100);
                progressBar.style.width = progress + '%';
                percentText.innerText = progress + '%';

                try {
                    const response = await fetch('admin.php', {
                        method: 'POST',
                        body: formData
                    });
                    const res = await response.json();

                    if (res.status === 'completed') {
                        statusText.innerText = 'Upload completed!';
                        showNotification('success', 'UPLOAD COMPLETE', `APK (${res.size}) is now live!`);
                        document.getElementById('currentApkBadge').innerText = res.size;
                        document.getElementById('overviewApkSize').innerText = res.size;
                        document.getElementById('apkLastTime').innerText = res.time;
                        fileInput.value = '';
                        break;
                    } else if (res.status !== 'chunk_saved') {
                        throw new Error(res.message || 'Chunk upload failed');
                    }
                } catch (err) {
                    showNotification('error', 'UPLOAD FAILED', err.message || 'Error occurred during upload.');
                    break;
                }
            }

            uploadBtn.disabled = false;
            uploadBtn.classList.remove('opacity-50', 'cursor-not-allowed');
        }
    </script>
</body>
</html>