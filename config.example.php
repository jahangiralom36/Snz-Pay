<?php
// =========================================================================
// ⚡ SNZPay - Central Configuration File (Pure MySQL)
// =========================================================================


define('DB_HOST', 'localhost');
define('DB_NAME', 'YOUR_DB_VALUE');
define('DB_USER', 'YOUR_DB_VALUE');
define('DB_PASS', 'YOUR_DB_VALUE');

// মাস্টার লাইসেন্স কী (আগে ৩টা ফাইলে আলাদা আলাদা hard-code করা ছিল)
define('MASTER_KEY', 'CHANGE_ME_MASTER_KEY');
define('MASTER_USER_ID', 1);

// অ্যাপ → সার্ভার SMS পাঠানোর সিক্রেট টোকেন (অ্যাপের সাথে মিল থাকতে হবে)
define('APP_SECRET_TOKEN', 'CHANGE_ME_APP_TOKEN');

$SITE_NAME = "SNZPay";
$SITE_TAGLINE = "Faster • Safer • Smarter";
$SITE_LOGO = "https://i.ibb.co.com/zHs2jjw3/app-logo-png.png";

$ADMIN_PASSWORD = "CHANGE_ME";

// Firebase (খালি রাখলে Firebase sync বন্ধ থাকবে, কোনো error হবে না)
$FIREBASE_URL = "";

$PAYMENT_INFO = [
    "bkash"   => "017XXXXXXXX",
    "nagad"   => "018XXXXXXXX",
    "binance" => "123456789"
];

// অ্যাডমিন প্যানেল থেকে সেভ করা পেমেন্ট নাম্বার এখান থেকে লোড হয়
$settingsFile = __DIR__ . '/payment_settings.json';
if (is_file($settingsFile)) {
    $saved = json_decode((string) @file_get_contents($settingsFile), true);
    if (is_array($saved)) {
        $PAYMENT_INFO = array_merge($PAYMENT_INFO, $saved);
    }
}

$USDT_RATE = 125.00;
$MIN_BINANCE_USDT = 0.25;

// গ্লোবাল পিডিও কানেকশন
function getDBConnection() {
    static $pdo = null;
    if ($pdo !== null) return $pdo;

    $hosts = array_unique([DB_HOST, '127.0.0.1']);
    foreach ($hosts as $h) {
        try {
            $pdo = new PDO("mysql:host=$h;dbname=" . DB_NAME . ";charset=utf8mb4", DB_USER, DB_PASS, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_TIMEOUT => 3,
            ]);
            // MySQL এর NOW() কে PHP এর টাইমজোনের সাথে মিলানো — না হলে key expiry / created_at এ ঘণ্টার গরমিল হয়
            try { $pdo->exec("SET time_zone = '" . date('P') . "'"); } catch (PDOException $e) {}
            return $pdo;
        } catch (PDOException $e) {
            $pdo = null;
        }
    }
    return null;
}

// JSON / form / query — যেভাবেই ডাটা আসুক সব একসাথে পড়া
function readRequestData() {
    $data = [];
    $raw = file_get_contents("php://input");
    if ($raw !== '' && $raw !== false) {
        $json = json_decode($raw, true);
        if (is_array($json)) {
            $data = $json;
        } else {
            parse_str($raw, $form);
            if (is_array($form)) $data = $form;
        }
    }
    return array_merge($_GET, $_POST, $data);
}

// "Tk 1,500.00" / "৳500" / "500.5 USDT" → 1500.00 / 500 / 500.5
function parseAmount($value) {
    $s = str_replace(',', '', (string) $value);
    // বাংলা সংখ্যা → ইংরেজি
    $s = strtr($s, ['০'=>'0','১'=>'1','২'=>'2','৩'=>'3','৪'=>'4','৫'=>'5','৬'=>'6','৭'=>'7','৮'=>'8','৯'=>'9']);
    if (preg_match('/\d+(?:\.\d+)?/', $s, $m)) {
        return floatval($m[0]);
    }
    return 0.0;
}

// API রেসপন্সের জন্য কমন CORS হেডার + OPTIONS preflight হ্যান্ডেল
function sendApiHeaders($methods = 'GET, POST, OPTIONS') {
    header("Content-Type: application/json; charset=UTF-8");
    header("Access-Control-Allow-Origin: *");
    header("Access-Control-Allow-Methods: " . $methods);
    header("Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With, X-API-KEY");
    header("Cache-Control: no-store");
    if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') {
        http_response_code(204);
        exit;
    }
}

function jsonResponse($code, array $payload) {
    http_response_code($code);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    exit;
}

function generateLicenseKey() {
    return "SNZPAY-" . strtoupper(bin2hex(random_bytes(3))) . "-" . strtoupper(bin2hex(random_bytes(3)));
}

function getPlanDetails($planName) {
    if (stripos($planName, '50') !== false || stripos($planName, 'Premium') !== false) {
        return ['price' => 50.00, 'hours' => 24 * 30, 'name' => 'Premium Plan (50 BDT)'];
    } elseif (stripos($planName, 'Trial') !== false) {
        return ['price' => 0.00, 'hours' => 30, 'name' => 'Free Trial (30 Hours)'];
    } else {
        return ['price' => 30.00, 'hours' => 24 * 7, 'name' => 'Standard Plan (30 BDT)'];
    }
}

// Firebase sync (আগে এই ফাংশনটাই ছিল না → admin.php তে key বানালে/ডিলিট করলে Fatal Error হত)
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

// =========================================================================
// ডাটাবেজ ইনডেক্স (একবারই চলে) — বড় টেবিলে সার্চ ১০০ গুণ পর্যন্ত দ্রুত করে
// =========================================================================
function ensureDatabaseIndexes(PDO $pdo) {
    $flag = __DIR__ . '/.db_indexes_v1';
    if (is_file($flag)) return;

    $indexes = [
        ['user_keys',    'idx_uk_license',   'license_key'],
        ['user_keys',    'idx_uk_user',      'user_id'],
        ['brand_keys',   'idx_bk_api',       'api_key'],
        ['payments',     'idx_pay_trx',      'trx_id'],
        ['payments',     'idx_pay_status_user', 'status, user_id'],
        ['payments',     'idx_pay_sender',   'sender'],
        ['user_wallets', 'idx_uw_provider',  'provider, status, user_id'],
    ];

    foreach ($indexes as [$table, $name, $cols]) {
        try {
            $firstCol = trim(explode(',', $cols)[0]);
            $chk = $pdo->prepare("SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ? AND SEQ_IN_INDEX = 1");
            $chk->execute([$table, $firstCol]);
            if ((int) $chk->fetchColumn() > 0) continue; // আগে থেকেই ইনডেক্স আছে
            $pdo->exec("ALTER TABLE `$table` ADD INDEX `$name` ($cols)");
        } catch (Throwable $e) {
            // টেবিল/কলাম না থাকলে বা TEXT কলাম হলে চুপচাপ স্কিপ
        }
    }
    @file_put_contents($flag, date('c'));
}
