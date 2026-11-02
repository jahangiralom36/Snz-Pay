package com.example.snzpay;

import android.content.Context;
import android.content.SharedPreferences;
import android.os.Build;

import org.json.JSONArray;
import org.json.JSONObject;

import java.io.PrintWriter;
import java.io.StringWriter;

/**
 * অ্যাপের error ওয়েবসাইটে পাঠায় → Admin Panel → ☰ → Errors → App Error।
 * নেট না থাকলে ফোনে জমা থাকে (সর্বোচ্চ ৩০টা), পরে নিজে থেকেই যায়।
 * একই error ১০ মিনিটে একবারের বেশি পাঠানো হয় না।
 */
public final class ErrorReporter {

    private static final String PREFS = "SNZ_ERRORS";
    private static final String KEY_QUEUE = "queue";
    private static final long SAME_ERROR_GAP = 10 * 60 * 1000L;
    private static final Object LOCK = new Object();
    private static volatile boolean crashHandlerSet = false;

    private ErrorReporter() {}

    public static String reportUrl() {
        return AppConfig.BASE_URL + "/api/app_error.php";
    }

    /** অ্যাপ crash করলে কারণটা সেভ করে রাখা — পরের বার চালু হলে সার্ভারে যাবে */
    public static void installCrashHandler(Context ctx) {
        if (crashHandlerSet) return;
        crashHandlerSet = true;
        final Context app = ctx.getApplicationContext();
        final Thread.UncaughtExceptionHandler previous = Thread.getDefaultUncaughtExceptionHandler();
        Thread.setDefaultUncaughtExceptionHandler((t, e) -> {
            try {
                StringWriter sw = new StringWriter();
                e.printStackTrace(new PrintWriter(sw));
                String trace = sw.toString();
                if (trace.length() > 3500) trace = trace.substring(0, 3500);
                add(app, "App crash: " + e.getClass().getSimpleName(),
                        e.getMessage() == null ? "The app stopped unexpectedly" : e.getMessage(),
                        "Thread: " + t.getName() + "\n" + trace, true);
            } catch (Throwable ignored) {}
            if (previous != null) previous.uncaughtException(t, e);
        });
    }

    /** error জমা করা + পাঠানোর চেষ্টা (যেকোনো থ্রেড থেকে ডাকা যায়) */
    public static void report(Context ctx, String title, String message, String detail) {
        if (ctx == null) return;
        final Context app = ctx.getApplicationContext();
        if (add(app, title, message, detail, false)) flushAsync(app);
    }

    /** @return নতুন করে জমা হলে true */
    private static boolean add(Context ctx, String title, String message, String detail, boolean sync) {
        synchronized (LOCK) {
            SharedPreferences p = ctx.getSharedPreferences(PREFS, Context.MODE_PRIVATE);
            String sig = (title + "|" + message).replaceAll("\\d{1,2}:\\d{2}", "");
            long now = System.currentTimeMillis();
            String lastKey = "last_" + Integer.toHexString(sig.hashCode());
            if (now - p.getLong(lastKey, 0) < SAME_ERROR_GAP) return false;
            try {
                JSONArray q = new JSONArray(p.getString(KEY_QUEUE, "[]"));
                JSONObject o = new JSONObject();
                o.put("title", cut(title, 180));
                o.put("message", cut(message, 1500));
                o.put("detail", cut((detail == null ? "" : detail + "\n") + "Time on phone: "
                        + new java.text.SimpleDateFormat("yyyy-MM-dd HH:mm:ss", java.util.Locale.US).format(new java.util.Date(now)), 4000));
                o.put("where", "Android app");
                q.put(o);
                while (q.length() > 30) q.remove(0);
                SharedPreferences.Editor ed = p.edit().putString(KEY_QUEUE, q.toString()).putLong(lastKey, now);
                if (sync) ed.commit(); else ed.apply();
                return true;
            } catch (Exception e) {
                return false;
            }
        }
    }

    public static void flushAsync(Context ctx) {
        final Context app = ctx.getApplicationContext();
        new Thread(() -> flush(app)).start();
    }

    /** জমে থাকা error সার্ভারে পাঠানো (ব্যাকগ্রাউন্ড থ্রেড) */
    public static void flush(Context ctx) {
        String queue;
        synchronized (LOCK) {
            queue = ctx.getSharedPreferences(PREFS, Context.MODE_PRIVATE).getString(KEY_QUEUE, "[]");
        }
        if ("[]".equals(queue) || !AppConfig.isOnline(ctx)) return;
        try {
            JSONArray items = new JSONArray(queue);
            if (items.length() == 0) return;
            JSONObject body = new JSONObject();
            body.put("secret_key", AppConfig.SECRET_TOKEN);
            body.put("license_key", AppConfig.getLicenseKey(ctx));
            body.put("device", deviceName());
            body.put("app_version", appVersion(ctx));
            body.put("errors", items);
            AppConfig.HttpResult r = AppConfig.httpPostJson(reportUrl(), body, 10000);
            if (r != null && r.code == 200) {
                synchronized (LOCK) {
                    // পাঠানোর মাঝে নতুন কিছু এলে সেগুলো রেখে দেওয়া
                    SharedPreferences p = ctx.getSharedPreferences(PREFS, Context.MODE_PRIVATE);
                    JSONArray now = new JSONArray(p.getString(KEY_QUEUE, "[]"));
                    JSONArray rest = new JSONArray();
                    for (int i = items.length(); i < now.length(); i++) rest.put(now.get(i));
                    p.edit().putString(KEY_QUEUE, rest.toString()).apply();
                }
            }
        } catch (Exception ignored) {
            // পরে আবার চেষ্টা হবে
        }
    }

    static String deviceName() {
        String brand = Build.MANUFACTURER == null ? "" : Build.MANUFACTURER.trim();
        String model = Build.MODEL == null ? "" : Build.MODEL.trim();
        if (!brand.isEmpty()) brand = brand.substring(0, 1).toUpperCase() + brand.substring(1);
        String name = model.toLowerCase().startsWith(brand.toLowerCase()) ? model : (brand + " " + model).trim();
        return name + " (Android " + Build.VERSION.RELEASE + ")";
    }

    static String appVersion(Context ctx) {
        try {
            return ctx.getPackageManager().getPackageInfo(ctx.getPackageName(), 0).versionName;
        } catch (Exception e) {
            return "";
        }
    }

    private static String cut(String s, int n) {
        if (s == null) return "";
        return s.length() > n ? s.substring(0, n) : s;
    }
}
