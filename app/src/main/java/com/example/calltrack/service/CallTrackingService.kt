package com.example.calltrack.service

import android.app.Notification
import android.app.NotificationChannel
import android.app.NotificationManager
import android.app.Service
import android.content.Intent
import android.os.Build
import android.os.IBinder
import android.telephony.TelephonyManager
import androidx.core.app.NotificationCompat
import com.example.calltrack.App
import com.example.calltrack.R
import com.example.calltrack.data.local.CallEntity
import com.example.calltrack.telephony.CallStateTracker
import com.example.calltrack.utils.CallUtils
import kotlinx.coroutines.CoroutineScope
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.SupervisorJob
import kotlinx.coroutines.launch

class CallTrackingService : Service() {
    private val scope = CoroutineScope(SupervisorJob() + Dispatchers.IO)
    private lateinit var tracker: CallStateTracker
    private var callStart: Long = 0L
    private var wasRinging = false

    override fun onCreate() {
        super.onCreate()
        createChannel()

        // На Android 14+ в некоторых сценариях система запрещает поднимать FGS в текущий момент.
        // В этом случае не падаем, а корректно останавливаем сервис.
        val started = runCatching {
            startForeground(101, createNotification("Отслеживание звонков активно"))
        }.isSuccess
        if (!started) {
            stopSelf()
            return
        }

        tracker = CallStateTracker(this) { state, _ ->
            when (state) {
                TelephonyManager.CALL_STATE_RINGING -> wasRinging = true
                TelephonyManager.CALL_STATE_OFFHOOK -> callStart = System.currentTimeMillis()
                TelephonyManager.CALL_STATE_IDLE -> {
                    if (callStart > 0L || wasRinging) {
                        val end = System.currentTimeMillis()
                        val duration = CallUtils.calculateDurationSec(callStart, end)
                        val type = CallUtils.defineCallType(startedFromApp = false, wasRinging = wasRinging, durationSec = duration)
                        val note = when (type.title) {
                            "Пропущенный" -> "Пропущенный"
                            "Неотвеченный" -> "Неотвеченный"
                            else -> "Вне приложения"
                        }
                        val entity = CallEntity(
                            phone = "Неизвестно",
                            type = type.title,
                            duration = duration,
                            note = note,
                            timestamp = end
                        )
                        val repo = (application as App).repository
                        scope.launch {
                            repo.saveCall(entity)
                            repo.syncPending()
                        }
                        callStart = 0L
                        wasRinging = false
                    }
                }
            }
        }
        tracker.start()
    }

    override fun onDestroy() {
        tracker.stop()
        super.onDestroy()
    }

    override fun onBind(intent: Intent?): IBinder? = null

    private fun createChannel() {
        if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.O) {
            val channel = NotificationChannel("calltrack", "Call Tracking", NotificationManager.IMPORTANCE_LOW)
            getSystemService(NotificationManager::class.java).createNotificationChannel(channel)
        }
    }

    private fun createNotification(text: String): Notification {
        return NotificationCompat.Builder(this, "calltrack")
            .setContentTitle("Calltrack")
            .setContentText(text)
            .setSmallIcon(R.drawable.ic_phone)
            .build()
    }
}
