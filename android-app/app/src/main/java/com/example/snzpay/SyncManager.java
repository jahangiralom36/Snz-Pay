package com.example.snzpay;

import android.Manifest;
import android.content.Context;
import android.content.Intent;
import android.content.SharedPreferences;
import android.content.pm.PackageManager;
import android.database.Cursor;
import android.net.Uri;
import android.os.Build;
import android.util.Log;

import org.json.JSONObject;

import java.util.List;

/**
 * SMS → ফোনের ডাটাবেজ → সার্ভার।
 * নেট না থাকলে SMS ফোনে জমা থাকে; নেট আসলেই (বা অ্যাপ খুললে / প্রতি ১৫ মিনিটে) পাঠানো হয়।
 * সার্ভারে আগে থেকেই থাকলে ("already_exists") সেটাও সফল ধরা হয়।
 */
public final class SyncManager {

    private static final String TAG = "SNZ_PAY";
    private static final Object LOCK = new Object();
    private static final String SYNC_PREFS = "SNZ_SYNC";

    private SyncManager() {}

    /** নতুন SMS এলে: পড়া + ফোনে সেভ। পেমেন্ট SMS হলে true */
    public static boolean handleIncomingSms(Context ctx, String origin, String body, long smsTime) {
        SmsParser.Result r = SmsParser.parse(ctx, origin, body);
        if (r == null) return false;
        long id = DatabaseHelper.get(ctx).insertIfNew(r.method, r.trxId, r.amount, r.customer, body, origin, smsTime);
        if (id > 0) notifyRefresh(ctx);
        return true;
    }

    public static void notifyRefresh(Context ctx) {
        Intent i = new Intent(AppConfig.ACTION_REFRESH);
        i.setPackage(ctx.getPackageName());
        ctx.sendBroadcast(i);
    }

    public static void syncAsync(Context ctx) {
        final Context app = ctx.getApplicationContext();
        new Thread(() -> syncPending(app)).start();
    }

    /**
     * জমে থাকা সব SMS সার্ভারে পাঠায় (ব্যাকগ্রাউন্ড থ্রেড থেকে কল করতে হবে)।
     * @return এখনো কয়টা বাকি আছে
     */
    public static int syncPending(Context ctx) {
        synchronized (LOCK) {
            DatabaseHelper db = DatabaseHelper.get(ctx);
            String key = AppConfig.getLicenseKey(ctx);
            if (key.isEmpty()) {
                setLastError(ctx, "Not logged in");
                return db.getUnsyncedCount();
            }

            // অ্যাডমিন অ্যাপ OFF রাখলে SMS ফোনেই জমা থাকবে, ON করলে যাবে
            if (!AppConfig.isAppEnabled(ctx)) return db.getUnsyncedCount();

            if (!AppConfig.isOnline(ctx)) {
                setLastError(ctx, "No internet connection");
                SyncJobService.scheduleWhenOnline(ctx);
                return db.getUnsyncedCount();
            }

            boolean changed = false;
            for (int round = 0; round < 10; round++) {
                List<DatabaseHelper.Tx> batch = db.getUnsynced(25);
                if (batch.isEmpty()) break;

                boolean stop = false;
                for (DatabaseHelper.Tx t : batch) {
                    int result = upload(ctx, key, t);
                    if (result == UPLOAD_OK) {
                        db.setStatus(t.id, DatabaseHelper.ST_SYNCED, null);
                        changed = true;
                    } else if (result == UPLOAD_REJECTED) {
                        db.setStatus(t.id, DatabaseHelper.ST_REJECTED, "rejected by server");
                        changed = true;
                    } else {
                        // নেট/সার্ভার সমস্যা বা লাইসেন্স সমস্যা → পরে আবার চেষ্টা
                        stop = true;
                        break;
                    }
                }
                if (stop) {
                    SyncJobService.scheduleWhenOnline(ctx);
                    break;
                }
            }
            if (changed) notifyRefresh(ctx);
            return db.getUnsyncedCount();
        }
    }

    private static final int UPLOAD_OK = 0;
    private static final int UPLOAD_RETRY = 1;
    private static final int UPLOAD_REJECTED = 2;

    private static int upload(Context ctx, String key, DatabaseHelper.Tx t) {
        try {
            JSONObject payload = new JSONObject();
            payload.put("secret_key", AppConfig.SECRET_TOKEN);
            payload.put("license_key", key);
            payload.put("method", t.method);
            payload.put("trx_id", t.trxId);
            payload.put("amount", String.valueOf(t.amount));
            payload.put("sender_number", t.customer == null ? "" : t.customer);
            payload.put("origin_address", t.origin == null ? "" : t.origin);
            payload.put("raw_message", t.rawSms == null ? "" : t.rawSms);
            payload.put("sms_time", t.smsTime);   // দেরিতে পাঠালেও আসল সময় সার্ভারে যাবে

            AppConfig.HttpResult res = AppConfig.httpPostJson(AppConfig.GATEWAY_URL, payload, 10000);
            String serverMsg = "";
            try { serverMsg = new JSONObject(res.body).optString("message", ""); } catch (Exception ignored) {}

            if (res.code == 200) {
                String st = new JSONObject(res.body).optString("status", "");
                if ("success".equals(st) || "already_exists".equals(st)) {
                    setLastError(ctx, null);
                    return UPLOAD_OK;
                }
                fail(ctx, t, "Server: " + (serverMsg.isEmpty() ? st : serverMsg));
                return UPLOAD_RETRY;
            }
            if (res.code == 422) return UPLOAD_REJECTED;     // TrxID/Amount ভুল — বারবার পাঠিয়ে লাভ নেই

            String reason;
            if (res.code == 403 && serverMsg.toLowerCase().contains("unauthorized")) {
                reason = "Secret token does not match the server (APP_SECRET_TOKEN in config.php)";
            } else if (res.code == 403) {
                reason = "License key invalid or expired — login again with an active key";
            } else if (res.code == 404) {
                reason = "api/app_gateway.php not found on the server";
            } else if (res.code >= 500) {
                reason = "Server error (HTTP " + res.code + ")" + (serverMsg.isEmpty() ? "" : ": " + serverMsg);
            } else {
                reason = "HTTP " + res.code + (serverMsg.isEmpty() ? "" : ": " + serverMsg);
            }
            fail(ctx, t, reason);
            return UPLOAD_RETRY;
        } catch (java.net.UnknownHostException e) {
            fail(ctx, t, "Cannot reach " + AppConfig.BASE_URL + " (no internet / DNS)");
            return UPLOAD_RETRY;
        } catch (java.net.SocketTimeoutException e) {
            fail(ctx, t, "Server did not respond (timeout)");
            return UPLOAD_RETRY;
        } catch (javax.net.ssl.SSLException e) {
            fail(ctx, t, "SSL/HTTPS problem: " + e.getMessage());
            return UPLOAD_RETRY;
        } catch (Exception e) {
            Log.w(TAG, "upload failed: " + e.getMessage());
            fail(ctx, t, e.getClass().getSimpleName() + (e.getMessage() == null ? "" : ": " + e.getMessage()));
            return UPLOAD_RETRY;
        }
    }

