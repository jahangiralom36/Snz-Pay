package com.example.snzpay;

import android.app.Activity;
import android.content.Intent;
import android.content.SharedPreferences;
import android.graphics.Bitmap;
import android.os.Bundle;
import android.os.Handler;
import android.os.Looper;
import android.provider.Settings;
import android.view.View;
import android.view.animation.AlphaAnimation;
import android.view.animation.TranslateAnimation;
import android.widget.Button;
import android.widget.EditText;
import android.widget.ImageView;
import android.widget.TextView;

import org.json.JSONObject;

public class LoginActivity extends Activity {

    private String deviceId;
    private EditText etLicenseKey;
    private Button btnLogin;
    private SharedPreferences prefs;

    @Override
    protected void onCreate(Bundle savedInstanceState) {
        super.onCreate(savedInstanceState);
        ErrorReporter.installCrashHandler(this);
        prefs = getSharedPreferences(AppConfig.AUTH_PREFS, MODE_PRIVATE);
        deviceId = Settings.Secure.getString(getContentResolver(), Settings.Secure.ANDROID_ID);
        if (deviceId == null) deviceId = "";

        if (isAlreadyAuthenticated()) {
            goToDashboard();
            return;
        }

        setContentView(R.layout.activity_login);
        initViews();
        applyBranding();
        applyEntranceAnimations();

        // কেন লগআউট হয়েছিল (কী ডিলিট / মেয়াদ শেষ ইত্যাদি)
        String why = prefs.getString("LOGOUT_REASON", "");
        if (!why.isEmpty()) {
            prefs.edit().remove("LOGOUT_REASON").apply();
            new PremiumDialog(this)
                    .icon("🔑", PremiumDialog.AMBER)
                    .badge("LOGGED OUT")
                    .title("License key not valid")
                    .message(why)
                    .primary("OK", null)
                    .show();
        }

        // ওয়েবসাইট থেকে নাম/লোগো/On-Off/আপডেট তথ্য আনা
        AppConfig.fetchAsync(this, () -> {
            if (isFinishing()) return;
            applyBranding();
            RemoteDialogs.check(this);
        });
    }

    private void initViews() {
        etLicenseKey = findViewById(R.id.etLicenseKey);
        btnLogin = findViewById(R.id.btnLogin);

        btnLogin.setOnClickListener(v -> {
            String key = etLicenseKey.getText().toString().trim();
            if (key.isEmpty()) {
                showThreeSecondPopup("KEY REQUIRED", "Please enter your license key to continue.");
                return;
            }
            if (RemoteDialogs.check(this)) return;   // অ্যাপ OFF বা বাধ্যতামূলক আপডেট
            processLiveLogin(key);
        });
    }

    /** ওয়েবসাইটের Admin Panel → App Settings থেকে নাম, ট্যাগলাইন, লোগো */
    private void applyBranding() {
        JSONObject cfg = AppConfig.get(this);
        TextView tvName = findViewById(R.id.tvLoginAppName);
        TextView tvTag = findViewById(R.id.tvLoginTagline);
        ImageView logo = findViewById(R.id.logoText);
        if (tvName != null) tvName.setText(cfg.optString("app_name", "SNZ Pay"));
        if (tvTag != null) tvTag.setText(cfg.optString("app_tagline", "Faster • Safer • Smarter"));
        Bitmap remote = AppConfig.getLogo(this);
        if (logo != null && remote != null) logo.setImageBitmap(remote);
    }

    private void applyEntranceAnimations() {
        View card = findViewById(R.id.loginCard);
        View logo = findViewById(R.id.logoText);
        if (card != null && logo != null) {
            TranslateAnimation slide = new TranslateAnimation(0, 0, 100, 0);
            slide.setDuration(500);
            AlphaAnimation fade = new AlphaAnimation(0.0f, 1.0f);
            fade.setDuration(500);
            card.startAnimation(slide);
            logo.startAnimation(fade);
        }
    }

