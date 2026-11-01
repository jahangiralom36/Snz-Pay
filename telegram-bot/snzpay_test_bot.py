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

⚠️ সফল ভেরিফাই হলে সেই পেমেন্ট সাইটে "used" হয়ে যায় — একই TrxID দ্বিতীয়বার
   আর success হবে না ("Transaction already used" আসবে)। এটাই সঠিক আচরণ।
"""

import os
import re
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

# প্রতিটা ইউজারের বর্তমান ধাপ: {chat_id: {"step": ..., "method": ..., "amount": ...}}
sessions = {}


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
    return rows


def esc(s) -> str:
    return str(s).replace("&", "&amp;").replace("<", "&lt;").replace(">", "&gt;")


# =========================================================================
# 3) বটের কথোপকথন
# =========================================================================
def show_menu(chat_id):
    sessions.pop(chat_id, None)
    send(chat_id,
         "👋 <b>SNZPay Test Bot</b>\n\n"
         "কোন মেথডের পেমেন্ট টেস্ট করবেন? নিচ থেকে বাছাই করুন 👇",
         method_buttons())


def on_method_selected(chat_id, code):
    m = METHODS[code]
    sessions[chat_id] = {"step": "amount", "method": code}
    unit = "USDT" if code == "BINANCE" else "BDT"
    send(chat_id,
         f"{m['emoji']} <b>{m['name']}</b> সিলেক্ট হয়েছে।\n\n"
         f"💰 কত টাকা ({unit}) পাঠানো হয়েছে লিখুন।\n"
         f"<i>যেকোনো অ্যামাউন্ট মেনে নিতে Skip চাপুন।</i>",
         [[{"text": "⏭ Skip (any amount)", "callback_data": "skip_amount"}],
          [{"text": "⬅️ Back", "callback_data": "menu"}]])


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


def on_amount(chat_id, text):
    clean = text.replace(",", "").replace("৳", "").replace("$", "").strip()
    clean = clean.translate(str.maketrans("০১২৩৪৫৬৭৮৯", "0123456789"))
    try:
        amount = float(clean)
        if amount < 0:
            raise ValueError
    except ValueError:
        send(chat_id, "❌ শুধু সংখ্যা লিখুন, যেমন <code>500</code> বা <code>2.5</code>")
        return
    sessions[chat_id]["amount"] = amount
    ask_value(chat_id)


def on_value(chat_id, text):
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

    amount = s.get("amount", 0)
    m = METHODS[code]
    send(chat_id, f"⏳ সার্ভারে চেক করা হচ্ছে…\n{m['emoji']} {m['name']} • <code>{esc(value)}</code>")

    started = time.time()
    result = verify_payment(code, value, amount)
    ms = int((time.time() - started) * 1000)

    if result["ok"]:
        d = result["data"]
        lines = [
            "✅ <b>Payment Verified!</b>",
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
        send(chat_id, "\n".join(lines), [[{"text": "🔁 Test another payment", "callback_data": "menu"}]])
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
        chat_id = cq["message"]["chat"]["id"]
        tg("answerCallbackQuery", callback_query_id=cq["id"])
        if not allowed(cq["from"]["id"]):
            return
        data = cq.get("data", "")
        if data == "menu":
            show_menu(chat_id)
        elif data.startswith("m:") and data[2:] in METHODS:
            on_method_selected(chat_id, data[2:])
        elif data == "skip_amount" and chat_id in sessions:
            sessions[chat_id]["amount"] = 0
            ask_value(chat_id)
        return

    # টেক্সট মেসেজ
    msg = upd.get("message")
    if not msg or "text" not in msg:
        return
    chat_id = msg["chat"]["id"]
    text = msg["text"].strip()

    if text.startswith("/id"):
        send(chat_id, f"🆔 Your Telegram ID: <code>{msg['from']['id']}</code>")
        return
    if not allowed(msg["from"]["id"]):
        send(chat_id, "⛔ You are not allowed to use this bot.")
        return
    if text.startswith("/start") or text.startswith("/menu") or text.startswith("/cancel"):
        show_menu(chat_id)
        return

    s = sessions.get(chat_id)
    if not s:
        show_menu(chat_id)
    elif s["step"] == "amount":
        on_amount(chat_id, text)
    elif s["step"] == "value":
        on_value(chat_id, text)


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
    tg("deleteWebhook")  # আগে webhook থাকলে polling কাজ করে না
    tg("setMyCommands", commands=[
        {"command": "start", "description": "Start payment test"},
        {"command": "cancel", "description": "Cancel and go to menu"},
        {"command": "id", "description": "Show my Telegram ID"},
    ])
    print(f"✅ Bot running: @{me['result']['username']}  →  API: {API_URL}")

    offset = 0
    while True:
        try:
            res = requests.get(f"{TG_API}/getUpdates",
                               params={"timeout": 30, "offset": offset}, timeout=40).json()
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
