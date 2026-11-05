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

    public static final String BG = "#061535", CARD = "#0B2559", LINE = "#1E4AA3", INPUT = "#051433",
            BRAND = "#00D9FF", GREEN = "#00D89A", AMBER = "#FBBF24", RED = "#F87171", MUTED = "#94A3B8";

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
            w.setDimAmount(0.6f);
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
    public static void confirm(Activity a, String icon, String title, String msg, String okText, String okColor, Runnable onOk) {
        LinearLayout c = card(a, false);
        c.setGravity(Gravity.CENTER_HORIZONTAL);

        TextView ic = text(a, icon, 30, okColor, false);
        ic.setGravity(Gravity.CENTER);
        int s = dp(a, 64);
        LinearLayout.LayoutParams icLp = new LinearLayout.LayoutParams(s, s);
        GradientDrawable circle = new GradientDrawable();
        circle.setShape(GradientDrawable.OVAL);
        circle.setColor(Color.parseColor(INPUT));
        circle.setStroke(dp(a, 2), Color.parseColor(okColor));
        ic.setBackground(circle);
        c.addView(ic, icLp);

        TextView t = text(a, title, 19, "#FFFFFF", true);
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
        TextView cancel = button(a, "Cancel", INPUT, LINE, "#FFFFFF");
        TextView ok = button(a, okText, okColor, null, BG);
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

            TextView ic = text(a, it.icon, 20, it.color, false);
            ic.setGravity(Gravity.CENTER);
            row.addView(ic, new LinearLayout.LayoutParams(dp(a, 36), dp(a, 36)));

            LinearLayout col = new LinearLayout(a);
            col.setOrientation(LinearLayout.VERTICAL);
            LinearLayout.LayoutParams cLp = new LinearLayout.LayoutParams(0, -2, 1f);
            cLp.leftMargin = dp(a, 12);
            col.addView(text(a, it.title, 15, it.color, true));
            if (it.sub != null && !it.sub.isEmpty()) col.addView(text(a, it.sub, 12, MUTED, false));
            row.addView(col, cLp);

            if (it.action != null) {
                row.addView(text(a, "›", 22, MUTED, false));
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
