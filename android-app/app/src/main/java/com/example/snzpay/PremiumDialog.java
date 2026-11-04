package com.example.snzpay;

import android.app.Activity;
import android.app.Dialog;
import android.graphics.Color;
import android.graphics.drawable.ColorDrawable;
import android.graphics.drawable.GradientDrawable;
import android.os.Build;
import android.text.method.ScrollingMovementMethod;
import android.view.View;
import android.view.Window;
import android.view.WindowManager;
import android.view.animation.AlphaAnimation;
import android.view.animation.AnimationSet;
import android.view.animation.DecelerateInterpolator;
import android.view.animation.ScaleAnimation;
import android.widget.TextView;

/**
 * ওয়েবসাইটের মত প্রিমিয়াম ডার্ক পপআপ — পেছনে অন্ধকার, নরম অ্যানিমেশন।
 *
 * ব্যবহার:
 *   new PremiumDialog(activity)
 *       .icon("🛠", PremiumDialog.AMBER).badge("MAINTENANCE")
 *       .title("Service Paused").message("...")
 *       .primary("CLOSE APP", d -> activity.finishAffinity())
 *       .cancelable(false).show();
 */
public class PremiumDialog {

    public static final int CYAN = Color.parseColor("#00D9FF");
    public static final int GREEN = Color.parseColor("#00D89A");
    public static final int AMBER = Color.parseColor("#FBBF24");
    public static final int RED = Color.parseColor("#F87171");
    public static final int BLUE = Color.parseColor("#3B82F6");

    public interface Action { void run(PremiumDialog d); }

    private final Activity activity;
    private final Dialog dialog;
    private final TextView tvIcon, tvBadge, tvTitle, tvMessage, tvInfo, btnPrimary, btnSecondary;
    private int accent = CYAN;
    private boolean autoDismissPrimary = true;

    public PremiumDialog(Activity a) {
        activity = a;
        dialog = new Dialog(a);
        dialog.requestWindowFeature(Window.FEATURE_NO_TITLE);
        dialog.setContentView(R.layout.dialog_premium);
        tvIcon = dialog.findViewById(R.id.tvPmIcon);
        tvBadge = dialog.findViewById(R.id.tvPmBadge);
        tvTitle = dialog.findViewById(R.id.tvPmTitle);
        tvMessage = dialog.findViewById(R.id.tvPmMessage);
        tvInfo = dialog.findViewById(R.id.tvPmInfo);
        btnPrimary = dialog.findViewById(R.id.btnPmPrimary);
        btnSecondary = dialog.findViewById(R.id.btnPmSecondary);
        tvInfo.setMovementMethod(new ScrollingMovementMethod());
        // ডিজাইন কোড থেকে (আলাদা drawable ফাইল লাগে না)
        View card = dialog.findViewById(R.id.pmCard);
        if (card != null) card.setBackground(Styles.premiumCard(a));
        tvInfo.setBackground(Styles.premiumInner(a));
        btnPrimary.setBackground(Styles.premiumButton(a));
        btnSecondary.setBackground(Styles.premiumOutline(a));
        btnPrimary.setOnClickListener(v -> dismiss());
        icon("⚡", CYAN);
    }

    public PremiumDialog icon(String emoji, int color) {
        accent = color;
        tvIcon.setText(emoji);
        GradientDrawable circle = new GradientDrawable();
        circle.setShape(GradientDrawable.OVAL);
        circle.setColor(withAlpha(color, 0x2E));
        circle.setStroke(SmsRow.dp(activity, 2), withAlpha(color, 0x99));
        tvIcon.setBackground(circle);
        return this;
    }

    public PremiumDialog badge(String text) {
        tvBadge.setText(text);
        tvBadge.setTextColor(accent);
        GradientDrawable pill = new GradientDrawable();
        pill.setCornerRadius(SmsRow.dp(activity, 20));
        pill.setColor(withAlpha(accent, 0x22));
        pill.setStroke(SmsRow.dp(activity, 1), withAlpha(accent, 0x66));
        tvBadge.setBackground(pill);
        tvBadge.setVisibility(View.VISIBLE);
        return this;
    }

