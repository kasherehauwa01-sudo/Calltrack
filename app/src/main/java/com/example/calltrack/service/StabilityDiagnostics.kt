package com.example.calltrack.service

import android.content.Context
import android.os.Build
import android.os.PowerManager
import org.json.JSONObject

/**
 * Постоянный «чёрный ящик» фоновой работы. Данные переживают убийство процесса,
 * поэтому следующий запуск может показать, на каком этапе сервис перестал жить.
 */
object StabilityDiagnostics {
    private const val PREFS = "stability_diagnostics"
    private const val RECOVERY_WINDOW_MS = 30 * 60 * 1000L
    private const val MAX_RECOVERY_ATTEMPTS = 3

    @Synchronized
    fun mark(context: Context, event: String, detail: String = "") {
        val now = System.currentTimeMillis()
        context.getSharedPreferences(PREFS, Context.MODE_PRIVATE).edit()
            .putLong("${event}_at", now)
            .putString("${event}_detail", detail.take(500))
            .putString("last_event", event)
            .putLong("last_event_at", now)
            .apply()
    }

    @Synchronized
    fun increment(context: Context, counter: String) {
        val prefs = context.getSharedPreferences(PREFS, Context.MODE_PRIVATE)
        prefs.edit().putLong(counter, prefs.getLong(counter, 0L) + 1L).apply()
    }

    fun serviceHeartbeat(context: Context) {
        val prefs = context.getSharedPreferences(PREFS, Context.MODE_PRIVATE)
        val lastRecovery = prefs.getLong("last_recovery_success_at", 0L)
        if (lastRecovery > 0L && System.currentTimeMillis() - lastRecovery >= 5 * 60 * 1000L) {
            prefs.edit()
                .putInt("recovery_attempt_count", 0)
                .putLong("recovery_window_started_at", 0L)
                .putBoolean("recovery_loop_detected", false)
                .apply()
        }
        mark(context, "service_heartbeat")
    }

    @Synchronized
    fun beginRecovery(context: Context, reason: RecoveryReason): Boolean {
        val now = System.currentTimeMillis()
        val prefs = context.getSharedPreferences(PREFS, Context.MODE_PRIVATE)
        val windowStartedAt = prefs.getLong("recovery_window_started_at", 0L)
        val previousCount = if (now - windowStartedAt <= RECOVERY_WINDOW_MS) prefs.getInt("recovery_attempt_count", 0) else 0
        if (previousCount >= MAX_RECOVERY_ATTEMPTS) {
            prefs.edit()
                .putBoolean("recovery_loop_detected", true)
                .putString("last_recovery_reason", reason.name)
                .putString("last_recovery_error", "recovery_loop_detected")
                .apply()
            mark(context, "recovery_loop_detected", "reason=${reason.name}; count=$previousCount")
            return false
        }
        prefs.edit()
            .putLong("recovery_window_started_at", if (previousCount == 0) now else windowStartedAt)
            .putLong("last_recovery_attempt_at", now)
            .putString("last_recovery_reason", reason.name)
            .putInt("recovery_attempt_count", previousCount + 1)
            .putBoolean("recovery_loop_detected", false)
            .remove("last_recovery_error")
            .commit()
        return true
    }

    fun recoverySucceeded(context: Context, reason: RecoveryReason) {
        context.getSharedPreferences(PREFS, Context.MODE_PRIVATE).edit()
            .putLong("last_recovery_success_at", System.currentTimeMillis())
            .putString("last_recovery_reason", reason.name)
            .remove("last_recovery_error")
            .apply()
        mark(context, "recovery_success", reason.name)
    }

    fun serviceConfirmed(context: Context) {
        val storedReason = context.getSharedPreferences(PREFS, Context.MODE_PRIVATE)
            .getString("last_recovery_reason", null) ?: return
        val reason = runCatching { RecoveryReason.valueOf(storedReason) }.getOrNull() ?: return
        recoverySucceeded(context, reason)
    }

    fun recoveryFailed(context: Context, reason: RecoveryReason, error: Throwable) {
        context.getSharedPreferences(PREFS, Context.MODE_PRIVATE).edit()
            .putString("last_recovery_reason", reason.name)
            .putString("last_recovery_error", error.message.orEmpty().take(500))
            .apply()
        mark(context, "recovery_failed", reason.name)
    }

    fun watchdogChecked(context: Context, heartbeatAgeMs: Long) {
        mark(context, "watchdog_last_check", "heartbeat_age_ms=$heartbeatAgeMs")
    }

    fun serviceHeartbeatAgeMs(context: Context): Long {
        val timestamp = context.getSharedPreferences(PREFS, Context.MODE_PRIVATE).getLong("service_heartbeat_at", 0L)
        return if (timestamp <= 0L) Long.MAX_VALUE else System.currentTimeMillis() - timestamp
    }

    fun snapshot(context: Context, pendingCalls: Int): JSONObject {
        val prefs = context.getSharedPreferences(PREFS, Context.MODE_PRIVATE)
        val power = context.getSystemService(Context.POWER_SERVICE) as PowerManager
        return JSONObject().apply {
            put("diagnostic_version", 2)
            put("captured_at", System.currentTimeMillis())
            put("last_event", prefs.getString("last_event", "unknown"))
            put("last_event_at", prefs.getLong("last_event_at", 0L))
            put("service_started_at", prefs.getLong("service_started_at", 0L))
            put("service_heartbeat_at", prefs.getLong("service_heartbeat_at", 0L))
            put("service_destroyed_at", prefs.getLong("service_destroyed_at", 0L))
            put("tracker_started_at", prefs.getLong("tracker_started_at", 0L))
            put("tracker_event_at", prefs.getLong("tracker_event_at", 0L))
            put("call_capture_started_at", prefs.getLong("call_capture_started_at", 0L))
            put("call_capture_finished_at", prefs.getLong("call_capture_finished_at", 0L))
            put("sync_started_at", prefs.getLong("sync_started_at", 0L))
            put("sync_finished_at", prefs.getLong("sync_finished_at", 0L))
            put("sync_failed_at", prefs.getLong("sync_failed_at", 0L))
            put("sync_failed_detail", prefs.getString("sync_failed_detail", ""))
            put("worker_started_at", prefs.getLong("worker_started_at", 0L))
            put("worker_finished_at", prefs.getLong("worker_finished_at", 0L))
            put("service_restart_count", prefs.getLong("service_restart_count", 0L))
            put("device_boot_at", prefs.getLong("device_boot_at", 0L))
            put("last_recovery_attempt_at", prefs.getLong("last_recovery_attempt_at", 0L))
            put("last_recovery_success_at", prefs.getLong("last_recovery_success_at", 0L))
            put("last_recovery_reason", prefs.getString("last_recovery_reason", ""))
            put("recovery_attempt_count", prefs.getInt("recovery_attempt_count", 0))
            put("recovery_loop_detected", prefs.getBoolean("recovery_loop_detected", false))
            put("last_recovery_error", prefs.getString("last_recovery_error", ""))
            put("watchdog_last_check_at", prefs.getLong("watchdog_last_check_at", 0L))
            put("pending_calls", pendingCalls)
            put("device_idle", if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.M) power.isDeviceIdleMode else false)
            put("power_save", power.isPowerSaveMode)
            put("battery_optimization_ignored", if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.M) power.isIgnoringBatteryOptimizations(context.packageName) else true)
        }
    }
}
