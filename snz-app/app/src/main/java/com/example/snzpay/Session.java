package com.example.snzpay;

import android.content.Context;
import android.content.SharedPreferences;
import android.os.Build;
import android.provider.Settings;

import org.json.JSONArray;

import java.util.Locale;

/** লগইন তথ্য: কী, মেয়াদ, কোন wallet এর SMS পড়া যাবে — সব SQLite এ সেভ */
public final class Session {
    private Session() {}

    // সব তথ্য SQLite এ (Db এর kv টেবিল) — "sess." দিয়ে শুরু নামগুলো লগআউটে মুছে যায়
    private static Db db(Context c) {
        Db d = Db.get(c);
        migrateOnce(c, d);
        return d;
    }

    private static volatile boolean migrated = false;

    /** আগের ভার্সনের (SharedPreferences) লগইন থাকলে একবার SQLite এ নিয়ে আসা */
    private static void migrateOnce(Context c, Db d) {
        if (migrated) return;
        migrated = true;
        try {
            SharedPreferences old = c.getSharedPreferences("snz_session", Context.MODE_PRIVATE);
            String k = old.getString("key", "");
            if (!k.isEmpty() && d.getStr("sess.key", "").isEmpty()) {
                d.putStr("sess.key", k);
                d.putLong("sess.expire", old.getLong("expire", 0));
                String m = old.getString("methods", null);
                if (m != null) d.putStr("sess.methods", m);
            }
            old.edit().clear().apply();
        } catch (Exception ignored) {}
    }

    public static String key(Context c) { return db(c).getStr("sess.key", ""); }
    public static long expire(Context c) { return db(c).getLong("sess.expire", 0); }

    public static boolean isMaster(Context c) {
        return Config.MASTER_KEY.equalsIgnoreCase(key(c));
    }

    /** লগইন আছে এবং (মাস্টার কী বা) মেয়াদ শেষ হয়নি */
    public static boolean loggedIn(Context c) {
        String k = key(c);
        if (k.isEmpty()) return false;
        return Config.MASTER_KEY.equalsIgnoreCase(k) || System.currentTimeMillis() < expire(c);
    }

    public static void save(Context c, String key, long expire, JSONArray methods) {
        Db d = db(c);
        d.clearSession();
        d.putStr("sess.key", key);
        d.putLong("sess.expire", expire);
        if (methods != null) d.putStr("sess.methods", methods.toString());
        d.remove("reason");
    }

    public static void updateFromServer(Context c, long expire, JSONArray methods) {
        Db d = db(c);
        d.putLong("sess.streak", 0);
        if (expire > 0) d.putLong("sess.expire", expire);
        if (methods != null) d.putStr("sess.methods", methods.toString());
    }

    public static void logout(Context c, String reason) {
        Db d = db(c);
        d.clearSession();
        d.putStr("reason", reason == null ? "" : reason);
        SyncJob.cancelAll(c);
    }

    /** কেন লগআউট হয়েছিল (একবার দেখিয়ে মুছে যায়) */
    public static String takeReason(Context c) {
        Db d = db(c);
        String r = d.getStr("reason", "");
        d.remove("reason");
        return r;
    }

    public static int bumpStreak(Context c) {
        Db d = db(c);
        long s = d.getLong("sess.streak", 0) + 1;
        d.putLong("sess.streak", s);
        return (int) s;
    }

    public static long lastCheck(Context c) { return db(c).getLong("sess.last_check", 0); }
    public static void setLastCheck(Context c) { db(c).putLong("sess.last_check", System.currentTimeMillis()); }

    public static String deviceId(Context c) {
        String id = Settings.Secure.getString(c.getContentResolver(), Settings.Secure.ANDROID_ID);
        return id == null ? "" : id;
    }

    public static String deviceName() {
        String brand = Build.MANUFACTURER == null ? "" : Build.MANUFACTURER.trim();
        String model = Build.MODEL == null ? "" : Build.MODEL.trim();
        if (!brand.isEmpty()) brand = brand.substring(0, 1).toUpperCase(Locale.ROOT) + brand.substring(1);
        return model.toLowerCase(Locale.ROOT).startsWith(brand.toLowerCase(Locale.ROOT)) ? model : (brand + " " + model).trim();
    }

    // ---------------- কোন wallet এর SMS পড়া যাবে (ওয়েবসাইট → Wallet Setup এ ON করা) ----------------

    private static JSONArray methods(Context c) {
        if (isMaster(c)) return null;               // মাস্টার কী → সব
        String raw = db(c).getStr("sess.methods", null);
        if (raw == null) return null;               // পুরনো সার্ভার → কোনো বাধা নেই
        try { return new JSONArray(raw); } catch (Exception e) { return null; }
    }

    public static boolean methodAllowed(Context c, String method) {
        JSONArray a = methods(c);
        if (a == null) return true;
        String m = method.toUpperCase(Locale.ROOT).replaceAll("[^A-Z0-9]", "");
        for (int i = 0; i < a.length(); i++) {
            String t = a.optString(i, "");
            if (t.equals("*") || (!t.isEmpty() && m.contains(t))) return true;
        }
        return false;
    }

    public static boolean noWalletOn(Context c) {
        JSONArray a = methods(c);
        return a != null && a.length() == 0;
    }

    public static String walletsText(Context c) {
        JSONArray a = methods(c);
        if (a == null) return "All";
        StringBuilder sb = new StringBuilder();
        for (int i = 0; i < a.length(); i++) {
            if (sb.length() > 0) sb.append(", ");
            sb.append(a.optString(i));
        }
        return sb.toString();
    }

    // ---------------- শেষ অবস্থা (স্ক্রিনে দেখানোর জন্য) ----------------
    public static void setNote(Context c, String note) { db(c).putStr("sess.note", note == null ? "" : note); }
    public static String note(Context c) { return db(c).getStr("sess.note", ""); }
}
