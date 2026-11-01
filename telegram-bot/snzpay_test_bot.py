"""
SNZPay Test Bot — Telegram থেকে সাইটের পেমেন্ট ভেরিফাই API টেস্ট করার বট
=========================================================================

চালানোর নিয়ম:
  1. pip install requests
  2. নিচে BOT_TOKEN আর LICENSE_KEY বসান
       BOT_TOKEN   → Telegram এ @BotFather → /newbot → যে টোকেন দেয়
       LICENSE_KEY → সাইটের Dashboard → My Plans এর লাইসেন্স কী
                     অথবা Brands (Bot Key) এর SNZ-BOT-... কী
  3. python snzpay_test_bot.py

বটে /start দিলে bKash, Nagad, Rocket, Binance, Bangla QR বাটন আসবে।
  - bKash / Nagad / Rocket → TrxID দিতে হবে
  - Binance               → Order ID দিতে হবে
  - Bangla QR             → যে নাম্বার থেকে টাকা এসেছে তার শেষ ৪ ডিজিট
ভেরিফাই সফল হলে ওই TrxID তে যত টাকা ছিল, সাথে সাথে ইউজারের বট-ব্যালেন্সে যোগ হয়।
ব্যালেন্স balances.json ফাইলে সেভ থাকে (বট বন্ধ করে চালালেও হারায় না)।

⚠️ সফল ভেরিফাই হলে সেই পেমেন্ট সাইটে "used" হয়ে যায় — একই TrxID দ্বিতীয়বার
   আর success হবে না ("Transaction already used" আসবে)। এটাই সঠিক আচরণ।
"""

import json
import os
import re
import threading
import time

import requests

# =========================================================================
# ⚙️ সেটিংস
# =========================================================================
BOT_TOKEN = os.getenv("BOT_TOKEN", "PASTE_YOUR_BOT_TOKEN_HERE")
API_URL = os.getenv("API_URL", "https://snzpay.digital/api/verify_payment.php")
LICENSE_KEY = os.getenv("LICENSE_KEY", "YOUR_ACTIVE_LICENSE_KEY")

# শুধু নির্দিষ্ট লোক বট ব্যবহার করতে পারবে চাইলে Telegram user ID দিন (খালি = সবাই)
# নিজের ID জানতে বটে /id লিখুন
ALLOWED_USER_IDS = []

TG_API = f"https://api.telegram.org/bot{BOT_TOKEN}"

# =========================================================================
# পেমেন্ট মেথড
# =========================================================================
METHODS = {
    "BKASH":     {"name": "bKash",     "emoji": "🟣", "ask": "TrxID",       "example": "BL7A90XYZ"},
    "NAGAD":     {"name": "Nagad",     "emoji": "🟠", "ask": "TrxID",       "example": "74HG8K2L"},
    "ROCKET":    {"name": "Rocket",    "emoji": "🟪", "ask": "TrxID",       "example": "3456789012"},
    "BINANCE":   {"name": "Binance",   "emoji": "🟡", "ask": "Order ID",    "example": "283746592837465"},
    "BANGLA_QR": {"name": "Bangla QR", "emoji": "🔵", "ask": "last 4 digits", "example": "5678"},
}

# প্রতিটা ইউজারের বর্তমান ধাপ: {chat_id: {"step": ..., "method": ...}}
sessions = {}

# =========================================================================
# 💰 ইউজারের ব্যালেন্স (balances.json ফাইলে সেভ থাকে)
# =========================================================================
BALANCE_FILE = os.path.join(os.path.dirname(os.path.abspath(__file__)), "balances.json")
_bal_lock = threading.Lock()


def _load_balances() -> dict:
    try:
        with open(BALANCE_FILE, "r", encoding="utf-8") as f:
            data = json.load(f)
            return data if isinstance(data, dict) else {}
    except (FileNotFoundError, ValueError):
        return {}


def get_balance(user_id) -> float:
    with _bal_lock:
        return float(_load_balances().get(str(user_id), {}).get("balance", 0))


