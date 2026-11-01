package com.example.snzpay;

import android.content.BroadcastReceiver;
import android.content.Context;
import android.content.Intent;
import android.provider.Telephony;
import android.telephony.SmsMessage;

import java.util.LinkedHashMap;
import java.util.Map;

/**
 * নতুন SMS এলে সাথে সাথে ধরে।
 * আগে লম্বা SMS (২-৩ ভাগে আসে) আলাদা আলাদা পড়া হত → TrxID/Amount মিস হত।
 * এখন সব ভাগ জোড়া লাগিয়ে পুরো SMS পড়া হয়।
 */
public class SmsReceiver extends BroadcastReceiver {

    @Override
    public void onReceive(Context context, Intent intent) {
        if (!Telephony.Sms.Intents.SMS_RECEIVED_ACTION.equals(intent.getAction())) return;

        SmsMessage[] parts;
        try {
            parts = Telephony.Sms.Intents.getMessagesFromIntent(intent);
        } catch (Exception e) {
            return;
        }
        if (parts == null || parts.length == 0) return;

        // একই প্রেরকের ভাগগুলো জোড়া লাগানো
        final Map<String, StringBuilder> bodies = new LinkedHashMap<>();
        long time = System.currentTimeMillis();
        for (SmsMessage part : parts) {
            if (part == null) continue;
            String from = part.getDisplayOriginatingAddress();
            if (from == null) from = "";
            StringBuilder sb = bodies.get(from);
            if (sb == null) {
                sb = new StringBuilder();
                bodies.put(from, sb);
            }
            if (part.getMessageBody() != null) sb.append(part.getMessageBody());
            if (part.getTimestampMillis() > 0) time = part.getTimestampMillis();
        }
        final long smsTime = time;

        final Context app = context.getApplicationContext();
        final PendingResult pending = goAsync();   // সিস্টেম যেন মাঝপথে প্রসেস বন্ধ না করে
        new Thread(() -> {
            try {
                boolean anyPayment = false;
                for (Map.Entry<String, StringBuilder> e : bodies.entrySet()) {
                    if (SyncManager.handleIncomingSms(app, e.getKey(), e.getValue().toString(), smsTime)) {
                        anyPayment = true;
                    }
                }
                if (anyPayment) {
                    // ব্যাকআপ: এখনই না গেলে নেট আসলে অবশ্যই যাবে
                    SyncJobService.scheduleWhenOnline(app);
                    SyncManager.syncPending(app);
                }
            } catch (Exception ignored) {
            } finally {
                pending.finish();
            }
        }).start();
    }
}
