package com.example.snzpay;

import android.content.BroadcastReceiver;
import android.content.Context;
import android.content.Intent;
import android.provider.Telephony;
import android.telephony.SmsMessage;

import java.util.LinkedHashMap;
import java.util.Map;

/** নতুন SMS এলে সাথে সাথে পড়ে পাঠায় (লম্বা SMS এর ভাগগুলো জোড়া লাগিয়ে) */
public class SmsReceiver extends BroadcastReceiver {
    @Override
    public void onReceive(Context context, Intent intent) {
        if (!Telephony.Sms.Intents.SMS_RECEIVED_ACTION.equals(intent.getAction())) return;
        final Context app = context.getApplicationContext();
        if (!Session.loggedIn(app)) return;

        SmsMessage[] parts;
        try {
            parts = Telephony.Sms.Intents.getMessagesFromIntent(intent);
        } catch (Exception e) {
            return;
        }
        if (parts == null || parts.length == 0) return;

        final Map<String, StringBuilder> bodies = new LinkedHashMap<>();
        long t = System.currentTimeMillis();
        for (SmsMessage m : parts) {
            if (m == null) continue;
            String from = m.getDisplayOriginatingAddress() == null ? "" : m.getDisplayOriginatingAddress();
            StringBuilder sb = bodies.get(from);
            if (sb == null) { sb = new StringBuilder(); bodies.put(from, sb); }
            if (m.getMessageBody() != null) sb.append(m.getMessageBody());
            if (m.getTimestampMillis() > 0) t = m.getTimestampMillis();
        }
        final long time = t;
        final PendingResult pending = goAsync();
        new Thread(() -> {
            try {
                boolean any = false;
                for (Map.Entry<String, StringBuilder> e : bodies.entrySet()) {
                    if (Sync.handle(app, e.getKey(), e.getValue().toString(), time)) any = true;
                }
                if (any && Sync.upload(app) > 0) SyncJob.scheduleOnline(app);
            } catch (Exception ignored) {
            } finally {
                pending.finish();
            }
        }).start();
    }
}
