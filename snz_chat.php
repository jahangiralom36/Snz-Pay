<?php
// =========================================================================
// 💬 SNZPay Live Chat — ইউজার (dashboard.php) ↔ AI / Admin (admin.php → Messenger)
// সেটিংস (API key সহ) থাকে chat_settings.json এ — এই ফাইল কখনো GitHub এ যাবে না।
// =========================================================================
if (defined('SNZ_CHAT_LOADED')) return;
define('SNZ_CHAT_LOADED', 1);

function snz_chat_settings_file() { return __DIR__ . '/chat_settings.json'; }

function snz_chat_settings() {
    $f = snz_chat_settings_file();
    $s = is_file($f) ? (json_decode((string) @file_get_contents($f), true) ?: []) : [];
    return array_merge([
        'enabled'    => 1,                    // Live chat বাটন দেখাবে
        'ai_enabled' => 1,                    // নিজে থেকে উত্তর (SNZPay Brain — কোনো API লাগে না)
        'bot_name'   => 'SNZPay Support',
        'agent_names' => 'Mahid, Nahid, Magid, Sihan, Raihan, Sayed',   // চ্যাটে উত্তরদাতার নাম (প্রতিটা চ্যাটে একজন)
        'admin_name' => 'Mahid',             // অ্যাডমিন নিজে উত্তর দিলে এই নাম দেখাবে
        'extra_info' => '',                   // পুরনো (ব্যবহার হয় না)
        'custom_qa'  => '',                   // অ্যাডমিনের শেখানো প্রশ্ন-উত্তর: "keyword, keyword => উত্তর" (প্রতি লাইনে একটা)
        'admin_pause_min' => 15,              // অ্যাডমিন উত্তর দিলে এতক্ষণ AI চুপ থাকবে
    ], $s);
}