def add_balance(user_id, amount: float, trx_id: str, method: str) -> float:
    """টাকা যোগ করে নতুন ব্যালেন্স ফেরত দেয়"""
    with _bal_lock:
        data = _load_balances()
        u = data.setdefault(str(user_id), {"balance": 0, "history": []})
        u["balance"] = round(float(u.get("balance", 0)) + float(amount), 2)
        u.setdefault("history", []).append({
            "trx_id": trx_id, "method": method, "amount": amount,
            "time": time.strftime("%Y-%m-%d %H:%M:%S"),
        })
        u["history"] = u["history"][-50:]   # শেষ ৫০টা লেনদেন রাখা
        tmp = BALANCE_FILE + ".tmp"
        with open(tmp, "w", encoding="utf-8") as f:
            json.dump(data, f, ensure_ascii=False, indent=2)
        os.replace(tmp, BALANCE_FILE)
        return u["balance"]


def get_history(user_id, limit=10) -> list:
    with _bal_lock:
        return _load_balances().get(str(user_id), {}).get("history", [])[-limit:][::-1]


# =========================================================================
# 1) সাইটের API কল (আপনার Developer API কোডের উপর ভিত্তি করে)
# =========================================================================
def verify_payment(method: str, value: str, amount: float = 0) -> dict:
    """
    BKASH/NAGAD/ROCKET → trx_id, BINANCE → Order ID (trx_id),
    BANGLA_QR → প্রেরকের নাম্বারের শেষ ৪ ডিজিট (phone)
    """
    payload = {"license_key": LICENSE_KEY, "method": method, "amount": amount}
    if method == "BANGLA_QR":
        payload["phone"] = value
    else:
        payload["trx_id"] = value

    try:
        res = requests.post(API_URL, json=payload, timeout=15)
        try:
            data = res.json()
        except ValueError:
            return {"ok": False, "reason": f"Server returned non-JSON (HTTP {res.status_code})", "http": res.status_code}
        if data.get("status") == "success":
            return {"ok": True, "data": data.get("data", {}), "http": res.status_code}
        return {
            "ok": False,
            "reason": data.get("reason") or data.get("message") or "Unknown error",
            "http": res.status_code,
        }
    except requests.exceptions.Timeout:
        return {"ok": False, "reason": "Server did not respond in 15 seconds", "http": 0}
    except Exception as e:  # নেট সমস্যা
        return {"ok": False, "reason": f"Network error: {e}", "http": 0}


# =========================================================================
# 2) Telegram helpers
# =========================================================================
def tg(method: str, **params):
    try:
        r = requests.post(f"{TG_API}/{method}", json=params, timeout=40)
        return r.json()
    except Exception as e:
        print("Telegram error:", e)
        return {}


def send(chat_id, text, buttons=None):
    params = {"chat_id": chat_id, "text": text, "parse_mode": "HTML", "disable_web_page_preview": True}
    if buttons:
        params["reply_markup"] = {"inline_keyboard": buttons}
    return tg("sendMessage", **params)


def method_buttons():
    rows, row = [], []
    for code, m in METHODS.items():
        row.append({"text": f"{m['emoji']} {m['name']}", "callback_data": f"m:{code}"})
        if len(row) == 2:
            rows.append(row)
            row = []
    if row:
        rows.append(row)
    rows.append([{"text": "💰 My Balance", "callback_data": "balance"},
                 {"text": "🧾 History", "callback_data": "history"}])
    return rows


def esc(s) -> str:
    return str(s).replace("&", "&amp;").replace("<", "&lt;").replace(">", "&gt;")


# =========================================================================
# 3) বটের কথোপকথন
# =========================================================================
def show_menu(chat_id, user_id):
    sessions.pop(chat_id, None)
    send(chat_id,
         "👋 <b>SNZPay Test Bot</b>\n\n"
         f"💰 আপনার ব্যালেন্স: <b>৳ {get_balance(user_id):,.2f}</b>\n\n"
         "কোন মেথডে টাকা পাঠিয়েছেন? নিচ থেকে বাছাই করুন 👇",
         method_buttons())


def show_balance(chat_id, user_id):
    send(chat_id, f"💰 আপনার বর্তমান ব্যালেন্স: <b>৳ {get_balance(user_id):,.2f}</b>",
         [[{"text": "➕ Add Money", "callback_data": "menu"}]])


