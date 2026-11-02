package com.example.snzpay;

import android.content.Context;
import android.content.SharedPreferences;
import android.content.pm.PackageInfo;
import android.graphics.Bitmap;
import android.graphics.BitmapFactory;
import android.net.ConnectivityManager;
import android.net.NetworkInfo;
import android.os.Build;
import android.os.Handler;
import android.os.Looper;

import org.json.JSONObject;

import java.io.BufferedReader;
import java.io.File;
import java.io.FileOutputStream;
import java.io.InputStream;
import java.io.InputStreamReader;
import java.io.OutputStream;
import java.net.HttpURLConnection;
import java.net.URL;
import java.nio.charset.StandardCharsets;

/**
 * সব সার্ভার লিংক, টোকেন আর ওয়েবসাইট থেকে আসা অ্যাপ সেটিংস এক জায়গায়।
 * ওয়েবসাইট: Admin Panel → App Settings / SMS Rules → api/app_config.php
 */
public final class AppConfig {

    public static final String BASE_URL = "https://snzpay.digital";
    public static final String GATEWAY_URL = BASE_URL + "/api/app_gateway.php";
    public static final String VERIFY_KEY_URL = BASE_URL + "/api/verify_key.php";
    public static final String CONFIG_URL = BASE_URL + "/api/app_config.php";

    public static final String SECRET_TOKEN = "CHANGE_ME_APP_TOKEN";
    public static final String MASTER_KEY = "CHANGE_ME_MASTER_KEY";

    public static final String AUTH_PREFS = "SNZ_AUTH";
    private static final String CONFIG_PREFS = "SNZ_CONFIG";
    private static final String LOGO_FILE = "remote_logo.png";

    public static final String ACTION_REFRESH = "REFRESH_DASHBOARD";

    private AppConfig() {}

    // ---------------------------------------------------------------------
    // ক্যাশ করা কনফিগ (নেট না থাকলেও শেষবারের সেটিংস কাজ করবে)
    // ---------------------------------------------------------------------
    public static JSONObject get(Context ctx) {
        String raw = ctx.getSharedPreferences(CONFIG_PREFS, Context.MODE_PRIVATE).getString("json", "");
        try {
            return raw.isEmpty() ? new JSONObject() : new JSONObject(raw);
        } catch (Exception e) {
            return new JSONObject();
        }
    }

    public static boolean isStale(Context ctx, long maxAgeMs) {
        long last = ctx.getSharedPreferences(CONFIG_PREFS, Context.MODE_PRIVATE).getLong("fetched_at", 0);
        return System.currentTimeMillis() - last > maxAgeMs;
    }

    /** সার্ভার থেকে নতুন সেটিংস আনে (ব্যাকগ্রাউন্ড থ্রেড থেকে কল করতে হবে) */
    public static boolean fetch(Context ctx) {
        try {
            HttpResult res = httpGet(CONFIG_URL, 8000);
            if (res.code != 200) return false;
            JSONObject json = new JSONObject(res.body);
            if (!"ok".equals(json.optString("status"))) return false;

            SharedPreferences prefs = ctx.getSharedPreferences(CONFIG_PREFS, Context.MODE_PRIVATE);
            String oldLogo = get(ctx).optString("logo_url", "");
            prefs.edit()
                    .putString("json", json.toString())
                    .putLong("fetched_at", System.currentTimeMillis())
                    .apply();

            String newLogo = json.optString("logo_url", "");
            File logoFile = new File(ctx.getFilesDir(), LOGO_FILE);
            if (newLogo.isEmpty()) {
                if (logoFile.exists()) logoFile.delete();
            } else if (!newLogo.equals(oldLogo) || !logoFile.exists()) {
                downloadFile(newLogo, logoFile);
            }
            return true;
        } catch (Exception e) {
            return false;
        }
    }

    public static void fetchAsync(Context ctx, Runnable onDone) {
        final Context app = ctx.getApplicationContext();
        new Thread(() -> {
            fetch(app);
            if (onDone != null) new Handler(Looper.getMainLooper()).post(onDone);
        }).start();
    }

    /** ওয়েবসাইট থেকে সেট করা লোগো (না থাকলে null → অ্যাপের নিজের লোগো দেখাবে) */
    public static Bitmap getLogo(Context ctx) {
        File f = new File(ctx.getFilesDir(), LOGO_FILE);
        if (!f.exists() || get(ctx).optString("logo_url", "").isEmpty()) return null;
        try {
            return BitmapFactory.decodeFile(f.getAbsolutePath());
        } catch (Exception e) {
            return null;
        }
    }

    public static String getLicenseKey(Context ctx) {
        return ctx.getSharedPreferences(AUTH_PREFS, Context.MODE_PRIVATE).getString("SAVED_KEY", "");
    }

    public static boolean isLoggedIn(Context ctx) {
        return !getLicenseKey(ctx).isEmpty();
    }

    public static boolean isAppEnabled(Context ctx) {
        return get(ctx).optBoolean("app_enabled", true);
    }

