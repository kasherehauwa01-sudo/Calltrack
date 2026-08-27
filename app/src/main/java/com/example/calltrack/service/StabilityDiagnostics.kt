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

    fun serviceHeartbeat(context: Context) = mark(context, "service_heartbeat")

    fun serviceHeartbeatAgeMs(context: Context): Long {
        val timestamp = context.getSharedPreferences(PREFS, Context.MODE_PRIVATE).getLong("service_heartbeat_at", 0L)
        return if (timestamp <= 0L) Long.MAX_VALUE else System.currentTimeMillis() - timestamp
    }

    fun snapshot(context: Context, pendingCalls: Int): JSONObject {
        val prefs = context.getSharedPreferences(PREFS, Context.MODE_PRIVATE)
        val power = context.getSystemService(Context.POWER_SERVICE) as PowerManager
        return JSONObject().apply {
            put("diagnostic_version", 1)
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
            put("pending_calls", pendingCalls)
            put("device_idle", if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.M) power.isDeviceIdleMode else false)
            put("power_save", power.isPowerSaveMode)
            put("battery_optimization_ignored", if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.M) power.isIgnoringBatteryOptimizations(context.packageName) else true)
        }
    }
}
