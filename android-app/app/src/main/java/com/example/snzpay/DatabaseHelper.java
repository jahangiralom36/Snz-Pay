package com.example.snzpay;

import android.content.ContentValues;
import android.content.Context;
import android.database.Cursor;
import android.database.sqlite.SQLiteDatabase;
import android.database.sqlite.SQLiteOpenHelper;

import java.text.SimpleDateFormat;
import java.util.ArrayList;
import java.util.Date;
import java.util.List;
import java.util.Locale;

/**
 * ফোনের ভেতরের ডাটাবেজ — প্রতিটা পেমেন্ট SMS আগে এখানে জমা হয়,
 * তারপর সার্ভারে পাঠানো হয়। নেট না থাকলে এখানেই থাকে, নেট এলে পাঠানো হয়।
 *
 * status: "Pending"  = এখনো সার্ভারে যায়নি
 *         "Synced"   = সার্ভারে পৌঁছেছে
 *         "Rejected" = সার্ভার নিতে পারেনি (ভুল TrxID/Amount) — আর পাঠানো হবে না
 */
public class DatabaseHelper extends SQLiteOpenHelper {

    private static final String DATABASE_NAME = "snz_pay.db";
    private static final int DATABASE_VERSION = 3;

    public static final String ST_PENDING = "Pending";
    public static final String ST_SYNCED = "Synced";
    public static final String ST_REJECTED = "Rejected";

    private static DatabaseHelper instance;

    /** সব জায়গায় একটাই কানেকশন (একসাথে SMS আর সিঙ্ক চললেও লক সমস্যা হবে না) */
    public static synchronized DatabaseHelper get(Context ctx) {
        if (instance == null) instance = new DatabaseHelper(ctx.getApplicationContext());
        return instance;
    }

    public DatabaseHelper(Context context) {
        super(context, DATABASE_NAME, null, DATABASE_VERSION);
    }

    @Override
    public void onCreate(SQLiteDatabase db) {
        db.execSQL("CREATE TABLE transactions (" +
                "id INTEGER PRIMARY KEY AUTOINCREMENT," +
                "method TEXT," +
                "trx_id TEXT," +
                "amount REAL," +
                "sender TEXT," +
                "status TEXT," +
                "raw_sms TEXT," +
                "date TEXT," +
                "origin TEXT," +
                "sms_time INTEGER DEFAULT 0," +
                "attempts INTEGER DEFAULT 0," +
                "last_error TEXT)");
        db.execSQL("CREATE INDEX IF NOT EXISTS idx_tx_trx ON transactions(trx_id)");
        db.execSQL("CREATE INDEX IF NOT EXISTS idx_tx_status ON transactions(status)");
    }

    @Override
    public void onUpgrade(SQLiteDatabase db, int oldVersion, int newVersion) {
        // আগে এখানে পুরো টেবিল ডিলিট হত — এখন পুরনো ডাটা রেখে নতুন কলাম যোগ হয়
        if (oldVersion < 2) {
            safeExec(db, "ALTER TABLE transactions ADD COLUMN origin TEXT");
            safeExec(db, "ALTER TABLE transactions ADD COLUMN sms_time INTEGER DEFAULT 0");
            safeExec(db, "ALTER TABLE transactions ADD COLUMN attempts INTEGER DEFAULT 0");
            safeExec(db, "ALTER TABLE transactions ADD COLUMN last_error TEXT");
            safeExec(db, "CREATE INDEX IF NOT EXISTS idx_tx_trx ON transactions(trx_id)");
            safeExec(db, "CREATE INDEX IF NOT EXISTS idx_tx_status ON transactions(status)");
            // পুরনো ভার্সনে পাঠানো হয়েছে কিনা জানা নেই — খালি TrxID ছাড়া সব আবার পাঠানো হবে, সার্ভার ডুপ্লিকেট নিজেই বাদ দেয়
            safeExec(db, "UPDATE transactions SET status='" + ST_REJECTED + "' WHERE trx_id IS NULL OR trx_id=''");
            safeExec(db, "UPDATE transactions SET status='" + ST_PENDING + "' WHERE status NOT IN ('" + ST_REJECTED + "')");
        }
        if (oldVersion < 3) cleanupOldData(db);
    }

