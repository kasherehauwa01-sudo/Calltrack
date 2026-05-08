package com.example.calltrack.service

import android.Manifest
import android.app.Notification
import android.app.NotificationChannel
import android.app.NotificationManager
import android.app.PendingIntent
import android.app.Service
import android.content.Intent
import android.content.pm.PackageManager
import android.net.Uri
import android.media.AudioAttributes
import android.media.RingtoneManager
import android.os.Build
import android.os.IBinder
import android.provider.CallLog
import android.provider.ContactsContract
import android.telephony.TelephonyManager
import android.util.Log
import androidx.core.app.NotificationCompat
import androidx.core.content.ContextCompat
import com.example.calltrack.App
import com.example.calltrack.R
import com.example.calltrack.data.local.CallEntity
import com.example.calltrack.telephony.CallStateTracker
import com.example.calltrack.ui.postcall.PostCallActivity
import kotlinx.coroutines.CoroutineScope
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.SupervisorJob
import kotlinx.coroutines.cancel
import kotlinx.coroutines.delay
import kotlinx.coroutines.launch

class CallTrackingService : Service() {
    private val scope = CoroutineScope(SupervisorJob() + Dispatchers.IO)
    private lateinit var tracker: CallStateTracker
    private var lastStateWasActive = false
    private var lastHandledTimestamp: Long = 0L

    override fun onCreate() {
        super.onCreate()
        createChannel()

        val started = runCatching {
            startForeground(101, createNotification("Приложение активно"))
        }.isSuccess
        if (!started) {
            stopSelf()
            return
        }

        // Запоминаем текущую последнюю запись, чтобы не дублировать старые звонки после старта сервиса.
        lastHandledTimestamp = readLatestCallEntity()?.timestamp ?: 0L

        tracker = CallStateTracker(this) { state, _ ->
            when (state) {
                TelephonyManager.CALL_STATE_RINGING,
                TelephonyManager.CALL_STATE_OFFHOOK -> lastStateWasActive = true

                TelephonyManager.CALL_STATE_IDLE -> {
                    if (lastStateWasActive) {
                        lastStateWasActive = false
                        scope.launch {
                            captureLatestCallWithRetry()
                        }
                    }
                }
            }
        }
        tracker.start()
    }

    private suspend fun captureLatestCallWithRetry() {
        // Стараемся показать post-call максимально быстро после завершения звонка.
        repeat(10) { attempt ->
            val captured = captureLatestCallIfNew()
            if (captured) return
            if (attempt < 9) delay(200)
        }
    }

    private suspend fun captureLatestCallIfNew(): Boolean {
        val entity = readLatestCallEntity() ?: return false
        if (entity.timestamp <= lastHandledTimestamp) return false

        lastHandledTimestamp = entity.timestamp
        val repo = (application as App).repository
        val callId = repo.saveCall(entity)
        Log.d("WEBHOOK", "Пытаемся отправить данные в Google Sheets")
        runCatching { repo.syncPending() }
            .onFailure {
                Log.e("WEBHOOK", "Ошибка отправки webhook", it)
            }
        if (shouldShowPostCallPrompt(entity.type)) {
            val contactName = resolveContactName(entity.phone)
            showPostCallNow(callId, entity.phone, contactName)
        }
        Log.d("CallTrackingService", "Call captured: ${entity.phone}, ${entity.type}, ${entity.timestamp}")
        return true
    }

    private fun shouldShowPostCallPrompt(callType: String): Boolean {
        return callType !in setOf("Пропущенный", "Сброшенный", "Неотвеченный")
    }

