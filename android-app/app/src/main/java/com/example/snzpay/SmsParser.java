package com.example.snzpay;

import android.content.Context;

import org.json.JSONArray;
import org.json.JSONObject;

import java.util.Locale;
import java.util.regex.Matcher;
import java.util.regex.Pattern;

/**
 * পেমেন্ট SMS পড়ে Method, TrxID, Amount আর কাস্টমারের নাম্বার বের করে।
 *  ১. প্রথমে ওয়েবসাইটের Admin Panel → SMS Rules এ বানানো রুল
 *  ২. তারপর অ্যাপের ভেতরের রুল (bKash, Nagad, Rocket, TallyPay, Bangla QR, Binance)
 *
 * ভুয়া SMS সুরক্ষা: "Fake SMS protection" চালু থাকলে সাধারণ মোবাইল নাম্বার থেকে আসা
 * "You have received Tk..." টাইপের SMS গ্রহণ করা হয় না।
 */
public final class SmsParser {

    public static final class Result {
        public String method = "";
        public String trxId = "";
        public double amount = 0;
        public String customer = "";
    }

    private static final int FLAGS = Pattern.CASE_INSENSITIVE | Pattern.UNICODE_CASE;
    private static final String AMOUNT = "([\\d,]+(?:\\.\\d+)?)";

    private SmsParser() {}

    public static Result parse(Context ctx, String sender, String body) {
        return parse(AppConfig.get(ctx), sender, body);
    }

    /** cfg = ওয়েবসাইট থেকে আসা সেটিংস (api/app_config.php) */
    public static Result parse(JSONObject cfg, String sender, String body) {
        if (body == null || body.trim().isEmpty()) return null;
        if (sender == null) sender = "";
        if (cfg == null) cfg = new JSONObject();
        boolean strict = cfg.optBoolean("strict_sender", true);

        // ১. ওয়েবসাইট থেকে আসা কাস্টম রুল
        JSONArray rules = cfg.optJSONArray("sms_rules");
        if (rules != null) {
            for (int i = 0; i < rules.length(); i++) {
                JSONObject rule = rules.optJSONObject(i);
                if (rule == null) continue;
                Result r = applyCustomRule(rule, sender, body, strict);
                if (r != null) return r;
            }
        }

        // ২. অ্যাপের ভেতরের রুল
        return builtIn(sender, body, strict);
    }

    // ---------------------------------------------------------------------
    // Admin panel rules ("Text before TrxID / Amount / Number")
    // সার্ভারের admin.php এর টেস্টারও ঠিক এই নিয়মে মেলায়
    // ---------------------------------------------------------------------
    private static Result applyCustomRule(JSONObject rule, String sender, String body, boolean strict) {
        String senders = rule.optString("senders", "").trim();
        if (!senders.isEmpty()) {
            boolean ok = false;
            for (String s : senders.split(",")) {
                String t = s.trim();
                if (!t.isEmpty() && containsIgnoreCase(sender, t)) { ok = true; break; }
            }
            if (!ok) return null;
        } else if (strict && isPersonalNumber(sender)) {
            return null;
        }

        String must = rule.optString("must_contain", "").trim();
        if (!must.isEmpty()) {
            for (String w : must.split(",")) {
                String t = w.trim();
                if (!t.isEmpty() && !containsIgnoreCase(body, t)) return null;
            }
        }

        String trx = extract(body, rulePattern(rule.optString("trx_before", ""), "trx"));
        String amt = extract(body, rulePattern(rule.optString("amount_before", ""), "amount"));
        String num = extract(body, rulePattern(rule.optString("number_before", ""), "number"));

        Result r = new Result();
        r.method = rule.optString("method", "CUSTOM").toUpperCase(Locale.ROOT);
        r.trxId = trx.toUpperCase(Locale.ROOT);
        r.amount = toAmount(amt);
        r.customer = num;
        return valid(r) ? r : null;
    }

    private static Pattern rulePattern(String before, String kind) {
        before = before == null ? "" : before.trim();
        if (before.isEmpty()) return null;
        try {
            if (before.toLowerCase(Locale.ROOT).startsWith("regex:")) {
                return Pattern.compile(before.substring(6), FLAGS);
            }
            String q = Pattern.quote(before);
            switch (kind) {
                case "trx":    return Pattern.compile(q + "[\\s:.#-]*([A-Za-z0-9]{4,})", FLAGS);
                case "amount": return Pattern.compile(q + "[\\s:.]*(?:Tk\\.?|BDT|৳)?\\s*" + AMOUNT, FLAGS);
                default:       return Pattern.compile(q + "[\\s:.]*([+0-9*]{3,})", FLAGS);
            }
        } catch (Exception e) {
            return null;
        }
    }

