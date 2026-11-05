package com.example.snzpay;

import android.app.Activity;
import android.app.Dialog;
import android.content.Context;
import android.graphics.Color;
import android.graphics.Typeface;
import android.graphics.drawable.ColorDrawable;
import android.graphics.drawable.GradientDrawable;
import android.view.Gravity;
import android.view.View;
import android.view.ViewGroup;
import android.view.Window;
import android.view.WindowManager;
import android.widget.LinearLayout;
import android.widget.TextView;

/** সাধারণ স্টাইল + সুন্দর পপআপ (কোনো ছবি / blur / চলমান animation নেই — হালকা) */
public final class Ui {
    private Ui() {}

    // পেমেন্ট অ্যাপের মত পরিষ্কার হালকা রঙ: সাদা কার্ড, গাঢ় নীল মূল রঙ, অবস্থা বোঝাতে সবুজ/কমলা/লাল
    public static final String BG = "#F3F6FB", CARD = "#FFFFFF", LINE = "#E2E8F0", INPUT = "#F8FAFC",
            BRAND = "#1D4ED8", ON_BRAND = "#FFFFFF", TEXT = "#0F172A",
            GREEN = "#15803D", AMBER = "#B45309", RED = "#DC2626", MUTED = "#64748B";

    public static int dp(Context c, int v) {
        return Math.round(v * c.getResources().getDisplayMetrics().density);
    }

    public static void box(View v, String fill, String stroke, int radiusDp) {
        if (v == null) return;
        GradientDrawable g = new GradientDrawable();
        g.setColor(Color.parseColor(fill));
        g.setCornerRadius(dp(v.getContext(), radiusDp));
        if (stroke != null) g.setStroke(dp(v.getContext(), 1), Color.parseColor(stroke));
        v.setBackground(g);
    }

    /** উপরের স্ট্যাটাস বার আর নিচের নেভিগেশন বারের নিচে যেন লেখা না ঢোকে */
    public static void fitSystemBars(final View v) {
        if (v == null) return;
        final int l = v.getPaddingLeft(), t = v.getPaddingTop(), r = v.getPaddingRight(), b = v.getPaddingBottom();
        v.setOnApplyWindowInsetsListener((view, in) -> {
            view.setPadding(l + in.getSystemWindowInsetLeft(), t + in.getSystemWindowInsetTop(),
                    r + in.getSystemWindowInsetRight(), b + in.getSystemWindowInsetBottom());
            return in;
        });
        v.requestApplyInsets();
    }

    /**
     * পুরো window এর জন্য: স্ট্যাটাস বার / নেভিগেশন বারের জায়গা target এর padding হিসেবে দেওয়া।
     * window নিজে (DecorView) ইনসেট নেয় — তাই Activity আর পুরো-স্ক্রিন Dialog দুই জায়গায়, সব Android এ কাজ করে।
     */
    @SuppressWarnings("deprecation")
    public static void fitWindow(Window w, final View target) {
        if (w == null || target == null) return;
        final int l = target.getPaddingLeft(), t = target.getPaddingTop(), r = target.getPaddingRight(), b = target.getPaddingBottom();
        View decor = w.getDecorView();
        decor.setOnApplyWindowInsetsListener((v, in) -> {
            target.setPadding(l + in.getSystemWindowInsetLeft(), t + in.getSystemWindowInsetTop(),
                    r + in.getSystemWindowInsetRight(), b + in.getSystemWindowInsetBottom());
            return in.consumeSystemWindowInsets();
        });
        decor.requestApplyInsets();
    }

    /** হালকা ব্যাকগ্রাউন্ডে স্ট্যাটাস বার / নেভিগেশন বারের আইকন কালো */
    @SuppressWarnings("deprecation")
    public static void lightBars(Window w) {
        if (w == null) return;
        View d = w.getDecorView();
        int f = d.getSystemUiVisibility() | View.SYSTEM_UI_FLAG_LIGHT_STATUS_BAR;
        if (android.os.Build.VERSION.SDK_INT >= 27) f |= View.SYSTEM_UI_FLAG_LIGHT_NAVIGATION_BAR;
        d.setSystemUiVisibility(f);
    }

