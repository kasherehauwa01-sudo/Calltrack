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
        AppLogger.log(app, "STABILITY", "Периодическая проверка фоновой работы")

        if (ContextCompat.checkSelfPermission(app, Manifest.permission.READ_PHONE_STATE) == PackageManager.PERMISSION_GRANTED) {
            runCatching {
                ContextCompat.startForegroundService(app, Intent(app, CallTrackingService::class.java))
            }.onFailure { error ->
                AppLogger.log(app, "WARN", "Не удалось восстановить сервис отслеживания: ${error.message}", error)
            }
        }

        return runCatching {
            app.repository.syncPending()
            app.repository.sendUserTelemetry()
            AppLogger.log(app, "STABILITY", "Фоновая синхронизация завершена")
            Result.success()
        }.getOrElse { error ->
            AppLogger.log(app, "ERROR", "Ошибка фоновой синхронизации: ${error.message}", error)
            Result.retry()
        }
    }

    companion object {
        private const val WORK_NAME = "calltrack-stability"

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
