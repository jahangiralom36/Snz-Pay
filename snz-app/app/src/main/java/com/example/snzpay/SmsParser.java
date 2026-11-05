package com.example.snzpay;

import java.util.Locale;
import java.util.regex.Matcher;
import java.util.regex.Pattern;

/**
 * টাকা আসার SMS থেকে Method, TrxID, Amount, প্রেরকের নম্বর বের করে।
 * সাধারণ মোবাইল নম্বর থেকে আসা SMS (ভুয়া হতে পারে) নেওয়া হয় না।
 */
public final class SmsParser {
    private SmsParser() {}

    public static final class Sms {
        public String method = "", trx = "", sender = "";
        public double amount;
    }

    private static final int F = Pattern.CASE_INSENSITIVE;
    private static final String AMT = "([0-9][0-9,]*(?:\\.[0-9]+)?)";

    public static Sms parse(String from, String body) {
        if (body == null || body.trim().isEmpty()) return null;
        if (from == null) from = "";
        String up = body.toUpperCase(Locale.ROOT);
        String snd = from.toUpperCase(Locale.ROOT);

        // শুধু টাকা আসার SMS
        boolean incoming = up.contains("RECEIVED") || up.contains("CASH IN") || up.contains("CASHIN")
                || up.contains("PAYMENT RECEIVED") || up.contains("CREDITED");
        if (!incoming || up.contains("DEBITED") && !up.contains("RECEIVED")) return null;
        // ভুয়া SMS সুরক্ষা: আসল bKash / Nagad নাম বা শর্ট কোড থেকে আসে, সাধারণ ১১ ডিজিট নম্বর থেকে না
        if (isPersonalNumber(from)) return null;

        Sms s = new Sms();
        s.method = method(snd, up);

        // TrxID — Rocket এ "TxnId:7100950328Date:01-NOV" এর মত লেগে থাকে
        s.trx = find(body, "(?:TrxID|TxnID|Txn\\s*ID|Transaction\\s*ID|Trans\\s*ID|Ref(?:erence)?\\s*(?:No)?)\\s*[:#.]?\\s*([0-9]{6,})(?=Date)");
        if (s.trx.isEmpty()) s.trx = find(body, "(?:TrxID|TxnID|Txn\\s*ID|Transaction\\s*ID|Trans\\s*ID|Ref(?:erence)?\\s*(?:No)?)\\s*[:#.]?\\s*([A-Za-z0-9]{5,})");
        s.trx = s.trx.toUpperCase(Locale.ROOT);

        // টাকা — প্রথমে "Amount: Tk", তারপর প্রথম "Tk" (ব্যালেন্স পরে আসে)
        String a = find(body, "Amount\\s*:?\\s*(?:Tk|BDT|৳)\\.?\\s*" + AMT);
        if (a.isEmpty()) a = find(body, "(?:Tk|BDT|৳)\\.?\\s*" + AMT);
        s.amount = toAmount(a);

        s.sender = find(body, "from\\s*(?:A/C\\s*:?\\s*)?([0-9*+]{4,})");
        if (s.sender.isEmpty()) s.sender = find(body, "Sender\\s*:?\\s*([0-9*+]{4,})");
        if (s.sender.isEmpty()) s.sender = find(body, "A/C\\s*:?\\s*([0-9*]{4,})");

        return s.trx.length() >= 5 && s.amount > 0 ? s : null;
    }

    private static String method(String snd, String up) {
        if (snd.contains("BKASH") || up.contains("BKASH")) return "BKASH";
        if (snd.contains("NAGAD") || up.contains("NAGAD")) return "NAGAD";
        if (snd.contains("16216") || snd.contains("ROCKET") || snd.contains("DBBL")) return "ROCKET";
        if (snd.contains("UPAY") || up.contains("UPAY")) return "UPAY";
        if (snd.contains("CELLFIN")) return "CELLFIN";
        if (snd.equals("TAP") || snd.contains("TAP.")) return "TAP";
        if (up.contains("BANGLA QR") || up.contains("QR PAYMENT") || snd.contains("TALLY") || up.contains("TALLYPAY")) return "BANGLA_QR";
        String clean = snd.replaceAll("[^A-Z0-9]", "");
        return clean.isEmpty() ? "OTHER" : clean;
    }

    /** সাধারণ মানুষের নম্বর (01XXXXXXXXX / +8801...) */
    static boolean isPersonalNumber(String sender) {
        String s = sender.replaceAll("[\\s-]", "");
        if (s.matches(".*[A-Za-z].*")) return false;
        return s.replaceAll("\\D", "").length() >= 10;
    }

    private static String find(String body, String regex) {
        try {
            Matcher m = Pattern.compile(regex, F).matcher(body);
            return m.find() ? m.group(1).trim() : "";
        } catch (Exception e) {
            return "";
        }
    }

    private static double toAmount(String s) {
        try { return Double.parseDouble(s.replace(",", "")); } catch (Exception e) { return 0; }
    }
}
