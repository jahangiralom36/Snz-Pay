<?php
// =========================================================================
// 💬 Live Chat ভাসমান বাটন + চ্যাট বক্স (শুধু dashboard.php তে include হয়)
// দরকার: $CSRF, $user
// =========================================================================
if (!function_exists('snz_chat_settings')) require_once __DIR__ . '/snz_chat.php';
$snzChatSet = snz_chat_settings();
if (empty($snzChatSet['enabled'])) return;
$snzCU = [
    'name'  => (string) ($user['username'] ?? ''),
    'email' => (string) ($user['email'] ?? ''),
    'phone' => (string) ($user['phone'] ?? ''),
];
?>
<style>
#snzChatFab,#snzChatPanel,#snzChatToast{--sc-g:#5E9A6E;--sc-gd:#4C8A5D;--sc-bg:#F1F8F2;--sc-line:#E3E8EF;--sc-tx:#1F2937;--sc-mu:#9AA3B2;font-family:'Inter','Hind Siliguri',system-ui,-apple-system,sans-serif}
#snzChatFab{position:fixed;right:18px;bottom:18px;z-index:70;width:60px;height:60px;border-radius:50%;border:0;cursor:pointer;color:#fff;font-size:25px;background:var(--sc-g);
  box-shadow:0 10px 28px rgba(76,138,93,.45);display:flex;align-items:center;justify-content:center;transition:transform .2s ease}
#snzChatFab:hover{transform:scale(1.07)}
#snzChatFab .ico-close{display:none}#snzChatFab.open .ico-close{display:block}#snzChatFab.open .ico-chat{display:none}
#snzChatFab:before{content:"";position:absolute;inset:0;border-radius:50%;border:2px solid var(--sc-g);animation:scRing 2.4s infinite;pointer-events:none}
#snzChatFab.open:before{display:none}
@keyframes scRing{0%{transform:scale(1);opacity:.7}100%{transform:scale(1.55);opacity:0}}
#snzChatBadge{position:absolute;top:-3px;right:-3px;min-width:22px;height:22px;padding:0 6px;border-radius:11px;background:#EF4444;color:#fff;font:800 11px/22px system-ui,sans-serif;border:2px solid #fff;display:none}

#snzChatPanel{position:fixed;right:18px;bottom:90px;z-index:70;width:370px;max-width:calc(100vw - 24px);height:600px;max-height:calc(100vh - 110px);background:#fff;border-radius:26px;
  box-shadow:0 24px 60px rgba(0,0,0,.35);display:flex;flex-direction:column;overflow:hidden;opacity:0;transform:translateY(20px) scale(.96);pointer-events:none;transition:all .25s cubic-bezier(.2,.9,.3,1.2);color:var(--sc-tx)}
