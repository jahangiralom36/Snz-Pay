package com.example.snzpay;

import android.app.Activity;
import android.content.Intent;
import android.net.Uri;
import android.widget.Toast;

import org.json.JSONObject;

import java.lang.ref.WeakReference;

/**
 * ওয়েবসাইট থেকে নিয়ন্ত্রিত প্রিমিয়াম পপআপ:
 *  - অ্যাপ OFF (মেইনটেন্যান্স)
 *  - নতুন ভার্সন এসেছে → আপডেট (বাধ্যতামূলক বা ঐচ্ছিক)
 */
public final class RemoteDialogs {

    private static WeakReference<PremiumDialog> current;
    private static String currentKind = "";
    private static int optionalShownFor = -1;   // ঐচ্ছিক আপডেট প্রতিবার না দেখিয়ে একবার

    private RemoteDialogs() {}

    /** @return true হলে অ্যাপ ব্যবহার করা যাবে না (OFF বা বাধ্যতামূলক আপডেট) */
    public static boolean check(Activity a) {
        if (a == null || a.isFinishing()) return false;
        JSONObject cfg = AppConfig.get(a);

        // ---------------- মেইনটেন্যান্স ----------------
        if (!cfg.optBoolean("app_enabled", true)) {
            if (isShowing("off")) return true;
            String msg = cfg.optString("disabled_message", "");
            if (msg.isEmpty()) msg = "Service is temporarily unavailable. Please try again later.";
            show("off", new PremiumDialog(a)
                    .icon("🛠", PremiumDialog.AMBER)
                    .badge("UNDER MAINTENANCE")
                    .title("We'll be back soon")
                    .message(msg)
                    .info("✓  Your payment SMS are safely saved on this phone\n✓  They will be sent automatically when service is back")
                    .primary("CLOSE APP", d -> a.finishAffinity())
                    .secondary("TRY AGAIN", d -> AppConfig.fetchAsync(a, () -> check(a)))
                    .cancelable(false));
            return true;
        }

        // ---------------- আপডেট ----------------
        int latest = cfg.optInt("latest_version_code", 0);
        int mine = AppConfig.currentVersionCode(a);
        if (latest > 0 && latest > mine) {
            final boolean force = cfg.optBoolean("force_update", false);
            if (isShowing("update")) return force;
            if (!force && optionalShownFor == latest) return false;
            optionalShownFor = latest;

            final String url = cfg.optString("apk_url", "").isEmpty()
                    ? AppConfig.BASE_URL + "/app-release.apk" : cfg.optString("apk_url");
            String name = cfg.optString("latest_version_name", "");
            String msg = cfg.optString("update_message", "");
            if (msg.isEmpty()) msg = "A new version of the app is available with improvements and fixes.";

            PremiumDialog d = new PremiumDialog(a)
                    .icon("🚀", PremiumDialog.CYAN)
                    .badge(force ? "REQUIRED UPDATE" : "NEW UPDATE")
                    .title(name.isEmpty() ? "Update Available" : "Version " + name + " is here")
                    .message(msg)
                    .primary("UPDATE NOW", x -> {
                        try {
                            a.startActivity(new Intent(Intent.ACTION_VIEW, Uri.parse(url)));
                        } catch (Exception e) {
                            Toast.makeText(a, "Open this link in browser: " + url, Toast.LENGTH_LONG).show();
                        }
                    })
                    .keepOpenOnPrimary(force)       // বাধ্যতামূলক হলে পপআপ থেকে যাবে
                    .cancelable(!force);
            if (!force) d.secondary("LATER", null);
            show("update", d);
            return force;
        }

        dismissCurrent();
        return false;
    }

    private static boolean isShowing(String kind) {
        PremiumDialog d = current == null ? null : current.get();
        return d != null && d.isShowing() && kind.equals(currentKind);
    }

    private static void show(String kind, PremiumDialog d) {
        dismissCurrent();
        d.show();
        current = new WeakReference<>(d);
        currentKind = kind;
    }

    private static void dismissCurrent() {
        PremiumDialog d = current == null ? null : current.get();
        if (d != null) d.dismiss();
        current = null;
        currentKind = "";
    }
}
