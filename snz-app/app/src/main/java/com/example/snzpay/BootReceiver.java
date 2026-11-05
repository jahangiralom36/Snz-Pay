package com.example.snzpay;

import android.content.BroadcastReceiver;
import android.content.Context;
import android.content.Intent;

/** ফোন চালু / অ্যাপ আপডেট হলে নিয়মিত কাজ আবার সেট করা */
public class BootReceiver extends BroadcastReceiver {
    @Override
    public void onReceive(Context context, Intent intent) {
        Context app = context.getApplicationContext();
        if (!Session.loggedIn(app)) return;
        SyncJob.schedulePeriodic(app);
        SyncJob.scheduleOnline(app);
    }
}
