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
        Ui.fitSystemBars(findViewById(R.id.mainRoot));
        findViewById(R.id.btnMenu).setOnClickListener(v -> openMenu());

        SyncJob.schedulePeriodic(this);
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

        // কী এর মেয়াদ
        if (Session.isMaster(this)) {
            tvKeyInfo.setText("Master key • all wallets");
        } else {
            long left = Session.expire(this) - System.currentTimeMillis();
            long days = left / 86400000L, hours = (left % 86400000L) / 3600000L;
            tvKeyInfo.setText("Key valid: " + days + "d " + hours + "h left • " + Session.deviceName());
        }

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
        for (Db.Row r : rows) {
            LinearLayout.LayoutParams lp = new LinearLayout.LayoutParams(-1, -2);
            lp.bottomMargin = Ui.dp(this, 8);
            list.addView(smsRow(this, r), lp);
        }
    }

    /** একটা SMS এর কার্ড (History স্ক্রিনেও এটাই) */
    static View smsRow(android.content.Context c, Db.Row r) {
        LinearLayout row = new LinearLayout(c);
        row.setOrientation(LinearLayout.VERTICAL);
        int p = Ui.dp(c, 12);
        row.setPadding(p, p, p, p);
        Ui.box(row, Ui.CARD, Ui.LINE, 14);

        TextView t1 = new TextView(c);
        t1.setText(r.method + "  •  Tk " + fmt(r.amount));
        t1.setTextColor(Color.WHITE);
        t1.setTextSize(15);
        t1.setTypeface(android.graphics.Typeface.DEFAULT_BOLD);

        TextView t2 = new TextView(c);
        String st = r.status == Db.SENT ? "✓ Uploaded" : r.status == Db.REJECTED ? "✕ Not accepted" : "⏳ Waiting";
        t2.setText(r.trx + "  •  " + new SimpleDateFormat("dd MMM, hh:mm a", Locale.US).format(new Date(r.time)) + "  •  " + st);
        t2.setTextColor(Color.parseColor(r.status == Db.SENT ? Ui.GREEN : r.status == Db.REJECTED ? Ui.RED : Ui.AMBER));
        t2.setTextSize(12);

        row.addView(t1);
        row.addView(t2);
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
                new Ui.Item("📜", "All History", Db.get(this).totalCount() + " payment SMS saved on this phone", Ui.BRAND,
                        () -> startActivity(new Intent(this, HistoryActivity.class))),
                new Ui.Item("🔑", "Account validity", validity, Ui.GREEN, null),
                new Ui.Item("📱", "This phone", Session.deviceName(), Ui.MUTED, null),
                new Ui.Item("🚪", "Logout", "You can log in again on this same phone", Ui.RED, this::askLogout));
    }

    private void askLogout() {
        Ui.confirm(this, "🚪", "Logout?", "You can log in again later on this same phone with your key.",
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
