package com.example.calltrack.service

import android.Manifest
import android.content.Context
import android.content.Intent
import android.content.pm.PackageManager
import androidx.core.content.ContextCompat
import androidx.work.CoroutineWorker
import androidx.work.ExistingPeriodicWorkPolicy
import androidx.work.PeriodicWorkRequestBuilder
import androidx.work.WorkManager
import androidx.work.WorkerParameters
import com.example.calltrack.App
import com.example.calltrack.logging.AppLogger
import java.util.concurrent.TimeUnit

class CalltrackStabilityWorker(context: Context, params: WorkerParameters) : CoroutineWorker(context, params) {
    override suspend fun doWork(): Result {
        val app = applicationContext as App
        val heartbeatAge = StabilityDiagnostics.serviceHeartbeatAgeMs(app)
        StabilityDiagnostics.mark(app, "worker_started", "heartbeat_age_ms=$heartbeatAge; attempt=$runAttemptCount")
        AppLogger.log(app, "STABILITY", "\u041F\u0435\u0440\u0438\u043E\u0434\u0438\u0447\u0435\u0441\u043A\u0430\u044F \u043F\u0440\u043E\u0432\u0435\u0440\u043A\u0430 \u0444\u043E\u043D\u043E\u0432\u043E\u0439 \u0440\u0430\u0431\u043E\u0442\u044B")

        if (heartbeatAge > STALE_HEARTBEAT_MS) {
            AppLogger.log(app, "STABILITY_GAP", "Heartbeat \u0441\u0435\u0440\u0432\u0438\u0441\u0430 \u043E\u0442\u0441\u0443\u0442\u0441\u0442\u0432\u043E\u0432\u0430\u043B ${heartbeatAge / 1000} \u0441\u0435\u043A.; WorkManager \u0432\u043E\u0441\u0441\u0442\u0430\u043D\u0430\u0432\u043B\u0438\u0432\u0430\u0435\u0442 \u0441\u0435\u0440\u0432\u0438\u0441")
        }

        if (ContextCompat.checkSelfPermission(app, Manifest.permission.READ_PHONE_STATE) == PackageManager.PERMISSION_GRANTED) {
            runCatching {
                ContextCompat.startForegroundService(app, Intent(app, CallTrackingService::class.java))
            }.onFailure { error ->
                AppLogger.log(app, "WARN", "\u041D\u0435 \u0443\u0434\u0430\u043B\u043E\u0441\u044C \u0432\u043E\u0441\u0441\u0442\u0430\u043D\u043E\u0432\u0438\u0442\u044C \u0441\u0435\u0440\u0432\u0438\u0441 \u043E\u0442\u0441\u043B\u0435\u0436\u0438\u0432\u0430\u043D\u0438\u044F: ${error.message}", error)
            }
        }

        return runCatching {
            app.repository.syncPending()
            app.repository.sendUserTelemetry()
            StabilityDiagnostics.mark(app, "worker_finished", "success")
            AppLogger.log(app, "STABILITY", "\u0424\u043E\u043D\u043E\u0432\u0430\u044F \u0441\u0438\u043D\u0445\u0440\u043E\u043D\u0438\u0437\u0430\u0446\u0438\u044F \u0437\u0430\u0432\u0435\u0440\u0448\u0435\u043D\u0430")
            Result.success()
        }.getOrElse { error ->
            StabilityDiagnostics.mark(app, "sync_failed", "worker: ${error.message}")
            AppLogger.log(app, "ERROR", "\u041E\u0448\u0438\u0431\u043A\u0430 \u0444\u043E\u043D\u043E\u0432\u043E\u0439 \u0441\u0438\u043D\u0445\u0440\u043E\u043D\u0438\u0437\u0430\u0446\u0438\u0438: ${error.message}", error)
            Result.retry()
        }
    }

    companion object {
        private const val WORK_NAME = "calltrack-stability"
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
