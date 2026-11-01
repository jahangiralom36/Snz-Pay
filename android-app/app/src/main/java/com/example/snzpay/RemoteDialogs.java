package com.example.snzpay;

import android.app.Activity;
import android.app.AlertDialog;
import android.content.Intent;
import android.net.Uri;
import android.widget.Button;
import android.widget.Toast;

import org.json.JSONObject;

import java.lang.ref.WeakReference;

/**
 * ওয়েবসাইট থেকে নিয়ন্ত্রিত পপআপ:
 *  - অ্যাপ OFF (মেইনটেন্যান্স)
 *  - নতুন ভার্সন এসেছে → আপডেট (বাধ্যতামূলক বা ঐচ্ছিক)
 */
public final class RemoteDialogs {

    private static WeakReference<AlertDialog> current;
    private static int optionalShownFor = -1;   // ঐচ্ছিক আপডেট প্রতিবার না দেখিয়ে একবার

    private RemoteDialogs() {}

    /** @return true হলে অ্যাপ ব্যবহার করা যাবে না (OFF বা বাধ্যতামূলক আপডেট) */
    public static boolean check(Activity a) {
        if (a == null || a.isFinishing()) return false;
        JSONObject cfg = AppConfig.get(a);

        if (!cfg.optBoolean("app_enabled", true)) {
            String msg = cfg.optString("disabled_message", "");
            if (msg.isEmpty()) msg = "Service is temporarily unavailable. Please try again later.";
            show(a, new AlertDialog.Builder(a)
                    .setTitle("Service Paused")
                    .setMessage(msg + "\n\nYour payment SMS are safely saved on this phone and will be sent automatically.")
                    .setCancelable(false)
                    .setPositiveButton("CLOSE APP", (d, w) -> a.finishAffinity()), null);
            return true;
        }

        int latest = cfg.optInt("latest_version_code", 0);
        int mine = AppConfig.currentVersionCode(a);
        if (latest > 0 && latest > mine) {
            final boolean force = cfg.optBoolean("force_update", false);
            if (!force && optionalShownFor == latest) return false;
            optionalShownFor = latest;

            final String url = cfg.optString("apk_url", AppConfig.BASE_URL + "/app-release.apk");
            String name = cfg.optString("latest_version_name", "");
            String msg = cfg.optString("update_message", "");
            if (msg.isEmpty()) msg = "A new version of the app is available. Please update now.";

            AlertDialog.Builder b = new AlertDialog.Builder(a)
                    .setTitle(name.isEmpty() ? "Update Available" : "Update Available (v" + name + ")")
                    .setMessage(msg)
                    .setCancelable(!force)
                    .setPositiveButton("UPDATE NOW", null);
            if (!force) b.setNegativeButton("LATER", null);

            show(a, b, dialog -> {
                Button btn = dialog.getButton(AlertDialog.BUTTON_POSITIVE);
                if (btn != null) btn.setOnClickListener(v -> {
                    try {
                        a.startActivity(new Intent(Intent.ACTION_VIEW, Uri.parse(url)));
                    } catch (Exception e) {
                        Toast.makeText(a, "Open this link in browser: " + url, Toast.LENGTH_LONG).show();
                    }
                    if (!force) dialog.dismiss();   // বাধ্যতামূলক হলে ডায়ালগ থেকেই যাবে
                });
            });
            return force;
        }

        dismissCurrent();
        return false;
    }

    private interface OnShown { void run(AlertDialog d); }

    private static void show(Activity a, AlertDialog.Builder b, OnShown onShown) {
        dismissCurrent();
        try {
            AlertDialog d = b.create();
            d.show();
            current = new WeakReference<>(d);
            if (onShown != null) onShown.run(d);
        } catch (Exception ignored) {}
    }

    private static void dismissCurrent() {
        AlertDialog d = current == null ? null : current.get();
        if (d != null && d.isShowing()) {
            try { d.dismiss(); } catch (Exception ignored) {}
        }
        current = null;
    }
}
