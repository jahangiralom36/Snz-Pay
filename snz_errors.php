<?php
// =========================================================================
// 🧯 SNZPay Error Log — ওয়েবসাইট আর অ্যাপের সব error এক জায়গায়
// Admin Panel → ☰ → Errors এ দেখা যায় (App Error / Website Error)
// এই ফাইল না থাকলেও সাইট চলবে — শুধু error লগ হবে না।
// =========================================================================
if (defined('SNZ_ERRORS_LOADED')) return;
define('SNZ_ERRORS_LOADED', 1);

/** DB না পেলে এখানে জমা থাকে (PHP ফাইল — ব্রাউজারে খুললে কিছু দেখা যায় না) */
function snz_err_fallback_file() {
    return __DIR__ . '/.snz_errors.php';
}

function snz_err_pdo($pdo = null) {
    static $cached = false;
    if ($pdo instanceof PDO) return $pdo;
    if ($cached !== false) return $cached;
    $cached = null;
    try {
        if (function_exists('getDBConnection')) $cached = getDBConnection();
    } catch (Throwable $e) {
        $cached = null;
    }
    return $cached;
}

function snz_err_table(PDO $pdo) {
    static $done = false;
    if ($done) return true;
    $flag = __DIR__ . '/.db_errors_v1';
    if (!is_file($flag)) {
        try {
            $pdo->exec("CREATE TABLE IF NOT EXISTS error_logs (
                id INT AUTO_INCREMENT PRIMARY KEY,
                source VARCHAR(10) NOT NULL DEFAULT 'web',
                err_hash CHAR(32) NOT NULL,
                title VARCHAR(190) NOT NULL DEFAULT '',
                message TEXT NULL,
                detail TEXT NULL,
                where_at VARCHAR(255) NULL,
                device VARCHAR(190) NULL,
                license_key VARCHAR(100) NULL,
                ip VARCHAR(64) NULL,
                app_version VARCHAR(30) NULL,
                hits INT NOT NULL DEFAULT 1,
                is_read TINYINT(1) NOT NULL DEFAULT 0,
                first_seen DATETIME NULL,
                last_seen DATETIME NULL,
                UNIQUE KEY uq_err_hash (err_hash),
                KEY idx_err_src (source, last_seen)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
            @file_put_contents($flag, date('c'));
        } catch (Throwable $e) {
            return false;
        }
    }
    $done = true;
    return true;
}

function snz_err_cut($s, $n) {
    $s = trim((string) $s);
    return function_exists('mb_substr') ? mb_substr($s, 0, $n) : substr($s, 0, $n);
}

/**
 * একটা error সেভ করা। একই error বারবার হলে নতুন সারি না — শুধু hits বাড়ে।
 * @param string $source 'app' বা 'web'
 */
function snz_log_error($source, $title, $message, $detail = '', array $extra = [], $pdo = null) {
    static $busy = false;
    if ($busy) return;
    $busy = true;
    try {
        $source  = $source === 'app' ? 'app' : 'web';
        $title   = snz_err_cut($title, 180);
        $message = snz_err_cut($message, 2000);
        $detail  = snz_err_cut($detail, 6000);
        // সংখ্যা/সময় বাদ দিয়ে hash → "Line 12" আর "Line 13" আলাদা, কিন্তু টাইমস্ট্যাম্প আলাদা হলেও একই error
        $norm = preg_replace('/\b\d{2}:\d{2}(:\d{2})?\b|\b\d{4}-\d{2}-\d{2}\b/', '', $title . '|' . $message);
        $hash = md5($source . '|' . $norm . '|' . ($extra['where'] ?? ''));
        $row = [
            'source' => $source, 'hash' => $hash, 'title' => $title, 'message' => $message, 'detail' => $detail,
            'where' => snz_err_cut($extra['where'] ?? '', 250),
            'device' => snz_err_cut($extra['device'] ?? '', 180),
            'license_key' => snz_err_cut($extra['license_key'] ?? '', 90),
            'ip' => snz_err_cut($extra['ip'] ?? ($_SERVER['REMOTE_ADDR'] ?? ''), 60),
            'app_version' => snz_err_cut($extra['app_version'] ?? '', 28),
            'time' => date('Y-m-d H:i:s'),
        ];
        $db = snz_err_pdo($pdo);
        if ($db && snz_err_table($db)) {
            snz_err_insert($db, $row);
        } else {
            // ডাটাবেজই কাজ না করলে ফাইলে রাখা — DB ঠিক হলে Admin খুললে ভেতরে চলে যাবে
            $f = snz_err_fallback_file();
            if (!is_file($f)) @file_put_contents($f, "<?php exit; ?>\n");
            if (@filesize($f) < 512 * 1024) @file_put_contents($f, json_encode($row, JSON_UNESCAPED_UNICODE) . "\n", FILE_APPEND | LOCK_EX);
        }
    } catch (Throwable $e) {
        // error লগ করতে গিয়ে সাইট যেন কখনো না ভাঙে
    }
    $busy = false;
}

function snz_err_insert(PDO $db, array $r) {
    $st = $db->prepare("INSERT INTO error_logs (source, err_hash, title, message, detail, where_at, device, license_key, ip, app_version, hits, is_read, first_seen, last_seen)
        VALUES (?,?,?,?,?,?,?,?,?,?,1,0,?,?)
        ON DUPLICATE KEY UPDATE hits = hits + 1, is_read = 0, last_seen = VALUES(last_seen),
            message = VALUES(message), detail = VALUES(detail), device = VALUES(device),
            license_key = VALUES(license_key), ip = VALUES(ip), app_version = VALUES(app_version)");
    $st->execute([$r['source'], $r['hash'], $r['title'], $r['message'], $r['detail'], $r['where'], $r['device'],
        $r['license_key'], $r['ip'], $r['app_version'], $r['time'], $r['time']]);
}

/** ফাইলে জমে থাকা error গুলো ডাটাবেজে তোলা (Admin পেজ খুললে) */
function snz_err_import_fallback(PDO $db) {
    $f = snz_err_fallback_file();
    if (!is_file($f) || !snz_err_table($db)) return;
    $lines = @file($f, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
    @unlink($f);
    foreach ($lines as $ln) {
        $r = json_decode($ln, true);
        if (is_array($r) && !empty($r['hash'])) {
            try { snz_err_insert($db, $r); } catch (Throwable $e) {}
        }
    }
}

/** ওয়েবসাইটের PHP warning / fatal error / exception ধরা */
function snz_install_error_handlers() {
    static $installed = false;
    if ($installed) return;
    $installed = true;
    $page = basename($_SERVER['SCRIPT_NAME'] ?? 'php');
    $isApi = strpos(str_replace('\\', '/', $_SERVER['SCRIPT_NAME'] ?? ''), '/api/') !== false;

    set_error_handler(function ($no, $str, $file, $line) use ($page) {
        // @ দিয়ে চুপ করানো, নোটিশ, deprecated → লগ না
        if (!(error_reporting() & $no)) return false;
        if (in_array($no, [E_NOTICE, E_USER_NOTICE, E_DEPRECATED, E_USER_DEPRECATED, 2048], true)) return false;
        $kind = in_array($no, [E_WARNING, E_USER_WARNING, E_CORE_WARNING, E_COMPILE_WARNING], true) ? 'PHP Warning' : 'PHP Error';
        snz_log_error('web', $kind . ' in ' . basename($file), $str, basename($file) . ' line ' . $line, ['where' => $page]);
        return false;   // PHP এর নিজের কাজ চলতে থাকুক
    });

    set_exception_handler(function ($e) use ($page, $isApi) {
        snz_log_error('web', 'Uncaught ' . get_class($e) . ' in ' . basename($e->getFile()), $e->getMessage(),
            basename($e->getFile()) . ' line ' . $e->getLine() . "\n" . snz_err_cut($e->getTraceAsString(), 3000), ['where' => $page]);
        if (!headers_sent()) http_response_code(500);
        if ($isApi) {
            if (!headers_sent()) header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['status' => 'error', 'message' => 'Server error. Admin has been notified.']);
        } else {
            echo '<div style="font-family:system-ui;max-width:420px;margin:60px auto;padding:24px;border-radius:18px;background:#0b2559;color:#e2e8f0;text-align:center">'
               . '<div style="font-size:34px">⚠️</div><h3 style="margin:8px 0">Something went wrong</h3>'
               . '<p style="color:#94a3b8;font-size:14px">Please try again in a moment. The admin has been notified.</p></div>';
        }
    });

    register_shutdown_function(function () use ($page) {
        $e = error_get_last();
        if ($e && in_array($e['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR, E_RECOVERABLE_ERROR], true)) {
            snz_log_error('web', 'Fatal error in ' . basename($e['file']), $e['message'], basename($e['file']) . ' line ' . $e['line'], ['where' => $page]);
        }
    });
}

/**
 * Admin popup এর জন্য সহজ বাংলা ব্যাখ্যা: কী সমস্যা + কী করবেন
 * @return array [icon, what, fix]
 */
function snz_error_advice($source, $title, $message) {
    $t = strtolower($title . ' ' . $message);
    $has = function () use ($t) {
        foreach (func_get_args() as $w) if (strpos($t, strtolower($w)) !== false) return true;
        return false;
    };
    if ($has('secret token', 'unauthorized', 'app token')) return ['🔐',
        'অ্যাপ SMS পাঠাচ্ছে, কিন্তু সার্ভার বলছে token মেলেনি। সাধারণত দুইটা কারণ: (১) ফোন এখনো পুরনো হোস্টিং এ যাচ্ছে (সেখানে পুরনো token), অথবা (২) config.php তে APP_SECRET_TOKEN আর অ্যাপের token আলাদা।',
        'config.php তে APP_SECRET_TOKEN এর মান অ্যাপের AppConfig.java এর SECRET_TOKEN এর মতো রাখুন। নতুন APK (v15) ইনস্টল করুন — সেটা নিজে থেকে নতুন সার্ভার খুঁজে নেয়। ডোমেইনের DNS এ শুধু নতুন হোস্টিং এর IP রাখুন।'];
    if ($has('404', 'old hosting', 'old dns', 'private dns')) return ['🌐',
        'ফোন ডোমেইনের পুরনো IP তে যাচ্ছে (হোস্টিং বদলানোর পর মোবাইল অপারেটরের DNS আপডেট হয়নি), তাই ফাইল খুঁজে পাচ্ছে না।',
        'নতুন APK (v15) দিন — সেটা Google/Cloudflare DNS দিয়ে আসল IP বের করে সরাসরি কানেক্ট করে। ডোমেইন প্যানেলে পুরনো A record মুছে দিন।'];
    if ($has('timeout', 'did not respond', 'cannot reach', 'no internet', 'unknownhost', 'unable to resolve')) return ['📡',
        'ফোন সার্ভারের সাথে কানেক্ট হতে পারেনি — ফোনে নেট নেই, নেট খুব স্লো, বা সার্ভার তখন বন্ধ ছিল।',
        'কিছু করতে হবে না — নেট আসলে অ্যাপ নিজেই SMS আবার পাঠাবে। বারবার আসলে হোস্টিং চালু আছে কিনা check.php খুলে দেখুন।'];
    if ($has('ssl', 'certificate', 'handshake')) return ['🔒',
        'HTTPS (SSL সার্টিফিকেট) এ সমস্যা — সার্টিফিকেট নেই, মেয়াদ শেষ, বা ভুল ডোমেইনের।',
        'হোস্টিং প্যানেল (cPanel → SSL/TLS বা AutoSSL) থেকে ডোমেইনের জন্য SSL চালু/রিনিউ করুন।'];
    if ($has('banned')) return ['🚫',
        'এই লাইসেন্স কী অ্যাডমিন থেকে ban করা, তাই অ্যাপ লগআউট হয়ে গেছে।',
        'ভুল করে ban হলে Admin → All Licenses থেকে Unban করুন।'];
    if ($has('connected to', 'device limit', 'another phone', 'already in use')) return ['📱',
        'কী টা প্ল্যানের সর্বোচ্চ ফোনে আগেই কানেক্ট আছে, নতুন ফোনে ঢুকতে পারছে না।',
        'Admin → All Licenses থেকে পুরনো ফোন Remove/Reset করুন, অথবা বেশি ডিভাইসের প্ল্যান দিন।'];
    if ($has('expired', 'not found', 'invalid', 'license', 'deleted')) return ['🔑',
        'লাইসেন্স কী সার্ভারে নেই / মেয়াদ শেষ / ভুল — তাই লগইন বা SMS আপলোড হচ্ছে না।',
        'ইউজারকে সঠিক ও চালু কী দিয়ে লগইন করতে বলুন। দরকার হলে Admin → Create License থেকে নতুন কী দিন।'];
    if ($has('crash', 'exception', 'nullpointer')) return $source === 'app' ? ['💥',
        'অ্যাপ হঠাৎ বন্ধ (crash) হয়েছিল। অ্যাপ আবার চালু হয়ে নিজে কাজ চালিয়ে যাচ্ছে।',
        'বারবার হলে Technical details টা কপি করে ডেভেলপারকে পাঠান।'] : ['💥',
        'ওয়েবসাইটের কোডে একটা অপ্রত্যাশিত সমস্যা হয়েছে, পেজটা ঠিকমতো লোড হয়নি।',
        'বারবার হলে Technical details টা কপি করে ডেভেলপারকে পাঠান। ফাইলগুলো সর্বশেষ ভার্সন আপলোড করা আছে কিনা দেখুন।'];
    if ($has('database connection', 'access denied for user', 'sqlstate[hy000] [1045]', 'sqlstate[hy000] [2002]')) return ['🗄️',
        'ওয়েবসাইট ডাটাবেজে ঢুকতে পারছে না।',
        'config.php তে DB_NAME, DB_USER, DB_PASS ঠিক আছে কিনা দেখুন, আর cPanel → MySQL Databases এ ইউজারকে ডাটাবেজে যোগ (All Privileges) করা আছে কিনা দেখুন।'];
    if ($has("doesn't exist", '42s02', 'base table')) return ['🗄️',
        'ডাটাবেজে একটা টেবিল নেই।',
        'phpMyAdmin এ database.sql ইমপোর্ট করুন (পুরনো ডাটা মুছবে না)।'];
    if ($has('unknown column', '42s22')) return ['🗄️',
        'ডাটাবেজের টেবিলে একটা কলাম নেই (পুরনো টেবিল)।',
        'Admin Panel একবার খুলুন — বেশিরভাগ কলাম নিজে তৈরি হয়। না হলে database.sql আবার ইমপোর্ট করুন।'];
    if ($has('permission denied', 'failed to open stream', 'not writable', 'could not save')) return ['📁',
        'সার্ভারের ফোল্ডারে ফাইল লেখা/পড়া যাচ্ছে না (permission)।',
        'cPanel → File Manager এ ওয়েবসাইট ফোল্ডার আর uploads ফোল্ডারের permission 755 দিন।'];
    if ($has('allowed memory', 'maximum execution time')) return ['⏱️',
        'হোস্টিং এর মেমরি/সময়ের সীমা শেষ হয়ে গেছে।',
        'cPanel → Select PHP Version / MultiPHP INI Editor এ memory_limit 256M আর max_execution_time 60 দিন।'];
    if ($has('call to undefined function', 'class "pdo"', 'could not find driver')) return ['🧩',
        'সার্ভারে দরকারি PHP extension বা ফাইল নেই।',
        'cPanel → Select PHP Version থেকে pdo_mysql, curl, mbstring চালু করুন এবং সব ফাইল আপলোড হয়েছে কিনা দেখুন।'];
    if ($has('server error', 'http 5')) return ['🛠️',
        'সার্ভার ভেতরে সমস্যায় পড়েছে (HTTP 500)।',
        'Website Error ট্যাবে একই সময়ের error দেখুন — আসল কারণ সেখানে থাকবে।'];
    if ($has('server problem', 'firewall', '403')) return ['🧱',
        'হোস্টিং এর ফায়ারওয়াল (ModSecurity) বা ভুল সার্ভার অ্যাপের রিকোয়েস্ট আটকে দিচ্ছে।',
        'হোস্টিং সাপোর্টকে বলুন /api/ ফোল্ডারের জন্য ModSecurity বন্ধ করতে। ডোমেইন সঠিক হোস্টিং এ যাচ্ছে কিনা check.php তে দেখুন।'];
    if ($has('warning')) return ['⚠️',
        'ওয়েবসাইটে একটা ছোট সতর্কবার্তা (warning) এসেছে — সাধারণত সাইট চলতে থাকে।',
        'বারবার না হলে চিন্তার কিছু নেই। বারবার হলে Technical details ডেভেলপারকে পাঠান।'];
    return ['ℹ️',
        $source === 'app' ? 'অ্যাপে একটা সমস্যা দেখা গেছে।' : 'ওয়েবসাইটে একটা সমস্যা দেখা গেছে।',
        'বারবার হলে Technical details টা কপি করে ডেভেলপারকে পাঠান।'];
}

snz_install_error_handlers();
