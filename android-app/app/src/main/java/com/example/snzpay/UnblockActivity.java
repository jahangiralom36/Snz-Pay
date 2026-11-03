package com.example.snzpay;

import android.app.Activity;
import android.graphics.Color;
import android.graphics.drawable.GradientDrawable;
import android.os.Bundle;
import android.view.Gravity;
import android.view.View;
import android.view.ViewGroup;
import android.webkit.CookieManager;
import android.webkit.WebChromeClient;
import android.webkit.WebSettings;
import android.webkit.WebView;
import android.webkit.WebViewClient;
import android.widget.FrameLayout;
import android.widget.LinearLayout;
import android.widget.ProgressBar;
import android.widget.TextView;
import android.widget.Toast;

/**
 * 🧱 হোস্টিং ফায়ারওয়াল "আপনি কি রোবট?" যাচাই চাইলে — অ্যাপের ভেতরেই ব্রাউজারের মতো খুলে
 * ব্যবহারকারী একবার যাচাই করলে ফোনের IP / কুকি ছাড়া পায়, তারপর অ্যাপ আবার স্বাভাবিক চলে।
 */
public class UnblockActivity extends Activity {

    private WebView web;
    private TextView status;
    private boolean done = false;

    @Override
    protected void onCreate(Bundle b) {
        super.onCreate(b);
        int dark = Color.parseColor("#061535");

        LinearLayout root = new LinearLayout(this);
        root.setOrientation(LinearLayout.VERTICAL);
        root.setBackgroundColor(dark);

        LinearLayout bar = new LinearLayout(this);
        bar.setOrientation(LinearLayout.HORIZONTAL);
        bar.setGravity(Gravity.CENTER_VERTICAL);
        int p = dp(14);
        bar.setPadding(p, dp(34), p, p);
        TextView title = new TextView(this);
        title.setText("🛡️  Unblock connection");
        title.setTextColor(Color.WHITE);
        title.setTextSize(17);
        title.setTypeface(android.graphics.Typeface.DEFAULT_BOLD);
        bar.addView(title, new LinearLayout.LayoutParams(0, ViewGroup.LayoutParams.WRAP_CONTENT, 1f));
        TextView close = new TextView(this);
        close.setText("✕");
        close.setTextColor(Color.WHITE);
        close.setTextSize(20);
        close.setPadding(dp(12), 0, dp(4), 0);
        close.setOnClickListener(v -> finish());
        bar.addView(close);
        root.addView(bar);

        status = new TextView(this);
        status.setText("If you see a check box or “I'm not a robot”, complete it. This page closes by itself when the connection is OK.");
        status.setTextColor(Color.parseColor("#CBD5E1"));
        status.setTextSize(13);
        status.setPadding(p, 0, p, p);
        root.addView(status);

        FrameLayout box = new FrameLayout(this);
        GradientDrawable bg = new GradientDrawable();
        bg.setColor(Color.WHITE);
        bg.setCornerRadii(new float[]{dp(22), dp(22), dp(22), dp(22), 0, 0, 0, 0});
        box.setBackground(bg);
        box.setClipToOutline(true);
        web = new WebView(this);
        ProgressBar pb = new ProgressBar(this);
        box.addView(web, new FrameLayout.LayoutParams(ViewGroup.LayoutParams.MATCH_PARENT, ViewGroup.LayoutParams.MATCH_PARENT));
        FrameLayout.LayoutParams lp = new FrameLayout.LayoutParams(dp(42), dp(42), Gravity.CENTER);
        box.addView(pb, lp);
        root.addView(box, new LinearLayout.LayoutParams(ViewGroup.LayoutParams.MATCH_PARENT, 0, 1f));
        setContentView(root);

        WebSettings ws = web.getSettings();
        ws.setJavaScriptEnabled(true);
        ws.setDomStorageEnabled(true);
        CookieManager.getInstance().setAcceptCookie(true);
        try { CookieManager.getInstance().setAcceptThirdPartyCookies(web, true); } catch (Throwable ignored) {}
        web.setWebChromeClient(new WebChromeClient());
        web.setWebViewClient(new WebViewClient() {
            @Override
            public void onPageFinished(WebView view, String url) {
                pb.setVisibility(View.GONE);
                // পেজে আমাদের JSON দেখা গেলে → ফায়ারওয়াল পার হয়েছে
                view.evaluateJavascript("(document.body && document.body.innerText || '').trim().slice(0, 1)", v -> {
                    if (v != null && v.contains("{")) success();
                });
            }
        });
        web.loadUrl(AppConfig.BASE_URL + "/api/app_config.php?unblock=1");
    }

    private void success() {
        if (done) return;
        done = true;
        try { CookieManager.getInstance().flush(); } catch (Throwable ignored) {}
        AppConfig.clearFirewall();
        status.setText("✅ Connection is OK now. Going back…");
        Toast.makeText(this, "Connection unblocked ✓", Toast.LENGTH_LONG).show();
        SyncManager.syncAsync(this);
        web.postDelayed(this::finish, 1200);
    }

    private int dp(int v) { return Math.round(v * getResources().getDisplayMetrics().density); }

    @Override
    protected void onDestroy() {
        if (web != null) { web.stopLoading(); web.destroy(); }
        super.onDestroy();
    }
}
