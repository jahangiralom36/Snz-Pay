<?php
// =========================================================================
// 💬 Live Chat ভাসমান বাটন + চ্যাট বক্স (শুধু dashboard.php তে include হয়)
// দরকার: $CSRF, $user
// =========================================================================
if (!function_exists('snz_chat_settings')) require_once __DIR__ . '/snz_chat.php';
$snzChatSet = snz_chat_settings();
if (empty($snzChatSet['enabled'])) return;
$snzChatName = htmlspecialchars((string) $snzChatSet['bot_name']);
$snzChatUser = htmlspecialchars((string) ($user['username'] ?? ''));
?>
<style>
#snzChatFab{position:fixed;right:18px;bottom:18px;z-index:70;width:60px;height:60px;border-radius:50%;border:0;cursor:pointer;color:#061535;font-size:24px;
  background:linear-gradient(135deg,#00D9FF,#00D89A);box-shadow:0 10px 30px rgba(0,217,255,.35),0 0 0 0 rgba(0,217,255,.5);display:flex;align-items:center;justify-content:center;transition:transform .2s ease;animation:snzFabPulse 2.6s infinite}
#snzChatFab:hover{transform:scale(1.07)}
#snzChatFab.open{animation:none}
#snzChatFab .ico-close{display:none}#snzChatFab.open .ico-close{display:block}#snzChatFab.open .ico-chat{display:none}
@keyframes snzFabPulse{0%{box-shadow:0 10px 30px rgba(0,217,255,.35),0 0 0 0 rgba(0,217,255,.45)}70%{box-shadow:0 10px 30px rgba(0,217,255,.35),0 0 0 16px rgba(0,217,255,0)}100%{box-shadow:0 10px 30px rgba(0,217,255,.35),0 0 0 0 rgba(0,217,255,0)}}
#snzChatBadge{position:absolute;top:-3px;right:-3px;min-width:22px;height:22px;padding:0 6px;border-radius:11px;background:#F43F5E;color:#fff;font:800 11px/22px system-ui,sans-serif;border:2px solid #061535;display:none}
#snzChatPanel{position:fixed;right:18px;bottom:90px;z-index:70;width:380px;max-width:calc(100vw - 24px);height:580px;max-height:calc(100vh - 110px);background:#06173a;border:1px solid #174396;border-radius:24px;
  box-shadow:0 25px 60px rgba(0,0,0,.55);display:flex;flex-direction:column;overflow:hidden;opacity:0;transform:translateY(20px) scale(.96);pointer-events:none;transition:all .25s cubic-bezier(.2,.9,.3,1.2);font-family:inherit}