    /**
     * পুরনো অ্যাপের রেখে যাওয়া ভাঙা লাইন পরিষ্কার:
     *  - TrxID নেই বা টাকা ০ (যেমন Rocket এর "debited" SMS) → মুছে ফেলা
     *  - সময় নেই (1970 দেখাত) → তারিখ থেকে সময় বসানো
     *  - Rocket TrxID এর শেষে "Date" লেগে থাকলে (7100950328Date) → ঠিক করা
     */
    private static void cleanupOldData(SQLiteDatabase db) {
        safeExec(db, "DELETE FROM transactions WHERE trx_id IS NULL OR trx_id='' OR amount IS NULL OR amount<=0");
        safeExec(db, "UPDATE transactions SET sms_time = CAST(strftime('%s', date) AS INTEGER) * 1000 " +
                "WHERE (sms_time IS NULL OR sms_time = 0) AND date IS NOT NULL AND date != ''");
        try (Cursor c = db.rawQuery("SELECT id, trx_id FROM transactions WHERE method='ROCKET'", null)) {
            while (c.moveToNext()) {
                String trx = c.getString(1);
                java.util.regex.Matcher m = java.util.regex.Pattern.compile("^(\\d{6,})[A-Za-z]+$").matcher(trx == null ? "" : trx);
                if (m.find()) {
                    ContentValues cv = new ContentValues();
                    cv.put("trx_id", m.group(1));
                    cv.put("status", ST_PENDING);
                    db.update("transactions", cv, "id=?", new String[]{String.valueOf(c.getLong(0))});
                }
            }
        } catch (Exception ignored) {}
    }

    private static void safeExec(SQLiteDatabase db, String sql) {
        try { db.execSQL(sql); } catch (Exception ignored) {}
    }

    private static String today() {
        return new SimpleDateFormat("yyyy-MM-dd", Locale.US).format(new Date());
    }

    /** একই TrxID আগে থাকলে আবার সেভ হবে না → -1 */
    public synchronized long insertIfNew(String method, String trxId, double amount, String customer,
                                         String rawSms, String origin, long smsTime) {
        SQLiteDatabase db = getWritableDatabase();
        try (Cursor c = db.rawQuery("SELECT id FROM transactions WHERE trx_id=? LIMIT 1", new String[]{trxId})) {
            if (c.moveToFirst()) return -1;
        }
        ContentValues cv = new ContentValues();
        cv.put("method", method);
        cv.put("trx_id", trxId);
        cv.put("amount", amount);
        cv.put("sender", customer);
        cv.put("status", ST_PENDING);
        cv.put("raw_sms", rawSms);
        cv.put("origin", origin);
        cv.put("sms_time", smsTime > 0 ? smsTime : System.currentTimeMillis());
        cv.put("date", new SimpleDateFormat("yyyy-MM-dd", Locale.US).format(new Date(smsTime > 0 ? smsTime : System.currentTimeMillis())));
        return db.insert("transactions", null, cv);
    }

    public static final class Tx {
        public long id;
        public String method, trxId, customer, rawSms, origin, status, lastError, date;
        public double amount;
        public long smsTime;
    }

