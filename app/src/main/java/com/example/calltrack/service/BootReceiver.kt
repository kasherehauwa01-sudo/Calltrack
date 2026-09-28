package com.example.calltrack.service

import android.content.BroadcastReceiver
import android.content.Context
import android.content.Intent
import com.example.calltrack.logging.AppLogger

class BootReceiver : BroadcastReceiver() {
    override fun onReceive(context: Context, intent: Intent?) {
        val reason = when (intent?.action) {
            Intent.ACTION_BOOT_COMPLETED -> RecoveryReason.DEVICE_BOOT
            Intent.ACTION_MY_PACKAGE_REPLACED -> RecoveryReason.PACKAGE_REPLACED
            else -> return
        }
        if (reason == RecoveryReason.DEVICE_BOOT) StabilityDiagnostics.mark(context, "device_boot")
        AppLogger.log(context, "STABILITY", "\u0421\u0438\u0441\u0442\u0435\u043C\u043D\u043E\u0435 \u0432\u043E\u0441\u0441\u0442\u0430\u043D\u043E\u0432\u043B\u0435\u043D\u0438\u0435 Calltrack \u0437\u0430\u043F\u043B\u0430\u043D\u0438\u0440\u043E\u0432\u0430\u043D\u043E: ${reason.name}")
        CalltrackRecoveryManager.recover(context, reason)
    }
}