    // ---------------- পপআপ ----------------

    private static Dialog dialog(Activity a, View content, boolean bottom) {
        Dialog d = new Dialog(a);
        d.requestWindowFeature(Window.FEATURE_NO_TITLE);
        d.setContentView(content);
        Window w = d.getWindow();
        if (w != null) {
            w.setBackgroundDrawable(new ColorDrawable(Color.TRANSPARENT));
            w.setLayout(ViewGroup.LayoutParams.MATCH_PARENT, ViewGroup.LayoutParams.WRAP_CONTENT);
            w.setGravity(bottom ? Gravity.BOTTOM : Gravity.CENTER);
            w.addFlags(WindowManager.LayoutParams.FLAG_DIM_BEHIND);
            w.setDimAmount(0.45f);
        }
        return d;
    }

    private static LinearLayout card(Activity a, boolean bottom) {
        LinearLayout c = new LinearLayout(a);
        c.setOrientation(LinearLayout.VERTICAL);
        int p = dp(a, 20);
        c.setPadding(p, p, p, bottom ? p + dp(a, 8) : p);
        GradientDrawable g = new GradientDrawable();
        g.setColor(Color.parseColor(CARD));
        g.setStroke(dp(a, 1), Color.parseColor(LINE));
        float r = dp(a, 24);
        g.setCornerRadii(bottom ? new float[]{r, r, r, r, 0, 0, 0, 0} : new float[]{r, r, r, r, r, r, r, r});
        c.setBackground(g);
        return c;
    }

    private static TextView text(Activity a, String s, int sp, String color, boolean bold) {
        TextView t = new TextView(a);
        t.setText(s);
        t.setTextSize(sp);
        t.setTextColor(Color.parseColor(color));
        if (bold) t.setTypeface(Typeface.DEFAULT_BOLD);
        return t;
    }

    private static TextView button(Activity a, String s, String fill, String stroke, String color) {
        TextView b = text(a, s, 15, color, true);
        b.setGravity(Gravity.CENTER);
        b.setMinHeight(dp(a, 50));
        box(b, fill, stroke, 14);
        return b;
    }

    /** মাঝখানে সুন্দর নিশ্চিতকরণ পপআপ */
    public static void confirm(Activity a, String iconRes, String title, String msg, String okText, String okColor, Runnable onOk) {
        LinearLayout c = card(a, false);
        c.setGravity(Gravity.CENTER_HORIZONTAL);

        int s = dp(a, 64);
        android.widget.ImageView ic = icon(a, iconRes, okColor);
        int pad = dp(a, 17);
        ic.setPadding(pad, pad, pad, pad);
        GradientDrawable circle = new GradientDrawable();
        circle.setShape(GradientDrawable.OVAL);
        circle.setColor(Color.parseColor(INPUT));
        circle.setStroke(dp(a, 2), Color.parseColor(okColor));
        ic.setBackground(circle);
        c.addView(ic, new LinearLayout.LayoutParams(s, s));

        TextView t = text(a, title, 19, TEXT, true);
        t.setGravity(Gravity.CENTER);
        LinearLayout.LayoutParams tLp = new LinearLayout.LayoutParams(-1, -2);
        tLp.topMargin = dp(a, 14);
        c.addView(t, tLp);

        TextView m = text(a, msg, 14, MUTED, false);
        m.setGravity(Gravity.CENTER);
        LinearLayout.LayoutParams mLp = new LinearLayout.LayoutParams(-1, -2);
        mLp.topMargin = dp(a, 6);
        c.addView(m, mLp);

        LinearLayout row = new LinearLayout(a);
        row.setOrientation(LinearLayout.HORIZONTAL);
        LinearLayout.LayoutParams rowLp = new LinearLayout.LayoutParams(-1, -2);
        rowLp.topMargin = dp(a, 20);
        c.addView(row, rowLp);

        final Dialog d = dialog(a, wrap(a, c, false), false);
        TextView cancel = button(a, "Cancel", INPUT, LINE, TEXT);
        TextView ok = button(a, okText, okColor, null, ON_BRAND);
        LinearLayout.LayoutParams bl = new LinearLayout.LayoutParams(0, -2, 1f);
        LinearLayout.LayoutParams br = new LinearLayout.LayoutParams(0, -2, 1f);
        br.leftMargin = dp(a, 10);
        row.addView(cancel, bl);
        row.addView(ok, br);
        cancel.setOnClickListener(v -> d.dismiss());
        ok.setOnClickListener(v -> { d.dismiss(); if (onOk != null) onOk.run(); });
        d.show();
    }

