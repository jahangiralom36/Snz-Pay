package com.example.snzpay;

import android.content.Context;
import android.content.SharedPreferences;
import android.os.Build;
import android.provider.Settings;

import org.json.JSONArray;

import java.util.Locale;

/** লগইন তথ্য: কী, মেয়াদ, কোন wallet এর SMS পড়া যাবে */
public final class Session {
    private Session() {}

    private static SharedPreferences p(Context c) {
        return c.getSharedPreferences("snz_session", Context.MODE_PRIVATE);
    }

    public static String key(Context c) { return p(c).getString("key", ""); }
    public static long expire(Context c) { return p(c).getLong("expire", 0); }

    public static boolean isMaster(Context c) {
        return Config.MASTER_KEY.equalsIgnoreCase(key(c));
    }

    /** লগইন আছে এবং (মাস্টার কী বা) মেয়াদ শেষ হয়নি */
    public static boolean loggedIn(Context c) {
        if (key(c).isEmpty()) return false;
        return isMaster(c) || System.currentTimeMillis() < expire(c);
    }

    public static void save(Context c, String key, long expire, JSONArray methods) {
        SharedPreferences.Editor e = p(c).edit().putString("key", key).putLong("expire", expire).putInt("streak", 0);
        if (methods != null) e.putString("methods", methods.toString());
        e.remove("reason").apply();
    }

    public static void updateFromServer(Context c, long expire, JSONArray methods) {
        SharedPreferences.Editor e = p(c).edit().putInt("streak", 0);
        if (expire > 0) e.putLong("expire", expire);
        if (methods != null) e.putString("methods", methods.toString());
        e.apply();
    }

    public static void logout(Context c, String reason) {
        p(c).edit().clear().putString("reason", reason == null ? "" : reason).apply();
        SyncJob.cancelAll(c);
    }

    /** কেন লগআউট হয়েছিল (একবার দেখিয়ে মুছে যায়) */
    public static String takeReason(Context c) {
        String r = p(c).getString("reason", "");
        p(c).edit().remove("reason").apply();
        return r;
    }

    public static int bumpStreak(Context c) {
        int s = p(c).getInt("streak", 0) + 1;
        p(c).edit().putInt("streak", s).apply();
        return s;
    }

    public static long lastCheck(Context c) { return p(c).getLong("last_check", 0); }
    public static void setLastCheck(Context c) { p(c).edit().putLong("last_check", System.currentTimeMillis()).apply(); }

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
        String raw = p(c).getString("methods", null);
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
    public static void setNote(Context c, String note) { p(c).edit().putString("note", note == null ? "" : note).apply(); }
    public static String note(Context c) { return p(c).getString("note", ""); }
}
