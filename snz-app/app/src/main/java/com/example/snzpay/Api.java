package com.example.snzpay;

import android.os.Build;
import android.util.Base64;

import org.json.JSONObject;

import java.io.ByteArrayOutputStream;
import java.io.IOException;
import java.io.InputStream;
import java.io.OutputStream;
import java.net.HttpURLConnection;
import java.net.URL;
import java.net.URLEncoder;
import java.nio.charset.StandardCharsets;
import java.util.Iterator;

/**
 * সার্ভারে রিকোয়েস্ট পাঠানো।
 * হোস্টিং ফায়ারওয়াল যেন ভুল করে না আটকায়: আগে পুরো ডাটা এনকোড করে (p=...) পাঠায়,
 * না হলে JSON, তারপর form — যেটা কাজ করে সেটা মনে রাখে।
 */
public final class Api {
    private Api() {}

    public static final class Res {
        public final int code;
        public final JSONObject json;   // আমাদের সার্ভারের JSON না হলে null
        public final String raw;
        boolean enc;
        Res(int code, String raw) {
            this.code = code;
            this.raw = raw == null ? "" : raw;
            this.json = parse(this.raw);
        }
    }

    private static final String UA = "Mozilla/5.0 (Linux; Android " + Build.VERSION.RELEASE
            + ") AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120 Mobile Safari/537.36 SNZApp/3";
    private static volatile int mode = 0;            // 0 = এনকোড, 1 = JSON, 2 = form
    private static volatile boolean encBad = false;  // পুরনো সার্ভার ফাইল এনকোড বোঝে না

    public static Res post(String url, JSONObject body) throws IOException {
        Res first = null;
        IOException last = null;
        for (int i = 0; i < 3; i++) {
            int m = (mode + i) % 3;
            if (m == 0 && encBad) continue;
            Res r;
            try {
                r = send(m, url, body);
            } catch (java.net.UnknownHostException | java.net.SocketTimeoutException e) {
                throw e;   // নেট নেই / সার্ভার সাড়া দেয় না — আবার চেষ্টা করে লাভ নেই
            } catch (IOException e) {
                last = e;
                continue;
            }
            if (r.json != null) {
                if (m == 0 && !r.enc && r.code >= 400) { encBad = true; if (first == null) first = r; continue; }
                mode = m;
                return r;
            }
            if (first == null) first = r;
            if (r.code == 404) break;
        }
        if (first != null) return first;
        throw last != null ? last : new IOException("No response");
    }

    private static Res send(int m, String url, JSONObject body) throws IOException {
        byte[] data;
        String type;
        if (m == 0) {
            String p = Base64.encodeToString(body.toString().getBytes(StandardCharsets.UTF_8),
                    Base64.URL_SAFE | Base64.NO_WRAP | Base64.NO_PADDING);
            data = ("p=" + p).getBytes(StandardCharsets.UTF_8);
            type = "application/x-www-form-urlencoded; charset=utf-8";
        } else if (m == 1) {
            data = body.toString().getBytes(StandardCharsets.UTF_8);
            type = "application/json; charset=utf-8";
        } else {
            data = toForm(body).getBytes(StandardCharsets.UTF_8);
            type = "application/x-www-form-urlencoded; charset=utf-8";
        }
        HttpURLConnection c = (HttpURLConnection) new URL(url).openConnection();
        c.setRequestMethod("POST");
        c.setConnectTimeout(15000);
        c.setReadTimeout(15000);
        c.setUseCaches(false);
        c.setDoOutput(true);
        c.setRequestProperty("User-Agent", UA);
        c.setRequestProperty("Accept", "application/json, */*");
        c.setRequestProperty("Content-Type", type);
        try (OutputStream os = c.getOutputStream()) {
            os.write(data);
        }
        int code = c.getResponseCode();
        InputStream in = code >= 400 ? c.getErrorStream() : c.getInputStream();
        String text = read(in);
        Res r = new Res(code, text);
        r.enc = c.getHeaderField("X-SNZ-Enc") != null;
        return r;
    }

    private static String read(InputStream in) throws IOException {
        if (in == null) return "";
        try (InputStream is = in) {
            ByteArrayOutputStream out = new ByteArrayOutputStream();
            byte[] buf = new byte[4096];
            int n;
            while ((n = is.read(buf)) > 0 && out.size() < 512 * 1024) out.write(buf, 0, n);
            return new String(out.toByteArray(), StandardCharsets.UTF_8);
        }
    }

    private static String toForm(JSONObject b) throws IOException {
        StringBuilder sb = new StringBuilder();
        Iterator<String> it = b.keys();
        while (it.hasNext()) {
            String k = it.next();
            if (sb.length() > 0) sb.append('&');
            sb.append(URLEncoder.encode(k, "UTF-8")).append('=').append(URLEncoder.encode(String.valueOf(b.opt(k)), "UTF-8"));
        }
        return sb.toString();
    }

    /** উত্তর থেকে আমাদের JSON বের করা (আগে বাড়তি লেখা থাকলেও) */
    static JSONObject parse(String raw) {
        String t = raw.trim();
        int i = t.startsWith("{") ? 0 : t.indexOf("{\"status\"");
        int j = t.lastIndexOf('}');
        if (i < 0 || j <= i) return null;
        try {
            JSONObject o = new JSONObject(t.substring(i, j + 1));
            return o.has("status") ? o : null;
        } catch (Exception e) {
            return null;
        }
    }

    /** ব্লক পেজ থেকে সংক্ষিপ্ত লেখা */
    public static String snippet(String raw) {
        String t = raw == null ? "" : raw.replaceAll("(?is)<(script|style)[^>]*>.*?</\\1>", " ")
                .replaceAll("<[^>]+>", " ").replaceAll("\\s+", " ").trim();
        return t.length() > 120 ? t.substring(0, 120) + "…" : t;
    }
}
