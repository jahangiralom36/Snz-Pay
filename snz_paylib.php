<?php
// =========================================================================
// 💳 SNZPay Payment Methods — Admin → Payment Setup থেকে যোগ / এডিট / ON-OFF / ডিলিট
// payment_settings.json এর "methods" লিস্টে থাকে। পুরনো সেটিংস (bkash/nagad/...) নিজে থেকে চলে আসে।
// =========================================================================
if (defined('SNZ_PAYLIB_LOADED')) return;
define('SNZ_PAYLIB_LOADED', 1);

/** দ্রুত যোগ করার জন্য পরিচিত মেথড */
function snz_pay_presets() {
    return [
        'bkash'     => ['name' => 'bKash',       'color' => '#E2136E', 'kind' => 'mobile'],
        'nagad'     => ['name' => 'Nagad',       'color' => '#EC1C24', 'kind' => 'mobile'],
        'rocket'    => ['name' => 'Rocket',      'color' => '#8C3494', 'kind' => 'mobile'],
        'upay'      => ['name' => 'Upay',        'color' => '#FFC20E', 'kind' => 'mobile'],
        'tap'       => ['name' => 'Tap',         'color' => '#5B2C83', 'kind' => 'mobile'],
        'bangla_qr' => ['name' => 'Bangla QR',   'color' => '#0284C7', 'kind' => 'qr'],
        'binance'   => ['name' => 'Binance Pay', 'color' => '#F0B90B', 'kind' => 'crypto'],
    ];
}

function snz_pay_slug($s) {
    $s = strtolower(preg_replace('/[^A-Za-z0-9]+/', '_', (string) $s));
    $s = trim($s, '_');
    return $s !== '' ? substr($s, 0, 24) : 'm' . substr(md5(uniqid('', true)), 0, 6);
}

function snz_pay_clean_color($c, $fallback = '#00D9FF') {
    $c = trim((string) $c);
    return preg_match('/^#[0-9a-fA-F]{6}$/', $c) ? strtoupper($c) : $fallback;
}

/** একটা মেথডের সব ফিল্ড ঠিকঠাক (ডিফল্ট সহ) */
function snz_pay_normalize(array $m) {
    $kind = in_array($m['kind'] ?? '', ['mobile', 'qr', 'crypto', 'bank'], true) ? $m['kind'] : 'mobile';
    $type = in_array($m['type'] ?? '', ['personal', 'agent', 'merchant'], true) ? $m['type'] : 'personal';
    $currency = ($m['currency'] ?? '') === 'USDT' || $kind === 'crypto' ? 'USDT' : 'BDT';
    return [
        'id'       => snz_pay_slug($m['id'] ?? ($m['name'] ?? '')),
        'name'     => mb_substr(trim((string) ($m['name'] ?? 'Payment')), 0, 40),
        'logo'     => (string) ($m['logo'] ?? ''),
        'color'    => snz_pay_clean_color($m['color'] ?? ''),
        'kind'     => $kind,
        'type'     => $kind === 'mobile' ? $type : '',
        // Bangla QR: যেকোনো অ্যাপ থেকে স্ক্যান — নম্বর লাগে না, দেখানোও হয় না
        'number'   => $kind === 'qr' ? '' : mb_substr(trim((string) ($m['number'] ?? '')), 0, 120),
        'qr'       => (string) ($m['qr'] ?? ''),
        'note'     => mb_substr(trim((string) ($m['note'] ?? '')), 0, 1000),
        'currency' => $currency,
        'min'      => max(0, (float) ($m['min'] ?? ($currency === 'USDT' ? 0.25 : 10))),
        'enabled'  => !empty($m['enabled']) ? 1 : 0,
    ];
}

/** সব মেথড (সাজানো ক্রমে)। আগের ভার্সনের সেটিংস থাকলে সেগুলো থেকে বানিয়ে দেয়। */
function snz_pay_methods(array $info, array $legacyBot = []) {
    if (isset($info['methods']) && is_array($info['methods'])) {
        $out = [];
        foreach ($info['methods'] as $m) if (is_array($m)) $out[] = snz_pay_normalize($m);
        return $out;
    }
    $out = [];
    foreach (snz_pay_presets() as $pid => $p) {
        if ($pid === 'upay' || $pid === 'tap') continue;
        $num = (string) ($info[$pid] ?? '');
        if ($num === '' && $pid !== 'bangla_qr') $num = (string) ($legacyBot[strtoupper($pid)] ?? '');
        $qr = (string) ($info[$pid . '_qr'] ?? '');
        if ($pid === 'bangla_qr' && $qr === '') $qr = (string) ($legacyBot['BANGLA_QR_IMAGE'] ?? '');
        if ($num === '' && $qr === '') continue;
        $out[] = snz_pay_normalize([
            'id' => $pid, 'name' => $p['name'], 'color' => $p['color'], 'kind' => $p['kind'],
            'type' => $info[$pid . '_type'] ?? 'personal', 'number' => $num, 'qr' => $qr,
            'note' => $info[$pid . '_note'] ?? '',
            'enabled' => !isset($info[$pid . '_enabled']) || !empty($info[$pid . '_enabled']),
        ]);
    }
    return $out;
}

