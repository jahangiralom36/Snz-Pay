<?php
// Gzip compression (পেজ দ্রুত লোড)
if (!ob_start("ob_gzhandler")) ob_start();

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/config.php';

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
    @file_put_contents($noticeFile, "⚡ Welcome to SNZPay! Automated Payment Gateway & SMS Verifier. Add funds and activate your license plan to connect bots and websites.");
}
$noticeText = file_exists($noticeFile) ? trim(file_get_contents($noticeFile)) : "Welcome to SNZPay!";

// Admin Bangla QR Image & Payment Numbers
$botSettingsFile = __DIR__ . '/bot_settings.json';
$adminSettings = file_exists($botSettingsFile) ? json_decode(file_get_contents($botSettingsFile), true) : [];
$banglaQrImage = $adminSettings['BANGLA_QR_IMAGE'] ?? 'https://i.ibb.co.com/zHs2jjw3/app-logo-png.png';
$bkashNumber = $adminSettings['BKASH'] ?? $PAYMENT_INFO['bkash'];
$nagadNumber = $adminSettings['NAGAD'] ?? $PAYMENT_INFO['nagad'];
$rocketNumber = $adminSettings['ROCKET'] ?? '019XXXXXXXX';
$binancePayId = $PAYMENT_INFO['binance'] ?? '123456789';

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
    $provider = strtolower(trim($_POST['provider'] ?? 'bkash'));

    if ($provider === 'binance' && $amount < $MIN_BINANCE_USDT) {
        $error = "Minimum deposit for Binance is $MIN_BINANCE_USDT USDT!";
    } elseif ($provider !== 'binance' && $amount < 10) {
        $error = "Minimum deposit amount is 10 BDT!";
    } elseif (empty($trxInput)) {
        $error = "Please provide a valid Transaction ID or Last 4 Digits!";
    } else {
        $payment = null;
        // ইউজার টাকা পাঠায় অ্যাডমিনের নাম্বারে → তাই অ্যাডমিনের অ্যাপে আসা SMS থেকে খুঁজতে হবে।
        // আগে নিজের (user_id = নিজে) পেমেন্ট খুঁজত → নিজের কাস্টমারের টাকা বা Custom SMS দিয়ে ফ্রি ব্যালেন্স নেওয়া যেত।
        $fundOwnerId = MASTER_USER_ID;
        $cleanTrx = preg_replace('/[^A-Z0-9]/', '', $trxInput);

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

        if (!$payment) {
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
                $mark = $pdo->prepare("UPDATE payments SET status = 'used', used_by = ?, used_at = NOW(), claimed_via = 'Web Add Fund' WHERE id = ? AND status <> 'used'");
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
                }
            }
        }
    }
}