#snzChatPanel.show{opacity:1;transform:none;pointer-events:auto}
.snzc-head{background:linear-gradient(135deg,#0b2a6b,#081e4b);padding:14px 16px;display:flex;align-items:center;gap:12px;border-bottom:1px solid #123982}
.snzc-av{width:42px;height:42px;border-radius:14px;background:linear-gradient(135deg,#00D9FF,#00D89A);color:#061535;display:flex;align-items:center;justify-content:center;font-size:19px;flex-shrink:0;position:relative}
.snzc-av:after{content:"";position:absolute;right:-2px;bottom:-2px;width:12px;height:12px;border-radius:50%;background:#22C55E;border:2px solid #0b2a6b}
.snzc-title{color:#fff;font-weight:800;font-size:14px;line-height:1.2}
.snzc-sub{color:#7dd3fc;font-size:11px;margin-top:2px}
.snzc-hbtn{margin-left:auto;display:flex;gap:6px}
.snzc-hbtn button{width:32px;height:32px;border-radius:10px;border:1px solid #1d4ea8;background:#0a2560;color:#cbd5e1;cursor:pointer;font-size:13px}
.snzc-hbtn button:hover{color:#fff;border-color:#00D9FF}
.snzc-body{flex:1;overflow-y:auto;padding:16px 14px;display:flex;flex-direction:column;gap:10px;background:radial-gradient(circle at 20% 0%,rgba(0,217,255,.06),transparent 45%),#051231;scroll-behavior:smooth}
.snzc-body::-webkit-scrollbar{width:5px}.snzc-body::-webkit-scrollbar-thumb{background:#1d4ea8;border-radius:5px}
.snzc-row{display:flex;flex-direction:column;max-width:84%;animation:snzBubble .35s cubic-bezier(.2,.9,.3,1.3)}
.snzc-row.me{align-self:flex-end;align-items:flex-end}
.snzc-row.them{align-self:flex-start}
@keyframes snzBubble{from{opacity:0;transform:translateY(10px) scale(.92)}to{opacity:1;transform:none}}
.snzc-who{font-size:10px;font-weight:800;margin:0 4px 4px;display:flex;align-items:center;gap:5px;letter-spacing:.02em}
.snzc-who.ai{color:#00D9FF}.snzc-who.admin{color:#00D89A}
.snzc-b{padding:10px 13px;border-radius:18px;font-size:13px;line-height:1.55;word-wrap:break-word;overflow-wrap:anywhere;white-space:normal}
.me .snzc-b{background:linear-gradient(135deg,#00B4E6,#00C98F);color:#03122e;font-weight:600;border-bottom-right-radius:6px}
.them .snzc-b{background:#0b2559;color:#e2e8f0;border:1px solid #16408f;border-bottom-left-radius:6px}
.them.admin .snzc-b{border-color:#0f7a5c;background:linear-gradient(135deg,#08324a,#0b2559)}
.snzc-b a{color:#67e8f9;text-decoration:underline}
.me .snzc-b a{color:#03122e}
.snzc-t{font-size:9.5px;color:#64748b;margin:3px 6px 0}
.snzc-sys{align-self:center;max-width:92%;text-align:center;font-size:11.5px;color:#cbd5e1;background:rgba(0,217,255,.08);border:1px dashed rgba(0,217,255,.35);padding:8px 12px;border-radius:14px;white-space:pre-line;animation:snzBubble .35s ease}
.snzc-typing{align-self:flex-start;background:#0b2559;border:1px solid #16408f;border-radius:18px;border-bottom-left-radius:6px;padding:12px 14px;display:none;gap:4px}
.snzc-typing span{width:7px;height:7px;border-radius:50%;background:#7dd3fc;display:inline-block;animation:snzDot 1.2s infinite}
.snzc-typing span:nth-child(2){animation-delay:.15s}.snzc-typing span:nth-child(3){animation-delay:.3s}
@keyframes snzDot{0%,60%,100%{opacity:.3;transform:translateY(0)}30%{opacity:1;transform:translateY(-4px)}}
.snzc-welcome{text-align:center;padding:10px 6px 4px}
.snzc-welcome .big{width:64px;height:64px;margin:0 auto 10px;border-radius:22px;background:linear-gradient(135deg,rgba(0,217,255,.18),rgba(0,216,154,.18));border:1px solid rgba(0,217,255,.35);display:flex;align-items:center;justify-content:center;font-size:28px;color:#00D9FF}
.snzc-welcome h4{color:#fff;font-weight:800;font-size:15px;margin:0}
.snzc-welcome p{color:#94a3b8;font-size:12px;margin:6px 0 0}
.snzc-chips{display:flex;flex-wrap:wrap;gap:6px;justify-content:center;margin-top:12px}
.snzc-chips button{font-size:11.5px;font-weight:700;color:#7dd3fc;background:#0a2560;border:1px solid #1d4ea8;border-radius:999px;padding:6px 11px;cursor:pointer}
.snzc-chips button:hover{border-color:#00D9FF;color:#fff}
.snzc-foot{padding:10px 12px;border-top:1px solid #123982;background:#06173a}
.snzc-form{display:flex;gap:8px;align-items:flex-end}
.snzc-form textarea{flex:1;resize:none;max-height:110px;min-height:42px;background:#020b20;border:1px solid #16408f;border-radius:14px;color:#fff;font-size:13px;padding:11px 12px;outline:none;font-family:inherit;line-height:1.4}
.snzc-form textarea:focus{border-color:#00D9FF}
.snzc-send{width:44px;height:44px;flex-shrink:0;border-radius:14px;border:0;cursor:pointer;background:linear-gradient(135deg,#00D9FF,#00D89A);color:#061535;font-size:16px}
.snzc-send:disabled{opacity:.5;cursor:default}
.snzc-closed{display:none;text-align:center}
.snzc-closed p{color:#94a3b8;font-size:12px;margin:0 0 8px}
.snzc-closed button{width:100%;border:0;border-radius:14px;padding:11px;font-weight:800;font-size:13px;cursor:pointer;background:linear-gradient(135deg,#00D9FF,#00D89A);color:#061535}
.snzc-note{font-size:9.5px;color:#475569;text-align:center;margin-top:6px}
#snzChatToast{position:fixed;right:18px;bottom:92px;z-index:71;width:320px;max-width:calc(100vw - 36px);background:#081e4b;border:1px solid #00D9FF;border-radius:20px;padding:12px 14px;box-shadow:0 20px 50px rgba(0,0,0,.55),0 0 25px rgba(0,217,255,.25);
  display:flex;gap:11px;align-items:flex-start;cursor:pointer;opacity:0;transform:translateY(16px) scale(.9);pointer-events:none;transition:all .35s cubic-bezier(.2,.9,.3,1.35)}
#snzChatToast.show{opacity:1;transform:none;pointer-events:auto}
#snzChatToast .tt{color:#fff;font-size:12.5px;font-weight:800}
#snzChatToast .tm{color:#cbd5e1;font-size:12px;margin-top:3px;display:-webkit-box;-webkit-line-clamp:3;-webkit-box-orient:vertical;overflow:hidden}
#snzChatToast .tx{margin-left:auto;color:#64748b;font-size:14px;background:none;border:0;cursor:pointer;padding:0 0 0 6px}
@media (max-width:520px){#snzChatPanel{right:8px;left:8px;width:auto;max-width:none;bottom:84px;height:calc(100vh - 100px);max-height:none;border-radius:22px}#snzChatFab{right:14px;bottom:14px;width:56px;height:56px}#snzChatToast{right:14px;left:14px;width:auto;bottom:80px}}
</style>

<div id="snzChatToast" onclick="snzChatOpen()">
    <div class="snzc-av" id="snzToastAv" style="width:38px;height:38px;font-size:16px">S</div>
    <div style="min-width:0">
        <div class="tt" id="snzToastTitle">New reply</div>
        <div class="tm" id="snzToastMsg"></div>
    </div>
    <button class="tx" onclick="event.stopPropagation();snzToastHide()" aria-label="Close"><i class="fa-solid fa-xmark"></i></button>
</div>

<div id="snzChatPanel" role="dialog" aria-label="Live chat">
    <div class="snzc-head">
        <div class="snzc-av"><i class="fa-solid fa-headset"></i></div>
        <div style="min-width:0">
            <div class="snzc-title">Live Support</div>
            <div class="snzc-sub" id="snzChatSub">SNZPay Support Team • Online</div>
        </div>
        <div class="snzc-hbtn">
            <button type="button" id="snzChatEndBtn" title="End chat" onclick="snzChatEnd()" style="display:none"><i class="fa-solid fa-power-off"></i></button>
            <button type="button" title="Minimize" onclick="snzChatToggle(false)"><i class="fa-solid fa-chevron-down"></i></button>
        </div>
    </div>
    <div class="snzc-body" id="snzChatBody">
        <div class="snzc-welcome" id="snzChatWelcome">
            <div class="big"><i class="fa-solid fa-comments"></i></div>
            <h4>আসসালামু আলাইকুম<?= $snzChatUser !== '' ? ', ' . $snzChatUser : '' ?>! 👋</h4>
            <p>কিভাবে সাহায্য করতে পারি স্যার? নিচে লিখুন — সাথে সাথেই উত্তর পাবেন।</p>
            <div class="snzc-chips">
                <button type="button" onclick="snzChatQuick(this)">ডিপোজিট এখনো আসেনি</button>
                <button type="button" onclick="snzChatQuick(this)">প্ল্যান কিভাবে কিনব?</button>
                <button type="button" onclick="snzChatQuick(this)">অ্যাপে লগইন হচ্ছে না</button>
                <button type="button" onclick="snzChatQuick(this)">Brand API key কিভাবে বানাব?</button>
            </div>
        </div>
        <div class="snzc-typing" id="snzChatTyping"><span></span><span></span><span></span></div>
    </div>
    <div class="snzc-foot">
        <form class="snzc-form" id="snzChatForm" onsubmit="snzChatSend(event)">
            <textarea id="snzChatInput" rows="1" maxlength="1500" placeholder="আপনার মেসেজ লিখুন..." onkeydown="if(event.key==='Enter'&&!event.shiftKey){event.preventDefault();snzChatSend(event);}" oninput="this.style.height='auto';this.style.height=Math.min(this.scrollHeight,110)+'px'"></textarea>
            <button type="submit" class="snzc-send" id="snzChatSendBtn" aria-label="Send"><i class="fa-solid fa-paper-plane"></i></button>
        </form>
        <div class="snzc-closed" id="snzChatClosed">
            <p><i class="fa-solid fa-circle-check" style="color:#00D89A"></i> এই চ্যাটটি বন্ধ হয়েছে।</p>
            <button type="button" onclick="snzChatNew()"><i class="fa-solid fa-plus"></i> নতুন চ্যাট শুরু করুন</button>
        </div>
        <div class="snzc-note">SNZPay সাপোর্ট টিম সাধারণত কয়েক সেকেন্ডেই উত্তর দেয়</div>
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
    let AGENT = '', ADMIN = '';
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

    function render(m) {
        if (seen.has(m.id)) return;
        seen.add(m.id);
        lastId = Math.max(lastId, m.id);
        $('snzChatWelcome').style.display = 'none';
        let el;
        if (m.sender === 'system') {
            el = document.createElement('div');
            el.className = 'snzc-sys';
            el.textContent = m.body;
        } else {
            const me = m.sender === 'user';
            el = document.createElement('div');
            el.className = 'snzc-row ' + (me ? 'me' : 'them');
            const who = me ? '' : '<div class="snzc-who ai"><i class="fa-solid fa-circle-user"></i> ' + esc(nameOf(m)) + ' <span style="color:#64748b;font-weight:700">• SNZPay</span></div>';
            el.innerHTML = who + '<div class="snzc-b">' + fmt(m.body) + '</div><div class="snzc-t">' + esc(m.time) + '</div>';
        }
        body.insertBefore(el, typing);
    }

    function nameOf(m) { return m.sender === 'admin' ? (ADMIN || AGENT || 'SNZPay Support') : (AGENT || 'SNZPay Support'); }
    function setPeople(t) {
        if (!t) return;
        AGENT = t.agent || AGENT; ADMIN = t.admin || ADMIN;
        $('snzChatSub').textContent = (AGENT ? AGENT + ' • ' : '') + 'SNZPay Support • Online';
    }

    function setStatus(st) {
        threadStatus = st;
        const closed = st === 'closed';
        $('snzChatForm').style.display = closed ? 'none' : 'flex';
        $('snzChatClosed').style.display = closed ? 'block' : 'none';
        $('snzChatEndBtn').style.display = st === 'open' ? 'block' : 'none';
    }

    function setBadge(n) {
        unread = n;
        const b = $('snzChatBadge');
        b.textContent = n > 9 ? '9+' : n;
        b.style.display = n > 0 && !open ? 'block' : 'none';
    }

    function toast(m) {
        const nm = m.sender === 'system' ? 'SNZPay Support' : nameOf(m);
        $('snzToastTitle').innerHTML = esc(nm) + ' <span style="color:#7dd3fc;font-weight:700;font-size:11px">• SNZPay Support</span>';
        $('snzToastMsg').textContent = m.body;
        $('snzToastAv').textContent = nm.charAt(0).toUpperCase();
        $('snzToastAv').style.fontWeight = '900';
        $('snzChatToast').classList.add('show');
        clearTimeout(toastTimer);
        toastTimer = setTimeout(snzToastHide, 8000);
    }
    window.snzToastHide = () => $('snzChatToast').classList.remove('show');

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
            if (open) {
                if (d.messages.length) scrollDown();
                if (d.unread > 0) markSeen();
            } else {
                setBadge(d.unread);
                if (newest && !firstLoad) toast(newest);
            }
        } catch (e) {
        } finally { busy = false; firstLoad = false; }
    }

    function resetView() {
        body.querySelectorAll('.snzc-row,.snzc-sys').forEach(e => e.remove());
        seen.clear(); lastId = 0; threadStatus = null; threadId = 0; AGENT = '';
        $('snzChatSub').textContent = 'SNZPay Support Team • Online';
        $('snzChatWelcome').style.display = '';
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
        if (open) {
            snzToastHide();
            setBadge(0);
            scrollDown();
            if (unread || threadStatus) markSeen();
            setTimeout(() => $('snzChatInput').focus(), 250);
            poll();
        } else {
            setBadge(0);
        }
    };
    window.snzChatOpen = () => window.snzChatToggle(true);

    window.snzChatQuick = function (btn) { $('snzChatInput').value = btn.textContent; snzChatSend(); };

    window.snzChatSend = async function (e) {
        if (e) e.preventDefault();
        const inp = $('snzChatInput');
        const text = inp.value.trim();
        if (!text || sending) return;
        sending = true;
        $('snzChatSendBtn').disabled = true;
        inp.value = ''; inp.style.height = 'auto';
        $('snzChatWelcome').style.display = 'none';
        // সাথে সাথে নিজের মেসেজ দেখানো
        const tmp = document.createElement('div');
        tmp.className = 'snzc-row me';
        tmp.innerHTML = '<div class="snzc-b">' + fmt(text) + '</div><div class="snzc-t">Sending…</div>';
        body.insertBefore(tmp, typing);
        typing.style.display = 'flex';
        setStatus('open');
        scrollDown();
        try {
            const f = new FormData();
            f.append('a', 'send'); f.append('csrf', CSRF); f.append('body', text); f.append('after', lastId);
            const r = await fetch(API, { method: 'POST', body: f, credentials: 'same-origin' });
            const d = await r.json().catch(() => ({}));
            tmp.remove();
            if (d.status !== 'success') {
                inp.value = text;
                const er = document.createElement('div'); er.className = 'snzc-sys'; er.textContent = d.message || 'মেসেজ পাঠানো যায়নি, আবার চেষ্টা করুন।';
                body.insertBefore(er, typing); setTimeout(() => er.remove(), 5000);
            } else {
                if (d.thread.id !== threadId) { body.querySelectorAll('.snzc-row,.snzc-sys').forEach(e => e.remove()); seen.clear(); }
                threadId = d.thread.id; ignoreThread = 0;
                setPeople(d.thread);
                (d.messages || []).forEach(render);
                setStatus(d.thread.status);
                markSeen();
            }
        } catch (err) {
            tmp.querySelector('.snzc-t').textContent = 'Not sent — check internet';
            inp.value = text;
        } finally {
            typing.style.display = 'none';
            sending = false;
            $('snzChatSendBtn').disabled = false;
            scrollDown();
        }
    };

    window.snzChatEnd = async function () {
        if (!confirm('চ্যাটটি বন্ধ করবেন?')) return;
        const f = new FormData(); f.append('a', 'close'); f.append('csrf', CSRF);
        await fetch(API, { method: 'POST', body: f, credentials: 'same-origin' }).catch(() => {});
        poll();
    };

    window.snzChatNew = function () { ignoreThread = threadId; resetView(); setStatus('new'); $('snzChatEndBtn').style.display = 'none'; $('snzChatInput').focus(); };

    setStatus(null);
    poll();
    // খোলা থাকলে প্রতি ৩.৫ সেকেন্ডে, বন্ধ থাকলে প্রতি ~১০ সেকেন্ডে নতুন উত্তর দেখা
    setInterval(() => { tick++; if (!document.hidden && (open || tick % 3 === 0)) poll(); }, 3500);
})();
</script>
