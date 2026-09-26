package com.example.calltrack.service

import android.content.Context
import androidx.work.CoroutineWorker
import androidx.work.ExistingPeriodicWorkPolicy
import androidx.work.PeriodicWorkRequestBuilder
import androidx.work.WorkManager
import androidx.work.WorkerParameters
import com.example.calltrack.logging.AppLogger
import java.util.concurrent.TimeUnit

class CalltrackStabilityWorker(context: Context, params: WorkerParameters) : CoroutineWorker(context, params) {
    override suspend fun doWork(): Result {
        val app = applicationContext
        if(!CalltrackRecoveryManager.shouldRun(app))return Result.success()
        val heartbeatAge = StabilityDiagnostics.serviceHeartbeatAgeMs(app)
        StabilityDiagnostics.watchdogChecked(app, heartbeatAge)
        AppLogger.log(app, "STABILITY", "\u041F\u0435\u0440\u0438\u043E\u0434\u0438\u0447\u0435\u0441\u043A\u0430\u044F \u043F\u0440\u043E\u0432\u0435\u0440\u043A\u0430 \u0444\u043E\u043D\u043E\u0432\u043E\u0439 \u0440\u0430\u0431\u043E\u0442\u044B")

        if (heartbeatAge > STALE_HEARTBEAT_MS) {
            AppLogger.log(app, "STABILITY_GAP", "Heartbeat \u0441\u0435\u0440\u0432\u0438\u0441\u0430 \u043E\u0442\u0441\u0443\u0442\u0441\u0442\u0432\u043E\u0432\u0430\u043B ${heartbeatAge / 1000} \u0441\u0435\u043A.; WorkManager \u0432\u043E\u0441\u0441\u0442\u0430\u043D\u0430\u0432\u043B\u0438\u0432\u0430\u0435\u0442 \u0441\u0435\u0440\u0432\u0438\u0441")
            return if (CalltrackRecoveryManager.recoverNow(app, RecoveryReason.WATCHDOG)) Result.success() else Result.retry()
        }
        CalltrackRecoveryManager.schedulePendingSync(app)
        return Result.success()
    }

    companion object {
        const val WORK_NAME = "calltrack-stability"
        private val STALE_HEARTBEAT_MS = TimeUnit.MINUTES.toMillis(3)

        fun schedule(context: Context) {
            val request = PeriodicWorkRequestBuilder<CalltrackStabilityWorker>(15, TimeUnit.MINUTES).build()
            WorkManager.getInstance(context).enqueueUniquePeriodicWork(
                WORK_NAME,
                ExistingPeriodicWorkPolicy.KEEP,
                request
            )
        }
    }
}
