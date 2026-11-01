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
    <style>*,:after,:before{--tw-border-spacing-x:0;--tw-border-spacing-y:0;--tw-translate-x:0;--tw-translate-y:0;--tw-rotate:0;--tw-skew-x:0;--tw-skew-y:0;--tw-scale-x:1;--tw-scale-y:1;--tw-pan-x: ;--tw-pan-y: ;--tw-pinch-zoom: ;--tw-scroll-snap-strictness:proximity;--tw-gradient-from-position: ;--tw-gradient-via-position: ;--tw-gradient-to-position: ;--tw-ordinal: ;--tw-slashed-zero: ;--tw-numeric-figure: ;--tw-numeric-spacing: ;--tw-numeric-fraction: ;--tw-ring-inset: ;--tw-ring-offset-width:0px;--tw-ring-offset-color:#fff;--tw-ring-color:rgba(59,130,246,.5);--tw-ring-offset-shadow:0 0 #0000;--tw-ring-shadow:0 0 #0000;--tw-shadow:0 0 #0000;--tw-shadow-colored:0 0 #0000;--tw-blur: ;--tw-brightness: ;--tw-contrast: ;--tw-grayscale: ;--tw-hue-rotate: ;--tw-invert: ;--tw-saturate: ;--tw-sepia: ;--tw-drop-shadow: ;--tw-backdrop-blur: ;--tw-backdrop-brightness: ;--tw-backdrop-contrast: ;--tw-backdrop-grayscale: ;--tw-backdrop-hue-rotate: ;--tw-backdrop-invert: ;--tw-backdrop-opacity: ;--tw-backdrop-saturate: ;--tw-backdrop-sepia: ;--tw-contain-size: ;--tw-contain-layout: ;--tw-contain-paint: ;--tw-contain-style: }::backdrop{--tw-border-spacing-x:0;--tw-border-spacing-y:0;--tw-translate-x:0;--tw-translate-y:0;--tw-rotate:0;--tw-skew-x:0;--tw-skew-y:0;--tw-scale-x:1;--tw-scale-y:1;--tw-pan-x: ;--tw-pan-y: ;--tw-pinch-zoom: ;--tw-scroll-snap-strictness:proximity;--tw-gradient-from-position: ;--tw-gradient-via-position: ;--tw-gradient-to-position: ;--tw-ordinal: ;--tw-slashed-zero: ;--tw-numeric-figure: ;--tw-numeric-spacing: ;--tw-numeric-fraction: ;--tw-ring-inset: ;--tw-ring-offset-width:0px;--tw-ring-offset-color:#fff;--tw-ring-color:rgba(59,130,246,.5);--tw-ring-offset-shadow:0 0 #0000;--tw-ring-shadow:0 0 #0000;--tw-shadow:0 0 #0000;--tw-shadow-colored:0 0 #0000;--tw-blur: ;--tw-brightness: ;--tw-contrast: ;--tw-grayscale: ;--tw-hue-rotate: ;--tw-invert: ;--tw-saturate: ;--tw-sepia: ;--tw-drop-shadow: ;--tw-backdrop-blur: ;--tw-backdrop-brightness: ;--tw-backdrop-contrast: ;--tw-backdrop-grayscale: ;--tw-backdrop-hue-rotate: ;--tw-backdrop-invert: ;--tw-backdrop-opacity: ;--tw-backdrop-saturate: ;--tw-backdrop-sepia: ;--tw-contain-size: ;--tw-contain-layout: ;--tw-contain-paint: ;--tw-contain-style: }/*! tailwindcss v3.4.17 | MIT License | https://tailwindcss.com*/*,:after,:before{box-sizing:border-box;border:0 solid #e5e7eb}:after,:before{--tw-content:""}:host,html{line-height:1.5;-webkit-text-size-adjust:100%;-moz-tab-size:4;-o-tab-size:4;tab-size:4;font-family:ui-sans-serif,system-ui,sans-serif,Apple Color Emoji,Segoe UI Emoji,Segoe UI Symbol,Noto Color Emoji;font-feature-settings:normal;font-variation-settings:normal;-webkit-tap-highlight-color:transparent}body{margin:0;line-height:inherit}hr{height:0;color:inherit;border-top-width:1px}abbr:where([title]){-webkit-text-decoration:underline dotted;text-decoration:underline dotted}h1,h2,h3,h4,h5,h6{font-size:inherit;font-weight:inherit}a{color:inherit;text-decoration:inherit}b,strong{font-weight:bolder}code,kbd,pre,samp{font-family:ui-monospace,SFMono-Regular,Menlo,Monaco,Consolas,Liberation Mono,Courier New,monospace;font-feature-settings:normal;font-variation-settings:normal;font-size:1em}small{font-size:80%}sub,sup{font-size:75%;line-height:0;position:relative;vertical-align:baseline}sub{bottom:-.25em}sup{top:-.5em}table{text-indent:0;border-color:inherit;border-collapse:collapse}button,input,optgroup,select,textarea{font-family:inherit;font-feature-settings:inherit;font-variation-settings:inherit;font-size:100%;font-weight:inherit;line-height:inherit;letter-spacing:inherit;color:inherit;margin:0;padding:0}button,select{text-transform:none}button,input:where([type=button]),input:where([type=reset]),input:where([type=submit]){-webkit-appearance:button;background-color:transparent;background-image:none}:-moz-focusring{outline:auto}:-moz-ui-invalid{box-shadow:none}progress{vertical-align:baseline}::-webkit-inner-spin-button,::-webkit-outer-spin-button{height:auto}[type=search]{-webkit-appearance:textfield;outline-offset:-2px}::-webkit-search-decoration{-webkit-appearance:none}::-webkit-file-upload-button{-webkit-appearance:button;font:inherit}summary{display:list-item}blockquote,dd,dl,figure,h1,h2,h3,h4,h5,h6,hr,p,pre{margin:0}fieldset{margin:0}fieldset,legend{padding:0}menu,ol,ul{list-style:none;margin:0;padding:0}dialog{padding:0}textarea{resize:vertical}input::-moz-placeholder,textarea::-moz-placeholder{opacity:1;color:#9ca3af}input::placeholder,textarea::placeholder{opacity:1;color:#9ca3af}[role=button],button{cursor:pointer}:disabled{cursor:default}audio,canvas,embed,iframe,img,object,svg,video{display:block;vertical-align:middle}img,video{max-width:100%;height:auto}[hidden]:where(:not([hidden=until-found])){display:none}.\!container{width:100%!important}.container{width:100%}@media (min-width:640px){.\!container{max-width:640px!important}.container{max-width:640px}}@media (min-width:768px){.\!container{max-width:768px!important}.container{max-width:768px}}@media (min-width:1024px){.\!container{max-width:1024px!important}.container{max-width:1024px}}@media (min-width:1280px){.\!container{max-width:1280px!important}.container{max-width:1280px}}@media (min-width:1536px){.\!container{max-width:1536px!important}.container{max-width:1536px}}.sr-only{position:absolute;width:1px;height:1px;padding:0;margin:-1px;overflow:hidden;clip:rect(0,0,0,0);white-space:nowrap;border-width:0}.pointer-events-none{pointer-events:none}.static{position:static}.fixed{position:fixed}.absolute{position:absolute}.relative{position:relative}.sticky{position:sticky}.inset-0{inset:0}.bottom-0{bottom:0}.left-0{left:0}.left-1\/2{left:50%}.left-4{left:1rem}.right-0{right:0}.right-4{right:1rem}.right-5{right:1.25rem}.right-6{right:1.5rem}.top-0{top:0}.top-3\.5{top:.875rem}.top-5{top:1.25rem}.top-\[65px\]{top:65px}.z-30{z-index:30}.z-40{z-index:40}.z-50{z-index:50}.z-\[60\]{z-index:60}.z-\[70\]{z-index:70}.mx-auto{margin-left:auto;margin-right:auto}.my-3{margin-top:.75rem;margin-bottom:.75rem}.my-4{margin-top:1rem;margin-bottom:1rem}.-mt-0\.5{margin-top:-.125rem}.mb-1\.5{margin-bottom:.375rem}.mb-10{margin-bottom:2.5rem}.mb-2{margin-bottom:.5rem}.mb-3{margin-bottom:.75rem}.mb-4{margin-bottom:1rem}.mb-5{margin-bottom:1.25rem}.mb-6{margin-bottom:1.5rem}.mt-0\.5{margin-top:.125rem}.mt-1{margin-top:.25rem}.mt-2{margin-top:.5rem}.mt-3{margin-top:.75rem}.mt-4{margin-top:1rem}.mt-5{margin-top:1.25rem}.mt-6{margin-top:1.5rem}.block{display:block}.flex{display:flex}.inline-flex{display:inline-flex}.table{display:table}.grid{display:grid}.hidden{display:none}.h-10{height:2.5rem}.h-11{height:2.75rem}.h-12{height:3rem}.h-14{height:3.5rem}.h-16{height:4rem}.h-2\.5{height:.625rem}.h-20{height:5rem}.h-36{height:9rem}.h-48{height:12rem}.h-6{height:1.5rem}.h-8{height:2rem}.h-9{height:2.25rem}.h-full{height:100%}.min-h-screen{min-height:100vh}.w-1\/2{width:50%}.w-10{width:2.5rem}.w-11{width:2.75rem}.w-12{width:3rem}.w-14{width:3.5rem}.w-16{width:4rem}.w-20{width:5rem}.w-4{width:1rem}.w-48{width:12rem}.w-5{width:1.25rem}.w-6{width:1.5rem}.w-72{width:18rem}.w-8{width:2rem}.w-9{width:2.25rem}.w-\[90\%\]{width:90%}.w-full{width:100%}.min-w-0{min-width:0}.max-w-2xl{max-width:42rem}.max-w-4xl{max-width:56rem}.max-w-6xl{max-width:72rem}.max-w-lg{max-width:32rem}.max-w-md{max-width:28rem}.max-w-sm{max-width:24rem}.max-w-xl{max-width:36rem}.max-w-xs{max-width:20rem}.flex-1{flex:1 1 0%}.shrink-0{flex-shrink:0}.-translate-x-1\/2{--tw-translate-x:-50%}.-translate-x-1\/2,.-translate-x-full{transform:translate(var(--tw-translate-x),var(--tw-translate-y)) rotate(var(--tw-rotate)) skewX(var(--tw-skew-x)) skewY(var(--tw-skew-y)) scaleX(var(--tw-scale-x)) scaleY(var(--tw-scale-y))}.-translate-x-full{--tw-translate-x:-100%}.-translate-y-8{--tw-translate-y:-2rem}.-translate-y-8,.translate-x-full{transform:translate(var(--tw-translate-x),var(--tw-translate-y)) rotate(var(--tw-rotate)) skewX(var(--tw-skew-x)) skewY(var(--tw-skew-y)) scaleX(var(--tw-scale-x)) scaleY(var(--tw-scale-y))}.translate-x-full{--tw-translate-x:100%}.translate-y-0{--tw-translate-y:0px}.transform,.translate-y-0{transform:translate(var(--tw-translate-x),var(--tw-translate-y)) rotate(var(--tw-rotate)) skewX(var(--tw-skew-x)) skewY(var(--tw-skew-y)) scaleX(var(--tw-scale-x)) scaleY(var(--tw-scale-y))}@keyframes bounce{0%,to{transform:translateY(-25%);animation-timing-function:cubic-bezier(.8,0,1,1)}50%{transform:none;animation-timing-function:cubic-bezier(0,0,.2,1)}}.animate-bounce{animation:bounce 1s infinite}@keyframes pulse{50%{opacity:.5}}.animate-pulse{animation:pulse 2s cubic-bezier(.4,0,.6,1) infinite}.cursor-not-allowed{cursor:not-allowed}.cursor-pointer{cursor:pointer}.select-all{-webkit-user-select:all;-moz-user-select:all;user-select:all}.grid-cols-1{grid-template-columns:repeat(1,minmax(0,1fr))}.grid-cols-2{grid-template-columns:repeat(2,minmax(0,1fr))}.grid-cols-3{grid-template-columns:repeat(3,minmax(0,1fr))}.flex-col{flex-direction:column}.flex-wrap{flex-wrap:wrap}.items-start{align-items:flex-start}.items-center{align-items:center}.items-stretch{align-items:stretch}.justify-center{justify-content:center}.justify-between{justify-content:space-between}.gap-1{gap:.25rem}.gap-1\.5{gap:.375rem}.gap-10{gap:2.5rem}.gap-2{gap:.5rem}.gap-2\.5{gap:.625rem}.gap-3{gap:.75rem}.gap-3\.5{gap:.875rem}.gap-4{gap:1rem}.gap-5{gap:1.25rem}.gap-6{gap:1.5rem}.gap-8{gap:2rem}.space-y-1>:not([hidden])~:not([hidden]){--tw-space-y-reverse:0;margin-top:calc(.25rem*(1 - var(--tw-space-y-reverse)));margin-bottom:calc(.25rem*var(--tw-space-y-reverse))}.space-y-1\.5>:not([hidden])~:not([hidden]){--tw-space-y-reverse:0;margin-top:calc(.375rem*(1 - var(--tw-space-y-reverse)));margin-bottom:calc(.375rem*var(--tw-space-y-reverse))}.space-y-2>:not([hidden])~:not([hidden]){--tw-space-y-reverse:0;margin-top:calc(.5rem*(1 - var(--tw-space-y-reverse)));margin-bottom:calc(.5rem*var(--tw-space-y-reverse))}.space-y-2\.5>:not([hidden])~:not([hidden]){--tw-space-y-reverse:0;margin-top:calc(.625rem*(1 - var(--tw-space-y-reverse)));margin-bottom:calc(.625rem*var(--tw-space-y-reverse))}.space-y-3>:not([hidden])~:not([hidden]){--tw-space-y-reverse:0;margin-top:calc(.75rem*(1 - var(--tw-space-y-reverse)));margin-bottom:calc(.75rem*var(--tw-space-y-reverse))}.space-y-3\.5>:not([hidden])~:not([hidden]){--tw-space-y-reverse:0;margin-top:calc(.875rem*(1 - var(--tw-space-y-reverse)));margin-bottom:calc(.875rem*var(--tw-space-y-reverse))}.space-y-4>:not([hidden])~:not([hidden]){--tw-space-y-reverse:0;margin-top:calc(1rem*(1 - var(--tw-space-y-reverse)));margin-bottom:calc(1rem*var(--tw-space-y-reverse))}.space-y-5>:not([hidden])~:not([hidden]){--tw-space-y-reverse:0;margin-top:calc(1.25rem*(1 - var(--tw-space-y-reverse)));margin-bottom:calc(1.25rem*var(--tw-space-y-reverse))}.space-y-6>:not([hidden])~:not([hidden]){--tw-space-y-reverse:0;margin-top:calc(1.5rem*(1 - var(--tw-space-y-reverse)));margin-bottom:calc(1.5rem*var(--tw-space-y-reverse))}.divide-y>:not([hidden])~:not([hidden]){--tw-divide-y-reverse:0;border-top-width:calc(1px*(1 - var(--tw-divide-y-reverse)));border-bottom-width:calc(1px*var(--tw-divide-y-reverse))}.divide-\[\#0d265c\]>:not([hidden])~:not([hidden]){--tw-divide-opacity:1;border-color:rgb(13 38 92/var(--tw-divide-opacity,1))}.divide-\[\#0e275c\]>:not([hidden])~:not([hidden]){--tw-divide-opacity:1;border-color:rgb(14 39 92/var(--tw-divide-opacity,1))}.overflow-hidden{overflow:hidden}.overflow-x-auto{overflow-x:auto}.overflow-x-hidden{overflow-x:hidden}.truncate{overflow:hidden;text-overflow:ellipsis}.truncate,.whitespace-nowrap{white-space:nowrap}.break-all{word-break:break-all}.rounded-2xl{border-radius:1rem}.rounded-3xl{border-radius:1.5rem}.rounded-full{border-radius:9999px}.rounded-lg{border-radius:.5rem}.rounded-xl{border-radius:.75rem}.border{border-width:1px}.border-2{border-width:2px}.border-b{border-bottom-width:1px}.border-l{border-left-width:1px}.border-r{border-right-width:1px}.border-t{border-top-width:1px}.border-dashed{border-style:dashed}.border-\[\#007BFF\]\/30{border-color:rgba(0,123,255,.3)}.border-\[\#007BFF\]\/40{border-color:rgba(0,123,255,.4)}.border-\[\#007BFF\]\/50{border-color:rgba(0,123,255,.5)}.border-\[\#00D89A\]\/20{border-color:rgba(0,216,154,.2)}.border-\[\#00D89A\]\/30{border-color:rgba(0,216,154,.3)}.border-\[\#00D89A\]\/40{border-color:rgba(0,216,154,.4)}.border-\[\#00D89A\]\/50{border-color:rgba(0,216,154,.5)}.border-\[\#00D9FF\]{--tw-border-opacity:1;border-color:rgb(0 217 255/var(--tw-border-opacity,1))}.border-\[\#00D9FF\]\/20{border-color:rgba(0,217,255,.2)}.border-\[\#00D9FF\]\/30{border-color:rgba(0,217,255,.3)}.border-\[\#00D9FF\]\/40{border-color:rgba(0,217,255,.4)}.border-\[\#00D9FF\]\/50{border-color:rgba(0,217,255,.5)}.border-\[\#0d265c\]{--tw-border-opacity:1;border-color:rgb(13 38 92/var(--tw-border-opacity,1))}.border-\[\#0d2a63\]{--tw-border-opacity:1;border-color:rgb(13 42 99/var(--tw-border-opacity,1))}.border-\[\#0e275c\]{--tw-border-opacity:1;border-color:rgb(14 39 92/var(--tw-border-opacity,1))}.border-\[\#0e306e\]{--tw-border-opacity:1;border-color:rgb(14 48 110/var(--tw-border-opacity,1))}.border-\[\#10326f\]{--tw-border-opacity:1;border-color:rgb(16 50 111/var(--tw-border-opacity,1))}.border-\[\#123982\]{--tw-border-opacity:1;border-color:rgb(18 57 130/var(--tw-border-opacity,1))}.border-\[\#143985\]{--tw-border-opacity:1;border-color:rgb(20 57 133/var(--tw-border-opacity,1))}.border-\[\#16418f\]{--tw-border-opacity:1;border-color:rgb(22 65 143/var(--tw-border-opacity,1))}.border-\[\#174396\]{--tw-border-opacity:1;border-color:rgb(23 67 150/var(--tw-border-opacity,1))}.border-amber-500\/30{border-color:rgba(245,158,11,.3)}.border-amber-500\/40{border-color:rgba(245,158,11,.4)}.border-rose-500\/40{border-color:rgba(244,63,94,.4)}.border-slate-700{--tw-border-opacity:1;border-color:rgb(51 65 85/var(--tw-border-opacity,1))}.bg-\[\#007BFF\]{--tw-bg-opacity:1;background-color:rgb(0 123 255/var(--tw-bg-opacity,1))}.bg-\[\#007BFF\]\/20{background-color:rgba(0,123,255,.2)}.bg-\[\#00D89A\]\/10{background-color:rgba(0,216,154,.1)}.bg-\[\#00D89A\]\/15{background-color:rgba(0,216,154,.15)}.bg-\[\#00D89A\]\/20{background-color:rgba(0,216,154,.2)}.bg-\[\#00D9FF\]\/10{background-color:rgba(0,217,255,.1)}.bg-\[\#00D9FF\]\/15{background-color:rgba(0,217,255,.15)}.bg-\[\#00D9FF\]\/20{background-color:rgba(0,217,255,.2)}.bg-\[\#020817\]{--tw-bg-opacity:1;background-color:rgb(2 8 23/var(--tw-bg-opacity,1))}.bg-\[\#020817\]\/80{background-color:rgba(2,8,23,.8)}.bg-\[\#020817\]\/85{background-color:rgba(2,8,23,.85)}.bg-\[\#030d24\]\/95{background-color:rgba(3,13,36,.95)}.bg-\[\#03132e\]{--tw-bg-opacity:1;background-color:rgb(3 19 46/var(--tw-bg-opacity,1))}.bg-\[\#040e24\]{--tw-bg-opacity:1;background-color:rgb(4 14 36/var(--tw-bg-opacity,1))}.bg-\[\#04122d\]{--tw-bg-opacity:1;background-color:rgb(4 18 45/var(--tw-bg-opacity,1))}.bg-\[\#05112b\]\/95{background-color:rgba(5,17,43,.95)}.bg-\[\#051433\]{--tw-bg-opacity:1;background-color:rgb(5 20 51/var(--tw-bg-opacity,1))}.bg-\[\#051433\]\/90{background-color:rgba(5,20,51,.9)}.bg-\[\#051433\]\/95{background-color:rgba(5,20,51,.95)}.bg-\[\#061535\]{--tw-bg-opacity:1;background-color:rgb(6 21 53/var(--tw-bg-opacity,1))}.bg-\[\#061535\]\/80{background-color:rgba(6,21,53,.8)}.bg-\[\#061535\]\/85{background-color:rgba(6,21,53,.85)}.bg-\[\#061535\]\/90{background-color:rgba(6,21,53,.9)}.bg-\[\#071638\]{--tw-bg-opacity:1;background-color:rgb(7 22 56/var(--tw-bg-opacity,1))}.bg-\[\#081b42\]\/95{background-color:rgba(8,27,66,.95)}.bg-\[\#081e4b\]{--tw-bg-opacity:1;background-color:rgb(8 30 75/var(--tw-bg-opacity,1))}.bg-\[\#09204e\]{--tw-bg-opacity:1;background-color:rgb(9 32 78/var(--tw-bg-opacity,1))}.bg-\[\#092257\]{--tw-bg-opacity:1;background-color:rgb(9 34 87/var(--tw-bg-opacity,1))}.bg-\[\#0a1e4a\]{--tw-bg-opacity:1;background-color:rgb(10 30 74/var(--tw-bg-opacity,1))}.bg-\[\#0a2356\]{--tw-bg-opacity:1;background-color:rgb(10 35 86/var(--tw-bg-opacity,1))}.bg-\[\#0c2454\]{--tw-bg-opacity:1;background-color:rgb(12 36 84/var(--tw-bg-opacity,1))}.bg-\[\#35F58A\]\/15{background-color:rgba(53,245,138,.15)}.bg-\[\#35F58A\]\/20{background-color:rgba(53,245,138,.2)}.bg-\[\#8B5CF6\]\/20{background-color:rgba(139,92,246,.2)}.bg-amber-500\/10{background-color:rgba(245,158,11,.1)}.bg-amber-500\/15{background-color:rgba(245,158,11,.15)}.bg-amber-500\/20{background-color:rgba(245,158,11,.2)}.bg-black\/80{background-color:rgba(0,0,0,.8)}.bg-rose-500\/20{background-color:rgba(244,63,94,.2)}.bg-slate-800{--tw-bg-opacity:1;background-color:rgb(30 41 59/var(--tw-bg-opacity,1))}.bg-transparent{background-color:transparent}.bg-white{--tw-bg-opacity:1;background-color:rgb(255 255 255/var(--tw-bg-opacity,1))}.bg-gradient-to-br{background-image:linear-gradient(to bottom right,var(--tw-gradient-stops))}.from-\[\#081d49\]{--tw-gradient-from:#081d49 var(--tw-gradient-from-position);--tw-gradient-to:rgba(8,29,73,0) var(--tw-gradient-to-position);--tw-gradient-stops:var(--tw-gradient-from),var(--tw-gradient-to)}.via-\[\#092257\]{--tw-gradient-to:rgba(9,34,87,0) var(--tw-gradient-to-position);--tw-gradient-stops:var(--tw-gradient-from),#092257 var(--tw-gradient-via-position),var(--tw-gradient-to)}.to-\[\#04112c\]{--tw-gradient-to:#04112c var(--tw-gradient-to-position)}.object-contain{-o-object-fit:contain;object-fit:contain}.p-1{padding:.25rem}.p-1\.5{padding:.375rem}.p-2{padding:.5rem}.p-3{padding:.75rem}.p-3\.5{padding:.875rem}.p-4{padding:1rem}.p-5{padding:1.25rem}.p-6{padding:1.5rem}.p-8{padding:2rem}.px-2{padding-left:.5rem;padding-right:.5rem}.px-2\.5{padding-left:.625rem;padding-right:.625rem}.px-3{padding-left:.75rem;padding-right:.75rem}.px-3\.5{padding-left:.875rem;padding-right:.875rem}.px-4{padding-left:1rem;padding-right:1rem}.px-5{padding-left:1.25rem;padding-right:1.25rem}.px-7{padding-left:1.75rem;padding-right:1.75rem}.py-0\.5{padding-top:.125rem;padding-bottom:.125rem}.py-1{padding-top:.25rem;padding-bottom:.25rem}.py-1\.5{padding-top:.375rem;padding-bottom:.375rem}.py-10{padding-top:2.5rem;padding-bottom:2.5rem}.py-12{padding-top:3rem;padding-bottom:3rem}.py-2{padding-top:.5rem;padding-bottom:.5rem}.py-2\.5{padding-top:.625rem;padding-bottom:.625rem}.py-3{padding-top:.75rem;padding-bottom:.75rem}.py-3\.5{padding-top:.875rem;padding-bottom:.875rem}.py-8{padding-top:2rem;padding-bottom:2rem}.pb-3{padding-bottom:.75rem}.pb-4{padding-bottom:1rem}.pb-5{padding-bottom:1.25rem}.pl-11{padding-left:2.75rem}.pr-11{padding-right:2.75rem}.pr-2{padding-right:.5rem}.pr-4{padding-right:1rem}.pt-0\.5{padding-top:.125rem}.pt-2{padding-top:.5rem}.pt-2\.5{padding-top:.625rem}.pt-3{padding-top:.75rem}.pt-6{padding-top:1.5rem}.text-left{text-align:left}.text-center{text-align:center}.text-right{text-align:right}.font-mono{font-family:ui-monospace,SFMono-Regular,Menlo,Monaco,Consolas,Liberation Mono,Courier New,monospace}.font-sans{font-family:ui-sans-serif,system-ui,sans-serif,Apple Color Emoji,Segoe UI Emoji,Segoe UI Symbol,Noto Color Emoji}.text-2xl{font-size:1.5rem;line-height:2rem}.text-3xl{font-size:1.875rem;line-height:2.25rem}.text-4xl{font-size:2.25rem;line-height:2.5rem}.text-\[10px\]{font-size:10px}.text-\[11px\]{font-size:11px}.text-\[6px\]{font-size:6px}.text-\[9px\]{font-size:9px}.text-base{font-size:1rem;line-height:1.5rem}.text-lg{font-size:1.125rem;line-height:1.75rem}.text-sm{font-size:.875rem;line-height:1.25rem}.text-xl{font-size:1.25rem;line-height:1.75rem}.text-xs{font-size:.75rem;line-height:1rem}.font-black{font-weight:900}.font-bold{font-weight:700}.font-medium{font-weight:500}.font-semibold{font-weight:600}.uppercase{text-transform:uppercase}.leading-relaxed{line-height:1.625}.leading-tight{line-height:1.25}.tracking-wide{letter-spacing:.025em}.tracking-wider{letter-spacing:.05em}.tracking-widest{letter-spacing:.1em}.text-\[\#007BFF\]{--tw-text-opacity:1;color:rgb(0 123 255/var(--tw-text-opacity,1))}.text-\[\#00D89A\]{--tw-text-opacity:1;color:rgb(0 216 154/var(--tw-text-opacity,1))}.text-\[\#00D89A\]\/20{color:rgba(0,216,154,.2)}.text-\[\#00D9FF\]{--tw-text-opacity:1;color:rgb(0 217 255/var(--tw-text-opacity,1))}.text-\[\#00D9FF\]\/20{color:rgba(0,217,255,.2)}.text-\[\#020817\]{--tw-text-opacity:1;color:rgb(2 8 23/var(--tw-text-opacity,1))}.text-\[\#0284C7\]{--tw-text-opacity:1;color:rgb(2 132 199/var(--tw-text-opacity,1))}.text-\[\#061535\]{--tw-text-opacity:1;color:rgb(6 21 53/var(--tw-text-opacity,1))}.text-\[\#35F58A\]{--tw-text-opacity:1;color:rgb(53 245 138/var(--tw-text-opacity,1))}.text-\[\#35F58A\]\/20{color:rgba(53,245,138,.2)}.text-\[\#38BDF8\]{--tw-text-opacity:1;color:rgb(56 189 248/var(--tw-text-opacity,1))}.text-\[\#7C3AED\]{--tw-text-opacity:1;color:rgb(124 58 237/var(--tw-text-opacity,1))}.text-\[\#8B5CF6\]{--tw-text-opacity:1;color:rgb(139 92 246/var(--tw-text-opacity,1))}.text-\[\#E2136E\]{--tw-text-opacity:1;color:rgb(226 19 110/var(--tw-text-opacity,1))}.text-\[\#EA580C\]{--tw-text-opacity:1;color:rgb(234 88 12/var(--tw-text-opacity,1))}.text-\[\#F5F8FC\]{--tw-text-opacity:1;color:rgb(245 248 252/var(--tw-text-opacity,1))}.text-\[\#FBBF24\],.text-amber-400{--tw-text-opacity:1;color:rgb(251 191 36/var(--tw-text-opacity,1))}.text-cyan-400{--tw-text-opacity:1;color:rgb(34 211 238/var(--tw-text-opacity,1))}.text-emerald-400{--tw-text-opacity:1;color:rgb(52 211 153/var(--tw-text-opacity,1))}.text-rose-400{--tw-text-opacity:1;color:rgb(251 113 133/var(--tw-text-opacity,1))}.text-slate-200{--tw-text-opacity:1;color:rgb(226 232 240/var(--tw-text-opacity,1))}.text-slate-300{--tw-text-opacity:1;color:rgb(203 213 225/var(--tw-text-opacity,1))}.text-slate-400{--tw-text-opacity:1;color:rgb(148 163 184/var(--tw-text-opacity,1))}.text-slate-500{--tw-text-opacity:1;color:rgb(100 116 139/var(--tw-text-opacity,1))}.text-white{--tw-text-opacity:1;color:rgb(255 255 255/var(--tw-text-opacity,1))}.opacity-0{opacity:0}.opacity-100{opacity:1}.opacity-50{opacity:.5}.shadow-2xl{--tw-shadow:0 25px 50px -12px rgba(0,0,0,.25);--tw-shadow-colored:0 25px 50px -12px var(--tw-shadow-color);box-shadow:var(--tw-ring-offset-shadow,0 0 #0000),var(--tw-ring-shadow,0 0 #0000),var(--tw-shadow)}.shadow-\[0_0_10px_rgba\(0\2c 123\2c 255\2c 0\.4\)\]{--tw-shadow:0 0 10px rgba(0,123,255,.4);--tw-shadow-colored:0 0 10px var(--tw-shadow-color);box-shadow:var(--tw-ring-offset-shadow,0 0 #0000),var(--tw-ring-shadow,0 0 #0000),var(--tw-shadow)}.shadow-\[0_0_10px_rgba\(0\2c 216\2c 154\2c 0\.4\)\]{--tw-shadow:0 0 10px rgba(0,216,154,.4);--tw-shadow-colored:0 0 10px var(--tw-shadow-color);box-shadow:var(--tw-ring-offset-shadow,0 0 #0000),var(--tw-ring-shadow,0 0 #0000),var(--tw-shadow)}.shadow-\[0_0_10px_rgba\(0\2c 217\2c 255\2c 0\.2\)\]{--tw-shadow:0 0 10px rgba(0,217,255,.2);--tw-shadow-colored:0 0 10px var(--tw-shadow-color);box-shadow:var(--tw-ring-offset-shadow,0 0 #0000),var(--tw-ring-shadow,0 0 #0000),var(--tw-shadow)}.shadow-\[0_0_10px_rgba\(0\2c 217\2c 255\2c 0\.4\)\]{--tw-shadow:0 0 10px rgba(0,217,255,.4);--tw-shadow-colored:0 0 10px var(--tw-shadow-color);box-shadow:var(--tw-ring-offset-shadow,0 0 #0000),var(--tw-ring-shadow,0 0 #0000),var(--tw-shadow)}.shadow-\[0_0_10px_rgba\(139\2c 92\2c 246\2c 0\.4\)\]{--tw-shadow:0 0 10px rgba(139,92,246,.4);--tw-shadow-colored:0 0 10px var(--tw-shadow-color);box-shadow:var(--tw-ring-offset-shadow,0 0 #0000),var(--tw-ring-shadow,0 0 #0000),var(--tw-shadow)}.shadow-\[0_0_15px_rgba\(0\2c 217\2c 255\2c 0\.1\)\]{--tw-shadow:0 0 15px rgba(0,217,255,.1);--tw-shadow-colored:0 0 15px var(--tw-shadow-color);box-shadow:var(--tw-ring-offset-shadow,0 0 #0000),var(--tw-ring-shadow,0 0 #0000),var(--tw-shadow)}.shadow-\[0_0_15px_rgba\(0\2c 217\2c 255\2c 0\.15\)\]{--tw-shadow:0 0 15px rgba(0,217,255,.15);--tw-shadow-colored:0 0 15px var(--tw-shadow-color);box-shadow:var(--tw-ring-offset-shadow,0 0 #0000),var(--tw-ring-shadow,0 0 #0000),var(--tw-shadow)}.shadow-\[0_0_20px_rgba\(0\2c 217\2c 255\2c 0\.12\)\]{--tw-shadow:0 0 20px rgba(0,217,255,.12);--tw-shadow-colored:0 0 20px var(--tw-shadow-color);box-shadow:var(--tw-ring-offset-shadow,0 0 #0000),var(--tw-ring-shadow,0 0 #0000),var(--tw-shadow)}.shadow-\[0_0_20px_rgba\(0\2c 217\2c 255\2c 0\.4\)\]{--tw-shadow:0 0 20px rgba(0,217,255,.4);--tw-shadow-colored:0 0 20px var(--tw-shadow-color);box-shadow:var(--tw-ring-offset-shadow,0 0 #0000),var(--tw-ring-shadow,0 0 #0000),var(--tw-shadow)}.shadow-\[0_0_25px_rgba\(0\2c 217\2c 255\2c 0\.3\)\]{--tw-shadow:0 0 25px rgba(0,217,255,.3);--tw-shadow-colored:0 0 25px var(--tw-shadow-color)}.shadow-\[0_0_25px_rgba\(0\2c 217\2c 255\2c 0\.3\)\],.shadow-lg{box-shadow:var(--tw-ring-offset-shadow,0 0 #0000),var(--tw-ring-shadow,0 0 #0000),var(--tw-shadow)}.shadow-lg{--tw-shadow:0 10px 15px -3px rgba(0,0,0,.1),0 4px 6px -4px rgba(0,0,0,.1);--tw-shadow-colored:0 10px 15px -3px var(--tw-shadow-color),0 4px 6px -4px var(--tw-shadow-color)}.shadow-md{--tw-shadow:0 4px 6px -1px rgba(0,0,0,.1),0 2px 4px -2px rgba(0,0,0,.1);--tw-shadow-colored:0 4px 6px -1px var(--tw-shadow-color),0 2px 4px -2px var(--tw-shadow-color)}.shadow-md,.shadow-sm{box-shadow:var(--tw-ring-offset-shadow,0 0 #0000),var(--tw-ring-shadow,0 0 #0000),var(--tw-shadow)}.shadow-sm{--tw-shadow:0 1px 2px 0 rgba(0,0,0,.05);--tw-shadow-colored:0 1px 2px 0 var(--tw-shadow-color)}.shadow-xl{--tw-shadow:0 20px 25px -5px rgba(0,0,0,.1),0 8px 10px -6px rgba(0,0,0,.1);--tw-shadow-colored:0 20px 25px -5px var(--tw-shadow-color),0 8px 10px -6px var(--tw-shadow-color);box-shadow:var(--tw-ring-offset-shadow,0 0 #0000),var(--tw-ring-shadow,0 0 #0000),var(--tw-shadow)}.shadow-black\/40{--tw-shadow-color:rgba(0,0,0,.4);--tw-shadow:var(--tw-shadow-colored)}.shadow-cyan-500\/10{--tw-shadow-color:rgba(6,182,212,.1);--tw-shadow:var(--tw-shadow-colored)}.shadow-cyan-500\/20{--tw-shadow-color:rgba(6,182,212,.2);--tw-shadow:var(--tw-shadow-colored)}.shadow-cyan-500\/25{--tw-shadow-color:rgba(6,182,212,.25);--tw-shadow:var(--tw-shadow-colored)}.shadow-cyan-900\/10{--tw-shadow-color:rgba(22,78,99,.1);--tw-shadow:var(--tw-shadow-colored)}.backdrop-blur-2xl{--tw-backdrop-blur:blur(40px)}.backdrop-blur-2xl,.backdrop-blur-lg{-webkit-backdrop-filter:var(--tw-backdrop-blur) var(--tw-backdrop-brightness) var(--tw-backdrop-contrast) var(--tw-backdrop-grayscale) var(--tw-backdrop-hue-rotate) var(--tw-backdrop-invert) var(--tw-backdrop-opacity) var(--tw-backdrop-saturate) var(--tw-backdrop-sepia);backdrop-filter:var(--tw-backdrop-blur) var(--tw-backdrop-brightness) var(--tw-backdrop-contrast) var(--tw-backdrop-grayscale) var(--tw-backdrop-hue-rotate) var(--tw-backdrop-invert) var(--tw-backdrop-opacity) var(--tw-backdrop-saturate) var(--tw-backdrop-sepia)}.backdrop-blur-lg{--tw-backdrop-blur:blur(16px)}.backdrop-blur-md{--tw-backdrop-blur:blur(12px)}.backdrop-blur-md,.backdrop-blur-sm{-webkit-backdrop-filter:var(--tw-backdrop-blur) var(--tw-backdrop-brightness) var(--tw-backdrop-contrast) var(--tw-backdrop-grayscale) var(--tw-backdrop-hue-rotate) var(--tw-backdrop-invert) var(--tw-backdrop-opacity) var(--tw-backdrop-saturate) var(--tw-backdrop-sepia);backdrop-filter:var(--tw-backdrop-blur) var(--tw-backdrop-brightness) var(--tw-backdrop-contrast) var(--tw-backdrop-grayscale) var(--tw-backdrop-hue-rotate) var(--tw-backdrop-invert) var(--tw-backdrop-opacity) var(--tw-backdrop-saturate) var(--tw-backdrop-sepia)}.backdrop-blur-sm{--tw-backdrop-blur:blur(4px)}.backdrop-blur-xl{--tw-backdrop-blur:blur(24px);-webkit-backdrop-filter:var(--tw-backdrop-blur) var(--tw-backdrop-brightness) var(--tw-backdrop-contrast) var(--tw-backdrop-grayscale) var(--tw-backdrop-hue-rotate) var(--tw-backdrop-invert) var(--tw-backdrop-opacity) var(--tw-backdrop-saturate) var(--tw-backdrop-sepia);backdrop-filter:var(--tw-backdrop-blur) var(--tw-backdrop-brightness) var(--tw-backdrop-contrast) var(--tw-backdrop-grayscale) var(--tw-backdrop-hue-rotate) var(--tw-backdrop-invert) var(--tw-backdrop-opacity) var(--tw-backdrop-saturate) var(--tw-backdrop-sepia)}.transition{transition-property:color,background-color,border-color,text-decoration-color,fill,stroke,opacity,box-shadow,transform,filter,-webkit-backdrop-filter;transition-property:color,background-color,border-color,text-decoration-color,fill,stroke,opacity,box-shadow,transform,filter,backdrop-filter;transition-property:color,background-color,border-color,text-decoration-color,fill,stroke,opacity,box-shadow,transform,filter,backdrop-filter,-webkit-backdrop-filter;transition-timing-function:cubic-bezier(.4,0,.2,1);transition-duration:.15s}.transition-all{transition-property:all;transition-timing-function:cubic-bezier(.4,0,.2,1);transition-duration:.15s}.transition-opacity{transition-property:opacity;transition-timing-function:cubic-bezier(.4,0,.2,1);transition-duration:.15s}.transition-transform{transition-property:transform;transition-timing-function:cubic-bezier(.4,0,.2,1);transition-duration:.15s}.duration-200{transition-duration:.2s}.duration-300{transition-duration:.3s}.ease-in-out{transition-timing-function:cubic-bezier(.4,0,.2,1)}.selection\:bg-\[\#00D9FF\] ::-moz-selection{--tw-bg-opacity:1;background-color:rgb(0 217 255/var(--tw-bg-opacity,1))}.selection\:bg-\[\#00D9FF\] ::selection{--tw-bg-opacity:1;background-color:rgb(0 217 255/var(--tw-bg-opacity,1))}.selection\:text-\[\#020817\] ::-moz-selection{--tw-text-opacity:1;color:rgb(2 8 23/var(--tw-text-opacity,1))}.selection\:text-\[\#020817\] ::selection{--tw-text-opacity:1;color:rgb(2 8 23/var(--tw-text-opacity,1))}.selection\:text-\[\#061535\] ::-moz-selection{--tw-text-opacity:1;color:rgb(6 21 53/var(--tw-text-opacity,1))}.selection\:text-\[\#061535\] ::selection{--tw-text-opacity:1;color:rgb(6 21 53/var(--tw-text-opacity,1))}.selection\:bg-\[\#00D9FF\]::-moz-selection{--tw-bg-opacity:1;background-color:rgb(0 217 255/var(--tw-bg-opacity,1))}.selection\:bg-\[\#00D9FF\]::selection{--tw-bg-opacity:1;background-color:rgb(0 217 255/var(--tw-bg-opacity,1))}.selection\:text-\[\#020817\]::-moz-selection{--tw-text-opacity:1;color:rgb(2 8 23/var(--tw-text-opacity,1))}.selection\:text-\[\#020817\]::selection{--tw-text-opacity:1;color:rgb(2 8 23/var(--tw-text-opacity,1))}.selection\:text-\[\#061535\]::-moz-selection{--tw-text-opacity:1;color:rgb(6 21 53/var(--tw-text-opacity,1))}.selection\:text-\[\#061535\]::selection{--tw-text-opacity:1;color:rgb(6 21 53/var(--tw-text-opacity,1))}.file\:mr-4::file-selector-button{margin-right:1rem}.file\:rounded-xl::file-selector-button{border-radius:.75rem}.file\:border-0::file-selector-button{border-width:0}.file\:bg-\[\#007BFF\]::file-selector-button{--tw-bg-opacity:1;background-color:rgb(0 123 255/var(--tw-bg-opacity,1))}.file\:px-4::file-selector-button{padding-left:1rem;padding-right:1rem}.file\:py-2::file-selector-button{padding-top:.5rem;padding-bottom:.5rem}.file\:py-2\.5::file-selector-button{padding-top:.625rem;padding-bottom:.625rem}.file\:text-xs::file-selector-button{font-size:.75rem;line-height:1rem}.file\:font-semibold::file-selector-button{font-weight:600}.file\:text-white::file-selector-button{--tw-text-opacity:1;color:rgb(255 255 255/var(--tw-text-opacity,1))}.after\:absolute:after{content:var(--tw-content);position:absolute}.after\:left-\[2px\]:after{content:var(--tw-content);left:2px}.after\:top-\[2px\]:after{content:var(--tw-content);top:2px}.after\:h-5:after{content:var(--tw-content);height:1.25rem}.after\:w-5:after{content:var(--tw-content);width:1.25rem}.after\:rounded-full:after{content:var(--tw-content);border-radius:9999px}.after\:border:after{content:var(--tw-content);border-width:1px}.after\:border-gray-300:after{content:var(--tw-content);--tw-border-opacity:1;border-color:rgb(209 213 219/var(--tw-border-opacity,1))}.after\:bg-white:after{content:var(--tw-content);--tw-bg-opacity:1;background-color:rgb(255 255 255/var(--tw-bg-opacity,1))}.after\:transition-all:after{content:var(--tw-content);transition-property:all;transition-timing-function:cubic-bezier(.4,0,.2,1);transition-duration:.15s}.after\:content-\[\'\'\]:after{--tw-content:"";content:var(--tw-content)}.hover\:border-\[\#00D9FF\]:hover{--tw-border-opacity:1;border-color:rgb(0 217 255/var(--tw-border-opacity,1))}.hover\:border-\[\#00D9FF\]\/40:hover{border-color:rgba(0,217,255,.4)}.hover\:border-\[\#0284C7\]:hover{--tw-border-opacity:1;border-color:rgb(2 132 199/var(--tw-border-opacity,1))}.hover\:border-\[\#7C3AED\]:hover{--tw-border-opacity:1;border-color:rgb(124 58 237/var(--tw-border-opacity,1))}.hover\:border-\[\#E2136E\]:hover{--tw-border-opacity:1;border-color:rgb(226 19 110/var(--tw-border-opacity,1))}.hover\:border-\[\#EA580C\]:hover{--tw-border-opacity:1;border-color:rgb(234 88 12/var(--tw-border-opacity,1))}.hover\:border-amber-400:hover{--tw-border-opacity:1;border-color:rgb(251 191 36/var(--tw-border-opacity,1))}.hover\:bg-\[\#0056b3\]:hover{--tw-bg-opacity:1;background-color:rgb(0 86 179/var(--tw-bg-opacity,1))}.hover\:bg-\[\#007BFF\]:hover{--tw-bg-opacity:1;background-color:rgb(0 123 255/var(--tw-bg-opacity,1))}.hover\:bg-\[\#00D9FF\]\/20:hover{background-color:rgba(0,217,255,.2)}.hover\:bg-\[\#051433\]\/60:hover{background-color:rgba(5,20,51,.6)}.hover\:bg-\[\#081e4b\]:hover{--tw-bg-opacity:1;background-color:rgb(8 30 75/var(--tw-bg-opacity,1))}.hover\:bg-\[\#092257\]:hover{--tw-bg-opacity:1;background-color:rgb(9 34 87/var(--tw-bg-opacity,1))}.hover\:bg-\[\#0c245c\]:hover{--tw-bg-opacity:1;background-color:rgb(12 36 92/var(--tw-bg-opacity,1))}.hover\:bg-\[\#0c2e75\]:hover{--tw-bg-opacity:1;background-color:rgb(12 46 117/var(--tw-bg-opacity,1))}.hover\:bg-\[\#0f2d6e\]:hover{--tw-bg-opacity:1;background-color:rgb(15 45 110/var(--tw-bg-opacity,1))}.hover\:bg-rose-500\/30:hover{background-color:rgba(244,63,94,.3)}.hover\:bg-slate-800:hover{--tw-bg-opacity:1;background-color:rgb(30 41 59/var(--tw-bg-opacity,1))}.hover\:text-\[\#00D89A\]:hover{--tw-text-opacity:1;color:rgb(0 216 154/var(--tw-text-opacity,1))}.hover\:text-\[\#00D9FF\]:hover{--tw-text-opacity:1;color:rgb(0 217 255/var(--tw-text-opacity,1))}.hover\:text-rose-300:hover{--tw-text-opacity:1;color:rgb(253 164 175/var(--tw-text-opacity,1))}.hover\:text-rose-400:hover{--tw-text-opacity:1;color:rgb(251 113 133/var(--tw-text-opacity,1))}.hover\:text-white:hover{--tw-text-opacity:1;color:rgb(255 255 255/var(--tw-text-opacity,1))}.hover\:underline:hover{text-decoration-line:underline}.hover\:file\:bg-\[\#0056b3\]::file-selector-button:hover{--tw-bg-opacity:1;background-color:rgb(0 86 179/var(--tw-bg-opacity,1))}.focus\:border-\[\#00D89A\]:focus{--tw-border-opacity:1;border-color:rgb(0 216 154/var(--tw-border-opacity,1))}.focus\:border-\[\#00D9FF\]:focus{--tw-border-opacity:1;border-color:rgb(0 217 255/var(--tw-border-opacity,1))}.focus\:border-amber-400:focus{--tw-border-opacity:1;border-color:rgb(251 191 36/var(--tw-border-opacity,1))}.focus\:outline-none:focus{outline:2px solid transparent;outline-offset:2px}.active\:scale-95:active{--tw-scale-x:.95;--tw-scale-y:.95;transform:translate(var(--tw-translate-x),var(--tw-translate-y)) rotate(var(--tw-rotate)) skewX(var(--tw-skew-x)) skewY(var(--tw-skew-y)) scaleX(var(--tw-scale-x)) scaleY(var(--tw-scale-y))}.group:hover .group-hover\:text-\[\#00D89A\]{--tw-text-opacity:1;color:rgb(0 216 154/var(--tw-text-opacity,1))}.group:hover .group-hover\:text-\[\#00D9FF\]{--tw-text-opacity:1;color:rgb(0 217 255/var(--tw-text-opacity,1))}.group:hover .group-hover\:text-amber-400{--tw-text-opacity:1;color:rgb(251 191 36/var(--tw-text-opacity,1))}.peer:checked~.peer-checked\:bg-\[\#00D89A\]{--tw-bg-opacity:1;background-color:rgb(0 216 154/var(--tw-bg-opacity,1))}.peer:checked~.peer-checked\:after\:translate-x-full:after{content:var(--tw-content);--tw-translate-x:100%;transform:translate(var(--tw-translate-x),var(--tw-translate-y)) rotate(var(--tw-rotate)) skewX(var(--tw-skew-x)) skewY(var(--tw-skew-y)) scaleX(var(--tw-scale-x)) scaleY(var(--tw-scale-y))}.peer:checked~.peer-checked\:after\:border-white:after{content:var(--tw-content);--tw-border-opacity:1;border-color:rgb(255 255 255/var(--tw-border-opacity,1))}.peer:focus~.peer-focus\:outline-none{outline:2px solid transparent;outline-offset:2px}@media (min-width:640px){.sm\:w-auto{width:auto}.sm\:grid-cols-2{grid-template-columns:repeat(2,minmax(0,1fr))}.sm\:grid-cols-4{grid-template-columns:repeat(4,minmax(0,1fr))}.sm\:grid-cols-5{grid-template-columns:repeat(5,minmax(0,1fr))}.sm\:flex-row{flex-direction:row}.sm\:items-center{align-items:center}.sm\:justify-end{justify-content:flex-end}.sm\:border-t-0{border-top-width:0}.sm\:p-8{padding:2rem}.sm\:px-4{padding-left:1rem;padding-right:1rem}.sm\:pt-0{padding-top:0}.sm\:text-right{text-align:right}.sm\:text-sm{font-size:.875rem;line-height:1.25rem}}@media (min-width:768px){.md\:mx-0{margin-left:0;margin-right:0}.md\:ml-72{margin-left:18rem}.md\:flex{display:flex}.md\:hidden{display:none}.md\:w-1\/2{width:50%}.md\:w-auto{width:auto}.md\:flex-none{flex:none}.md\:translate-x-0{--tw-translate-x:0px;transform:translate(var(--tw-translate-x),var(--tw-translate-y)) rotate(var(--tw-rotate)) skewX(var(--tw-skew-x)) skewY(var(--tw-skew-y)) scaleX(var(--tw-scale-x)) scaleY(var(--tw-scale-y))}.md\:grid-cols-2{grid-template-columns:repeat(2,minmax(0,1fr))}.md\:grid-cols-3{grid-template-columns:repeat(3,minmax(0,1fr))}.md\:grid-cols-4{grid-template-columns:repeat(4,minmax(0,1fr))}.md\:flex-row{flex-direction:row}.md\:items-center{align-items:center}.md\:justify-start{justify-content:flex-start}.md\:gap-4{gap:1rem}.md\:p-10{padding:2.5rem}.md\:p-12{padding:3rem}.md\:p-5{padding:1.25rem}.md\:p-8{padding:2rem}.md\:px-10{padding-left:2.5rem;padding-right:2.5rem}.md\:px-8{padding-left:2rem;padding-right:2rem}.md\:py-16{padding-top:4rem;padding-bottom:4rem}.md\:text-left{text-align:left}.md\:text-2xl{font-size:1.5rem;line-height:2rem}.md\:text-3xl{font-size:1.875rem;line-height:2.25rem}.md\:text-5xl{font-size:3rem;line-height:1}.md\:text-\[11px\]{font-size:11px}.md\:text-base{font-size:1rem;line-height:1.5rem}.md\:text-lg{font-size:1.125rem;line-height:1.75rem}.md\:text-sm{font-size:.875rem;line-height:1.25rem}}</style>
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
    unset($_POST['action_create_key'], $_POST['action_add_balance'], $_POST['action_update_payments'], $_POST['action_update_site'], $_GET['delete_key']);
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
$currentNotice = is_file(__DIR__ . '/notice.txt') ? trim((string) file_get_contents(__DIR__ . '/notice.txt')) : '';

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
    <style>*,:after,:before{--tw-border-spacing-x:0;--tw-border-spacing-y:0;--tw-translate-x:0;--tw-translate-y:0;--tw-rotate:0;--tw-skew-x:0;--tw-skew-y:0;--tw-scale-x:1;--tw-scale-y:1;--tw-pan-x: ;--tw-pan-y: ;--tw-pinch-zoom: ;--tw-scroll-snap-strictness:proximity;--tw-gradient-from-position: ;--tw-gradient-via-position: ;--tw-gradient-to-position: ;--tw-ordinal: ;--tw-slashed-zero: ;--tw-numeric-figure: ;--tw-numeric-spacing: ;--tw-numeric-fraction: ;--tw-ring-inset: ;--tw-ring-offset-width:0px;--tw-ring-offset-color:#fff;--tw-ring-color:rgba(59,130,246,.5);--tw-ring-offset-shadow:0 0 #0000;--tw-ring-shadow:0 0 #0000;--tw-shadow:0 0 #0000;--tw-shadow-colored:0 0 #0000;--tw-blur: ;--tw-brightness: ;--tw-contrast: ;--tw-grayscale: ;--tw-hue-rotate: ;--tw-invert: ;--tw-saturate: ;--tw-sepia: ;--tw-drop-shadow: ;--tw-backdrop-blur: ;--tw-backdrop-brightness: ;--tw-backdrop-contrast: ;--tw-backdrop-grayscale: ;--tw-backdrop-hue-rotate: ;--tw-backdrop-invert: ;--tw-backdrop-opacity: ;--tw-backdrop-saturate: ;--tw-backdrop-sepia: ;--tw-contain-size: ;--tw-contain-layout: ;--tw-contain-paint: ;--tw-contain-style: }::backdrop{--tw-border-spacing-x:0;--tw-border-spacing-y:0;--tw-translate-x:0;--tw-translate-y:0;--tw-rotate:0;--tw-skew-x:0;--tw-skew-y:0;--tw-scale-x:1;--tw-scale-y:1;--tw-pan-x: ;--tw-pan-y: ;--tw-pinch-zoom: ;--tw-scroll-snap-strictness:proximity;--tw-gradient-from-position: ;--tw-gradient-via-position: ;--tw-gradient-to-position: ;--tw-ordinal: ;--tw-slashed-zero: ;--tw-numeric-figure: ;--tw-numeric-spacing: ;--tw-numeric-fraction: ;--tw-ring-inset: ;--tw-ring-offset-width:0px;--tw-ring-offset-color:#fff;--tw-ring-color:rgba(59,130,246,.5);--tw-ring-offset-shadow:0 0 #0000;--tw-ring-shadow:0 0 #0000;--tw-shadow:0 0 #0000;--tw-shadow-colored:0 0 #0000;--tw-blur: ;--tw-brightness: ;--tw-contrast: ;--tw-grayscale: ;--tw-hue-rotate: ;--tw-invert: ;--tw-saturate: ;--tw-sepia: ;--tw-drop-shadow: ;--tw-backdrop-blur: ;--tw-backdrop-brightness: ;--tw-backdrop-contrast: ;--tw-backdrop-grayscale: ;--tw-backdrop-hue-rotate: ;--tw-backdrop-invert: ;--tw-backdrop-opacity: ;--tw-backdrop-saturate: ;--tw-backdrop-sepia: ;--tw-contain-size: ;--tw-contain-layout: ;--tw-contain-paint: ;--tw-contain-style: }/*! tailwindcss v3.4.17 | MIT License | https://tailwindcss.com*/*,:after,:before{box-sizing:border-box;border:0 solid #e5e7eb}:after,:before{--tw-content:""}:host,html{line-height:1.5;-webkit-text-size-adjust:100%;-moz-tab-size:4;-o-tab-size:4;tab-size:4;font-family:ui-sans-serif,system-ui,sans-serif,Apple Color Emoji,Segoe UI Emoji,Segoe UI Symbol,Noto Color Emoji;font-feature-settings:normal;font-variation-settings:normal;-webkit-tap-highlight-color:transparent}body{margin:0;line-height:inherit}hr{height:0;color:inherit;border-top-width:1px}abbr:where([title]){-webkit-text-decoration:underline dotted;text-decoration:underline dotted}h1,h2,h3,h4,h5,h6{font-size:inherit;font-weight:inherit}a{color:inherit;text-decoration:inherit}b,strong{font-weight:bolder}code,kbd,pre,samp{font-family:ui-monospace,SFMono-Regular,Menlo,Monaco,Consolas,Liberation Mono,Courier New,monospace;font-feature-settings:normal;font-variation-settings:normal;font-size:1em}small{font-size:80%}sub,sup{font-size:75%;line-height:0;position:relative;vertical-align:baseline}sub{bottom:-.25em}sup{top:-.5em}table{text-indent:0;border-color:inherit;border-collapse:collapse}button,input,optgroup,select,textarea{font-family:inherit;font-feature-settings:inherit;font-variation-settings:inherit;font-size:100%;font-weight:inherit;line-height:inherit;letter-spacing:inherit;color:inherit;margin:0;padding:0}button,select{text-transform:none}button,input:where([type=button]),input:where([type=reset]),input:where([type=submit]){-webkit-appearance:button;background-color:transparent;background-image:none}:-moz-focusring{outline:auto}:-moz-ui-invalid{box-shadow:none}progress{vertical-align:baseline}::-webkit-inner-spin-button,::-webkit-outer-spin-button{height:auto}[type=search]{-webkit-appearance:textfield;outline-offset:-2px}::-webkit-search-decoration{-webkit-appearance:none}::-webkit-file-upload-button{-webkit-appearance:button;font:inherit}summary{display:list-item}blockquote,dd,dl,figure,h1,h2,h3,h4,h5,h6,hr,p,pre{margin:0}fieldset{margin:0}fieldset,legend{padding:0}menu,ol,ul{list-style:none;margin:0;padding:0}dialog{padding:0}textarea{resize:vertical}input::-moz-placeholder,textarea::-moz-placeholder{opacity:1;color:#9ca3af}input::placeholder,textarea::placeholder{opacity:1;color:#9ca3af}[role=button],button{cursor:pointer}:disabled{cursor:default}audio,canvas,embed,iframe,img,object,svg,video{display:block;vertical-align:middle}img,video{max-width:100%;height:auto}[hidden]:where(:not([hidden=until-found])){display:none}.\!container{width:100%!important}.container{width:100%}@media (min-width:640px){.\!container{max-width:640px!important}.container{max-width:640px}}@media (min-width:768px){.\!container{max-width:768px!important}.container{max-width:768px}}@media (min-width:1024px){.\!container{max-width:1024px!important}.container{max-width:1024px}}@media (min-width:1280px){.\!container{max-width:1280px!important}.container{max-width:1280px}}@media (min-width:1536px){.\!container{max-width:1536px!important}.container{max-width:1536px}}.sr-only{position:absolute;width:1px;height:1px;padding:0;margin:-1px;overflow:hidden;clip:rect(0,0,0,0);white-space:nowrap;border-width:0}.pointer-events-none{pointer-events:none}.static{position:static}.fixed{position:fixed}.absolute{position:absolute}.relative{position:relative}.sticky{position:sticky}.inset-0{inset:0}.bottom-0{bottom:0}.left-0{left:0}.left-1\/2{left:50%}.left-4{left:1rem}.right-0{right:0}.right-4{right:1rem}.right-5{right:1.25rem}.right-6{right:1.5rem}.top-0{top:0}.top-3\.5{top:.875rem}.top-5{top:1.25rem}.top-\[65px\]{top:65px}.z-30{z-index:30}.z-40{z-index:40}.z-50{z-index:50}.z-\[60\]{z-index:60}.z-\[70\]{z-index:70}.mx-auto{margin-left:auto;margin-right:auto}.my-3{margin-top:.75rem;margin-bottom:.75rem}.my-4{margin-top:1rem;margin-bottom:1rem}.-mt-0\.5{margin-top:-.125rem}.mb-1\.5{margin-bottom:.375rem}.mb-10{margin-bottom:2.5rem}.mb-2{margin-bottom:.5rem}.mb-3{margin-bottom:.75rem}.mb-4{margin-bottom:1rem}.mb-5{margin-bottom:1.25rem}.mb-6{margin-bottom:1.5rem}.mt-0\.5{margin-top:.125rem}.mt-1{margin-top:.25rem}.mt-2{margin-top:.5rem}.mt-3{margin-top:.75rem}.mt-4{margin-top:1rem}.mt-5{margin-top:1.25rem}.mt-6{margin-top:1.5rem}.block{display:block}.flex{display:flex}.inline-flex{display:inline-flex}.table{display:table}.grid{display:grid}.hidden{display:none}.h-10{height:2.5rem}.h-11{height:2.75rem}.h-12{height:3rem}.h-14{height:3.5rem}.h-16{height:4rem}.h-2\.5{height:.625rem}.h-20{height:5rem}.h-36{height:9rem}.h-48{height:12rem}.h-6{height:1.5rem}.h-8{height:2rem}.h-9{height:2.25rem}.h-full{height:100%}.min-h-screen{min-height:100vh}.w-1\/2{width:50%}.w-10{width:2.5rem}.w-11{width:2.75rem}.w-12{width:3rem}.w-14{width:3.5rem}.w-16{width:4rem}.w-20{width:5rem}.w-4{width:1rem}.w-48{width:12rem}.w-5{width:1.25rem}.w-6{width:1.5rem}.w-72{width:18rem}.w-8{width:2rem}.w-9{width:2.25rem}.w-\[90\%\]{width:90%}.w-full{width:100%}.min-w-0{min-width:0}.max-w-2xl{max-width:42rem}.max-w-4xl{max-width:56rem}.max-w-6xl{max-width:72rem}.max-w-lg{max-width:32rem}.max-w-md{max-width:28rem}.max-w-sm{max-width:24rem}.max-w-xl{max-width:36rem}.max-w-xs{max-width:20rem}.flex-1{flex:1 1 0%}.shrink-0{flex-shrink:0}.-translate-x-1\/2{--tw-translate-x:-50%}.-translate-x-1\/2,.-translate-x-full{transform:translate(var(--tw-translate-x),var(--tw-translate-y)) rotate(var(--tw-rotate)) skewX(var(--tw-skew-x)) skewY(var(--tw-skew-y)) scaleX(var(--tw-scale-x)) scaleY(var(--tw-scale-y))}.-translate-x-full{--tw-translate-x:-100%}.-translate-y-8{--tw-translate-y:-2rem}.-translate-y-8,.translate-x-full{transform:translate(var(--tw-translate-x),var(--tw-translate-y)) rotate(var(--tw-rotate)) skewX(var(--tw-skew-x)) skewY(var(--tw-skew-y)) scaleX(var(--tw-scale-x)) scaleY(var(--tw-scale-y))}.translate-x-full{--tw-translate-x:100%}.translate-y-0{--tw-translate-y:0px}.transform,.translate-y-0{transform:translate(var(--tw-translate-x),var(--tw-translate-y)) rotate(var(--tw-rotate)) skewX(var(--tw-skew-x)) skewY(var(--tw-skew-y)) scaleX(var(--tw-scale-x)) scaleY(var(--tw-scale-y))}@keyframes bounce{0%,to{transform:translateY(-25%);animation-timing-function:cubic-bezier(.8,0,1,1)}50%{transform:none;animation-timing-function:cubic-bezier(0,0,.2,1)}}.animate-bounce{animation:bounce 1s infinite}@keyframes pulse{50%{opacity:.5}}.animate-pulse{animation:pulse 2s cubic-bezier(.4,0,.6,1) infinite}.cursor-not-allowed{cursor:not-allowed}.cursor-pointer{cursor:pointer}.select-all{-webkit-user-select:all;-moz-user-select:all;user-select:all}.grid-cols-1{grid-template-columns:repeat(1,minmax(0,1fr))}.grid-cols-2{grid-template-columns:repeat(2,minmax(0,1fr))}.grid-cols-3{grid-template-columns:repeat(3,minmax(0,1fr))}.flex-col{flex-direction:column}.flex-wrap{flex-wrap:wrap}.items-start{align-items:flex-start}.items-center{align-items:center}.items-stretch{align-items:stretch}.justify-center{justify-content:center}.justify-between{justify-content:space-between}.gap-1{gap:.25rem}.gap-1\.5{gap:.375rem}.gap-10{gap:2.5rem}.gap-2{gap:.5rem}.gap-2\.5{gap:.625rem}.gap-3{gap:.75rem}.gap-3\.5{gap:.875rem}.gap-4{gap:1rem}.gap-5{gap:1.25rem}.gap-6{gap:1.5rem}.gap-8{gap:2rem}.space-y-1>:not([hidden])~:not([hidden]){--tw-space-y-reverse:0;margin-top:calc(.25rem*(1 - var(--tw-space-y-reverse)));margin-bottom:calc(.25rem*var(--tw-space-y-reverse))}.space-y-1\.5>:not([hidden])~:not([hidden]){--tw-space-y-reverse:0;margin-top:calc(.375rem*(1 - var(--tw-space-y-reverse)));margin-bottom:calc(.375rem*var(--tw-space-y-reverse))}.space-y-2>:not([hidden])~:not([hidden]){--tw-space-y-reverse:0;margin-top:calc(.5rem*(1 - var(--tw-space-y-reverse)));margin-bottom:calc(.5rem*var(--tw-space-y-reverse))}.space-y-2\.5>:not([hidden])~:not([hidden]){--tw-space-y-reverse:0;margin-top:calc(.625rem*(1 - var(--tw-space-y-reverse)));margin-bottom:calc(.625rem*var(--tw-space-y-reverse))}.space-y-3>:not([hidden])~:not([hidden]){--tw-space-y-reverse:0;margin-top:calc(.75rem*(1 - var(--tw-space-y-reverse)));margin-bottom:calc(.75rem*var(--tw-space-y-reverse))}.space-y-3\.5>:not([hidden])~:not([hidden]){--tw-space-y-reverse:0;margin-top:calc(.875rem*(1 - var(--tw-space-y-reverse)));margin-bottom:calc(.875rem*var(--tw-space-y-reverse))}.space-y-4>:not([hidden])~:not([hidden]){--tw-space-y-reverse:0;margin-top:calc(1rem*(1 - var(--tw-space-y-reverse)));margin-bottom:calc(1rem*var(--tw-space-y-reverse))}.space-y-5>:not([hidden])~:not([hidden]){--tw-space-y-reverse:0;margin-top:calc(1.25rem*(1 - var(--tw-space-y-reverse)));margin-bottom:calc(1.25rem*var(--tw-space-y-reverse))}.space-y-6>:not([hidden])~:not([hidden]){--tw-space-y-reverse:0;margin-top:calc(1.5rem*(1 - var(--tw-space-y-reverse)));margin-bottom:calc(1.5rem*var(--tw-space-y-reverse))}.divide-y>:not([hidden])~:not([hidden]){--tw-divide-y-reverse:0;border-top-width:calc(1px*(1 - var(--tw-divide-y-reverse)));border-bottom-width:calc(1px*var(--tw-divide-y-reverse))}.divide-\[\#0d265c\]>:not([hidden])~:not([hidden]){--tw-divide-opacity:1;border-color:rgb(13 38 92/var(--tw-divide-opacity,1))}.divide-\[\#0e275c\]>:not([hidden])~:not([hidden]){--tw-divide-opacity:1;border-color:rgb(14 39 92/var(--tw-divide-opacity,1))}.overflow-hidden{overflow:hidden}.overflow-x-auto{overflow-x:auto}.overflow-x-hidden{overflow-x:hidden}.truncate{overflow:hidden;text-overflow:ellipsis}.truncate,.whitespace-nowrap{white-space:nowrap}.break-all{word-break:break-all}.rounded-2xl{border-radius:1rem}.rounded-3xl{border-radius:1.5rem}.rounded-full{border-radius:9999px}.rounded-lg{border-radius:.5rem}.rounded-xl{border-radius:.75rem}.border{border-width:1px}.border-2{border-width:2px}.border-b{border-bottom-width:1px}.border-l{border-left-width:1px}.border-r{border-right-width:1px}.border-t{border-top-width:1px}.border-dashed{border-style:dashed}.border-\[\#007BFF\]\/30{border-color:rgba(0,123,255,.3)}.border-\[\#007BFF\]\/40{border-color:rgba(0,123,255,.4)}.border-\[\#007BFF\]\/50{border-color:rgba(0,123,255,.5)}.border-\[\#00D89A\]\/20{border-color:rgba(0,216,154,.2)}.border-\[\#00D89A\]\/30{border-color:rgba(0,216,154,.3)}.border-\[\#00D89A\]\/40{border-color:rgba(0,216,154,.4)}.border-\[\#00D89A\]\/50{border-color:rgba(0,216,154,.5)}.border-\[\#00D9FF\]{--tw-border-opacity:1;border-color:rgb(0 217 255/var(--tw-border-opacity,1))}.border-\[\#00D9FF\]\/20{border-color:rgba(0,217,255,.2)}.border-\[\#00D9FF\]\/30{border-color:rgba(0,217,255,.3)}.border-\[\#00D9FF\]\/40{border-color:rgba(0,217,255,.4)}.border-\[\#00D9FF\]\/50{border-color:rgba(0,217,255,.5)}.border-\[\#0d265c\]{--tw-border-opacity:1;border-color:rgb(13 38 92/var(--tw-border-opacity,1))}.border-\[\#0d2a63\]{--tw-border-opacity:1;border-color:rgb(13 42 99/var(--tw-border-opacity,1))}.border-\[\#0e275c\]{--tw-border-opacity:1;border-color:rgb(14 39 92/var(--tw-border-opacity,1))}.border-\[\#0e306e\]{--tw-border-opacity:1;border-color:rgb(14 48 110/var(--tw-border-opacity,1))}.border-\[\#10326f\]{--tw-border-opacity:1;border-color:rgb(16 50 111/var(--tw-border-opacity,1))}.border-\[\#123982\]{--tw-border-opacity:1;border-color:rgb(18 57 130/var(--tw-border-opacity,1))}.border-\[\#143985\]{--tw-border-opacity:1;border-color:rgb(20 57 133/var(--tw-border-opacity,1))}.border-\[\#16418f\]{--tw-border-opacity:1;border-color:rgb(22 65 143/var(--tw-border-opacity,1))}.border-\[\#174396\]{--tw-border-opacity:1;border-color:rgb(23 67 150/var(--tw-border-opacity,1))}.border-amber-500\/30{border-color:rgba(245,158,11,.3)}.border-amber-500\/40{border-color:rgba(245,158,11,.4)}.border-rose-500\/40{border-color:rgba(244,63,94,.4)}.border-slate-700{--tw-border-opacity:1;border-color:rgb(51 65 85/var(--tw-border-opacity,1))}.bg-\[\#007BFF\]{--tw-bg-opacity:1;background-color:rgb(0 123 255/var(--tw-bg-opacity,1))}.bg-\[\#007BFF\]\/20{background-color:rgba(0,123,255,.2)}.bg-\[\#00D89A\]\/10{background-color:rgba(0,216,154,.1)}.bg-\[\#00D89A\]\/15{background-color:rgba(0,216,154,.15)}.bg-\[\#00D89A\]\/20{background-color:rgba(0,216,154,.2)}.bg-\[\#00D9FF\]\/10{background-color:rgba(0,217,255,.1)}.bg-\[\#00D9FF\]\/15{background-color:rgba(0,217,255,.15)}.bg-\[\#00D9FF\]\/20{background-color:rgba(0,217,255,.2)}.bg-\[\#020817\]{--tw-bg-opacity:1;background-color:rgb(2 8 23/var(--tw-bg-opacity,1))}.bg-\[\#020817\]\/80{background-color:rgba(2,8,23,.8)}.bg-\[\#020817\]\/85{background-color:rgba(2,8,23,.85)}.bg-\[\#030d24\]\/95{background-color:rgba(3,13,36,.95)}.bg-\[\#03132e\]{--tw-bg-opacity:1;background-color:rgb(3 19 46/var(--tw-bg-opacity,1))}.bg-\[\#040e24\]{--tw-bg-opacity:1;background-color:rgb(4 14 36/var(--tw-bg-opacity,1))}.bg-\[\#04122d\]{--tw-bg-opacity:1;background-color:rgb(4 18 45/var(--tw-bg-opacity,1))}.bg-\[\#05112b\]\/95{background-color:rgba(5,17,43,.95)}.bg-\[\#051433\]{--tw-bg-opacity:1;background-color:rgb(5 20 51/var(--tw-bg-opacity,1))}.bg-\[\#051433\]\/90{background-color:rgba(5,20,51,.9)}.bg-\[\#051433\]\/95{background-color:rgba(5,20,51,.95)}.bg-\[\#061535\]{--tw-bg-opacity:1;background-color:rgb(6 21 53/var(--tw-bg-opacity,1))}.bg-\[\#061535\]\/80{background-color:rgba(6,21,53,.8)}.bg-\[\#061535\]\/85{background-color:rgba(6,21,53,.85)}.bg-\[\#061535\]\/90{background-color:rgba(6,21,53,.9)}.bg-\[\#071638\]{--tw-bg-opacity:1;background-color:rgb(7 22 56/var(--tw-bg-opacity,1))}.bg-\[\#081b42\]\/95{background-color:rgba(8,27,66,.95)}.bg-\[\#081e4b\]{--tw-bg-opacity:1;background-color:rgb(8 30 75/var(--tw-bg-opacity,1))}.bg-\[\#09204e\]{--tw-bg-opacity:1;background-color:rgb(9 32 78/var(--tw-bg-opacity,1))}.bg-\[\#092257\]{--tw-bg-opacity:1;background-color:rgb(9 34 87/var(--tw-bg-opacity,1))}.bg-\[\#0a1e4a\]{--tw-bg-opacity:1;background-color:rgb(10 30 74/var(--tw-bg-opacity,1))}.bg-\[\#0a2356\]{--tw-bg-opacity:1;background-color:rgb(10 35 86/var(--tw-bg-opacity,1))}.bg-\[\#0c2454\]{--tw-bg-opacity:1;background-color:rgb(12 36 84/var(--tw-bg-opacity,1))}.bg-\[\#35F58A\]\/15{background-color:rgba(53,245,138,.15)}.bg-\[\#35F58A\]\/20{background-color:rgba(53,245,138,.2)}.bg-\[\#8B5CF6\]\/20{background-color:rgba(139,92,246,.2)}.bg-amber-500\/10{background-color:rgba(245,158,11,.1)}.bg-amber-500\/15{background-color:rgba(245,158,11,.15)}.bg-amber-500\/20{background-color:rgba(245,158,11,.2)}.bg-black\/80{background-color:rgba(0,0,0,.8)}.bg-rose-500\/20{background-color:rgba(244,63,94,.2)}.bg-slate-800{--tw-bg-opacity:1;background-color:rgb(30 41 59/var(--tw-bg-opacity,1))}.bg-transparent{background-color:transparent}.bg-white{--tw-bg-opacity:1;background-color:rgb(255 255 255/var(--tw-bg-opacity,1))}.bg-gradient-to-br{background-image:linear-gradient(to bottom right,var(--tw-gradient-stops))}.from-\[\#081d49\]{--tw-gradient-from:#081d49 var(--tw-gradient-from-position);--tw-gradient-to:rgba(8,29,73,0) var(--tw-gradient-to-position);--tw-gradient-stops:var(--tw-gradient-from),var(--tw-gradient-to)}.via-\[\#092257\]{--tw-gradient-to:rgba(9,34,87,0) var(--tw-gradient-to-position);--tw-gradient-stops:var(--tw-gradient-from),#092257 var(--tw-gradient-via-position),var(--tw-gradient-to)}.to-\[\#04112c\]{--tw-gradient-to:#04112c var(--tw-gradient-to-position)}.object-contain{-o-object-fit:contain;object-fit:contain}.p-1{padding:.25rem}.p-1\.5{padding:.375rem}.p-2{padding:.5rem}.p-3{padding:.75rem}.p-3\.5{padding:.875rem}.p-4{padding:1rem}.p-5{padding:1.25rem}.p-6{padding:1.5rem}.p-8{padding:2rem}.px-2{padding-left:.5rem;padding-right:.5rem}.px-2\.5{padding-left:.625rem;padding-right:.625rem}.px-3{padding-left:.75rem;padding-right:.75rem}.px-3\.5{padding-left:.875rem;padding-right:.875rem}.px-4{padding-left:1rem;padding-right:1rem}.px-5{padding-left:1.25rem;padding-right:1.25rem}.px-7{padding-left:1.75rem;padding-right:1.75rem}.py-0\.5{padding-top:.125rem;padding-bottom:.125rem}.py-1{padding-top:.25rem;padding-bottom:.25rem}.py-1\.5{padding-top:.375rem;padding-bottom:.375rem}.py-10{padding-top:2.5rem;padding-bottom:2.5rem}.py-12{padding-top:3rem;padding-bottom:3rem}.py-2{padding-top:.5rem;padding-bottom:.5rem}.py-2\.5{padding-top:.625rem;padding-bottom:.625rem}.py-3{padding-top:.75rem;padding-bottom:.75rem}.py-3\.5{padding-top:.875rem;padding-bottom:.875rem}.py-8{padding-top:2rem;padding-bottom:2rem}.pb-3{padding-bottom:.75rem}.pb-4{padding-bottom:1rem}.pb-5{padding-bottom:1.25rem}.pl-11{padding-left:2.75rem}.pr-11{padding-right:2.75rem}.pr-2{padding-right:.5rem}.pr-4{padding-right:1rem}.pt-0\.5{padding-top:.125rem}.pt-2{padding-top:.5rem}.pt-2\.5{padding-top:.625rem}.pt-3{padding-top:.75rem}.pt-6{padding-top:1.5rem}.text-left{text-align:left}.text-center{text-align:center}.text-right{text-align:right}.font-mono{font-family:ui-monospace,SFMono-Regular,Menlo,Monaco,Consolas,Liberation Mono,Courier New,monospace}.font-sans{font-family:ui-sans-serif,system-ui,sans-serif,Apple Color Emoji,Segoe UI Emoji,Segoe UI Symbol,Noto Color Emoji}.text-2xl{font-size:1.5rem;line-height:2rem}.text-3xl{font-size:1.875rem;line-height:2.25rem}.text-4xl{font-size:2.25rem;line-height:2.5rem}.text-\[10px\]{font-size:10px}.text-\[11px\]{font-size:11px}.text-\[6px\]{font-size:6px}.text-\[9px\]{font-size:9px}.text-base{font-size:1rem;line-height:1.5rem}.text-lg{font-size:1.125rem;line-height:1.75rem}.text-sm{font-size:.875rem;line-height:1.25rem}.text-xl{font-size:1.25rem;line-height:1.75rem}.text-xs{font-size:.75rem;line-height:1rem}.font-black{font-weight:900}.font-bold{font-weight:700}.font-medium{font-weight:500}.font-semibold{font-weight:600}.uppercase{text-transform:uppercase}.leading-relaxed{line-height:1.625}.leading-tight{line-height:1.25}.tracking-wide{letter-spacing:.025em}.tracking-wider{letter-spacing:.05em}.tracking-widest{letter-spacing:.1em}.text-\[\#007BFF\]{--tw-text-opacity:1;color:rgb(0 123 255/var(--tw-text-opacity,1))}.text-\[\#00D89A\]{--tw-text-opacity:1;color:rgb(0 216 154/var(--tw-text-opacity,1))}.text-\[\#00D89A\]\/20{color:rgba(0,216,154,.2)}.text-\[\#00D9FF\]{--tw-text-opacity:1;color:rgb(0 217 255/var(--tw-text-opacity,1))}.text-\[\#00D9FF\]\/20{color:rgba(0,217,255,.2)}.text-\[\#020817\]{--tw-text-opacity:1;color:rgb(2 8 23/var(--tw-text-opacity,1))}.text-\[\#0284C7\]{--tw-text-opacity:1;color:rgb(2 132 199/var(--tw-text-opacity,1))}.text-\[\#061535\]{--tw-text-opacity:1;color:rgb(6 21 53/var(--tw-text-opacity,1))}.text-\[\#35F58A\]{--tw-text-opacity:1;color:rgb(53 245 138/var(--tw-text-opacity,1))}.text-\[\#35F58A\]\/20{color:rgba(53,245,138,.2)}.text-\[\#38BDF8\]{--tw-text-opacity:1;color:rgb(56 189 248/var(--tw-text-opacity,1))}.text-\[\#7C3AED\]{--tw-text-opacity:1;color:rgb(124 58 237/var(--tw-text-opacity,1))}.text-\[\#8B5CF6\]{--tw-text-opacity:1;color:rgb(139 92 246/var(--tw-text-opacity,1))}.text-\[\#E2136E\]{--tw-text-opacity:1;color:rgb(226 19 110/var(--tw-text-opacity,1))}.text-\[\#EA580C\]{--tw-text-opacity:1;color:rgb(234 88 12/var(--tw-text-opacity,1))}.text-\[\#F5F8FC\]{--tw-text-opacity:1;color:rgb(245 248 252/var(--tw-text-opacity,1))}.text-\[\#FBBF24\],.text-amber-400{--tw-text-opacity:1;color:rgb(251 191 36/var(--tw-text-opacity,1))}.text-cyan-400{--tw-text-opacity:1;color:rgb(34 211 238/var(--tw-text-opacity,1))}.text-emerald-400{--tw-text-opacity:1;color:rgb(52 211 153/var(--tw-text-opacity,1))}.text-rose-400{--tw-text-opacity:1;color:rgb(251 113 133/var(--tw-text-opacity,1))}.text-slate-200{--tw-text-opacity:1;color:rgb(226 232 240/var(--tw-text-opacity,1))}.text-slate-300{--tw-text-opacity:1;color:rgb(203 213 225/var(--tw-text-opacity,1))}.text-slate-400{--tw-text-opacity:1;color:rgb(148 163 184/var(--tw-text-opacity,1))}.text-slate-500{--tw-text-opacity:1;color:rgb(100 116 139/var(--tw-text-opacity,1))}.text-white{--tw-text-opacity:1;color:rgb(255 255 255/var(--tw-text-opacity,1))}.opacity-0{opacity:0}.opacity-100{opacity:1}.opacity-50{opacity:.5}.shadow-2xl{--tw-shadow:0 25px 50px -12px rgba(0,0,0,.25);--tw-shadow-colored:0 25px 50px -12px var(--tw-shadow-color);box-shadow:var(--tw-ring-offset-shadow,0 0 #0000),var(--tw-ring-shadow,0 0 #0000),var(--tw-shadow)}.shadow-\[0_0_10px_rgba\(0\2c 123\2c 255\2c 0\.4\)\]{--tw-shadow:0 0 10px rgba(0,123,255,.4);--tw-shadow-colored:0 0 10px var(--tw-shadow-color);box-shadow:var(--tw-ring-offset-shadow,0 0 #0000),var(--tw-ring-shadow,0 0 #0000),var(--tw-shadow)}.shadow-\[0_0_10px_rgba\(0\2c 216\2c 154\2c 0\.4\)\]{--tw-shadow:0 0 10px rgba(0,216,154,.4);--tw-shadow-colored:0 0 10px var(--tw-shadow-color);box-shadow:var(--tw-ring-offset-shadow,0 0 #0000),var(--tw-ring-shadow,0 0 #0000),var(--tw-shadow)}.shadow-\[0_0_10px_rgba\(0\2c 217\2c 255\2c 0\.2\)\]{--tw-shadow:0 0 10px rgba(0,217,255,.2);--tw-shadow-colored:0 0 10px var(--tw-shadow-color);box-shadow:var(--tw-ring-offset-shadow,0 0 #0000),var(--tw-ring-shadow,0 0 #0000),var(--tw-shadow)}.shadow-\[0_0_10px_rgba\(0\2c 217\2c 255\2c 0\.4\)\]{--tw-shadow:0 0 10px rgba(0,217,255,.4);--tw-shadow-colored:0 0 10px var(--tw-shadow-color);box-shadow:var(--tw-ring-offset-shadow,0 0 #0000),var(--tw-ring-shadow,0 0 #0000),var(--tw-shadow)}.shadow-\[0_0_10px_rgba\(139\2c 92\2c 246\2c 0\.4\)\]{--tw-shadow:0 0 10px rgba(139,92,246,.4);--tw-shadow-colored:0 0 10px var(--tw-shadow-color);box-shadow:var(--tw-ring-offset-shadow,0 0 #0000),var(--tw-ring-shadow,0 0 #0000),var(--tw-shadow)}.shadow-\[0_0_15px_rgba\(0\2c 217\2c 255\2c 0\.1\)\]{--tw-shadow:0 0 15px rgba(0,217,255,.1);--tw-shadow-colored:0 0 15px var(--tw-shadow-color);box-shadow:var(--tw-ring-offset-shadow,0 0 #0000),var(--tw-ring-shadow,0 0 #0000),var(--tw-shadow)}.shadow-\[0_0_15px_rgba\(0\2c 217\2c 255\2c 0\.15\)\]{--tw-shadow:0 0 15px rgba(0,217,255,.15);--tw-shadow-colored:0 0 15px var(--tw-shadow-color);box-shadow:var(--tw-ring-offset-shadow,0 0 #0000),var(--tw-ring-shadow,0 0 #0000),var(--tw-shadow)}.shadow-\[0_0_20px_rgba\(0\2c 217\2c 255\2c 0\.12\)\]{--tw-shadow:0 0 20px rgba(0,217,255,.12);--tw-shadow-colored:0 0 20px var(--tw-shadow-color);box-shadow:var(--tw-ring-offset-shadow,0 0 #0000),var(--tw-ring-shadow,0 0 #0000),var(--tw-shadow)}.shadow-\[0_0_20px_rgba\(0\2c 217\2c 255\2c 0\.4\)\]{--tw-shadow:0 0 20px rgba(0,217,255,.4);--tw-shadow-colored:0 0 20px var(--tw-shadow-color);box-shadow:var(--tw-ring-offset-shadow,0 0 #0000),var(--tw-ring-shadow,0 0 #0000),var(--tw-shadow)}.shadow-\[0_0_25px_rgba\(0\2c 217\2c 255\2c 0\.3\)\]{--tw-shadow:0 0 25px rgba(0,217,255,.3);--tw-shadow-colored:0 0 25px var(--tw-shadow-color)}.shadow-\[0_0_25px_rgba\(0\2c 217\2c 255\2c 0\.3\)\],.shadow-lg{box-shadow:var(--tw-ring-offset-shadow,0 0 #0000),var(--tw-ring-shadow,0 0 #0000),var(--tw-shadow)}.shadow-lg{--tw-shadow:0 10px 15px -3px rgba(0,0,0,.1),0 4px 6px -4px rgba(0,0,0,.1);--tw-shadow-colored:0 10px 15px -3px var(--tw-shadow-color),0 4px 6px -4px var(--tw-shadow-color)}.shadow-md{--tw-shadow:0 4px 6px -1px rgba(0,0,0,.1),0 2px 4px -2px rgba(0,0,0,.1);--tw-shadow-colored:0 4px 6px -1px var(--tw-shadow-color),0 2px 4px -2px var(--tw-shadow-color)}.shadow-md,.shadow-sm{box-shadow:var(--tw-ring-offset-shadow,0 0 #0000),var(--tw-ring-shadow,0 0 #0000),var(--tw-shadow)}.shadow-sm{--tw-shadow:0 1px 2px 0 rgba(0,0,0,.05);--tw-shadow-colored:0 1px 2px 0 var(--tw-shadow-color)}.shadow-xl{--tw-shadow:0 20px 25px -5px rgba(0,0,0,.1),0 8px 10px -6px rgba(0,0,0,.1);--tw-shadow-colored:0 20px 25px -5px var(--tw-shadow-color),0 8px 10px -6px var(--tw-shadow-color);box-shadow:var(--tw-ring-offset-shadow,0 0 #0000),var(--tw-ring-shadow,0 0 #0000),var(--tw-shadow)}.shadow-black\/40{--tw-shadow-color:rgba(0,0,0,.4);--tw-shadow:var(--tw-shadow-colored)}.shadow-cyan-500\/10{--tw-shadow-color:rgba(6,182,212,.1);--tw-shadow:var(--tw-shadow-colored)}.shadow-cyan-500\/20{--tw-shadow-color:rgba(6,182,212,.2);--tw-shadow:var(--tw-shadow-colored)}.shadow-cyan-500\/25{--tw-shadow-color:rgba(6,182,212,.25);--tw-shadow:var(--tw-shadow-colored)}.shadow-cyan-900\/10{--tw-shadow-color:rgba(22,78,99,.1);--tw-shadow:var(--tw-shadow-colored)}.backdrop-blur-2xl{--tw-backdrop-blur:blur(40px)}.backdrop-blur-2xl,.backdrop-blur-lg{-webkit-backdrop-filter:var(--tw-backdrop-blur) var(--tw-backdrop-brightness) var(--tw-backdrop-contrast) var(--tw-backdrop-grayscale) var(--tw-backdrop-hue-rotate) var(--tw-backdrop-invert) var(--tw-backdrop-opacity) var(--tw-backdrop-saturate) var(--tw-backdrop-sepia);backdrop-filter:var(--tw-backdrop-blur) var(--tw-backdrop-brightness) var(--tw-backdrop-contrast) var(--tw-backdrop-grayscale) var(--tw-backdrop-hue-rotate) var(--tw-backdrop-invert) var(--tw-backdrop-opacity) var(--tw-backdrop-saturate) var(--tw-backdrop-sepia)}.backdrop-blur-lg{--tw-backdrop-blur:blur(16px)}.backdrop-blur-md{--tw-backdrop-blur:blur(12px)}.backdrop-blur-md,.backdrop-blur-sm{-webkit-backdrop-filter:var(--tw-backdrop-blur) var(--tw-backdrop-brightness) var(--tw-backdrop-contrast) var(--tw-backdrop-grayscale) var(--tw-backdrop-hue-rotate) var(--tw-backdrop-invert) var(--tw-backdrop-opacity) var(--tw-backdrop-saturate) var(--tw-backdrop-sepia);backdrop-filter:var(--tw-backdrop-blur) var(--tw-backdrop-brightness) var(--tw-backdrop-contrast) var(--tw-backdrop-grayscale) var(--tw-backdrop-hue-rotate) var(--tw-backdrop-invert) var(--tw-backdrop-opacity) var(--tw-backdrop-saturate) var(--tw-backdrop-sepia)}.backdrop-blur-sm{--tw-backdrop-blur:blur(4px)}.backdrop-blur-xl{--tw-backdrop-blur:blur(24px);-webkit-backdrop-filter:var(--tw-backdrop-blur) var(--tw-backdrop-brightness) var(--tw-backdrop-contrast) var(--tw-backdrop-grayscale) var(--tw-backdrop-hue-rotate) var(--tw-backdrop-invert) var(--tw-backdrop-opacity) var(--tw-backdrop-saturate) var(--tw-backdrop-sepia);backdrop-filter:var(--tw-backdrop-blur) var(--tw-backdrop-brightness) var(--tw-backdrop-contrast) var(--tw-backdrop-grayscale) var(--tw-backdrop-hue-rotate) var(--tw-backdrop-invert) var(--tw-backdrop-opacity) var(--tw-backdrop-saturate) var(--tw-backdrop-sepia)}.transition{transition-property:color,background-color,border-color,text-decoration-color,fill,stroke,opacity,box-shadow,transform,filter,-webkit-backdrop-filter;transition-property:color,background-color,border-color,text-decoration-color,fill,stroke,opacity,box-shadow,transform,filter,backdrop-filter;transition-property:color,background-color,border-color,text-decoration-color,fill,stroke,opacity,box-shadow,transform,filter,backdrop-filter,-webkit-backdrop-filter;transition-timing-function:cubic-bezier(.4,0,.2,1);transition-duration:.15s}.transition-all{transition-property:all;transition-timing-function:cubic-bezier(.4,0,.2,1);transition-duration:.15s}.transition-opacity{transition-property:opacity;transition-timing-function:cubic-bezier(.4,0,.2,1);transition-duration:.15s}.transition-transform{transition-property:transform;transition-timing-function:cubic-bezier(.4,0,.2,1);transition-duration:.15s}.duration-200{transition-duration:.2s}.duration-300{transition-duration:.3s}.ease-in-out{transition-timing-function:cubic-bezier(.4,0,.2,1)}.selection\:bg-\[\#00D9FF\] ::-moz-selection{--tw-bg-opacity:1;background-color:rgb(0 217 255/var(--tw-bg-opacity,1))}.selection\:bg-\[\#00D9FF\] ::selection{--tw-bg-opacity:1;background-color:rgb(0 217 255/var(--tw-bg-opacity,1))}.selection\:text-\[\#020817\] ::-moz-selection{--tw-text-opacity:1;color:rgb(2 8 23/var(--tw-text-opacity,1))}.selection\:text-\[\#020817\] ::selection{--tw-text-opacity:1;color:rgb(2 8 23/var(--tw-text-opacity,1))}.selection\:text-\[\#061535\] ::-moz-selection{--tw-text-opacity:1;color:rgb(6 21 53/var(--tw-text-opacity,1))}.selection\:text-\[\#061535\] ::selection{--tw-text-opacity:1;color:rgb(6 21 53/var(--tw-text-opacity,1))}.selection\:bg-\[\#00D9FF\]::-moz-selection{--tw-bg-opacity:1;background-color:rgb(0 217 255/var(--tw-bg-opacity,1))}.selection\:bg-\[\#00D9FF\]::selection{--tw-bg-opacity:1;background-color:rgb(0 217 255/var(--tw-bg-opacity,1))}.selection\:text-\[\#020817\]::-moz-selection{--tw-text-opacity:1;color:rgb(2 8 23/var(--tw-text-opacity,1))}.selection\:text-\[\#020817\]::selection{--tw-text-opacity:1;color:rgb(2 8 23/var(--tw-text-opacity,1))}.selection\:text-\[\#061535\]::-moz-selection{--tw-text-opacity:1;color:rgb(6 21 53/var(--tw-text-opacity,1))}.selection\:text-\[\#061535\]::selection{--tw-text-opacity:1;color:rgb(6 21 53/var(--tw-text-opacity,1))}.file\:mr-4::file-selector-button{margin-right:1rem}.file\:rounded-xl::file-selector-button{border-radius:.75rem}.file\:border-0::file-selector-button{border-width:0}.file\:bg-\[\#007BFF\]::file-selector-button{--tw-bg-opacity:1;background-color:rgb(0 123 255/var(--tw-bg-opacity,1))}.file\:px-4::file-selector-button{padding-left:1rem;padding-right:1rem}.file\:py-2::file-selector-button{padding-top:.5rem;padding-bottom:.5rem}.file\:py-2\.5::file-selector-button{padding-top:.625rem;padding-bottom:.625rem}.file\:text-xs::file-selector-button{font-size:.75rem;line-height:1rem}.file\:font-semibold::file-selector-button{font-weight:600}.file\:text-white::file-selector-button{--tw-text-opacity:1;color:rgb(255 255 255/var(--tw-text-opacity,1))}.after\:absolute:after{content:var(--tw-content);position:absolute}.after\:left-\[2px\]:after{content:var(--tw-content);left:2px}.after\:top-\[2px\]:after{content:var(--tw-content);top:2px}.after\:h-5:after{content:var(--tw-content);height:1.25rem}.after\:w-5:after{content:var(--tw-content);width:1.25rem}.after\:rounded-full:after{content:var(--tw-content);border-radius:9999px}.after\:border:after{content:var(--tw-content);border-width:1px}.after\:border-gray-300:after{content:var(--tw-content);--tw-border-opacity:1;border-color:rgb(209 213 219/var(--tw-border-opacity,1))}.after\:bg-white:after{content:var(--tw-content);--tw-bg-opacity:1;background-color:rgb(255 255 255/var(--tw-bg-opacity,1))}.after\:transition-all:after{content:var(--tw-content);transition-property:all;transition-timing-function:cubic-bezier(.4,0,.2,1);transition-duration:.15s}.after\:content-\[\'\'\]:after{--tw-content:"";content:var(--tw-content)}.hover\:border-\[\#00D9FF\]:hover{--tw-border-opacity:1;border-color:rgb(0 217 255/var(--tw-border-opacity,1))}.hover\:border-\[\#00D9FF\]\/40:hover{border-color:rgba(0,217,255,.4)}.hover\:border-\[\#0284C7\]:hover{--tw-border-opacity:1;border-color:rgb(2 132 199/var(--tw-border-opacity,1))}.hover\:border-\[\#7C3AED\]:hover{--tw-border-opacity:1;border-color:rgb(124 58 237/var(--tw-border-opacity,1))}.hover\:border-\[\#E2136E\]:hover{--tw-border-opacity:1;border-color:rgb(226 19 110/var(--tw-border-opacity,1))}.hover\:border-\[\#EA580C\]:hover{--tw-border-opacity:1;border-color:rgb(234 88 12/var(--tw-border-opacity,1))}.hover\:border-amber-400:hover{--tw-border-opacity:1;border-color:rgb(251 191 36/var(--tw-border-opacity,1))}.hover\:bg-\[\#0056b3\]:hover{--tw-bg-opacity:1;background-color:rgb(0 86 179/var(--tw-bg-opacity,1))}.hover\:bg-\[\#007BFF\]:hover{--tw-bg-opacity:1;background-color:rgb(0 123 255/var(--tw-bg-opacity,1))}.hover\:bg-\[\#00D9FF\]\/20:hover{background-color:rgba(0,217,255,.2)}.hover\:bg-\[\#051433\]\/60:hover{background-color:rgba(5,20,51,.6)}.hover\:bg-\[\#081e4b\]:hover{--tw-bg-opacity:1;background-color:rgb(8 30 75/var(--tw-bg-opacity,1))}.hover\:bg-\[\#092257\]:hover{--tw-bg-opacity:1;background-color:rgb(9 34 87/var(--tw-bg-opacity,1))}.hover\:bg-\[\#0c245c\]:hover{--tw-bg-opacity:1;background-color:rgb(12 36 92/var(--tw-bg-opacity,1))}.hover\:bg-\[\#0c2e75\]:hover{--tw-bg-opacity:1;background-color:rgb(12 46 117/var(--tw-bg-opacity,1))}.hover\:bg-\[\#0f2d6e\]:hover{--tw-bg-opacity:1;background-color:rgb(15 45 110/var(--tw-bg-opacity,1))}.hover\:bg-rose-500\/30:hover{background-color:rgba(244,63,94,.3)}.hover\:bg-slate-800:hover{--tw-bg-opacity:1;background-color:rgb(30 41 59/var(--tw-bg-opacity,1))}.hover\:text-\[\#00D89A\]:hover{--tw-text-opacity:1;color:rgb(0 216 154/var(--tw-text-opacity,1))}.hover\:text-\[\#00D9FF\]:hover{--tw-text-opacity:1;color:rgb(0 217 255/var(--tw-text-opacity,1))}.hover\:text-rose-300:hover{--tw-text-opacity:1;color:rgb(253 164 175/var(--tw-text-opacity,1))}.hover\:text-rose-400:hover{--tw-text-opacity:1;color:rgb(251 113 133/var(--tw-text-opacity,1))}.hover\:text-white:hover{--tw-text-opacity:1;color:rgb(255 255 255/var(--tw-text-opacity,1))}.hover\:underline:hover{text-decoration-line:underline}.hover\:file\:bg-\[\#0056b3\]::file-selector-button:hover{--tw-bg-opacity:1;background-color:rgb(0 86 179/var(--tw-bg-opacity,1))}.focus\:border-\[\#00D89A\]:focus{--tw-border-opacity:1;border-color:rgb(0 216 154/var(--tw-border-opacity,1))}.focus\:border-\[\#00D9FF\]:focus{--tw-border-opacity:1;border-color:rgb(0 217 255/var(--tw-border-opacity,1))}.focus\:border-amber-400:focus{--tw-border-opacity:1;border-color:rgb(251 191 36/var(--tw-border-opacity,1))}.focus\:outline-none:focus{outline:2px solid transparent;outline-offset:2px}.active\:scale-95:active{--tw-scale-x:.95;--tw-scale-y:.95;transform:translate(var(--tw-translate-x),var(--tw-translate-y)) rotate(var(--tw-rotate)) skewX(var(--tw-skew-x)) skewY(var(--tw-skew-y)) scaleX(var(--tw-scale-x)) scaleY(var(--tw-scale-y))}.group:hover .group-hover\:text-\[\#00D89A\]{--tw-text-opacity:1;color:rgb(0 216 154/var(--tw-text-opacity,1))}.group:hover .group-hover\:text-\[\#00D9FF\]{--tw-text-opacity:1;color:rgb(0 217 255/var(--tw-text-opacity,1))}.group:hover .group-hover\:text-amber-400{--tw-text-opacity:1;color:rgb(251 191 36/var(--tw-text-opacity,1))}.peer:checked~.peer-checked\:bg-\[\#00D89A\]{--tw-bg-opacity:1;background-color:rgb(0 216 154/var(--tw-bg-opacity,1))}.peer:checked~.peer-checked\:after\:translate-x-full:after{content:var(--tw-content);--tw-translate-x:100%;transform:translate(var(--tw-translate-x),var(--tw-translate-y)) rotate(var(--tw-rotate)) skewX(var(--tw-skew-x)) skewY(var(--tw-skew-y)) scaleX(var(--tw-scale-x)) scaleY(var(--tw-scale-y))}.peer:checked~.peer-checked\:after\:border-white:after{content:var(--tw-content);--tw-border-opacity:1;border-color:rgb(255 255 255/var(--tw-border-opacity,1))}.peer:focus~.peer-focus\:outline-none{outline:2px solid transparent;outline-offset:2px}@media (min-width:640px){.sm\:w-auto{width:auto}.sm\:grid-cols-2{grid-template-columns:repeat(2,minmax(0,1fr))}.sm\:grid-cols-4{grid-template-columns:repeat(4,minmax(0,1fr))}.sm\:grid-cols-5{grid-template-columns:repeat(5,minmax(0,1fr))}.sm\:flex-row{flex-direction:row}.sm\:items-center{align-items:center}.sm\:justify-end{justify-content:flex-end}.sm\:border-t-0{border-top-width:0}.sm\:p-8{padding:2rem}.sm\:px-4{padding-left:1rem;padding-right:1rem}.sm\:pt-0{padding-top:0}.sm\:text-right{text-align:right}.sm\:text-sm{font-size:.875rem;line-height:1.25rem}}@media (min-width:768px){.md\:mx-0{margin-left:0;margin-right:0}.md\:ml-72{margin-left:18rem}.md\:flex{display:flex}.md\:hidden{display:none}.md\:w-1\/2{width:50%}.md\:w-auto{width:auto}.md\:flex-none{flex:none}.md\:translate-x-0{--tw-translate-x:0px;transform:translate(var(--tw-translate-x),var(--tw-translate-y)) rotate(var(--tw-rotate)) skewX(var(--tw-skew-x)) skewY(var(--tw-skew-y)) scaleX(var(--tw-scale-x)) scaleY(var(--tw-scale-y))}.md\:grid-cols-2{grid-template-columns:repeat(2,minmax(0,1fr))}.md\:grid-cols-3{grid-template-columns:repeat(3,minmax(0,1fr))}.md\:grid-cols-4{grid-template-columns:repeat(4,minmax(0,1fr))}.md\:flex-row{flex-direction:row}.md\:items-center{align-items:center}.md\:justify-start{justify-content:flex-start}.md\:gap-4{gap:1rem}.md\:p-10{padding:2.5rem}.md\:p-12{padding:3rem}.md\:p-5{padding:1.25rem}.md\:p-8{padding:2rem}.md\:px-10{padding-left:2.5rem;padding-right:2.5rem}.md\:px-8{padding-left:2rem;padding-right:2rem}.md\:py-16{padding-top:4rem;padding-bottom:4rem}.md\:text-left{text-align:left}.md\:text-2xl{font-size:1.5rem;line-height:2rem}.md\:text-3xl{font-size:1.875rem;line-height:2.25rem}.md\:text-5xl{font-size:3rem;line-height:1}.md\:text-\[11px\]{font-size:11px}.md\:text-base{font-size:1rem;line-height:1.5rem}.md\:text-lg{font-size:1.125rem;line-height:1.75rem}.md\:text-sm{font-size:.875rem;line-height:1.25rem}}</style>
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
        <div class="flex items-center gap-2.5">
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
            <button onclick="switchSection('sec-site')" id="tab-sec-site" class="nav-tab px-4 py-2 rounded-xl text-xs font-bold text-slate-400 hover:bg-[#081e4b] transition flex items-center gap-2">
                <i class="fa-solid fa-gear text-sm text-amber-400"></i> Site Settings
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

        <?php if ($activeSection !== 'sec-overview'): ?>
            switchSection(<?= json_encode($activeSection) ?>);
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