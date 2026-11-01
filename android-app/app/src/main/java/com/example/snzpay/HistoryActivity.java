package com.example.snzpay;

import android.app.Activity;
import android.content.BroadcastReceiver;
import android.content.Context;
import android.content.Intent;
import android.content.IntentFilter;
import android.graphics.Color;
import android.graphics.drawable.GradientDrawable;
import android.os.Build;
import android.os.Bundle;
import android.view.LayoutInflater;
import android.view.View;
import android.view.ViewGroup;
import android.widget.BaseAdapter;
import android.widget.ListView;
import android.widget.TextView;

import java.util.ArrayList;
import java.util.List;
import java.util.Locale;

/** মেনু (≡) → ALL HISTORY: ফোনে আসা সব পেমেন্ট SMS */
public class HistoryActivity extends Activity {

    private static final int F_ALL = 0, F_TODAY = 1, F_UPLOADED = 2, F_WAITING = 3;

    private int filter = F_ALL;
    private final List<DatabaseHelper.Tx> items = new ArrayList<>();
    private Adapter adapter;
    private TextView tvSummary, tvEmpty;
    private TextView[] tabs;
    private boolean receiverRegistered = false;

    private final BroadcastReceiver refreshReceiver = new BroadcastReceiver() {
        @Override
        public void onReceive(Context context, Intent intent) {
            load();
        }
    };

    @Override
    protected void onCreate(Bundle savedInstanceState) {
        super.onCreate(savedInstanceState);
        setContentView(R.layout.activity_history);

        View root = findViewById(R.id.history_root);
        if (root != null && Build.VERSION.SDK_INT >= 20) {
            final int side = SmsRow.dp(this, 20), top = SmsRow.dp(this, 18);
            root.setOnApplyWindowInsetsListener((v, insets) -> {
                v.setPadding(insets.getSystemWindowInsetLeft() + side, insets.getSystemWindowInsetTop() + top,
                        insets.getSystemWindowInsetRight() + side, insets.getSystemWindowInsetBottom());
                return insets;
            });
        }

        tvSummary = findViewById(R.id.tvHistorySummary);
        tvEmpty = findViewById(R.id.tvHistoryEmpty);
        findViewById(R.id.btnHistoryBack).setOnClickListener(v -> finish());

        tabs = new TextView[]{findViewById(R.id.tabAll), findViewById(R.id.tabToday),
                findViewById(R.id.tabUploaded), findViewById(R.id.tabWaiting)};
        for (int i = 0; i < tabs.length; i++) {
            final int f = i;
            tabs[i].setOnClickListener(v -> {
                filter = f;
                load();
            });
        }

        ListView list = findViewById(R.id.listHistory);
        adapter = new Adapter();
        list.setAdapter(adapter);
        load();
    }

    private void load() {
        DatabaseHelper db = DatabaseHelper.get(this);
        List<DatabaseHelper.Tx> data;
        switch (filter) {
            case F_TODAY:    data = db.getTransactions(true, null, 500); break;
            case F_UPLOADED: data = db.getTransactions(false, DatabaseHelper.ST_SYNCED, 500); break;
            case F_WAITING:  data = db.getTransactions(false, DatabaseHelper.ST_PENDING, 500); break;
            default:         data = db.getTransactions(false, null, 500);
        }
        items.clear();
        items.addAll(data);
        adapter.notifyDataSetChanged();

        double sum = 0;
        for (DatabaseHelper.Tx t : items) {
            if (!DatabaseHelper.ST_REJECTED.equals(t.status) && (t.method == null || !t.method.toUpperCase(Locale.ROOT).contains("BINANCE"))) {
                sum += t.amount;
            }
        }
        tvSummary.setText(items.size() + " transactions • Tk " + String.format(Locale.US, "%.2f", sum));
        tvEmpty.setVisibility(items.isEmpty() ? View.VISIBLE : View.GONE);

        for (int i = 0; i < tabs.length; i++) {
            boolean on = i == filter;
            GradientDrawable bg = new GradientDrawable();
            bg.setCornerRadius(SmsRow.dp(this, 10));
            bg.setColor(on ? Color.parseColor("#0284C7") : Color.TRANSPARENT);
            tabs[i].setBackground(bg);
            tabs[i].setTextColor(on ? Color.WHITE : Color.parseColor("#475569"));
        }
    }

    private class Adapter extends BaseAdapter {
        @Override public int getCount() { return items.size(); }
        @Override public Object getItem(int position) { return items.get(position); }
        @Override public long getItemId(int position) { return items.get(position).id; }

        @Override
        public View getView(int position, View convertView, ViewGroup parent) {
            View row = convertView != null ? convertView
                    : LayoutInflater.from(HistoryActivity.this).inflate(R.layout.item_sms, parent, false);
            SmsRow.bind(HistoryActivity.this, row, items.get(position), true);
            return row;
        }
    }

    @Override
    protected void onResume() {
        super.onResume();
        if (!receiverRegistered) {
            if (Build.VERSION.SDK_INT >= 33) {
                registerReceiver(refreshReceiver, new IntentFilter(AppConfig.ACTION_REFRESH), Context.RECEIVER_NOT_EXPORTED);
            } else {
                registerReceiver(refreshReceiver, new IntentFilter(AppConfig.ACTION_REFRESH));
            }
            receiverRegistered = true;
        }
        load();
    }

    @Override
    protected void onPause() {
        super.onPause();
        if (receiverRegistered) {
            try { unregisterReceiver(refreshReceiver); } catch (Exception ignored) {}
            receiverRegistered = false;
        }
    }
}