// =========================================================================
// 3. Buy Plan & Auto-Expiry Lifecycle
// =========================================================================
if (isset($_POST['action_buy_plan'])) {
    $activeTabTarget = "view-plans";
    $planType = $_POST['plan_type'];
    $price = 0; $hours = 0; $planName = "";

    if ($planType === 'free_trial') {
        // Atomic: ডাবল ক্লিক করলেও একবারই ট্রায়াল
        $trialUpd = $pdo->prepare("UPDATE users SET has_used_trial = 1 WHERE id = ? AND (has_used_trial = 0 OR has_used_trial IS NULL)");
        $trialUpd->execute([$userId]);
        if ($trialUpd->rowCount() === 0) {
            $modalData = [
                "title" => "Free Trial Already Used",
                "message" => "You have already enjoyed your free trial access. Please select a standard or 30-day plan to continue using SNZPay.",
                "type" => "warning",
                "btn_text" => "Choose Plan",
                "btn_action" => "switchView('view-plans')"
            ];
        } else {
            $hours = 30;
            $planName = "Free Trial (30 Hours)";
            $user['has_used_trial'] = 1;
            
            $licenseKey = generateLicenseKey();
            $expiresAt = date("Y-m-d H:i:s", strtotime("+$hours hours"));
            $pdo->prepare("INSERT INTO user_keys (user_id, license_key, plan_name, expires_at, status) VALUES (?, ?, ?, ?, 'active')")
                ->execute([$userId, $licenseKey, $planName, $expiresAt]);
            $msg = "Free Trial activated: $licenseKey";
            $activeTabTarget = "view-my-plan";
        }
    } elseif ($planType === 'plan_30') {
        $price = 30; $hours = 24 * 7; $planName = "Standard Plan (30 BDT)";
    } elseif ($planType === 'plan_50') {
        $price = 50; $hours = 24 * 30; $planName = "Premium Plan (50 BDT)";
    }

    if ($price > 0) {
        // Atomic: ব্যালেন্স থাকলেই কাটবে (ডাবল ক্লিকে নেগেটিভ ব্যালেন্স হবে না)
        $deduct = $pdo->prepare("UPDATE users SET balance = balance - ? WHERE id = ? AND balance >= ?");
        $deduct->execute([$price, $userId, $price]);
        if ($deduct->rowCount() === 0) {
            $modalData = [
                "title" => "Insufficient Balance",
                "message" => "Your wallet balance is ৳ " . number_format(floatval($user['balance']), 2) . ". You need ৳ " . number_format($price, 2) . " to activate this plan.",
                "type" => "balance",
                "btn_text" => "+ Add Funds to Wallet",
                "btn_action" => "switchView('view-add-fund')"
            ];
        } else {
            $user['balance'] = floatval($user['balance']) - $price;

            $licenseKey = generateLicenseKey();
            $expiresAt = date("Y-m-d H:i:s", strtotime("+$hours hours"));
            $pdo->prepare("INSERT INTO user_keys (user_id, license_key, plan_name, expires_at, status) VALUES (?, ?, ?, ?, 'active')")
                ->execute([$userId, $licenseKey, $planName, $expiresAt]);

            $msg = "Plan activated: $licenseKey ($planName)";
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

        $trxId = strtoupper($trxId);
        $allowedProviders = ['BKASH', 'NAGAD', 'ROCKET', 'UPAY'];
        $selectedProvider = in_array(strtoupper($selectedProvider), $allowedProviders, true) ? strtoupper($selectedProvider) : 'BKASH';

        if (empty($trxId) || $amount <= 0) {
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
$bkStmt = $pdo->prepare("SELECT * FROM brand_keys WHERE user_id = ? AND status != 'revoked' ORDER BY id DESC LIMIT 1");
$bkStmt->execute([$userId]);
$userBrandKey = $bkStmt->fetch();

if (isset($_POST['action_generate_brand_key'])) {
    $activeTabTarget = "view-brands";
    $planCheck = $pdo->prepare("SELECT COUNT(*) FROM user_keys WHERE user_id = ? AND status = 'active' AND expires_at > NOW()");
    $planCheck->execute([$userId]);
    $activePlanCount = intval($planCheck->fetchColumn());

    if ($activePlanCount <= 0) {
        $modalData = [
            "title" => "No Active Plan Found",
            "message" => "You must purchase an active subscription plan first before creating a Bot Key.",
            "type" => "warning",
            "btn_text" => "View Plans",
            "btn_action" => "switchView('view-plans')"
        ];
    } elseif ($userBrandKey) {
        $error = "You already have an active Bot Connection Key!";
    } else {
        $brandName = mb_substr(trim($_POST['brand_name'] ?? '') ?: 'Store Bot', 0, 100);
        $newBotKey = "SNZ-BOT-" . strtoupper(bin2hex(random_bytes(6)));

        $pdo->prepare("INSERT INTO brand_keys (user_id, brand_name, api_key, plan_duration_hours, status) VALUES (?, ?, ?, 720, 'unlinked')")
            ->execute([$userId, $brandName, $newBotKey]);

        $msg = "Bot Key generated! Expiry countdown begins upon first verification.";
    }
}

if (isset($_POST['action_revoke_brand_key'])) {
    $activeTabTarget = "view-brands";
    $rKeyId = intval($_POST['revoke_key_id']);
    $pdo->prepare("UPDATE brand_keys SET status = 'revoked' WHERE id = ? AND user_id = ?")->execute([$rKeyId, $userId]);
    $msg = "Bot Key revoked successfully.";
}

// অ্যাকশনের পর নতুন অবস্থা দেখানো (আগে Refresh:0 দিয়ে পুরো পেজ দুইবার লোড হত)
if (isset($_POST['action_generate_brand_key']) || isset($_POST['action_revoke_brand_key'])) {
    $bkStmt->execute([$userId]);
    $userBrandKey = $bkStmt->fetch();
}

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

    if ($provider === 'Binance' && (empty($apiKey) || empty($secretKey))) {
        $error = "Please provide both Binance API Key and Secret Key!";
    } elseif ($category === 'qr' && empty($qrImagePath) && empty($accNumber)) {
        $error = "Please upload a QR Code image!";
    } elseif ($category !== 'qr' && $provider !== 'Binance' && empty($accNumber) && empty($accName)) {
        $error = "Please provide an Account Number!";
    } else {
        try {
            if ($provider === 'Binance') {
                $pdo->prepare("DELETE FROM user_wallets WHERE user_id = ? AND provider = 'Binance'")->execute([$userId]);
                $accNumber = 'Binance Pay Active';
            }

            $pdo->prepare("INSERT INTO user_wallets (user_id, category, provider, account_type, account_number, account_name, api_key, secret_key, qr_image, dollar_rate, status) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)")
                ->execute([$userId, $category, $provider, $accType, $accNumber, $accName, $apiKey, $secretKey, $qrImagePath, $dollarRate, $status]);
            
            $msg = ($provider === 'Binance') ? "Binance Connected! (Rate: 1 USDT = ৳{$dollarRate} BDT)" : "{$provider} saved successfully!";
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

$allUserKeys = [];
$activeKeys = [];
$deactivatedKeys = [];
try {
    $kStmt = $pdo->prepare("SELECT * FROM user_keys WHERE user_id = ? ORDER BY id DESC");
    $kStmt->execute([$userId]);
    $allUserKeys = $kStmt->fetchAll();

    foreach ($allUserKeys as $k) {
        if (strtotime($k['expires_at']) >= time()) {
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
    <link rel="stylesheet" href="assets/snz.css?v=2">
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
    </style>
</head>
<body class="text-[#F5F8FC] font-sans selection:bg-[#00D9FF] selection:text-[#020817]">

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
                <button onclick="<?= $modalData['btn_action'] ?>; document.getElementById('sleekActionModal').remove();" class="w-full snz-gradient text-[#020817] font-black py-3 rounded-2xl text-xs transition">
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
        <div>
            <div class="p-5 border-b border-[#0e275c] flex justify-between items-center">
                <div class="flex items-center gap-2.5">
                    <img src="<?= htmlspecialchars($SITE_LOGO) ?>" alt="Logo" class="w-9 h-9 object-contain rounded-xl shadow-lg shadow-cyan-500/20">
                    <div>
                        <h2 class="text-lg font-black tracking-wider text-white">SNZ<span class="text-[#00D9FF]">Pay</span></h2>
                        <p class="text-[9px] text-[#00D89A] font-bold tracking-widest uppercase"><?= htmlspecialchars($SITE_TAGLINE) ?></p>
                    </div>
                </div>
                <button onclick="toggleDrawer()" class="md:hidden text-slate-400 hover:text-white text-lg">
                    <i class="fa-solid fa-xmark"></i>
                </button>
            </div>

            <nav class="p-3 space-y-1.5 text-sm font-medium">
                <button onclick="switchView('view-dashboard')" id="nav-dashboard" class="nav-item w-full flex items-center gap-3.5 px-4 py-2.5 rounded-2xl hover:bg-[#092257] text-slate-300 active-tab transition">
                    <i class="fa-solid fa-chart-pie w-5 text-center text-base"></i> Dashboard
                </button>

                <button onclick="switchView('view-history')" id="nav-history" class="nav-item w-full flex items-center justify-between px-4 py-2.5 rounded-2xl hover:bg-[#092257] text-slate-300 transition">
                    <span class="flex items-center gap-3.5">
                        <i class="fa-solid fa-clock-rotate-left w-5 text-center text-base text-[#00D9FF]"></i> History
                    </span>
                    <span class="text-[10px] font-bold px-2 py-0.5 rounded-full bg-[#00D9FF]/20 text-[#00D9FF] border border-[#00D9FF]/30"><?= count($allTransactionsHistory) ?></span>
                </button>

                <button onclick="switchView('view-wallet-setup')" id="nav-wallet-setup" class="nav-item w-full flex items-center justify-between px-4 py-2.5 rounded-2xl hover:bg-[#092257] text-slate-300 transition">
                    <span class="flex items-center gap-3.5">
                        <i class="fa-solid fa-sliders w-5 text-center text-base text-[#00D9FF]"></i> Wallet Setup
                    </span>
                    <span class="text-[9px] font-black px-2 py-0.5 rounded-full bg-[#00D9FF]/20 text-[#00D9FF] border border-[#00D9FF]/50 uppercase tracking-widest animate-pulse">NEW</span>
                </button>

                <button onclick="switchView('view-custom-sms')" id="nav-custom-sms" class="nav-item w-full flex items-center gap-3.5 px-4 py-2.5 rounded-2xl hover:bg-[#092257] text-slate-300 transition">
                    <i class="fa-solid fa-envelope-open-text w-5 text-center text-base text-[#38BDF8]"></i> Custom SMS
                </button>

                <button onclick="switchView('view-brands')" id="nav-brands" class="nav-item w-full flex items-center justify-between px-4 py-2.5 rounded-2xl hover:bg-[#092257] text-slate-300 transition">
                    <span class="flex items-center gap-3.5">
                        <i class="fa-solid fa-robot w-5 text-center text-base text-[#FBBF24]"></i> Brands (Bot Key)
                    </span>
                    <span class="text-[9px] font-bold px-2 py-0.5 rounded-full <?= $userBrandKey ? 'bg-[#00D89A]/20 text-[#00D89A] border border-[#00D89A]/40' : 'bg-slate-800 text-slate-400' ?>"><?= $userBrandKey ? '1 KEY' : 'NEW' ?></span>
                </button>

                <button onclick="switchView('view-add-fund')" id="nav-add-fund" class="nav-item w-full flex items-center gap-3.5 px-4 py-2.5 rounded-2xl hover:bg-[#092257] text-slate-300 transition">
                    <i class="fa-solid fa-wallet w-5 text-center text-base text-[#00D89A]"></i> Add Funds
                </button>

                <button onclick="switchView('view-my-plan')" id="nav-my-plan" class="nav-item w-full flex items-center justify-between px-4 py-2.5 rounded-2xl hover:bg-[#092257] text-slate-300 transition">
                    <span class="flex items-center gap-3.5">
                        <i class="fa-solid fa-key w-5 text-center text-base text-[#00D9FF]"></i> My Plans
                    </span>
                    <span class="text-[10px] font-bold px-2 py-0.5 rounded-full bg-[#00D89A]/20 text-[#00D89A] border border-[#00D89A]/30"><?= count($activeKeys) ?></span>
                </button>

                <button onclick="switchView('view-plans')" id="nav-plans" class="nav-item w-full flex items-center gap-3.5 px-4 py-2.5 rounded-2xl hover:bg-[#092257] text-slate-300 transition">
                    <i class="fa-solid fa-layer-group w-5 text-center text-base text-[#007BFF]"></i> Pricing Plans
                </button>

                <button onclick="switchView('view-developer')" id="nav-developer" class="nav-item w-full flex items-center justify-between px-4 py-2.5 rounded-2xl hover:bg-[#092257] text-slate-300 transition">
                    <span class="flex items-center gap-3.5">
                        <i class="fa-solid fa-code w-5 text-center text-base text-[#00D9FF]"></i> Developer API
                    </span>
                    <span class="text-[9px] font-black px-2 py-0.5 rounded-full bg-[#00D9FF]/20 text-[#00D9FF] border border-[#00D9FF]/40 uppercase tracking-wider">PRO</span>
                </button>

                <button onclick="switchView('view-app')" id="nav-app" class="nav-item w-full flex items-center gap-3.5 px-4 py-2.5 rounded-2xl hover:bg-[#092257] text-slate-300 transition">
                    <i class="fa-brands fa-android w-5 text-center text-base text-[#35F58A]"></i> Download App
                </button>

                <button onclick="switchView('view-support')" id="nav-support" class="nav-item w-full flex items-center gap-3.5 px-4 py-2.5 rounded-2xl hover:bg-[#092257] text-slate-300 transition">
                    <i class="fa-solid fa-headset w-5 text-center text-base text-[#00D9FF]"></i> Support
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
    <div class="md:ml-72 min-h-screen flex flex-col">
        <header class="bg-[#020817]/80 backdrop-blur-md px-4 md:px-8 py-3.5 flex justify-between items-center sticky top-0 z-30">
            <div class="flex items-center gap-3">
                <button onclick="toggleDrawer()" class="header-menu-btn w-11 h-11 flex items-center justify-center text-[#00D9FF] text-xl transition active:scale-95">
                    <i class="fa-solid fa-bars"></i>
                </button>
                <div class="flex items-center gap-2.5">
                    <img src="<?= htmlspecialchars($SITE_LOGO) ?>" alt="SNZPay Logo" class="w-9 h-9 object-contain rounded-xl">
                    <div>
                        <h1 class="text-base md:text-lg font-black tracking-wide text-white">SNZ<span class="text-[#00D9FF]">Pay</span></h1>
                        <p class="text-[9px] text-[#00D89A] font-bold tracking-widest uppercase"><?= htmlspecialchars($SITE_TAGLINE) ?></p>
                    </div>
                </div>
            </div>

            <div class="header-balance-pill flex items-center gap-2.5 px-4 py-2 shadow-lg shadow-cyan-500/10">
                <i class="fa-solid fa-coins text-[#00D9FF] text-sm"></i>
                <span class="text-xs text-slate-300 font-medium">Balance:</span>
                <span class="text-xs md:text-sm font-black text-[#00D89A]">৳ <?= number_format(floatval($user['balance']), 2) ?></span>
            </div>
        </header>

        <main class="p-4 md:p-8 flex-1 max-w-4xl w-full mx-auto space-y-6">

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
                                        <div class="flex items-center gap-3">
                                            <span class="text-[10px] font-black px-2.5 py-1 rounded-lg text-white" style="background-color: <?= $badgeColor ?>;">
                                                <?= htmlspecialchars($method) ?>
                                            </span>
                                            <div>
                                                <div class="flex items-center gap-2">
                                                    <span class="font-mono text-sm font-bold text-white tracking-wider select-all"><?= htmlspecialchars($sms['trx_id']) ?></span>
                                                    <button onclick="copyText(<?= htmlspecialchars(json_encode($sms['trx_id']), ENT_QUOTES) ?>)" class="text-slate-400 hover:text-[#00D9FF] text-xs">
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
                                            <span class="text-sm font-mono font-black text-[#00D89A] tracking-wider select-all"><?= htmlspecialchars($k['license_key']) ?></span>
                                            <button onclick="copyText(<?= htmlspecialchars(json_encode($k['license_key']), ENT_QUOTES) ?>)" class="text-xs bg-[#092257] hover:bg-[#007BFF] text-white px-3 py-1.5 rounded-xl border border-[#174396] transition">Copy</button>
                                        </div>
                                    </div>

                                    <div class="text-[11px] text-slate-300 border-t border-[#0e275c] pt-2 flex justify-between">
                                        <span>Expires On:</span>
                                        <b class="text-white"><?= date("d M Y, h:i A", strtotime($k['expires_at'])) ?></b>
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
                                    <div class="flex items-center gap-3">
                                        <span class="text-[10px] font-black px-2.5 py-1 rounded-lg text-white shrink-0" style="background-color: <?= $mColor ?>;">
                                            <?= htmlspecialchars($th['method']) ?>
                                        </span>
                                        <div>
                                            <div class="flex items-center gap-2">
                                                <span class="font-mono text-sm font-bold text-white tracking-wider select-all"><?= htmlspecialchars($th['trx_id']) ?></span>
                                                <button onclick="copyText(<?= htmlspecialchars(json_encode($th['trx_id']), ENT_QUOTES) ?>)" class="text-slate-400 hover:text-[#00D9FF] text-xs">
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
                                <span class="text-xs font-bold text-white group-hover:text-[#00D9FF] truncate"><?= $b['name'] ?></span>
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
                                <span class="text-xs font-bold text-white group-hover:text-[#00D9FF] truncate"><?= $bnk ?></span>
                            </button>
                        <?php endforeach; ?>
                    </div>

                    <div id="grid-qr" class="wallet-grid hidden grid grid-cols-2 sm:grid-cols-4 gap-3">
                        <button type="button" onclick="openWalletModal('qr', 'Bangla QR')" class="bg-[#020817] border border-[#0e275c] hover:border-[#00D9FF] p-3.5 rounded-2xl flex items-center gap-2.5 text-left transition group">
                            <div class="w-8 h-8 rounded-xl bg-[#00D89A]/20 text-[#00D89A] flex items-center justify-center text-xs shrink-0">
                                <i class="fa-solid fa-qrcode"></i>
                            </div>
                            <span class="text-xs font-bold text-white group-hover:text-[#00D89A] truncate">Bangla QR</span>
                        </button>
                    </div>

                    <div id="grid-international" class="wallet-grid hidden grid grid-cols-2 sm:grid-cols-4 gap-3">
                        <button type="button" onclick="openWalletModal('international', 'Binance')" class="bg-[#020817] border border-[#0e275c] hover:border-amber-400 p-3.5 rounded-2xl flex items-center gap-2.5 text-left transition group">
                            <div class="w-8 h-8 rounded-xl bg-amber-500/20 text-amber-400 flex items-center justify-center text-xs shrink-0">
                                <i class="fa-brands fa-bitcoin"></i>
                            </div>
                            <span class="text-xs font-bold text-white group-hover:text-amber-400 truncate">Binance</span>
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
                                    <input type="text" name="account_name" placeholder="Optional details" class="w-full bg-[#04122d] border border-[#0e275c] rounded-2xl px-4 py-3 text-white text-sm focus:outline-none focus:border-[#00D9FF]">
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
                                    <input type="text" name="api_key" placeholder="Enter Binance API key" class="w-full bg-[#04122d] border border-[#0e275c] rounded-2xl px-4 py-3 text-white font-mono text-xs focus:outline-none focus:border-amber-400">
                                </div>
                                <div>
                                    <label class="text-xs font-semibold text-slate-200 block mb-1.5">Binance Secret Key:</label>
                                    <input type="password" name="secret_key" placeholder="Enter Binance Secret key" class="w-full bg-[#04122d] border border-[#0e275c] rounded-2xl px-4 py-3 text-white font-mono text-xs focus:outline-none focus:border-amber-400">
                                </div>
                                <div>
                                    <label class="text-xs font-semibold text-slate-200 block mb-1.5">Dollar Exchange Rate (1 USDT = ? BDT):</label>
                                    <input type="number" step="any" name="dollar_rate" value="125.00" placeholder="125.00" class="w-full bg-[#04122d] border border-[#0e275c] rounded-2xl px-4 py-3 text-white font-mono text-xs focus:outline-none focus:border-amber-400">
                                    <span class="text-[10px] text-slate-400 mt-1 block">Customer's USDT deposit will be multiplied by this rate to add BDT balance.</span>
                                </div>
                            </div>

                            <div class="flex items-center justify-between pt-2">
                                <span class="text-xs font-semibold text-white">Enable Gateway (Status):</span>
                                <label class="relative inline-flex items-center cursor-pointer">
                                    <input type="checkbox" name="wallet_status" checked class="sr-only peer">
                                    <div class="w-11 h-6 bg-[#0a2356] peer-focus:outline-none rounded-full peer peer-checked:after:translate-x-full peer-checked:after:border-white after:content-[''] after:absolute after:top-[2px] after:left-[2px] after:bg-white after:border-gray-300 after:border after:rounded-full after:h-5 after:w-5 after:transition-all peer-checked:bg-[#00D89A]"></div>
                                </label>
                            </div>

                            <button type="submit" class="w-full snz-gradient text-[#061535] font-black py-3 rounded-2xl text-xs transition flex items-center justify-center gap-2">
                                <i class="fa-solid fa-circle-check"></i> Save Gateway
                            </button>
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
                    <form method="POST" class="space-y-4">
                                <input type="hidden" name="csrf" value="<?= $CSRF ?>">
                        <input type="hidden" name="action_add_custom_sms" value="1">
                        <div>
                            <label class="text-xs font-semibold text-slate-200 block mb-1.5">Select Provider:</label>
                            <select name="custom_provider" class="w-full bg-[#020817] border border-[#0e275c] rounded-2xl px-4 py-3 text-white text-sm focus:outline-none focus:border-[#00D9FF]">
                                <option value="BKASH">bKash</option>
                                <option value="NAGAD">Nagad</option>
                                <option value="ROCKET">Rocket</option>
                                <option value="UPAY">Upay</option>
                            </select>
                        </div>
                        <div>
                            <label class="text-xs font-semibold text-slate-200 block mb-1.5">Paste Raw Received SMS:</label>
                            <textarea name="custom_sms_text" rows="4" required placeholder="Paste original SMS here..." class="w-full bg-[#020817] border border-[#0e275c] rounded-2xl p-4 text-white font-mono text-xs focus:outline-none focus:border-[#00D9FF]"></textarea>
                        </div>
                        <button type="submit" class="w-full snz-gradient text-[#061535] font-black py-3.5 rounded-2xl text-xs transition">Add to Verification Queue</button>
                    </form>
                </div>
            </section>

            <!-- ========================================================================= -->
            <!-- 5. BRANDS (BOT KEY) VIEW -->
            <!-- ========================================================================= -->
            <section id="view-brands" class="content-view hidden space-y-6">
                <div class="bg-[#04122d] border border-[#00D9FF]/40 rounded-3xl p-6 md:p-8 shadow-2xl space-y-6">
                    <div class="pb-3 border-b border-[#0e275c] flex justify-between items-center">
                        <h2 class="text-base md:text-lg font-bold text-white flex items-center gap-2">
                            <i class="fa-solid fa-robot text-[#FBBF24]"></i> Brands &amp; Bot Key
                        </h2>
                        <span class="text-xs bg-[#03132e] text-[#00D9FF] px-3 py-1 rounded-full border border-[#174396]">Limit: 1 Key / Plan</span>
                    </div>

                    <?php if ($userBrandKey): ?>
                        <div class="bg-[#020817] border border-[#00D9FF]/30 p-5 rounded-3xl space-y-4">
                            <div class="flex justify-between items-start">
                                <div>
                                    <span class="text-[10px] uppercase font-bold text-[#FBBF24] bg-amber-500/10 border border-amber-500/30 px-2.5 py-0.5 rounded-full">
                                        <?= htmlspecialchars($userBrandKey['brand_name'] ?? 'Store Bot') ?>
                                    </span>
                                    <h4 class="text-xs font-semibold text-slate-300 mt-2">Dedicated Bot Connection Key:</h4>
                                </div>
                                <span class="text-[10px] font-bold px-2.5 py-1 rounded-full <?= $userBrandKey['is_connected'] == 1 ? 'bg-[#00D89A]/15 text-[#00D89A] border border-[#00D89A]/30' : 'bg-amber-500/15 text-amber-400 border border-amber-500/30' ?>">
                                    <?= $userBrandKey['is_connected'] == 1 ? 'CONNECTED & ACTIVE' : 'WAITING FOR FIRST CONNECT' ?>
                                </span>
                            </div>

                            <div class="bg-[#04122d] border border-[#0e275c] p-3 rounded-2xl flex items-center justify-between gap-2">
                                <span class="text-sm font-mono font-black text-[#00D89A] tracking-wider select-all"><?= htmlspecialchars($userBrandKey['api_key']) ?></span>
                                <button onclick="copyText(<?= htmlspecialchars(json_encode($userBrandKey['api_key']), ENT_QUOTES) ?>)" class="text-xs bg-[#092257] hover:bg-[#007BFF] text-white px-3 py-1.5 rounded-xl border border-[#174396] transition flex items-center gap-1.5 shrink-0">
                                    <i class="fa-regular fa-copy"></i> Copy
                                </button>
                            </div>

                            <div class="text-[11px] text-slate-300 flex justify-between items-center border-t border-[#0e275c] pt-3">
                                <span>Lifecycle Status:</span>
                                <?php if ($userBrandKey['is_connected'] == 1): ?>
                                    <b class="text-[#00D89A]">Expires: <?= !empty($userBrandKey['expires_at']) ? date("d M Y, h:i A", strtotime($userBrandKey['expires_at'])) : 'N/A' ?></b>
                                <?php else: ?>
                                    <b class="text-amber-400">Countdown begins when first connected</b>
                                <?php endif; ?>
                            </div>

                            <form method="POST" onsubmit="return confirm('Revoke this key?');" class="pt-2 text-right">
                                <input type="hidden" name="csrf" value="<?= $CSRF ?>">
                                <input type="hidden" name="action_revoke_brand_key" value="1">
                                <input type="hidden" name="revoke_key_id" value="<?= $userBrandKey['id'] ?>">
                                <button type="submit" class="text-xs text-rose-400 hover:text-rose-300 font-bold transition">
                                    <i class="fa-solid fa-ban"></i> Revoke Key
                                </button>
                            </form>
                        </div>
                    <?php else: ?>
                        <form method="POST" class="space-y-4">
                                <input type="hidden" name="csrf" value="<?= $CSRF ?>">
                            <input type="hidden" name="action_generate_brand_key" value="1">
                            <div>
                                <label class="text-xs font-semibold text-slate-200 block mb-1.5">Brand / Bot Store Name:</label>
                                <input type="text" name="brand_name" required placeholder="e.g., My Store Bot" class="w-full bg-[#020817] border border-[#0e275c] rounded-2xl px-4 py-3 text-white text-sm focus:outline-none focus:border-[#00D9FF]">
                            </div>
                            <button type="submit" class="w-full snz-gradient text-[#061535] font-black py-3.5 rounded-2xl text-xs transition flex items-center justify-center gap-2">
                                <i class="fa-solid fa-key"></i> Generate Bot Connection Key
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

                    <!-- Payment Provider Switcher -->
                    <div class="space-y-2">
                        <label class="text-xs font-semibold text-slate-200 block">Select Payment Gateway:</label>
                        <div class="grid grid-cols-2 sm:grid-cols-5 gap-3">
                            <label class="bg-[#020817] p-3 rounded-2xl border border-[#0e275c] hover:border-[#E2136E] cursor-pointer flex flex-col items-center justify-center text-center transition">
                                <input type="radio" name="fund_provider_radio" value="bkash" checked class="hidden" onchange="selectFundGateway('bkash')">
                                <span class="text-xs font-black text-[#E2136E]">bKash</span>
                            </label>
                            <label class="bg-[#020817] p-3 rounded-2xl border border-[#0e275c] hover:border-[#EA580C] cursor-pointer flex flex-col items-center justify-center text-center transition">
                                <input type="radio" name="fund_provider_radio" value="nagad" class="hidden" onchange="selectFundGateway('nagad')">
                                <span class="text-xs font-black text-[#EA580C]">Nagad</span>
                            </label>
                            <label class="bg-[#020817] p-3 rounded-2xl border border-[#0e275c] hover:border-[#7C3AED] cursor-pointer flex flex-col items-center justify-center text-center transition">
                                <input type="radio" name="fund_provider_radio" value="rocket" class="hidden" onchange="selectFundGateway('rocket')">
                                <span class="text-xs font-black text-[#7C3AED]">Rocket</span>
                            </label>
                            <label class="bg-[#020817] p-3 rounded-2xl border border-[#0e275c] hover:border-[#0284C7] cursor-pointer flex flex-col items-center justify-center text-center transition">
                                <input type="radio" name="fund_provider_radio" value="bangla_qr" class="hidden" onchange="selectFundGateway('bangla_qr')">
                                <span class="text-xs font-black text-[#0284C7]">Bangla QR</span>
                            </label>
                            <label class="bg-[#020817] p-3 rounded-2xl border border-[#0e275c] hover:border-amber-400 cursor-pointer flex flex-col items-center justify-center text-center transition">
                                <input type="radio" name="fund_provider_radio" value="binance" class="hidden" onchange="selectFundGateway('binance')">
                                <span class="text-xs font-black text-amber-400">Binance Pay</span>
                            </label>
                        </div>
                    </div>

                    <!-- Dynamic Gateway Display Box -->
                    <div id="fundGatewayInfo" class="bg-[#020817] border border-[#0e275c] p-4 rounded-2xl flex flex-col sm:flex-row justify-between items-center gap-3">
                        <div>
                            <span id="fundInfoTitle" class="text-xs text-slate-400 block">Send Payment To (bKash Personal):</span>
                            <span id="fundInfoValue" class="text-sm md:text-base font-mono font-black text-[#00D89A]"><?= htmlspecialchars($bkashNumber) ?></span>
                        </div>
                        <button type="button" id="btnCopyFundTarget" onclick="copyText(document.getElementById('fundInfoValue').innerText)" class="text-xs bg-[#092257] hover:bg-[#007BFF] text-white px-4 py-2 rounded-xl border border-[#174396] transition flex items-center gap-1.5 shrink-0 font-bold">
                            <i class="fa-regular fa-copy"></i> Copy
                        </button>
                    </div>

                    <!-- Bangla QR Image Display (Initially Hidden) -->
                    <div id="fundBanglaQrBox" class="hidden bg-[#020817] border border-[#00D9FF]/40 p-4 rounded-2xl text-center space-y-3">
                        <span class="text-xs font-bold text-white block">Scan Bangla QR with any Bank or MFS app:</span>
                        <img id="fundQrImg" src="<?= htmlspecialchars($banglaQrImage) ?>" alt="Bangla QR" class="w-48 h-48 object-contain mx-auto rounded-xl bg-white p-2 border border-[#10326f]">
                        <p class="text-[11px] text-slate-400">Scan and send money. Enter your TrxID or the last 4 digits of your phone number below.</p>
                    </div>

                    <form method="POST" class="space-y-4">
                                <input type="hidden" name="csrf" value="<?= $CSRF ?>">
                        <input type="hidden" name="action_add_fund" value="1">
                        <input type="hidden" id="fund_hidden_provider" name="provider" value="bkash">

                        <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                            <div>
                                <label id="fundAmountLabel" class="text-xs font-semibold text-slate-200 block mb-1.5">Amount (BDT):</label>
                                <input type="number" step="any" name="deposit_amount" required placeholder="Min 10 BDT" class="w-full bg-[#020817] border border-[#0e275c] rounded-2xl px-4 py-3 text-white text-sm focus:outline-none focus:border-[#00D9FF]">
                            </div>
                            <div>
                                <label id="fundTrxLabel" class="text-xs font-semibold text-slate-200 block mb-1.5">Transaction ID (TrxID):</label>
                                <input type="text" name="trx_id" required placeholder="Enter TrxID or Order ID" class="w-full bg-[#020817] border border-[#0e275c] rounded-2xl px-4 py-3 text-white font-mono text-sm focus:outline-none focus:border-[#00D9FF] uppercase">
                            </div>
                        </div>

                        <button type="submit" class="w-full snz-gradient text-[#061535] font-black py-3.5 rounded-2xl text-xs transition flex items-center justify-center gap-2 shadow-lg shadow-cyan-500/20">
                            <i class="fa-solid fa-circle-check"></i> Verify &amp; Credit Balance
                        </button>
                    </form>
                </div>
            </section>

            <!-- ========================================================================= -->
            <!-- 7. MY PLANS VIEW -->
            <!-- ========================================================================= -->
            <section id="view-my-plan" class="content-view hidden space-y-6">
                <div class="bg-[#04122d] border border-[#00D9FF]/40 rounded-3xl p-6 md:p-8">
                    <div class="flex justify-between items-center mb-6 pb-4 border-b border-[#0e275c]">
                        <div>
                            <h2 class="text-base md:text-lg font-bold text-white flex items-center gap-2">
                                <i class="fa-solid fa-key text-[#00D9FF]"></i> My Active &amp; Past Licenses
                            </h2>
                            <p class="text-xs text-slate-300 mt-0.5">Manage your active plans, license keys, and renewal dates.</p>
                        </div>
                        <span class="text-xs bg-[#03132e] text-[#00D9FF] px-3 py-1 rounded-full border border-[#174396]">Total: <?= count($allUserKeys) ?></span>
                    </div>

                    <?php if (count($allUserKeys) > 0): ?>
                        <div class="space-y-4">
                            <?php foreach ($allUserKeys as $k): 
                                $isExp = strtotime($k['expires_at']) < time();
                                $pDetails = getPlanDetails($k['plan_name']);
                            ?>
                                <div class="bg-[#020817] border <?= $isExp ? 'border-[#00D9FF]/30' : 'border-[#00D89A]/30' ?> p-4 rounded-2xl flex flex-col md:flex-row justify-between items-start md:items-center gap-3">
                                    <div class="space-y-1">
                                        <div class="flex items-center gap-2">
                                            <span class="text-xs font-bold text-white"><?= htmlspecialchars($k['plan_name']) ?></span>
                                            <?php if ($isExp): ?>
                                                <span class="text-[10px] font-bold px-2 py-0.5 rounded-full bg-[#0c2454] text-[#00D9FF] border border-[#16418f]">EXPIRED</span>
                                            <?php else: ?>
                                                <span class="text-[10px] font-bold px-2 py-0.5 rounded-full bg-[#00D89A]/15 text-[#00D89A] border border-[#00D89A]/30">ACTIVE</span>
                                            <?php endif; ?>
                                        </div>
                                        <div class="font-mono text-sm md:text-base font-black <?= $isExp ? 'text-slate-300' : 'text-[#00D89A]' ?> select-all tracking-wider">
                                            <?= htmlspecialchars($k['license_key']) ?>
                                        </div>
                                        <div class="text-[11px] text-slate-400">
                                            Expires: <b class="text-slate-200"><?= date("d M Y, h:i A", strtotime($k['expires_at'])) ?></b>
                                        </div>
                                    </div>

                                    <div class="flex items-center gap-2 w-full md:w-auto">
                                        <?php if ($isExp): ?>
                                            <form method="POST" class="w-full md:w-auto">
                                <input type="hidden" name="csrf" value="<?= $CSRF ?>">
                                                <input type="hidden" name="action_manual_renew" value="1">
                                                <input type="hidden" name="renew_key_id" value="<?= $k['id'] ?>">
                                                <button type="submit" class="w-full md:w-auto snz-gradient text-[#061535] text-xs font-black px-4 py-2.5 rounded-xl transition flex items-center justify-center gap-1.5 shadow-lg shadow-cyan-500/20">
                                                    <i class="fa-solid fa-arrows-rotate"></i> Renew (৳<?= $pDetails['price'] ?>)
                                                </button>
                                            </form>
                                        <?php endif; ?>
                                        <button onclick="copyText(<?= htmlspecialchars(json_encode($k['license_key']), ENT_QUOTES) ?>)" class="w-full md:w-auto bg-[#092257] hover:bg-[#007BFF] text-white text-xs font-bold px-4 py-2.5 rounded-xl border border-[#174396] transition flex items-center justify-center gap-1.5 shrink-0">
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
                    <p class="text-xs text-slate-300 mt-0.5">Wallet balance is required to activate paid plans.</p>
                </div>

                <div class="grid grid-cols-1 md:grid-cols-3 gap-5">
                    <!-- Free Trial Card -->
                    <div class="bg-[#04122d] border border-[#00D9FF]/40 rounded-3xl p-6 flex flex-col justify-between shadow-xl">
                        <div>
                            <span class="bg-[#00D89A]/15 text-[#00D89A] text-[11px] font-bold px-3 py-1 rounded-full border border-[#00D89A]/30">Free Access</span>
                            <h3 class="text-base font-bold text-white mt-3">30 Hours Trial</h3>
                            <p class="text-slate-300 text-xs mt-1">Experience full automation features for 30 hours completely free.</p>
                            <div class="text-3xl font-black text-white my-4">৳ 0</div>
                        </div>
                        <form method="POST">
                                <input type="hidden" name="csrf" value="<?= $CSRF ?>">
                            <input type="hidden" name="action_buy_plan" value="1">
                            <input type="hidden" name="plan_type" value="free_trial">
                            <button type="submit" class="w-full snz-gradient text-[#061535] font-black py-3 rounded-2xl text-xs transition">
                                <?= $user['has_used_trial'] == 1 ? 'Claimed (1-Time Only)' : 'Claim Free Trial' ?>
                            </button>
                        </form>
                    </div>

                    <!-- 30 TK Plan Card -->
                    <div class="bg-[#04122d] border border-[#007BFF]/50 rounded-3xl p-6 flex flex-col justify-between shadow-xl">
                        <div>
                            <span class="bg-[#007BFF]/20 text-[#00D9FF] text-[11px] font-bold px-3 py-1 rounded-full border border-[#007BFF]/40">Standard</span>
                            <h3 class="text-base font-bold text-white mt-3">7-Day License Plan</h3>
                            <p class="text-slate-300 text-xs mt-1">7 days of unlimited SMS reader and 1 dedicated Bot Key.</p>
                            <div class="text-3xl font-black text-white my-4">৳ 30</div>
                        </div>
                        <form method="POST">
                                <input type="hidden" name="csrf" value="<?= $CSRF ?>">
                            <input type="hidden" name="action_buy_plan" value="1">
                            <input type="hidden" name="plan_type" value="plan_30">
                            <button type="submit" class="w-full bg-[#007BFF] hover:bg-[#0056b3] text-white font-bold py-3 rounded-2xl text-xs transition">Purchase with Wallet</button>
                        </form>
                    </div>

                    <!-- 50 TK Plan Card -->
                    <div class="bg-[#04122d] border border-[#00D89A]/50 rounded-3xl p-6 flex flex-col justify-between shadow-xl">
                        <div>
                            <span class="snz-gradient text-[#061535] text-[11px] font-black px-3 py-1 rounded-full">Popular Choice</span>
                            <h3 class="text-base font-bold text-white mt-3">30-Day License Plan</h3>
                            <p class="text-slate-300 text-xs mt-1">Full 1 month of uninterrupted service with 1 dedicated Bot Key.</p>
                            <div class="text-3xl font-black text-white my-4">৳ 50</div>
                        </div>
                        <form method="POST">
                                <input type="hidden" name="csrf" value="<?= $CSRF ?>">
                            <input type="hidden" name="action_buy_plan" value="1">
                            <input type="hidden" name="plan_type" value="plan_50">
                            <button type="submit" class="w-full snz-gradient text-[#061535] font-black py-3 rounded-2xl text-xs transition shadow-lg shadow-cyan-500/25">Purchase with Wallet</button>
                        </form>
                    </div>
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
LICENSE_KEY = "YOUR_ACTIVE_LICENSE_KEY"

def verify_payment(trx_id: str, method: str = "BKASH", amount: float = 0):
    payload = {
        "license_key": LICENSE_KEY,
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
            print("Verification Failed:", data.get("message"))
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
$licenseKey = "YOUR_ACTIVE_LICENSE_KEY";

$payload = [
    "license_key" => $licenseKey,
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
    echo "Verification Failed: " . ($result['message'] ?? 'Error');
}</pre>
                    </div>

                    <!-- Node.js / JavaScript -->
                    <div id="code-block-js" class="hidden relative bg-[#020817] border border-[#0e275c] rounded-2xl p-4 font-mono text-xs text-slate-300 overflow-x-auto">
                        <pre id="jsSnippet">const axios = require('axios');

async function verifyPayment(trxId, method = 'BKASH', amount = 0) {
    const url = '<?= htmlspecialchars($publicApiUrl) ?>';
    const payload = {
        license_key: 'YOUR_ACTIVE_LICENSE_KEY',
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
        String json = "{\"license_key\":\"YOUR_KEY\",\"trx_id\":\"BL7A90XYZ\",\"method\":\"BKASH\",\"amount\":500.0}";

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
                    <img src="<?= htmlspecialchars($SITE_LOGO) ?>" alt="App Logo" class="w-20 h-20 object-contain mx-auto mb-4">
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
                    <a href="https://t.me/YOUR_TELEGRAM_USERNAME" target="_blank" class="inline-flex items-center justify-center gap-2 w-full snz-gradient text-[#061535] font-black py-3.5 rounded-2xl shadow-lg shadow-cyan-500/20 transition text-xs">
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
                                <div class="flex items-center gap-3">
                                    <span class="text-[10px] font-black px-2.5 py-1 rounded-lg text-white" style="background-color: ${badgeColor};">
                                        ${esc(sms.method)}
                                    </span>
                                    <div>
                                        <div class="flex items-center gap-2">
                                            <span class="font-mono text-sm font-bold text-white tracking-wider select-all">${esc(sms.trx_id)}</span>
                                            <button onclick="copyText(this.dataset.v)" data-v="${esc(sms.trx_id)}" class="text-slate-400 hover:text-[#00D9FF] text-xs">
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
        const fundGateways = {
            "bkash": { title: "Send Payment To (bKash Personal):", val: <?= json_encode((string) $bkashNumber, JSON_HEX_TAG | JSON_UNESCAPED_UNICODE) ?>, isQr: false, trxLabel: "Transaction ID (TrxID):", amtLabel: "Amount (BDT):", min: "Min 10 BDT" },
            "nagad": { title: "Send Payment To (Nagad Personal):", val: <?= json_encode((string) $nagadNumber, JSON_HEX_TAG | JSON_UNESCAPED_UNICODE) ?>, isQr: false, trxLabel: "Transaction ID (TxnID):", amtLabel: "Amount (BDT):", min: "Min 10 BDT" },
            "rocket": { title: "Send Payment To (Rocket Account):", val: <?= json_encode((string) $rocketNumber, JSON_HEX_TAG | JSON_UNESCAPED_UNICODE) ?>, isQr: false, trxLabel: "Transaction ID (TxnId):", amtLabel: "Amount (BDT):", min: "Min 10 BDT" },
            "bangla_qr": { title: "Scan Bangla QR Code directly:", val: "Scan Image", isQr: true, trxLabel: "Transaction ID or Sender Phone Last 4 Digits:", amtLabel: "Amount (BDT):", min: "Min 10 BDT" },
            "binance": { title: "Send USDT To (Binance Pay ID):", val: <?= json_encode((string) $binancePayId, JSON_HEX_TAG | JSON_UNESCAPED_UNICODE) ?>, isQr: false, trxLabel: "Binance Pay Order ID / TxID:", amtLabel: "Amount (USDT):", min: "Min <?= $MIN_BINANCE_USDT ?> USDT" }
        };

        function selectFundGateway(provider) {
            document.getElementById('fund_hidden_provider').value = provider;
            const info = fundGateways[provider];
            if (!info) return;

            document.getElementById('fundInfoTitle').innerText = info.title;
            document.getElementById('fundInfoValue').innerText = info.val;
            document.getElementById('fundTrxLabel').innerText = info.trxLabel;
            document.getElementById('fundAmountLabel').innerText = info.amtLabel;
            const amtInput = document.querySelector('input[name="deposit_amount"]');
            if (amtInput) amtInput.placeholder = info.min;

            const qrBox = document.getElementById('fundBanglaQrBox');
            const infoBox = document.getElementById('fundGatewayInfo');
            if (info.isQr) {
                qrBox.classList.remove('hidden');
                infoBox.classList.add('hidden');
            } else {
                qrBox.classList.add('hidden');
                infoBox.classList.remove('hidden');
            }
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

        function openWalletModal(category, provider) {
            const formBox = document.getElementById('walletSetupForm');
            formBox.classList.remove('hidden');

            document.getElementById('input_category').value = category;
            document.getElementById('input_provider').value = provider;
            document.getElementById('formTitle').innerText = "Configure " + provider;

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