    // ---------------------------------------------------------------------
    // লাইসেন্স কী সার্ভারে আছে কিনা নিয়মিত যাচাই
    // শুধু সার্ভার পরিষ্কার "invalid / expired / অন্য ফোনে লক" বললেই লগআউট —
    // নেট নেই, সার্ভার ডাউন, 404/500 হলে কখনো লগআউট হবে না।
    // ---------------------------------------------------------------------
    public static final int KEY_VALID = 1;
    public static final int KEY_INVALID = 2;
    public static final int KEY_UNKNOWN = 0;

    public static int verifyKeyNow(Context ctx) {
        SharedPreferences prefs = ctx.getSharedPreferences(AUTH_PREFS, Context.MODE_PRIVATE);
        String key = prefs.getString("SAVED_KEY", "");
        if (key.isEmpty()) return KEY_INVALID;
        if (MASTER_KEY.equalsIgnoreCase(key)) return KEY_VALID;
        try {
            JSONObject req = new JSONObject();
            req.put("license_key", key);
            req.put("device_id", prefs.getString("LOCKED_DEVICE", ""));
            req.put("device_name", (Build.MANUFACTURER + " " + Build.MODEL).trim());
            HttpResult res = httpPostJson(VERIFY_KEY_URL, req, 10000);
            JSONObject json;
            try { json = new JSONObject(res.body); } catch (Exception e) { return KEY_UNKNOWN; }   // HTML এরর পেজ ইত্যাদি
            if (res.code == 200 && "valid".equals(json.optString("status"))) {
                long exp = json.optLong("expires_timestamp", 0);
                if (exp > 0) prefs.edit().putLong("EXPIRE_TIME", exp).apply();   // অ্যাডমিন মেয়াদ বাড়ালে/কমালে আপডেট
                prefs.edit().putLong("KEY_CHECKED_AT", System.currentTimeMillis()).apply();
                return KEY_VALID;
            }
            if (res.code == 403 && "error".equals(json.optString("status"))) {
                prefs.edit().putString("LOGOUT_REASON", json.optString("message", "Your license key is no longer valid.")).apply();
                return KEY_INVALID;
            }
            return KEY_UNKNOWN;
        } catch (Exception e) {
            return KEY_UNKNOWN;
        }
    }

    /** কী বাতিল → সব বন্ধ করে লগইন স্ক্রিনে পাঠানোর জন্য প্রস্তুত */
    public static void forceLogout(Context ctx) {
        SharedPreferences prefs = ctx.getSharedPreferences(AUTH_PREFS, Context.MODE_PRIVATE);
        String reason = prefs.getString("LOGOUT_REASON", "");
        prefs.edit().clear().putString("LOGOUT_REASON", reason).commit();
        SyncJobService.cancelAll(ctx);
        try { ctx.stopService(new android.content.Intent(ctx, PaymentService.class)); } catch (Exception ignored) {}
    }

    @SuppressWarnings("deprecation")
    public static int currentVersionCode(Context ctx) {
        try {
            PackageInfo pi = ctx.getPackageManager().getPackageInfo(ctx.getPackageName(), 0);
            return Build.VERSION.SDK_INT >= 28 ? (int) pi.getLongVersionCode() : pi.versionCode;
        } catch (Exception e) {
            return 0;
        }
    }

    @SuppressWarnings("deprecation")
    public static boolean isOnline(Context ctx) {
        try {
            ConnectivityManager cm = (ConnectivityManager) ctx.getSystemService(Context.CONNECTIVITY_SERVICE);
            if (cm == null) return true;
            NetworkInfo ni = cm.getActiveNetworkInfo();
            return ni != null && ni.isConnected();
        } catch (Exception e) {
            return true;
        }
    }

    // ---------------------------------------------------------------------
    // HTTP helpers
    // ---------------------------------------------------------------------
    public static final class HttpResult {
        public final int code;
        public final String body;
        HttpResult(int code, String body) { this.code = code; this.body = body; }
    }

    // কিছু হোস্টিং এর ফায়ারওয়াল (ModSecurity / Imunify360) অ্যাপের ডিফল্ট পরিচয় দেখলে 403/404 দেয়
    private static final String USER_AGENT = "Mozilla/5.0 (Linux; Android " + Build.VERSION.RELEASE + ") AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120 Mobile Safari/537.36 SNZPayApp/2";

    private static void commonHeaders(HttpURLConnection conn) {
        conn.setRequestProperty("User-Agent", USER_AGENT);
        conn.setRequestProperty("Accept", "application/json, text/plain, */*");
        conn.setRequestProperty("X-Requested-With", "com.example.snzpay");
    }

    public static HttpResult httpGet(String url, int timeoutMs) throws Exception {
        HttpURLConnection conn = (HttpURLConnection) new URL(url).openConnection();
        conn.setRequestMethod("GET");
        commonHeaders(conn);
        conn.setConnectTimeout(timeoutMs);
        conn.setReadTimeout(timeoutMs);
        conn.setUseCaches(false);
        try {
            int code = conn.getResponseCode();
            return new HttpResult(code, readBody(conn, code));
        } finally {
            conn.disconnect();
        }
    }

