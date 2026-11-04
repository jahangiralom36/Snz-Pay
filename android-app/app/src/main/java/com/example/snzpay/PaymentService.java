package com.example.snzpay;

import android.app.AlarmManager;
import android.app.Notification;
import android.app.NotificationChannel;
import android.app.NotificationManager;
import android.app.PendingIntent;
import android.app.Service;
import android.content.Context;
import android.content.Intent;
import android.content.pm.ServiceInfo;
import android.net.ConnectivityManager;
import android.net.Network;
import android.os.Build;
import android.os.Handler;
import android.os.IBinder;
import android.os.Looper;
import android.os.SystemClock;

/**
 * ২৪/৭ ব্যাকগ্রাউন্ড সার্ভিস।
 *  - নোটিফিকেশন কেটে দিলে কয়েক সেকেন্ড পর আবার চলে আসে
 *  - নেট চালু হলেই জমে থাকা SMS সার্ভারে পাঠায়
 *  - প্রতি মিনিটে বাকি SMS চেক, প্রতি ১০ মিনিটে মিস হওয়া SMS খোঁজে
 */
public class PaymentService extends Service {

    // নতুন চুপচাপ (Silent) চ্যানেল — আগের চ্যানেলগুলো মুছে ফেলা হয় যাতে দুটো নোটিফিকেশন না দেখায়
    private static final String CHANNEL_ID = "snz_gateway_silent";
    private static final String[] OLD_CHANNELS = {"snz_gateway_channel_live", "snz_gateway_service"};
    private static final int NOTIFICATION_ID = 1001;
    public static final String ACTION_REPOST = "com.example.snzpay.REPOST_NOTIFICATION";

    private final Handler handler = new Handler(Looper.getMainLooper());
    private ConnectivityManager.NetworkCallback networkCallback;
    private long lastInboxScan = 0;
    private long lastKeyCheck = 0;

    private final Runnable periodicTask = new Runnable() {
        @Override
        public void run() {
            final Context app = getApplicationContext();
            new Thread(() -> {
                try {
                    if (AppConfig.isStale(app, 30 * 60 * 1000L)) AppConfig.fetch(app);
                    if (SystemClock.elapsedRealtime() - lastInboxScan > 10 * 60 * 1000L) {
                        lastInboxScan = SystemClock.elapsedRealtime();
                        SyncManager.scanInbox(app);
                    }
                    SyncManager.syncPending(app);
                    // মেয়াদ ফোনের ঘড়িতেই শেষ → নেট না থাকলেও লগআউট
                    android.content.SharedPreferences ap = app.getSharedPreferences(AppConfig.AUTH_PREFS, MODE_PRIVATE);
                    long exp = ap.getLong("EXPIRE_TIME", 0);
                    if (!AppConfig.MASTER_KEY.equalsIgnoreCase(ap.getString("SAVED_KEY", "")) && exp > 0 && System.currentTimeMillis() > exp) {
                        ap.edit().putString("LOGOUT_REASON", "Your license key has expired. Please renew your plan.").apply();
                        AppConfig.forceLogout(app);
                        handler.post(() -> stopSelf());
                        return;
                    }
                    // প্রতি ২ মিনিটে কী সার্ভারে আছে কিনা (Ban / Delete / মেয়াদ শেষ → লগআউট)
                    if (SystemClock.elapsedRealtime() - lastKeyCheck > 2 * 60 * 1000L && AppConfig.firewallWaitSec() == 0) {
                        lastKeyCheck = SystemClock.elapsedRealtime();
                        if (AppConfig.verifyKeyNow(app) == AppConfig.KEY_INVALID) {
                            AppConfig.forceLogout(app);
                            handler.post(() -> stopSelf());
                            return;
                        }
                    }
                    handler.post(() -> showNotification());
                } catch (Exception ignored) {}
            }).start();
            // প্রতি ~৩০ সেকেন্ডে বাকি SMS অটো পাঠানো (সব ফোন একই সেকেন্ডে না যায় তাই একটু এলোমেলো)
            handler.postDelayed(this, 30 * 1000L + (long) (Math.random() * 8000));
        }
    };