    public PremiumDialog title(String t) { tvTitle.setText(t); return this; }

    public PremiumDialog message(String m) {
        tvMessage.setText(m);
        tvMessage.setVisibility(m == null || m.isEmpty() ? View.GONE : View.VISIBLE);
        return this;
    }

    /** ভেতরের ডার্ক বক্সে বিস্তারিত তথ্য (যেমন পুরো SMS) */
    public PremiumDialog info(String text) {
        tvInfo.setText(text);
        tvInfo.setVisibility(text == null || text.isEmpty() ? View.GONE : View.VISIBLE);
        return this;
    }

    public PremiumDialog primary(String text, Action action) {
        btnPrimary.setText(text);
        btnPrimary.setOnClickListener(v -> {
            if (action != null) action.run(this);
            if (autoDismissPrimary) dismiss();
        });
        return this;
    }

    /** true = প্রধান বাটন চাপলেও পপআপ থেকে যাবে (বাধ্যতামূলক আপডেটের জন্য) */
    public PremiumDialog keepOpenOnPrimary(boolean keep) { autoDismissPrimary = !keep; return this; }

    public PremiumDialog secondary(String text, Action action) {
        btnSecondary.setText(text);
        btnSecondary.setVisibility(View.VISIBLE);
        btnSecondary.setOnClickListener(v -> {
            if (action != null) action.run(this);
            dismiss();
        });
        return this;
    }

    public PremiumDialog cancelable(boolean c) {
        dialog.setCancelable(c);
        dialog.setCanceledOnTouchOutside(c);
        return this;
    }

    public boolean isShowing() { return dialog.isShowing(); }

    public void dismiss() {
        try { if (dialog.isShowing()) dialog.dismiss(); } catch (Exception ignored) {}
    }

    public PremiumDialog show() {
        if (activity.isFinishing()) return this;
        try {
            dialog.show();
            styleWindow(activity, dialog, 0.88f);
            animateIn(dialog.findViewById(R.id.pmCard));
        } catch (Exception ignored) {}
        return this;
    }

    /** যেকোনো Dialog কে প্রিমিয়াম স্টাইল দেয়: স্বচ্ছ ব্যাকগ্রাউন্ড + ডার্ক dim (হালকা, blur ছাড়া) */
    public static void styleWindow(Activity a, Dialog d, float widthRatio) {
        Window w = d.getWindow();
        if (w == null) return;
        w.setBackgroundDrawable(new ColorDrawable(Color.TRANSPARENT));
        int width = (int) (a.getResources().getDisplayMetrics().widthPixels * widthRatio);
        w.setLayout(Math.min(width, SmsRow.dp(a, 420)), WindowManager.LayoutParams.WRAP_CONTENT);
        // শুধু অন্ধকার পর্দা — blur নেই (blur ফোনের System UI কে ভারী করে, কম দামি ফোন আটকে যায়)
        w.addFlags(WindowManager.LayoutParams.FLAG_DIM_BEHIND);
        w.setDimAmount(0.75f);
    }

    public static void animateIn(View card) {
        if (card == null) return;
        AnimationSet set = new AnimationSet(true);
        ScaleAnimation scale = new ScaleAnimation(0.86f, 1f, 0.86f, 1f,
                ScaleAnimation.RELATIVE_TO_SELF, 0.5f, ScaleAnimation.RELATIVE_TO_SELF, 0.5f);
        AlphaAnimation fade = new AlphaAnimation(0f, 1f);
        set.addAnimation(scale);
        set.addAnimation(fade);
        set.setDuration(260);
        set.setInterpolator(new DecelerateInterpolator());
        card.startAnimation(set);
    }

    static int withAlpha(int color, int alpha) {
        return (color & 0x00FFFFFF) | (alpha << 24);
    }
}
