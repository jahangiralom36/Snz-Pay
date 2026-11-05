package com.example.snzpay;

import android.Manifest;
import android.app.Activity;
import android.content.Intent;
import android.graphics.Color;
import android.net.Uri;
import android.os.Bundle;
import android.os.Handler;
import android.os.Looper;
import android.provider.Settings;
import android.view.View;
import android.widget.Button;
import android.widget.LinearLayout;
import android.widget.TextView;

import java.text.SimpleDateFormat;
import java.util.Date;
import java.util.List;
import java.util.Locale;

/** মূল স্ক্রিন: অবস্থা, আজকের হিসাব, আজকের SMS, ☰ মেনু (History / মেয়াদ / Logout) */
public class MainActivity extends Activity {

    private TextView tvKeyInfo, tvStatus, tvStatusSub, tvToday, tvCount, tvEmpty;
    private Button btnFix;
    private LinearLayout list;
    private final Handler ui = new Handler(Looper.getMainLooper());
    private boolean busy = false;
    private long lastWork = 0;
    private boolean askedPermission = false;

    // স্ক্রিন খোলা থাকলে: প্রতি ১০ সেকেন্ডে শুধু ফোনের ডাটাবেজ থেকে দেখানো (হালকা),
    // প্রতি ১ মিনিটে সার্ভারের কাজ
    private final Runnable tick = new Runnable() {
        @Override
        public void run() {
            render();
            if (System.currentTimeMillis() - lastWork > 60 * 1000L) work();
            ui.postDelayed(this, 10 * 1000L);
        }
    };

    @Override
    protected void onCreate(Bundle b) {
        super.onCreate(b);
        CrashLog.install(this);
        if (!Session.loggedIn(this)) { toLogin(); return; }
        setContentView(R.layout.activity_main);
        tvKeyInfo = findViewById(R.id.tvKeyInfo);
        tvStatus = findViewById(R.id.tvStatus);
        tvStatusSub = findViewById(R.id.tvStatusSub);
        tvToday = findViewById(R.id.tvToday);
        tvCount = findViewById(R.id.tvCount);
        tvEmpty = findViewById(R.id.tvEmpty);
        btnFix = findViewById(R.id.btnFix);
        list = findViewById(R.id.list);

        Ui.box(findViewById(R.id.cardStatus), Ui.CARD, Ui.LINE, 18);
        Ui.box(findViewById(R.id.boxToday), Ui.CARD, Ui.LINE, 16);
        Ui.box(findViewById(R.id.boxCount), Ui.CARD, Ui.LINE, 16);
        Ui.box(btnFix, Ui.BRAND, null, 12);
        Ui.fitWindow(getWindow(), findViewById(R.id.mainRoot));
        Ui.lightBars(getWindow());
        Ui.box(findViewById(R.id.listCard), Ui.CARD, Ui.LINE, 18);
        ((android.widget.ImageView) findViewById(R.id.btnMenu)).setImageDrawable(Icons.get("menu", Color.parseColor(Ui.BRAND)));
        findViewById(R.id.btnMenu).setOnClickListener(v -> openMenu());

        SyncJob.schedulePeriodic(this);
        CrashLog.showIfAny(this);
    }

    @Override
    protected void onResume() {
        super.onResume();
        if (!Session.loggedIn(this)) { toLogin(); return; }
        if (!Sync.hasSmsPermission(this) && !askedPermission) {
            askedPermission = true;
            requestPermissions(new String[]{Manifest.permission.RECEIVE_SMS, Manifest.permission.READ_SMS}, 1);
        }
        lastWork = 0;
        ui.removeCallbacks(tick);
        ui.post(tick);
    }

    @Override
    protected void onPause() {
        super.onPause();
        ui.removeCallbacks(tick);
    }

    @Override
    public void onRequestPermissionsResult(int code, String[] p, int[] r) {
        super.onRequestPermissionsResult(code, p, r);
        lastWork = 0;
        render();
        work();
    }

    /** সার্ভারের কাজ ব্যাকগ্রাউন্ডে: কী যাচাই → মিস হওয়া SMS → পাঠানো */
    private void work() {
        if (busy) return;
        busy = true;
        lastWork = System.currentTimeMillis();
        final android.content.Context app = getApplicationContext();
        new Thread(() -> {
            int k = KeyCheck.UNKNOWN;
            try {
                k = KeyCheck.check(app);
                if (k != KeyCheck.INVALID) {
                    Sync.scanInbox(app);
                    if (Sync.upload(app) > 0) SyncJob.scheduleOnline(app);
                }
            } catch (Exception ignored) {}
            final int result = k;
            ui.post(() -> {
                busy = false;
                if (isFinishing()) return;
                if (result == KeyCheck.INVALID || !Session.loggedIn(app)) toLogin();
                else render();
            });
        }).start();
    }

