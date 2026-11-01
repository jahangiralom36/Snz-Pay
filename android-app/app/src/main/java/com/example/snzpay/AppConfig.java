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

    public static HttpResult httpGet(String url, int timeoutMs) throws Exception {
        HttpURLConnection conn = (HttpURLConnection) new URL(url).openConnection();
        conn.setRequestMethod("GET");
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

    public static HttpResult httpPostJson(String url, JSONObject body, int timeoutMs) throws Exception {
        HttpURLConnection conn = (HttpURLConnection) new URL(url).openConnection();
        conn.setRequestMethod("POST");
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
