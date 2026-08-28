package com.example.calltrack.service

import android.content.BroadcastReceiver
import android.content.Context
import android.content.Intent
import com.example.calltrack.logging.AppLogger

class BootReceiver : BroadcastReceiver() {
    override fun onReceive(context: Context, intent: Intent?) {
        if (intent?.action != Intent.ACTION_BOOT_COMPLETED) return
        StabilityDiagnostics.mark(context, "device_booted")
        AppLogger.log(context, "STABILITY", "\u0423\u0441\u0442\u0440\u043E\u0439\u0441\u0442\u0432\u043E \u0437\u0430\u0433\u0440\u0443\u0436\u0435\u043D\u043E, \u0444\u043E\u043D\u043E\u0432\u0430\u044F \u043F\u0440\u043E\u0432\u0435\u0440\u043A\u0430 CallTrack \u0437\u0430\u043F\u043B\u0430\u043D\u0438\u0440\u043E\u0432\u0430\u043D\u0430")
        CalltrackStabilityWorker.schedule(context)
    }
}
