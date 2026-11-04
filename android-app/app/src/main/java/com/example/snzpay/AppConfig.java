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
        ErrorReporter.report(ctx, "Auto logout: license key not valid",
                reason.isEmpty() ? "The app logged out because the key is no longer valid" : reason,
                "Key: " + prefs.getString("SAVED_KEY", ""));
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
        public boolean enc;   // সার্ভার এনকোড করা ডাটা বুঝেছে (X-SNZ-Enc হেডার)
        HttpResult(int code, String body) { this.code = code; this.body = cleanJson(body); }
    }

    /** উত্তরের আগে PHP warning / বাড়তি লেখা থাকলে বাদ দিয়ে শুধু JSON রাখা */
    static String cleanJson(String b) {
        if (b == null) return "";
        String t = b.trim();
        if (t.startsWith("{") || t.startsWith("[")) return t;
        int i = t.indexOf("{\"status\"");
        int j = t.lastIndexOf('}');
        if (i >= 0 && j > i) {
            String cut = t.substring(i, j + 1);
            try { new JSONObject(cut); return cut; } catch (Exception ignored) {}
        }
        return b;
    }

    // কিছু হোস্টিং এর ফায়ারওয়াল (ModSecurity / Imunify360) অ্যাপের ডিফল্ট পরিচয় দেখলে 403/404 দেয়
    private static final String USER_AGENT = "Mozilla/5.0 (Linux; Android " + Build.VERSION.RELEASE + ") AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120 Mobile Safari/537.36 SNZPayApp/2";

    private static void commonHeaders(HttpURLConnection conn) {
        conn.setRequestProperty("User-Agent", USER_AGENT);
        conn.setRequestProperty("Accept", "application/json, text/plain, */*");
        conn.setRequestProperty("X-Requested-With", "com.example.snzpay");
        // "Unblock" স্ক্রিনে ফায়ারওয়ালের যাচাই পার হলে তার কুকি সাথে পাঠানো
        try {
            String ck = android.webkit.CookieManager.getInstance().getCookie(BASE_URL);
            if (ck != null && !ck.isEmpty()) conn.setRequestProperty("Cookie", ck);
        } catch (Throwable ignored) {}
    }

    /** firewall-এ ধরা না পড়ার জন্য: পুরো ডাটা একটা ফিল্ডে এনকোড করে (p=...) */
    private static HttpResult rawPostEncoded(String url, JSONObject body, int timeoutMs) throws Exception {
        HttpURLConnection conn = (HttpURLConnection) new URL(url).openConnection();
        conn.setRequestMethod("POST");
        commonHeaders(conn);
        conn.setRequestProperty("Content-Type", "application/x-www-form-urlencoded; charset=utf-8");
        conn.setDoOutput(true);
        conn.setConnectTimeout(timeoutMs);
        conn.setReadTimeout(timeoutMs);
        conn.setUseCaches(false);
        String enc = android.util.Base64.encodeToString(body.toString().getBytes(StandardCharsets.UTF_8),
                android.util.Base64.URL_SAFE | android.util.Base64.NO_WRAP | android.util.Base64.NO_PADDING);
        try (OutputStream os = conn.getOutputStream()) {
            os.write(("p=" + enc).getBytes(StandardCharsets.UTF_8));
        }
        int code = conn.getResponseCode();
        HttpResult r = new HttpResult(code, readBody(conn, code));
        r.enc = conn.getHeaderField("X-SNZ-Enc") != null;
        return r;
    }

    /** ফায়ারওয়াল যাচাই পার হলে (Unblock স্ক্রিন) সাথে সাথে আবার চেষ্টা */
    public static void clearFirewall() { fwUntil = 0; fwBackoff = 0; fwInfo = ""; }

    public static HttpResult httpGet(String url, int timeoutMs) throws Exception {
        if (preferDirect()) {
            HttpResult d = viaFreshDns("GET", url, null, null, timeoutMs);
            if (d != null && d.code == 200) return d;
        }
        HttpResult normal = null;
        Exception err = null;
        try { normal = rawGet(url, timeoutMs); } catch (Exception e) { err = e; }
        if (normal != null && normal.code == 200) return normal;
        // ফোনের DNS পুরনো সার্ভারে পাঠালে → নতুন IP দিয়ে সরাসরি
        HttpResult d = viaFreshDns("GET", url, null, null, timeoutMs);
        if (d != null && d.code == 200) return d;
        if (normal != null) return normal;
        throw err != null ? err : new Exception("No response");
    }

    private static HttpResult rawGet(String url, int timeoutMs) throws Exception {
        HttpURLConnection conn = (HttpURLConnection) new URL(url).openConnection();
        conn.setRequestMethod("GET");
        commonHeaders(conn);
        conn.setConnectTimeout(timeoutMs);
        conn.setReadTimeout(timeoutMs);
        conn.setUseCaches(false);
        {
            int code = conn.getResponseCode();
            return new HttpResult(code, readBody(conn, code));
        }
    }

    // =====================================================================
    // 🌐 পুরনো DNS সমস্যার স্থায়ী সমাধান
    // হোস্টিং বদলালে মোবাইল অপারেটর কয়েকদিন পুরনো IP দিতে পারে (তখন 404)।
    // তখন অ্যাপ Google / Cloudflare DNS-over-HTTPS থেকে আসল IP নেয় এবং
    // সেই IP তে সরাসরি HTTPS কানেক্ট করে — সার্টিফিকেট আসল ডোমেইন নামেই যাচাই হয়।
    // =====================================================================
    /** নতুন হোস্টিং এর IP (check.php তে "Server IP") — শেষ ভরসা; সার্টিফিকেট তবুও যাচাই হয় */
    static final String FALLBACK_IP = "192.250.235.43";
    private static volatile String directIp = null;
    private static volatile long directUntil = 0;

    private static boolean preferDirect() {
        return directIp != null && System.currentTimeMillis() < directUntil;
    }

    /** DNS-over-HTTPS → domain এর A record */
    private static volatile String dohHost = null, dohIp = null;
    private static volatile long dohUntil = 0;

    static String freshDnsIp(String host) {
        if (host.equals(dohHost) && System.currentTimeMillis() < dohUntil) return dohIp;
        String ip = lookupDoh(host);
        dohHost = host;
        dohIp = ip;
        dohUntil = System.currentTimeMillis() + (ip != null ? 10 : 2) * 60 * 1000L;   // ১০ মিনিট মনে রাখা
        return ip;
    }

    private static String lookupDoh(String host) {
        String[][] resolvers = {
                {"https://dns.google/resolve?type=A&name=", null},
                {"https://cloudflare-dns.com/dns-query?type=A&name=", "application/dns-json"},
        };
        for (String[] r : resolvers) {
            HttpURLConnection c = null;
            try {
                c = (HttpURLConnection) new URL(r[0] + host).openConnection();
                c.setConnectTimeout(6000);
                c.setReadTimeout(6000);
                if (r[1] != null) c.setRequestProperty("Accept", r[1]);
                if (c.getResponseCode() != 200) continue;
                JSONObject j = new JSONObject(readBody(c, 200));
                org.json.JSONArray ans = j.optJSONArray("Answer");
                if (ans == null) continue;
                for (int i = 0; i < ans.length(); i++) {
                    JSONObject a = ans.optJSONObject(i);
                    if (a != null && a.optInt("type") == 1) {
                        String ip = a.optString("data", "");
                        if (ip.matches("\\d{1,3}(\\.\\d{1,3}){3}")) return ip;
                    }
                }
            } catch (Exception ignored) {
            } finally {
                if (c != null) c.disconnect();
            }
        }
        return null;
    }

    /** নতুন IP দিয়ে রিকোয়েস্ট; ফোনের DNS ঠিক থাকলে (একই IP) কিছু করে না */
    private static HttpResult viaFreshDns(String method, String url, String contentType, byte[] body, int timeoutMs) {
        try {
            URL u = new URL(url);
            String host = u.getHost();
            String ip = preferDirect() ? directIp : freshDnsIp(host);
            if (ip == null && BASE_URL.contains(host)) ip = FALLBACK_IP;   // DoH ও না পেলে নতুন হোস্টিং এর IP
            if (ip == null || ip.isEmpty()) return null;
            if (!preferDirect()) {
                for (java.net.InetAddress ia : java.net.InetAddress.getAllByName(host)) {
                    if (ip.equals(ia.getHostAddress())) return null;   // ফোনও একই IP দেখছে → DNS সমস্যা না
                }
            }
            HttpResult r = directHttps(ip, host, method, u.getFile().isEmpty() ? "/" : u.getFile(), contentType, body, timeoutMs);
            if (r != null && r.code > 0 && r.code < 500) {
                directIp = ip;
                directUntil = System.currentTimeMillis() + 30 * 60 * 1000L;   // ৩০ মিনিট সরাসরি এই IP
            }
            return r;
        } catch (Exception e) {
            return null;
        }
    }

    /** IP তে সরাসরি HTTPS (SNI + সার্টিফিকেট আসল ডোমেইন দিয়ে) — ছোট JSON রিকোয়েস্টের জন্য */
    private static HttpResult directHttps(String ip, String host, String method, String path,
                                          String contentType, byte[] body, int timeoutMs) throws Exception {
        java.net.Socket plain = new java.net.Socket();
        plain.connect(new java.net.InetSocketAddress(ip, 443), timeoutMs);
        plain.setSoTimeout(timeoutMs);
        javax.net.ssl.SSLSocketFactory f = (javax.net.ssl.SSLSocketFactory) javax.net.ssl.SSLSocketFactory.getDefault();
        javax.net.ssl.SSLSocket ssl = (javax.net.ssl.SSLSocket) f.createSocket(plain, host, 443, true);
        try {
            if (Build.VERSION.SDK_INT >= 24) {
                javax.net.ssl.SSLParameters sp = ssl.getSSLParameters();
                sp.setServerNames(java.util.Collections.<javax.net.ssl.SNIServerName>singletonList(new javax.net.ssl.SNIHostName(host)));
                ssl.setSSLParameters(sp);
            }
            ssl.startHandshake();
            if (!javax.net.ssl.HttpsURLConnection.getDefaultHostnameVerifier().verify(host, ssl.getSession())) {
                throw new javax.net.ssl.SSLPeerUnverifiedException("Certificate does not match " + host);
            }
            StringBuilder req = new StringBuilder();
            req.append(method).append(' ').append(path).append(" HTTP/1.1\r\n");
            req.append("Host: ").append(host).append("\r\n");
            req.append("User-Agent: ").append(USER_AGENT).append("\r\n");
            req.append("Accept: application/json, */*\r\n");
            req.append("Accept-Encoding: identity\r\n");
            req.append("X-Requested-With: com.example.snzpay\r\n");
            req.append("Connection: close\r\n");
            if (body != null) {
                req.append("Content-Type: ").append(contentType).append("\r\n");
                req.append("Content-Length: ").append(body.length).append("\r\n");
            }
            req.append("\r\n");
            OutputStream os = ssl.getOutputStream();
            os.write(req.toString().getBytes(StandardCharsets.UTF_8));
            if (body != null) os.write(body);
            os.flush();

            InputStream in = new java.io.BufferedInputStream(ssl.getInputStream());
            String status = readLine(in);
            if (status == null || !status.startsWith("HTTP/")) return null;
            int code = Integer.parseInt(status.split(" ")[1].trim());
            boolean chunked = false;
            int length = -1;
            String line;
            while ((line = readLine(in)) != null && !line.isEmpty()) {
                String lower = line.toLowerCase(java.util.Locale.ROOT);
                if (lower.startsWith("transfer-encoding:") && lower.contains("chunked")) chunked = true;
                if (lower.startsWith("content-length:")) {
                    try { length = Integer.parseInt(line.substring(15).trim()); } catch (Exception ignored) {}
                }
            }
            java.io.ByteArrayOutputStream out = new java.io.ByteArrayOutputStream();
            if (chunked) {
                while (true) {
                    String sizeLine = readLine(in);
                    if (sizeLine == null) break;
                    int semi = sizeLine.indexOf(';');
                    int size = Integer.parseInt((semi >= 0 ? sizeLine.substring(0, semi) : sizeLine).trim(), 16);
                    if (size == 0) break;
                    byte[] buf = new byte[size];
                    int read = 0;
                    while (read < size) {
                        int n = in.read(buf, read, size - read);
                        if (n < 0) break;
                        read += n;
                    }
                    out.write(buf, 0, read);
                    readLine(in);   // chunk এর শেষের CRLF
                }
            } else {
                byte[] buf = new byte[8192];
                int n, total = 0;
                while ((length < 0 || total < length) && (n = in.read(buf)) > 0) {
                    out.write(buf, 0, n);
                    total += n;
                }
            }
            return new HttpResult(code, new String(out.toByteArray(), StandardCharsets.UTF_8));
        } finally {
            try { ssl.close(); } catch (Exception ignored) {}
        }
    }

    private static String readLine(InputStream in) throws java.io.IOException {
        java.io.ByteArrayOutputStream b = new java.io.ByteArrayOutputStream();
        int c;
        while ((c = in.read()) != -1) {
            if (c == '\n') break;
            if (c != '\r') b.write(c);
        }
        if (c == -1 && b.size() == 0) return null;
        return new String(b.toByteArray(), StandardCharsets.UTF_8);
    }

    /** ফোন ডোমেইনটাকে কোন IP তে পাঠাচ্ছে (হোস্টিং বদলের পর পুরনো IP তে গেলে 404 আসে) */
    public static String resolvedIps() {
        try {
            java.net.InetAddress[] all = java.net.InetAddress.getAllByName(new URL(BASE_URL).getHost());
            StringBuilder sb = new StringBuilder();
            for (java.net.InetAddress ia : all) {
                if (sb.length() > 0) sb.append(", ");
                sb.append(ia.getHostAddress());
            }
            return sb.toString();
        } catch (Exception e) {
            return "unknown";
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
    // =====================================================================
    // 🧱 হোস্টিং ফায়ারওয়াল (Imunify360 / ModSecurity / LiteSpeed …) সুরক্ষা
    // ফায়ারওয়াল আটকালে অ্যাপ কিছুক্ষণ চুপ থাকে (২ মিনিট → সর্বোচ্চ ৩০ মিনিট)।
    // বারবার চেষ্টা করলে ফায়ারওয়াল ফোনের IP আরও লম্বা সময় ব্লক করে — তাই।
    // =====================================================================
    public static class FirewallException extends java.io.IOException {
        public FirewallException(String m) { super(m); }
    }
    private static volatile long fwUntil = 0, fwBackoff = 0;
    private static volatile int postMode = 0;   // 0 = এনকোড, 1 = JSON, 2 = form, 3 = GET (যেটা ফায়ারওয়াল পার হয় সেটাই মনে রাখা)
    public static volatile String fwInfo = "";

    /** ব্লক পেজ দেখে কোন ফায়ারওয়াল সেটা বোঝা */
    public static String firewallName(String body) {
        String b = body == null ? "" : body.toLowerCase(java.util.Locale.ROOT);
        if (b.contains("imunify")) return "Imunify360";
        if (b.contains("mod_security") || b.contains("modsecurity") || b.contains("not acceptable")) return "ModSecurity";
        if (b.contains("bitninja")) return "BitNinja";
        if (b.contains("cloudflare")) return "Cloudflare";
        if (b.contains("wordfence")) return "Wordfence";
        if (b.contains("sucuri")) return "Sucuri";
        if (b.contains("captcha") || b.contains("challenge")) return "Bot-check (captcha)";
        if (b.contains("litespeed")) return "LiteSpeed";
        if (b.contains("too many")) return "Rate limit";
        return "Hosting firewall";
    }

    /** ব্লক পেজের প্রথম কয়েকটা শব্দ (HTML বাদ দিয়ে) */
    public static String bodySnippet(String body) {
        if (body == null) return "";
        String t = body.replaceAll("(?is)<(script|style)[^>]*>.*?</\\1>", " ").replaceAll("<[^>]+>", " ").replaceAll("\\s+", " ").trim();
        return t.length() > 140 ? t.substring(0, 140) + "…" : t;
    }

    public static boolean looksFirewall(HttpResult r) {
        return r != null && !isApiJson(r) && (r.code == 403 || r.code == 406 || r.code == 429 || r.code == 503 || r.code == 501 || r.code == 409);
    }

    /** ফায়ারওয়াল থেকে মুক্তি পেতে আর কত সেকেন্ড (0 = এখন ঠিক আছে) */
    public static long firewallWaitSec() {
        long w = fwUntil - System.currentTimeMillis();
        return w > 0 ? (w + 999) / 1000 : 0;
    }

    private static void noteFirewall(HttpResult r) {
        fwBackoff = fwBackoff == 0 ? 2 * 60 * 1000L : Math.min(fwBackoff * 2, 30 * 60 * 1000L);
        fwUntil = System.currentTimeMillis() + fwBackoff;
        fwInfo = firewallName(r.body) + " (HTTP " + r.code + ")";
    }

    public static HttpResult httpPostJson(String url, JSONObject body, int timeoutMs) throws Exception {
        return httpPostJson(url, body, timeoutMs, false);
    }

    /** @param force true = ব্যবহারকারী নিজে বাটন চাপলে (লগইন) — অপেক্ষা ছাড়াই চেষ্টা */
    public static HttpResult httpPostJson(String url, JSONObject body, int timeoutMs, boolean force) throws Exception {
        if (!force && System.currentTimeMillis() < fwUntil) {
            throw new FirewallException(fwInfo + " blocked the app — waiting " + firewallWaitSec() + "s before trying again");
        }
        HttpResult r = postInner(url, body, timeoutMs);
        if (isApiJson(r)) { fwBackoff = 0; fwUntil = 0; fwInfo = ""; }
        else if (looksFirewall(r)) noteFirewall(r);
        return r;
    }

    private static volatile boolean encUnsupported = false;   // পুরনো সার্ভার ফাইল হলে এনকোড বাদ

    private static HttpResult send(int mode, String url, JSONObject body, int t) throws Exception {
        switch (mode) {
            case 0: return rawPostEncoded(url, body, t);
            case 1: return rawPostJson(url, body, t);
            case 2: return rawPostForm(url, body, t);
            default: return rawGet(url + (url.contains("?") ? "&" : "?") + toQuery(body), t);
        }
    }

    /**
     * রিকোয়েস্ট পাঠানো — যে পদ্ধতি ফায়ারওয়াল পার হয় সেটা আগে, বাকিগুলো দরকার হলে।
     * 0 = এনকোড (সবচেয়ে নিরাপদ), 1 = JSON, 2 = form, 3 = GET
     */
    private static HttpResult postInner(String url, JSONObject body, int timeoutMs) throws Exception {
        byte[] jsonBytes = body.toString().getBytes(StandardCharsets.UTF_8);
        if (preferDirect()) {
            HttpResult d = viaFreshDns("POST", url, "application/json; charset=utf-8", jsonBytes, timeoutMs);
            if (isApiJson(d)) return d;
        }
        java.util.List<Integer> order = new java.util.ArrayList<>();
        order.add(postMode);
        for (int m = 0; m < 4; m++) if (m != postMode) order.add(m);
        if (encUnsupported) order.remove(Integer.valueOf(0));

        HttpResult first = null;
        Exception firstErr = null;
        boolean dnsTried = false;
        for (int mode : order) {
            HttpResult r;
            try {
                r = send(mode, url, body, timeoutMs);
            } catch (java.net.UnknownHostException | java.net.SocketTimeoutException e) {
                // নেট নেই / সার্ভার সাড়া দেয় না → পুরনো DNS হলে নতুন IP তে একবার
                HttpResult d = dnsTried ? null : viaFreshDns("POST", url, "application/json; charset=utf-8", jsonBytes, timeoutMs);
                if (isApiJson(d)) return d;
                throw e;
            } catch (Exception e) {
                if (firstErr == null) firstErr = e;
                continue;
            }
            if (isApiJson(r)) {
                // পুরনো সার্ভার ফাইল এনকোড বোঝে না → JSON দিয়ে আবার
                if (mode == 0 && !r.enc && r.code >= 400) { encUnsupported = true; if (first == null) first = r; continue; }
                if (r.code == 200) { postMode = mode; return r; }
                // আমাদের সার্ভারের "না" (কী ভুল ইত্যাদি) — তবে পুরনো হোস্টিং এর উত্তর কিনা একবার দেখা
                if (!dnsTried) {
                    dnsTried = true;
                    HttpResult d = viaFreshDns("POST", url, "application/json; charset=utf-8", jsonBytes, timeoutMs);
                    if (isApiJson(d)) return d;
                }
                postMode = mode;
                return r;
            }
            if (first == null || (first.code != 403 && r.code == 403)) first = r;
            if (!dnsTried) {
                dnsTried = true;
                HttpResult d = viaFreshDns("POST", url, "application/json; charset=utf-8", jsonBytes, timeoutMs);
                if (isApiJson(d)) return d;
            }
            if (r.code == 404) break;   // ভুল সার্ভার — অন্য পদ্ধতিতেও 404 আসবে
        }
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
        {
            try (OutputStream os = conn.getOutputStream()) {
                os.write(toQuery(body).getBytes(StandardCharsets.UTF_8));
            }
            int code = conn.getResponseCode();
            return new HttpResult(code, readBody(conn, code));
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
        {
            try (OutputStream os = conn.getOutputStream()) {
                os.write(body.toString().getBytes(StandardCharsets.UTF_8));
            }
            int code = conn.getResponseCode();
            return new HttpResult(code, readBody(conn, code));
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