    @Override
    public void onCreate() {
        super.onCreate();
        ErrorReporter.installCrashHandler(this);
        createNotificationChannel();
        showNotification(true);
        registerNetworkWatcher();
        SyncJobService.schedulePeriodic(this);
        handler.postDelayed(periodicTask, 5000);
    }

    @Override
    public int onStartCommand(Intent intent, int flags, int startId) {
        if (!AppConfig.isLoggedIn(this)) {
            stopSelf();
            return START_NOT_STICKY;
        }
        showNotification(true);   // startForegroundService এর নিয়ম — একবার বসানো বাধ্যতামূলক
        return START_STICKY;
    }

    // ---------------------------------------------------------------------
    // Notification
    // ---------------------------------------------------------------------
    private Notification buildNotification() {
        Intent open = new Intent(this, MainActivity.class);
        open.setFlags(Intent.FLAG_ACTIVITY_NEW_TASK | Intent.FLAG_ACTIVITY_CLEAR_TOP);
        PendingIntent contentPi = PendingIntent.getActivity(this, 0, open,
                PendingIntent.FLAG_IMMUTABLE | PendingIntent.FLAG_UPDATE_CURRENT);


        Notification.Builder builder = Build.VERSION.SDK_INT >= 26
                ? new Notification.Builder(this, CHANNEL_ID)
                : new Notification.Builder(this);

        String appName = AppConfig.get(this).optString("app_name", "SNZ Pay");
        String text;
        if (!AppConfig.isAppEnabled(this)) {
            text = appName + " সাময়িক বন্ধ আছে • SMS ফোনে জমা হচ্ছে";
        } else {
            text = appName + " অটো পেমেন্ট সক্রিয় আছে";
        }

        // সাদামাটা ছোট নোটিফিকেশন: শুধু নাম + এক লাইন (বড় ছবি নেই, শব্দ নেই)
        builder.setContentTitle(appName)
                .setContentText(text)
                .setSmallIcon(android.R.drawable.stat_notify_sync)
                .setContentIntent(contentPi)
                .setOngoing(true)
                .setAutoCancel(false)
                .setOnlyAlertOnce(true)
                .setShowWhen(false);
        if (Build.VERSION.SDK_INT < 26) builder.setPriority(Notification.PRIORITY_LOW);
        if (Build.VERSION.SDK_INT >= 31) builder.setForegroundServiceBehavior(Notification.FOREGROUND_SERVICE_IMMEDIATE);

        Notification n = builder.build();
        n.flags |= Notification.FLAG_ONGOING_EVENT | Notification.FLAG_NO_CLEAR;
        return n;
    }

    private String lastNoteText = null;

    private String noteText() {
        String appName = AppConfig.get(this).optString("app_name", "SNZ Pay");
        return AppConfig.isAppEnabled(this) ? appName + " অটো পেমেন্ট সক্রিয় আছে" : appName + " সাময়িক বন্ধ আছে • SMS ফোনে জমা হচ্ছে";
    }

    /** লেখা না বদলালে আবার বসানো হয় না — বারবার বসালে ফোনের System UI আটকে যায় */
    private void showNotification() { showNotification(false); }

    private void showNotification(boolean force) {
        String t = noteText();
        if (!force && t.equals(lastNoteText)) return;
        lastNoteText = t;
        Notification n = buildNotification();
        try {
            if (Build.VERSION.SDK_INT >= 29) {
                startForeground(NOTIFICATION_ID, n, ServiceInfo.FOREGROUND_SERVICE_TYPE_DATA_SYNC);
            } else {
                startForeground(NOTIFICATION_ID, n);
            }
        } catch (Exception e) {
            try {
                startForeground(NOTIFICATION_ID, n);
            } catch (Exception ignored) {
                // foreground চালু করা না গেলে অন্তত সাধারণ নোটিফিকেশন
                NotificationManager nm = getSystemService(NotificationManager.class);
                if (nm != null) nm.notify(NOTIFICATION_ID, n);
            }
        }
    }

