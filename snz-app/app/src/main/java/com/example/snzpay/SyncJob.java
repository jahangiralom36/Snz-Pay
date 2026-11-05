package com.example.snzpay;

import android.app.job.JobInfo;
import android.app.job.JobParameters;
import android.app.job.JobScheduler;
import android.app.job.JobService;
import android.content.ComponentName;
import android.content.Context;

/**
 * Android এর নিজস্ব JobScheduler (ব্যাটারি-বান্ধব, কোনো নোটিফিকেশন নেই):
 *  - প্রতি ১৫ মিনিটে: কী যাচাই + মিস হওয়া SMS খোঁজা + জমে থাকা SMS পাঠানো
 *  - নেট বন্ধ থাকলে: নেট আসলেই জমে থাকা SMS পাঠানো
 */
public class SyncJob extends JobService {

    private static final int PERIODIC = 9101;
    private static final int ONLINE = 9102;

    public static void schedulePeriodic(Context c) {
        try {
            JobScheduler js = c.getSystemService(JobScheduler.class);
            if (js == null || js.getPendingJob(PERIODIC) != null) return;
            js.schedule(new JobInfo.Builder(PERIODIC, new ComponentName(c, SyncJob.class))
                    .setPeriodic(15 * 60 * 1000L)
                    .setRequiredNetworkType(JobInfo.NETWORK_TYPE_ANY)
                    .setPersisted(true)
                    .build());
        } catch (Exception ignored) {}
    }

    public static void scheduleOnline(Context c) {
        try {
            JobScheduler js = c.getSystemService(JobScheduler.class);
            if (js == null || js.getPendingJob(ONLINE) != null) return;
            js.schedule(new JobInfo.Builder(ONLINE, new ComponentName(c, SyncJob.class))
                    .setRequiredNetworkType(JobInfo.NETWORK_TYPE_ANY)
                    .setMinimumLatency(15000)
                    .setBackoffCriteria(60000, JobInfo.BACKOFF_POLICY_EXPONENTIAL)
                    .setPersisted(true)
                    .build());
        } catch (Exception ignored) {}
    }

    public static void cancelAll(Context c) {
        try {
            JobScheduler js = c.getSystemService(JobScheduler.class);
            if (js != null) { js.cancel(PERIODIC); js.cancel(ONLINE); }
        } catch (Exception ignored) {}
    }

    @Override
    public boolean onStartJob(final JobParameters params) {
        final Context app = getApplicationContext();
        if (!Session.loggedIn(app)) return false;
        new Thread(() -> {
            int left = 0;
            try {
                if (params.getJobId() == PERIODIC && KeyCheck.check(app) == KeyCheck.INVALID) {
                    jobFinished(params, false);
                    return;
                }
                Sync.scanInbox(app);
                left = Sync.upload(app);
            } catch (Exception ignored) {
            }
            jobFinished(params, params.getJobId() == ONLINE && left > 0);
        }).start();
        return true;
    }

    @Override
    public boolean onStopJob(JobParameters params) {
        return true;
    }
}