    // ---------------------------------------------------------------------
    // Built-in rules
    // ---------------------------------------------------------------------
    private static Result builtIn(String sender, String body, boolean strict) {
        String up = body.toUpperCase(Locale.ROOT);
        String snd = sender.toUpperCase(Locale.ROOT);
        Result r = new Result();

        // ১. TallyPay / Bangla QR (TallyKhata)
        if (up.contains("TALLYPAY") || snd.contains("TALLY")) {
            if (strict && !snd.contains("TALLY") && isPersonalNumber(sender)) return null;
            r.method = "TALLYPAY_QR";
            r.amount = toAmount(extract(body, "Tk\\s*" + AMOUNT));
            r.customer = extract(body, "from\\s*([0-9*+]+)");
            r.trxId = extract(body, "Txn\\s*ID\\s*:?\\s*([A-Za-z0-9]+)");
        }
        // ২. সাধারণ Bangla QR (ব্যাংকের SMS)
        else if (up.contains("BANGLA QR") || up.contains("QR PAYMENT")) {
            if (strict && isPersonalNumber(sender)) return null;
            r.method = "BANGLA_QR";
            r.amount = toAmount(extract(body, "(?:Tk|BDT)\\.?\\s*" + AMOUNT));
            r.customer = extract(body, "from\\s*([0-9*+]+)");
            r.trxId = extract(body, "(?:Txn\\s*ID|TrxID|Ref|RRN)[:\\s]*([A-Za-z0-9]+)");
        }
        // ৩. Binance
        else if (snd.contains("BINANCE") || up.contains("BINANCE")) {
            if (strict && isPersonalNumber(sender)) return null;
            r.method = "BINANCE";
            r.amount = toAmount(extract(body, AMOUNT + "\\s*USDT"));
            if (r.amount <= 0) r.amount = toAmount(extract(body, "(?:deposit|deposited|received|payment)\\s*(?:of)?\\s*" + AMOUNT));
            r.trxId = extract(body, "(?:TxID|TxId|Order\\s*ID|Transaction\\s*ID|Pay\\s*ID)[:\\s]*([A-Za-z0-9]+)");
            if (r.trxId.isEmpty()) r.trxId = extract(body, "\\b(\\d{10,20})\\b");
            r.customer = extract(body, "(?:from|user)[:\\s]*([0-9*]+)");
        }
        // ৪. bKash — টাকা আসার SMS (Send Money received / Payment received / Cash In / Agent Cash Out)
        else if (up.contains("TRXID") && (snd.contains("BKASH") || (!strict && up.contains("RECEIVED")))
                && Pattern.compile("received|cash\\s*in\\s*tk|cash\\s*out\\s*tk[\\d,.\\s]+from", FLAGS).matcher(body).find()) {
            r.method = "BKASH";
            r.amount = toAmount(extract(body, "Tk\\s*" + AMOUNT));
            r.customer = extract(body, "from\\s*([0-9*+]+)");
            r.trxId = extract(body, "TrxID\\s*:?\\s*([A-Za-z0-9]+)");
        }
        // ৫. Nagad
        else if ((up.contains("TXNID") || up.contains("TRXID")) && up.contains("RECEIVED")
                && (snd.contains("NAGAD") || !strict)) {
            r.method = "NAGAD";
            r.amount = toAmount(extract(body, "Amount\\s*:?\\s*Tk\\s*" + AMOUNT));
            if (r.amount <= 0) r.amount = toAmount(extract(body, "Tk\\s*" + AMOUNT));
            r.customer = extract(body, "Sender\\s*:?\\s*([0-9*+]+)");
            if (r.customer.isEmpty()) r.customer = extract(body, "from\\s*([0-9*+]+)");
            r.trxId = extract(body, "(?:TxnID|TrxID)\\s*:?\\s*([A-Za-z0-9]+)");
        }
        // ৬. Rocket (16216)
        else if ((up.contains("RECEIVED FROM A/C") || up.contains("NEXUSPAY") || (up.contains("TXNID") && up.contains("RECEIVED")))
                && (snd.contains("16216") || snd.contains("ROCKET") || snd.contains("DBBL") || !strict)) {
            r.method = "ROCKET";
            r.amount = toAmount(extract(body, "Tk\\.?\\s*" + AMOUNT + "\\s*received"));
            if (r.amount <= 0) r.amount = toAmount(extract(body, "Tk\\.?\\s*" + AMOUNT));
            r.customer = extract(body, "A/C\\s*:?\\s*([*0-9]+)");
            r.trxId = extract(body, "TxnId\\s*:?\\s*([A-Za-z0-9]+)");
        } else {
            return null;
        }

        r.trxId = r.trxId.toUpperCase(Locale.ROOT);
        return valid(r) ? r : null;
    }

    // ---------------------------------------------------------------------
    // helpers
    // ---------------------------------------------------------------------
    private static boolean valid(Result r) {
        return r.trxId != null && r.trxId.length() >= 4 && r.amount > 0;
    }

    /** সাধারণ মানুষের মোবাইল নাম্বার (01XXXXXXXXX / +8801...) — আসল bKash/Nagad কখনো এখান থেকে SMS দেয় না */
    static boolean isPersonalNumber(String sender) {
        if (sender == null) return true;
        String s = sender.replaceAll("[\\s-]", "");
        if (s.matches(".*[A-Za-z].*")) return false;          // bKash, NAGAD → নাম দিয়ে আসে
        String digits = s.replaceAll("\\D", "");
        return digits.length() >= 10;                          // 16216 এর মত শর্ট কোড ঠিক আছে
    }

    private static boolean containsIgnoreCase(String hay, String needle) {
        return hay != null && hay.toLowerCase(Locale.ROOT).contains(needle.toLowerCase(Locale.ROOT));
    }

    private static String extract(String text, String regex) {
        try {
            return extract(text, Pattern.compile(regex, FLAGS));
        } catch (Exception e) {
            return "";
        }
    }

    private static String extract(String text, Pattern p) {
        if (p == null || text == null) return "";
        try {
            Matcher m = p.matcher(text);
            if (m.find() && m.groupCount() >= 1 && m.group(1) != null) return m.group(1).trim();
        } catch (Exception ignored) {}
        return "";
    }

    private static double toAmount(String s) {
        if (s == null) return 0;
        String clean = s.replace(",", "").trim();
        while (clean.endsWith(".")) clean = clean.substring(0, clean.length() - 1);
        try {
            return Double.parseDouble(clean);
        } catch (Exception e) {
            return 0;
        }
    }
}