    private static void fail(Context ctx, DatabaseHelper.Tx t, String reason) {
        DatabaseHelper.get(ctx).markAttempt(t.id, reason);
        setLastError(ctx, reason);
    }

    /** শেষবার আপলোড কেন ব্যর্থ হয়েছিল (সফল হলে null) — অ্যাপের স্ক্রিনে দেখানো হয় */
    public static String getLastError(Context ctx) {
        return ctx.getSharedPreferences(SYNC_PREFS, Context.MODE_PRIVATE).getString("last_error", null);
    }

    private static void setLastError(Context ctx, String reason) {
        SharedPreferences.Editor e = ctx.getSharedPreferences(SYNC_PREFS, Context.MODE_PRIVATE).edit();
        if (reason == null) e.remove("last_error"); else e.putString("last_error", reason);
        e.apply();
    }

    /**
     * ফোন বন্ধ / অ্যাপ বন্ধ থাকায় যেসব SMS মিস হয়েছে, Inbox থেকে খুঁজে বের করে।
     * ডুপ্লিকেট হবে না — একই TrxID দুইবার সেভ হয় না।
     */
    public static void scanInbox(Context ctx) {
        if (Build.VERSION.SDK_INT >= 23
                && ctx.checkSelfPermission(Manifest.permission.READ_SMS) != PackageManager.PERMISSION_GRANTED) {
            return;
        }
        if (!AppConfig.isLoggedIn(ctx)) return;

        SharedPreferences prefs = ctx.getSharedPreferences(SYNC_PREFS, Context.MODE_PRIVATE);
        long now = System.currentTimeMillis();
        // প্রথমবার: শেষ ২ ঘণ্টা; সর্বোচ্চ ৩ দিন পিছনে যাবে
        long last = prefs.getLong("last_inbox_scan", now - 2L * 3600 * 1000);
        // নতুন ভার্সনে একবার: শেষ ৩ দিনের SMS নতুন নিয়মে আবার পড়া (পুরনো ভুলভাবে সেভ হওয়াগুলো ঠিকভাবে ফিরে আসবে)
        if (!prefs.getBoolean("rescan_v3_done", false)) {
            last = now - 3L * 24 * 3600 * 1000;
            prefs.edit().putBoolean("rescan_v3_done", true).apply();
        }
        last = Math.max(last, now - 3L * 24 * 3600 * 1000);

        long maxSeen = last;
        try (Cursor c = ctx.getContentResolver().query(
                Uri.parse("content://sms/inbox"),
                new String[]{"address", "body", "date"},
                "date > ?", new String[]{String.valueOf(last)},
                "date ASC")) {
            if (c != null) {
                int n = 0;
                while (c.moveToNext() && n < 300) {
                    String address = c.getString(0);
                    String body = c.getString(1);
                    long date = c.getLong(2);
                    handleIncomingSms(ctx, address, body, date);
                    if (date > maxSeen) maxSeen = date;
                    n++;
                }
            }
        } catch (Exception e) {
            Log.w(TAG, "inbox scan failed: " + e.getMessage());
            return;
        }
        prefs.edit().putLong("last_inbox_scan", maxSeen).apply();
    }

    /** লগইনের সময় — এর আগের পুরনো SMS নতুন করে তোলার দরকার নেই */
    public static void resetInboxScan(Context ctx) {
        ctx.getSharedPreferences(SYNC_PREFS, Context.MODE_PRIVATE).edit()
                .putLong("last_inbox_scan", System.currentTimeMillis() - 2L * 3600 * 1000)
                .apply();
    }

    /** সার্ভিস চালু রাখা (যেখান থেকেই ডাকা হোক, ক্র্যাশ করবে না) */
    public static void ensureServiceRunning(Context ctx) {
        if (!AppConfig.isLoggedIn(ctx)) return;
        try {
            Intent intent = new Intent(ctx, PaymentService.class);
            if (Build.VERSION.SDK_INT >= 26) ctx.startForegroundService(intent);
            else ctx.startService(intent);
        } catch (Exception ignored) {
            // Android 12+ এ ব্যাকগ্রাউন্ড থেকে সবসময় চালু করা যায় না — তখন JobScheduler কাজ চালিয়ে যায়
        }
    }
}
