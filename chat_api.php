<?php
// =========================================================================
// 💬 Live Chat API — dashboard.php (ইউজার) আর admin.php → Messenger (অ্যাডমিন) এখান থেকে চলে
// =========================================================================
@ini_set('display_errors', '0');
if (session_status() === PHP_SESSION_NONE) session_start();
require_once __DIR__ . '/config.php';
if (is_file(__DIR__ . '/snz_errors.php')) require_once __DIR__ . '/snz_errors.php';
require_once __DIR__ . '/snz_chat.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

function chatOut($arr, $code = 200) {
    http_response_code($code);
    echo json_encode($arr, JSON_UNESCAPED_UNICODE);
    exit;
}

$isAdmin = (($_GET['role'] ?? $_POST['role'] ?? '') === 'admin');
$a = (string) ($_REQUEST['a'] ?? 'poll');
$isPost = ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST';

if ($isAdmin) {
    if (!isset($_SESSION['is_admin']) || $_SESSION['is_admin'] !== true) chatOut(['status' => 'error', 'message' => 'logged_out'], 401);
    $userId = 0;
} else {
    if (!isset($_SESSION['user_id'])) chatOut(['status' => 'error', 'message' => 'logged_out'], 401);
    $userId = (int) $_SESSION['user_id'];
}
if ($isPost && !hash_equals((string) ($_SESSION['csrf'] ?? ''), (string) ($_POST['csrf'] ?? ''))) {
    chatOut(['status' => 'error', 'message' => 'Session expired — please refresh the page.'], 403);
}
// ছোট রেট লিমিট (ইউজার) — সেশনে রাখা
$lastSend = (float) ($_SESSION['chat_last_send'] ?? 0);
$hourKey = date('YmdH');
$hourCount = ($_SESSION['chat_hour'] ?? '') === $hourKey ? (int) ($_SESSION['chat_hour_count'] ?? 0) : 0;
if (!$isAdmin && $a === 'send') {
    if (microtime(true) - $lastSend < 1.2) chatOut(['status' => 'error', 'message' => 'একটু ধীরে লিখুন স্যার 🙂'], 429);
    if ($hourCount >= 80) chatOut(['status' => 'error', 'message' => 'অনেক মেসেজ পাঠানো হয়েছে, কিছুক্ষণ পর আবার চেষ্টা করুন।'], 429);
    $_SESSION['chat_last_send'] = microtime(true);
    $_SESSION['chat_hour'] = $hourKey;
    $_SESSION['chat_hour_count'] = $hourCount + 1;
}
session_write_close();   // AI উত্তর আসা পর্যন্ত বাকি সাইট যেন আটকে না থাকে

$pdo = getDBConnection();
if (!$pdo) chatOut(['status' => 'error', 'message' => 'Database error'], 500);
snz_chat_ensure($pdo);
$set = snz_chat_settings();

function chatAgent($thread, $set) {
    return (string) (($thread['agent_name'] ?? '') !== '' ? $thread['agent_name'] : snz_chat_agent_list($set)[0]);
}

function chatThreadRow(PDO $pdo, $id) {
    $q = $pdo->prepare("SELECT * FROM chat_threads WHERE id = ?");
    $q->execute([(int) $id]);
    return $q->fetch() ?: null;
}

