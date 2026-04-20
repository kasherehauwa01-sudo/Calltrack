package com.example.calltrack.service

import android.Manifest
import android.app.Notification
import android.app.NotificationChannel
import android.app.NotificationManager
import android.app.Service
import android.content.Intent
import android.content.pm.PackageManager
import android.os.Build
import android.os.IBinder
import android.provider.CallLog
import android.telephony.TelephonyManager
import androidx.core.app.NotificationCompat
import androidx.core.content.ContextCompat
import com.example.calltrack.App
import com.example.calltrack.R
import com.example.calltrack.data.local.CallEntity
import com.example.calltrack.telephony.CallStateTracker
import kotlinx.coroutines.CoroutineScope
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.SupervisorJob
import kotlinx.coroutines.delay
import kotlinx.coroutines.launch

class CallTrackingService : Service() {
    private val scope = CoroutineScope(SupervisorJob() + Dispatchers.IO)
    private lateinit var tracker: CallStateTracker
    private var lastStateWasActive = false

    override fun onCreate() {
        super.onCreate()
        createChannel()

        val started = runCatching {
            startForeground(101, createNotification("Отслеживание звонков активно"))
        }.isSuccess
        if (!started) {
            stopSelf()
            return
        }

        tracker = CallStateTracker(this) { state, _ ->
            when (state) {
                TelephonyManager.CALL_STATE_RINGING,
                TelephonyManager.CALL_STATE_OFFHOOK -> lastStateWasActive = true

                TelephonyManager.CALL_STATE_IDLE -> {
                    if (lastStateWasActive) {
                        lastStateWasActive = false
                        scope.launch {
                            // Даем системе небольшой буфер, чтобы запись точно появилась в CallLog.
                            delay(600)
                            readLatestCallEntity()?.let { entity ->
                                val repo = (application as App).repository
                                repo.saveCall(entity)
                                repo.syncPending()
                            }
                        }
                    }
                }
            }
        }
        tracker.start()
    }

    private fun readLatestCallEntity(): CallEntity? {
        if (ContextCompat.checkSelfPermission(this, Manifest.permission.READ_CALL_LOG) != PackageManager.PERMISSION_GRANTED) {
            return null
        }

        val projection = arrayOf(
            CallLog.Calls.NUMBER,
            CallLog.Calls.TYPE,
            CallLog.Calls.DURATION,
            CallLog.Calls.DATE
        )

        contentResolver.query(
            CallLog.Calls.CONTENT_URI,
            projection,
            null,
            null,
            "${CallLog.Calls.DATE} DESC LIMIT 1"
        )?.use { cursor ->
            if (cursor.moveToFirst()) {
                val number = cursor.getString(cursor.getColumnIndexOrThrow(CallLog.Calls.NUMBER)).orEmpty()
                val typeInt = cursor.getInt(cursor.getColumnIndexOrThrow(CallLog.Calls.TYPE))
                val duration = cursor.getLong(cursor.getColumnIndexOrThrow(CallLog.Calls.DURATION))
                val timestamp = cursor.getLong(cursor.getColumnIndexOrThrow(CallLog.Calls.DATE))

                val (type, note) = mapCallType(typeInt, duration)
                return CallEntity(
                    phone = if (number.isBlank()) "Неизвестно" else number,
                    type = type,
                    duration = duration,
                    note = note,
                    timestamp = timestamp
                )
            }
        }
        return null
    }

    private fun mapCallType(typeInt: Int, duration: Long): Pair<String, String> {
        return when (typeInt) {
            CallLog.Calls.INCOMING_TYPE -> "Входящий" to ""
            CallLog.Calls.OUTGOING_TYPE -> "Исходящий" to ""
            CallLog.Calls.MISSED_TYPE -> "Пропущенный" to "Пропущенный"
            CallLog.Calls.REJECTED_TYPE -> "Неотвеченный" to "Неотвеченный"
            CallLog.Calls.BLOCKED_TYPE -> "Неотвеченный" to "Неотвеченный"
            else -> {
                if (duration == 0L) "Пропущенный" to "Пропущенный" else "Исходящий" to ""
            }
        }
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