    private void render() {
        if (tvStatus == null) return;
        if (!Session.loggedIn(this)) { toLogin(); return; }
        Db db = Db.get(this);

        // নামের নিচে ট্যাগলাইন (মেয়াদ / ফোন ☰ মেনুতে)
        tvKeyInfo.setText("Faster • Safer • Smarter");

        // অবস্থা (সবচেয়ে জরুরিটা আগে)
        int pending = db.pendingCount();
        String note = Session.note(this);
        btnFix.setVisibility(View.GONE);
        if (!Sync.hasSmsPermission(this)) {
            status("📵 SMS permission is OFF", Ui.RED,
                    "Payment SMS cannot be read. Tap below → Permissions → SMS → Allow.\n"
                            + "If it says “Restricted setting”: on App info tap ⋮ (top-right) → “Allow restricted settings”, then allow SMS.");
            btnFix.setText("OPEN APP SETTINGS");
            btnFix.setVisibility(View.VISIBLE);
            btnFix.setOnClickListener(v -> {
                Intent i = new Intent(Settings.ACTION_APPLICATION_DETAILS_SETTINGS);
                i.setData(Uri.parse("package:" + getPackageName()));
                startActivity(i);
            });
        } else if (Session.noWalletOn(this)) {
            status("⚙ No wallet is ON on the website", Ui.AMBER,
                    "Website → Dashboard → Wallet Setup: turn ON bKash / Nagad / … — only those SMS are read.");
        } else if (pending > 0) {
            status("⏳ " + pending + " SMS waiting to upload", Ui.AMBER,
                    note.isEmpty() ? "Uploading automatically…" : note);
        } else {
            status("✓ Active — all SMS uploaded", Ui.GREEN, "Reading: " + Session.walletsText(this));
        }

        tvToday.setText("Tk " + fmt(db.todayAmount()));
        tvCount.setText(String.valueOf(db.todayCount()));

        // আজকের SMS (সব দেখতে ☰ → All History)
        List<Db.Row> rows = db.today(50);
        list.removeAllViews();
        tvEmpty.setVisibility(rows.isEmpty() ? View.VISIBLE : View.GONE);
        TextView cnt = findViewById(R.id.tvListCount);
        if (cnt != null) cnt.setText(rows.size() + " SMS");
        for (Db.Row r : rows) {
            LinearLayout.LayoutParams lp = new LinearLayout.LayoutParams(-1, -2);
            lp.bottomMargin = Ui.dp(this, 8);
            list.addView(smsRow(this, r), lp);
        }
    }

    /** wallet অনুযায়ী রঙ */
    static String walletColor(String m) {
        String u = m == null ? "" : m.toUpperCase(Locale.ROOT);
        if (u.contains("BKASH")) return "#E2136E";
        if (u.contains("NAGAD")) return "#EC1C24";
        if (u.contains("ROCKET")) return "#8C3494";
        if (u.contains("UPAY")) return "#D19C00";
        if (u.contains("QR")) return "#0284C7";
        if (u.contains("BINANCE")) return "#C99400";
        return Ui.BRAND;
    }

    /** wallet অনুযায়ী আইকন */
    static String walletIcon(String m) {
        String u = m == null ? "" : m.toUpperCase(Locale.ROOT);
        if (u.contains("BKASH")) return "send";
        if (u.contains("NAGAD")) return "coins";
        if (u.contains("ROCKET")) return "rocket";
        if (u.contains("UPAY")) return "mobile";
        if (u.contains("TAP")) return "tap";
        if (u.contains("IPAY")) return "card";
        if (u.contains("QR")) return "qr";
        if (u.contains("BINANCE")) return "coins";
        if (u.contains("CELLFIN") || u.contains("BANK") || u.contains("DBBL") || u.contains("BRAC") || u.contains("CITY")
                || u.contains("EBL") || u.contains("IFIC") || u.contains("SONALI") || u.contains("AGRANI") || u.contains("JAMUNA")) return "bank";
        return "wallet";
    }

    static String walletName(String m) {
        String u = m == null ? "" : m.toUpperCase(Locale.ROOT);
        if (u.contains("BKASH")) return "bKash";
        if (u.contains("NAGAD")) return "Nagad";
        if (u.contains("ROCKET")) return "Rocket";
        if (u.contains("UPAY")) return "Upay";
        if (u.contains("QR")) return "Bangla QR";
        if (u.contains("BINANCE")) return "Binance";
        return m == null || m.isEmpty() ? "Payment" : m;
    }

