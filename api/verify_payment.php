<?php
// =========================================================================
// ✅ Brand / Bot → Payment Verify API
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

sendApiHeaders('GET, POST, OPTIONS');   // এখন browser/JS থেকেও কল করা যাবে (CORS preflight ফিক্স)

$input = readRequestData();             // JSON / form / GET সব সাপোর্ট

$rawKey      = strtoupper(trim((string) ($input['license_key'] ?? $input['api_key'] ?? $input['key'] ?? ($_SERVER['HTTP_X_API_KEY'] ?? ''))));
$trxInput    = strtoupper(trim((string) ($input['trx_id'] ?? $input['trxid'] ?? $input['transaction_id'] ?? '')));
$phoneInput  = trim((string) ($input['phone'] ?? $input['sender'] ?? ''));
$reqMethod   = strtoupper(trim((string) ($input['method'] ?? '')));
$expectedAmt = parseAmount($input['amount'] ?? 0);

// মেথড নামের ভিন্নতা ঠিক করা (bKash, B-KASH, nagad ইত্যাদি)
$methodAliases = ['B-KASH' => 'BKASH', 'BIKASH' => 'BKASH', 'QR' => 'BANGLA_QR', 'BANGLAQR' => 'BANGLA_QR'];
$reqMethod = str_replace(' ', '', $reqMethod);
$reqMethod = $methodAliases[$reqMethod] ?? $reqMethod;

$searchVal = $trxInput !== '' ? $trxInput : strtoupper($phoneInput);

function fail($code, $reason, array $extra = []) {
    // "message" আগের মতোই রাখলাম যাতে পুরনো ব্র্যান্ড ইন্টিগ্রেশন না ভাঙে, "reason" এ আসল কারণ
    jsonResponse($code, array_merge(["status" => "error", "message" => "Failed Transaction", "reason" => $reason], $extra));
}

if ($rawKey === '' || $searchVal === '' || $reqMethod === '') {
    fail(400, "license_key, trx_id (or phone) and method are required.");
}

$pdo = getDBConnection();
if (!$pdo) {
    fail(500, "Database connection error.");
}
ensureDatabaseIndexes($pdo);

// =========================================================================
// ১. লাইসেন্স ভ্যালিডেশন
// =========================================================================
$isMaster = strcasecmp($rawKey, MASTER_KEY) === 0;
$isAuthorized = $isMaster;
$keyOwnerId = MASTER_USER_ID;

if (!$isAuthorized) {
    $stmt = $pdo->prepare("SELECT user_id, expires_at FROM user_keys WHERE license_key = ? AND status = 'active' ORDER BY expires_at DESC LIMIT 1");
    $stmt->execute([$rawKey]);
    $kRow = $stmt->fetch();
    if ($kRow && strtotime($kRow['expires_at']) >= time()) {
        $isAuthorized = true;
        $keyOwnerId = (int) $kRow['user_id'];
    }
}

if (!$isAuthorized) {
    $bStmt = $pdo->prepare("SELECT id, user_id, expires_at, is_connected, plan_duration_hours FROM brand_keys WHERE api_key = ? AND status != 'revoked' LIMIT 1");
    $bStmt->execute([$rawKey]);
    $bRow = $bStmt->fetch();
    if ($bRow) {
        if ((int) $bRow['is_connected'] === 0) {
            // প্রথমবার কানেক্ট → মেয়াদ শুরু
            $duration = intval($bRow['plan_duration_hours']) ?: 720;
            $expiresAt = date("Y-m-d H:i:s", time() + ($duration * 3600));
            $pdo->prepare("UPDATE brand_keys SET is_connected = 1, connected_at = NOW(), expires_at = ?, status = 'active' WHERE id = ?")->execute([$expiresAt, $bRow['id']]);
            $bRow['expires_at'] = $expiresAt;
        }
        // আগে মেয়াদ শেষ হলেও brand key কাজ করত — এখন চেক হয়
        if (empty($bRow['expires_at']) || strtotime($bRow['expires_at']) >= time()) {
            $isAuthorized = true;
            $keyOwnerId = (int) $bRow['user_id'];
        } else {
            fail(403, "API key has expired.");
        }
    }
}

if (!$isAuthorized) {
    jsonResponse(403, ["status" => "error", "message" => "Invalid license key", "reason" => "Invalid or expired license key."]);
}

// =========================================================================
// ২. মেথড প্যাটার্ন গার্ড (ক্রস-মেথড ব্লকার)
// str_starts_with() PHP 8 ছাড়া চলে না → strncmp দিয়ে বদলানো হয়েছে
// =========================================================================
if ($reqMethod === 'BKASH' && $trxInput !== '' && ctype_digit($trxInput)) {
    if (strlen($trxInput) >= 17 || (strlen($trxInput) == 10 && strncmp($trxInput, '70', 2) === 0)) {
        fail(400, "This TrxID does not belong to bKash.");
    }
}