/** সেভ — পুরনো কোড/বট যেন চলে তাই bkash/nagad/rocket/binance নম্বরও আলাদা করে রাখা হয় */
function snz_pay_save($file, array $info, array $methods) {
    $info['methods'] = array_values(array_map('snz_pay_normalize', $methods));
    foreach (['bkash', 'nagad', 'rocket', 'binance'] as $pid) {
        foreach ($info['methods'] as $m) {
            if ($m['id'] === $pid || stripos($m['name'], $pid === 'binance' ? 'binance' : $pid) === 0) { $info[$pid] = $m['number']; break; }
        }
    }
    return @file_put_contents($file, json_encode($info, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) !== false ? $info : false;
}

/** ইউজার কোন অপশনে টাকা পাঠাবে */
function snz_pay_action(array $m) {
    if ($m['kind'] === 'qr') return 'Scan QR';
    if ($m['kind'] === 'crypto') return 'Send';
    if ($m['kind'] === 'bank') return 'Transfer';
    return ['personal' => 'Send Money', 'agent' => 'Cash Out', 'merchant' => 'Payment'][$m['type']] ?? 'Send Money';
}

function snz_pay_type_label(array $m) {
    if ($m['kind'] !== 'mobile') return ['qr' => 'Bangla QR', 'crypto' => 'Crypto', 'bank' => 'Bank'][$m['kind']] ?? '';
    return ucfirst($m['type']);
}

/** লোগো না থাকলে নামের প্রথম অক্ষর দিয়ে রঙিন ব্যাজ */
function snz_pay_logo_html(array $m, $size = 44) {
    $s = (int) $size;
    if ($m['logo'] !== '') {
        return '<img src="' . htmlspecialchars($m['logo']) . '" alt="' . htmlspecialchars($m['name']) . '" style="width:' . $s . 'px;height:' . $s . 'px" class="object-contain rounded-xl bg-white p-1 shrink-0">';
    }
    $ch = function_exists('mb_substr') ? mb_strtoupper(mb_substr($m['name'], 0, 1)) : strtoupper(substr($m['name'], 0, 1));
    return '<span style="width:' . $s . 'px;height:' . $s . 'px;background:' . $m['color'] . ';font-size:' . round($s * 0.45) . 'px" class="rounded-xl text-white font-black flex items-center justify-center shrink-0">' . htmlspecialchars($ch) . '</span>';
}

/** ছবি আপলোড (লোগো / QR) — সফল হলে path, না হলে null, ভুল হলে error string */
function snz_pay_upload($field, $prefix, &$err) {
    if (!isset($_FILES[$field]) || $_FILES[$field]['error'] !== UPLOAD_ERR_OK) return null;
    $ext = strtolower(pathinfo($_FILES[$field]['name'], PATHINFO_EXTENSION));
    if (!in_array($ext, ['png', 'jpg', 'jpeg', 'webp', 'gif'], true) || @getimagesize($_FILES[$field]['tmp_name']) === false) {
        $err = 'Image must be PNG, JPG, WEBP or GIF.';
        return null;
    }
    if ($_FILES[$field]['size'] > 3 * 1024 * 1024) {
        $err = 'Image must be smaller than 3 MB.';
        return null;
    }
    $dir = __DIR__ . '/uploads/pay/';
    if (!is_dir($dir)) @mkdir($dir, 0755, true);
    $name = $prefix . '_' . time() . '_' . mt_rand(100, 999) . '.' . ($ext === 'jpeg' ? 'jpg' : $ext);
    if (!@move_uploaded_file($_FILES[$field]['tmp_name'], $dir . $name)) {
        $err = 'Could not save image (check uploads folder permission).';
        return null;
    }
    return 'uploads/pay/' . $name;
}

function snz_pay_delete_file($path) {
    $path = (string) $path;
    if (strpos($path, 'uploads/pay/') === 0 || strpos($path, 'uploads/qr/') === 0) @unlink(__DIR__ . '/' . $path);
}
