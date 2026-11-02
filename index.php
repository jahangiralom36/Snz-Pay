<?php
// Gzip compression for ultra-fast page delivery
if (!ob_start("ob_gzhandler")) ob_start();

session_start();

// Central Configuration File Connection
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

// হোমপেজের প্ল্যান — ফাইল ক্যাশ থেকে (ডাটাবেজ কানেকশন ছাড়াই দ্রুত); ক্যাশ না থাকলে ডাটাবেজ থেকে
$homePlans = snzReadPlansCache();
if ($homePlans === null) {
    $tmpPdo = getDBConnection();
    if ($tmpPdo) {
        snzEnsurePlanSchema($tmpPdo);
        $homePlans = snzGetPlans($tmpPdo, true);
        snzWritePlansCache($tmpPdo);
    }
}
if (!is_array($homePlans)) $homePlans = [];

// Redirect if already logged in
if (isset($_SESSION['user_id'])) {
    header("Location: dashboard.php");
    exit;
}

$error = "";
$success = "";
$authMode = "login";

// Lazy Database Connection: Only connects when Login or Register form is submitted
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $pdo = getDBConnection();

    if (!$pdo) {
        if (function_exists('snz_log_error')) snz_log_error('web', 'Database connection failed', 'Database connection error on login/register — check DB_NAME / DB_USER / DB_PASS in config.php', '', ['where' => 'index.php']);
        $error = "Database connection error! Check config.php settings.";
    } else {
        // Sign-Up Handler
        if (isset($_POST['register'])) {
            $authMode = "register";
            $username = trim($_POST['username']);
            $email = strtolower(trim($_POST['email']));
            $passwordRaw = trim($_POST['password']);

            if (strlen($username) < 3) {
                $error = "Username must be at least 3 characters!";
            } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $error = "Please enter a valid email address!";
            } elseif (strlen($passwordRaw) < 6) {
                $error = "Password must be at least 6 characters!";
            } else {
                $check = $pdo->prepare("SELECT id FROM users WHERE LOWER(email) = LOWER(?) OR LOWER(username) = LOWER(?) LIMIT 1");
                $check->execute([$email, $username]);
                if ($check->fetchColumn()) {
                    $error = "Email or username is already registered!";
                } else {
                    $passwordHash = password_hash($passwordRaw, PASSWORD_BCRYPT);
                    $stmt = $pdo->prepare("INSERT INTO users (username, email, password) VALUES (?, ?, ?)");
                    if ($stmt->execute([$username, $email, $passwordHash])) {
                        $success = "Registration successful! Please sign in now.";
                        $authMode = "login";
                    } else {
                        $error = "Registration failed! Please try again.";
                    }
                }
            }
        }

        // Login Handler (Supports Email OR Username)
        if (isset($_POST['login'])) {
            $authMode = "login";
            $loginInput = trim($_POST['login_input']);
            $password = trim($_POST['password']);

            if (empty($loginInput) || empty($password)) {
                $error = "Please fill in all fields!";
            } else {
                $stmt = $pdo->prepare("SELECT * FROM users WHERE LOWER(email) = LOWER(?) OR LOWER(username) = LOWER(?) LIMIT 1");
                $stmt->execute([$loginInput, $loginInput]);
                $user = $stmt->fetch();

                $stored = (string) ($user['password'] ?? '');
                $isHashed = !empty(password_get_info($stored)['algo']);
                // আগে হ্যাশ স্ট্রিংটাই পাসওয়ার্ড হিসেবে দিলে লগইন হয়ে যেত — এখন শুধু পুরনো plain-text পাসওয়ার্ডে সেটা চলবে
                $valid = $user && ($isHashed ? password_verify($password, $stored) : hash_equals($stored, $password));

                if ($valid && ($user['status'] ?? 'active') === 'banned') {
                    $error = "Your account has been banned. Please contact support.";
                } elseif ($valid) {
                    // পুরনো plain-text পাসওয়ার্ড অটো bcrypt এ আপগ্রেড
                    if (!$isHashed || password_needs_rehash($stored, PASSWORD_BCRYPT)) {
                        $pdo->prepare("UPDATE users SET password = ? WHERE id = ?")->execute([password_hash($password, PASSWORD_BCRYPT), $user['id']]);
                    }
                    session_regenerate_id(true);
                    $_SESSION['user_id'] = $user['id'];
                    $_SESSION['username'] = $user['username'];
                    $_SESSION['email'] = $user['email'];
                    header("Location: dashboard.php");
                    exit;
                } else {
                    $error = "Incorrect username/email or password!";
                }
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title><?= htmlspecialchars($SITE_NAME) ?> — <?= htmlspecialchars($SITE_TAGLINE) ?></title>

    <!-- Speed Optimization Preconnects -->
    <link rel="preconnect" href="https://cdnjs.cloudflare.com" crossorigin>
    <link rel="preconnect" href="https://i.ibb.co.com" crossorigin>
    <link rel="dns-prefetch" href="https://i.ibb.co.com">

    <!-- Pre-built Tailwind CSS (CDN JIT script এর বদলে — পেজ অনেক দ্রুত লোড হয়) -->
    <style>*,:after,:before{--tw-border-spacing-x:0;--tw-border-spacing-y:0;--tw-translate-x:0;--tw-translate-y:0;--tw-rotate:0;--tw-skew-x:0;--tw-skew-y:0;--tw-scale-x:1;--tw-scale-y:1;--tw-pan-x: ;--tw-pan-y: ;--tw-pinch-zoom: ;--tw-scroll-snap-strictness:proximity;--tw-gradient-from-position: ;--tw-gradient-via-position: ;--tw-gradient-to-position: ;--tw-ordinal: ;--tw-slashed-zero: ;--tw-numeric-figure: ;--tw-numeric-spacing: ;--tw-numeric-fraction: ;--tw-ring-inset: ;--tw-ring-offset-width:0px;--tw-ring-offset-color:#fff;--tw-ring-color:rgba(59,130,246,.5);--tw-ring-offset-shadow:0 0 #0000;--tw-ring-shadow:0 0 #0000;--tw-shadow:0 0 #0000;--tw-shadow-colored:0 0 #0000;--tw-blur: ;--tw-brightness: ;--tw-contrast: ;--tw-grayscale: ;--tw-hue-rotate: ;--tw-invert: ;--tw-saturate: ;--tw-sepia: ;--tw-drop-shadow: ;--tw-backdrop-blur: ;--tw-backdrop-brightness: ;--tw-backdrop-contrast: ;--tw-backdrop-grayscale: ;--tw-backdrop-hue-rotate: ;--tw-backdrop-invert: ;--tw-backdrop-opacity: ;--tw-backdrop-saturate: ;--tw-backdrop-sepia: ;--tw-contain-size: ;--tw-contain-layout: ;--tw-contain-paint: ;--tw-contain-style: }::backdrop{--tw-border-spacing-x:0;--tw-border-spacing-y:0;--tw-translate-x:0;--tw-translate-y:0;--tw-rotate:0;--tw-skew-x:0;--tw-skew-y:0;--tw-scale-x:1;--tw-scale-y:1;--tw-pan-x: ;--tw-pan-y: ;--tw-pinch-zoom: ;--tw-scroll-snap-strictness:proximity;--tw-gradient-from-position: ;--tw-gradient-via-position: ;--tw-gradient-to-position: ;--tw-ordinal: ;--tw-slashed-zero: ;--tw-numeric-figure: ;--tw-numeric-spacing: ;--tw-numeric-fraction: ;--tw-ring-inset: ;--tw-ring-offset-width:0px;--tw-ring-offset-color:#fff;--tw-ring-color:rgba(59,130,246,.5);--tw-ring-offset-shadow:0 0 #0000;--tw-ring-shadow:0 0 #0000;--tw-shadow:0 0 #0000;--tw-shadow-colored:0 0 #0000;--tw-blur: ;--tw-brightness: ;--tw-contrast: ;--tw-grayscale: ;--tw-hue-rotate: ;--tw-invert: ;--tw-saturate: ;--tw-sepia: ;--tw-drop-shadow: ;--tw-backdrop-blur: ;--tw-backdrop-brightness: ;--tw-backdrop-contrast: ;--tw-backdrop-grayscale: ;--tw-backdrop-hue-rotate: ;--tw-backdrop-invert: ;--tw-backdrop-opacity: ;--tw-backdrop-saturate: ;--tw-backdrop-sepia: ;--tw-contain-size: ;--tw-contain-layout: ;--tw-contain-paint: ;--tw-contain-style: }/*! tailwindcss v3.4.17 | MIT License | https://tailwindcss.com*/*,:after,:before{box-sizing:border-box;border:0 solid #e5e7eb}:after,:before{--tw-content:""}:host,html{line-height:1.5;-webkit-text-size-adjust:100%;-moz-tab-size:4;-o-tab-size:4;tab-size:4;font-family:ui-sans-serif,system-ui,sans-serif,Apple Color Emoji,Segoe UI Emoji,Segoe UI Symbol,Noto Color Emoji;font-feature-settings:normal;font-variation-settings:normal;-webkit-tap-highlight-color:transparent}body{margin:0;line-height:inherit}hr{height:0;color:inherit;border-top-width:1px}abbr:where([title]){-webkit-text-decoration:underline dotted;text-decoration:underline dotted}h1,h2,h3,h4,h5,h6{font-size:inherit;font-weight:inherit}a{color:inherit;text-decoration:inherit}b,strong{font-weight:bolder}code,kbd,pre,samp{font-family:ui-monospace,SFMono-Regular,Menlo,Monaco,Consolas,Liberation Mono,Courier New,monospace;font-feature-settings:normal;font-variation-settings:normal;font-size:1em}small{font-size:80%}sub,sup{font-size:75%;line-height:0;position:relative;vertical-align:baseline}sub{bottom:-.25em}sup{top:-.5em}table{text-indent:0;border-color:inherit;border-collapse:collapse}button,input,optgroup,select,textarea{font-family:inherit;font-feature-settings:inherit;font-variation-settings:inherit;font-size:100%;font-weight:inherit;line-height:inherit;letter-spacing:inherit;color:inherit;margin:0;padding:0}button,select{text-transform:none}button,input:where([type=button]),input:where([type=reset]),input:where([type=submit]){-webkit-appearance:button;background-color:transparent;background-image:none}:-moz-focusring{outline:auto}:-moz-ui-invalid{box-shadow:none}progress{vertical-align:baseline}::-webkit-inner-spin-button,::-webkit-outer-spin-button{height:auto}[type=search]{-webkit-appearance:textfield;outline-offset:-2px}::-webkit-search-decoration{-webkit-appearance:none}::-webkit-file-upload-button{-webkit-appearance:button;font:inherit}summary{display:list-item}blockquote,dd,dl,figure,h1,h2,h3,h4,h5,h6,hr,p,pre{margin:0}fieldset{margin:0}fieldset,legend{padding:0}menu,ol,ul{list-style:none;margin:0;padding:0}dialog{padding:0}textarea{resize:vertical}input::-moz-placeholder,textarea::-moz-placeholder{opacity:1;color:#9ca3af}input::placeholder,textarea::placeholder{opacity:1;color:#9ca3af}[role=button],button{cursor:pointer}:disabled{cursor:default}audio,canvas,embed,iframe,img,object,svg,video{display:block;vertical-align:middle}img,video{max-width:100%;height:auto}[hidden]:where(:not([hidden=until-found])){display:none}.\!container{width:100%!important}.container{width:100%}@media (min-width:640px){.\!container{max-width:640px!important}.container{max-width:640px}}@media (min-width:768px){.\!container{max-width:768px!important}.container{max-width:768px}}@media (min-width:1024px){.\!container{max-width:1024px!important}.container{max-width:1024px}}@media (min-width:1280px){.\!container{max-width:1280px!important}.container{max-width:1280px}}@media (min-width:1536px){.\!container{max-width:1536px!important}.container{max-width:1536px}}.sr-only{position:absolute;width:1px;height:1px;padding:0;margin:-1px;overflow:hidden;clip:rect(0,0,0,0);white-space:nowrap;border-width:0}.pointer-events-none{pointer-events:none}.visible{visibility:visible}.static{position:static}.fixed{position:fixed}.absolute{position:absolute}.relative{position:relative}.sticky{position:sticky}.inset-0{inset:0}.-top-3{top:-.75rem}.bottom-0{bottom:0}.left-0{left:0}.left-1{left:.25rem}.left-1\/2{left:50%}.left-4{left:1rem}.left-5{left:1.25rem}.right-0{right:0}.right-3{right:.75rem}.right-4{right:1rem}.right-5{right:1.25rem}.right-6{right:1.5rem}.top-0{top:0}.top-1{top:.25rem}.top-1\/2{top:50%}.top-3{top:.75rem}.top-3\.5{top:.875rem}.top-5{top:1.25rem}.z-30{z-index:30}.z-40{z-index:40}.z-50{z-index:50}.z-\[60\]{z-index:60}.z-\[70\]{z-index:70}.z-\[80\]{z-index:80}.mx-auto{margin-left:auto;margin-right:auto}.my-3{margin-top:.75rem;margin-bottom:.75rem}.my-4{margin-top:1rem;margin-bottom:1rem}.-mt-0{margin-top:0}.-mt-0\.5{margin-top:-.125rem}.mb-1{margin-bottom:.25rem}.mb-1\.5{margin-bottom:.375rem}.mb-10{margin-bottom:2.5rem}.mb-2{margin-bottom:.5rem}.mb-3{margin-bottom:.75rem}.mb-4{margin-bottom:1rem}.mb-5{margin-bottom:1.25rem}.mb-6{margin-bottom:1.5rem}.ml-auto{margin-left:auto}.mr-1{margin-right:.25rem}.mr-1\.5{margin-right:.375rem}.mt-0{margin-top:0}.mt-0\.5{margin-top:.125rem}.mt-1{margin-top:.25rem}.mt-1\.5{margin-top:.375rem}.mt-2{margin-top:.5rem}.mt-3{margin-top:.75rem}.mt-4{margin-top:1rem}.mt-5{margin-top:1.25rem}.mt-6{margin-top:1.5rem}.block{display:block}.inline-block{display:inline-block}.flex{display:flex}.inline-flex{display:inline-flex}.table{display:table}.grid{display:grid}.hidden{display:none}.h-10{height:2.5rem}.h-11{height:2.75rem}.h-12{height:3rem}.h-14{height:3.5rem}.h-16{height:4rem}.h-2{height:.5rem}.h-2\.5{height:.625rem}.h-20{height:5rem}.h-3{height:.75rem}.h-36{height:9rem}.h-4{height:1rem}.h-48{height:12rem}.h-5{height:1.25rem}.h-52{height:13rem}.h-6{height:1.5rem}.h-7{height:1.75rem}.h-8{height:2rem}.h-9{height:2.25rem}.h-\[46px\]{height:46px}.h-full{height:100%}.max-h-48{max-height:12rem}.max-h-\[90vh\]{max-height:90vh}.max-h-\[92vh\]{max-height:92vh}.min-h-screen{min-height:100vh}.w-1{width:.25rem}.w-1\/2{width:50%}.w-10{width:2.5rem}.w-11{width:2.75rem}.w-12{width:3rem}.w-14{width:3.5rem}.w-16{width:4rem}.w-2{width:.5rem}.w-2\.5{width:.625rem}.w-20{width:5rem}.w-3{width:.75rem}.w-4{width:1rem}.w-48{width:12rem}.w-5{width:1.25rem}.w-52{width:13rem}.w-6{width:1.5rem}.w-7{width:1.75rem}.w-72{width:18rem}.w-8{width:2rem}.w-9{width:2.25rem}.w-\[90\%\]{width:90%}.w-auto{width:auto}.w-full{width:100%}.min-w-0{min-width:0}.max-w-2xl{max-width:42rem}.max-w-3xl{max-width:48rem}.max-w-4xl{max-width:56rem}.max-w-6xl{max-width:72rem}.max-w-lg{max-width:32rem}.max-w-md{max-width:28rem}.max-w-sm{max-width:24rem}.max-w-xl{max-width:36rem}.max-w-xs{max-width:20rem}.flex-1{flex:1 1 0%}.flex-shrink{flex-shrink:1}.shrink-0{flex-shrink:0}.-translate-x-1{--tw-translate-x:-0.25rem}.-translate-x-1,.-translate-x-1\/2{transform:translate(var(--tw-translate-x),var(--tw-translate-y)) rotate(var(--tw-rotate)) skewX(var(--tw-skew-x)) skewY(var(--tw-skew-y)) scaleX(var(--tw-scale-x)) scaleY(var(--tw-scale-y))}.-translate-x-1\/2{--tw-translate-x:-50%}.-translate-x-full{--tw-translate-x:-100%}.-translate-x-full,.-translate-y-1{transform:translate(var(--tw-translate-x),var(--tw-translate-y)) rotate(var(--tw-rotate)) skewX(var(--tw-skew-x)) skewY(var(--tw-skew-y)) scaleX(var(--tw-scale-x)) scaleY(var(--tw-scale-y))}.-translate-y-1{--tw-translate-y:-0.25rem}.-translate-y-1\/2{--tw-translate-y:-50%}.-translate-y-1\/2,.-translate-y-8{transform:translate(var(--tw-translate-x),var(--tw-translate-y)) rotate(var(--tw-rotate)) skewX(var(--tw-skew-x)) skewY(var(--tw-skew-y)) scaleX(var(--tw-scale-x)) scaleY(var(--tw-scale-y))}.-translate-y-8{--tw-translate-y:-2rem}.translate-x-full{--tw-translate-x:100%}.translate-x-full,.translate-y-0{transform:translate(var(--tw-translate-x),var(--tw-translate-y)) rotate(var(--tw-rotate)) skewX(var(--tw-skew-x)) skewY(var(--tw-skew-y)) scaleX(var(--tw-scale-x)) scaleY(var(--tw-scale-y))}.translate-y-0{--tw-translate-y:0px}.transform{transform:translate(var(--tw-translate-x),var(--tw-translate-y)) rotate(var(--tw-rotate)) skewX(var(--tw-skew-x)) skewY(var(--tw-skew-y)) scaleX(var(--tw-scale-x)) scaleY(var(--tw-scale-y))}@keyframes bounce{0%,to{transform:translateY(-25%);animation-timing-function:cubic-bezier(.8,0,1,1)}50%{transform:none;animation-timing-function:cubic-bezier(0,0,.2,1)}}.animate-bounce{animation:bounce 1s infinite}@keyframes pulse{50%{opacity:.5}}.animate-pulse{animation:pulse 2s cubic-bezier(.4,0,.6,1) infinite}.cursor-not-allowed{cursor:not-allowed}.cursor-pointer{cursor:pointer}.select-none{-webkit-user-select:none;-moz-user-select:none;user-select:none}.select-all{-webkit-user-select:all;-moz-user-select:all;user-select:all}.grid-cols-1{grid-template-columns:repeat(1,minmax(0,1fr))}.grid-cols-2{grid-template-columns:repeat(2,minmax(0,1fr))}.grid-cols-3{grid-template-columns:repeat(3,minmax(0,1fr))}.grid-cols-4{grid-template-columns:repeat(4,minmax(0,1fr))}.grid-cols-\[1fr_auto\]{grid-template-columns:1fr auto}.flex-col{flex-direction:column}.flex-wrap{flex-wrap:wrap}.items-start{align-items:flex-start}.items-end{align-items:flex-end}.items-center{align-items:center}.items-stretch{align-items:stretch}.justify-center{justify-content:center}.justify-between{justify-content:space-between}.gap-1{gap:.25rem}.gap-1\.5{gap:.375rem}.gap-10{gap:2.5rem}.gap-2{gap:.5rem}.gap-2\.5{gap:.625rem}.gap-3{gap:.75rem}.gap-3\.5{gap:.875rem}.gap-4{gap:1rem}.gap-5{gap:1.25rem}.gap-6{gap:1.5rem}.gap-8{gap:2rem}.gap-x-4{-moz-column-gap:1rem;column-gap:1rem}.gap-y-1{row-gap:.25rem}.space-y-0>:not([hidden])~:not([hidden]){--tw-space-y-reverse:0;margin-top:calc(0px*(1 - var(--tw-space-y-reverse)));margin-bottom:calc(0px*var(--tw-space-y-reverse))}.space-y-0\.5>:not([hidden])~:not([hidden]){--tw-space-y-reverse:0;margin-top:calc(.125rem*(1 - var(--tw-space-y-reverse)));margin-bottom:calc(.125rem*var(--tw-space-y-reverse))}.space-y-1>:not([hidden])~:not([hidden]){--tw-space-y-reverse:0;margin-top:calc(.25rem*(1 - var(--tw-space-y-reverse)));margin-bottom:calc(.25rem*var(--tw-space-y-reverse))}.space-y-1\.5>:not([hidden])~:not([hidden]){--tw-space-y-reverse:0;margin-top:calc(.375rem*(1 - var(--tw-space-y-reverse)));margin-bottom:calc(.375rem*var(--tw-space-y-reverse))}.space-y-2>:not([hidden])~:not([hidden]){--tw-space-y-reverse:0;margin-top:calc(.5rem*(1 - var(--tw-space-y-reverse)));margin-bottom:calc(.5rem*var(--tw-space-y-reverse))}.space-y-2\.5>:not([hidden])~:not([hidden]){--tw-space-y-reverse:0;margin-top:calc(.625rem*(1 - var(--tw-space-y-reverse)));margin-bottom:calc(.625rem*var(--tw-space-y-reverse))}.space-y-3>:not([hidden])~:not([hidden]){--tw-space-y-reverse:0;margin-top:calc(.75rem*(1 - var(--tw-space-y-reverse)));margin-bottom:calc(.75rem*var(--tw-space-y-reverse))}.space-y-3\.5>:not([hidden])~:not([hidden]){--tw-space-y-reverse:0;margin-top:calc(.875rem*(1 - var(--tw-space-y-reverse)));margin-bottom:calc(.875rem*var(--tw-space-y-reverse))}.space-y-4>:not([hidden])~:not([hidden]){--tw-space-y-reverse:0;margin-top:calc(1rem*(1 - var(--tw-space-y-reverse)));margin-bottom:calc(1rem*var(--tw-space-y-reverse))}.space-y-5>:not([hidden])~:not([hidden]){--tw-space-y-reverse:0;margin-top:calc(1.25rem*(1 - var(--tw-space-y-reverse)));margin-bottom:calc(1.25rem*var(--tw-space-y-reverse))}.space-y-6>:not([hidden])~:not([hidden]){--tw-space-y-reverse:0;margin-top:calc(1.5rem*(1 - var(--tw-space-y-reverse)));margin-bottom:calc(1.5rem*var(--tw-space-y-reverse))}.divide-y>:not([hidden])~:not([hidden]){--tw-divide-y-reverse:0;border-top-width:calc(1px*(1 - var(--tw-divide-y-reverse)));border-bottom-width:calc(1px*var(--tw-divide-y-reverse))}.divide-\[\#0d265c\]>:not([hidden])~:not([hidden]){--tw-divide-opacity:1;border-color:rgb(13 38 92/var(--tw-divide-opacity,1))}.divide-\[\#0e275c\]>:not([hidden])~:not([hidden]){--tw-divide-opacity:1;border-color:rgb(14 39 92/var(--tw-divide-opacity,1))}.overflow-hidden{overflow:hidden}.overflow-x-auto{overflow-x:auto}.overflow-y-auto{overflow-y:auto}.overflow-x-hidden{overflow-x:hidden}.truncate{overflow:hidden;text-overflow:ellipsis}.truncate,.whitespace-nowrap{white-space:nowrap}.whitespace-pre-line{white-space:pre-line}.whitespace-pre-wrap{white-space:pre-wrap}.break-words{overflow-wrap:break-word}.break-all{word-break:break-all}.rounded-2xl{border-radius:1rem}.rounded-3xl{border-radius:1.5rem}.rounded-full{border-radius:9999px}.rounded-lg{border-radius:.5rem}.rounded-md{border-radius:.375rem}.rounded-xl{border-radius:.75rem}.rounded-t-3xl{border-top-left-radius:1.5rem;border-top-right-radius:1.5rem}.border{border-width:1px}.border-2{border-width:2px}.border-4{border-width:4px}.border-b{border-bottom-width:1px}.border-l{border-left-width:1px}.border-r{border-right-width:1px}.border-t{border-top-width:1px}.border-dashed{border-style:dashed}.border-\[\#007BFF\]\/30{border-color:rgba(0,123,255,.3)}.border-\[\#00D89A\]\/20{border-color:rgba(0,216,154,.2)}.border-\[\#00D89A\]\/30{border-color:rgba(0,216,154,.3)}.border-\[\#00D89A\]\/40{border-color:rgba(0,216,154,.4)}.border-\[\#00D89A\]\/50{border-color:rgba(0,216,154,.5)}.border-\[\#00D89A\]\/60{border-color:rgba(0,216,154,.6)}.border-\[\#00D9FF\]{--tw-border-opacity:1;border-color:rgb(0 217 255/var(--tw-border-opacity,1))}.border-\[\#00D9FF\]\/20{border-color:rgba(0,217,255,.2)}.border-\[\#00D9FF\]\/30{border-color:rgba(0,217,255,.3)}.border-\[\#00D9FF\]\/40{border-color:rgba(0,217,255,.4)}.border-\[\#00D9FF\]\/50{border-color:rgba(0,217,255,.5)}.border-\[\#0d265c\]{--tw-border-opacity:1;border-color:rgb(13 38 92/var(--tw-border-opacity,1))}.border-\[\#0d2a63\]{--tw-border-opacity:1;border-color:rgb(13 42 99/var(--tw-border-opacity,1))}.border-\[\#0e275c\]{--tw-border-opacity:1;border-color:rgb(14 39 92/var(--tw-border-opacity,1))}.border-\[\#0e306e\]{--tw-border-opacity:1;border-color:rgb(14 48 110/var(--tw-border-opacity,1))}.border-\[\#10326f\]{--tw-border-opacity:1;border-color:rgb(16 50 111/var(--tw-border-opacity,1))}.border-\[\#123982\]{--tw-border-opacity:1;border-color:rgb(18 57 130/var(--tw-border-opacity,1))}.border-\[\#143985\]{--tw-border-opacity:1;border-color:rgb(20 57 133/var(--tw-border-opacity,1))}.border-\[\#16418f\]{--tw-border-opacity:1;border-color:rgb(22 65 143/var(--tw-border-opacity,1))}.border-\[\#174396\]{--tw-border-opacity:1;border-color:rgb(23 67 150/var(--tw-border-opacity,1))}.border-\[\#1e4aa3\]{--tw-border-opacity:1;border-color:rgb(30 74 163/var(--tw-border-opacity,1))}.border-amber-400{--tw-border-opacity:1;border-color:rgb(251 191 36/var(--tw-border-opacity,1))}.border-amber-500{--tw-border-opacity:1;border-color:rgb(245 158 11/var(--tw-border-opacity,1))}.border-amber-500\/30{border-color:rgba(245,158,11,.3)}.border-amber-500\/40{border-color:rgba(245,158,11,.4)}.border-rose-500{--tw-border-opacity:1;border-color:rgb(244 63 94/var(--tw-border-opacity,1))}.border-rose-500\/20{border-color:rgba(244,63,94,.2)}.border-rose-500\/30{border-color:rgba(244,63,94,.3)}.border-rose-500\/40{border-color:rgba(244,63,94,.4)}.border-sky-500\/30{border-color:rgba(14,165,233,.3)}.border-slate-700{--tw-border-opacity:1;border-color:rgb(51 65 85/var(--tw-border-opacity,1))}.border-white{--tw-border-opacity:1;border-color:rgb(255 255 255/var(--tw-border-opacity,1))}.bg-\[\#007BFF\]{--tw-bg-opacity:1;background-color:rgb(0 123 255/var(--tw-bg-opacity,1))}.bg-\[\#007BFF\]\/20{background-color:rgba(0,123,255,.2)}.bg-\[\#00D89A\]{--tw-bg-opacity:1;background-color:rgb(0 216 154/var(--tw-bg-opacity,1))}.bg-\[\#00D89A\]\/10{background-color:rgba(0,216,154,.1)}.bg-\[\#00D89A\]\/15{background-color:rgba(0,216,154,.15)}.bg-\[\#00D89A\]\/20{background-color:rgba(0,216,154,.2)}.bg-\[\#00D89A\]\/5{background-color:rgba(0,216,154,.05)}.bg-\[\#00D9FF\]\/10{background-color:rgba(0,217,255,.1)}.bg-\[\#00D9FF\]\/15{background-color:rgba(0,217,255,.15)}.bg-\[\#00D9FF\]\/20{background-color:rgba(0,217,255,.2)}.bg-\[\#020817\]{--tw-bg-opacity:1;background-color:rgb(2 8 23/var(--tw-bg-opacity,1))}.bg-\[\#020817\]\/80{background-color:rgba(2,8,23,.8)}.bg-\[\#020817\]\/85{background-color:rgba(2,8,23,.85)}.bg-\[\#030d24\]\/95{background-color:rgba(3,13,36,.95)}.bg-\[\#03132e\]{--tw-bg-opacity:1;background-color:rgb(3 19 46/var(--tw-bg-opacity,1))}.bg-\[\#040e24\]{--tw-bg-opacity:1;background-color:rgb(4 14 36/var(--tw-bg-opacity,1))}.bg-\[\#04122d\]{--tw-bg-opacity:1;background-color:rgb(4 18 45/var(--tw-bg-opacity,1))}.bg-\[\#05112b\]\/95{background-color:rgba(5,17,43,.95)}.bg-\[\#051433\]{--tw-bg-opacity:1;background-color:rgb(5 20 51/var(--tw-bg-opacity,1))}.bg-\[\#051433\]\/95{background-color:rgba(5,20,51,.95)}.bg-\[\#061535\]{--tw-bg-opacity:1;background-color:rgb(6 21 53/var(--tw-bg-opacity,1))}.bg-\[\#061535\]\/80{background-color:rgba(6,21,53,.8)}.bg-\[\#061535\]\/85{background-color:rgba(6,21,53,.85)}.bg-\[\#061535\]\/90{background-color:rgba(6,21,53,.9)}.bg-\[\#071638\]{--tw-bg-opacity:1;background-color:rgb(7 22 56/var(--tw-bg-opacity,1))}.bg-\[\#081b42\]\/95{background-color:rgba(8,27,66,.95)}.bg-\[\#081e4b\]{--tw-bg-opacity:1;background-color:rgb(8 30 75/var(--tw-bg-opacity,1))}.bg-\[\#09204e\]{--tw-bg-opacity:1;background-color:rgb(9 32 78/var(--tw-bg-opacity,1))}.bg-\[\#092257\]{--tw-bg-opacity:1;background-color:rgb(9 34 87/var(--tw-bg-opacity,1))}.bg-\[\#0a1e4a\]{--tw-bg-opacity:1;background-color:rgb(10 30 74/var(--tw-bg-opacity,1))}.bg-\[\#0a2356\]{--tw-bg-opacity:1;background-color:rgb(10 35 86/var(--tw-bg-opacity,1))}.bg-\[\#0c2454\]{--tw-bg-opacity:1;background-color:rgb(12 36 84/var(--tw-bg-opacity,1))}.bg-\[\#35F58A\]\/15{background-color:rgba(53,245,138,.15)}.bg-\[\#35F58A\]\/20{background-color:rgba(53,245,138,.2)}.bg-\[\#8B5CF6\]\/20{background-color:rgba(139,92,246,.2)}.bg-amber-400{--tw-bg-opacity:1;background-color:rgb(251 191 36/var(--tw-bg-opacity,1))}.bg-amber-500{--tw-bg-opacity:1;background-color:rgb(245 158 11/var(--tw-bg-opacity,1))}.bg-amber-500\/10{background-color:rgba(245,158,11,.1)}.bg-amber-500\/15{background-color:rgba(245,158,11,.15)}.bg-amber-500\/20{background-color:rgba(245,158,11,.2)}.bg-amber-500\/5{background-color:rgba(245,158,11,.05)}.bg-black{--tw-bg-opacity:1;background-color:rgb(0 0 0/var(--tw-bg-opacity,1))}.bg-black\/60{background-color:rgba(0,0,0,.6)}.bg-black\/80{background-color:rgba(0,0,0,.8)}.bg-rose-500{--tw-bg-opacity:1;background-color:rgb(244 63 94/var(--tw-bg-opacity,1))}.bg-rose-500\/10{background-color:rgba(244,63,94,.1)}.bg-rose-500\/15{background-color:rgba(244,63,94,.15)}.bg-rose-500\/20{background-color:rgba(244,63,94,.2)}.bg-sky-500\/10{background-color:rgba(14,165,233,.1)}.bg-slate-700{--tw-bg-opacity:1;background-color:rgb(51 65 85/var(--tw-bg-opacity,1))}.bg-slate-800{--tw-bg-opacity:1;background-color:rgb(30 41 59/var(--tw-bg-opacity,1))}.bg-transparent{background-color:transparent}.bg-white{--tw-bg-opacity:1;background-color:rgb(255 255 255/var(--tw-bg-opacity,1))}.bg-white\/10{background-color:hsla(0,0%,100%,.1)}.bg-gradient-to-b{background-image:linear-gradient(to bottom,var(--tw-gradient-stops))}.bg-gradient-to-br{background-image:linear-gradient(to bottom right,var(--tw-gradient-stops))}.bg-gradient-to-r{background-image:linear-gradient(to right,var(--tw-gradient-stops))}.from-\[\#00D89A\]\/15{--tw-gradient-from:rgba(0,216,154,.15) var(--tw-gradient-from-position);--tw-gradient-to:rgba(0,216,154,0) var(--tw-gradient-to-position);--tw-gradient-stops:var(--tw-gradient-from),var(--tw-gradient-to)}.from-\[\#06303a\]{--tw-gradient-from:#06303a var(--tw-gradient-from-position);--tw-gradient-to:rgba(6,48,58,0) var(--tw-gradient-to-position);--tw-gradient-stops:var(--tw-gradient-from),var(--tw-gradient-to)}.from-\[\#071a44\]{--tw-gradient-from:#071a44 var(--tw-gradient-from-position);--tw-gradient-to:rgba(7,26,68,0) var(--tw-gradient-to-position);--tw-gradient-stops:var(--tw-gradient-from),var(--tw-gradient-to)}.from-\[\#081d49\]{--tw-gradient-from:#081d49 var(--tw-gradient-from-position);--tw-gradient-to:rgba(8,29,73,0) var(--tw-gradient-to-position);--tw-gradient-stops:var(--tw-gradient-from),var(--tw-gradient-to)}.from-\[\#0b2559\]{--tw-gradient-from:#0b2559 var(--tw-gradient-from-position);--tw-gradient-to:rgba(11,37,89,0) var(--tw-gradient-to-position);--tw-gradient-stops:var(--tw-gradient-from),var(--tw-gradient-to)}.via-\[\#092257\]{--tw-gradient-to:rgba(9,34,87,0) var(--tw-gradient-to-position);--tw-gradient-stops:var(--tw-gradient-from),#092257 var(--tw-gradient-via-position),var(--tw-gradient-to)}.to-\[\#00D9FF\]\/10{--tw-gradient-to:rgba(0,217,255,.1) var(--tw-gradient-to-position)}.to-\[\#020817\]{--tw-gradient-to:#020817 var(--tw-gradient-to-position)}.to-\[\#04112c\]{--tw-gradient-to:#04112c var(--tw-gradient-to-position)}.to-\[\#04122d\]{--tw-gradient-to:#04122d var(--tw-gradient-to-position)}.to-\[\#05112b\]{--tw-gradient-to:#05112b var(--tw-gradient-to-position)}.to-\[\#061535\]{--tw-gradient-to:#061535 var(--tw-gradient-to-position)}.to-\[\#081e4b\]{--tw-gradient-to:#081e4b var(--tw-gradient-to-position)}.object-contain{-o-object-fit:contain;object-fit:contain}.p-0{padding:0}.p-0\.5{padding:.125rem}.p-1{padding:.25rem}.p-1\.5{padding:.375rem}.p-2{padding:.5rem}.p-2\.5{padding:.625rem}.p-3{padding:.75rem}.p-3\.5{padding:.875rem}.p-4{padding:1rem}.p-5{padding:1.25rem}.p-6{padding:1.5rem}.p-8{padding:2rem}.px-1{padding-left:.25rem;padding-right:.25rem}.px-1\.5{padding-left:.375rem;padding-right:.375rem}.px-2{padding-left:.5rem;padding-right:.5rem}.px-2\.5{padding-left:.625rem;padding-right:.625rem}.px-3{padding-left:.75rem;padding-right:.75rem}.px-3\.5{padding-left:.875rem;padding-right:.875rem}.px-4{padding-left:1rem;padding-right:1rem}.px-5{padding-left:1.25rem;padding-right:1.25rem}.px-7{padding-left:1.75rem;padding-right:1.75rem}.py-0{padding-top:0;padding-bottom:0}.py-0\.5{padding-top:.125rem;padding-bottom:.125rem}.py-1{padding-top:.25rem;padding-bottom:.25rem}.py-1\.5{padding-top:.375rem;padding-bottom:.375rem}.py-10{padding-top:2.5rem;padding-bottom:2.5rem}.py-12{padding-top:3rem;padding-bottom:3rem}.py-2{padding-top:.5rem;padding-bottom:.5rem}.py-2\.5{padding-top:.625rem;padding-bottom:.625rem}.py-3{padding-top:.75rem;padding-bottom:.75rem}.py-3\.5{padding-top:.875rem;padding-bottom:.875rem}.py-4{padding-top:1rem;padding-bottom:1rem}.py-8{padding-top:2rem;padding-bottom:2rem}.pb-3{padding-bottom:.75rem}.pb-4{padding-bottom:1rem}.pb-5{padding-bottom:1.25rem}.pl-11{padding-left:2.75rem}.pr-11{padding-right:2.75rem}.pr-2{padding-right:.5rem}.pr-4{padding-right:1rem}.pr-6{padding-right:1.5rem}.pt-0{padding-top:0}.pt-0\.5{padding-top:.125rem}.pt-1{padding-top:.25rem}.pt-2{padding-top:.5rem}.pt-2\.5{padding-top:.625rem}.pt-3{padding-top:.75rem}.pt-5{padding-top:1.25rem}.pt-6{padding-top:1.5rem}.text-left{text-align:left}.text-center{text-align:center}.text-right{text-align:right}.font-mono{font-family:ui-monospace,SFMono-Regular,Menlo,Monaco,Consolas,Liberation Mono,Courier New,monospace}.font-sans{font-family:ui-sans-serif,system-ui,sans-serif,Apple Color Emoji,Segoe UI Emoji,Segoe UI Symbol,Noto Color Emoji}.text-2xl{font-size:1.5rem;line-height:2rem}.text-3xl{font-size:1.875rem;line-height:2.25rem}.text-4xl{font-size:2.25rem;line-height:2.5rem}.text-\[10px\]{font-size:10px}.text-\[11px\]{font-size:11px}.text-\[12px\]{font-size:12px}.text-\[13px\]{font-size:13px}.text-\[17px\]{font-size:17px}.text-\[6px\]{font-size:6px}.text-\[9px\]{font-size:9px}.text-base{font-size:1rem;line-height:1.5rem}.text-lg{font-size:1.125rem;line-height:1.75rem}.text-sm{font-size:.875rem;line-height:1.25rem}.text-xl{font-size:1.25rem;line-height:1.75rem}.text-xs{font-size:.75rem;line-height:1rem}.font-black{font-weight:900}.font-bold{font-weight:700}.font-medium{font-weight:500}.font-normal{font-weight:400}.font-semibold{font-weight:600}.uppercase{text-transform:uppercase}.leading-relaxed{line-height:1.625}.leading-snug{line-height:1.375}.leading-tight{line-height:1.25}.tracking-wide{letter-spacing:.025em}.tracking-wider{letter-spacing:.05em}.tracking-widest{letter-spacing:.1em}.text-\[\#007BFF\]{--tw-text-opacity:1;color:rgb(0 123 255/var(--tw-text-opacity,1))}.text-\[\#00D89A\]{--tw-text-opacity:1;color:rgb(0 216 154/var(--tw-text-opacity,1))}.text-\[\#00D89A\]\/20{color:rgba(0,216,154,.2)}.text-\[\#00D9FF\]{--tw-text-opacity:1;color:rgb(0 217 255/var(--tw-text-opacity,1))}.text-\[\#00D9FF\]\/20{color:rgba(0,217,255,.2)}.text-\[\#020817\]{--tw-text-opacity:1;color:rgb(2 8 23/var(--tw-text-opacity,1))}.text-\[\#061535\]{--tw-text-opacity:1;color:rgb(6 21 53/var(--tw-text-opacity,1))}.text-\[\#25D366\]{--tw-text-opacity:1;color:rgb(37 211 102/var(--tw-text-opacity,1))}.text-\[\#35F58A\]{--tw-text-opacity:1;color:rgb(53 245 138/var(--tw-text-opacity,1))}.text-\[\#35F58A\]\/20{color:rgba(53,245,138,.2)}.text-\[\#38BDF8\]{--tw-text-opacity:1;color:rgb(56 189 248/var(--tw-text-opacity,1))}.text-\[\#8B5CF6\]{--tw-text-opacity:1;color:rgb(139 92 246/var(--tw-text-opacity,1))}.text-\[\#F5F8FC\]{--tw-text-opacity:1;color:rgb(245 248 252/var(--tw-text-opacity,1))}.text-\[\#FBBF24\]{--tw-text-opacity:1;color:rgb(251 191 36/var(--tw-text-opacity,1))}.text-amber-300{--tw-text-opacity:1;color:rgb(252 211 77/var(--tw-text-opacity,1))}.text-amber-400{--tw-text-opacity:1;color:rgb(251 191 36/var(--tw-text-opacity,1))}.text-cyan-400{--tw-text-opacity:1;color:rgb(34 211 238/var(--tw-text-opacity,1))}.text-emerald-400{--tw-text-opacity:1;color:rgb(52 211 153/var(--tw-text-opacity,1))}.text-rose-300{--tw-text-opacity:1;color:rgb(253 164 175/var(--tw-text-opacity,1))}.text-rose-400{--tw-text-opacity:1;color:rgb(251 113 133/var(--tw-text-opacity,1))}.text-sky-200{--tw-text-opacity:1;color:rgb(186 230 253/var(--tw-text-opacity,1))}.text-slate-100{--tw-text-opacity:1;color:rgb(241 245 249/var(--tw-text-opacity,1))}.text-slate-200{--tw-text-opacity:1;color:rgb(226 232 240/var(--tw-text-opacity,1))}.text-slate-300{--tw-text-opacity:1;color:rgb(203 213 225/var(--tw-text-opacity,1))}.text-slate-400{--tw-text-opacity:1;color:rgb(148 163 184/var(--tw-text-opacity,1))}.text-slate-500{--tw-text-opacity:1;color:rgb(100 116 139/var(--tw-text-opacity,1))}.text-white{--tw-text-opacity:1;color:rgb(255 255 255/var(--tw-text-opacity,1))}.line-through{text-decoration-line:line-through}.accent-\[\#00D89A\]{accent-color:#00d89a}.accent-rose-500{accent-color:#f43f5e}.opacity-0{opacity:0}.opacity-100{opacity:1}.opacity-50{opacity:.5}.opacity-60{opacity:.6}.opacity-75{opacity:.75}.shadow-2xl{--tw-shadow:0 25px 50px -12px rgba(0,0,0,.25);--tw-shadow-colored:0 25px 50px -12px var(--tw-shadow-color);box-shadow:var(--tw-ring-offset-shadow,0 0 #0000),var(--tw-ring-shadow,0 0 #0000),var(--tw-shadow)}.shadow-\[0_0_10px_rgba\(0\2c 123\2c 255\2c 0\.4\)\]{--tw-shadow:0 0 10px rgba(0,123,255,.4);--tw-shadow-colored:0 0 10px var(--tw-shadow-color);box-shadow:var(--tw-ring-offset-shadow,0 0 #0000),var(--tw-ring-shadow,0 0 #0000),var(--tw-shadow)}.shadow-\[0_0_10px_rgba\(0\2c 216\2c 154\2c 0\.4\)\]{--tw-shadow:0 0 10px rgba(0,216,154,.4);--tw-shadow-colored:0 0 10px var(--tw-shadow-color);box-shadow:var(--tw-ring-offset-shadow,0 0 #0000),var(--tw-ring-shadow,0 0 #0000),var(--tw-shadow)}.shadow-\[0_0_10px_rgba\(0\2c 217\2c 255\2c 0\.2\)\]{--tw-shadow:0 0 10px rgba(0,217,255,.2);--tw-shadow-colored:0 0 10px var(--tw-shadow-color);box-shadow:var(--tw-ring-offset-shadow,0 0 #0000),var(--tw-ring-shadow,0 0 #0000),var(--tw-shadow)}.shadow-\[0_0_10px_rgba\(0\2c 217\2c 255\2c 0\.4\)\]{--tw-shadow:0 0 10px rgba(0,217,255,.4);--tw-shadow-colored:0 0 10px var(--tw-shadow-color);box-shadow:var(--tw-ring-offset-shadow,0 0 #0000),var(--tw-ring-shadow,0 0 #0000),var(--tw-shadow)}.shadow-\[0_0_10px_rgba\(139\2c 92\2c 246\2c 0\.4\)\]{--tw-shadow:0 0 10px rgba(139,92,246,.4);--tw-shadow-colored:0 0 10px var(--tw-shadow-color);box-shadow:var(--tw-ring-offset-shadow,0 0 #0000),var(--tw-ring-shadow,0 0 #0000),var(--tw-shadow)}.shadow-\[0_0_15px_rgba\(0\2c 217\2c 255\2c 0\.1\)\]{--tw-shadow:0 0 15px rgba(0,217,255,.1);--tw-shadow-colored:0 0 15px var(--tw-shadow-color);box-shadow:var(--tw-ring-offset-shadow,0 0 #0000),var(--tw-ring-shadow,0 0 #0000),var(--tw-shadow)}.shadow-\[0_0_15px_rgba\(0\2c 217\2c 255\2c 0\.15\)\]{--tw-shadow:0 0 15px rgba(0,217,255,.15);--tw-shadow-colored:0 0 15px var(--tw-shadow-color);box-shadow:var(--tw-ring-offset-shadow,0 0 #0000),var(--tw-ring-shadow,0 0 #0000),var(--tw-shadow)}.shadow-\[0_0_18px_rgba\(0\2c 216\2c 154\2c 0\.15\)\]{--tw-shadow:0 0 18px rgba(0,216,154,.15);--tw-shadow-colored:0 0 18px var(--tw-shadow-color);box-shadow:var(--tw-ring-offset-shadow,0 0 #0000),var(--tw-ring-shadow,0 0 #0000),var(--tw-shadow)}.shadow-\[0_0_20px_rgba\(0\2c 217\2c 255\2c 0\.12\)\]{--tw-shadow:0 0 20px rgba(0,217,255,.12);--tw-shadow-colored:0 0 20px var(--tw-shadow-color);box-shadow:var(--tw-ring-offset-shadow,0 0 #0000),var(--tw-ring-shadow,0 0 #0000),var(--tw-shadow)}.shadow-\[0_0_20px_rgba\(0\2c 217\2c 255\2c 0\.4\)\]{--tw-shadow:0 0 20px rgba(0,217,255,.4);--tw-shadow-colored:0 0 20px var(--tw-shadow-color);box-shadow:var(--tw-ring-offset-shadow,0 0 #0000),var(--tw-ring-shadow,0 0 #0000),var(--tw-shadow)}.shadow-\[0_0_25px_rgba\(0\2c 217\2c 255\2c 0\.3\)\]{--tw-shadow:0 0 25px rgba(0,217,255,.3);--tw-shadow-colored:0 0 25px var(--tw-shadow-color);box-shadow:var(--tw-ring-offset-shadow,0 0 #0000),var(--tw-ring-shadow,0 0 #0000),var(--tw-shadow)}.shadow-\[0_0_8px_rgba\(0\2c 216\2c 154\2c 0\.8\)\]{--tw-shadow:0 0 8px rgba(0,216,154,.8);--tw-shadow-colored:0 0 8px var(--tw-shadow-color)}.shadow-\[0_0_8px_rgba\(0\2c 216\2c 154\2c 0\.8\)\],.shadow-lg{box-shadow:var(--tw-ring-offset-shadow,0 0 #0000),var(--tw-ring-shadow,0 0 #0000),var(--tw-shadow)}.shadow-lg{--tw-shadow:0 10px 15px -3px rgba(0,0,0,.1),0 4px 6px -4px rgba(0,0,0,.1);--tw-shadow-colored:0 10px 15px -3px var(--tw-shadow-color),0 4px 6px -4px var(--tw-shadow-color)}.shadow-md{--tw-shadow:0 4px 6px -1px rgba(0,0,0,.1),0 2px 4px -2px rgba(0,0,0,.1);--tw-shadow-colored:0 4px 6px -1px var(--tw-shadow-color),0 2px 4px -2px var(--tw-shadow-color)}.shadow-md,.shadow-sm{box-shadow:var(--tw-ring-offset-shadow,0 0 #0000),var(--tw-ring-shadow,0 0 #0000),var(--tw-shadow)}.shadow-sm{--tw-shadow:0 1px 2px 0 rgba(0,0,0,.05);--tw-shadow-colored:0 1px 2px 0 var(--tw-shadow-color)}.shadow-xl{--tw-shadow:0 20px 25px -5px rgba(0,0,0,.1),0 8px 10px -6px rgba(0,0,0,.1);--tw-shadow-colored:0 20px 25px -5px var(--tw-shadow-color),0 8px 10px -6px var(--tw-shadow-color);box-shadow:var(--tw-ring-offset-shadow,0 0 #0000),var(--tw-ring-shadow,0 0 #0000),var(--tw-shadow)}.shadow-black{--tw-shadow-color:#000;--tw-shadow:var(--tw-shadow-colored)}.shadow-black\/40{--tw-shadow-color:rgba(0,0,0,.4);--tw-shadow:var(--tw-shadow-colored)}.shadow-cyan-500{--tw-shadow-color:#06b6d4;--tw-shadow:var(--tw-shadow-colored)}.shadow-cyan-500\/10{--tw-shadow-color:rgba(6,182,212,.1);--tw-shadow:var(--tw-shadow-colored)}.shadow-cyan-500\/20{--tw-shadow-color:rgba(6,182,212,.2);--tw-shadow:var(--tw-shadow-colored)}.shadow-cyan-900{--tw-shadow-color:#164e63;--tw-shadow:var(--tw-shadow-colored)}.shadow-cyan-900\/10{--tw-shadow-color:rgba(22,78,99,.1);--tw-shadow:var(--tw-shadow-colored)}.outline{outline-style:solid}.blur{--tw-blur:blur(8px)}.blur,.filter{filter:var(--tw-blur) var(--tw-brightness) var(--tw-contrast) var(--tw-grayscale) var(--tw-hue-rotate) var(--tw-invert) var(--tw-saturate) var(--tw-sepia) var(--tw-drop-shadow)}.backdrop-blur-2xl{--tw-backdrop-blur:blur(40px)}.backdrop-blur-2xl,.backdrop-blur-lg{-webkit-backdrop-filter:var(--tw-backdrop-blur) var(--tw-backdrop-brightness) var(--tw-backdrop-contrast) var(--tw-backdrop-grayscale) var(--tw-backdrop-hue-rotate) var(--tw-backdrop-invert) var(--tw-backdrop-opacity) var(--tw-backdrop-saturate) var(--tw-backdrop-sepia);backdrop-filter:var(--tw-backdrop-blur) var(--tw-backdrop-brightness) var(--tw-backdrop-contrast) var(--tw-backdrop-grayscale) var(--tw-backdrop-hue-rotate) var(--tw-backdrop-invert) var(--tw-backdrop-opacity) var(--tw-backdrop-saturate) var(--tw-backdrop-sepia)}.backdrop-blur-lg{--tw-backdrop-blur:blur(16px)}.backdrop-blur-md{--tw-backdrop-blur:blur(12px)}.backdrop-blur-md,.backdrop-blur-sm{-webkit-backdrop-filter:var(--tw-backdrop-blur) var(--tw-backdrop-brightness) var(--tw-backdrop-contrast) var(--tw-backdrop-grayscale) var(--tw-backdrop-hue-rotate) var(--tw-backdrop-invert) var(--tw-backdrop-opacity) var(--tw-backdrop-saturate) var(--tw-backdrop-sepia);backdrop-filter:var(--tw-backdrop-blur) var(--tw-backdrop-brightness) var(--tw-backdrop-contrast) var(--tw-backdrop-grayscale) var(--tw-backdrop-hue-rotate) var(--tw-backdrop-invert) var(--tw-backdrop-opacity) var(--tw-backdrop-saturate) var(--tw-backdrop-sepia)}.backdrop-blur-sm{--tw-backdrop-blur:blur(4px)}.backdrop-blur-xl{--tw-backdrop-blur:blur(24px)}.backdrop-blur-xl,.backdrop-filter{-webkit-backdrop-filter:var(--tw-backdrop-blur) var(--tw-backdrop-brightness) var(--tw-backdrop-contrast) var(--tw-backdrop-grayscale) var(--tw-backdrop-hue-rotate) var(--tw-backdrop-invert) var(--tw-backdrop-opacity) var(--tw-backdrop-saturate) var(--tw-backdrop-sepia);backdrop-filter:var(--tw-backdrop-blur) var(--tw-backdrop-brightness) var(--tw-backdrop-contrast) var(--tw-backdrop-grayscale) var(--tw-backdrop-hue-rotate) var(--tw-backdrop-invert) var(--tw-backdrop-opacity) var(--tw-backdrop-saturate) var(--tw-backdrop-sepia)}.transition{transition-property:color,background-color,border-color,text-decoration-color,fill,stroke,opacity,box-shadow,transform,filter,-webkit-backdrop-filter;transition-property:color,background-color,border-color,text-decoration-color,fill,stroke,opacity,box-shadow,transform,filter,backdrop-filter;transition-property:color,background-color,border-color,text-decoration-color,fill,stroke,opacity,box-shadow,transform,filter,backdrop-filter,-webkit-backdrop-filter;transition-timing-function:cubic-bezier(.4,0,.2,1);transition-duration:.15s}.transition-all{transition-property:all;transition-timing-function:cubic-bezier(.4,0,.2,1);transition-duration:.15s}.transition-opacity{transition-property:opacity;transition-timing-function:cubic-bezier(.4,0,.2,1);transition-duration:.15s}.transition-transform{transition-property:transform;transition-timing-function:cubic-bezier(.4,0,.2,1);transition-duration:.15s}.duration-200{transition-duration:.2s}.duration-300{transition-duration:.3s}.ease-in{transition-timing-function:cubic-bezier(.4,0,1,1)}.ease-in-out{transition-timing-function:cubic-bezier(.4,0,.2,1)}.ease-out{transition-timing-function:cubic-bezier(0,0,.2,1)}.selection\:bg-\[\#00D9FF\] ::-moz-selection{--tw-bg-opacity:1;background-color:rgb(0 217 255/var(--tw-bg-opacity,1))}.selection\:bg-\[\#00D9FF\] ::selection{--tw-bg-opacity:1;background-color:rgb(0 217 255/var(--tw-bg-opacity,1))}.selection\:text-\[\#020817\] ::-moz-selection{--tw-text-opacity:1;color:rgb(2 8 23/var(--tw-text-opacity,1))}.selection\:text-\[\#020817\] ::selection{--tw-text-opacity:1;color:rgb(2 8 23/var(--tw-text-opacity,1))}.selection\:text-\[\#061535\] ::-moz-selection{--tw-text-opacity:1;color:rgb(6 21 53/var(--tw-text-opacity,1))}.selection\:text-\[\#061535\] ::selection{--tw-text-opacity:1;color:rgb(6 21 53/var(--tw-text-opacity,1))}.selection\:bg-\[\#00D9FF\]::-moz-selection{--tw-bg-opacity:1;background-color:rgb(0 217 255/var(--tw-bg-opacity,1))}.selection\:bg-\[\#00D9FF\]::selection{--tw-bg-opacity:1;background-color:rgb(0 217 255/var(--tw-bg-opacity,1))}.selection\:text-\[\#020817\]::-moz-selection{--tw-text-opacity:1;color:rgb(2 8 23/var(--tw-text-opacity,1))}.selection\:text-\[\#020817\]::selection{--tw-text-opacity:1;color:rgb(2 8 23/var(--tw-text-opacity,1))}.selection\:text-\[\#061535\]::-moz-selection{--tw-text-opacity:1;color:rgb(6 21 53/var(--tw-text-opacity,1))}.selection\:text-\[\#061535\]::selection{--tw-text-opacity:1;color:rgb(6 21 53/var(--tw-text-opacity,1))}.file\:mr-4::file-selector-button{margin-right:1rem}.file\:rounded-xl::file-selector-button{border-radius:.75rem}.file\:border-0::file-selector-button{border-width:0}.file\:bg-\[\#007BFF\]::file-selector-button{--tw-bg-opacity:1;background-color:rgb(0 123 255/var(--tw-bg-opacity,1))}.file\:px-4::file-selector-button{padding-left:1rem;padding-right:1rem}.file\:py-2::file-selector-button{padding-top:.5rem;padding-bottom:.5rem}.file\:py-2\.5::file-selector-button{padding-top:.625rem;padding-bottom:.625rem}.file\:text-xs::file-selector-button{font-size:.75rem;line-height:1rem}.file\:font-semibold::file-selector-button{font-weight:600}.file\:text-white::file-selector-button{--tw-text-opacity:1;color:rgb(255 255 255/var(--tw-text-opacity,1))}.after\:absolute:after{content:var(--tw-content);position:absolute}.after\:left-\[2px\]:after{content:var(--tw-content);left:2px}.after\:top-\[2px\]:after{content:var(--tw-content);top:2px}.after\:h-5:after{content:var(--tw-content);height:1.25rem}.after\:w-5:after{content:var(--tw-content);width:1.25rem}.after\:rounded-full:after{content:var(--tw-content);border-radius:9999px}.after\:border:after{content:var(--tw-content);border-width:1px}.after\:border-gray-300:after{content:var(--tw-content);--tw-border-opacity:1;border-color:rgb(209 213 219/var(--tw-border-opacity,1))}.after\:bg-white:after{content:var(--tw-content);--tw-bg-opacity:1;background-color:rgb(255 255 255/var(--tw-bg-opacity,1))}.after\:transition-all:after{content:var(--tw-content);transition-property:all;transition-timing-function:cubic-bezier(.4,0,.2,1);transition-duration:.15s}.after\:content-\[\'\'\]:after{--tw-content:"";content:var(--tw-content)}.hover\:border-\[\#00D9FF\]:hover{--tw-border-opacity:1;border-color:rgb(0 217 255/var(--tw-border-opacity,1))}.hover\:border-\[\#00D9FF\]\/40:hover{border-color:rgba(0,217,255,.4)}.hover\:border-amber-400:hover{--tw-border-opacity:1;border-color:rgb(251 191 36/var(--tw-border-opacity,1))}.hover\:bg-\[\#007BFF\]:hover{--tw-bg-opacity:1;background-color:rgb(0 123 255/var(--tw-bg-opacity,1))}.hover\:bg-\[\#00D9FF\]\/20:hover{background-color:rgba(0,217,255,.2)}.hover\:bg-\[\#051433\]\/60:hover{background-color:rgba(5,20,51,.6)}.hover\:bg-\[\#092257\]:hover{--tw-bg-opacity:1;background-color:rgb(9 34 87/var(--tw-bg-opacity,1))}.hover\:bg-\[\#0a1e4a\]:hover{--tw-bg-opacity:1;background-color:rgb(10 30 74/var(--tw-bg-opacity,1))}.hover\:bg-\[\#0c245c\]:hover{--tw-bg-opacity:1;background-color:rgb(12 36 92/var(--tw-bg-opacity,1))}.hover\:bg-\[\#0c2e75\]:hover{--tw-bg-opacity:1;background-color:rgb(12 46 117/var(--tw-bg-opacity,1))}.hover\:bg-\[\#0f2d6e\]:hover{--tw-bg-opacity:1;background-color:rgb(15 45 110/var(--tw-bg-opacity,1))}.hover\:bg-rose-500\/30:hover{background-color:rgba(244,63,94,.3)}.hover\:bg-slate-800:hover{--tw-bg-opacity:1;background-color:rgb(30 41 59/var(--tw-bg-opacity,1))}.hover\:text-\[\#00D89A\]:hover{--tw-text-opacity:1;color:rgb(0 216 154/var(--tw-text-opacity,1))}.hover\:text-\[\#00D9FF\]:hover{--tw-text-opacity:1;color:rgb(0 217 255/var(--tw-text-opacity,1))}.hover\:text-rose-300:hover{--tw-text-opacity:1;color:rgb(253 164 175/var(--tw-text-opacity,1))}.hover\:text-rose-400:hover{--tw-text-opacity:1;color:rgb(251 113 133/var(--tw-text-opacity,1))}.hover\:text-white:hover{--tw-text-opacity:1;color:rgb(255 255 255/var(--tw-text-opacity,1))}.hover\:underline:hover{text-decoration-line:underline}.hover\:file\:bg-\[\#0056b3\]::file-selector-button:hover{--tw-bg-opacity:1;background-color:rgb(0 86 179/var(--tw-bg-opacity,1))}.focus\:border-\[\#00D89A\]:focus{--tw-border-opacity:1;border-color:rgb(0 216 154/var(--tw-border-opacity,1))}.focus\:border-\[\#00D9FF\]:focus{--tw-border-opacity:1;border-color:rgb(0 217 255/var(--tw-border-opacity,1))}.focus\:border-amber-400:focus{--tw-border-opacity:1;border-color:rgb(251 191 36/var(--tw-border-opacity,1))}.focus\:outline-none:focus{outline:2px solid transparent;outline-offset:2px}.active\:scale-95:active{--tw-scale-x:.95;--tw-scale-y:.95;transform:translate(var(--tw-translate-x),var(--tw-translate-y)) rotate(var(--tw-rotate)) skewX(var(--tw-skew-x)) skewY(var(--tw-skew-y)) scaleX(var(--tw-scale-x)) scaleY(var(--tw-scale-y))}.group:hover .group-hover\:text-\[\#00D89A\]{--tw-text-opacity:1;color:rgb(0 216 154/var(--tw-text-opacity,1))}.group:hover .group-hover\:text-\[\#00D9FF\]{--tw-text-opacity:1;color:rgb(0 217 255/var(--tw-text-opacity,1))}.group:hover .group-hover\:text-amber-400{--tw-text-opacity:1;color:rgb(251 191 36/var(--tw-text-opacity,1))}.group:hover .group-hover\:text-white{--tw-text-opacity:1;color:rgb(255 255 255/var(--tw-text-opacity,1))}.peer:checked~.peer-checked\:border-\[\#00D9FF\]{--tw-border-opacity:1;border-color:rgb(0 217 255/var(--tw-border-opacity,1))}.peer:checked~.peer-checked\:bg-\[\#00D89A\]{--tw-bg-opacity:1;background-color:rgb(0 216 154/var(--tw-bg-opacity,1))}.peer:checked~.peer-checked\:bg-\[\#00D9FF\]\/10{background-color:rgba(0,217,255,.1)}.peer:checked~.peer-checked\:text-\[\#00D9FF\]{--tw-text-opacity:1;color:rgb(0 217 255/var(--tw-text-opacity,1))}.peer:checked~.peer-checked\:after\:translate-x-full:after{content:var(--tw-content);--tw-translate-x:100%;transform:translate(var(--tw-translate-x),var(--tw-translate-y)) rotate(var(--tw-rotate)) skewX(var(--tw-skew-x)) skewY(var(--tw-skew-y)) scaleX(var(--tw-scale-x)) scaleY(var(--tw-scale-y))}.peer:checked~.peer-checked\:after\:border-white:after{content:var(--tw-content);--tw-border-opacity:1;border-color:rgb(255 255 255/var(--tw-border-opacity,1))}.peer:focus~.peer-focus\:outline-none{outline:2px solid transparent;outline-offset:2px}@media (min-width:640px){.sm\:col-span-2{grid-column:span 2/span 2}.sm\:inline{display:inline}.sm\:w-auto{width:auto}.sm\:max-w-lg{max-width:32rem}.sm\:grid-cols-2{grid-template-columns:repeat(2,minmax(0,1fr))}.sm\:grid-cols-3{grid-template-columns:repeat(3,minmax(0,1fr))}.sm\:grid-cols-4{grid-template-columns:repeat(4,minmax(0,1fr))}.sm\:flex-row{flex-direction:row}.sm\:items-center{align-items:center}.sm\:justify-end{justify-content:flex-end}.sm\:rounded-3xl{border-radius:1.5rem}.sm\:border-t-0{border-top-width:0}.sm\:p-4{padding:1rem}.sm\:p-8{padding:2rem}.sm\:px-4{padding-left:1rem;padding-right:1rem}.sm\:pt-0{padding-top:0}.sm\:text-right{text-align:right}.sm\:text-sm{font-size:.875rem;line-height:1.25rem}}@media (min-width:768px){.md\:col-span-3{grid-column:span 3/span 3}.md\:mx-0{margin-left:0;margin-right:0}.md\:ml-72{margin-left:18rem}.md\:flex{display:flex}.md\:hidden{display:none}.md\:w-1\/2{width:50%}.md\:w-auto{width:auto}.md\:flex-none{flex:none}.md\:translate-x-0{--tw-translate-x:0px;transform:translate(var(--tw-translate-x),var(--tw-translate-y)) rotate(var(--tw-rotate)) skewX(var(--tw-skew-x)) skewY(var(--tw-skew-y)) scaleX(var(--tw-scale-x)) scaleY(var(--tw-scale-y))}.md\:grid-cols-2{grid-template-columns:repeat(2,minmax(0,1fr))}.md\:grid-cols-3{grid-template-columns:repeat(3,minmax(0,1fr))}.md\:grid-cols-4{grid-template-columns:repeat(4,minmax(0,1fr))}.md\:flex-row{flex-direction:row}.md\:items-center{align-items:center}.md\:justify-start{justify-content:flex-start}.md\:gap-4{gap:1rem}.md\:p-10{padding:2.5rem}.md\:p-12{padding:3rem}.md\:p-5{padding:1.25rem}.md\:p-6{padding:1.5rem}.md\:p-7{padding:1.75rem}.md\:p-8{padding:2rem}.md\:px-10{padding-left:2.5rem;padding-right:2.5rem}.md\:px-8{padding-left:2rem;padding-right:2rem}.md\:py-16{padding-top:4rem;padding-bottom:4rem}.md\:text-left{text-align:left}.md\:text-2xl{font-size:1.5rem;line-height:2rem}.md\:text-3xl{font-size:1.875rem;line-height:2.25rem}.md\:text-5xl{font-size:3rem;line-height:1}.md\:text-\[11px\]{font-size:11px}.md\:text-base{font-size:1rem;line-height:1.5rem}.md\:text-lg{font-size:1.125rem;line-height:1.75rem}.md\:text-sm{font-size:.875rem;line-height:1.25rem}}</style>
    <!-- FontAwesome Icons -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">

    <style>
        body { touch-action: manipulation; }

        .snz-gradient {
            background: linear-gradient(135deg, #007BFF 0%, #00D9FF 50%, #00D89A 100%);
        }

        .snz-gradient-text {
            background: linear-gradient(135deg, #00D9FF 0%, #00D89A 100%);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
        }

        .active-tab {
            background: linear-gradient(135deg, #007BFF 0%, #00D9FF 50%, #00D89A 100%) !important;
            color: #061535 !important;
            font-weight: 800 !important;
            box-shadow: 0 4px 15px -2px rgba(0, 217, 255, 0.4);
        }

        .interactive-card {
            transition: all 0.3s cubic-bezier(0.16, 1, 0.3, 1);
        }
        .interactive-card:hover {
            transform: translateY(-3px);
            border-color: rgba(0, 217, 255, 0.45) !important;
            box-shadow: 0 16px 36px -8px rgba(6, 21, 53, 0.9), 0 0 20px -2px rgba(0, 217, 255, 0.2);
        }

        @keyframes gentleFloat {
            0%, 100% { transform: translateY(0); }
            50% { transform: translateY(-6px); }
        }
        .float-graphic { animation: gentleFloat 4s ease-in-out infinite; }
        .plan-filter-btn { background: #081e4b; color: #94a3b8; }
        .plan-filter-btn.active-filter { background: linear-gradient(135deg, #007BFF 0%, #00D9FF 50%, #00D89A 100%); color: #061535; border-color: transparent; box-shadow: 0 0 14px rgba(0,217,255,.35); }

    </style>
</head>
<body class="bg-[#061535] text-[#F5F8FC] min-h-screen font-sans selection:bg-[#00D9FF] selection:text-[#061535] relative overflow-x-hidden">

    <!-- 2-Second Floating Toast Notification -->
    <div id="universalToast" class="fixed top-5 left-1/2 -translate-x-1/2 z-[70] w-[90%] max-w-sm bg-[#081b42]/95 backdrop-blur-md border border-[#00D9FF]/40 shadow-2xl rounded-2xl p-4 flex items-center gap-3.5 transition-all duration-300 opacity-0 -translate-y-8 pointer-events-none">
        <div id="toastIconContainer" class="w-10 h-10 rounded-xl flex items-center justify-center text-lg shrink-0"></div>
        <div class="flex-1 overflow-hidden">
            <h4 id="toastTitle" class="text-[11px] font-bold uppercase tracking-wider text-[#00D9FF]"></h4>
            <p id="toastMessage" class="text-sm font-medium text-white truncate"></p>
        </div>
    </div>

    <!-- Mobile 3-Line Drawer Overlay -->
    <div id="drawerOverlay" onclick="toggleDrawer()" class="fixed inset-0 bg-[#061535]/80 backdrop-blur-sm z-40 hidden transition-opacity"></div>

    <!-- Mobile 3-Line Sidebar Menu -->
    <aside id="sidebar" class="fixed top-0 right-0 bottom-0 w-72 bg-[#05112b]/95 backdrop-blur-xl border-l border-[#0d265c] z-50 transform translate-x-full transition-transform duration-300 flex flex-col justify-between p-6">
        <div>
            <div class="flex justify-between items-center pb-5 border-b border-[#0d265c]">
                <div class="flex items-center gap-2.5">
                    <img src="<?= htmlspecialchars($SITE_LOGO) ?>" onerror="this.onerror=null;this.src='data:image/svg+xml,%3Csvg xmlns=%22http://www.w3.org/2000/svg%22 viewBox=%220 0 64 64%22%3E%3Cdefs%3E%3ClinearGradient id=%22g%22 x1=%220%22 y1=%220%22 x2=%221%22 y2=%221%22%3E%3Cstop offset=%220%22 stop-color=%22%23007BFF%22/%3E%3Cstop offset=%22.5%22 stop-color=%22%2300D9FF%22/%3E%3Cstop offset=%221%22 stop-color=%22%2300D89A%22/%3E%3C/linearGradient%3E%3C/defs%3E%3Crect width=%2264%22 height=%2264%22 rx=%2216%22 fill=%22url(%23g)%22/%3E%3Ctext x=%2232%22 y=%2244%22 font-family=%22Arial%22 font-weight=%22900%22 font-size=%2234%22 text-anchor=%22middle%22 fill=%22%23061535%22%3ES%3C/text%3E%3C/svg%3E'" alt="<?= htmlspecialchars($SITE_NAME) ?>" class="w-9 h-9 object-contain shrink-0">
                    <span class="text-lg font-black tracking-wider text-white"><?= snzBrandHtml($SITE_NAME) ?></span>
                </div>
                <button onclick="toggleDrawer()" class="text-slate-400 hover:text-white text-xl">
                    <i class="fa-solid fa-xmark"></i>
                </button>
            </div>

            <nav class="space-y-4 mt-6 text-sm font-semibold">
                <a href="#features" onclick="toggleDrawer()" class="block py-2 text-slate-300 hover:text-[#00D9FF] transition flex items-center gap-2.5">
                    <i class="fa-solid fa-bolt text-[#00D9FF] w-4"></i> Features
                </a>
                <a href="#howitworks" onclick="toggleDrawer()" class="block py-2 text-slate-300 hover:text-[#00D9FF] transition flex items-center gap-2.5">
                    <i class="fa-solid fa-diagram-project text-[#00D89A] w-4"></i> How It Works
                </a>
                <a href="#plans" onclick="toggleDrawer()" class="block py-2 text-slate-300 hover:text-[#00D9FF] transition flex items-center gap-2.5">
                    <i class="fa-solid fa-layer-group text-[#007BFF] w-4"></i> Pricing Plans
                </a>
                <a href="#download" onclick="toggleDrawer()" class="block py-2 text-slate-300 hover:text-[#00D9FF] transition flex items-center gap-2.5">
                    <i class="fa-brands fa-android text-[#35F58A] w-4"></i> Download App
                </a>
            </nav>
        </div>

        <div class="space-y-3 pt-6 border-t border-[#0d265c]">
            <button onclick="openAuthModal('login'); toggleDrawer();" class="w-full py-3 bg-[#092257] hover:bg-[#0c2e75] text-white rounded-2xl text-xs font-bold transition flex items-center justify-center gap-2 border border-[#174396]">
                <i class="fa-solid fa-right-to-bracket text-[#00D9FF]"></i> Sign In
            </button>
            <button onclick="openAuthModal('register'); toggleDrawer();" class="w-full py-3 snz-gradient text-[#061535] rounded-2xl text-xs font-black transition flex items-center justify-center gap-2 shadow-lg shadow-cyan-500/20">
                <i class="fa-solid fa-user-plus"></i> Create Account
            </button>
        </div>
    </aside>

    <!-- Top Navigation Bar -->
    <header class="bg-[#061535]/80 backdrop-blur-md border-b border-[#0d265c] sticky top-0 z-30 px-4 md:px-10 py-3.5 flex justify-between items-center">
        <a href="index.php" class="flex items-center gap-2.5">
            <img src="<?= htmlspecialchars($SITE_LOGO) ?>" onerror="this.onerror=null;this.src='data:image/svg+xml,%3Csvg xmlns=%22http://www.w3.org/2000/svg%22 viewBox=%220 0 64 64%22%3E%3Cdefs%3E%3ClinearGradient id=%22g%22 x1=%220%22 y1=%220%22 x2=%221%22 y2=%221%22%3E%3Cstop offset=%220%22 stop-color=%22%23007BFF%22/%3E%3Cstop offset=%22.5%22 stop-color=%22%2300D9FF%22/%3E%3Cstop offset=%221%22 stop-color=%22%2300D89A%22/%3E%3C/linearGradient%3E%3C/defs%3E%3Crect width=%2264%22 height=%2264%22 rx=%2216%22 fill=%22url(%23g)%22/%3E%3Ctext x=%2232%22 y=%2244%22 font-family=%22Arial%22 font-weight=%22900%22 font-size=%2234%22 text-anchor=%22middle%22 fill=%22%23061535%22%3ES%3C/text%3E%3C/svg%3E'" alt="<?= htmlspecialchars($SITE_NAME) ?> Logo" class="w-9 h-9 object-contain shrink-0">
            <div>
                <span class="text-xl font-black text-white tracking-wide"><?= snzBrandHtml($SITE_NAME) ?></span>
                <span class="block text-[9px] text-[#00D89A] font-semibold tracking-wider uppercase -mt-0.5"><?= htmlspecialchars($SITE_TAGLINE) ?></span>
            </div>
        </a>

        <!-- Desktop Navigation Links -->
        <nav class="hidden md:flex items-center gap-8 text-xs font-bold text-slate-300">
            <a href="#features" class="hover:text-[#00D9FF] transition">Features</a>
            <a href="#howitworks" class="hover:text-[#00D9FF] transition">How It Works</a>
            <a href="#plans" class="hover:text-[#00D9FF] transition">Plans</a>
            <a href="#download" class="hover:text-[#00D9FF] transition">App Download</a>
        </nav>

        <!-- Desktop Action Buttons -->
        <div class="hidden md:flex items-center gap-3">
            <button onclick="openAuthModal('login')" class="bg-[#092257] hover:bg-[#0c2e75] text-white text-xs font-bold px-4 py-2.5 rounded-xl border border-[#174396] transition flex items-center gap-2">
                <i class="fa-solid fa-right-to-bracket text-[#00D9FF]"></i> Sign In
            </button>
            <button onclick="openAuthModal('register')" class="snz-gradient text-[#061535] text-xs font-black px-5 py-2.5 rounded-xl transition shadow-lg shadow-cyan-500/20 flex items-center gap-2">
                <i class="fa-solid fa-user-plus"></i> Get Started
            </button>
        </div>

        <!-- Mobile 3-Line Hamburger Button -->
        <button onclick="toggleDrawer()" class="md:hidden w-10 h-10 bg-[#09204e] border border-[#143985] rounded-xl text-white flex items-center justify-center text-base">
            <i class="fa-solid fa-bars"></i>
        </button>
    </header>

    <!-- HERO SECTION -->
    <section class="max-w-6xl mx-auto px-4 py-10 md:py-16 flex flex-col md:flex-row items-center justify-between gap-10">
        <div class="md:w-1/2 space-y-5 text-center md:text-left">
            <div class="inline-flex items-center gap-2 bg-[#00D9FF]/10 border border-[#00D9FF]/30 px-3.5 py-1.5 rounded-full">
                <i class="fa-solid fa-shield-halved text-[#00D89A] text-xs"></i>
                <span class="text-[11px] font-bold text-[#00D9FF] tracking-wider uppercase">Next-Gen Payment Automation</span>
            </div>

            <h1 class="text-3xl md:text-5xl font-black text-white leading-tight">
                Automate SMS &amp; Binance Pay <span class="snz-gradient-text">In Real-Time</span>
            </h1>

            <p class="text-sm text-slate-300 leading-relaxed max-w-lg mx-auto md:mx-0">
                Instant payment verification for bKash, Nagad, and Binance Pay with zero delays. Connect Telegram bots and websites easily with <?= htmlspecialchars($SITE_NAME) ?> cloud synchronization.
            </p>

            <div class="flex flex-col sm:flex-row items-center gap-3.5 pt-2 justify-center md:justify-start">
                <button onclick="openAuthModal('register')" class="w-full sm:w-auto snz-gradient text-[#061535] font-black px-7 py-3.5 rounded-2xl shadow-xl shadow-cyan-500/20 text-xs tracking-wider uppercase transition flex items-center justify-center gap-2">
                    <i class="fa-solid fa-rocket"></i> Get Started Free
                </button>
                <button onclick="openAuthModal('login')" class="w-full sm:w-auto bg-[#092257] hover:bg-[#0c2e75] text-white font-bold px-7 py-3.5 rounded-2xl border border-[#174396] text-xs transition flex items-center justify-center gap-2">
                    <i class="fa-solid fa-right-to-bracket text-[#00D9FF]"></i> Sign In to Portal
                </button>
            </div>

            <div class="flex items-center justify-center md:justify-start gap-6 pt-3 text-xs text-slate-400">
                <span class="flex items-center gap-1.5"><i class="fa-solid fa-circle-check text-[#00D89A]"></i> 30-Hr Free Trial</span>
                <span class="flex items-center gap-1.5"><i class="fa-solid fa-circle-check text-[#00D89A]"></i> 99.9% Up-time</span>
                <span class="flex items-center gap-1.5"><i class="fa-solid fa-circle-check text-[#00D89A]"></i> Multi-Key Support</span>
            </div>
        </div>

        <!-- Hero Graphic Showcase Card -->
        <div class="md:w-1/2 w-full flex justify-center">
            <div class="interactive-card float-graphic bg-gradient-to-br from-[#081d49] via-[#092257] to-[#04112c] border border-[#143985] p-6 md:p-8 rounded-3xl shadow-2xl max-w-md w-full relative">
                <div class="flex justify-between items-center mb-5 pb-4 border-b border-[#0d265c]">
                    <div class="flex items-center gap-2.5">
                        <img src="<?= htmlspecialchars($SITE_LOGO) ?>" onerror="this.onerror=null;this.src='data:image/svg+xml,%3Csvg xmlns=%22http://www.w3.org/2000/svg%22 viewBox=%220 0 64 64%22%3E%3Cdefs%3E%3ClinearGradient id=%22g%22 x1=%220%22 y1=%220%22 x2=%221%22 y2=%221%22%3E%3Cstop offset=%220%22 stop-color=%22%23007BFF%22/%3E%3Cstop offset=%22.5%22 stop-color=%22%2300D9FF%22/%3E%3Cstop offset=%221%22 stop-color=%22%2300D89A%22/%3E%3C/linearGradient%3E%3C/defs%3E%3Crect width=%2264%22 height=%2264%22 rx=%2216%22 fill=%22url(%23g)%22/%3E%3Ctext x=%2232%22 y=%2244%22 font-family=%22Arial%22 font-weight=%22900%22 font-size=%2234%22 text-anchor=%22middle%22 fill=%22%23061535%22%3ES%3C/text%3E%3C/svg%3E'" alt="Badge" class="w-9 h-9 object-contain shrink-0">
                        <div>
                            <span class="text-xs font-bold text-white block"><?= htmlspecialchars($SITE_NAME) ?> Automation Engine</span>
                            <span class="text-[10px] text-[#00D89A] flex items-center gap-1"><i class="fa-solid fa-circle text-[6px] animate-pulse"></i> Cloud Connected</span>
                        </div>
                    </div>
                    <span class="text-[10px] font-black bg-[#00D9FF]/10 text-[#00D9FF] px-2.5 py-1 rounded-full border border-[#00D9FF]/30">v2.0</span>
                </div>

                <div class="space-y-3 font-mono text-xs">
                    <div class="bg-[#051433] p-3 rounded-2xl border border-[#0d2a63] flex justify-between items-center">
                        <span class="text-slate-300 flex items-center gap-2"><i class="fa-solid fa-mobile-screen text-[#00D9FF]"></i> bKash TrxID</span>
                        <span class="text-[#00D89A] font-bold">+ ৳ 500.00 [AUTO]</span>
                    </div>
                    <div class="bg-[#051433] p-3 rounded-2xl border border-[#0d2a63] flex justify-between items-center">
                        <span class="text-slate-300 flex items-center gap-2"><i class="fa-brands fa-bitcoin text-[#35F58A]"></i> Binance USDT</span>
                        <span class="text-[#00D9FF] font-bold">+ 2.00 USDT (৳ 250)</span>
                    </div>
                    <div class="bg-[#051433] p-3 rounded-2xl border border-[#0d2a63] flex justify-between items-center">
                        <span class="text-slate-300 flex items-center gap-2"><i class="fa-solid fa-key text-amber-400"></i> Active License</span>
                        <span class="text-[#35F58A] font-bold">SNZPAY-ACTIVE ● ON</span>
                    </div>
                </div>

                <div class="mt-5 pt-3 border-t border-[#0d265c] flex justify-between items-center text-xs">
                    <span class="text-slate-400">API Response Speed:</span>
                    <b class="text-[#00D9FF] font-mono">&lt; 150ms</b>
                </div>
            </div>
        </div>
    </section>

    <!-- FEATURES SECTION -->
    <section id="features" class="max-w-6xl mx-auto px-4 py-12">
        <div class="text-center max-w-xl mx-auto mb-10">
            <span class="text-[11px] font-bold text-[#00D89A] uppercase tracking-wider bg-[#00D89A]/10 border border-[#00D89A]/20 px-3 py-1 rounded-full">Core Advantages</span>
            <h2 class="text-2xl md:text-3xl font-black text-white mt-3">Engineered for Automated Payments</h2>
            <p class="text-xs text-slate-300 mt-2">Everything you need to automate incoming transactions directly to your business.</p>
        </div>

        <div class="grid grid-cols-1 md:grid-cols-3 gap-6">
            <div class="interactive-card bg-[#081e4b] border border-[#123982] p-6 rounded-3xl">
                <div class="w-12 h-12 rounded-2xl bg-[#007BFF]/20 text-[#007BFF] flex items-center justify-center text-xl mb-4 border border-[#007BFF]/30">
                    <i class="fa-solid fa-comment-sms"></i>
                </div>
                <h3 class="text-base font-bold text-white mb-2">Automated SMS Reader</h3>
                <p class="text-xs text-slate-300 leading-relaxed">Runs in the background, reading incoming transaction SMS from bKash and Nagad, syncing directly with the cloud database.</p>
            </div>

            <div class="interactive-card bg-[#081e4b] border border-[#123982] p-6 rounded-3xl">
                <div class="w-12 h-12 rounded-2xl bg-[#00D9FF]/20 text-[#00D9FF] flex items-center justify-center text-xl mb-4 border border-[#00D9FF]/30">
                    <i class="fa-brands fa-bitcoin"></i>
                </div>
                <h3 class="text-base font-bold text-white mb-2">Binance Pay Auto-Sync</h3>
                <p class="text-xs text-slate-300 leading-relaxed">Accept USDT deposits starting from <?= $MIN_BINANCE_USDT ?> USDT with live conversion rates calculated automatically.</p>
            </div>

            <div class="interactive-card bg-[#081e4b] border border-[#123982] p-6 rounded-3xl">
                <div class="w-12 h-12 rounded-2xl bg-[#00D89A]/20 text-[#00D89A] flex items-center justify-center text-xl mb-4 border border-[#00D89A]/30">
                    <i class="fa-solid fa-code"></i>
                </div>
                <h3 class="text-base font-bold text-white mb-2">Developer Friendly API</h3>
                <p class="text-xs text-slate-300 leading-relaxed">Easy REST verification endpoints for Telegram Bots, Python, PHP, and Java scripts with complete documentation.</p>
            </div>
        </div>
    </section>

    <!-- HOW IT WORKS SECTION -->
    <section id="howitworks" class="max-w-6xl mx-auto px-4 py-12">
        <div class="text-center max-w-xl mx-auto mb-10">
            <span class="text-[11px] font-bold text-[#00D9FF] uppercase tracking-wider bg-[#00D9FF]/10 border border-[#00D9FF]/20 px-3 py-1 rounded-full">Fast Setup</span>
            <h2 class="text-2xl md:text-3xl font-black text-white mt-3">How <?= htmlspecialchars($SITE_NAME) ?> Works</h2>
            <p class="text-xs text-slate-300 mt-2">Get your automated payment pipeline operational in less than 5 minutes.</p>
        </div>

        <div class="grid grid-cols-1 md:grid-cols-3 gap-6">
            <div class="interactive-card bg-[#081e4b] border border-[#123982] p-6 rounded-3xl relative">
                <span class="text-4xl font-black text-[#00D9FF]/20 absolute top-5 right-6">01</span>
                <div class="w-10 h-10 rounded-xl bg-[#00D9FF]/15 text-[#00D9FF] flex items-center justify-center text-base mb-4">
                    <i class="fa-solid fa-key"></i>
                </div>
                <h3 class="text-sm font-bold text-white mb-1.5">Get Your License Key</h3>
                <p class="text-xs text-slate-300">Sign up and claim your free trial or select a subscription to receive an instant license key.</p>
            </div>

            <div class="interactive-card bg-[#081e4b] border border-[#123982] p-6 rounded-3xl relative">
                <span class="text-4xl font-black text-[#00D89A]/20 absolute top-5 right-6">02</span>
                <div class="w-10 h-10 rounded-xl bg-[#00D89A]/15 text-[#00D89A] flex items-center justify-center text-base mb-4">
                    <i class="fa-brands fa-android"></i>
                </div>
                <h3 class="text-sm font-bold text-white mb-1.5">Install Mobile App</h3>
                <p class="text-xs text-slate-300">Download the <?= htmlspecialchars($SITE_NAME) ?> APK, sign in using your active key, and grant SMS read permissions.</p>
            </div>

            <div class="interactive-card bg-[#081e4b] border border-[#123982] p-6 rounded-3xl relative">
                <span class="text-4xl font-black text-[#35F58A]/20 absolute top-5 right-6">03</span>
                <div class="w-10 h-10 rounded-xl bg-[#35F58A]/15 text-[#35F58A] flex items-center justify-center text-base mb-4">
                    <i class="fa-solid fa-cloud-bolt"></i>
                </div>
                <h3 class="text-sm font-bold text-white mb-1.5">Real-Time Sync</h3>
                <p class="text-xs text-slate-300">Payments are synced instantly to the cloud. Your bot or website verifies TrxIDs automatically.</p>
            </div>
        </div>
    </section>

    <!-- PLANS SECTION -->
    <section id="plans" class="max-w-6xl mx-auto px-4 py-12">
        <div class="text-center max-w-xl mx-auto mb-10">
            <span class="text-[11px] font-bold text-[#00D9FF] uppercase tracking-wider bg-[#00D9FF]/10 border border-[#00D9FF]/20 px-3 py-1 rounded-full">Transparent Pricing</span>
            <h2 class="text-2xl md:text-3xl font-black text-white mt-3">Choose Your Desired Plan</h2>
            <p class="text-xs text-slate-300 mt-2">Start with our free trial or unlock unlimited licenses.</p>
        </div>

        <?php $planCats = snzPlanCategories($homePlans); if ($planCats): ?>
                    <div class="flex flex-wrap items-center justify-center gap-2 mb-5" id="planFilters">
                        <button type="button" onclick="filterPlans('all', this)" class="plan-filter-btn active-filter px-4 py-2 rounded-full text-xs font-bold border border-[#00D9FF]/40 transition">All</button>
                        <?php foreach ($planCats as $pc): ?>
                            <button type="button" onclick="filterPlans(<?= htmlspecialchars(json_encode($pc), ENT_QUOTES) ?>, this)" class="plan-filter-btn px-4 py-2 rounded-full text-xs font-bold border border-[#0e275c] transition"><?= htmlspecialchars($pc) ?></button>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
                        <div class="grid grid-cols-1 md:grid-cols-3 gap-6">
            <?php foreach ($homePlans as $pl):
                $final = snzPlanFinalPrice($pl);
                $disc = (float) $pl['discount_percent'];
                $isTrial = !empty($pl['is_trial']) || $final <= 0;
                $featured = !empty($pl['badge']) && stripos($pl['badge'], 'popular') !== false; ?>
                <div data-cat="<?= htmlspecialchars((string) ($pl['category'] ?? '')) ?>" class="plan-card interactive-card relative <?= $featured ? 'bg-gradient-to-br from-[#06303a] to-[#081e4b] border-[#00D89A]/50' : 'bg-[#081e4b] border-[#123982]' ?> border p-6 rounded-3xl flex flex-col justify-between">
                    <?php if (!empty($pl['is_pinned'])): ?><span class="absolute -top-3 left-5 text-[10px] font-black px-3 py-1 rounded-full bg-amber-400 text-[#061535] shadow-lg">📌 PINNED</span><?php endif; ?>
                    <?php if ($disc > 0): ?><span class="absolute -top-3 right-5 text-[10px] font-black px-3 py-1 rounded-full bg-rose-500 text-white shadow-lg"><?= rtrim(rtrim(number_format($disc, 2), '0'), '.') ?>% OFF</span><?php endif; ?>
                    <div>
                        <?php if (!empty($pl['badge'])): ?><span class="<?= $featured ? 'snz-gradient text-[#061535] font-black' : 'text-[#00D89A] bg-[#00D89A]/10 border border-[#00D89A]/30 font-bold' ?> text-[10px] px-3 py-1 rounded-full"><?= htmlspecialchars($pl['badge']) ?></span><?php endif; ?>
                        <h3 class="text-lg font-bold text-white mt-3"><?= htmlspecialchars($pl['name']) ?></h3>
                        <?php if (!empty($pl['description'])): ?><p class="text-xs text-slate-300 mt-1"><?= htmlspecialchars($pl['description']) ?></p><?php endif; ?>
                        <div class="my-4 flex items-end gap-2">
                            <span class="text-3xl font-black text-white">৳ <?= rtrim(rtrim(number_format($final, 2), '0'), '.') ?></span>
                            <?php if ($disc > 0): ?><span class="text-sm text-slate-500 line-through mb-1">৳ <?= rtrim(rtrim(number_format((float) $pl['price'], 2), '0'), '.') ?></span><?php endif; ?>
                        </div>
                        <ul class="space-y-2 text-xs text-slate-200 mb-5">
                            <li class="flex items-center gap-2"><i class="fa-regular fa-clock text-[#00D9FF] w-4"></i> <?= snzDurationLabel($pl['duration_hours']) ?> access</li>
                            <li class="flex items-center gap-2"><i class="fa-solid fa-mobile-screen text-[#00D89A] w-4"></i> <?= (int) $pl['max_devices'] ?> phone<?= (int) $pl['max_devices'] > 1 ? 's' : '' ?> per key</li>
                            <li class="flex items-center gap-2"><i class="fa-solid fa-link text-amber-400 w-4"></i> <?= (int) $pl['max_brand_keys'] ?> brand key<?= (int) $pl['max_brand_keys'] != 1 ? 's' : '' ?></li>
                        </ul>
                    </div>
                    <button onclick="openAuthModal('register')" class="w-full snz-gradient text-[#061535] font-black py-3 rounded-2xl text-xs transition"><?= $isTrial ? 'Claim Free Trial' : 'Get Started' ?></button>
                </div>
            <?php endforeach; ?>
        </div>
    </section>

    <!-- DOWNLOAD APP CTA -->
    <section id="download" class="max-w-4xl mx-auto px-4 py-12 text-center">
        <div class="interactive-card bg-gradient-to-br from-[#081d49] via-[#092257] to-[#04112c] border border-[#143985] p-8 md:p-12 rounded-3xl shadow-2xl">
            <img src="<?= htmlspecialchars($SITE_LOGO) ?>" onerror="this.onerror=null;this.src='data:image/svg+xml,%3Csvg xmlns=%22http://www.w3.org/2000/svg%22 viewBox=%220 0 64 64%22%3E%3Cdefs%3E%3ClinearGradient id=%22g%22 x1=%220%22 y1=%220%22 x2=%221%22 y2=%221%22%3E%3Cstop offset=%220%22 stop-color=%22%23007BFF%22/%3E%3Cstop offset=%22.5%22 stop-color=%22%2300D9FF%22/%3E%3Cstop offset=%221%22 stop-color=%22%2300D89A%22/%3E%3C/linearGradient%3E%3C/defs%3E%3Crect width=%2264%22 height=%2264%22 rx=%2216%22 fill=%22url(%23g)%22/%3E%3Ctext x=%2232%22 y=%2244%22 font-family=%22Arial%22 font-weight=%22900%22 font-size=%2234%22 text-anchor=%22middle%22 fill=%22%23061535%22%3ES%3C/text%3E%3C/svg%3E'" alt="App Icon" class="w-16 h-16 object-contain mx-auto mb-4">
            <h2 class="text-2xl md:text-3xl font-black text-white">Download the Official <?= htmlspecialchars($SITE_NAME) ?> App</h2>
            <p class="text-xs text-slate-300 max-w-md mx-auto mt-2 mb-6">Install our lightweight application on your phone, sign in with your active license key, and start syncing payments instantly.</p>
            <div class="flex flex-col sm:flex-row items-center justify-center gap-3.5 max-w-xs mx-auto">
                <a href="app-release.apk" download class="w-full snz-gradient text-[#061535] font-black py-3.5 rounded-2xl text-xs uppercase tracking-wider transition shadow-lg shadow-cyan-500/20 flex items-center justify-center gap-2">
                    <i class="fa-solid fa-download"></i> Download APK
                </a>
                <button onclick="openAuthModal('login')" class="w-full bg-[#092257] hover:bg-[#0c2e75] text-white font-bold py-3.5 rounded-2xl border border-[#174396] text-xs transition flex items-center justify-center gap-2">
                    <i class="fa-solid fa-right-to-bracket text-[#00D9FF]"></i> Open Portal
                </button>
            </div>
        </div>
    </section>

    <!-- FOOTER -->
    <footer class="border-t border-[#0d265c] bg-[#040e24] py-8 text-center text-xs text-slate-400">
        <div class="max-w-6xl mx-auto px-4 flex flex-col sm:flex-row justify-between items-center gap-4">
            <div class="flex items-center gap-2.5">
                <img src="<?= htmlspecialchars($SITE_LOGO) ?>" onerror="this.onerror=null;this.src='data:image/svg+xml,%3Csvg xmlns=%22http://www.w3.org/2000/svg%22 viewBox=%220 0 64 64%22%3E%3Cdefs%3E%3ClinearGradient id=%22g%22 x1=%220%22 y1=%220%22 x2=%221%22 y2=%221%22%3E%3Cstop offset=%220%22 stop-color=%22%23007BFF%22/%3E%3Cstop offset=%22.5%22 stop-color=%22%2300D9FF%22/%3E%3Cstop offset=%221%22 stop-color=%22%2300D89A%22/%3E%3C/linearGradient%3E%3C/defs%3E%3Crect width=%2264%22 height=%2264%22 rx=%2216%22 fill=%22url(%23g)%22/%3E%3Ctext x=%2232%22 y=%2244%22 font-family=%22Arial%22 font-weight=%22900%22 font-size=%2234%22 text-anchor=%22middle%22 fill=%22%23061535%22%3ES%3C/text%3E%3C/svg%3E'" alt="<?= htmlspecialchars($SITE_NAME) ?>" class="w-6 h-6 object-contain shrink-0">
                <span class="font-bold text-white"><?= htmlspecialchars($SITE_NAME) ?> Platform</span>
                <span>— <?= htmlspecialchars($SITE_TAGLINE) ?></span>
            </div>
            <div class="flex items-center gap-4 text-slate-400">
                <a href="#features" class="hover:text-white transition">Features</a>
                <a href="#plans" class="hover:text-white transition">Plans</a>
                <button onclick="openAuthModal('login')" class="hover:text-[#00D9FF] transition">Login</button>
                <button onclick="openAuthModal('register')" class="hover:text-[#00D89A] transition">Register</button>
            </div>
        </div>
    </footer>

    <!-- ========================================================================= -->
    <!-- AUTH MODAL -->
    <!-- ========================================================================= -->
    <div id="authModal" class="fixed inset-0 z-[60] bg-[#061535]/85 backdrop-blur-md flex items-center justify-center p-4 transition-all duration-300 opacity-0 pointer-events-none">
        
        <div class="bg-[#081e4b] border border-[#143985] w-full max-w-md rounded-3xl p-6 sm:p-8 shadow-2xl relative">
            
            <button onclick="closeAuthModal()" class="absolute top-5 right-5 text-slate-400 hover:text-white text-lg">
                <i class="fa-solid fa-xmark"></i>
            </button>

            <div class="text-center mb-6">
                <img src="<?= htmlspecialchars($SITE_LOGO) ?>" onerror="this.onerror=null;this.src='data:image/svg+xml,%3Csvg xmlns=%22http://www.w3.org/2000/svg%22 viewBox=%220 0 64 64%22%3E%3Cdefs%3E%3ClinearGradient id=%22g%22 x1=%220%22 y1=%220%22 x2=%221%22 y2=%221%22%3E%3Cstop offset=%220%22 stop-color=%22%23007BFF%22/%3E%3Cstop offset=%22.5%22 stop-color=%22%2300D9FF%22/%3E%3Cstop offset=%221%22 stop-color=%22%2300D89A%22/%3E%3C/linearGradient%3E%3C/defs%3E%3Crect width=%2264%22 height=%2264%22 rx=%2216%22 fill=%22url(%23g)%22/%3E%3Ctext x=%2232%22 y=%2244%22 font-family=%22Arial%22 font-weight=%22900%22 font-size=%2234%22 text-anchor=%22middle%22 fill=%22%23061535%22%3ES%3C/text%3E%3C/svg%3E'" alt="<?= htmlspecialchars($SITE_NAME) ?> Logo" class="w-12 h-12 object-contain mx-auto mb-2">
                <h2 class="text-xl font-black text-white tracking-wide"><?= snzBrandHtml($SITE_NAME) ?> PORTAL</h2>
                <p class="text-slate-300 text-xs mt-0.5">Sign in to your account or create a new one</p>
            </div>

            <!-- Tab Switcher -->
            <div class="flex bg-[#051433] p-1.5 rounded-2xl mb-6 border border-[#0d2a63]">
                <button onclick="switchTab('login')" id="btnLoginTab" class="w-1/2 py-2.5 text-xs font-bold rounded-xl active-tab transition flex items-center justify-center gap-2">
                    <i class="fa-solid fa-arrow-right-to-bracket text-xs"></i> Sign In
                </button>
                <button onclick="switchTab('register')" id="btnRegTab" class="w-1/2 py-2.5 text-xs font-bold rounded-xl text-slate-400 hover:text-white transition flex items-center justify-center gap-2">
                    <i class="fa-solid fa-user-plus text-xs"></i> Register
                </button>
            </div>

            <!-- Login Form -->
            <form id="formLogin" method="POST" class="space-y-4">
                <input type="hidden" name="login" value="1">
                <div>
                    <label class="text-slate-200 text-xs font-semibold block mb-1.5">Email Address or Username</label>
                    <div class="relative">
                        <i class="fa-regular fa-user absolute left-4 top-3.5 text-slate-400 text-sm"></i>
                        <input type="text" name="login_input" required placeholder="Username or email address" class="w-full bg-[#051433] border border-[#0d2a63] rounded-2xl pl-11 pr-4 py-3 text-white text-sm focus:outline-none focus:border-[#00D9FF] transition">
                    </div>
                </div>

                <div>
                    <label class="text-slate-200 text-xs font-semibold block mb-1.5">Password</label>
                    <div class="relative">
                        <i class="fa-solid fa-lock absolute left-4 top-3.5 text-slate-400 text-sm"></i>
                        <input type="password" id="loginPassword" name="password" required placeholder="••••••••" class="w-full bg-[#051433] border border-[#0d2a63] rounded-2xl pl-11 pr-11 py-3 text-white text-sm focus:outline-none focus:border-[#00D9FF] transition">
                        <button type="button" onclick="togglePass('loginPassword', 'eyeIconLogin')" class="absolute right-4 top-3.5 text-slate-400 hover:text-white">
                            <i id="eyeIconLogin" class="fa-regular fa-eye text-sm"></i>
                        </button>
                    </div>
                </div>

                <button type="submit" class="w-full snz-gradient text-[#061535] font-black py-3.5 rounded-2xl shadow-lg shadow-cyan-500/20 transition text-sm flex items-center justify-center gap-2">
                    <i class="fa-solid fa-right-to-bracket"></i> Sign In to Portal
                </button>
            </form>

            <!-- Register Form -->
            <form id="formRegister" method="POST" class="space-y-4 hidden">
                <input type="hidden" name="register" value="1">
                <div>
                    <label class="text-slate-200 text-xs font-semibold block mb-1.5">Username</label>
                    <div class="relative">
                        <i class="fa-regular fa-user absolute left-4 top-3.5 text-slate-400 text-sm"></i>
                        <input type="text" name="username" required placeholder="e.g., mahidxyz" class="w-full bg-[#051433] border border-[#0d2a63] rounded-2xl pl-11 pr-4 py-3 text-white text-sm focus:outline-none focus:border-[#00D9FF] transition">
                    </div>
                </div>

                <div>
                    <label class="text-slate-200 text-xs font-semibold block mb-1.5">Email Address</label>
                    <div class="relative">
                        <i class="fa-regular fa-envelope absolute left-4 top-3.5 text-slate-400 text-sm"></i>
                        <input type="email" name="email" required placeholder="name@example.com" class="w-full bg-[#051433] border border-[#0d2a63] rounded-2xl pl-11 pr-4 py-3 text-white text-sm focus:outline-none focus:border-[#00D9FF] transition">
                    </div>
                </div>

                <div>
                    <label class="text-slate-200 text-xs font-semibold block mb-1.5">Create Password</label>
                    <div class="relative">
                        <i class="fa-solid fa-lock absolute left-4 top-3.5 text-slate-400 text-sm"></i>
                        <input type="password" id="regPassword" name="password" required placeholder="••••••••" class="w-full bg-[#051433] border border-[#0d2a63] rounded-2xl pl-11 pr-11 py-3 text-white text-sm focus:outline-none focus:border-[#00D9FF] transition">
                        <button type="button" onclick="togglePass('regPassword', 'eyeIconReg')" class="absolute right-4 top-3.5 text-slate-400 hover:text-white">
                            <i id="eyeIconReg" class="fa-regular fa-eye text-sm"></i>
                        </button>
                    </div>
                </div>

                <button type="submit" class="w-full snz-gradient text-[#061535] font-black py-3.5 rounded-2xl shadow-lg shadow-cyan-500/20 transition text-sm flex items-center justify-center gap-2">
                    <i class="fa-solid fa-user-check"></i> Complete Registration
                </button>
            </form>

        </div>
    </div>

    <script>
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
            }, 2000);
        }

        if (window.history.replaceState) {
            window.history.replaceState(null, null, window.location.href);
        }

        function toggleDrawer() {
            const sidebar = document.getElementById('sidebar');
            const overlay = document.getElementById('drawerOverlay');
            const isOpen = !sidebar.classList.contains('translate-x-full');

            if (isOpen) {
                sidebar.classList.add('translate-x-full');
                overlay.classList.add('hidden');
            } else {
                sidebar.classList.remove('translate-x-full');
                overlay.classList.remove('hidden');
            }
        }

        function openAuthModal(mode) {
            const modal = document.getElementById('authModal');
            modal.classList.remove('opacity-0', 'pointer-events-none');
            modal.classList.add('opacity-100');
            switchTab(mode);
        }

        function closeAuthModal() {
            const modal = document.getElementById('authModal');
            modal.classList.add('opacity-0', 'pointer-events-none');
            modal.classList.remove('opacity-100');
        }

        function switchTab(tab) {
            const loginForm = document.getElementById('formLogin');
            const regForm = document.getElementById('formRegister');
            const btnLogin = document.getElementById('btnLoginTab');
            const btnReg = document.getElementById('btnRegTab');

            if (tab === 'login') {
                loginForm.classList.remove('hidden');
                regForm.classList.add('hidden');
                btnLogin.classList.add('active-tab');
                btnLogin.classList.remove('text-slate-400');
                btnReg.classList.remove('active-tab');
                btnReg.classList.add('text-slate-400');
            } else {
                loginForm.classList.add('hidden');
                regForm.classList.remove('hidden');
                btnReg.classList.add('active-tab');
                btnReg.classList.remove('text-slate-400');
                btnLogin.classList.remove('active-tab');
                btnLogin.classList.add('text-slate-400');
            }
        }

// প্ল্যান ফিল্টার বাটন
        function filterPlans(cat, btn) {
            document.querySelectorAll('.plan-card').forEach(c => {
                c.style.display = (cat === 'all' || c.dataset.cat === cat) ? '' : 'none';
            });
            document.querySelectorAll('.plan-filter-btn').forEach(b => b.classList.remove('active-filter'));
            if (btn) btn.classList.add('active-filter');
        }

                function togglePass(inputId, eyeId) {
            const input = document.getElementById(inputId);
            const icon = document.getElementById(eyeId);
            if (input.type === 'password') {
                input.type = 'text';
                icon.classList.remove('fa-eye');
                icon.classList.add('fa-eye-slash');
            } else {
                input.type = 'password';
                icon.classList.remove('fa-eye-slash');
                icon.classList.add('fa-eye');
            }
        }

        <?php if ($success): ?>
            showNotification('success', 'SUCCESS', <?= json_encode($success, JSON_HEX_TAG | JSON_HEX_APOS | JSON_UNESCAPED_UNICODE) ?>);
            openAuthModal('login');
        <?php endif; ?>
        <?php if ($error): ?>
            showNotification('error', 'ATTENTION', <?= json_encode($error, JSON_HEX_TAG | JSON_HEX_APOS | JSON_UNESCAPED_UNICODE) ?>);
            openAuthModal(<?= json_encode($authMode) ?>);
        <?php endif; ?>
    </script>
</body>
</html>