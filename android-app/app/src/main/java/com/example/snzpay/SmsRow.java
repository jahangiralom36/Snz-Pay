package com.example.snzpay;

import android.app.Activity;
import android.content.ClipData;
import android.content.ClipboardManager;
import android.content.Context;
import android.graphics.Color;
import android.graphics.drawable.GradientDrawable;
import android.view.View;
import android.widget.TextView;
import android.widget.Toast;

import java.text.SimpleDateFormat;
import java.util.Date;
import java.util.Locale;

/** Today's SMS বক্স আর All History — দুই জায়গায় একই রকম লাইন দেখানোর জন্য */
public final class SmsRow {

    private SmsRow() {}

    public static void bind(final Activity a, View row, final DatabaseHelper.Tx t, boolean showDate) {
        TextView method = row.findViewById(R.id.tvRowMethod);
        TextView trx = row.findViewById(R.id.tvRowTrx);
        TextView meta = row.findViewById(R.id.tvRowMeta);
        TextView amount = row.findViewById(R.id.tvRowAmount);
        TextView status = row.findViewById(R.id.tvRowStatus);

        String m = t.method == null ? "" : t.method.toUpperCase(Locale.ROOT);
        method.setText(shortName(m));
        GradientDrawable badge = new GradientDrawable();
        badge.setCornerRadius(dp(a, 10));
        badge.setColor(methodColor(m));
        method.setBackground(badge);

        trx.setText(t.trxId == null || t.trxId.isEmpty() ? "—" : t.trxId);

        String when = new SimpleDateFormat(showDate ? "dd MMM, hh:mm a" : "hh:mm a", Locale.US)
                .format(new Date(t.smsTime > 0 ? t.smsTime : System.currentTimeMillis()));
        String from = t.customer == null || t.customer.isEmpty() ? "" : "From " + t.customer + " • ";
        meta.setText(from + when);

        boolean usdt = m.contains("BINANCE");
        amount.setText(usdt ? String.format(Locale.US, "$ %.2f", t.amount) : String.format(Locale.US, "Tk %.2f", t.amount));

        if (DatabaseHelper.ST_SYNCED.equals(t.status)) {
            status.setText("✓ Uploaded");
            status.setTextColor(Color.parseColor("#16A34A"));
        } else if (DatabaseHelper.ST_REJECTED.equals(t.status)) {
            status.setText("✕ Rejected");
            status.setTextColor(Color.parseColor("#DC2626"));
        } else {
            status.setText("⏳ Waiting");
            status.setTextColor(Color.parseColor("#D97706"));
        }

        View card = row.findViewById(R.id.rowCard);
        if (card != null) card.setBackground(Styles.smsRow(a));
        (card != null ? card : row).setOnClickListener(v -> showDetails(a, t));
    }

    private static void showDetails(final Activity a, final DatabaseHelper.Tx t) {
        String m = t.method == null ? "" : t.method.toUpperCase(Locale.ROOT);
        boolean usdt = m.contains("BINANCE");
        String amountText = usdt ? String.format(Locale.US, "$ %.2f USDT", t.amount) : String.format(Locale.US, "৳ %.2f", t.amount);

        StringBuilder info = new StringBuilder();
        info.append("TrxID   : ").append(t.trxId).append('\n');
        if (t.customer != null && !t.customer.isEmpty()) info.append("Sender  : ").append(t.customer).append('\n');
        info.append("Time    : ").append(new SimpleDateFormat("dd MMM yyyy, hh:mm a", Locale.US).format(new Date(t.smsTime))).append('\n');
        if (!DatabaseHelper.ST_SYNCED.equals(t.status) && t.lastError != null && !t.lastError.isEmpty()) {
            info.append("Error   : ").append(t.lastError).append('\n');
        }
        if (t.rawSms != null && !t.rawSms.isEmpty()) info.append("\n").append(t.rawSms.trim());

        String icon; int color; String badge;
        if (DatabaseHelper.ST_SYNCED.equals(t.status)) { icon = "✅"; color = PremiumDialog.GREEN; badge = "UPLOADED TO SERVER"; }
        else if (DatabaseHelper.ST_REJECTED.equals(t.status)) { icon = "⛔"; color = PremiumDialog.RED; badge = "REJECTED"; }
        else { icon = "⏳"; color = PremiumDialog.AMBER; badge = "WAITING TO UPLOAD"; }

        new PremiumDialog(a)
                .icon(icon, color)
                .badge(badge)
                .title(amountText)
                .message(shortName(m) + " payment")
                .info(info.toString())
                .primary("COPY TRXID", d -> {
                    ClipboardManager cm = (ClipboardManager) a.getSystemService(Context.CLIPBOARD_SERVICE);
                    if (cm != null) cm.setPrimaryClip(ClipData.newPlainText("TrxID", t.trxId));
                    Toast.makeText(a, "Copied: " + t.trxId, Toast.LENGTH_SHORT).show();
                })
                .secondary("CLOSE", null)
                .show();
    }

    static String shortName(String m) {
        if (m.contains("BKASH")) return "bKash";
        if (m.contains("NAGAD")) return "Nagad";
        if (m.contains("ROCKET")) return "Rocket";
        if (m.contains("BINANCE")) return "Binance";
        if (m.contains("TALLY")) return "Tally QR";
        if (m.contains("QR")) return "QR";
        if (m.contains("UPAY")) return "Upay";
        return m.length() > 8 ? m.substring(0, 8) : m;
    }

    static int methodColor(String m) {
        if (m.contains("BKASH")) return Color.parseColor("#E2136E");
        if (m.contains("NAGAD")) return Color.parseColor("#EA580C");
        if (m.contains("ROCKET")) return Color.parseColor("#7C3AED");
        if (m.contains("BINANCE")) return Color.parseColor("#F59E0B");
        if (m.contains("QR") || m.contains("TALLY")) return Color.parseColor("#0284C7");
        if (m.contains("UPAY")) return Color.parseColor("#0EA5E9");
        return Color.parseColor("#475569");
    }

    static int dp(Context c, int v) {
        return (int) (v * c.getResources().getDisplayMetrics().density);
    }
}
