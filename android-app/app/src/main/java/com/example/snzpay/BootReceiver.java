package com.example.snzpay;

import android.content.BroadcastReceiver;
import android.content.Context;
import android.content.Intent;

/**
 * ফোন চালু হলে বা অ্যাপ আপডেট হলে সার্ভিস আবার চালু করে,
 * আর ফোন বন্ধ থাকার সময় আসা SMS খুঁজে সার্ভারে পাঠায়।
 */
public class BootReceiver extends BroadcastReceiver {
    @Override
    public void onReceive(Context context, Intent intent) {
        String action = intent.getAction();
        if (!Intent.ACTION_BOOT_COMPLETED.equals(action)
                && !"android.intent.action.QUICKBOOT_POWERON".equals(action)
                && !"com.htc.intent.action.QUICKBOOT_POWERON".equals(action)
                && !Intent.ACTION_MY_PACKAGE_REPLACED.equals(action)) {
            return;
        }

        final Context app = context.getApplicationContext();
        if (!AppConfig.isLoggedIn(app)) return;

        SyncManager.ensureServiceRunning(app);
        SyncJobService.schedulePeriodic(app);
        SyncJobService.scheduleWhenOnline(app);

        final PendingResult pending = goAsync();
        new Thread(() -> {
            try {
                SyncManager.scanInbox(app);
                SyncManager.syncPending(app);
            } catch (Exception ignored) {
            } finally {
                pending.finish();
            }
        }).start();
    }
}
