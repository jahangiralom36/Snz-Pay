package com.example.snzpay;

import android.content.Context;
import android.graphics.Color;
import android.graphics.drawable.Drawable;
import android.graphics.drawable.GradientDrawable;
import android.graphics.drawable.StateListDrawable;

/**
 * প্রিমিয়াম ডিজাইনের সব ব্যাকগ্রাউন্ড কোড থেকে তৈরি হয় —
 * আলাদা res/drawable ফাইল লাগে না (তাই "resource not found" build error হবে না)।
 */
public final class Styles {

    private Styles() {}

    /** ডার্ক নীল প্রিমিয়াম কার্ড + সায়ান বর্ডার (পপআপের মূল কার্ড) */
    public static Drawable premiumCard(Context c) {
        GradientDrawable g = new GradientDrawable(GradientDrawable.Orientation.TOP_BOTTOM,
                new int[]{Color.parseColor("#0B2559"), Color.parseColor("#05112B")});
        g.setCornerRadius(SmsRow.dp(c, 28));
        g.setStroke(Math.max(1, SmsRow.dp(c, 1) + SmsRow.dp(c, 1) / 2), Color.parseColor("#6600D9FF"));
        return g;
    }

    /** পপআপের ভেতরের তথ্য বক্স */
    public static Drawable premiumInner(Context c) {
        GradientDrawable g = new GradientDrawable();
        g.setColor(Color.parseColor("#051433"));
        g.setCornerRadius(SmsRow.dp(c, 16));
        g.setStroke(SmsRow.dp(c, 1), Color.parseColor("#0D2A63"));
        return g;
    }

    /** ওয়েবসাইটের গ্রেডিয়েন্ট বাটন (নীল → সায়ান → সবুজ) */
    public static Drawable premiumButton(Context c) {
        GradientDrawable normal = new GradientDrawable(GradientDrawable.Orientation.LEFT_RIGHT,
                new int[]{Color.parseColor("#007BFF"), Color.parseColor("#00D9FF"), Color.parseColor("#00D89A")});
        normal.setCornerRadius(SmsRow.dp(c, 16));
        GradientDrawable pressed = new GradientDrawable(GradientDrawable.Orientation.LEFT_RIGHT,
                new int[]{Color.parseColor("#0062CC"), Color.parseColor("#00B8D9"), Color.parseColor("#00B583")});
        pressed.setCornerRadius(SmsRow.dp(c, 16));
        return states(pressed, normal);
    }

    /** গাঢ় বর্ডার বাটন (Close / Later ইত্যাদি) */
    public static Drawable premiumOutline(Context c) {
        return states(box(c, "#14306B", "#1E4AA3", 16), box(c, "#0A1F4D", "#1E4AA3", 16));
    }

    /** History এর প্রতিটা লাইনের হালকা কার্ড */
    public static Drawable smsRow(Context c) {
        return states(box(c, "#E0F2FE", "#7DD3FC", 16), box(c, "#F8FAFC", "#E2E8F0", 16));
    }

    private static GradientDrawable box(Context c, String fill, String stroke, int radiusDp) {
        GradientDrawable g = new GradientDrawable();
        g.setColor(Color.parseColor(fill));
        g.setCornerRadius(SmsRow.dp(c, radiusDp));
        g.setStroke(SmsRow.dp(c, 1), Color.parseColor(stroke));
        return g;
    }

    private static Drawable states(Drawable pressed, Drawable normal) {
        StateListDrawable s = new StateListDrawable();
        s.addState(new int[]{android.R.attr.state_pressed}, pressed);
        s.addState(new int[]{}, normal);
        return s;
    }
}