    /**
     * History তালিকার জন্য।
     * @param onlyToday true হলে শুধু আজকের
     * @param status    null = সব, নাহলে ST_PENDING / ST_SYNCED / ST_REJECTED
     */
    public synchronized List<Tx> getTransactions(boolean onlyToday, String status, int limit) {
        List<Tx> list = new ArrayList<>();
        StringBuilder where = new StringBuilder("1=1");
        List<String> args = new ArrayList<>();
        if (onlyToday) { where.append(" AND date=?"); args.add(today()); }
        if (status != null) { where.append(" AND status=?"); args.add(status); }
        try (Cursor c = getReadableDatabase().rawQuery(
                "SELECT id, method, trx_id, amount, sender, raw_sms, origin, sms_time, status, last_error, date " +
                "FROM transactions WHERE " + where + " ORDER BY id DESC LIMIT " + limit,
                args.toArray(new String[0]))) {
            while (c.moveToNext()) {
                Tx t = new Tx();
                t.id = c.getLong(0);
                t.method = c.getString(1);
                t.trxId = c.getString(2);
                t.amount = c.getDouble(3);
                t.customer = c.getString(4);
                t.rawSms = c.getString(5);
                t.origin = c.getString(6);
                t.smsTime = c.getLong(7);
                t.status = c.getString(8);
                t.lastError = c.getString(9);
                t.date = c.getString(10);
                list.add(t);
            }
        }
        return list;
    }

    public synchronized int getTodayCount() {
        return count("SELECT COUNT(*) FROM transactions WHERE status!=? AND date=?", new String[]{ST_REJECTED, today()});
    }

    public synchronized List<Tx> getUnsynced(int limit) {
        List<Tx> list = new ArrayList<>();
        try (Cursor c = getReadableDatabase().rawQuery(
                "SELECT id, method, trx_id, amount, sender, raw_sms, origin, sms_time FROM transactions WHERE status=? ORDER BY id ASC LIMIT " + limit,
                new String[]{ST_PENDING})) {
            while (c.moveToNext()) {
                Tx t = new Tx();
                t.id = c.getLong(0);
                t.method = c.getString(1);
                t.trxId = c.getString(2);
                t.amount = c.getDouble(3);
                t.customer = c.getString(4);
                t.rawSms = c.getString(5);
                t.origin = c.getString(6);
                t.smsTime = c.getLong(7);
                list.add(t);
            }
        }
        return list;
    }

    public synchronized void setStatus(long id, String status, String error) {
        ContentValues cv = new ContentValues();
        cv.put("status", status);
        cv.put("last_error", error);
        getWritableDatabase().update("transactions", cv, "id=?", new String[]{String.valueOf(id)});
    }

    public synchronized void markAttempt(long id, String error) {
        getWritableDatabase().execSQL("UPDATE transactions SET attempts = attempts + 1, last_error = ? WHERE id = ?",
                new Object[]{error, id});
    }

    // ---------------- Dashboard numbers ----------------
    public synchronized double getTodayDeposit() {
        return sum("SELECT SUM(amount) FROM transactions WHERE status!=? AND date=? AND method NOT LIKE '%BINANCE%'",
                new String[]{ST_REJECTED, today()});
    }

    public synchronized double getTotalDeposit() {
        return sum("SELECT SUM(amount) FROM transactions WHERE status!=? AND method NOT LIKE '%BINANCE%'",
                new String[]{ST_REJECTED});
    }

    public synchronized int getReceivedCount() {
        return count("SELECT COUNT(*) FROM transactions WHERE status!=?", new String[]{ST_REJECTED});
    }

    public synchronized int getUnsyncedCount() {
        return count("SELECT COUNT(*) FROM transactions WHERE status=?", new String[]{ST_PENDING});
    }

    /** পুরনো কোডের সাথে মিল রাখার জন্য */
    public synchronized int getCount(String status) {
        return count("SELECT COUNT(*) FROM transactions WHERE status=?", new String[]{status});
    }

    public synchronized Cursor getAllTransactions() {
        return getReadableDatabase().rawQuery("SELECT * FROM transactions ORDER BY id DESC", null);
    }

    private double sum(String sql, String[] args) {
        try (Cursor c = getReadableDatabase().rawQuery(sql, args)) {
            return c.moveToFirst() ? c.getDouble(0) : 0;
        }
    }

    private int count(String sql, String[] args) {
        try (Cursor c = getReadableDatabase().rawQuery(sql, args)) {
            return c.moveToFirst() ? c.getInt(0) : 0;
        }
    }
}
