package com.example.snzpay;

import android.app.Activity;
import android.graphics.Color;
import android.os.Bundle;
import android.view.View;
import android.view.ViewGroup;
import android.widget.BaseAdapter;
import android.widget.ListView;
import android.widget.TextView;

import java.util.ArrayList;
import java.util.List;

/** সব SMS এর তালিকা (ফোনে সেভ থাকা) — All / Uploaded / Waiting */
public class HistoryActivity extends Activity {

    private final List<Db.Row> rows = new ArrayList<>();
    private BaseAdapter adapter;
    private TextView tabAll, tabSent, tabWait, tvTotal, tvNone;

    @Override
    protected void onCreate(Bundle b) {
        super.onCreate(b);
        setContentView(R.layout.activity_history);
        Ui.fitSystemBars(findViewById(R.id.historyRoot));
        findViewById(R.id.btnBack).setOnClickListener(v -> finish());
        Ui.box(findViewById(R.id.tabs), Ui.INPUT, Ui.LINE, 14);
        tabAll = findViewById(R.id.tabAll);
        tabSent = findViewById(R.id.tabSent);
        tabWait = findViewById(R.id.tabWait);
        tvTotal = findViewById(R.id.tvTotal);
        tvNone = findViewById(R.id.tvNone);

        adapter = new BaseAdapter() {
            @Override public int getCount() { return rows.size(); }
            @Override public Object getItem(int i) { return rows.get(i); }
            @Override public long getItemId(int i) { return rows.get(i).id; }
            @Override public View getView(int i, View old, ViewGroup parent) {
                return MainActivity.smsRow(HistoryActivity.this, rows.get(i));
            }
        };
        ((ListView) findViewById(R.id.listAll)).setAdapter(adapter);

        tabAll.setOnClickListener(v -> load(-1));
        tabSent.setOnClickListener(v -> load(Db.SENT));
        tabWait.setOnClickListener(v -> load(Db.PENDING));
        load(-1);
    }

    private void load(int status) {
        rows.clear();
        rows.addAll(Db.get(this).history(status, 500));
        adapter.notifyDataSetChanged();
        tvNone.setVisibility(rows.isEmpty() ? View.VISIBLE : View.GONE);
        tvTotal.setText(rows.size() + " SMS");
        mark(tabAll, status == -1);
        mark(tabSent, status == Db.SENT);
        mark(tabWait, status == Db.PENDING);
    }

    private void mark(TextView t, boolean on) {
        if (on) {
            Ui.box(t, Ui.BRAND, null, 11);
            t.setTextColor(Color.parseColor(Ui.BG));
        } else {
            t.setBackground(null);
            t.setTextColor(Color.parseColor(Ui.MUTED));
        }
    }
}
