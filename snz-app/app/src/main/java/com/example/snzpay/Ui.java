package com.example.snzpay;

import android.content.Context;
import android.graphics.Color;
import android.graphics.drawable.GradientDrawable;
import android.view.View;

/** সাধারণ গোল কোণার ব্যাকগ্রাউন্ড (কোনো ছবি / blur / animation নেই — হালকা) */
public final class Ui {
    private Ui() {}

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

    public static final String BG = "#061535", CARD = "#0B2559", LINE = "#1E4AA3", INPUT = "#051433",
            BRAND = "#00D9FF", GREEN = "#00D89A", AMBER = "#FBBF24", RED = "#F87171", MUTED = "#94A3B8";
}