def show_history(chat_id, user_id):
    items = get_history(user_id)
    if not items:
        send(chat_id, "🧾 এখনো কোনো লেনদেন নেই।", [[{"text": "🏠 Main menu", "callback_data": "menu"}]])
        return
    lines = ["🧾 <b>শেষ লেনদেনগুলো</b>", ""]
    for h in items:
        lines.append(f"• {esc(h['method'])} <code>{esc(h['trx_id'])}</code> +৳{h['amount']:,.2f}  <i>{esc(h['time'])}</i>")
    lines += ["", f"💰 মোট ব্যালেন্স: <b>৳ {get_balance(user_id):,.2f}</b>"]
    send(chat_id, "\n".join(lines), [[{"text": "🏠 Main menu", "callback_data": "menu"}]])


def on_method_selected(chat_id, code):
    # অ্যামাউন্ট জিজ্ঞেস না করে সরাসরি TrxID চাওয়া — যত টাকা এসেছে সার্ভারই বলে দেবে
    sessions[chat_id] = {"step": "value", "method": code}
    ask_value(chat_id)


def ask_value(chat_id):
    s = sessions[chat_id]
    m = METHODS[s["method"]]
    if s["method"] == "BANGLA_QR":
        text = ("📱 যে নাম্বার থেকে টাকা এসেছে তার <b>শেষ ৪ ডিজিট</b> লিখুন।\n"
                f"উদাহরণ: <code>{m['example']}</code>")
    elif s["method"] == "BINANCE":
        text = ("🧾 Binance Pay এর <b>Order ID</b> লিখুন।\n"
                f"উদাহরণ: <code>{m['example']}</code>")
    else:
        text = (f"🧾 {m['name']} SMS এর <b>TrxID</b> লিখুন।\n"
                f"উদাহরণ: <code>{m['example']}</code>")
    s["step"] = "value"
    send(chat_id, text, [[{"text": "⬅️ Back", "callback_data": "menu"}]])


def on_value(chat_id, user_id, text):
    s = sessions[chat_id]
    code = s["method"]
    value = text.strip().upper().replace(" ", "")

    if code == "BANGLA_QR":
        digits = re.sub(r"\D", "", value)
        if not (3 <= len(digits) <= 11):
            send(chat_id, "❌ নাম্বারের শেষ ৪ ডিজিট লিখুন, যেমন <code>5678</code>")
            return
        value = digits
    elif not re.fullmatch(r"[A-Z0-9]{4,64}", value):
        send(chat_id, f"❌ সঠিক {METHODS[code]['ask']} লিখুন (শুধু ইংরেজি অক্ষর ও সংখ্যা)।")
        return

    amount = 0   # যেকোনো অ্যামাউন্ট — TrxID তে যত টাকা আছে ততই যোগ হবে
    m = METHODS[code]
    send(chat_id, f"⏳ সার্ভারে চেক করা হচ্ছে…\n{m['emoji']} {m['name']} • <code>{esc(value)}</code>")

    started = time.time()
    result = verify_payment(code, value, amount)
    ms = int((time.time() - started) * 1000)

    if result["ok"]:
        d = result["data"]
        try:
            credited = float(d.get("amount") or 0)
        except (TypeError, ValueError):
            credited = 0.0
        new_balance = add_balance(user_id, credited, str(d.get("trx_id", value)), str(d.get("method", code)))
        lines = [
            "✅ <b>Payment Verified!</b>",
            f"➕ <b>৳ {credited:,.2f}</b> আপনার ব্যালেন্সে যোগ হয়েছে।",
            f"💰 নতুন ব্যালেন্স: <b>৳ {new_balance:,.2f}</b>",
            "",
            f"🏷 Method: <b>{esc(d.get('method', code))}</b>",
            f"🧾 TrxID: <code>{esc(d.get('trx_id', value))}</code>",
            f"💰 Amount: <b>৳ {esc(d.get('amount'))}</b>",
        ]
        if d.get("amount_usdt") is not None:
            lines.append(f"💵 USDT: <b>{esc(d.get('amount_usdt'))}</b> (rate {esc(d.get('dollar_rate'))})")
        lines += [
            f"📱 Sender: <code>{esc(d.get('sender', 'N/A'))}</code>",
            f"⚡ Response time: {ms} ms",
        ]
        send(chat_id, "\n".join(lines), [[{"text": "➕ Add more money", "callback_data": "menu"},
                                            {"text": "💰 Balance", "callback_data": "balance"}]])
    else:
        send(chat_id,
             "❌ <b>Verification Failed</b>\n\n"
             f"কারণ: <b>{esc(result['reason'])}</b>\n"
             f"HTTP: {result['http']} • {ms} ms",
             [[{"text": "🔁 Try again", "callback_data": f"m:{code}"}],
              [{"text": "🏠 Main menu", "callback_data": "menu"}]])
    sessions.pop(chat_id, None)