// =========================================================================
// 👤 ইউজার
// =========================================================================
if (!$isAdmin) {
    if (empty($set['enabled'])) chatOut(['status' => 'off']);
    $cur = $pdo->prepare("SELECT * FROM chat_threads WHERE user_id = ? ORDER BY id DESC LIMIT 1");
    $cur->execute([$userId]);
    $thread = $cur->fetch() ?: null;

    if ($a === 'send' && $isPost) {
        $body = trim((string) ($_POST['body'] ?? ''));
        if ($body === '') chatOut(['status' => 'error', 'message' => 'Empty message']);
        $body = mb_substr($body, 0, 1500);
        if (!$thread || $thread['status'] !== 'open') {
            $cName = mb_substr(trim((string) ($_POST['name'] ?? '')), 0, 80);
            $cEmail = mb_substr(trim((string) ($_POST['email'] ?? '')), 0, 120);
            $cPhone = mb_substr(preg_replace('/[^0-9+\- ]/', '', (string) ($_POST['phone'] ?? '')), 0, 30);
            $pdo->prepare("INSERT INTO chat_threads (user_id, status, agent_name, customer_name, customer_email, customer_phone, created_at, updated_at) VALUES (?, 'open', ?, ?, ?, ?, NOW(), NOW())")
                ->execute([$userId, snz_chat_pick_agent($set), $cName ?: null, $cEmail ?: null, $cPhone ?: null]);
            $thread = chatThreadRow($pdo, $pdo->lastInsertId());
        }
        snz_chat_add($pdo, $thread['id'], 'user', $body);
        $result = snz_chat_ai_reply($pdo, $thread);
        $thread = chatThreadRow($pdo, $thread['id']);
        $after = (int) ($_POST['after'] ?? 0);
        chatOut(['status' => 'success', 'result' => $result, 'thread' => ['id' => (int) $thread['id'], 'status' => $thread['status'], 'agent' => chatAgent($thread, $set), 'admin' => snz_chat_admin_name($set)],
                 'messages' => snz_chat_messages($pdo, $thread['id'], $after)]);
    }

    if ($a === 'close' && $isPost && $thread && $thread['status'] === 'open') {
        snz_chat_add($pdo, $thread['id'], 'system', snz_chat_close_text());
        snz_chat_close($pdo, $thread['id'], 'user');
        chatOut(['status' => 'success']);
    }

    if ($a === 'seen' && $isPost && $thread) {
        $pdo->prepare("UPDATE chat_threads SET user_unread = 0 WHERE id = ?")->execute([$thread['id']]);
        chatOut(['status' => 'success']);
    }

    // poll
    $after = (int) ($_GET['after'] ?? 0);
    if (!$thread) chatOut(['status' => 'success', 'thread' => null, 'messages' => [], 'unread' => 0, 'ai' => !empty($set['ai_enabled'])]);
    // বন্ধ চ্যাট ৬ ঘণ্টা পরে আর দেখানো হবে না (নতুন চ্যাট শুরু)
    if ($thread['status'] === 'closed' && strtotime((string) $thread['closed_at']) < time() - 6 * 3600) {
        chatOut(['status' => 'success', 'thread' => null, 'messages' => [], 'unread' => 0, 'ai' => !empty($set['ai_enabled'])]);
    }
    chatOut(['status' => 'success',
             'thread' => ['id' => (int) $thread['id'], 'status' => $thread['status'], 'agent' => chatAgent($thread, $set), 'admin' => snz_chat_admin_name($set)],
             'messages' => snz_chat_messages($pdo, $thread['id'], $after),
             'unread' => (int) $thread['user_unread'],
             'ai' => !empty($set['ai_enabled']) && empty($thread['ai_off'])]);
}

// =========================================================================
// 🛡️ অ্যাডমিন (Messenger)
// =========================================================================
if ($a === 'list') {
    $q = trim((string) ($_GET['q'] ?? ''));
    $f = (string) ($_GET['f'] ?? 'all');
    $where = [];
    $args = [];
    if ($q !== '') {
        $where[] = "(u.username LIKE ? OR u.email LIKE ? OR t.customer_name LIKE ? OR t.customer_phone LIKE ? OR t.last_preview LIKE ? OR t.id = ? OR EXISTS (SELECT 1 FROM chat_messages m WHERE m.thread_id = t.id AND m.body LIKE ?))";
        $like = '%' . $q . '%';
        array_push($args, $like, $like, $like, $like, $like, (int) ltrim($q, '#'), $like);
    }
    if ($f === 'unseen') $where[] = "t.admin_unread > 0";
    if ($f === 'seen') $where[] = "t.admin_unread = 0";
    if ($f === 'need') $where[] = "t.needs_admin = 1 AND t.status = 'open'";
    if ($f === 'open') $where[] = "t.status = 'open'";
    if ($f === 'closed') $where[] = "t.status = 'closed'";
    if ($f === 'ai') $where[] = "t.last_reply_by = 'ai'";
    if ($f === 'admin') $where[] = "t.last_reply_by = 'admin'";
    $sql = "SELECT t.*, u.username, u.email FROM chat_threads t LEFT JOIN users u ON u.id = t.user_id"
         . ($where ? " WHERE " . implode(' AND ', $where) : '') . " ORDER BY t.updated_at DESC, t.id DESC LIMIT 150";
    $st = $pdo->prepare($sql);
    $st->execute($args);
    $rows = [];
    foreach ($st->fetchAll() as $r) {
        $rows[] = [
            'id' => (int) $r['id'], 'user_id' => (int) $r['user_id'],
            'username' => (string) (($r['customer_name'] ?? '') !== '' ? $r['customer_name'] : ($r['username'] ?? 'Deleted user')), 'email' => (string) ($r['email'] ?? ''),
            'status' => $r['status'], 'preview' => (string) $r['last_preview'], 'last_sender' => (string) $r['last_sender'],
            'reply_by' => (string) $r['last_reply_by'], 'unread' => (int) $r['admin_unread'], 'need' => (int) $r['needs_admin'],
            'ai_off' => (int) $r['ai_off'],
            'time' => $r['updated_at'] ? (date('Y-m-d') === date('Y-m-d', strtotime($r['updated_at'])) ? date('h:i A', strtotime($r['updated_at'])) : date('d M', strtotime($r['updated_at']))) : '',
        ];
    }
    $tot = $pdo->query("SELECT COUNT(*) FROM chat_threads WHERE admin_unread > 0")->fetchColumn();
    chatOut(['status' => 'success', 'threads' => $rows, 'unseen_total' => (int) $tot]);
}

