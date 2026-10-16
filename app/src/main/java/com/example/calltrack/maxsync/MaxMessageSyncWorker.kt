package com.example.calltrack.maxsync

import android.content.Context
import androidx.work.*
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.withContext
import java.util.concurrent.TimeUnit

class MaxMessageSyncWorker(context: Context, parameters: WorkerParameters) : CoroutineWorker(context, parameters) {
    override suspend fun doWork(): Result = withContext(Dispatchers.IO) {
        try {
            if (MaxMessageSyncRuntime.run(applicationContext)) Result.retry() else Result.success()
        } catch (cancelled: kotlinx.coroutines.CancellationException) {
            throw cancelled
        } catch (_: Exception) {
            // Never log payload, PII, tokens, HTTP bodies or SQL exceptions.
            Result.retry()
        }
    }
    companion object {
        private val network = Constraints.Builder().setRequiredNetworkType(NetworkType.CONNECTED).build()
        fun schedule(context: Context) {
            val manager = WorkManager.getInstance(context)
            manager.enqueueUniquePeriodicWork("max-message-sync-periodic", ExistingPeriodicWorkPolicy.KEEP,
                PeriodicWorkRequestBuilder<MaxMessageSyncWorker>(15, TimeUnit.MINUTES)
                    .setConstraints(network).setBackoffCriteria(BackoffPolicy.EXPONENTIAL, 30, TimeUnit.SECONDS).build())
            enqueue(context)
        }
        fun enqueue(context: Context) {
            WorkManager.getInstance(context).enqueueUniqueWork("max-message-sync", ExistingWorkPolicy.KEEP,
                OneTimeWorkRequestBuilder<MaxMessageSyncWorker>().setConstraints(network)
                    .setBackoffCriteria(BackoffPolicy.EXPONENTIAL, 30, TimeUnit.SECONDS).build())
        }
    }
}
