package com.example.calltrack.service

import android.content.BroadcastReceiver
import android.content.Context
import android.content.Intent
import com.example.calltrack.logging.AppLogger

class BootReceiver : BroadcastReceiver() {
    override fun onReceive(context: Context, intent: Intent?) {
        if (intent?.action != Intent.ACTION_BOOT_COMPLETED) return
        StabilityDiagnostics.mark(context, "device_booted")
        AppLogger.log(context, "STABILITY", "Устройство загружено, фоновая проверка CallTrack запланирована")
        CalltrackStabilityWorker.schedule(context)
    }
}