    /**
     * একটা SMS এর কার্ড (মূল স্ক্রিন + History):
     * [রঙিন ব্যাজ]  bKash                Tk 120
     *               DK552XX043 • 12:37 PM   ✓ Uploaded
     */
    static View smsRow(android.content.Context c, Db.Row r) {
        LinearLayout row = new LinearLayout(c);
        row.setOrientation(LinearLayout.HORIZONTAL);
        row.setGravity(android.view.Gravity.CENTER_VERTICAL);
        int p = Ui.dp(c, 12);
        row.setPadding(p, p, p, p);
        Ui.box(row, Ui.INPUT, Ui.LINE, 14);
        String col = walletColor(r.method);

        // বামে wallet ব্যাজ (রঙিন বক্সে সাদা আইকন)
        String name = walletName(r.method);
        android.widget.ImageView badge = Ui.icon(c, walletIcon(r.method), "#FFFFFF");
        int bp = Ui.dp(c, 10);
        badge.setPadding(bp, bp, bp, bp);
        Ui.box(badge, col, null, 12);
        int bs = Ui.dp(c, 42);
        row.addView(badge, new LinearLayout.LayoutParams(bs, bs));

        // মাঝে নাম + TrxID/সময়
        LinearLayout mid = new LinearLayout(c);
        mid.setOrientation(LinearLayout.VERTICAL);
        LinearLayout.LayoutParams midLp = new LinearLayout.LayoutParams(0, -2, 1f);
        midLp.leftMargin = Ui.dp(c, 12);
        TextView tName = new TextView(c);
        tName.setText(name);
        tName.setTextColor(Color.parseColor(Ui.TEXT));
        tName.setTextSize(15);
        tName.setTypeface(android.graphics.Typeface.DEFAULT_BOLD);
        TextView tSub = new TextView(c);
        tSub.setText(r.trx + "  •  " + new SimpleDateFormat("dd MMM, hh:mm a", Locale.US).format(new Date(r.time)));
        tSub.setTextColor(Color.parseColor(Ui.MUTED));
        tSub.setTextSize(12);
        tSub.setSingleLine(true);
        tSub.setEllipsize(android.text.TextUtils.TruncateAt.END);
        mid.addView(tName);
        mid.addView(tSub);
        row.addView(mid, midLp);

        // ডানে টাকা + অবস্থা
        LinearLayout right = new LinearLayout(c);
        right.setOrientation(LinearLayout.VERTICAL);
        right.setGravity(android.view.Gravity.END);
        TextView tAmt = new TextView(c);
        tAmt.setText("Tk " + fmt(r.amount));
        tAmt.setTextColor(Color.parseColor(Ui.TEXT));
        tAmt.setTextSize(16);
        tAmt.setTypeface(android.graphics.Typeface.DEFAULT_BOLD);
        tAmt.setGravity(android.view.Gravity.END);
        TextView tSt = new TextView(c);
        tSt.setText(r.status == Db.SENT ? "✓ Uploaded" : r.status == Db.REJECTED ? "✕ Not accepted" : "⏳ Waiting");
        tSt.setTextColor(Color.parseColor(r.status == Db.SENT ? Ui.GREEN : r.status == Db.REJECTED ? Ui.RED : Ui.AMBER));
        tSt.setTextSize(11);
        tSt.setTypeface(android.graphics.Typeface.DEFAULT_BOLD);
        tSt.setGravity(android.view.Gravity.END);
        right.addView(tAmt);
        right.addView(tSt);
        LinearLayout.LayoutParams rLp = new LinearLayout.LayoutParams(-2, -2);
        rLp.leftMargin = Ui.dp(c, 8);
        row.addView(right, rLp);
        return row;
    }

    /** ☰ মেনু: All History, কী এর মেয়াদ, Logout */
    private void openMenu() {
        String validity;
        if (Session.isMaster(this)) {
            validity = "Master key • no expiry";
        } else {
            long left = Session.expire(this) - System.currentTimeMillis();
            validity = (left / 86400000L) + " days " + ((left % 86400000L) / 3600000L) + " hours left • until "
                    + new SimpleDateFormat("dd MMM yyyy, hh:mm a", Locale.US).format(new Date(Session.expire(this)));
        }
        Ui.menu(this, "MENU",
                new Ui.Item("history", "All History", Db.get(this).totalCount() + " payment SMS saved on this phone", Ui.BRAND,
                        this::openHistory),
                new Ui.Item("calendar", "Account validity", validity, Ui.GREEN, null),
                new Ui.Item("mobile", "This phone", Session.deviceName(), Ui.MUTED, null),
                new Ui.Item("logout", "Logout", "You can log in again on this same phone", Ui.RED, this::askLogout));
    }

