package com.example.snzpay;

import android.Manifest;
import android.app.Activity;
import android.app.Dialog;
import android.content.BroadcastReceiver;
import android.content.Context;
import android.content.Intent;
import android.content.IntentFilter;
import android.content.SharedPreferences;
import android.content.pm.PackageManager;
import android.graphics.Bitmap;
import android.graphics.Color;
import android.graphics.drawable.ColorDrawable;
import android.net.Uri;
import android.os.Build;
import android.os.Bundle;
import android.os.PowerManager;
import android.provider.Settings;
import android.view.LayoutInflater;
import android.view.View;
import android.view.ViewGroup;
import android.view.animation.Animation;
import android.view.animation.ScaleAnimation;
import android.widget.Button;
import android.widget.ImageView;
import android.widget.LinearLayout;
import android.widget.TextView;
import android.widget.Toast;

import org.json.JSONObject;

import java.util.ArrayList;
import java.util.List;
import java.util.Locale;
import java.util.concurrent.TimeUnit;

public class MainActivity extends Activity {

    private DatabaseHelper dbHelper;
    private TextView tvTodayDeposit, tvTotalDeposit, tvTotalCount, btnMenu;
    private TextView tvAppName, tvTagline, tvNotice, tvSyncStatus, tvTodayCount, tvTodayEmpty;
    private LinearLayout listToday;
    private View tvLiveLogo;
    private boolean receiverRegistered = false;

    private final BroadcastReceiver refreshReceiver = new BroadcastReceiver() {
        @Override
        public void onReceive(Context context, Intent intent) {
            loadDashboardData();
        }
    };

    @Override
    protected void onCreate(Bundle savedInstanceState) {
        super.onCreate(savedInstanceState);

        if (!validateSession()) {
            performLogout();
            return;
        }

        setContentView(R.layout.activity_main);

        final View mainView = findViewById(R.id.main_layout);
        View scrollRoot = findViewById(R.id.main_scroll);
        if (mainView != null && scrollRoot != null && Build.VERSION.SDK_INT >= 20) {
            final int baseSidePadding = (int) (22 * getResources().getDisplayMetrics().density);
            final int baseTopPadding = (int) (18 * getResources().getDisplayMetrics().density);
            scrollRoot.setOnApplyWindowInsetsListener((v, insets) -> {
                mainView.setPadding(
                        insets.getSystemWindowInsetLeft() + baseSidePadding,
                        insets.getSystemWindowInsetTop() + baseTopPadding,
                        insets.getSystemWindowInsetRight() + baseSidePadding,
                        insets.getSystemWindowInsetBottom() + baseSidePadding);
                return insets;
            });
        }

        dbHelper = DatabaseHelper.get(this);
        initViews();
        applyRemoteConfig();
        startLiveLogoAnimation();
        forceRequestPermissions();
        askBatteryOptimizationOnce();
        loadDashboardData();
    }

    private void initViews() {
        tvTodayDeposit = findViewById(R.id.tvTodayDeposit);
        tvTotalDeposit = findViewById(R.id.tvTotalDeposit);
        tvTotalCount = findViewById(R.id.tvTotalCount);
        btnMenu = findViewById(R.id.btnMenu);
        tvLiveLogo = findViewById(R.id.tvLiveLogo);
        tvAppName = findViewById(R.id.tvAppName);
        tvTagline = findViewById(R.id.tvTagline);
        tvNotice = findViewById(R.id.tvNotice);
        tvSyncStatus = findViewById(R.id.tvSyncStatus);
        tvTodayCount = findViewById(R.id.tvTodayCount);
        tvTodayEmpty = findViewById(R.id.tvTodayEmpty);
        listToday = findViewById(R.id.listToday);

        btnMenu.setOnClickListener(v -> showSettingsMenuDialog());
        View viewAll = findViewById(R.id.btnViewAllHistory);
        if (viewAll != null) viewAll.setOnClickListener(v -> openHistory());
    }

    private void openHistory() {
        startActivity(new Intent(this, HistoryActivity.class));
    }