function snz_chat_save_settings(array $new) {
    $s = array_merge(snz_chat_settings(), $new);
    return @file_put_contents(snz_chat_settings_file(), json_encode($s, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) !== false;
}

/** টেবিল একবারই বানানো হয় */
function snz_chat_ensure(PDO $pdo) {
    static $done = false;
    if ($done) return;
    $done = true;
    $flag = __DIR__ . '/.db_chat_v1';
    if (is_file($flag)) { snz_chat_ensure_v2($pdo); snz_chat_ensure_v3($pdo); return; }
    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS `chat_threads` (
            `id` INT NOT NULL AUTO_INCREMENT,
            `user_id` INT NOT NULL,
            `status` VARCHAR(10) NOT NULL DEFAULT 'open',
            `last_sender` VARCHAR(10) NULL,
            `last_reply_by` VARCHAR(10) NULL,
            `last_preview` VARCHAR(160) NULL,
            `admin_unread` INT NOT NULL DEFAULT 0,
            `user_unread` INT NOT NULL DEFAULT 0,
            `needs_admin` TINYINT(1) NOT NULL DEFAULT 0,
            `ai_off` TINYINT(1) NOT NULL DEFAULT 0,
            `admin_active_at` DATETIME NULL,
            `closed_by` VARCHAR(10) NULL,
            `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
            `updated_at` DATETIME NULL,
            `closed_at` DATETIME NULL,
            PRIMARY KEY (`id`),
            KEY `idx_ct_user` (`user_id`, `status`),
            KEY `idx_ct_upd` (`updated_at`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $pdo->exec("CREATE TABLE IF NOT EXISTS `chat_messages` (
            `id` INT NOT NULL AUTO_INCREMENT,
            `thread_id` INT NOT NULL,
            `sender` VARCHAR(10) NOT NULL,
            `body` TEXT NOT NULL,
            `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            KEY `idx_cm_thread` (`thread_id`, `id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        @file_put_contents($flag, date('c'));
        snz_chat_ensure_v2($pdo);
        snz_chat_ensure_v3($pdo);
    } catch (Throwable $e) {
        if (function_exists('snz_log_error')) snz_log_error('web', 'Live chat tables', $e->getMessage(), '', ['where' => 'snz_chat.php']);
    }
}

/** v2: প্রতিটা চ্যাটে উত্তরদাতার নাম */
function snz_chat_ensure_v2(PDO $pdo) {
    $flag = __DIR__ . '/.db_chat_v2';
    if (is_file($flag)) return;
    try { $pdo->exec("ALTER TABLE `chat_threads` ADD COLUMN `agent_name` VARCHAR(40) NULL"); } catch (Throwable $e) {}
    @file_put_contents($flag, date('c'));
}

/** v3: চ্যাট শুরুর ফর্মের নাম / ইমেইল / ফোন */
function snz_chat_ensure_v3(PDO $pdo) {
    $flag = __DIR__ . '/.db_chat_v3';
    if (is_file($flag)) return;
    foreach (["`customer_name` VARCHAR(80) NULL", "`customer_email` VARCHAR(120) NULL", "`customer_phone` VARCHAR(30) NULL"] as $col) {
        try { $pdo->exec("ALTER TABLE `chat_threads` ADD COLUMN " . $col); } catch (Throwable $e) {}
    }
    @file_put_contents($flag, date('c'));
}

/** সেটিংসের নামের লিস্ট থেকে একজন */
function snz_chat_agent_list(array $set) {
    $names = array_values(array_filter(array_map(function ($n) { return mb_substr(trim($n), 0, 30); }, preg_split('/[,\n]+/', (string) $set['agent_names']))));
    return $names ?: ['Mahid'];
}
function snz_chat_pick_agent(array $set) {
    $n = snz_chat_agent_list($set);
    return $n[array_rand($n)];
}
function snz_chat_admin_name(array $set) {
    $n = trim((string) ($set['admin_name'] ?? ''));
    return $n !== '' ? mb_substr($n, 0, 30) : snz_chat_agent_list($set)[0];
}

function snz_chat_close_text() {
    return "ধন্যবাদ স্যার! 😊 আপনার চ্যাটটি বন্ধ করা হলো।\nআবার কোনো দরকার হলে যেকোনো সময় মেসেজ দিবেন — আমরা সবসময় পাশে আছি। শুভকামনা রইল! 🌸";
}

function snz_chat_add(PDO $pdo, $threadId, $sender, $body) {
    $body = trim((string) $body);
    if ($body === '') return 0;
    $pdo->prepare("INSERT INTO chat_messages (thread_id, sender, body, created_at) VALUES (?, ?, ?, NOW())")->execute([$threadId, $sender, $body]);
    $id = (int) $pdo->lastInsertId();
    $preview = mb_substr(preg_replace('/\s+/u', ' ', $body), 0, 150);
    if ($sender === 'user') {
        $pdo->prepare("UPDATE chat_threads SET last_sender = 'user', last_preview = ?, admin_unread = admin_unread + 1, updated_at = NOW() WHERE id = ?")->execute([$preview, $threadId]);
    } else {
        $pdo->prepare("UPDATE chat_threads SET last_sender = ?, last_reply_by = IF(? = 'system', last_reply_by, ?), last_preview = ?, user_unread = user_unread + 1, updated_at = NOW() WHERE id = ?")
            ->execute([$sender, $sender, $sender, $preview, $threadId]);
    }
    return $id;
}

function snz_chat_close(PDO $pdo, $threadId, $by) {
    $t = $pdo->prepare("SELECT status FROM chat_threads WHERE id = ?");
    $t->execute([$threadId]);
    if ($t->fetchColumn() !== 'open') return false;
    $pdo->prepare("UPDATE chat_threads SET status = 'closed', closed_by = ?, closed_at = NOW(), needs_admin = 0 WHERE id = ?")->execute([$by, $threadId]);
    return true;
}

function snz_chat_messages(PDO $pdo, $threadId, $after = 0, $limit = 200) {
    $q = $pdo->prepare("SELECT id, sender, body, created_at FROM chat_messages WHERE thread_id = ? AND id > ? ORDER BY id ASC LIMIT " . (int) $limit);
    $q->execute([$threadId, (int) $after]);
    $out = [];
    foreach ($q->fetchAll() as $r) {
        $out[] = ['id' => (int) $r['id'], 'sender' => $r['sender'], 'body' => $r['body'], 'time' => date('h:i A', strtotime($r['created_at'])), 'date' => date('d M Y', strtotime($r['created_at']))];
    }
    return $out;
}

/** সাইটের লিংক (https://domain/folder/) */
function snz_chat_base_url() {
    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https') || (($_SERVER['SERVER_PORT'] ?? '') == 443);
    $host = preg_replace('/[^A-Za-z0-9.\-:]/', '', (string) ($_SERVER['HTTP_HOST'] ?? 'snzpay.digital'));
    $dir = rtrim(str_replace('\\', '/', dirname((string) ($_SERVER['SCRIPT_NAME'] ?? '/'))), '/');
    if (substr($dir, -4) === '/api') $dir = substr($dir, 0, -4);
    return ($https ? 'https' : 'http') . '://' . $host . $dir . '/';
}

/** ডিপোজিট নম্বর / মেথড (Admin → Payment Setup এ যেগুলো ON) */
function snz_chat_pay_methods_text() {
    global $PAYMENT_INFO;
    $info = is_array($PAYMENT_INFO ?? null) ? $PAYMENT_INFO : [];
    $f = __DIR__ . '/payment_settings.json';
    if (is_file($f)) { $j = json_decode((string) @file_get_contents($f), true); if (is_array($j)) $info = array_merge($info, $j); }
    if (!function_exists('snz_pay_methods') && is_file(__DIR__ . '/snz_paylib.php')) require_once __DIR__ . '/snz_paylib.php';
    if (!function_exists('snz_pay_methods')) return '';
    $out = '';
    foreach (snz_pay_methods($info) as $m) {
        if (!$m['enabled']) continue;
        $out .= '• ' . $m['name'] . ' (' . snz_pay_type_label($m) . ' — ' . snz_pay_action($m) . ')' . ($m['number'] !== '' ? ': ' . $m['number'] : ' — Add Funds পেজের QR স্ক্যান করুন')
              . ', সর্বনিম্ন ' . ($m['currency'] === 'USDT' ? $m['min'] . ' USDT' : '৳' . $m['min']) . "\n";
    }
    return $out;
}


// =========================================================================
// 🧠 SNZPay Brain — কোনো বাইরের API ছাড়াই উত্তর দেয়
// সাইটের সব তথ্য + ইউজারের নিজের অ্যাকাউন্ট (ব্যালেন্স, প্ল্যান, ডিপোজিট, TrxID) দেখে উত্তর
// বুঝতে না পারলে অ্যাডমিনের জন্য রেখে দেয় (Messenger → Needs Admin)
// =========================================================================

/** লেখা সহজ করা: ছোট হাতের অক্ষর, বাংলা সংখ্যা → ইংরেজি, বাড়তি চিহ্ন বাদ */
function snz_brain_norm($t) {
    $t = mb_strtolower(trim((string) $t));
    $t = strtr($t, ['০'=>'0','১'=>'1','২'=>'2','৩'=>'3','৪'=>'4','৫'=>'5','৬'=>'6','৭'=>'7','৮'=>'8','৯'=>'9']);
    $t = preg_replace('/[?!.,।;:"\'()\[\]{}]+/u', ' ', $t);
    return trim(preg_replace('/\s+/u', ' ', $t));
}

/** সব বিষয় (intent) — প্রতিটার শব্দ বাংলা / Banglish / English এ */
function snz_brain_intents() {
    return [
        'bye'       => '^(ধন্যবাদ|শুকরিয়া|dhonn?obad|donnobad|thanks?|thank ?you|thanx|tnx|thx|ty|bye|good ?bye|allah ?hafe?z|আল্লাহ হাফেজ|ar kichu lagbe na|আর কিছু লাগবে না|ok thanks?|ok ধন্যবাদ|problem solved|solved|সমাধান হয়েছে|hoye gese|hoye geche|হয়ে গেছে)( (bhai|vai|ভাই|sir|স্যার|boss|বস|apu|আপু))*$',
        'human'     => 'admin|এডমিন|অ্যাডমিন|এ্যাডমিন|manush|মানুষ|human|real person|call (dao|den|korun)|কল দ|ফোন দ|kotha bolbo|কথা বলব|owner|মালিক|support team',
        'refund'    => 'refund|রিফান্ড|ferot|ফেরত|taka ferot|money back|cancel plan|প্ল্যান বাতিল',
        'ban'       => '\bban(ned)?\b|ব্যান|block|ব্লক|suspend|deactive|নিষ্ক্রিয়',
        'phone_change' => 'phone (change|poriborton|bodol)|ফোন (পরিবর্তন|বদল|চেঞ্জ)|new phone|নতুন ফোন|onno phone|অন্য ফোন|device (change|reset|locked)|device_locked|reset (phone|device|key)|রিসেট|phone lock|ফোন লক',
        'password'  => 'password|পাসওয়ার্ড|pass (vule|bhule)|vule gesi|ভুলে গেছি|forgot|reset pass|login korte parchi na website|account (hack|lock)',
        'discount'  => 'discount|ডিসকাউন্ট|offer|অফার|coupon|কুপন|promo|কম দামে|kom dam|sasta',
        'trial'     => 'trial|ট্রায়াল|free plan|ফ্রি প্ল্যান|free (te|e)|ফ্রিতে|free kivabe|free test',
        'balance'   => '(amar|আমার|my) (balance|ব্যালেন্স|taka|টাকা)|balance (koto|কত|check|dekh)|ব্যালেন্স (কত|চেক)|koto taka ase|কত টাকা আছে',
        'my_plan'   => '(amar|আমার|my) (plan|প্ল্যান|key|কী|license|লাইসেন্স)|meyad|মেয়াদ|expire|expiry|validity|ভ্যালিডিটি|kobe (sesh|shesh)|কবে শেষ|koto din baki|কত দিন বাকি',
        'renew'     => 'renew|রিনিউ|extend|বাড়া|barate|baran|abar kinbo|আবার কিনব',
        'deposit_problem' => '(deposit|ডিপোজিট|dipojit|diposit|add ?fund|fund|taka|টাকা|balance|ব্যালেন্স|trx|payment|পেমেন্ট|bkash|বিকাশ|nagad|নগদ|rocket|রকেট|upay|binance).{0,40}(hoy ?ni|hoy ?nai|hoyni|ase ?ni|ashe ?ni|aseni|asheni|আসেনি|আসে নাই|পাইনি|হয়নি|হয় নাই|jog hoy ?ni|যোগ হয়নি|add hoy ?ni|hocche na|hosse na|hoche na|হচ্ছে না|kete|কেটে|failed|fail|not (found|added|received)|pending|পেন্ডিং|mismatch|problem|সমস্যা|somossa|error)|(hoy ?ni|ase ?ni|আসেনি|হয়নি|পাইনি).{0,30}(deposit|ডিপোজিট|taka|টাকা|balance|ব্যালেন্স)',
        'pay_numbers' => '(bkash|বিকাশ|nagad|নগদ|rocket|রকেট|upay|উপায়|binance|বাইনান্স|payment|পেমেন্ট|deposit|ডিপোজিট|send money).{0,20}(number|নম্বর|নাম্বার|nambar|num|id|address|account|একাউন্ট)|(number|নম্বর|নাম্বার|nambar) (dao|den|din|দিন|দাও|koto|কত)|kon number|কোন নম্বর|কোথায় টাকা পাঠাব|kothay taka pathabo|taka kothay',
        'deposit'   => 'deposit|ডিপোজিট|bangla ?qr|\bqr\b|কিউআর|scan|স্ক্যান|dipojit|diposit|add ?fund|add ?money|অ্যাড ফান্ড|fund|ফান্ড|recharge|রিচার্জ|taka (add|jog|dibo|dite|pathabo|pathate)|টাকা (যোগ|দিব|দেব|পাঠাব|ঢুকাব)|balance (add|jog|barabo)|ব্যালেন্স (যোগ|বাড়াব)|top ?up|send money|সেন্ড মানি|trx ?id|trxid|ট্রানজেকশন|transaction id',
        'plan'      => 'plan|প্ল্যান|প্লান|package|প্যাকেজ|price|প্রাইস|pricing|dam|দাম|koto taka|কত টাকা|cost|charge|চার্জ|subscription|সাবস্ক্রিপশন|kinbo|kinte|কিনব|কিনতে|buy|purchase|monthly|মাসিক',
        'app_problem' => '(app|অ্যাপ|এপ|apk|login|লগইন|key|কী|license|লাইসেন্স|sms).{0,40}(hosse ?na|hocche ?na|hoche ?na|hoy ?na|হচ্ছে না|হয় না|kaj kore ?na|কাজ করে না|kaj korche ?na|কাজ করছে না|problem|সমস্যা|error|এরর|invalid|wrong|vul|ভুল|expired|crash|বন্ধ হয়ে|off hoye|hang|logout hoye|লগআউট হয়ে|jacche ?na|যাচ্ছে না|asche ?na|আসছে না|upload hoy ?na|not working|failed)',
        'app'       => '\bapp\b|অ্যাপ|এ্যাপ|এপ|apk|download|ডাউনলোড|install|ইনস্টল|android|অ্যান্ড্রয়েড|mobile app|login (korbo|kivabe|kemne)|লগইন (করব|কিভাবে)|license key|লাইসেন্স কী|key (dibo|bosabo|kothay)',
        'sms'       => '\bsms\b|এসএমএস|message (asche|ase|jacche|upload)|permission|পারমিশন|battery|ব্যাটারি|upload',
        'wallet'    => 'wallet|ওয়ালেট|wallet setup|kon wallet|কোন ওয়ালেট|bkash on|nagad on|sms on|চালু করব',
        'custom_sms' => 'custom sms|কাস্টম এসএমএস|sms format|ফরম্যাট|নিজের sms|bank sms|ব্যাংক',
        'brand'     => 'brand|ব্র্যান্ড|brand key|api key|এপিআই কী|bot key|বট কী|secret key',
        'api'       => '\bapi\b|এপিআই|developer|ডেভেলপার|integration|ইন্টিগ্রেশন|integrate|webhook|ওয়েবহুক|code|কোড|php|python|node|wordpress|woocommerce|telegram bot|টেলিগ্রাম বট|\bbot\b|বট|website a|ওয়েবসাইটে|site a lagabo|নিজের সাইটে|auto verify|অটো ভেরিফাই|verify payment',
        'history'   => 'history|হিস্টরি|হিস্ট্রি|transaction (list|dekhbo)|লেনদেন|ager payment|আগের পেমেন্ট|record|রেকর্ড',
        'register'  => 'register|রেজিস্টার|রেজিস্ট্রেশন|sign ?up|account (khulbo|open|kivabe)|একাউন্ট (খুলব|খোলা)|new account',
        'about'     => 'snzpay (ki|কি|kী)|what is snzpay|এটা কি|eta ki|kivabe kaj kore|কিভাবে কাজ করে|how (it|does it) work|features?|ফিচার|ki ki (ase|pabo)|কি কি পাব|সুবিধা|suvidha|kno (lav|labh)|safe|নিরাপদ|trust|বিশ্বাস|real|asol',
        'links'     => 'link|লিংক|লিঙ্ক|url|website|ওয়েবসাইট|site|সাইট|page|পেজ|kothay pabo|কোথায় পাব|kon jaygay|কোথায়',
        'who'       => '(tumi|apni|আপনি|তুমি) (ke|কে|kon|কোন)|who are you|tomar nam|আপনার নাম|apnar nam|bot (naki|na)|ai (naki|na)|robot|রোবট|মানুষ নাকি|manush naki',
        'how_are'   => 'kemon (aso|acho|asen|achen|aso vai)|কেমন (আছ|আছেন)|how are you|ki (khobor|obostha)|কি খবর',
        'ack'       => '^(ok+|okay|okk|accha|আচ্ছা|thik ase|thik ache|ঠিক আছে|hmm+|hm+|ji|জি|ha|হ্যাঁ|haa|yes|right|bujhsi|bujhechi|বুঝেছি|বুঝছি|got it|dekhchi|দেখছি)( (bhai|vai|ভাই|sir|স্যার))*$',
        'greet'     => '^(hi+|hello+|hlw|hlo|helo|hey+|hii+|oi+|ওই|হাই|হ্যালো|হেলো|salam|slm|assalamu ?alaikum|as?salamualaikum|আসসালামু আলাইকুম|সালাম|bhai|ভাই|vai|sir|স্যার|boss|বস|keu (asen|acen|ase)|কেউ আছেন|admin vai|good (morning|evening|night|afternoon))(\s|$)',
    ];
}

/** কোন বিষয়ে প্রশ্ন — সবচেয়ে মিল থাকাটা */
function snz_brain_detect($t) {
    $found = [];
    foreach (snz_brain_intents() as $k => $re) {
        if (preg_match('/' . $re . '/iu', $t)) $found[] = $k;
    }
    return $found;
}

/** মেসেজে TrxID আছে কিনা (অক্ষর+সংখ্যা মিলিয়ে ৬-২০ ঘর) */
function snz_brain_trx($raw) {
    if (preg_match_all('/\b([A-Za-z0-9]{6,20})\b/u', (string) $raw, $m)) {
        foreach ($m[1] as $c) {
            if (preg_match('/[A-Za-z]/', $c) && preg_match('/[0-9]/', $c)) return strtoupper($c);
            if (preg_match('/^[0-9]{10,20}$/', $c) && !preg_match('/^01[3-9][0-9]{8}$/', $c)) return $c;   // Binance order id (ফোন নম্বর বাদ)
        }
    }
    return '';
}

function snz_brain_site_receiver() {
    $id = defined('MASTER_USER_ID') ? (int) MASTER_USER_ID : 1;
    $f = __DIR__ . '/app_settings.json';
    $s = is_file($f) ? (json_decode((string) @file_get_contents($f), true) ?: []) : [];
    if (!empty($s['site_receiver_user_id'])) $id = (int) $s['site_receiver_user_id'];
    return $id;
}

function snz_brain_plans_text(PDO $pdo) {
    $out = '';
    try {
        foreach ($pdo->query("SELECT name, price, discount_percent, duration_hours, max_brand_keys, is_trial FROM plans WHERE status = 'active' ORDER BY sort_order, id LIMIT 10")->fetchAll() as $p) {
            $price = (float) $p['price'] * (1 - (float) $p['discount_percent'] / 100);
            $h = (int) $p['duration_hours'];
            $out .= "• " . $p['name'] . " — " . ($price > 0 ? "৳" . number_format($price, 0) : "FREE") . " / " . ($h % 24 === 0 ? ($h / 24) . " দিন" : $h . " ঘণ্টা")
                  . ((int) $p['max_brand_keys'] > 0 ? ", " . (int) $p['max_brand_keys'] . "টা Brand key" : '') . (!empty($p['is_trial']) ? " (একবারই)" : '') . "\n";
        }
    } catch (Throwable $e) {}
    return trim($out);
}

/** অ্যাডমিনের শেখানো প্রশ্ন-উত্তর (Messenger → Settings) */
function snz_brain_custom(array $set, $t) {
    foreach (preg_split('/\r?\n/', (string) $set['custom_qa']) as $line) {
        if (strpos($line, '=>') === false) continue;
        [$keys, $ans] = array_map('trim', explode('=>', $line, 2));
        if ($ans === '') continue;
        foreach (preg_split('/\s*[,|]\s*/u', $keys) as $k) {
            $k = snz_brain_norm($k);
            if ($k !== '' && mb_strpos($t, $k) !== false) return str_replace('\n', "\n", $ans);
        }
    }
    return '';
}

/**
 * উত্তর তৈরি — ['text' => ..., 'need' => অ্যাডমিন লাগবে?, 'close' => চ্যাট বন্ধ?] অথবা null (বুঝিনি)
 */
function snz_chat_brain(PDO $pdo, array $thread, $raw, $isFirst, $prevRaw = '') {
    $set = snz_chat_settings();
    $agent = (string) (($thread['agent_name'] ?? '') !== '' ? $thread['agent_name'] : snz_chat_agent_list($set)[0]);
    $base = snz_chat_base_url();
    $uid = (int) $thread['user_id'];
    $t = snz_brain_norm($raw);
    $hello = $isFirst ? "আসসালামু আলাইকুম স্যার, আমি {$agent}, SNZPay থেকে বলছি। " : '';
    $R = function ($text, $need = false, $close = false) use ($hello) { return ['text' => $hello . $text, 'need' => $need, 'close' => $close]; };

    // ১) অ্যাডমিনের শেখানো উত্তর সবার আগে
    $c = snz_brain_custom($set, $t);
    if ($c !== '') return $R($c);

    $in = snz_brain_detect($t);
    // ছোট ফলো-আপ ("kivabe?", "link?", "koto?") → আগের মেসেজের বিষয় ধরে
    if (!$in && $prevRaw !== '' && mb_strlen($t) <= 25 && preg_match('/kivabe|kibhabe|kemne|kemone|keno|koto|kothay|kobe|kon|link|details|bujhi ?ni|bujhlam na|abar|ar\b|then|tarpor|কিভাবে|কেমনে|কেন|কত|কোথায়|কবে|কোন|লিংক|বুঝিনি|বুঝলাম না|আবার|তারপর|^(ki|কি)$/iu', $t)) $in = snz_brain_detect(snz_brain_norm($prevRaw . ' ' . $raw));
    $has = function ($k) use ($in) { return in_array($k, $in, true); };

    // ইউজারের অ্যাকাউন্ট
    $u = ['username' => '', 'balance' => 0];
    try { $q = $pdo->prepare("SELECT username, balance FROM users WHERE id = ?"); $q->execute([$uid]); $u = $q->fetch() ?: $u; } catch (Throwable $e) {}

    // ২) TrxID দিলে নিজেই খুঁজে দেখা
    $trx = snz_brain_trx($raw);
    if ($trx !== '' && ($has('deposit') || $has('deposit_problem') || $has('pay_numbers') || count($in) === 0 || preg_match('/trx|ট্রানজ|transaction|id/iu', $t))) {
        $dr = null;
        try { $q = $pdo->prepare("SELECT status, attempts, amount, credited_amount, admin_note FROM deposit_requests WHERE user_id = ? AND trx_id = ? LIMIT 1"); $q->execute([$uid, $trx]); $dr = $q->fetch(); } catch (Throwable $e) {}
        if ($dr && $dr['status'] === 'approved') return $R("✅ স্যার, TrxID {$trx} সফলভাবে verify হয়েছে — ৳" . number_format((float) ($dr['credited_amount'] ?? $dr['amount']), 2) . " আপনার ব্যালেন্সে যোগ হয়েছে। এখন আপনার ব্যালেন্স ৳" . number_format((float) $u['balance'], 2) . "।");
        if ($dr && $dr['status'] === 'review') return $R("স্যার, TrxID {$trx} এখন অ্যাডমিনের কাছে Pending আছে ⏳ অ্যাডমিন চেক করে খুব শীঘ্রই ব্যালেন্স যোগ করে দেবেন। আমি টিমকে আবার জানিয়ে দিলাম 🙏", true);
        if ($dr && $dr['status'] === 'rejected') return $R("স্যার, TrxID {$trx} রিকোয়েস্টটা Reject হয়েছে" . (!empty($dr['admin_note']) ? " (কারণ: " . $dr['admin_note'] . ")" : '') . "। ভুল মনে হলে এখানে লিখুন, অ্যাডমিন আবার দেখবেন।", true);
        $pay = null;
        try { $q = $pdo->prepare("SELECT status, amount, method FROM payments WHERE trx_id = ? AND user_id IN (?, ?) ORDER BY id DESC LIMIT 1"); $q->execute([$trx, snz_brain_site_receiver(), $uid]); $pay = $q->fetch(); } catch (Throwable $e) {}
        if ($pay && $pay['status'] === 'used') return $R("স্যার, TrxID {$trx} আগেই একবার ব্যবহার (verify) করা হয়েছে — একটা TrxID দুইবার ব্যবহার করা যায় না। আপনি নিজে ব্যবহার না করে থাকলে এখানে জানান, অ্যাডমিন চেক করবেন।", true);
        if ($pay) return $R("স্যার, TrxID {$trx} এর পেমেন্ট আমরা পেয়েছি ✅ (৳" . number_format((float) $pay['amount'], 2) . ", " . $pay['method'] . ")। এখন Add Funds এ গিয়ে ঠিক এই Amount আর TrxID দিয়ে Verify চাপুন, সাথে সাথে ব্যালেন্স যোগ হবে:\n{$base}dashboard.php#add-fund");
        return $R("স্যার, TrxID {$trx} এর পেমেন্ট এখনো আমাদের সিস্টেমে আসেনি। টাকা পাঠানোর ২-৫ মিনিট পর আবার Add Funds এ Verify করে দেখুন। ৩ বার চেষ্টা করেও না হলে রিকোয়েস্ট নিজে থেকেই অ্যাডমিনের কাছে চলে যাবে। আমি টিমকেও জানিয়ে রাখলাম 🙏", true);
    }

    // ৩) বিদায় → সুন্দর করে চ্যাট বন্ধ
    if ($has('bye') && count($in) <= 2) return ['text' => snz_chat_close_text(), 'need' => false, 'close' => true];

    // ৪) যেগুলো শুধু অ্যাডমিন পারেন
    if ($has('refund')) return $R("স্যার, রিফান্ডের বিষয়টা অ্যাডমিন নিজে দেখেন। আপনার রিকোয়েস্টটা টিমকে পাঠিয়ে দিয়েছি — কিছুক্ষণের মধ্যেই এখানে উত্তর পাবেন 🙏", true);
    if ($has('phone_change')) return $R("স্যার, একটা License Key একটা ফোনেই চলে। নতুন ফোনে চালাতে হলে অ্যাডমিন আগের ফোনটা রিসেট করে দেবেন — আপনার রিকোয়েস্ট টিমকে পাঠিয়ে দিয়েছি 🙏 (একই ফোনে logout করে আবার login করতে কোনো সমস্যা নেই।)", true);
    if ($has('ban')) return $R("স্যার, অ্যাকাউন্ট/কী ব্যান বা ব্লকের বিষয়টা অ্যাডমিন চেক করবেন। টিমকে জানিয়ে দিয়েছি, এখানেই উত্তর পাবেন 🙏", true);
    if ($has('password')) return $R("স্যার, পাসওয়ার্ড রিসেটের জন্য অ্যাডমিন আপনার অ্যাকাউন্ট যাচাই করে সাহায্য করবেন। আপনার username/email টা এখানে লিখে দিন, টিমকে পাঠিয়ে দিয়েছি 🙏", true);
    if ($has('discount')) return $R("স্যার, অফার/ডিসকাউন্ট চললে প্ল্যানের দামেই দেখানো থাকে: {$base}dashboard.php#plans\nবিশেষ অফারের জন্য অ্যাডমিনকে জানিয়ে দিয়েছি, উনি এখানে উত্তর দেবেন 😊", true);
    if ($has('human')) return $R("জি স্যার, অ্যাডমিন টিমকে জানিয়ে দিয়েছি — উনি এই চ্যাটেই আপনাকে উত্তর দেবেন। ততক্ষণ কিছু জানার থাকলে আমাকে বলতে পারেন 😊", true);

    // ৫) ইউজারের নিজের তথ্য
    if ($has('balance')) {
        return $R("স্যার, আপনার অ্যাকাউন্টে এখন ব্যালেন্স আছে ৳" . number_format((float) $u['balance'], 2) . "। ব্যালেন্স যোগ করতে: {$base}dashboard.php#add-fund");
    }
    if ($has('my_plan') || $has('renew')) {
        $keys = [];
        try { $q = $pdo->prepare("SELECT plan_name, license_key, expires_at, status FROM user_keys WHERE user_id = ? ORDER BY expires_at DESC LIMIT 5"); $q->execute([$uid]); $keys = $q->fetchAll(); } catch (Throwable $e) {}
        if (!$keys) return $R("স্যার, আপনার অ্যাকাউন্টে এখনো কোনো প্ল্যান নেই। প্ল্যান কিনতে:\n1. Add Funds থেকে ব্যালেন্স যোগ করুন: {$base}dashboard.php#add-fund\n2. প্ল্যান বেছে Buy চাপুন: {$base}dashboard.php#plans");
        $lines = [];
        foreach ($keys as $k) {
            $left = strtotime((string) $k['expires_at']) - time();
            $state = ($k['status'] ?? 'active') !== 'active' ? 'বন্ধ (' . $k['status'] . ')' : ($left <= 0 ? 'মেয়াদ শেষ' : 'চালু — আর ' . ($left >= 86400 ? floor($left / 86400) . ' দিন' : max(1, floor($left / 3600)) . ' ঘণ্টা') . ' বাকি');
            $lines[] = "• " . ($k['plan_name'] ?: 'Plan') . " (কী …" . substr((string) $k['license_key'], -4) . "): " . $state . ", মেয়াদ " . date('d M Y, h:i A', strtotime((string) $k['expires_at']));
        }
        return $R("আপনার প্ল্যান স্যার:\n" . implode("\n", $lines) . "\n\nরিনিউ করতে My Plans এ গিয়ে Renew চাপুন (ব্যালেন্স লাগবে): {$base}dashboard.php#my-plan");
    }

    // ৬) ডিপোজিট
    if ($has('deposit_problem')) {
        $last = null;
        try { $q = $pdo->prepare("SELECT trx_id, status, attempts FROM deposit_requests WHERE user_id = ? ORDER BY updated_at DESC LIMIT 1"); $q->execute([$uid]); $last = $q->fetch(); } catch (Throwable $e) {}
        $extra = '';
        if ($last && $last['status'] === 'review') $extra = "\n\nআপনার শেষ রিকোয়েস্ট (TrxID " . $last['trx_id'] . ") অ্যাডমিনের কাছে Pending আছে ⏳";
        elseif ($last && $last['status'] === 'trying') $extra = "\n\nআপনার শেষ TrxID " . $last['trx_id'] . " — " . (int) $last['attempts'] . "/3 বার চেষ্টা হয়েছে।";
        return $R("স্যার, চিন্তা করবেন না 🙏 একটু মিলিয়ে নিন:\n1. TrxID ঠিক লিখেছেন কিনা (SMS থেকে কপি করে দিন)\n2. যত টাকা পাঠিয়েছেন ঠিক সেই Amount দিয়েছেন কিনা\n3. সঠিক মেথড (bKash/Nagad...) বেছেছেন কিনা\n4. টাকা পাঠানোর ২-৫ মিনিট পর চেষ্টা করুন\n\n৩ বার চেষ্টা করেও না হলে রিকোয়েস্ট নিজে থেকেই অ্যাডমিনের কাছে Pending এ যায়, উনি চেক করে যোগ করে দেবেন। আপনার TrxID টা এখানে লিখলে আমি এখনই চেক করে দেখছি।" . $extra, $last && $last['status'] === 'review');
    }
    if ($has('pay_numbers') || ($has('deposit') && preg_match('/number|নম্বর|নাম্বার|nambar|kothay|কোথায়/iu', $t))) {
        $pay = trim(snz_chat_pay_methods_text());
        return $R($pay !== '' ? "টাকা পাঠানোর নম্বর স্যার:\n{$pay}\n\nটাকা পাঠিয়ে Add Funds এ Amount আর TrxID দিয়ে Verify করুন: {$base}dashboard.php#add-fund" : "পেমেন্ট নম্বর Add Funds পেজে দেওয়া আছে স্যার: {$base}dashboard.php#add-fund");
    }
    if ($has('deposit')) {
        $pay = trim(snz_chat_pay_methods_text());
        return $R("ব্যালেন্স যোগ করার নিয়ম স্যার:\n1. এই পেজে যান: {$base}dashboard.php#add-fund\n2. পেমেন্ট মেথড বেছে নিচের নম্বরে টাকা পাঠান\n3. Amount আর TrxID লিখে Verify চাপুন — কয়েক সেকেন্ডেই ব্যালেন্স যোগ হবে ✅" . ($pay !== '' ? "\n\n" . $pay : '') . "\n\nমনে রাখবেন: একটা TrxID একবারই ব্যবহার করা যায়।");
    }

    // ৭) প্ল্যান
    if ($has('trial')) return $R("জি স্যার, নতুন অ্যাকাউন্টে একবার ফ্রি ট্রায়াল প্ল্যান নেওয়া যায় (চালু থাকলে Pricing Plans এ দেখাবে): {$base}dashboard.php#plans");
    if ($has('plan')) {
        $pl = snz_brain_plans_text($pdo);
        return $R("প্ল্যান কেনার নিয়ম স্যার:\n1. Add Funds থেকে ব্যালেন্স যোগ করুন: {$base}dashboard.php#add-fund\n2. Pricing Plans থেকে প্ল্যান বেছে Buy চাপুন: {$base}dashboard.php#plans\n3. কেনার পর License Key পাবেন My Plans এ: {$base}dashboard.php#my-plan" . ($pl !== '' ? "\n\nএখনকার প্ল্যান:\n" . $pl : '') . "\n\nআপনার ব্যালেন্স এখন ৳" . number_format((float) $u['balance'], 2) . "।");
    }

    // ৮) অ্যাপ
    if ($has('app_problem') || ($has('sms') && preg_match('/na\b|না|problem|সমস্যা/iu', $t))) {
        return $R("স্যার, এগুলো একটু চেক করুন:\n1. License Key এর মেয়াদ আছে কিনা — My Plans: {$base}dashboard.php#my-plan\n2. কী সঠিকভাবে কপি-পেস্ট করেছেন কিনা (বাড়তি স্পেস ছাড়া)\n3. একটা কী একটা ফোনেই চলে — অন্য ফোনে লগইন থাকলে অ্যাডমিনকে রিসেট করতে বলতে হবে\n4. ফোনে SMS পারমিশন Allow, ইন্টারনেট চালু আর Battery Optimization বন্ধ রাখুন\n5. Wallet Setup এ যে ওয়ালেটের SMS লাগবে সেটা ON আছে কিনা: {$base}dashboard.php#wallet-setup\n6. পুরনো অ্যাপ হলে নতুনটা দিন: {$base}app-release.apk\n\nতারপরও না হলে কী error দেখাচ্ছে লিখে দিন বা স্ক্রিনশট দিন — অ্যাডমিন টিমকে জানিয়ে রাখলাম 🙏", true);
    }
    if ($has('app')) {
        return $R("অ্যাপ চালু করার নিয়ম স্যার:\n1. অ্যাপ ডাউনলোড করুন: {$base}app-release.apk\n2. ইনস্টল করে SMS পারমিশন Allow দিন\n3. My Plans থেকে License Key কপি করে অ্যাপে লগইন করুন: {$base}dashboard.php#my-plan\n4. Wallet Setup এ ওয়ালেট ON করুন: {$base}dashboard.php#wallet-setup\n\nএকটা কী একটা ফোনেই চলে, একই ফোনে যতবার খুশি logout/login করা যায়। ব্যাটারি অপটিমাইজেশন বন্ধ আর ইন্টারনেট চালু রাখবেন, তাহলে SMS সাথে সাথে আপলোড হবে ✅");
    }
    if ($has('custom_sms')) return $R("নিজের ধরনের SMS (যেমন ব্যাংক বা অন্য ওয়ালেট) পড়াতে চাইলে Custom SMS এ ফরম্যাট যোগ করুন স্যার: {$base}dashboard.php#custom-sms");
    if ($has('wallet') || $has('sms')) return $R("অ্যাপ কোন কোন ওয়ালেটের (bKash, Nagad, Rocket...) SMS পড়বে সেটা Wallet Setup থেকে ON/OFF করুন স্যার: {$base}dashboard.php#wallet-setup\nযেগুলো ON করবেন শুধু সেগুলোর SMS-ই অ্যাপ পড়বে ও আপলোড করবে। নিজের SMS ফরম্যাট লাগলে: {$base}dashboard.php#custom-sms");

    // ৯) ডেভেলপার
    if ($has('brand') || $has('api')) {
        return $R("নিজের ওয়েবসাইট বা টেলিগ্রাম বটে SNZPay লাগানোর নিয়ম স্যার:\n1. Brands থেকে Brand API Key বানান: {$base}dashboard.php#brands\n2. Developer API তে সব কোড (PHP, Python, Node...) ও নিয়ম দেওয়া আছে: {$base}dashboard.php#developer\n3. কাস্টমার টাকা পাঠিয়ে TrxID দিলে API দিয়ে verify করবেন — SMS আপনার ফোনের অ্যাপ থেকে আসবে।\n\nকোথাও আটকে গেলে কোন ভাষায় সাইট বানিয়েছেন জানান, সাহায্য করব 😊" . ($has('api') && preg_match('/error|সমস্যা|problem|hosse na|হচ্ছে না|kaj kore na/iu', $t) ? "\nAPI তে error হলে error লেখাটা এখানে দিন, অ্যাডমিন টিম দেখবেন।" : ''), (bool) preg_match('/error|সমস্যা|problem|hosse na|হচ্ছে না|kaj kore na/iu', $t));
    }

    // ১০) সাধারণ
    if ($has('history')) return $R("আপনার সব লেনদেন History পেজে দেখতে পাবেন স্যার: {$base}dashboard.php#history");
    if ($has('register')) return $R("নতুন অ্যাকাউন্ট খুলতে: {$base}index.php#register\nলগইন: {$base}index.php#login");
    if ($has('who')) return $R("আমি {$agent}, SNZPay সাপোর্ট টিম থেকে 😊 ডিপোজিট, প্ল্যান, অ্যাপ, API — যেকোনো বিষয়ে সাহায্য করতে পারি। জটিল কিছু হলে অ্যাডমিন নিজেও এই চ্যাটে উত্তর দেন।");
    if ($has('about')) return $R("SNZPay হলো বাংলাদেশের অটোমেটিক পেমেন্ট ভেরিফিকেশন সিস্টেম স্যার:\n• আপনার ফোনে SNZPay অ্যাপ বসালে bKash/Nagad/Rocket এর পেমেন্ট SMS নিজে থেকে পড়ে নেয়\n• আপনার ওয়েবসাইট/বট API দিয়ে TrxID চেক করে সাথে সাথে পেমেন্ট নিশ্চিত করে — ম্যানুয়ালি চেক করতে হয় না\n• শুধু আপনার বেছে নেওয়া ওয়ালেটের SMS পড়ে, ডাটা নিরাপদ থাকে\n\nশুরু করতে: প্ল্যান কিনুন {$base}dashboard.php#plans → অ্যাপ বসান → Brand key দিয়ে সাইটে লাগান।");
    if ($has('links')) return $R("দরকারি লিংক স্যার:\n• Dashboard: {$base}dashboard.php\n• Add Funds: {$base}dashboard.php#add-fund\n• Pricing Plans: {$base}dashboard.php#plans\n• My Plans: {$base}dashboard.php#my-plan\n• History: {$base}dashboard.php#history\n• Wallet Setup: {$base}dashboard.php#wallet-setup\n• Brands: {$base}dashboard.php#brands\n• App (APK): {$base}app-release.apk\n• Developer API: {$base}dashboard.php#developer");
    if ($has('how_are')) return ['text' => ($isFirst ? "আসসালামু আলাইকুম স্যার, আমি {$agent}, SNZPay থেকে বলছি। " : '') . "আলহামদুলিল্লাহ ভালো আছি স্যার, আপনি কেমন আছেন? 😊 বলুন কিভাবে সাহায্য করতে পারি?", 'need' => false, 'close' => false];
    if ($has('ack')) return $R("জি স্যার 😊 আর কিছু জানার থাকলে বলুন, আমি আছি।");
    if ($has('greet') || $has('bye')) return ['text' => ($isFirst ? "আসসালামু আলাইকুম স্যার, আমি {$agent}, SNZPay থেকে বলছি। " : "জি স্যার, আমি আছি 😊 ") . "বলুন কিভাবে সাহায্য করতে পারি?\n(যেমন: ডিপোজিট, প্ল্যান কেনা, অ্যাপ লগইন, Brand key / API, লিংক)", 'need' => false, 'close' => false];

    return null;
}

/**
 * ইউজারের মেসেজের পর উত্তর (Brain) — রিটার্ন: 'ai' | 'closed' | 'skipped' | 'failed'
 */
function snz_chat_ai_reply(PDO $pdo, array $thread) {
    $set = snz_chat_settings();
    if (empty($set['ai_enabled']) || !empty($thread['ai_off'])) return 'skipped';
    $tid = (int) $thread['id'];
    $pause = max(0, (int) $set['admin_pause_min']);
    $adminActive = !empty($thread['admin_active_at']) && strtotime($thread['admin_active_at']) > time() - $pause * 60;

    $lq = $pdo->prepare("SELECT body FROM chat_messages WHERE thread_id = ? AND sender = 'user' ORDER BY id DESC LIMIT 2");
    $lq->execute([$tid]);
    $um = $lq->fetchAll(PDO::FETCH_COLUMN);
    $last = (string) ($um[0] ?? '');
    $prev = (string) ($um[1] ?? '');
    $cq = $pdo->prepare("SELECT COUNT(*) FROM chat_messages WHERE thread_id = ? AND sender IN ('ai','admin')");
    $cq->execute([$tid]);
    $isFirst = (int) $cq->fetchColumn() === 0;

    $r = snz_chat_brain($pdo, $thread, $last, $isFirst, $prev);
    // অ্যাডমিন কথা বললে চুপ — শুধু বিদায় জানালে সুন্দর করে বন্ধ করবে
    if ($adminActive && !($r && $r['close'])) return 'skipped';
    if (!$r) {
        // বুঝতে পারেনি → অ্যাডমিনের জন্য রেখে দেওয়া (১০ মিনিটে একবারই জানাবে)
        $pdo->prepare("UPDATE chat_threads SET needs_admin = 1 WHERE id = ?")->execute([$tid]);
        $rq = $pdo->prepare("SELECT COUNT(*) FROM chat_messages WHERE thread_id = ? AND sender = 'ai' AND body LIKE '%টিমকে পাঠিয়ে%' AND created_at > NOW() - INTERVAL 10 MINUTE");
        $rq->execute([$tid]);
        if ((int) $rq->fetchColumn()) return 'failed';
        $agent = (string) (($thread['agent_name'] ?? '') !== '' ? $thread['agent_name'] : snz_chat_agent_list($set)[0]);
        $r = ['text' => ($isFirst ? "আসসালামু আলাইকুম স্যার, আমি {$agent}, SNZPay থেকে বলছি। " : '') . "জি স্যার, আপনার বিষয়টা টিমকে পাঠিয়ে দিয়েছি — কিছুক্ষণের মধ্যেই এখানে বিস্তারিত উত্তর পাবেন 🙏\nততক্ষণ চাইলে ডিপোজিট, প্ল্যান, অ্যাপ বা লিংক নিয়ে জিজ্ঞেস করতে পারেন।", 'need' => true, 'close' => false];
    }
    usleep(random_int(600, 1500) * 1000);   // মানুষের মতো একটু সময় নিয়ে উত্তর
    snz_chat_add($pdo, $tid, 'ai', $r['text']);
    if ($r['need']) $pdo->prepare("UPDATE chat_threads SET needs_admin = 1 WHERE id = ?")->execute([$tid]);
    if ($r['close']) { snz_chat_close($pdo, $tid, 'ai'); return 'closed'; }
    return 'ai';
}
