<?php
// Gzip compression for ultra-fast page delivery
if (!ob_start("ob_gzhandler")) ob_start();

session_start();

// Central Configuration File Connection
require_once __DIR__ . '/config.php';

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

                if ($valid) {
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
    <link rel="stylesheet" href="assets/snz.css?v=2">
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
                    <img src="<?= htmlspecialchars($SITE_LOGO) ?>" alt="<?= htmlspecialchars($SITE_NAME) ?>" class="w-9 h-9 object-contain shrink-0">
                    <span class="text-lg font-black tracking-wider text-white">SNZ<span class="text-[#00D9FF]">Pay</span></span>
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
            <img src="<?= htmlspecialchars($SITE_LOGO) ?>" alt="<?= htmlspecialchars($SITE_NAME) ?> Logo" class="w-9 h-9 object-contain shrink-0">
            <div>
                <span class="text-xl font-black text-white tracking-wide">SNZ<span class="text-[#00D9FF]">Pay</span></span>
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
                        <img src="<?= htmlspecialchars($SITE_LOGO) ?>" alt="Badge" class="w-9 h-9 object-contain shrink-0">
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

        <div class="grid grid-cols-1 md:grid-cols-3 gap-6">
            <div class="interactive-card bg-[#081e4b] border border-[#123982] p-6 rounded-3xl flex flex-col justify-between">
                <div>
                    <span class="text-[10px] font-bold text-[#00D89A] bg-[#00D89A]/10 border border-[#00D89A]/30 px-3 py-1 rounded-full">1st Time Free</span>
                    <h3 class="text-lg font-bold text-white mt-3">30 Hours Trial</h3>
                    <p class="text-xs text-slate-300 mt-1">Test all automated features completely free of charge.</p>
                    <div class="text-3xl font-black text-white my-4">৳ 0</div>
                </div>
                <button onclick="openAuthModal('register')" class="w-full snz-gradient text-[#061535] font-black py-3 rounded-2xl text-xs transition">Claim Free Trial</button>
            </div>

            <div class="interactive-card bg-[#081e4b] border border-[#007BFF]/40 p-6 rounded-3xl flex flex-col justify-between">
                <div>
                    <span class="text-[10px] font-bold text-[#00D9FF] bg-[#007BFF]/20 border border-[#007BFF]/40 px-3 py-1 rounded-full">Standard</span>
                    <h3 class="text-lg font-bold text-white mt-3">7-Day License</h3>
                    <p class="text-xs text-slate-300 mt-1">7 days of uninterrupted SMS &amp; Binance automation.</p>
                    <div class="text-3xl font-black text-white my-4">৳ 30</div>
                </div>
                <button onclick="openAuthModal('register')" class="w-full bg-[#007BFF] hover:bg-[#0056b3] text-white font-bold py-3 rounded-2xl text-xs transition">Get Started</button>
            </div>

            <div class="interactive-card bg-[#081e4b] border border-[#00D89A]/50 p-6 rounded-3xl flex flex-col justify-between">
                <div>
                    <span class="snz-gradient text-[#061535] text-[10px] font-black px-3 py-1 rounded-full">Popular Choice</span>
                    <h3 class="text-lg font-bold text-white mt-3">30-Day License</h3>
                    <p class="text-slate-300 text-xs mt-1">Full 1 month of uninterrupted high-priority processing.</p>
                    <div class="text-3xl font-black text-white my-4">৳ 50</div>
                </div>
                <button onclick="openAuthModal('register')" class="w-full snz-gradient text-[#061535] font-black py-3 rounded-2xl text-xs transition shadow-lg shadow-cyan-500/25">Purchase Plan</button>
            </div>
        </div>
    </section>

    <!-- DOWNLOAD APP CTA -->
    <section id="download" class="max-w-4xl mx-auto px-4 py-12 text-center">
        <div class="interactive-card bg-gradient-to-br from-[#081d49] via-[#092257] to-[#04112c] border border-[#143985] p-8 md:p-12 rounded-3xl shadow-2xl">
            <img src="<?= htmlspecialchars($SITE_LOGO) ?>" alt="App Icon" class="w-16 h-16 object-contain mx-auto mb-4">
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
                <img src="<?= htmlspecialchars($SITE_LOGO) ?>" alt="<?= htmlspecialchars($SITE_NAME) ?>" class="w-6 h-6 object-contain shrink-0">
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
                <img src="<?= htmlspecialchars($SITE_LOGO) ?>" alt="<?= htmlspecialchars($SITE_NAME) ?> Logo" class="w-12 h-12 object-contain mx-auto mb-2">
                <h2 class="text-xl font-black text-white tracking-wide">SNZ<span class="text-[#00D9FF]">Pay</span> PORTAL</h2>
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