if ($a === 'test') {
    // ⚙️ Settings → "Test AI": আসল কী দিয়ে একবার চেষ্টা, ভুল থাকলে ঠিক কারণটা দেখাবে
    $r = snz_chat_call_ai($set, 'You are a test. Reply with: OK', [['role' => 'user', 'content' => 'Say OK']]);
    snz_chat_note_status($r['ok'], $r['error']);
    chatOut($r['ok'] ? ['status' => 'success', 'message' => 'AI is working ✅ Reply: ' . mb_substr($r['text'], 0, 80)] : ['status' => 'error', 'message' => $r['error']]);
}

if ($a === 'unseen') {
    chatOut(['status' => 'success', 'unseen_total' => (int) $pdo->query("SELECT COUNT(*) FROM chat_threads WHERE admin_unread > 0")->fetchColumn()]);
}

$tid = (int) ($_REQUEST['id'] ?? 0);
$thread = $tid ? chatThreadRow($pdo, $tid) : null;
if (!$thread) chatOut(['status' => 'error', 'message' => 'Chat not found'], 404);

if ($a === 'thread') {
    $after = (int) ($_GET['after'] ?? 0);
    if ((int) $thread['admin_unread'] > 0) $pdo->prepare("UPDATE chat_threads SET admin_unread = 0 WHERE id = ?")->execute([$tid]);
    $u = $pdo->prepare("SELECT username, email, balance FROM users WHERE id = ?");
    $u->execute([(int) $thread['user_id']]);
    $ur = $u->fetch() ?: ['username' => 'Deleted user', 'email' => '', 'balance' => 0];
    chatOut(['status' => 'success',
             'thread' => ['id' => $tid, 'status' => $thread['status'], 'need' => (int) $thread['needs_admin'], 'ai_off' => (int) $thread['ai_off'],
                          'username' => $ur['username'], 'email' => $ur['email'], 'balance' => number_format((float) $ur['balance'], 2),
                          'closed_by' => (string) $thread['closed_by'], 'user_id' => (int) $thread['user_id'],
                          'agent' => chatAgent($thread, $set), 'admin_name' => snz_chat_admin_name($set),
                          'name' => (string) ($thread['customer_name'] ?? ''), 'phone' => (string) ($thread['customer_phone'] ?? ''), 'cemail' => (string) ($thread['customer_email'] ?? '')],
             'messages' => snz_chat_messages($pdo, $tid, $after, 500)]);
}

if (!$isPost) chatOut(['status' => 'error', 'message' => 'Bad request'], 400);

if ($a === 'reply') {
    $body = mb_substr(trim((string) ($_POST['body'] ?? '')), 0, 3000);
    if ($body === '') chatOut(['status' => 'error', 'message' => 'Empty message']);
    if ($thread['status'] !== 'open') {
        $pdo->prepare("UPDATE chat_threads SET status = 'open', closed_at = NULL, closed_by = NULL WHERE id = ?")->execute([$tid]);
    }
    snz_chat_add($pdo, $tid, 'admin', $body);
    $pdo->prepare("UPDATE chat_threads SET admin_active_at = NOW(), needs_admin = 0, admin_unread = 0 WHERE id = ?")->execute([$tid]);
    chatOut(['status' => 'success', 'messages' => snz_chat_messages($pdo, $tid, (int) ($_POST['after'] ?? 0), 500)]);
}

if ($a === 'close') {
    if ($thread['status'] === 'open') {
        snz_chat_add($pdo, $tid, 'admin', snz_chat_close_text());
        snz_chat_close($pdo, $tid, 'admin');
    }
    chatOut(['status' => 'success']);
}

if ($a === 'ai_toggle') {
    $pdo->prepare("UPDATE chat_threads SET ai_off = 1 - ai_off WHERE id = ?")->execute([$tid]);
    chatOut(['status' => 'success', 'ai_off' => 1 - (int) $thread['ai_off']]);
}

if ($a === 'mark_unseen') {
    $pdo->prepare("UPDATE chat_threads SET admin_unread = GREATEST(admin_unread, 1) WHERE id = ?")->execute([$tid]);
    chatOut(['status' => 'success']);
}

if ($a === 'delete') {
    $pdo->prepare("DELETE FROM chat_messages WHERE thread_id = ?")->execute([$tid]);
    $pdo->prepare("DELETE FROM chat_threads WHERE id = ?")->execute([$tid]);
    chatOut(['status' => 'success']);
}

chatOut(['status' => 'error', 'message' => 'Unknown action'], 400);