// মেথড অনুযায়ী SQL ফিল্টার
function methodSqlFilter($reqMethod, array &$params) {
    switch ($reqMethod) {
        case 'BKASH':   $params[] = '%BKASH%';   return "UPPER(method) LIKE ?";
        case 'NAGAD':   $params[] = '%NAGAD%';   return "UPPER(method) LIKE ?";
        case 'ROCKET':  $params[] = '%ROCKET%';  return "UPPER(method) LIKE ?";
        case 'BINANCE': $params[] = '%BINANCE%'; return "UPPER(method) LIKE ?";
        case 'BANGLA_QR':
            $params[] = '%QR%'; $params[] = '%TALLYPAY%';
            return "(UPPER(method) LIKE ? OR UPPER(method) LIKE ?)";
        default:
            $params[] = '%' . $reqMethod . '%';
            return "UPPER(method) LIKE ?";
    }
}

// =========================================================================
// ৩. লোকাল ডাটাবেজ সার্চ
// =========================================================================
$payment = null;
$ownerSql = $isMaster ? "" : " AND user_id = ?";   // অন্য ইউজারের পেমেন্ট আর ধরা যাবে না

// (ক) TrxID দিয়ে exact match
if ($trxInput !== '') {
    $cleanTrx = preg_replace('/[^A-Z0-9]/', '', $trxInput);
    $params = [$trxInput, $cleanTrx];
    if (!$isMaster) $params[] = $keyOwnerId;
    $pStmt = $pdo->prepare("SELECT * FROM payments WHERE trx_id IN (?, ?)" . $ownerSql . " ORDER BY (status = 'pending') DESC LIMIT 1");
    $pStmt->execute($params);
    $payment = $pStmt->fetch();
}

// (খ) ফোন নাম্বার দিয়ে খোঁজা — শুধু তখনই যখন ইনপুট আসলেই নাম্বার।
// আগে TrxID না পেলে TrxID এর ভেতরের সংখ্যা দিয়ে অন্য কারো পেমেন্ট "used" হয়ে যেত — সেই বাগ ফিক্স।
if (!$payment) {
    $phoneSource = $phoneInput !== '' ? $phoneInput : ($trxInput !== '' && preg_match('/^\+?[0-9\s-]+$/', $trxInput) && strlen(preg_replace('/\D/', '', $trxInput)) <= 13 ? $trxInput : '');
    $cleanDigits = preg_replace('/[^0-9]/', '', $phoneSource);
    $len = strlen($cleanDigits);

    if ($len >= 3) {
        if ($len >= 10)      $suffix = substr($cleanDigits, -10);  // পুরো নাম্বার
        elseif ($len >= 4)   $suffix = substr($cleanDigits, -4);   // শেষ ৪ ডিজিট
        else                 $suffix = $cleanDigits;               // শেষ ৩ ডিজিট

        $params = ['%' . $suffix];
        $methodSql = methodSqlFilter($reqMethod, $params);
        if (!$isMaster) $params[] = $keyOwnerId;
        $amountSql = "";
        if ($expectedAmt > 0 && $reqMethod !== 'BINANCE') {
            $amountSql = " AND amount >= ?";
            $params[] = $expectedAmt - 0.01;
        }

        $sStmt = $pdo->prepare("SELECT * FROM payments WHERE status = 'pending' AND sender LIKE ? AND $methodSql" . $ownerSql . $amountSql . " ORDER BY id DESC LIMIT 1");
        $sStmt->execute($params);
        $payment = $sStmt->fetch();
    }
}

