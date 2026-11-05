package com.example.snzpay;

/** সব সেটিং এক জায়গায় */
public final class Config {
    private Config() {}

    public static final String BASE_URL = "https://snzpay.digital";
    public static final String VERIFY_URL = BASE_URL + "/api/verify_key.php";
    public static final String GATEWAY_URL = BASE_URL + "/api/app_gateway.php";

    /** config.php এর APP_SECRET_TOKEN এর সাথে মিলতে হবে */
    public static final String SECRET_TOKEN = "CHANGE_ME_APP_TOKEN";
    /** অ্যাডমিনের নিজের ফোনের জন্য মাস্টার কী (সব SMS পড়ে, মেয়াদ নেই) */
    public static final String MASTER_KEY = "CHANGE_ME_MASTER_KEY";
}
