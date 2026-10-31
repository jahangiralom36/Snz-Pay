<?php
// Gzip compression for faster delivery
if (!ob_start("ob_gzhandler")) ob_start();

session_start();
header("X-Content-Type-Options: nosniff");

// Central Configuration File Connection
require_once __DIR__ . '/config.php';

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

// CSRF টোকেন (অন্য সাইট থেকে admin অ্যাকশন চালানো ঠেকাতে)
if (empty($_SESSION['csrf'])) {
    $_SESSION['csrf'] = bin2hex(random_bytes(16));
}
$CSRF = $_SESSION['csrf'];

// Admin Login Check
if (isset($_POST['admin_login'])) {
    if (hash_equals((string) $ADMIN_PASSWORD, (string) ($_POST['password'] ?? ''))) {
        session_regenerate_id(true);
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
    <link rel="stylesheet" href="assets/snz.css?v=2">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <style>
        body { touch-action: manipulation; }
        .snz-gradient { background: linear-gradient(135deg, #007BFF 0%, #00D9FF 50%, #00D89A 100%); }
    </style>
</head>
<body class="bg-[#061535] text-[#F5F8FC] min-h-screen flex items-center justify-center p-4 selection:bg-[#00D9FF] selection:text-[#061535] font-sans">
    <div class="bg-[#081e4b] border border-[#143985] p-8 rounded-3xl w-full max-w-md shadow-2xl relative">
        <div class="text-center mb-6">
            <img src="<?= htmlspecialchars($SITE_LOGO) ?>" alt="<?= htmlspecialchars($SITE_NAME) ?> Logo" decoding="async" class="w-14 h-14 object-contain mx-auto mb-3">
            <h1 class="text-xl font-black text-white tracking-wide">SNZ<span class="text-[#00D9FF]">Pay</span> ADMIN</h1>
            <p class="text-xs text-slate-300 mt-0.5">Enter master password to access console</p>
        </div>
        <?php if (!empty($login_error)): ?>
            <div class="bg-[#00D9FF]/10 border border-[#00D9FF]/30 text-[#00D9FF] p-3 rounded-2xl text-xs mb-4 text-center font-medium flex items-center justify-center gap-2">
                <i class="fa-solid fa-circle-exclamation"></i> <?= htmlspecialchars($login_error) ?>
            </div>
        <?php endif; ?>
        <form method="POST" class="space-y-4">
            <div>
                <label class="text-xs text-slate-200 font-semibold block mb-1.5">Master Password</label>
                <div class="relative">
                    <i class="fa-solid fa-lock absolute left-4 top-3.5 text-slate-400 text-sm"></i>
                    <input type="password" name="password" required placeholder="••••••••" class="w-full bg-[#051433] border border-[#0d2a63] rounded-2xl pl-11 pr-4 py-3 text-white text-sm focus:outline-none focus:border-[#00D9FF] transition">
                </div>
            </div>
            <button type="submit" name="admin_login" class="w-full snz-gradient text-[#061535] font-black py-3.5 rounded-2xl text-xs tracking-wider uppercase transition shadow-lg shadow-cyan-500/20 flex items-center justify-center gap-2">
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
    unset($_POST['action_create_key'], $_POST['action_add_balance'], $_POST['action_update_payments'], $_GET['delete_key']);
}

// =========================================================================
// 2. Create Custom License Key
// =========================================================================
if (isset($_POST['action_create_key'])) {
    $targetUserId = intval($_POST['user_id']);
    $planName = trim($_POST['plan_name']) ?: "Custom Admin Plan";
    $durationType = $_POST['duration_type'];

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

        $stmt = $pdo->prepare("INSERT INTO user_keys (user_id, license_key, plan_name, expires_at, status) VALUES (?, ?, ?, ?, 'active')");
        $stmt->execute([$targetUserId, $licenseKey, $planName, $expiresAt]);

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
        
        $fbDelUrl = rtrim($FIREBASE_URL, '/') . "/license_keys/" . urlencode($keyString) . ".json";
        pushToFirebase($fbDelUrl, "DELETE");

        $msg = "License Key ($keyString) permanently removed!";
    }
}

// Statistics (password সহ সব কলাম আনার দরকার নেই → দ্রুত)
$allUsers = $pdo->query("SELECT id, username, email, balance, created_at FROM users ORDER BY id DESC")->fetchAll();
$allKeys = $pdo->query("SELECT uk.id, uk.license_key, uk.plan_name, uk.expires_at, u.username, u.email FROM user_keys uk JOIN users u ON uk.user_id = u.id ORDER BY uk.id DESC LIMIT 50")->fetchAll();
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
    <link rel="stylesheet" href="assets/snz.css?v=2">
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
        .no-scrollbar { -ms-overflow-style: none; scrollbar-width: none; }
    </style>
</head>
<body class="bg-[#061535] text-[#F5F8FC] min-h-screen font-sans selection:bg-[#00D9FF] selection:text-[#061535]">

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
        <div class="flex items-center gap-2.5">
            <img src="<?= htmlspecialchars($SITE_LOGO) ?>" alt="<?= htmlspecialchars($SITE_NAME) ?> Logo" class="w-9 h-9 object-contain shrink-0">
            <div>
                <h1 class="text-base font-black text-white tracking-wider flex items-center gap-2">
                    SNZ<span class="text-[#00D9FF]">Pay</span> <span class="text-[9px] bg-[#00D9FF]/20 text-[#00D9FF] border border-[#00D9FF]/30 px-2 py-0.5 rounded-full font-bold">ADMIN PRO</span>
                </h1>
                <p class="text-[10px] text-slate-400">Master Cloud Controller</p>
            </div>
        </div>
        <div class="flex items-center gap-3">
            <a href="dashboard.php" target="_blank" class="text-xs bg-[#092257] hover:bg-[#0c2e75] text-white px-3.5 py-2 rounded-xl transition flex items-center gap-1.5 border border-[#174396]">
                <i class="fa-solid fa-arrow-up-right-from-square text-[10px] text-[#00D9FF]"></i> User Portal
            </a>
            <a href="?logout=1" class="text-xs bg-[#0a1e4a] hover:bg-[#0f2d6e] text-slate-300 border border-[#143985] px-3.5 py-2 rounded-xl font-bold transition flex items-center gap-1.5">
                <i class="fa-solid fa-arrow-right-from-bracket"></i> Logout
            </a>
        </div>
    </header>

    <!-- Top Horizontal Scroll Navigation -->
    <div class="bg-[#051433]/90 backdrop-blur-md border-b border-[#0d265c] sticky top-[65px] z-30 px-4 py-2.5">
        <div class="max-w-6xl mx-auto flex items-center gap-2 overflow-x-auto no-scrollbar whitespace-nowrap">
            <button onclick="switchSection('sec-overview')" id="tab-sec-overview" class="nav-tab px-4 py-2 rounded-xl text-xs font-bold text-slate-300 hover:bg-[#081e4b] active-tab transition flex items-center gap-2">
                <i class="fa-solid fa-chart-pie text-sm"></i> Overview
            </button>
            <button onclick="switchSection('sec-apk')" id="tab-sec-apk" class="nav-tab px-4 py-2 rounded-xl text-xs font-bold text-slate-400 hover:bg-[#081e4b] transition flex items-center gap-2">
                <i class="fa-brands fa-android text-sm text-[#35F58A]"></i> APK Manager (Any MB)
            </button>
            <button onclick="switchSection('sec-create-key')" id="tab-sec-create-key" class="nav-tab px-4 py-2 rounded-xl text-xs font-bold text-slate-400 hover:bg-[#081e4b] transition flex items-center gap-2">
                <i class="fa-solid fa-key text-sm text-[#00D9FF]"></i> Create License
            </button>
            <button onclick="switchSection('sec-balance')" id="tab-sec-balance" class="nav-tab px-4 py-2 rounded-xl text-xs font-bold text-slate-400 hover:bg-[#081e4b] transition flex items-center gap-2">
                <i class="fa-solid fa-wallet text-sm text-[#00D89A]"></i> Add Balance
            </button>
            <button onclick="switchSection('sec-payments')" id="tab-sec-payments" class="nav-tab px-4 py-2 rounded-xl text-xs font-bold text-slate-400 hover:bg-[#081e4b] transition flex items-center gap-2">
                <i class="fa-solid fa-credit-card text-sm text-[#007BFF]"></i> Payment Setup
            </button>
            <button onclick="switchSection('sec-licenses')" id="tab-sec-licenses" class="nav-tab px-4 py-2 rounded-xl text-xs font-bold text-slate-400 hover:bg-[#081e4b] transition flex items-center gap-2">
                <i class="fa-solid fa-list-check text-sm text-cyan-400"></i> All Licenses
            </button>
            <button onclick="switchSection('sec-users')" id="tab-sec-users" class="nav-tab px-4 py-2 rounded-xl text-xs font-bold text-slate-400 hover:bg-[#081e4b] transition flex items-center gap-2">
                <i class="fa-solid fa-users text-sm text-emerald-400"></i> All Users
            </button>
        </div>
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
                        <label class="text-xs font-semibold text-slate-200 block mb-1.5">Plan Title</label>
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
                                <th class="p-3 text-right">Action</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-[#0d265c] font-mono">
                            <?php if (empty($allKeys)): ?>
                                <tr><td colspan="5" class="p-4 text-center text-slate-400 font-sans">No license keys found.</td></tr>
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
                                            <?php if ($expired): ?>
                                                <span class="text-slate-400 text-[10px] block font-bold">[EXPIRED]</span>
                                            <?php else: ?>
                                                <span class="text-[#00D89A] text-[10px] block font-bold">[ACTIVE]</span>
                                            <?php endif; ?>
                                        </td>
                                        <td class="p-3 font-sans text-right">
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
        function switchSection(sectionId) {
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