// =========================================================================
// ৪. লাইভ BINANCE PAY ভেরিফিকেশন (লোকাল ডাটাবেজে না পেলে)
// =========================================================================
if (!$payment && $reqMethod === 'BINANCE' && $trxInput !== '' && function_exists('curl_init')) {
    // আগে অন্য ইউজারের Binance ওয়ালেট দিয়েও চেক হত — এখন শুধু key মালিকের ওয়ালেট
    if ($isMaster) {
        $bWalletStmt = $pdo->prepare("SELECT user_id, api_key, secret_key FROM user_wallets WHERE provider = 'Binance' AND status = 'active' ORDER BY (user_id = ?) DESC LIMIT 1");
    } else {
        $bWalletStmt = $pdo->prepare("SELECT user_id, api_key, secret_key FROM user_wallets WHERE provider = 'Binance' AND status = 'active' AND user_id = ? LIMIT 1");
    }
    $bWalletStmt->execute([$keyOwnerId]);
    $bWallet = $bWalletStmt->fetch();

    if ($bWallet && !empty($bWallet['api_key']) && !empty($bWallet['secret_key'])) {
        $apiKey = trim($bWallet['api_key']);
        $secretKey = trim($bWallet['secret_key']);
        $queryString = "recvWindow=60000&timestamp=" . round(microtime(true) * 1000);
        $signature = hash_hmac('sha256', $queryString, $secretKey);

        $ch = curl_init("https://api.binance.com/sapi/v1/pay/transactions?" . $queryString . "&signature=" . $signature);
        curl_setopt($ch, CURLOPT_HTTPHEADER, ["X-MBX-APIKEY: " . $apiKey]);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 4);
        curl_setopt($ch, CURLOPT_TIMEOUT, 6);
        $res = curl_exec($ch);
        curl_close($ch);

        $json = $res ? json_decode($res, true) : null;
        $txList = is_array($json) ? ($json['data'] ?? []) : [];
        if (is_array($txList)) {
            foreach ($txList as $tx) {
                $ids = [strtoupper(trim((string) ($tx['orderId'] ?? ''))), strtoupper(trim((string) ($tx['transactionId'] ?? '')))];
                $amount = parseAmount($tx['amount'] ?? 0);
                if ($amount > 0 && in_array($trxInput, $ids, true)) {
                    $payer = $tx['payerInfo']['name'] ?? ($tx['payerInfo']['binanceId'] ?? 'Binance User');
                    try {
                        $ins = $pdo->prepare("INSERT INTO payments (trx_id, amount, method, sender, raw_sms, user_id, status, created_at) VALUES (?, ?, 'BINANCE', ?, ?, ?, 'pending', NOW())");
                        $ins->execute([$trxInput, $amount, $payer, "Binance Pay: $amount USDT from $payer", $bWallet['user_id']]);
                    } catch (PDOException $e) { /* অন্য রিকোয়েস্ট আগেই সেভ করেছে */ }

                    $pStmt = $pdo->prepare("SELECT * FROM payments WHERE trx_id = ? LIMIT 1");
                    $pStmt->execute([$trxInput]);
                    $payment = $pStmt->fetch();
                    break;
                }
            }
        }
    }
}

if (!$payment) {
    fail(404, "Transaction not found.");
}

// =========================================================================
// ৫. মেথড অমিল গার্ড
// =========================================================================
$actualMethod = strtoupper((string) $payment['method']);
$isMethodMatch = false;
if ($reqMethod === 'BANGLA_QR') {
    $isMethodMatch = (strpos($actualMethod, 'QR') !== false || strpos($actualMethod, 'TALLYPAY') !== false);
} else {
    $isMethodMatch = strpos($actualMethod, $reqMethod) !== false;
}
if (!$isMethodMatch) {
    fail(400, "Payment method does not match.");
}

if ($payment['status'] === 'used') {
    fail(409, "Transaction already used.", ["trx_id" => $payment['trx_id']]);
}

// =========================================================================
// ৬. ডলার রেট কনভার্সন
// =========================================================================
$receivedAmount = floatval($payment['amount']);
$finalCreditBdt = $receivedAmount;
$dollarRate     = floatval($USDT_RATE ?? 125.00);
$isBinance      = strpos($actualMethod, 'BINANCE') !== false;

if ($isBinance) {
    $rateStmt = $pdo->prepare("SELECT dollar_rate FROM user_wallets WHERE provider = 'Binance' AND status = 'active' ORDER BY (user_id = ?) DESC LIMIT 1");
    $rateStmt->execute([$keyOwnerId]);
    $customRate = floatval($rateStmt->fetchColumn());
    if ($customRate > 0) $dollarRate = $customRate;
    $finalCreditBdt = round($receivedAmount * $dollarRate, 2);
}

// আগে "amount" নেওয়া হত কিন্তু কখনো চেক হত না — এখন কম টাকা পাঠালে reject
if ($expectedAmt > 0) {
    $okBdt  = $finalCreditBdt + 0.01 >= $expectedAmt;
    $okUsdt = $isBinance && $receivedAmount + 0.0001 >= $expectedAmt;
    if (!$okBdt && !$okUsdt) {
        fail(400, "Amount mismatch. Received " . $finalCreditBdt . ", expected " . $expectedAmt . ".");
    }
}

// =========================================================================
// ৭. Used মার্ক করা — atomic (একই TrxID দুইবার একসাথে ভেরিফাই হলে ডাবল ক্রেডিট হবে না)
// =========================================================================
$upd = $pdo->prepare("UPDATE payments SET status = 'used', used_by = ?, used_at = NOW(), claimed_via = 'Bot Verify' WHERE id = ? AND status <> 'used'");
$upd->execute([$keyOwnerId, $payment['id']]);
if ($upd->rowCount() === 0) {
    fail(409, "Transaction already used.", ["trx_id" => $payment['trx_id']]);
}

jsonResponse(200, [
    "status"  => "success",
    "message" => "Payment verified successfully!",
    "data"    => [
        "trx_id"       => $payment['trx_id'],
        "amount"       => $finalCreditBdt,
        "amount_usdt"  => $isBinance ? $receivedAmount : null,
        "dollar_rate"  => $isBinance ? $dollarRate : null,
        "method"       => $payment['method'],
        "sender"       => $payment['sender'] ?? 'N/A',
        "timestamp"    => strtotime($payment['created_at']) * 1000
    ]
]);