#snzChatPanel.show{opacity:1;transform:none;pointer-events:auto}
.sc-head{background:var(--sc-g);color:#fff;padding:16px 18px;display:flex;align-items:center;gap:13px;flex-shrink:0}
.sc-hic{width:46px;height:46px;border-radius:14px;background:rgba(255,255,255,.18);display:flex;align-items:center;justify-content:center;font-size:22px;flex-shrink:0;font-weight:900}
.sc-ht{font-size:17px;font-weight:800;display:flex;align-items:center;gap:7px;line-height:1.2}
.sc-ht i.dot{width:9px;height:9px;border-radius:50%;background:#4ADE80;display:inline-block;box-shadow:0 0 0 3px rgba(74,222,128,.25)}
.sc-hs{font-size:12.5px;opacity:.85;margin-top:3px}
.sc-min{margin-left:auto;background:none;border:0;color:#fff;font-size:18px;cursor:pointer;width:34px;height:34px;border-radius:10px}
.sc-min:hover{background:rgba(255,255,255,.15)}
.sc-pages{flex:1;min-height:0;position:relative;background:var(--sc-bg)}
.sc-page{position:absolute;inset:0;display:none;flex-direction:column;min-height:0}
.sc-page.on{display:flex}

/* Home */
.sc-home{flex:1;overflow-y:auto;padding:16px}
.sc-field{position:relative;margin-bottom:11px}
.sc-field i{position:absolute;left:15px;top:15px;color:#B5BDC9;font-size:15px;width:18px;text-align:center}
.sc-field input,.sc-field textarea{width:100%;box-sizing:border-box;background:#fff;border:1px solid var(--sc-line);border-radius:16px;padding:13px 14px 13px 44px;font-size:14px;color:var(--sc-tx);outline:none;font-family:inherit;box-shadow:0 1px 2px rgba(16,24,40,.04)}
.sc-field textarea{min-height:92px;resize:none}
.sc-field input:focus,.sc-field textarea:focus{border-color:var(--sc-g);box-shadow:0 0 0 3px rgba(94,154,110,.15)}
.sc-btn{width:100%;border:0;border-radius:16px;background:var(--sc-g);color:#fff;font-weight:800;font-size:15px;padding:14px;cursor:pointer;display:flex;align-items:center;justify-content:center;gap:9px;font-family:inherit}
.sc-btn:hover{background:var(--sc-gd)}.sc-btn:disabled{opacity:.6;cursor:default}
.sc-err{color:#DC2626;font-size:12px;margin:-4px 2px 10px;display:none}
.sc-card{background:#fff;border:1px solid var(--sc-line);border-radius:18px;padding:14px;display:flex;gap:12px;align-items:center;cursor:pointer;margin-bottom:12px}
.sc-card:hover{border-color:var(--sc-g)}
.sc-av{width:42px;height:42px;border-radius:50%;background:var(--sc-g);color:#fff;display:flex;align-items:center;justify-content:center;font-weight:900;font-size:16px;flex-shrink:0;position:relative}
.sc-av:after{content:"";position:absolute;right:0;bottom:0;width:11px;height:11px;border-radius:50%;background:#4ADE80;border:2px solid #fff}
.sc-qs{display:flex;flex-wrap:wrap;gap:7px;margin-top:4px}
.sc-qs button{background:#fff;border:1px solid var(--sc-line);color:var(--sc-gd);font-weight:700;font-size:12.5px;border-radius:999px;padding:8px 12px;cursor:pointer;font-family:inherit}
.sc-qs button:hover{border-color:var(--sc-g)}
.sc-lbl{font-size:12px;font-weight:800;color:#6B7280;margin:6px 2px 8px}

/* Messages */
.sc-body{flex:1;overflow-y:auto;padding:14px;display:flex;flex-direction:column;gap:10px;scroll-behavior:smooth}
.sc-row{display:flex;gap:8px;max-width:86%;animation:scPop .32s cubic-bezier(.2,.9,.3,1.3)}
.sc-row.me{align-self:flex-end;flex-direction:row-reverse}
.sc-row .sc-av{width:30px;height:30px;font-size:12px;margin-top:18px}.sc-row .sc-av:after{display:none}
@keyframes scPop{from{opacity:0;transform:translateY(8px) scale(.94)}to{opacity:1;transform:none}}
.sc-who{font-size:11px;font-weight:800;color:var(--sc-gd);margin:0 6px 4px}
.sc-b{padding:10px 13px;border-radius:18px;font-size:13.5px;line-height:1.55;overflow-wrap:anywhere}
.me .sc-b{background:var(--sc-g);color:#fff;border-bottom-right-radius:6px}
.them .sc-b{background:#fff;color:var(--sc-tx);border:1px solid var(--sc-line);border-bottom-left-radius:6px}
.sc-b a{color:#2563EB;text-decoration:underline;word-break:break-all}.me .sc-b a{color:#fff}
.sc-t{font-size:10px;color:var(--sc-mu);margin:3px 8px 0}
.me .sc-t{text-align:right}
.sc-sys{align-self:center;max-width:92%;text-align:center;font-size:12px;color:#4B5563;background:#E7F2E9;padding:8px 13px;border-radius:14px;white-space:pre-line}
.sc-typing{align-self:flex-start;background:#fff;border:1px solid var(--sc-line);border-radius:18px;border-bottom-left-radius:6px;padding:12px 14px;display:none;gap:4px;margin-left:38px}
.sc-typing span{width:7px;height:7px;border-radius:50%;background:var(--sc-g);display:inline-block;animation:scDot 1.2s infinite}
.sc-typing span:nth-child(2){animation-delay:.15s}.sc-typing span:nth-child(3){animation-delay:.3s}
@keyframes scDot{0%,60%,100%{opacity:.3;transform:translateY(0)}30%{opacity:1;transform:translateY(-4px)}}
.sc-empty{margin:auto;text-align:center;color:var(--sc-mu);font-size:13px;padding:20px}
.sc-empty i{font-size:34px;color:#C7D7CB;display:block;margin-bottom:8px}
.sc-foot{padding:10px 12px;background:#fff;border-top:1px solid var(--sc-line);flex-shrink:0}
.sc-form{display:flex;gap:8px;align-items:flex-end}
.sc-form textarea{flex:1;resize:none;max-height:110px;min-height:44px;box-sizing:border-box;background:var(--sc-bg);border:1px solid var(--sc-line);border-radius:16px;color:var(--sc-tx);font-size:14px;padding:11px 13px;outline:none;font-family:inherit;line-height:1.4}
.sc-form textarea:focus{border-color:var(--sc-g)}
.sc-send{width:44px;height:44px;flex-shrink:0;border-radius:50%;border:0;cursor:pointer;background:var(--sc-g);color:#fff;font-size:16px}
.sc-send:disabled{opacity:.5}
.sc-closed{display:none;text-align:center}
.sc-closed p{color:#6B7280;font-size:12.5px;margin:2px 0 8px}
.sc-end{display:block;margin:7px auto 0;background:none;border:0;color:var(--sc-mu);font-size:11px;cursor:pointer;font-family:inherit}
.sc-end:hover{color:#DC2626}

/* Tabs */
.sc-tabs{display:grid;grid-template-columns:1fr 1fr;background:#fff;border-top:1px solid var(--sc-line);flex-shrink:0}
.sc-tab{border:0;background:none;padding:10px 0 9px;display:flex;flex-direction:column;align-items:center;gap:4px;color:var(--sc-mu);font-weight:700;font-size:13px;cursor:pointer;position:relative;font-family:inherit}
.sc-tab i{font-size:19px}
.sc-tab.on{color:var(--sc-g)}
.sc-tab .n{position:absolute;top:5px;left:calc(50% + 6px);background:#EF4444;color:#fff;font-size:10px;font-weight:800;border-radius:9px;min-width:17px;height:17px;line-height:17px;padding:0 4px;display:none}

#snzChatToast{position:fixed;right:18px;bottom:92px;z-index:71;width:310px;max-width:calc(100vw - 36px);background:#fff;border-radius:20px;padding:12px 14px;box-shadow:0 18px 45px rgba(0,0,0,.3);border-left:4px solid var(--sc-g);
  display:flex;gap:11px;align-items:flex-start;cursor:pointer;opacity:0;transform:translateY(16px) scale(.9);pointer-events:none;transition:all .35s cubic-bezier(.2,.9,.3,1.35);color:var(--sc-tx)}
#snzChatToast.show{opacity:1;transform:none;pointer-events:auto}
#snzChatToast .tt{font-size:13px;font-weight:800}
#snzChatToast .tm{color:#4B5563;font-size:12.5px;margin-top:3px;display:-webkit-box;-webkit-line-clamp:3;-webkit-box-orient:vertical;overflow:hidden}
#snzChatToast .tx{margin-left:auto;color:var(--sc-mu);font-size:14px;background:none;border:0;cursor:pointer;padding:0 0 0 6px}
@media (max-width:520px){#snzChatPanel{right:8px;left:8px;width:auto;max-width:none;bottom:82px;height:calc(100vh - 96px);height:calc(100dvh - 96px);max-height:none}#snzChatFab{right:14px;bottom:14px;width:56px;height:56px}#snzChatToast{right:14px;left:14px;width:auto;bottom:80px}}
</style>

<div id="snzChatToast" onclick="snzChatOpen('msgs')">
    <div class="sc-av" id="snzToastAv">S</div>
    <div style="min-width:0">
        <div class="tt" id="snzToastTitle">New reply</div>
        <div class="tm" id="snzToastMsg"></div>
    </div>
    <button class="tx" onclick="event.stopPropagation();snzToastHide()" aria-label="Close"><i class="fa-solid fa-xmark"></i></button>
</div>

<div id="snzChatPanel" role="dialog" aria-label="Live chat">
    <div class="sc-head">
        <div class="sc-hic" id="scHeadIc"><i class="fa-regular fa-face-smile"></i></div>
        <div style="min-width:0">
            <div class="sc-ht">We are online <i class="dot"></i></div>
            <div class="sc-hs" id="scHeadSub">Reply within 1-5 minutes</div>
        </div>
        <button type="button" class="sc-min" title="Minimize" onclick="snzChatToggle(false)"><i class="fa-solid fa-chevron-down"></i></button>
    </div>

    <div class="sc-pages">
        <!-- HOME -->
        <div class="sc-page on" id="scPageHome">
            <div class="sc-home">
                <div id="scContinue" style="display:none">
                    <div class="sc-card" onclick="snzChatTab('msgs')">
                        <div class="sc-av" id="scContAv">S</div>
                        <div style="min-width:0;flex:1">
                            <div style="font-weight:800;font-size:14px" id="scContName">SNZPay Support</div>
                            <div style="font-size:12.5px;color:#6B7280;white-space:nowrap;overflow:hidden;text-overflow:ellipsis" id="scContMsg">Continue the conversation</div>
                        </div>
                        <i class="fa-solid fa-chevron-right" style="color:#B5BDC9"></i>
                    </div>
                    <div class="sc-lbl">Quick questions</div>
                    <div class="sc-qs">
                        <button type="button" onclick="snzChatQuick(this)">ডিপোজিট এখনো আসেনি</button>
                        <button type="button" onclick="snzChatQuick(this)">প্ল্যান কিভাবে কিনব?</button>
                        <button type="button" onclick="snzChatQuick(this)">অ্যাপে লগইন হচ্ছে না</button>
                        <button type="button" onclick="snzChatQuick(this)">সব লিংক দিন</button>
                    </div>
                </div>
                <form id="scStartForm" onsubmit="snzChatStart(event)">
                    <div class="sc-field"><i class="fa-solid fa-user"></i><input type="text" id="scName" maxlength="80" placeholder="Your name" value="<?= htmlspecialchars($snzCU['name']) ?>" required></div>
                    <div class="sc-field"><i class="fa-solid fa-envelope"></i><input type="email" id="scEmail" maxlength="120" placeholder="Email address" value="<?= htmlspecialchars($snzCU['email']) ?>"></div>
                    <div class="sc-field"><i class="fa-solid fa-phone"></i><input type="tel" id="scPhone" maxlength="20" placeholder="Phone number" value="<?= htmlspecialchars($snzCU['phone']) ?>"></div>
                    <div class="sc-field"><i class="fa-solid fa-message"></i><textarea id="scFirst" maxlength="1500" placeholder="How can we help you?" required></textarea></div>
                    <div class="sc-err" id="scErr"></div>
                    <button type="submit" class="sc-btn" id="scStartBtn"><i class="fa-solid fa-paper-plane"></i> Start Chat</button>
                </form>
            </div>
        </div>

        <!-- MESSAGES -->
        <div class="sc-page" id="scPageMsgs">
            <div class="sc-body" id="snzChatBody">
                <div class="sc-empty" id="scEmpty"><i class="fa-regular fa-comments"></i>No messages yet.<br>Start a chat from Home.</div>
                <div class="sc-typing" id="snzChatTyping"><span></span><span></span><span></span></div>
            </div>
            <div class="sc-foot" id="scFoot" style="display:none">
                <form class="sc-form" id="snzChatForm" onsubmit="snzChatSend(event)">
                    <textarea id="snzChatInput" rows="1" maxlength="1500" placeholder="মেসেজ লিখুন..." onkeydown="if(event.key==='Enter'&&!event.shiftKey){event.preventDefault();snzChatSend(event);}" oninput="this.style.height='auto';this.style.height=Math.min(this.scrollHeight,110)+'px'"></textarea>
                    <button type="submit" class="sc-send" id="snzChatSendBtn" aria-label="Send"><i class="fa-solid fa-paper-plane"></i></button>
                </form>
                <button type="button" class="sc-end" id="snzChatEndBtn" onclick="snzChatEnd()"><i class="fa-solid fa-power-off"></i> End chat</button>
                <div class="sc-closed" id="snzChatClosed">
                    <p><i class="fa-solid fa-circle-check" style="color:#5E9A6E"></i> এই চ্যাটটি বন্ধ হয়েছে।</p>
                    <button type="button" class="sc-btn" onclick="snzChatNew()"><i class="fa-solid fa-plus"></i> নতুন চ্যাট শুরু করুন</button>
                </div>
            </div>
        </div>
    </div>

    <div class="sc-tabs">
        <button type="button" class="sc-tab on" data-t="home" onclick="snzChatTab('home')"><i class="fa-solid fa-house"></i> Home</button>
        <button type="button" class="sc-tab" data-t="msgs" onclick="snzChatTab('msgs')"><i class="fa-regular fa-message"></i> Messages <span class="n" id="scTabBadge">0</span></button>
    </div>
</div>

<button type="button" id="snzChatFab" onclick="snzChatToggle()" aria-label="Live chat">
    <i class="fa-solid fa-comment-dots ico-chat"></i><i class="fa-solid fa-xmark ico-close"></i>
    <span id="snzChatBadge">0</span>
</button>

<script>
(function () {
    const API = 'chat_api.php';
    const CSRF = <?= json_encode($CSRF) ?>;
    let AGENT = '', ADMIN = '', tab = 'home';
    let open = false, lastId = 0, threadStatus = null, threadId = 0, ignoreThread = 0, tick = 0, busy = false, sending = false, firstLoad = true, toastTimer = null, unread = 0;
    const seen = new Set();
    const $ = id => document.getElementById(id);
    const panel = $('snzChatPanel'), body = $('snzChatBody'), typing = $('snzChatTyping');

    function esc(v) { return String(v == null ? '' : v).replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c])); }
    function fmt(t) {
        let h = esc(t);
        h = h.replace(/\*\*(.+?)\*\*/g, '<b>$1</b>');
        h = h.replace(/(https?:\/\/[^\s<]+)/g, '<a href="$1" target="_blank" rel="noopener">$1</a>');
        return h.replace(/\n/g, '<br>');
    }
    function scrollDown() { body.scrollTop = body.scrollHeight + 500; }
    function nameOf(m) { return m.sender === 'admin' ? (ADMIN || AGENT || 'SNZPay Support') : (AGENT || 'SNZPay Support'); }
    const ini = n => (n || 'S').trim().charAt(0).toUpperCase();

    function render(m) {
        if (seen.has(m.id)) return;
        seen.add(m.id);
        lastId = Math.max(lastId, m.id);
        $('scEmpty').style.display = 'none';
        let el;
        if (m.sender === 'system') {
            el = document.createElement('div');
            el.className = 'sc-sys';
            el.textContent = m.body;
        } else {
            const me = m.sender === 'user', nm = nameOf(m);
            el = document.createElement('div');
            el.className = 'sc-row ' + (me ? 'me' : 'them');
            el.innerHTML = (me ? '' : '<div class="sc-av">' + esc(ini(nm)) + '</div>')
                + '<div style="min-width:0">' + (me ? '' : '<div class="sc-who">' + esc(nm) + '</div>')
                + '<div class="sc-b">' + fmt(m.body) + '</div><div class="sc-t">' + esc(m.time) + '</div></div>';
            if (!me) { $('scContMsg').textContent = m.body; }
        }
        body.insertBefore(el, typing);
    }

    function setPeople(t) {
        if (!t) return;
        AGENT = t.agent || AGENT; ADMIN = t.admin || ADMIN;
        if (AGENT) {
            $('scHeadSub').textContent = AGENT + ' • SNZPay Support';
            $('scHeadIc').textContent = ini(AGENT);
            $('scContName').textContent = AGENT + ' • SNZPay Support';
            $('scContAv').textContent = ini(AGENT);
        }
    }

    function setStatus(st) {
        threadStatus = st;
        const hasChat = st === 'open' || st === 'closed' || st === 'sending';
        $('scContinue').style.display = st === 'open' ? 'block' : 'none';
        $('scStartForm').style.display = st === 'open' ? 'none' : 'block';
        $('scFoot').style.display = hasChat ? 'block' : 'none';
        $('snzChatForm').style.display = st === 'closed' ? 'none' : 'flex';
        $('snzChatEndBtn').style.display = st === 'open' ? 'block' : 'none';
        $('snzChatClosed').style.display = st === 'closed' ? 'block' : 'none';
    }

    function setBadge(n) {
        unread = n;
        const b = $('snzChatBadge'), tb = $('scTabBadge');
        b.textContent = tb.textContent = n > 9 ? '9+' : n;
        b.style.display = n > 0 && !open ? 'block' : 'none';
        tb.style.display = n > 0 && tab !== 'msgs' ? 'block' : 'none';
    }

    function toast(m) {
        const nm = m.sender === 'system' ? 'SNZPay Support' : nameOf(m);
        $('snzToastTitle').textContent = nm + ' • SNZPay Support';
        $('snzToastMsg').textContent = m.body;
        $('snzToastAv').textContent = ini(nm);
        $('snzChatToast').classList.add('show');
        clearTimeout(toastTimer);
        toastTimer = setTimeout(snzToastHide, 8000);
    }
    window.snzToastHide = () => $('snzChatToast').classList.remove('show');

    window.snzChatTab = function (t) {
        tab = t;
        document.querySelectorAll('.sc-tab').forEach(b => b.classList.toggle('on', b.dataset.t === t));
        $('scPageHome').classList.toggle('on', t === 'home');
        $('scPageMsgs').classList.toggle('on', t === 'msgs');
        if (t === 'msgs') { scrollDown(); if (unread) markSeen(); setTimeout(() => { if (threadStatus === 'open') $('snzChatInput').focus(); }, 150); }
        setBadge(unread);
    };

    async function poll() {
        if (busy || sending) return;
        busy = true;
        try {
            const r = await fetch(API + '?a=poll&after=' + lastId, { credentials: 'same-origin', cache: 'no-store' });
            if (!r.ok) return;
            const d = await r.json();
            if (d.status === 'off') { $('snzChatFab').style.display = 'none'; return; }
            if (!d.thread || d.thread.id === ignoreThread) {
                if (!d.thread && threadStatus !== null && threadStatus !== 'new') resetView();
                return;
            }
            if (threadId && d.thread.id !== threadId) resetView();
            threadId = d.thread.id;
            setPeople(d.thread);
            let newest = null;
            d.messages.forEach(m => { if (!seen.has(m.id)) { render(m); if (m.sender !== 'user') newest = m; } });
            setStatus(d.thread.status);
            if (open && tab === 'msgs') {
                if (d.messages.length) scrollDown();
                if (d.unread > 0) markSeen();
            } else {
                setBadge(d.unread);
                if (newest && !firstLoad && !(open && tab === 'msgs')) toast(newest);
            }
        } catch (e) {
        } finally { busy = false; firstLoad = false; }
    }

    function resetView() {
        body.querySelectorAll('.sc-row,.sc-sys').forEach(e => e.remove());
        seen.clear(); lastId = 0; threadId = 0; AGENT = '';
        $('scHeadSub').textContent = 'Reply within 1-5 minutes';
        $('scHeadIc').innerHTML = '<i class="fa-regular fa-face-smile"></i>';
        $('scEmpty').style.display = '';
        setStatus(null);
    }

    function markSeen() {
        setBadge(0);
        const f = new FormData(); f.append('a', 'seen'); f.append('csrf', CSRF);
        fetch(API, { method: 'POST', body: f, credentials: 'same-origin' }).catch(() => {});
    }

    window.snzChatToggle = function (force) {
        open = typeof force === 'boolean' ? force : !open;
        panel.classList.toggle('show', open);
        $('snzChatFab').classList.toggle('open', open);
        if (open) { snzToastHide(); poll(); }
        setBadge(unread);
    };
    window.snzChatOpen = function (t) {
        window.snzChatToggle(true);
        snzChatTab(t || (threadStatus === 'open' ? 'msgs' : 'home'));
    };

    async function sendText(text, extra) {
        if (!text || sending) return false;
        sending = true;
        $('snzChatSendBtn').disabled = true;
        $('scStartBtn').disabled = true;
        snzChatTab('msgs');
        $('scEmpty').style.display = 'none';
        const tmp = document.createElement('div');
        tmp.className = 'sc-row me';
        tmp.innerHTML = '<div style="min-width:0"><div class="sc-b">' + fmt(text) + '</div><div class="sc-t">Sending…</div></div>';
        body.insertBefore(tmp, typing);
        if (threadStatus !== 'open') setStatus('sending');
        setTimeout(() => { if (sending) { typing.style.display = 'flex'; scrollDown(); } }, 700);
        scrollDown();
        let ok = false;
        try {
            const f = new FormData();
            f.append('a', 'send'); f.append('csrf', CSRF); f.append('body', text); f.append('after', lastId);
            Object.keys(extra || {}).forEach(k => f.append(k, extra[k]));
            const r = await fetch(API, { method: 'POST', body: f, credentials: 'same-origin' });
            const d = await r.json().catch(() => ({}));
            tmp.remove();
            if (d.status !== 'success') {
                $('snzChatInput').value = text;
                const er = document.createElement('div'); er.className = 'sc-sys'; er.textContent = d.message || 'মেসেজ পাঠানো যায়নি, আবার চেষ্টা করুন।';
                body.insertBefore(er, typing); setTimeout(() => er.remove(), 5000);
                if (!threadId) setStatus(null);
            } else {
                if (d.thread.id !== threadId) { body.querySelectorAll('.sc-row,.sc-sys').forEach(e => e.remove()); seen.clear(); }
                threadId = d.thread.id; ignoreThread = 0;
                setPeople(d.thread);
                (d.messages || []).forEach(render);
                setStatus(d.thread.status);
                markSeen();
                ok = true;
            }
        } catch (err) {
            tmp.querySelector('.sc-t').textContent = 'Not sent — check internet';
            $('snzChatInput').value = text;
        } finally {
            typing.style.display = 'none';
            sending = false;
            $('snzChatSendBtn').disabled = false;
            $('scStartBtn').disabled = false;
            scrollDown();
        }
        return ok;
    }

    window.snzChatStart = async function (e) {
        e.preventDefault();
        const name = $('scName').value.trim(), msg = $('scFirst').value.trim(), phone = $('scPhone').value.trim();
        const err = $('scErr');
        if (!name || !msg) { err.textContent = 'নাম আর মেসেজ লিখুন।'; err.style.display = 'block'; return; }
        if (phone && !/^[0-9+\-\s]{6,20}$/.test(phone)) { err.textContent = 'সঠিক ফোন নম্বর দিন।'; err.style.display = 'block'; return; }
        err.style.display = 'none';
        if (await sendText(msg, { name: name, email: $('scEmail').value.trim(), phone: phone })) $('scFirst').value = '';
    };

    window.snzChatQuick = function (btn) { sendText(btn.textContent); };

    window.snzChatSend = function (e) {
        if (e) e.preventDefault();
        const inp = $('snzChatInput');
        const text = inp.value.trim();
        if (!text) return;
        inp.value = ''; inp.style.height = 'auto';
        sendText(text);
    };

    window.snzChatEnd = async function () {
        if (!confirm('চ্যাটটি বন্ধ করবেন?')) return;
        const f = new FormData(); f.append('a', 'close'); f.append('csrf', CSRF);
        await fetch(API, { method: 'POST', body: f, credentials: 'same-origin' }).catch(() => {});
        poll();
    };

    window.snzChatNew = function () { ignoreThread = threadId; resetView(); setStatus('new'); snzChatTab('home'); $('scFirst').focus(); };

    setStatus(null);
    poll();
    // খোলা থাকলে প্রতি ৩.৫ সেকেন্ডে, বন্ধ থাকলে প্রতি ~১০ সেকেন্ডে নতুন উত্তর দেখা
    setInterval(() => { tick++; if (!document.hidden && (open || tick % 3 === 0)) poll(); }, 3500);
})();
</script>
