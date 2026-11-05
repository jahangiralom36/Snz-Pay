package com.example.snzpay;

import android.app.Activity;
import android.content.ClipData;
import android.content.ClipboardManager;
import android.content.Context;
import android.widget.Toast;

import java.io.PrintWriter;
import java.io.StringWriter;

/**
 * অ্যাপ হঠাৎ বন্ধ হলে কারণটা SQLite এ লিখে রাখে, পরেরবার খুললে দেখায় (Copy করে ডেভেলপারকে পাঠানো যায়)।
 */
public final class CrashLog {
    private CrashLog() {}

    private static volatile boolean installed = false;

    public static void install(Context ctx) {
        if (installed) return;
        installed = true;
        final Context app = ctx.getApplicationContext();
        final Thread.UncaughtExceptionHandler prev = Thread.getDefaultUncaughtExceptionHandler();
        Thread.setDefaultUncaughtExceptionHandler((t, e) -> {
            try {
                StringWriter sw = new StringWriter();
                e.printStackTrace(new PrintWriter(sw));
                String s = sw.toString();
                if (s.length() > 2500) s = s.substring(0, 2500);
                Db.get(app).putStr("last_crash", "App " + BuildInfo.version(app) + " • Android " + android.os.Build.VERSION.RELEASE
                        + " • " + Session.deviceName() + "\n\n" + s);
            } catch (Throwable ignored) {}
            if (prev != null) prev.uncaughtException(t, e);
        });
    }

    public static void showIfAny(final Activity a) {
        final String s;
        try {
            s = Db.get(a).getStr("last_crash", "");
            if (s.isEmpty()) return;
            Db.get(a).remove("last_crash");
        } catch (Throwable e) {
            return;
        }
        Ui.confirm(a, "⚠️", "The app closed unexpectedly",
                "Tap COPY and send it to the developer so it can be fixed.\n\n" + (s.length() > 300 ? s.substring(0, 300) + "…" : s),
                "Copy", Ui.AMBER, () -> {
                    try {
                        ClipboardManager cm = (ClipboardManager) a.getSystemService(Context.CLIPBOARD_SERVICE);
                        cm.setPrimaryClip(ClipData.newPlainText("SNZ crash", s));
                        Toast.makeText(a, "Copied ✓", Toast.LENGTH_SHORT).show();
                    } catch (Throwable ignored) {}
                });
    }

    /** ভার্সনের নাম (crash রিপোর্টে) */
    static final class BuildInfo {
        static String version(Context c) {
            try {
                return c.getPackageManager().getPackageInfo(c.getPackageName(), 0).versionName;
            } catch (Throwable e) {
                return "?";
            }
        }
    }
}
