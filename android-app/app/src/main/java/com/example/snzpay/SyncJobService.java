package com.example.snzpay;

import android.app.job.JobInfo;
import android.app.job.JobParameters;
import android.app.job.JobScheduler;
import android.app.job.JobService;
import android.content.ComponentName;
import android.content.Context;

/**
 * Android এর নিজস্ব JobScheduler:
 *  - প্রতি ১৫ মিনিটে: মিস হওয়া SMS খোঁজা + জমে থাকা SMS পাঠানো + সার্ভিস আবার চালু করা
 *  - নেট বন্ধ থাকলে: নেট আসার সাথে সাথে চালু হয়ে জমে থাকা SMS পাঠায়
 * অ্যাপ বন্ধ / নোটিফিকেশন কেটে দিলেও এটা চলে।
 */
public class SyncJobService extends JobService {

    private static final int JOB_PERIODIC = 7001;
    private static final int JOB_WHEN_ONLINE = 7002;

    public static void schedulePeriodic(Context ctx) {
        try {
            JobScheduler js = (JobScheduler) ctx.getSystemService(Context.JOB_SCHEDULER_SERVICE);
            if (js == null) return;
            for (JobInfo ji : js.getAllPendingJobs()) {
                if (ji.getId() == JOB_PERIODIC) return;   // আগে থেকেই আছে
            }
            JobInfo job = new JobInfo.Builder(JOB_PERIODIC, new ComponentName(ctx, SyncJobService.class))
                    .setPeriodic(15 * 60 * 1000L)
                    .setPersisted(true)                    // ফোন রিস্টার্টের পরেও থাকবে
                    .build();
            js.schedule(job);
        } catch (Exception ignored) {}
    }

    public static void scheduleWhenOnline(Context ctx) {
        try {
            JobScheduler js = (JobScheduler) ctx.getSystemService(Context.JOB_SCHEDULER_SERVICE);
            if (js == null) return;
            for (JobInfo ji : js.getAllPendingJobs()) {
                if (ji.getId() == JOB_WHEN_ONLINE) return;   // আগে থেকেই অপেক্ষায় আছে
            }
            JobInfo job = new JobInfo.Builder(JOB_WHEN_ONLINE, new ComponentName(ctx, SyncJobService.class))
                    .setRequiredNetworkType(JobInfo.NETWORK_TYPE_ANY)
                    .setMinimumLatency(20000)
                    .setBackoffCriteria(30000, JobInfo.BACKOFF_POLICY_EXPONENTIAL)
                    .setPersisted(true)
                    .build();
            js.schedule(job);
        } catch (Exception ignored) {}
    }

    public static void cancelAll(Context ctx) {
        try {
            JobScheduler js = (JobScheduler) ctx.getSystemService(Context.JOB_SCHEDULER_SERVICE);
            if (js != null) {
                js.cancel(JOB_PERIODIC);
                js.cancel(JOB_WHEN_ONLINE);
            }
        } catch (Exception ignored) {}
    }

    @Override
    public boolean onStartJob(JobParameters params) {
        final Context app = getApplicationContext();
        if (!AppConfig.isLoggedIn(app)) return false;

        new Thread(() -> {
            int left = 0;
            try {
                if (AppConfig.isStale(app, 10 * 60 * 1000L)) AppConfig.fetch(app);
                SyncManager.scanInbox(app);
                left = SyncManager.syncPending(app);
                SyncManager.ensureServiceRunning(app);
            } catch (Exception ignored) {
            } finally {
                // এখনো বাকি থাকলে (নেট ভালো না) Android আবার চেষ্টা করবে
                jobFinished(params, params.getJobId() == JOB_WHEN_ONLINE && left > 0);
            }
        }).start();
        return true;
    }

    @Override
    public boolean onStopJob(JobParameters params) {
        return true;
    }
}