    /** ওয়েবসাইট থেকে আসা নাম, ট্যাগলাইন, লোগো, নোটিশ, On/Off, আপডেট */
    private void applyRemoteConfig() {
        if (isFinishing()) return;
        JSONObject cfg = AppConfig.get(this);
        if (tvAppName != null) tvAppName.setText(cfg.optString("app_name", "SNZ Pay"));
        if (tvTagline != null) tvTagline.setText(cfg.optString("app_tagline", "Faster • Safer • Smarter"));
        if (tvNotice != null) {
            String notice = cfg.optString("notice", "").trim();
            tvNotice.setText(notice);
            tvNotice.setVisibility(notice.isEmpty() ? View.GONE : View.VISIBLE);
        }
        Bitmap remote = AppConfig.getLogo(this);
        if (remote != null && tvLiveLogo instanceof ImageView) ((ImageView) tvLiveLogo).setImageBitmap(remote);
        RemoteDialogs.check(this);
    }

    private void startLiveLogoAnimation() {
        if (tvLiveLogo != null) {
            ScaleAnimation pulse = new ScaleAnimation(0.92f, 1.08f, 0.92f, 1.08f,
                    Animation.RELATIVE_TO_SELF, 0.5f, Animation.RELATIVE_TO_SELF, 0.5f);
            pulse.setDuration(1200);
            pulse.setRepeatMode(Animation.REVERSE);
            pulse.setRepeatCount(Animation.INFINITE);
            tvLiveLogo.startAnimation(pulse);
        }
    }

    private void showSettingsMenuDialog() {
        Dialog dialog = new Dialog(this);
        dialog.setContentView(R.layout.dialog_settings);

        TextView tvValidity = dialog.findViewById(R.id.tvDialogValidity);
        TextView tvTitle = dialog.findViewById(R.id.tvDialogTitle);
        TextView tvSync = dialog.findViewById(R.id.tvDialogSync);
        Button btnLogout = dialog.findViewById(R.id.btnDialogLogout);
        Button btnClose = dialog.findViewById(R.id.btnDialogClose);
        Button btnSyncNow = dialog.findViewById(R.id.btnDialogSyncNow);
        Button btnHistory = dialog.findViewById(R.id.btnDialogHistory);
        if (btnHistory != null) btnHistory.setOnClickListener(v -> {
            dialog.dismiss();
            openHistory();
        });

        if (tvTitle != null) tvTitle.setText(AppConfig.get(this).optString("app_name", "SNZ Pay") + " Controller");

        SharedPreferences prefs = getSharedPreferences(AppConfig.AUTH_PREFS, MODE_PRIVATE);
        String key = prefs.getString("SAVED_KEY", "");
        long expireTime = prefs.getLong("EXPIRE_TIME", 0);

        if (AppConfig.MASTER_KEY.equalsIgnoreCase(key) || expireTime == Long.MAX_VALUE || expireTime > 4000000000000L) {
            tvValidity.setText("VIP Lifetime Active");
        } else {
            long remaining = expireTime - System.currentTimeMillis();
            if (remaining <= 0) {
                tvValidity.setText("Expired");
            } else {
                long days = TimeUnit.MILLISECONDS.toDays(remaining);
                long hours = TimeUnit.MILLISECONDS.toHours(remaining) % 24;
                long mins = TimeUnit.MILLISECONDS.toMinutes(remaining) % 60;
                if (days > 0) tvValidity.setText(days + (days == 1 ? " Day " : " Days ") + hours + " Hours Left");
                else if (hours > 0) tvValidity.setText(hours + " Hours " + mins + " Minutes Left");
                else tvValidity.setText(mins + " Minutes Left");
            }
        }

        if (tvSync != null) {
            int waiting = dbHelper.getUnsyncedCount();
            tvSync.setText(waiting == 0 ? "All SMS uploaded ✓" : waiting + " SMS waiting to upload");
            tvSync.setTextColor(waiting == 0 ? Color.parseColor("#16A34A") : Color.parseColor("#D97706"));
        }

        if (btnSyncNow != null) {
            btnSyncNow.setOnClickListener(v -> {
                Toast.makeText(this, "Checking SMS & uploading…", Toast.LENGTH_SHORT).show();
                final Context app = getApplicationContext();
                new Thread(() -> {
                    AppConfig.fetch(app);
                    SyncManager.scanInbox(app);
                    int left = SyncManager.syncPending(app);
                    runOnUiThread(() -> {
                        if (isFinishing()) return;
                        Toast.makeText(this, left == 0 ? "All SMS uploaded ✓" : left + " SMS still waiting (check internet)", Toast.LENGTH_LONG).show();
                        loadDashboardData();
                        applyRemoteConfig();
                        try { dialog.dismiss(); } catch (Exception ignored) {}
                    });
                }).start();
            });
        }

        btnLogout.setOnClickListener(v -> {
            dialog.dismiss();
            performLogout();
        });
        btnClose.setOnClickListener(v -> dialog.dismiss());
        dialog.show();

        if (dialog.getWindow() != null) {
            dialog.getWindow().setBackgroundDrawable(new ColorDrawable(Color.TRANSPARENT));
            int dialogWidth = (int) (getResources().getDisplayMetrics().widthPixels * 0.90);
            dialog.getWindow().setLayout(dialogWidth, -2);
        }
    }

