<?php
// =========================================================================
// 📲 App → Server SMS Gateway
// অ্যাপ এখানে bKash / Nagad / Rocket SMS পাঠায়, সেটা payments টেবিলে সেভ হয়
// =========================================================================
require_once __DIR__ . '/../config.php';
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
if (!hash_equals(APP_SECRET_TOKEN, $secret)) {
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

    $stmt = $pdo->prepare("INSERT INTO payments (trx_id, amount, method, sender, raw_sms, origin, user_id, status, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, 'pending', NOW())");
    $stmt->execute([$trxId, $amount, $method, $sender, $rawMsg, $origin, $ownerUserId]);

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