def allowed(user_id) -> bool:
    return not ALLOWED_USER_IDS or user_id in ALLOWED_USER_IDS


def handle_update(upd):
    # বাটনে চাপ
    if "callback_query" in upd:
        cq = upd["callback_query"]
        user_id = cq["from"]["id"]
        # বাটন চাপার পর Telegram এর লোডিং ঘড়ি বন্ধ করা
        tg("answerCallbackQuery", callback_query_id=cq["id"])
        msg = cq.get("message") or {}
        chat_id = (msg.get("chat") or {}).get("id", user_id)
        if not allowed(user_id):
            return
        data = cq.get("data", "")
        print(f"[button] user={user_id} data={data}")
        if data == "menu":
            show_menu(chat_id, user_id)
        elif data == "balance":
            show_balance(chat_id, user_id)
        elif data == "history":
            show_history(chat_id, user_id)
        elif data.startswith("m:") and data[2:] in METHODS:
            on_method_selected(chat_id, data[2:])
        return

    # টেক্সট মেসেজ
    msg = upd.get("message")
    if not msg or "text" not in msg:
        return
    chat_id = msg["chat"]["id"]
    user_id = msg["from"]["id"]
    text = msg["text"].strip()

    if text.startswith("/id"):
        send(chat_id, f"🆔 Your Telegram ID: <code>{msg['from']['id']}</code>")
        return
    if not allowed(msg["from"]["id"]):
        send(chat_id, "⛔ You are not allowed to use this bot.")
        return
    if text.startswith("/start") or text.startswith("/menu") or text.startswith("/cancel"):
        show_menu(chat_id, user_id)
        return
    if text.startswith("/balance"):
        show_balance(chat_id, user_id)
        return

    s = sessions.get(chat_id)
    if not s:
        show_menu(chat_id, user_id)
    elif s["step"] == "value":
        on_value(chat_id, user_id, text)


# =========================================================================
# 4) চালু করা (long polling — কোনো সার্ভার/ওয়েবহুক লাগবে না)
# =========================================================================
def main():
    if "PASTE_YOUR" in BOT_TOKEN:
        print("❌ BOT_TOKEN বসান (ফাইলের উপরে)।")
        return
    if LICENSE_KEY == "YOUR_ACTIVE_LICENSE_KEY":
        print("⚠️ LICENSE_KEY বসানো হয়নি — সব টেস্ট 'Invalid license key' দেখাবে।")

    me = tg("getMe")
    if not me.get("ok"):
        print("❌ BOT_TOKEN ভুল:", me)
        return
    # আগে webhook থাকলে polling কাজ করে না
    tg("deleteWebhook", drop_pending_updates=False)
    tg("setMyCommands", commands=[
        {"command": "start", "description": "Start payment test"},
        {"command": "balance", "description": "Show my balance"},
        {"command": "cancel", "description": "Cancel and go to menu"},
        {"command": "id", "description": "Show my Telegram ID"},
    ])
    print(f"✅ Bot running: @{me['result']['username']}  →  API: {API_URL}")

    offset = 0
    while True:
        try:
            # ⚠️ বাটন ফিক্স: allowed_updates এ "callback_query" না দিলে (আগের কোনো বট-কোড শুধু
            # "message" সেট করে রাখলে) বাটনে চাপ বটের কাছে আসেই না।
            res = requests.post(f"{TG_API}/getUpdates", json={
                "timeout": 30, "offset": offset,
                "allowed_updates": ["message", "callback_query"],
            }, timeout=40).json()
            if not res.get("ok"):
                if res.get("error_code") == 409:
                    print("❌ একই BOT_TOKEN দিয়ে আরেকটা বট/প্রোগ্রাম চলছে। সেটা বন্ধ করুন।")
                else:
                    print("Telegram:", res)
                time.sleep(5)
                continue
            for upd in res.get("result", []):
                offset = upd["update_id"] + 1
                try:
                    handle_update(upd)
                except Exception as e:
                    print("Handler error:", e)
        except KeyboardInterrupt:
            print("Bot stopped.")
            break
        except Exception as e:
            print("Polling error:", e)
            time.sleep(3)


if __name__ == "__main__":
    main()