    private void loadDashboardData() {
        if (dbHelper == null || tvTodayDeposit == null) return;
        double today = dbHelper.getTodayDeposit();
        double total = dbHelper.getTotalDeposit();
        int count = dbHelper.getReceivedCount();
        int waiting = dbHelper.getUnsyncedCount();

        tvTodayDeposit.setText("Tk " + String.format(Locale.US, "%.2f", today));
        tvTotalDeposit.setText("Tk " + String.format(Locale.US, "%.2f", total));
        tvTotalCount.setText(count + " Received");

        if (tvSyncStatus != null) {
            String err = SyncManager.getLastError(this);
            if (!AppConfig.isAppEnabled(this)) {
                tvSyncStatus.setText("⏸ Paused by admin — SMS are saved on this phone");
                tvSyncStatus.setTextColor(Color.parseColor("#D97706"));
            } else if (waiting == 0) {
                tvSyncStatus.setText("✓ All SMS uploaded to server");
                tvSyncStatus.setTextColor(Color.parseColor("#16A34A"));
            } else if (err == null || err.equals("No internet connection")) {
                tvSyncStatus.setText("⏳ " + waiting + " SMS waiting for internet — will upload automatically");
                tvSyncStatus.setTextColor(Color.parseColor("#D97706"));
            } else {
                // নেট আছে কিন্তু সার্ভার নিচ্ছে না → আসল কারণ দেখানো
                tvSyncStatus.setText("⚠ " + waiting + " SMS not uploaded: " + err);
                tvSyncStatus.setTextColor(Color.parseColor("#DC2626"));
            }
        }

        loadTodayList();
    }

    /** আজকের SMS বক্স — সর্বশেষ ৩০টা */
    private void loadTodayList() {
        if (listToday == null) return;
        java.util.List<DatabaseHelper.Tx> today = dbHelper.getTransactions(true, null, 30);
        listToday.removeAllViews();
        LayoutInflater inf = LayoutInflater.from(this);
        for (int i = 0; i < today.size(); i++) {
            View row = inf.inflate(R.layout.item_sms, listToday, false);
            SmsRow.bind(this, row, today.get(i), false);
            listToday.addView(row);
            if (i < today.size() - 1) {
                View line = new View(this);
                line.setBackgroundColor(Color.parseColor("#E2E8F0"));
                listToday.addView(line, new ViewGroup.LayoutParams(ViewGroup.LayoutParams.MATCH_PARENT, 1));
            }
        }
        if (tvTodayEmpty != null) tvTodayEmpty.setVisibility(today.isEmpty() ? View.VISIBLE : View.GONE);
        if (tvTodayCount != null) tvTodayCount.setText(dbHelper.getTodayCount() + " SMS");
    }

    private void forceRequestPermissions() {
        List<String> perms = new ArrayList<>();
        if (Build.VERSION.SDK_INT >= 23) {
            if (checkSelfPermission(Manifest.permission.RECEIVE_SMS) != PackageManager.PERMISSION_GRANTED)
                perms.add(Manifest.permission.RECEIVE_SMS);
            if (checkSelfPermission(Manifest.permission.READ_SMS) != PackageManager.PERMISSION_GRANTED)
                perms.add(Manifest.permission.READ_SMS);
        }
        if (Build.VERSION.SDK_INT >= 33) {
            if (checkSelfPermission(Manifest.permission.POST_NOTIFICATIONS) != PackageManager.PERMISSION_GRANTED)
                perms.add(Manifest.permission.POST_NOTIFICATIONS);
        }
        if (!perms.isEmpty() && Build.VERSION.SDK_INT >= 23) {
            requestPermissions(perms.toArray(new String[0]), 101);
        }
        start24x7Service();
    }

