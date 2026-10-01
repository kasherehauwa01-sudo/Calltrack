package com.example.calltrack.service

import android.Manifest
import android.content.Context
import android.content.Intent
import android.content.pm.PackageManager
import androidx.core.content.ContextCompat
import androidx.work.BackoffPolicy
import androidx.work.Constraints
import androidx.work.CoroutineWorker
import androidx.work.ExistingWorkPolicy
import androidx.work.NetworkType
import androidx.work.OneTimeWorkRequestBuilder
import androidx.work.WorkManager
import androidx.work.WorkerParameters
import androidx.work.workDataOf
import com.example.calltrack.App
import com.example.calltrack.auth.AuthStore
import com.example.calltrack.logging.AppLogger
import java.util.concurrent.TimeUnit

enum class RecoveryReason {
    DEVICE_BOOT,
    SERVICE_DESTROYED,
    PROCESS_RESTART,
    PACKAGE_REPLACED,
    WATCHDOG,
    APP_START
}

/** \u0415\u0434\u0438\u043D\u0430\u044F \u0442\u043E\u0447\u043A\u0430 \u0432\u043E\u0441\u0441\u0442\u0430\u043D\u043E\u0432\u043B\u0435\u043D\u0438\u044F \u0444\u043E\u043D\u043E\u0432\u043E\u0439 \u0440\u0430\u0431\u043E\u0442\u044B Calltrack. */
object CalltrackRecoveryManager {
    private const val RECOVERY_WORK = "calltrack-recovery"
    private const val SYNC_WORK = "calltrack-pending-sync"
    private const val REASON_KEY = "recovery_reason"

    fun recover(context: Context, reason: RecoveryReason) {
        val app = context.applicationContext
        if (!shouldRun(app)) {
            cancelAuthorizedWork(app)
            return
        }
        CalltrackStabilityWorker.schedule(app)
        schedulePendingSync(app)
        if (attemptServiceStart(app, reason)) return
        val request = OneTimeWorkRequestBuilder<CalltrackRecoveryWorker>()
            .setInputData(workDataOf(REASON_KEY to reason.name))
            .setBackoffCriteria(BackoffPolicy.EXPONENTIAL, 30, TimeUnit.SECONDS)
            .build()
        WorkManager.getInstance(app).enqueueUniqueWork(RECOVERY_WORK, ExistingWorkPolicy.KEEP, request)
    }

    fun schedulePendingSync(context: Context) {
        if (!canSync(context)) return
        val request = OneTimeWorkRequestBuilder<CalltrackSyncWorker>()
            .setConstraints(Constraints.Builder().setRequiredNetworkType(NetworkType.CONNECTED).build())
            .setBackoffCriteria(BackoffPolicy.EXPONENTIAL, 30, TimeUnit.SECONDS)
            .build()
        WorkManager.getInstance(context).enqueueUniqueWork(SYNC_WORK, ExistingWorkPolicy.KEEP, request)
    }

    fun cancelAuthorizedWork(context: Context) {
        WorkManager.getInstance(context).apply {
            cancelUniqueWork(RECOVERY_WORK)
            cancelUniqueWork(SYNC_WORK)
            cancelUniqueWork(CalltrackStabilityWorker.WORK_NAME)
        }
    }

    internal fun shouldRun(context: Context): Boolean =
        canSync(context) &&
            ContextCompat.checkSelfPermission(context, Manifest.permission.READ_PHONE_STATE) == PackageManager.PERMISSION_GRANTED &&
            ContextCompat.checkSelfPermission(context, Manifest.permission.READ_CALL_LOG) == PackageManager.PERMISSION_GRANTED

    internal fun canSync(context: Context): Boolean =
        runCatching { AuthStore(context).isAuthenticated }.getOrDefault(false)

    internal suspend fun recoverNow(context: Context, reason: RecoveryReason): Boolean {
        if (!shouldRun(context)) return true
        return attemptServiceStart(context, reason)
    }

    private fun attemptServiceStart(context: Context, reason: RecoveryReason): Boolean {
        if (!StabilityDiagnostics.beginRecovery(context, reason)) return true
        return runCatching {
            ContextCompat.startForegroundService(context, Intent(context, CallTrackingService::class.java))
            schedulePendingSync(context)
            AppLogger.log(context, "RECOVERY", "\u0424\u043E\u043D\u043E\u0432\u0430\u044F \u0440\u0430\u0431\u043E\u0442\u0430 \u0432\u043E\u0441\u0441\u0442\u0430\u043D\u043E\u0432\u043B\u0435\u043D\u0430: ${reason.name}")
            true
        }.getOrElse { error ->
            StabilityDiagnostics.recoveryFailed(context, reason, error)
            AppLogger.log(context, "ERROR", "\u041D\u0435 \u0443\u0434\u0430\u043B\u043E\u0441\u044C \u0432\u043E\u0441\u0441\u0442\u0430\u043D\u043E\u0432\u0438\u0442\u044C \u0444\u043E\u043D\u043E\u0432\u0443\u044E \u0440\u0430\u0431\u043E\u0442\u0443: ${reason.name}: ${error.message}", error)
            false
        }
    }

    internal fun reason(input: String?): RecoveryReason =
        runCatching { RecoveryReason.valueOf(input.orEmpty()) }.getOrDefault(RecoveryReason.PROCESS_RESTART)

    internal const val INPUT_REASON = REASON_KEY
}

class CalltrackRecoveryWorker(context: Context, params: WorkerParameters) : CoroutineWorker(context, params) {
    override suspend fun doWork(): Result {
        val reason = CalltrackRecoveryManager.reason(inputData.getString(CalltrackRecoveryManager.INPUT_REASON))
        return if (CalltrackRecoveryManager.recoverNow(applicationContext, reason)) Result.success() else Result.retry()
    }
}

class CalltrackSyncWorker(context: Context, params: WorkerParameters) : CoroutineWorker(context, params) {
    override suspend fun doWork(): Result {
        val app = applicationContext as App
        if (!CalltrackRecoveryManager.canSync(app)) return Result.success()
        return runCatching {
            val completed = app.repository.syncPending()
            app.repository.sendUserTelemetry()
            if (completed) Result.success() else Result.retry()
        }.getOrElse {
            StabilityDiagnostics.mark(app, "sync_failed", "recovery worker: ${it.message}")
            Result.retry()
        }
    }
}