    /** নিচ থেকে ওঠা মেনু */
    public static final class Item {
        final String icon, title, sub, color;
        final Runnable action;
        public Item(String icon, String title, String sub, String color, Runnable action) {
            this.icon = icon; this.title = title; this.sub = sub; this.color = color; this.action = action;
        }
    }

    /** ভেক্টর আইকন — যেকোনো রঙে */
    public static android.widget.ImageView icon(Context c, String name, String color) {
        android.widget.ImageView iv = new android.widget.ImageView(c);
        iv.setImageDrawable(Icons.get(name, Color.parseColor(color)));
        iv.setScaleType(android.widget.ImageView.ScaleType.FIT_CENTER);
        return iv;
    }

    public static void menu(Activity a, String heading, Item... items) {
        LinearLayout c = card(a, true);
        View handle = new View(a);
        box(handle, LINE, null, 3);
        LinearLayout.LayoutParams hLp = new LinearLayout.LayoutParams(dp(a, 44), dp(a, 5));
        hLp.gravity = Gravity.CENTER_HORIZONTAL;
        hLp.bottomMargin = dp(a, 14);
        c.addView(handle, hLp);
        c.addView(text(a, heading, 13, MUTED, true));

        final Dialog d = dialog(a, c, true);
        for (final Item it : items) {
            LinearLayout row = new LinearLayout(a);
            row.setOrientation(LinearLayout.HORIZONTAL);
            row.setGravity(Gravity.CENTER_VERTICAL);
            int p = dp(a, 14);
            row.setPadding(p, p, p, p);
            box(row, INPUT, LINE, 16);
            LinearLayout.LayoutParams rLp = new LinearLayout.LayoutParams(-1, -2);
            rLp.topMargin = dp(a, 10);

            // রঙিন হালকা বৃত্তের ভেতর আইকন
            android.widget.ImageView ic = icon(a, it.icon, it.color);
            int ip = dp(a, 9);
            ic.setPadding(ip, ip, ip, ip);
            GradientDrawable bg = new GradientDrawable();
            bg.setShape(GradientDrawable.OVAL);
            bg.setColor(Color.parseColor(it.color));
            bg.setAlpha(30);
            ic.setBackground(bg);
            row.addView(ic, new LinearLayout.LayoutParams(dp(a, 40), dp(a, 40)));

            LinearLayout col = new LinearLayout(a);
            col.setOrientation(LinearLayout.VERTICAL);
            LinearLayout.LayoutParams cLp = new LinearLayout.LayoutParams(0, -2, 1f);
            cLp.leftMargin = dp(a, 12);
            col.addView(text(a, it.title, 15, it.color, true));
            if (it.sub != null && !it.sub.isEmpty()) col.addView(text(a, it.sub, 12, MUTED, false));
            row.addView(col, cLp);

            if (it.action != null) {
                row.addView(text(a, "›", 24, MUTED, false));
                row.setOnClickListener(v -> { d.dismiss(); it.action.run(); });
            }
            c.addView(row, rLp);
        }
        d.show();
    }

    private static View wrap(Activity a, View card, boolean bottom) {
        LinearLayout f = new LinearLayout(a);
        int p = dp(a, 22);
        f.setPadding(p, 0, p, 0);
        f.addView(card, new LinearLayout.LayoutParams(-1, -2));
        return f;
    }
}