    /** ব্যাটারি সেভার যেন অ্যাপ বন্ধ না করে — একবারই জিজ্ঞেস করা হয় */
    private void askBatteryOptimizationOnce() {
        if (Build.VERSION.SDK_INT < 23) return;
        SharedPreferences p = getSharedPreferences("SNZ_UI", MODE_PRIVATE);
        if (p.getBoolean("battery_asked", false)) return;
        try {
            PowerManager pm = (PowerManager) getSystemService(Context.POWER_SERVICE);
            if (pm != null && !pm.isIgnoringBatteryOptimizations(getPackageName())) {
                Intent i = new Intent(Settings.ACTION_REQUEST_IGNORE_BATTERY_OPTIMIZATIONS);
                i.setData(Uri.parse("package:" + getPackageName()));
                startActivity(i);
            }
        } catch (Exception ignored) {}
        p.edit().putBoolean("battery_asked", true).apply();
    }

    private void start24x7Service() {
        SyncManager.ensureServiceRunning(this);
        SyncJobService.schedulePeriodic(this);
    }

    @Override
    public void onRequestPermissionsResult(int requestCode, String[] permissions, int[] grantResults) {
        super.onRequestPermissionsResult(requestCode, permissions, grantResults);
        start24x7Service();
        // পারমিশন পাওয়ার পর মিস হওয়া SMS খোঁজা
        final Context app = getApplicationContext();
        new Thread(() -> {
            SyncManager.scanInbox(app);
            SyncManager.syncPending(app);
        }).start();
    }

    private boolean validateSession() {
        SharedPreferences prefs = getSharedPreferences(AppConfig.AUTH_PREFS, MODE_PRIVATE);
        String key = prefs.getString("SAVED_KEY", "");
        if (AppConfig.MASTER_KEY.equalsIgnoreCase(key)) return true;

        long expireTime = prefs.getLong("EXPIRE_TIME", 0);
        String deviceId = Settings.Secure.getString(getContentResolver(), Settings.Secure.ANDROID_ID);
        String lockedDevice = prefs.getString("LOCKED_DEVICE", "");
        return !key.isEmpty() && deviceId != null && deviceId.equals(lockedDevice) && System.currentTimeMillis() < expireTime;
    }

    private void performLogout() {
        // আগে লগইন তথ্য মোছা, তারপর সার্ভিস বন্ধ — না হলে সার্ভিস নিজেকে আবার চালু করে ফেলত
        getSharedPreferences(AppConfig.AUTH_PREFS, MODE_PRIVATE).edit().clear().commit();
        SyncJobService.cancelAll(this);
        stopService(new Intent(this, PaymentService.class));

        Intent intent = new Intent(this, LoginActivity.class);
        intent.setFlags(Intent.FLAG_ACTIVITY_NEW_TASK | Intent.FLAG_ACTIVITY_CLEAR_TASK);
        startActivity(intent);
        finish();
    }

    @Override
    protected void onResume() {
        super.onResume();
        if (isFinishing()) return;
        start24x7Service();
        if (!receiverRegistered) {
            if (Build.VERSION.SDK_INT >= 33) {
                registerReceiver(refreshReceiver, new IntentFilter(AppConfig.ACTION_REFRESH), Context.RECEIVER_NOT_EXPORTED);
            } else {
                registerReceiver(refreshReceiver, new IntentFilter(AppConfig.ACTION_REFRESH));
            }
            receiverRegistered = true;
        }
        loadDashboardData();

        // অ্যাপ খুললেই: নতুন সেটিংস + মিস হওয়া SMS + বাকি SMS আপলোড
        AppConfig.fetchAsync(this, () -> {
            applyRemoteConfig();
            loadDashboardData();
        });
        final Context app = getApplicationContext();
        new Thread(() -> {
            SyncManager.scanInbox(app);
            SyncManager.syncPending(app);
        }).start();
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
