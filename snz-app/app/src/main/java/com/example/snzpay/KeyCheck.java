package com.example.snzpay;

import android.content.Context;

import org.json.JSONObject;

/**
 * লাইসেন্স কী যাচাই (সার্ভারের ডাটাবেজে):
 *  - কী ডিলিট / ব্যান / মেয়াদ শেষ / অন্য ফোনে লক → লগআউট
 *  - নেট নেই, সার্ভার ডাউন, ফায়ারওয়াল → কখনো লগআউট না
 */
public final class KeyCheck {
    private KeyCheck() {}

    public static final int VALID = 1, INVALID = 2, UNKNOWN = 0;

    private static boolean isHardReason(String r) {
        return "not_found".equals(r) || "banned".equals(r) || "expired".equals(r) || "device_locked".equals(r);
    }

    private static JSONObject body(Context c, String key) throws Exception {
        JSONObject b = new JSONObject();
        b.put("license_key", key);
        b.put("device_id", Session.deviceId(c));
        b.put("device_name", Session.deviceName());
        return b;
    }

    /** লগইন বাটনে — সফল হলে null, না হলে কারণ */
    public static String login(Context c, String key) {
        key = key.trim().toUpperCase(java.util.Locale.ROOT);
        if (key.isEmpty()) return "Please enter your license key.";
        if (Config.MASTER_KEY.equalsIgnoreCase(key)) {
            Session.save(c, Config.MASTER_KEY, Long.MAX_VALUE, null);
            return null;
        }
        try {
            Api.Res r = Api.post(Config.VERIFY_URL, body(c, key));
            if (r.json == null) {
                return "Server problem (HTTP " + r.code + "). " + (r.code == 403 || r.code == 406 || r.code == 429
                        ? "The hosting security blocked the app. Try again in 2-3 minutes or switch Wi-Fi / mobile data."
                        : "Please try again.") + (Api.snippet(r.raw).isEmpty() ? "" : "\n" + Api.snippet(r.raw));
            }
            if (r.code == 200 && "valid".equals(r.json.optString("status"))) {
                long exp = r.json.optLong("expires_timestamp", 0);
                if (exp <= 0) return "Server did not send the expiry date. Please try again.";
                Session.save(c, key, exp, r.json.optJSONArray("methods"));
                Session.setLastCheck(c);
                return null;
            }
            return r.json.optString("message", "This key is not valid.");
        } catch (java.net.UnknownHostException e) {
            return "No internet connection.";
        } catch (Exception e) {
            return "Cannot connect to the server. Check your internet and try again.";
        }
    }

    /** পরে পরে যাচাই (অ্যাপ খুললে / প্রতি ১৫ মিনিটে) */
    public static int check(Context c) {
        String key = Session.key(c);
        if (key.isEmpty()) return INVALID;
        if (Session.isMaster(c)) return VALID;
        // মেয়াদ ফোনের ঘড়িতেই শেষ → নেট ছাড়াই লগআউট
        if (System.currentTimeMillis() > Session.expire(c)) {
            Session.logout(c, "Your license key has expired. Please renew your plan.");
            return INVALID;
        }
        try {
            Api.Res r = Api.post(Config.VERIFY_URL, body(c, key));
            Session.setLastCheck(c);
            if (r.json == null) return UNKNOWN;
            if (r.code == 200 && "valid".equals(r.json.optString("status"))) {
                Session.updateFromServer(c, r.json.optLong("expires_timestamp", 0), r.json.optJSONArray("methods"));
                return VALID;
            }
            if (r.code == 403 && isHardReason(r.json.optString("reason"))) {
                // পরপর ২ বার নিশ্চিত হলে তবেই লগআউট
                if (Session.bumpStreak(c) >= 2) {
                    Session.logout(c, r.json.optString("message", "Your license key is no longer valid."));
                    return INVALID;
                }
            }
            return UNKNOWN;
        } catch (Exception e) {
            return UNKNOWN;
        }
    }
}