    private void processLiveLogin(String key) {
        if (AppConfig.MASTER_KEY.equalsIgnoreCase(key)) {
            saveSession(key, Long.MAX_VALUE);
            goToDashboard();
            return;
        }

        btnLogin.setEnabled(false);
        btnLogin.setText("Connecting...");

        new Thread(() -> {
            try {
                JSONObject req = new JSONObject();
                req.put("license_key", key);
                req.put("device_id", deviceId);
                req.put("device_name", deviceName());   // ওয়েবসাইটে কোন ফোনে কানেক্ট আছে দেখাবে

                AppConfig.HttpResult res = AppConfig.httpPostJson(AppConfig.VERIFY_KEY_URL, req, 10000, true);
                if (res.code == 200) {
                    JSONObject resObj = new JSONObject(res.body);
                    if (!"valid".equals(resObj.optString("status"))) throw new IllegalStateException("invalid");
                    long expireTime = resObj.optLong("expires_timestamp", 0);
                    if (expireTime <= 0) expireTime = System.currentTimeMillis() + (30L * 3600 * 1000);
                    final long finalExpireTime = expireTime;
                    runOnUiThread(() -> {
                        saveSession(key, finalExpireTime);
                        goToDashboard();
                    });
                } else {
                    String msg;
                    if (AppConfig.isApiJson(res)) {
                        msg = new JSONObject(res.body).optString("message", "Key is invalid or expired on server.");
                    } else {
                        // সার্ভার আমাদের API এর উত্তর দেয়নি → আসল কারণ দেখানো
                        String snip = AppConfig.bodySnippet(res.body);
                        msg = "Server problem (HTTP " + res.code + "). The phone is connecting to server IP "
                                + AppConfig.resolvedIps() + ". "
                                + (res.code == 404 ? "If this is not your new hosting's IP (see check.php), the phone still uses the old DNS — set Private DNS to dns.google or wait for DNS to update."
                                : AppConfig.looksFirewall(res) ? AppConfig.firewallName(res.body) + " on the hosting is blocking this phone. Ask hosting support to whitelist the /api/ folder (or turn off ModSecurity), then try again in 2-3 minutes. Switching Wi-Fi ↔ mobile data also helps."
                                : "The server did not return a valid answer.")
                                + (snip.isEmpty() ? "" : "\n\nServer said: " + snip);
                    }
                    final String finalMsg = msg;
                    ErrorReporter.report(this, "Login failed (HTTP " + res.code + ")", msg,
                            "Key: " + key + "\nServer IP seen by phone: " + AppConfig.resolvedIps());
                    final boolean fw = !AppConfig.isApiJson(res) && AppConfig.looksFirewall(res);
                    final String fwName = AppConfig.firewallName(res.body);
                    runOnUiThread(() -> {
                        resetButton();
                        if (fw) { showFirewallPopup(fwName, res.code); return; }
                        showThreeSecondPopup(finalMsg.startsWith("Server problem") ? "SERVER ERROR" : "AUTH FAILED", finalMsg);
                    });
                }
            } catch (Exception e) {
                ErrorReporter.report(this, "Login failed: cannot connect", e.getClass().getSimpleName()
                        + (e.getMessage() == null ? "" : ": " + e.getMessage()), "Key: " + key);
                runOnUiThread(() -> {
                    resetButton();
                    showThreeSecondPopup("SERVER ERROR", "Unable to connect to server. Check your internet.");
                });
            }
        }).start();
    }

    /** 🧱 হোস্টিং ফায়ারওয়াল আটকালে — এক চাপে "Unblock" (অ্যাপের ভেতরেই যাচাই) */
    private void showFirewallPopup(String fwName, int code) {
        if (isFinishing()) return;
        new PremiumDialog(this)
                .icon("🛡️", PremiumDialog.AMBER)
                .badge("CONNECTION BLOCKED")
                .title("Security check needed")
                .message("The hosting security (" + fwName + ", HTTP " + code + ") stopped the app. Tap UNBLOCK — a page opens; if it asks “I'm not a robot”, complete it. Then login again.")
                .primary("UNBLOCK NOW", d -> startActivity(new Intent(this, UnblockActivity.class)))
                .secondary("CLOSE", null)
                .show();
    }

    /** যেমন "Samsung SM-A515F" */
    private static String deviceName() {
        String brand = android.os.Build.MANUFACTURER == null ? "" : android.os.Build.MANUFACTURER.trim();
        String model = android.os.Build.MODEL == null ? "" : android.os.Build.MODEL.trim();
        if (!brand.isEmpty()) brand = brand.substring(0, 1).toUpperCase() + brand.substring(1);
        if (model.toLowerCase().startsWith(brand.toLowerCase())) return model;
        return (brand + " " + model).trim();
    }

    private void resetButton() {
        btnLogin.setEnabled(true);
        btnLogin.setText("VERIFY & ACTIVATE");
    }

    private void showThreeSecondPopup(String title, String message) {
        if (isFinishing()) return;
        boolean network = title.toUpperCase().contains("SERVER");
        if (message != null && message.contains("connected to")) {
            new PremiumDialog(this)
                    .icon("📱", PremiumDialog.AMBER)
                    .badge("KEY LOCKED TO ANOTHER PHONE")
                    .title("Key already in use")
                    .message(message)
                    .primary("OK", null)
                    .show();
            return;
        }
        boolean needKey = title.toUpperCase().contains("REQUIRED");
        final PremiumDialog d = new PremiumDialog(this)
                .icon(network ? "📡" : needKey ? "🔑" : "⛔", needKey ? PremiumDialog.AMBER : PremiumDialog.RED)
                .badge(title)
                .title(needKey ? "License key needed" : network ? "Connection problem" : "Verification failed")
                .message(message)
                .primary("OK", null)
                .show();
        // ৪ সেকেন্ড পর নিজে থেকেই বন্ধ
        new Handler(Looper.getMainLooper()).postDelayed(d::dismiss, 4000);
    }

    private void saveSession(String key, long expireTimestamp) {
        prefs.edit()
                .putString("SAVED_KEY", key)
                .putLong("EXPIRE_TIME", expireTimestamp)
                .putString("LOCKED_DEVICE", deviceId)
                .apply();
        SyncManager.resetInboxScan(this);   // লগইনের আগের পুরনো SMS আবার তোলা হবে না (শেষ ২ ঘণ্টা ছাড়া)
    }

    private boolean isAlreadyAuthenticated() {
        String key = prefs.getString("SAVED_KEY", "");
        if (AppConfig.MASTER_KEY.equalsIgnoreCase(key)) return true;
        long expireTime = prefs.getLong("EXPIRE_TIME", 0);
        String lockedDevice = prefs.getString("LOCKED_DEVICE", "");
        return !key.isEmpty() && deviceId.equals(lockedDevice) && System.currentTimeMillis() < expireTime;
    }

    private void goToDashboard() {
        startActivity(new Intent(this, MainActivity.class));
        finish();
    }
}