    // ---------------- All History (এই স্ক্রিনের ভেতরেই, আলাদা Activity নেই — তাই কখনো crash হবে না) ----------------
    private final java.util.List<Db.Row> histRows = new java.util.ArrayList<>();

    private void openHistory() {
        final android.app.Dialog d = new android.app.Dialog(this, android.R.style.Theme_Material_Light_NoActionBar);
        d.setContentView(R.layout.activity_history);

        android.view.Window w = d.getWindow();
        if (w != null) {
            w.setBackgroundDrawable(new android.graphics.drawable.ColorDrawable(Color.parseColor(Ui.BG)));
            w.setStatusBarColor(Color.parseColor(Ui.BG));
            w.setNavigationBarColor(Color.parseColor(Ui.BG));
            Ui.lightBars(w);
            Ui.fitWindow(w, d.findViewById(R.id.historyRoot));   // ← বাটন যেন স্ট্যাটাস বারের নিচে থাকে
        }
        Ui.box(d.findViewById(R.id.histCard), Ui.CARD, Ui.LINE, 18);
        d.findViewById(R.id.btnBack).setOnClickListener(v -> d.dismiss());
        Ui.box(d.findViewById(R.id.tabs), Ui.INPUT, Ui.LINE, 14);
        final TextView tabAll = d.findViewById(R.id.tabAll), tabSent = d.findViewById(R.id.tabSent), tabWait = d.findViewById(R.id.tabWait);
        final TextView tvTotal = d.findViewById(R.id.tvTotal), tvNone = d.findViewById(R.id.tvNone);
        final android.widget.BaseAdapter ad = new android.widget.BaseAdapter() {
            @Override public int getCount() { return histRows.size(); }
            @Override public Object getItem(int i) { return histRows.get(i); }
            @Override public long getItemId(int i) { return i; }
            @Override public View getView(int i, View old, android.view.ViewGroup parent) {
                // প্রতিটা সারি একটা ফ্রেমে — ListView এর নিয়ম মেনে
                android.widget.FrameLayout f = new android.widget.FrameLayout(MainActivity.this);
                f.setPadding(0, 0, 0, Ui.dp(MainActivity.this, 8));
                f.addView(smsRow(MainActivity.this, histRows.get(i)));
                return f;
            }
        };
        ((android.widget.ListView) d.findViewById(R.id.listAll)).setAdapter(ad);
        final int[] cur = {-1};
        final Runnable load = () -> {
            histRows.clear();
            histRows.addAll(Db.get(this).history(cur[0], 500));
            ad.notifyDataSetChanged();
            tvNone.setVisibility(histRows.isEmpty() ? View.VISIBLE : View.GONE);
            d.findViewById(R.id.listAll).setVisibility(histRows.isEmpty() ? View.GONE : View.VISIBLE);
            tvTotal.setText(histRows.size() + " SMS");
            tab(tabAll, cur[0] == -1);
            tab(tabSent, cur[0] == Db.SENT);
            tab(tabWait, cur[0] == Db.PENDING);
        };
        tabAll.setOnClickListener(v -> { cur[0] = -1; load.run(); });
        tabSent.setOnClickListener(v -> { cur[0] = Db.SENT; load.run(); });
        tabWait.setOnClickListener(v -> { cur[0] = Db.PENDING; load.run(); });
        load.run();
        d.show();
    }

    private static void tab(TextView t, boolean on) {
        if (on) {
            Ui.box(t, Ui.BRAND, null, 11);
            t.setTextColor(Color.parseColor(Ui.ON_BRAND));
        } else {
            t.setBackground(null);
            t.setTextColor(Color.parseColor(Ui.MUTED));
        }
    }

    private void askLogout() {
        Ui.confirm(this, "logout", "Logout?", "You can log in again later on this same phone with your key.",
                "Logout", Ui.RED, () -> { Session.logout(this, ""); toLogin(); });
    }

    private void status(String title, String color, String sub) {
        tvStatus.setText(title);
        tvStatus.setTextColor(Color.parseColor(color));
        tvStatusSub.setText(sub);
    }

    static String fmt(double v) {
        return v == Math.floor(v) ? String.valueOf((long) v) : String.format(Locale.US, "%.2f", v);
    }

    private void toLogin() {
        ui.removeCallbacks(tick);
        startActivity(new Intent(this, LoginActivity.class));
        finish();
    }
}