    private fun showPostCallNow(callId: Long, phone: String, contactName: String) {
        val postCallIntent = Intent(this, PostCallActivity::class.java).apply {
            addFlags(
                Intent.FLAG_ACTIVITY_NEW_TASK or
                    Intent.FLAG_ACTIVITY_CLEAR_TOP or
                    Intent.FLAG_ACTIVITY_SINGLE_TOP or
                    Intent.FLAG_ACTIVITY_NO_USER_ACTION
            )
            putExtra(PostCallActivity.EXTRA_CALL_ID, callId)
            putExtra(PostCallActivity.EXTRA_PHONE, phone)
            putExtra(PostCallActivity.EXTRA_NAME, contactName)
        }

        val pendingIntentFlags = PendingIntent.FLAG_UPDATE_CURRENT or PendingIntent.FLAG_IMMUTABLE
        val fullScreenIntent = PendingIntent.getActivity(this, callId.toInt(), postCallIntent, pendingIntentFlags)

        val manager = getSystemService(NotificationManager::class.java)
        val notificationId = buildPostCallNotificationId(callId)
        val vibrationPattern = longArrayOf(0, 250, 180, 250)
        val notification = NotificationCompat.Builder(this, POST_CALL_CHANNEL_ID)
            .setSmallIcon(R.drawable.ic_clover)
            .setContentTitle("Звонок завершён")
            .setContentText("Заполните результат звонка: $contactName")
            .setCategory(NotificationCompat.CATEGORY_REMINDER)
            .setPriority(NotificationCompat.PRIORITY_HIGH)
            .setOnlyAlertOnce(true)
            // Не открываем full-screen поверх приложения, показываем только обычное уведомление.
            // Уведомление остаётся в шторке, пока пользователь сам не нажмёт или не смахнёт его.
            .setAutoCancel(true)
            .setSound(resolveSoundUri())
            .setVibrate(vibrationPattern)
            .setContentIntent(fullScreenIntent)
            .build()

        manager.notify(notificationId, notification)
    }

    private fun buildPostCallNotificationId(callId: Long): Int {
        val stablePart = (callId and 0x7FFFFFFF).toInt()
        return POST_CALL_NOTIFICATION_ID_BASE + (stablePart % 100000)
    }

    private fun resolveContactName(phone: String): String {
        if (phone.isBlank() || phone == "Неизвестно") return phone
        val uri = Uri.withAppendedPath(ContactsContract.PhoneLookup.CONTENT_FILTER_URI, Uri.encode(phone))
        val projection = arrayOf(ContactsContract.PhoneLookup.DISPLAY_NAME)
        contentResolver.query(uri, projection, null, null, null)?.use { cursor ->
            if (cursor.moveToFirst()) {
                val name = cursor.getString(cursor.getColumnIndexOrThrow(ContactsContract.PhoneLookup.DISPLAY_NAME))
                if (!name.isNullOrBlank()) return name
            }
        }
        return phone
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
            "${CallLog.Calls.DATE} DESC"
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
        val callTypeString = when (typeInt) {
            CallLog.Calls.INCOMING_TYPE -> {
                if (duration < 2L) "Пропущенный" else "Входящий"
            }
            CallLog.Calls.OUTGOING_TYPE -> {
                if (duration < 2L) "Неотвеченный" else "Исходящий"
            }
            CallLog.Calls.MISSED_TYPE -> "Пропущенный"
            CallLog.Calls.REJECTED_TYPE -> "Сброшенный"
            else -> "Неотвеченный"
        }
        Log.d("CALL_TYPE", "Тип: $callTypeString, duration: $duration")
        return callTypeString to ""
    }

    override fun onDestroy() {
        scope.cancel()
        tracker.stop()
        super.onDestroy()
    }

    override fun onBind(intent: Intent?): IBinder? = null

    private fun createChannel() {
        if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.O) {
            val serviceChannel = NotificationChannel("calltrack", "Call Tracking", NotificationManager.IMPORTANCE_LOW)
            val postCallChannel = NotificationChannel(
                POST_CALL_CHANNEL_ID,
                "Post-call",
                NotificationManager.IMPORTANCE_HIGH
            ).apply {
                lockscreenVisibility = Notification.VISIBILITY_PUBLIC
                enableVibration(true)
                setVibrationPattern(longArrayOf(0, 250, 180, 250))
                setSound(
                    resolveSoundUri(),
                    AudioAttributes.Builder()
                        .setUsage(AudioAttributes.USAGE_NOTIFICATION)
                        .setContentType(AudioAttributes.CONTENT_TYPE_SONIFICATION)
                        .build()
                )
            }
            getSystemService(NotificationManager::class.java).apply {
                createNotificationChannel(serviceChannel)
                createNotificationChannel(postCallChannel)
            }
        }
    }

    private fun createNotification(text: String): Notification {
        return NotificationCompat.Builder(this, "calltrack")
            .setContentTitle("Calltrack")
            .setContentText(text)
            .setSmallIcon(R.drawable.ic_clover)
            .build()
    }

    private fun resolveSoundUri(): Uri {
        val resId = resources.getIdentifier("voice", "raw", packageName)
        if (resId != 0) return Uri.parse("android.resource://$packageName/$resId")
        return RingtoneManager.getDefaultUri(RingtoneManager.TYPE_NOTIFICATION)
    }

    companion object {
        private const val POST_CALL_CHANNEL_ID = "postcall"
        private const val POST_CALL_NOTIFICATION_ID_BASE = 1000
    }
}
