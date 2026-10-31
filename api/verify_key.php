<?php
// =========================================================================
// 🔑 App License Key Verify
// =========================================================================
require_once __DIR__ . '/../config.php';
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

// ইনডেক্স ব্যবহার করে দ্রুত সার্চ; একই key এর একাধিক row থাকলে সবচেয়ে বেশি মেয়াদিটা
$stmt = $pdo->prepare("SELECT plan_name, expires_at FROM user_keys WHERE license_key = ? AND status = 'active' ORDER BY expires_at DESC LIMIT 1");
$stmt->execute([$key]);
$keyRow = $stmt->fetch();

if (!$keyRow) {
    jsonResponse(403, ["status" => "error", "message" => "Invalid or inactive key."]);
}

$expireTime = strtotime($keyRow['expires_at']);
if ($expireTime === false || $expireTime < time()) {
    jsonResponse(403, ["status" => "error", "message" => "Key has expired."]);
}

jsonResponse(200, [
    "status" => "valid",
    "plan" => $keyRow['plan_name'],
    // আগে float (1.7E+12 / ".0") যেত → অ্যাপে Long parse সমস্যা; এখন integer
    "expires_timestamp" => $expireTime * 1000
]);