    private void createNotificationChannel() {
        if (Build.VERSION.SDK_INT >= 26) {
            NotificationManager nm = getSystemService(NotificationManager.class);
            if (nm != null) {
                for (String old : OLD_CHANNELS) {
                    try { nm.deleteNotificationChannel(old); } catch (Exception ignored) {}
                }
            }
            // LOW importance → "Silent" অংশে থাকে, কোনো শব্দ/ভাইব্রেশন/পপআপ নেই
            NotificationChannel channel = new NotificationChannel(CHANNEL_ID, "SNZ Pay Auto Payment",
                    NotificationManager.IMPORTANCE_LOW);
            channel.setDescription("Keeps SNZ Pay listening for payment SMS 24/7");
            channel.setLockscreenVisibility(Notification.VISIBILITY_PUBLIC);
            channel.setSound(null, null);
            channel.enableVibration(false);
            channel.setShowBadge(false);
            NotificationManager manager = getSystemService(NotificationManager.class);
            if (manager != null) manager.createNotificationChannel(channel);
        }
    }

    // ---------------------------------------------------------------------
    // নেট চালু হলেই সিঙ্ক
    // ---------------------------------------------------------------------
    private void registerNetworkWatcher() {
        if (Build.VERSION.SDK_INT < 24) return;
        try {
            ConnectivityManager cm = (ConnectivityManager) getSystemService(Context.CONNECTIVITY_SERVICE);
            if (cm == null) return;
            networkCallback = new ConnectivityManager.NetworkCallback() {
                @Override
                public void onAvailable(Network network) {
                    // নেট আসার ২ সেকেন্ড পর (কানেকশন স্থির হতে দেওয়া)
                    handler.postDelayed(() -> {
                        SyncManager.syncAsync(getApplicationContext());
                    }, 2000);
                }
            };
            cm.registerDefaultNetworkCallback(networkCallback);
        } catch (Exception ignored) {}
    }

    // ---------------------------------------------------------------------
    // Android 15: dataSync সার্ভিস দিনে ৬ ঘণ্টার বেশি চলতে দেয় না → সিস্টেম এটা কল করে।
    // তখন সার্ভিস বন্ধ হয়, কিন্তু SMS পড়া আর পাঠানো (SmsReceiver + SyncJobService) চলতেই থাকে।
    // (@Override নেই যাতে পুরনো compileSdk তেও build হয়)
    // ---------------------------------------------------------------------
    public void onTimeout(int startId, int fgsType) {
        SyncJobService.scheduleWhenOnline(this);
        stopSelf();
    }

    @Override
    public void onTaskRemoved(Intent rootIntent) {
        scheduleRestart(1000);
        super.onTaskRemoved(rootIntent);
    }

    @Override
    public void onDestroy() {
        handler.removeCallbacksAndMessages(null);
        if (networkCallback != null && Build.VERSION.SDK_INT >= 24) {
            try {
                ConnectivityManager cm = (ConnectivityManager) getSystemService(Context.CONNECTIVITY_SERVICE);
                if (cm != null) cm.unregisterNetworkCallback(networkCallback);
            } catch (Exception ignored) {}
        }
        if (AppConfig.isLoggedIn(this)) {
            scheduleRestart(5000);
            SyncJobService.scheduleWhenOnline(this);
        }
        super.onDestroy();
    }

    private void scheduleRestart(long delayMs) {
        if (!AppConfig.isLoggedIn(this)) return;
        try {
            Intent restartIntent = new Intent(getApplicationContext(), PaymentService.class);
            PendingIntent restartPending = Build.VERSION.SDK_INT >= 26
                    ? PendingIntent.getForegroundService(getApplicationContext(), 1, restartIntent,
                        PendingIntent.FLAG_ONE_SHOT | PendingIntent.FLAG_IMMUTABLE)
                    : PendingIntent.getService(getApplicationContext(), 1, restartIntent,
                        PendingIntent.FLAG_ONE_SHOT | PendingIntent.FLAG_IMMUTABLE);
            AlarmManager am = (AlarmManager) getSystemService(Context.ALARM_SERVICE);
            if (am != null) am.set(AlarmManager.ELAPSED_REALTIME_WAKEUP, SystemClock.elapsedRealtime() + delayMs, restartPending);
        } catch (Exception ignored) {}
    }

    @Override
    public IBinder onBind(Intent intent) {
        return null;
    }
}
