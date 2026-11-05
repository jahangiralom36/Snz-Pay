package com.example.snzpay;

import android.content.ContentValues;
import android.content.Context;
import android.database.Cursor;
import android.database.sqlite.SQLiteDatabase;
import android.database.sqlite.SQLiteOpenHelper;

import java.util.ArrayList;
import java.util.Calendar;
import java.util.List;

/** ফোনের ভেতরের ছোট ডাটাবেজ — নেট না থাকলে SMS এখানে জমা থাকে */
public final class Db extends SQLiteOpenHelper {

    public static final int PENDING = 0, SENT = 1, REJECTED = 2;

    public static final class Row {
        public long id, time;
        public String method, trx, sender, body, note;
        public double amount;
        public int status;
    }

    private static Db inst;

    public static synchronized Db get(Context c) {
        if (inst == null) inst = new Db(c.getApplicationContext());
        return inst;
    }

    private Db(Context c) {
        super(c, "snz_app.db", null, 1);
    }

    @Override
    public void onCreate(SQLiteDatabase db) {
        db.execSQL("CREATE TABLE sms (id INTEGER PRIMARY KEY AUTOINCREMENT, method TEXT, trx TEXT UNIQUE, amount REAL, "
                + "sender TEXT, body TEXT, time INTEGER, status INTEGER DEFAULT 0, note TEXT)");
        db.execSQL("CREATE INDEX idx_sms_status ON sms(status)");
        db.execSQL("CREATE INDEX idx_sms_time ON sms(time)");
    }

    @Override
    public void onUpgrade(SQLiteDatabase db, int o, int n) {}

    /** নতুন হলে true (একই TrxID দুইবার সেভ হয় না) */
    public synchronized boolean insert(String method, String trx, double amount, String sender, String body, long time) {
        ContentValues v = new ContentValues();
        v.put("method", method);
        v.put("trx", trx);
        v.put("amount", amount);
        v.put("sender", sender);
        v.put("body", body);
        v.put("time", time);
        v.put("status", PENDING);
        return getWritableDatabase().insertWithOnConflict("sms", null, v, SQLiteDatabase.CONFLICT_IGNORE) > 0;
    }

    public synchronized void setStatus(long id, int status, String note) {
        ContentValues v = new ContentValues();
        v.put("status", status);
        v.put("note", note);
        getWritableDatabase().update("sms", v, "id=?", new String[]{String.valueOf(id)});
    }

    public synchronized List<Row> pending(int limit) {
        return query("status=" + PENDING, "id ASC", limit);
    }

    public synchronized List<Row> recent(int limit) {
        return query(null, "time DESC", limit);
    }

    public synchronized List<Row> today(int limit) {
        return query("time>=" + todayStart(), "time DESC", limit);
    }

    /** status = -1 হলে সব */
    public synchronized List<Row> history(int status, int limit) {
        return query(status < 0 ? null : "status=" + status, "time DESC", limit);
    }

    public synchronized int totalCount() {
        return count("1=1");
    }

    public synchronized int pendingCount() {
        return count("status=" + PENDING);
    }

    public synchronized int todayCount() {
        return count("time>=" + todayStart());
    }

    public synchronized double todayAmount() {
        try (Cursor c = getReadableDatabase().rawQuery("SELECT COALESCE(SUM(amount),0) FROM sms WHERE status<>" + REJECTED + " AND time>=" + todayStart(), null)) {
            return c.moveToFirst() ? c.getDouble(0) : 0;
        }
    }

    private int count(String where) {
        try (Cursor c = getReadableDatabase().rawQuery("SELECT COUNT(*) FROM sms WHERE " + where, null)) {
            return c.moveToFirst() ? c.getInt(0) : 0;
        }
    }

    private List<Row> query(String where, String order, int limit) {
        List<Row> out = new ArrayList<>();
        try (Cursor c = getReadableDatabase().query("sms", null, where, null, null, null, order, String.valueOf(limit))) {
            while (c.moveToNext()) {
                Row r = new Row();
                r.id = c.getLong(c.getColumnIndexOrThrow("id"));
                r.method = c.getString(c.getColumnIndexOrThrow("method"));
                r.trx = c.getString(c.getColumnIndexOrThrow("trx"));
                r.amount = c.getDouble(c.getColumnIndexOrThrow("amount"));
                r.sender = c.getString(c.getColumnIndexOrThrow("sender"));
                r.body = c.getString(c.getColumnIndexOrThrow("body"));
                r.time = c.getLong(c.getColumnIndexOrThrow("time"));
                r.status = c.getInt(c.getColumnIndexOrThrow("status"));
                r.note = c.getString(c.getColumnIndexOrThrow("note"));
                out.add(r);
            }
        }
        return out;
    }

    private static long todayStart() {
        Calendar c = Calendar.getInstance();
        c.set(Calendar.HOUR_OF_DAY, 0);
        c.set(Calendar.MINUTE, 0);
        c.set(Calendar.SECOND, 0);
        c.set(Calendar.MILLISECOND, 0);
        return c.getTimeInMillis();
    }
}
