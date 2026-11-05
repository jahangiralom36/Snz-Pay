package com.example.snzpay;

import android.Manifest;
import android.content.Context;
import android.content.SharedPreferences;
import android.content.pm.PackageManager;
import android.database.Cursor;
import android.net.ConnectivityManager;
import android.net.NetworkCapabilities;
import android.net.Uri;

import org.json.JSONObject;

import java.util.List;

/** SMS → ফোনের ডাটাবেজ → সার্ভার */
public final class Sync {
    private Sync() {}

    private static final Object LOCK = new Object();

    public static boolean hasSmsPermission(Context c) {
        return c.checkSelfPermission(Manifest.permission.RECEIVE_SMS) == PackageManager.PERMISSION_GRANTED
                && c.checkSelfPermission(Manifest.permission.READ_SMS) == PackageManager.PERMISSION_GRANTED;
    }

    public static boolean online(Context c) {
        try {
            ConnectivityManager cm = (ConnectivityManager) c.getSystemService(Context.CONNECTIVITY_SERVICE);
            if (cm == null) return true;
            NetworkCapabilities nc = cm.getNetworkCapabilities(cm.getActiveNetwork());
            return nc != null && nc.hasCapability(NetworkCapabilities.NET_CAPABILITY_INTERNET);
        } catch (Exception e) {
            return true;
        }
    }

    /** একটা SMS পড়ে সেভ — ওয়েবসাইটে ON করা wallet হলেই */
    public static boolean handle(Context c, String from, String body, long time) {
        if (!Session.loggedIn(c)) return false;
        SmsParser.Sms s = SmsParser.parse(from, body);
        if (s == null || !Session.methodAllowed(c, s.method)) return false;
        return Db.get(c).insert(s.method, s.trx, s.amount, s.sender, body, time);
    }

    /** জমে থাকা SMS সার্ভারে পাঠানো। @return এখনো কয়টা বাকি */
    public static int upload(Context c) {
        synchronized (LOCK) {
            Db db = Db.get(c);
            if (!Session.loggedIn(c)) return db.pendingCount();
            if (!online(c)) {
                Session.setNote(c, "No internet — SMS are saved on this phone.");
                return db.pendingCount();
            }
            for (int round = 0; round < 5; round++) {
                List<Db.Row> rows = db.pending(20);
                if (rows.isEmpty()) break;
                for (Db.Row r : rows) {
                    int res = send(c, r);
                    if (res == 0) db.setStatus(r.id, Db.SENT, null);
                    else if (res == 2) db.setStatus(r.id, Db.REJECTED, Session.note(c));
                    else return db.pendingCount();   // পরে আবার চেষ্টা
                }
            }
            int left = db.pendingCount();
            if (left == 0) Session.setNote(c, "");
            return left;
        }
    }

    /** 0 = গেছে, 1 = পরে আবার, 2 = সার্ভার নেবে না */
    private static int send(Context c, Db.Row r) {
        try {
            JSONObject b = new JSONObject();
            b.put("secret_key", Config.SECRET_TOKEN);
            b.put("license_key", Session.key(c));
            b.put("method", r.method);
            b.put("trx_id", r.trx);
            b.put("amount", String.valueOf(r.amount));
            b.put("sender_number", r.sender == null ? "" : r.sender);
            b.put("raw_message", r.body == null ? "" : r.body);
            b.put("sms_time", r.time);
            Api.Res res = Api.post(Config.GATEWAY_URL, b);
            String msg = res.json == null ? "" : res.json.optString("message", "");
            if (res.code == 200 && res.json != null) {
                String st = res.json.optString("status");
                if ("success".equals(st) || "already_exists".equals(st)) return 0;
            }
            if (res.code == 422) { Session.setNote(c, msg); return 2; }
            if (res.json == null) {
                Session.setNote(c, "Server problem (HTTP " + res.code + ") — will retry automatically.");
            } else if (res.code == 403 && msg.toLowerCase().contains("unauthorized")) {
                Session.setNote(c, "App token does not match the website (APP_SECRET_TOKEN).");
            } else if (res.code == 403) {
                Session.setNote(c, msg);
                KeyCheck.check(c);
            } else {
                Session.setNote(c, msg.isEmpty() ? "Server error (HTTP " + res.code + ") — will retry." : msg);
            }
            return 1;
        } catch (java.net.UnknownHostException e) {
            Session.setNote(c, "No internet — SMS are saved on this phone.");
            return 1;
        } catch (Exception e) {
            Session.setNote(c, "Server not reachable — will retry automatically.");
            return 1;
        }
    }

    private static volatile long lastScan = 0;

    /** ফোন বন্ধ / অ্যাপ বন্ধ থাকায় মিস হওয়া SMS Inbox থেকে খোঁজা (শেষ ২ দিন) */
    public static void scanInbox(Context c) {
        if (!hasSmsPermission(c) || !Session.loggedIn(c)) return;
        long nowR = android.os.SystemClock.elapsedRealtime();
        if (nowR - lastScan < 60 * 1000L) return;
        lastScan = nowR;
        SharedPreferences p = c.getSharedPreferences("snz_scan", Context.MODE_PRIVATE);
        long now = System.currentTimeMillis();
        long since = Math.max(p.getLong("last", now - 2L * 86400000), now - 2L * 86400000);
        long max = since;
        try (Cursor cur = c.getContentResolver().query(Uri.parse("content://sms/inbox"),
                new String[]{"address", "body", "date"}, "date > ?", new String[]{String.valueOf(since)}, "date ASC")) {
            if (cur == null) return;
            int n = 0;
            while (cur.moveToNext() && n++ < 200) {
                long d = cur.getLong(2);
                handle(c, cur.getString(0), cur.getString(1), d);
                if (d > max) max = d;
            }
        } catch (Exception e) {
            return;
        }
        p.edit().putLong("last", max).apply();
    }
}