    /** উত্তরটা কি আমাদের API এর JSON? (না হলে মাঝপথে ফায়ারওয়াল/ভুল পেজ) */
    public static boolean isApiJson(HttpResult r) {
        if (r == null || r.body == null) return false;
        String b = r.body.trim();
        if (!b.startsWith("{")) return false;
        try { return new JSONObject(b).has("status"); } catch (Exception e) { return false; }
    }

    /**
     * JSON POST — ফায়ারওয়াল আটকালে (API JSON না এলে) নিজে থেকে
     * ১) form POST, ২) GET দিয়ে আবার চেষ্টা করে। সার্ভার তিনভাবেই বোঝে।
     */
    public static HttpResult httpPostJson(String url, JSONObject body, int timeoutMs) throws Exception {
        HttpResult first = null;
        Exception firstErr = null;
        try {
            first = rawPostJson(url, body, timeoutMs);
            if (isApiJson(first)) return first;
        } catch (java.net.UnknownHostException | java.net.SocketTimeoutException e) {
            throw e;   // নেট/টাইমআউট — আবার চেষ্টা করে লাভ নেই
        } catch (Exception e) {
            firstErr = e;
        }
        try {
            HttpResult form = rawPostForm(url, body, timeoutMs);
            if (isApiJson(form)) return form;
            HttpResult get = httpGet(url + (url.contains("?") ? "&" : "?") + toQuery(body), timeoutMs);
            if (isApiJson(get)) return get;
        } catch (Exception ignored) {}
        if (first != null) return first;
        throw firstErr != null ? firstErr : new Exception("No response");
    }

    private static String toQuery(JSONObject body) throws Exception {
        StringBuilder sb = new StringBuilder();
        java.util.Iterator<String> it = body.keys();
        while (it.hasNext()) {
            String k = it.next();
            if (sb.length() > 0) sb.append('&');
            sb.append(java.net.URLEncoder.encode(k, "UTF-8")).append('=')
              .append(java.net.URLEncoder.encode(String.valueOf(body.opt(k)), "UTF-8"));
        }
        return sb.toString();
    }

    private static HttpResult rawPostForm(String url, JSONObject body, int timeoutMs) throws Exception {
        HttpURLConnection conn = (HttpURLConnection) new URL(url).openConnection();
        conn.setRequestMethod("POST");
        commonHeaders(conn);
        conn.setRequestProperty("Content-Type", "application/x-www-form-urlencoded; charset=utf-8");
        conn.setDoOutput(true);
        conn.setConnectTimeout(timeoutMs);
        conn.setReadTimeout(timeoutMs);
        conn.setUseCaches(false);
        try {
            try (OutputStream os = conn.getOutputStream()) {
                os.write(toQuery(body).getBytes(StandardCharsets.UTF_8));
            }
            int code = conn.getResponseCode();
            return new HttpResult(code, readBody(conn, code));
        } finally {
            conn.disconnect();
        }
    }

    private static HttpResult rawPostJson(String url, JSONObject body, int timeoutMs) throws Exception {
        HttpURLConnection conn = (HttpURLConnection) new URL(url).openConnection();
        conn.setRequestMethod("POST");
        commonHeaders(conn);
        conn.setRequestProperty("Content-Type", "application/json; charset=utf-8");
        conn.setDoOutput(true);
        conn.setConnectTimeout(timeoutMs);
        conn.setReadTimeout(timeoutMs);
        conn.setUseCaches(false);
        try {
            try (OutputStream os = conn.getOutputStream()) {
                os.write(body.toString().getBytes(StandardCharsets.UTF_8));
            }
            int code = conn.getResponseCode();
            return new HttpResult(code, readBody(conn, code));
        } finally {
            conn.disconnect();
        }
    }

    private static String readBody(HttpURLConnection conn, int code) {
        try {
            InputStream in = code >= 400 ? conn.getErrorStream() : conn.getInputStream();
            if (in == null) return "";
            StringBuilder sb = new StringBuilder();
            try (BufferedReader r = new BufferedReader(new InputStreamReader(in, StandardCharsets.UTF_8))) {
                String line;
                while ((line = r.readLine()) != null) sb.append(line);
            }
            return sb.toString();
        } catch (Exception e) {
            return "";
        }
    }

    private static void downloadFile(String url, File out) {
        HttpURLConnection conn = null;
        try {
            conn = (HttpURLConnection) new URL(url).openConnection();
            commonHeaders(conn);
            conn.setConnectTimeout(8000);
            conn.setReadTimeout(10000);
            if (conn.getResponseCode() != 200) return;
            File tmp = new File(out.getAbsolutePath() + ".tmp");
            try (InputStream in = conn.getInputStream(); FileOutputStream fos = new FileOutputStream(tmp)) {
                byte[] buf = new byte[8192];
                int n;
                while ((n = in.read(buf)) > 0) fos.write(buf, 0, n);
            }
            // সত্যিই ছবি কিনা যাচাই
            if (BitmapFactory.decodeFile(tmp.getAbsolutePath()) != null) {
                if (out.exists()) out.delete();
                tmp.renameTo(out);
            } else {
                tmp.delete();
            }
        } catch (Exception ignored) {
        } finally {
            if (conn != null) conn.disconnect();
        }
    }
}
