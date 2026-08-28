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
import androidx.annotation.RequiresApi
import androidx.core.app.NotificationCompat
import androidx.core.content.ContextCompat
import com.example.calltrack.App
import com.example.calltrack.R
import com.example.calltrack.data.local.CallEntity
import com.example.calltrack.data.local.NotificationEntity
import com.example.calltrack.data.local.NotificationType
import com.example.calltrack.data.notification.NotificationTargets
import com.example.calltrack.logging.AppLogger
import com.example.calltrack.telephony.CallStateTracker
import com.example.calltrack.ui.postcall.PostCallActivity
import kotlinx.coroutines.CoroutineScope
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.SupervisorJob
import kotlinx.coroutines.cancel
import kotlinx.coroutines.CompletableDeferred
import kotlinx.coroutines.delay
import kotlinx.coroutines.launch
import kotlinx.coroutines.isActive
import java.util.concurrent.atomic.AtomicBoolean
import org.json.JSONObject

class CallTrackingService : Service() {
    private val scope = CoroutineScope(SupervisorJob() + Dispatchers.IO)
    private lateinit var tracker: CallStateTracker
    private var lastStateWasActive = false
    @Volatile
    private var lastHandledTimestamp: Long = 0L
    private val initialTimestampReady = CompletableDeferred<Unit>()
    private val captureInProgress = AtomicBoolean(false)


    override fun onStartCommand(intent: Intent?, flags: Int, startId: Int): Int {
        when (intent?.action) {
            ACTION_MARK_PERSONAL_FROM_NOTIFICATION -> {
                val phone = intent?.getStringExtra(EXTRA_NOTIFICATION_PHONE).orEmpty()
                if (phone.isNotBlank()) {
                    scope.launch {
                        runCatching {
                            val repository = (application as App).repository
                            repository.markAsPersonalContact(phone)
                            repository.markCallsPendingForPhoneResync(phone)
                            AppLogger.log(this@CallTrackingService, "UI", "\u041F\u043E\u043C\u0435\u0442\u043A\u0430 \u043B\u0438\u0447\u043D\u043E\u0433\u043E \u043A\u043E\u043D\u0442\u0430\u043A\u0442\u0430 \u0438\u0437 \u0443\u0432\u0435\u0434\u043E\u043C\u043B\u0435\u043D\u0438\u044F: $phone")
                        }
                    }
                    getSystemService(NotificationManager::class.java)
                        .cancel(MISSING_CLIENT_NOTIFICATION_ID)
                }
            }
        }
        return START_STICKY
    }

    override fun onCreate() {
        super.onCreate()
        StabilityDiagnostics.mark(this, "service_started", "pid=${android.os.Process.myPid()}")
        StabilityDiagnostics.increment(this, "service_restart_count")
        createChannel()

        val foregroundResult = runCatching {
            startForeground(101, createNotification("\u041F\u0440\u0438\u043B\u043E\u0436\u0435\u043D\u0438\u0435 \u0430\u043A\u0442\u0438\u0432\u043D\u043E"))
        }
        if (foregroundResult.isFailure) {
            AppLogger.log(this, "ERROR", "\u041D\u0435 \u0443\u0434\u0430\u043B\u043E\u0441\u044C \u043F\u0435\u0440\u0435\u0432\u0435\u0441\u0442\u0438 \u0441\u0435\u0440\u0432\u0438\u0441 \u0432 foreground: ${foregroundResult.exceptionOrNull()?.message}", foregroundResult.exceptionOrNull())
            stopSelf()
            return
        }

        // Метку последнего сохранённого звонка читаем асинхронно, чтобы onCreate() сервиса
        // не блокировал главный поток и не мог сам стать причиной ANR.
        val repo = (application as App).repository
        scope.launch {
            while (isActive) {
                StabilityDiagnostics.serviceHeartbeat(this@CallTrackingService)
                delay(SERVICE_HEARTBEAT_INTERVAL_MS)
            }
        }
        scope.launch {
            while (isActive) {
                runCatching { repo.sendUserTelemetry() }
                    .onFailure { error -> AppLogger.log(this@CallTrackingService, "WARN", "\u0424\u043E\u043D\u043E\u0432\u0430\u044F \u043F\u0440\u043E\u0432\u0435\u0440\u043A\u0430 \u043A\u043E\u043C\u0430\u043D\u0434 \u0437\u0430\u0432\u0435\u0440\u0448\u0438\u043B\u0430\u0441\u044C \u043E\u0448\u0438\u0431\u043A\u043E\u0439: ${error.message}", error) }
                delay(BACKGROUND_COMMAND_POLL_INTERVAL_MS)
            }
        }
        scope.launch {
            val startedAt = System.currentTimeMillis()
            AppLogger.log(this@CallTrackingService, "PERF", "initLastHandledTimestamp started")
            runCatching { repo.getLatestSavedCallTimestamp() }
                .onSuccess { timestamp ->
                    lastHandledTimestamp = maxOf(lastHandledTimestamp, timestamp)
                    AppLogger.log(
                        this@CallTrackingService,
                        "PERF",
                        "initLastHandledTimestamp finished in ${System.currentTimeMillis() - startedAt} ms, ts=$lastHandledTimestamp"
                    )
                }
                .onFailure { error ->
                    AppLogger.log(
                        this@CallTrackingService,
                        "PERF",
                        "initLastHandledTimestamp failed in ${System.currentTimeMillis() - startedAt} ms: ${error.message}"
                    )
                }
            if (!initialTimestampReady.isCompleted) {
                initialTimestampReady.complete(Unit)
            }
        }

        tracker = CallStateTracker(this) { state, _ ->
            StabilityDiagnostics.mark(this, "tracker_event", "state=$state")
            when (state) {
                TelephonyManager.CALL_STATE_RINGING,
                TelephonyManager.CALL_STATE_OFFHOOK -> {
                    lastStateWasActive = true
                    AppLogger.log(this, "CALL", "\u041D\u0430\u0447\u0430\u043B\u043E \u0437\u0432\u043E\u043D\u043A\u0430: unknown")
                }

                TelephonyManager.CALL_STATE_IDLE -> {
                    if (lastStateWasActive) {
                        lastStateWasActive = false
                        AppLogger.log(this, "CALL", "\u0417\u0430\u0432\u0435\u0440\u0448\u0435\u043D\u0438\u0435 \u0437\u0432\u043E\u043D\u043A\u0430: unknown")
                        scope.launch {
                            captureLatestCallWithRetry()
                        }
                    }
                }
            }
        }
        runCatching { tracker.start() }
            .onSuccess {
                StabilityDiagnostics.mark(this, "tracker_started")
                AppLogger.log(this, "STABILITY", "\u041E\u0442\u0441\u043B\u0435\u0436\u0438\u0432\u0430\u043D\u0438\u0435 \u0437\u0432\u043E\u043D\u043A\u043E\u0432 \u0437\u0430\u043F\u0443\u0449\u0435\u043D\u043E")
            }
            .onFailure { error ->
                AppLogger.log(this, "ERROR", "\u041D\u0435 \u0443\u0434\u0430\u043B\u043E\u0441\u044C \u043F\u043E\u0434\u043F\u0438\u0441\u0430\u0442\u044C\u0441\u044F \u043D\u0430 \u0441\u043E\u0441\u0442\u043E\u044F\u043D\u0438\u0435 \u0437\u0432\u043E\u043D\u043A\u043E\u0432: ${error.message}", error)
                stopSelf()
            }
    }

    private suspend fun captureLatestCallWithRetry() {
        if (!captureInProgress.compareAndSet(false, true)) {
            AppLogger.log(this, "PERF", "captureLatestCallWithRetry skipped: previous capture is still running")
            return
        }

        val startedAt = System.currentTimeMillis()
        StabilityDiagnostics.mark(this, "call_capture_started")
        var attempts = 0
        var captured = false
        AppLogger.log(this, "PERF", "captureLatestCallWithRetry started")
        try {
            initialTimestampReady.await()
            // На некоторых устройствах CallLog обновляется с задержкой, поэтому ждём дольше,
            // чтобы не схватить предыдущий звонок вместо только что завершённого.
            while (attempts < CALL_CAPTURE_RETRY_COUNT && !captured) {
                attempts++
                captured = captureLatestCallIfNew()
                if (!captured && attempts < CALL_CAPTURE_RETRY_COUNT) {
                    delay(CALL_CAPTURE_RETRY_DELAY_MS)
                }
            }
        } finally {
            AppLogger.log(
                this,
                "PERF",
                "captureLatestCallWithRetry finished in ${System.currentTimeMillis() - startedAt} ms, attempts=$attempts, captured=$captured"
            )
            StabilityDiagnostics.mark(this, "call_capture_finished", "attempts=$attempts; captured=$captured")
            captureInProgress.set(false)
        }
    }

    private suspend fun captureLatestCallIfNew(): Boolean {
        val startedAt = System.currentTimeMillis()
        var readCalls = 0
        var processedCalls = 0
        AppLogger.log(this, "PERF", "captureLatestCallIfNew started, minTs=$lastHandledTimestamp")

        return try {
            val entities = readLatestCallEntitiesAfter(lastHandledTimestamp)
            readCalls = entities.size
            if (entities.isEmpty()) return false

            val repo = (application as App).repository
            val personalContactCache = mutableMapOf<String, Boolean>()
            val contactNameCache = mutableMapOf<String, String>()
            var latestSavedCallId = 0L
            var latestSavedEntity: CallEntity? = null
            var hasNewData = false

            suspend fun isPersonalContactCached(phone: String): Boolean {
                personalContactCache[phone]?.let { return it }
                val value = repo.isPersonalContact(phone)
                personalContactCache[phone] = value
                return value
            }

            fun resolveContactNameCached(phone: String): String {
                return contactNameCache.getOrPut(phone) { resolveContactName(phone) }
            }

            entities.forEach { entity ->
                if (entity.timestamp <= lastHandledTimestamp) return@forEach
                hasNewData = true
                processedCalls++
                lastHandledTimestamp = maxOf(lastHandledTimestamp, entity.timestamp)
                AppLogger.log(this, "CALL", "\u0421\u043E\u0445\u0440\u0430\u043D\u0435\u043D\u0438\u0435 \u0437\u0432\u043E\u043D\u043A\u0430 \u0432 \u043B\u043E\u043A\u0430\u043B\u044C\u043D\u0443\u044E \u0411\u0414")
                latestSavedCallId = repo.saveCall(entity)
                latestSavedEntity = entity
                AppLogger.log(this, "CALL", "\u0417\u0430\u0432\u0435\u0440\u0448\u0435\u043D\u0438\u0435 \u0437\u0432\u043E\u043D\u043A\u0430: ${entity.phone} \u0434\u043B\u0438\u0442\u0435\u043B\u044C\u043D\u043E\u0441\u0442\u044C=${entity.duration} \u0442\u0438\u043F=${entity.type}")
                Log.d("WEBHOOK", "\u0421\u0440\u0430\u0437\u0443 \u043E\u0442\u043F\u0440\u0430\u0432\u043B\u044F\u0435\u043C \u0437\u0430\u0432\u0435\u0440\u0448\u0451\u043D\u043D\u044B\u0439 \u0437\u0432\u043E\u043D\u043E\u043A \u0432 SQL API: id=$latestSavedCallId")
                val syncStartedAt = System.currentTimeMillis()
                AppLogger.log(this, "PERF", "syncCallById started, id=$latestSavedCallId")
                runCatching { repo.syncCallById(latestSavedCallId) }
                    .onSuccess {
                        AppLogger.log(
                            this,
                            "PERF",
                            "syncCallById finished in ${System.currentTimeMillis() - syncStartedAt} ms, id=$latestSavedCallId"
                        )
                    }
                    .onFailure { error ->
                        AppLogger.log(
                            this,
                            "PERF",
                            "syncCallById failed in ${System.currentTimeMillis() - syncStartedAt} ms, id=$latestSavedCallId: ${error.message}"
                        )
                        Log.e("WEBHOOK", "\u041D\u0435 \u0443\u0434\u0430\u043B\u043E\u0441\u044C \u0441\u0440\u0430\u0437\u0443 \u043E\u0442\u043F\u0440\u0430\u0432\u0438\u0442\u044C \u0437\u0432\u043E\u043D\u043E\u043A: id=$latestSavedCallId", error)
                    }

                val isPersonal = isPersonalContactCached(entity.phone)
                if (!isPersonal && shouldShowPostCallPrompt(entity.type)) {
                    val contactName = resolveContactNameCached(entity.phone)
                    showPostCallNow(latestSavedCallId, entity.phone, contactName)
                }
            }
            if (!hasNewData) return false

            val finalEntity = latestSavedEntity ?: return false
            val isFinalPersonal = isPersonalContactCached(finalEntity.phone)
            val clientName = repo.findClientName(finalEntity.phone)

            Log.d("WEBHOOK", "\u0417\u0430\u0432\u0435\u0440\u0448\u0451\u043D\u043D\u044B\u0435 \u0437\u0432\u043E\u043D\u043A\u0438 \u043E\u0431\u0440\u0430\u0431\u043E\u0442\u0430\u043D\u044B \u0438 \u043E\u0442\u043F\u0440\u0430\u0432\u043B\u0435\u043D\u044B \u0442\u043E\u0447\u0435\u0447\u043D\u043E\u0439 \u0441\u0438\u043D\u0445\u0440\u043E\u043D\u0438\u0437\u0430\u0446\u0438\u0435\u0439")

            if (!isFinalPersonal && clientName.isBlank()) {
                val missingClientLabel = resolveContactNameCached(finalEntity.phone)
                AppLogger.log(this, "NOTIFY", "\u041F\u043E\u043A\u0430\u0437 \u0443\u0432\u0435\u0434\u043E\u043C\u043B\u0435\u043D\u0438\u044F: \u043A\u043B\u0438\u0435\u043D\u0442 $missingClientLabel \u043D\u0435 \u043D\u0430\u0439\u0434\u0435\u043D \u0432 \u0431\u0430\u0437\u0435 1\u0441")
                showMissingClientNotification(finalEntity.phone, missingClientLabel)
            }
            Log.d("CallTrackingService", "Calls captured count=${entities.size}, latest=${finalEntity.phone}, ts=${finalEntity.timestamp}")
            true
        } finally {
            AppLogger.log(
                this,
                "PERF",
                "captureLatestCallIfNew finished in ${System.currentTimeMillis() - startedAt} ms, read=$readCalls, calls=$processedCalls"
            )
        }
    }

    private fun shouldShowPostCallPrompt(callType: String): Boolean {
        return callType !in setOf("\u041F\u0440\u043E\u043F\u0443\u0449\u0435\u043D\u043D\u044B\u0439", "\u0421\u0431\u0440\u043E\u0448\u0435\u043D\u043D\u044B\u0439", "\u041D\u0435\u043E\u0442\u0432\u0435\u0447\u0435\u043D\u043D\u044B\u0439")
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
            .setContentTitle("\u0417\u0432\u043E\u043D\u043E\u043A \u0437\u0430\u0432\u0435\u0440\u0448\u0451\u043D")
            .setContentText("\u0417\u0430\u043F\u043E\u043B\u043D\u0438\u0442\u0435 \u0440\u0435\u0437\u0443\u043B\u044C\u0442\u0430\u0442 \u0437\u0432\u043E\u043D\u043A\u0430: $contactName")
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
        saveNotificationCenterItem(
            title = "\u0417\u0432\u043E\u043D\u043E\u043A \u0437\u0430\u0432\u0435\u0440\u0448\u0451\u043D",
            message = "\u0417\u0430\u043F\u043E\u043B\u043D\u0438\u0442\u0435 \u0440\u0435\u0437\u0443\u043B\u044C\u0442\u0430\u0442 \u0437\u0432\u043E\u043D\u043A\u0430: $contactName",
            type = NotificationType.CALLBACK,
            targetScreen = NotificationTargets.CALL_DETAIL,
            entityId = callId,
            payloadJson = JSONObject().apply {
                put("phone", phone)
                put("name", contactName)
                put("call_id", callId)
            }.toString()
        )
    }

    private fun showMissingClientNotification(phone: String, clientLabel: String) {
        val manager = getSystemService(NotificationManager::class.java)
        val displayClient = clientLabel.ifBlank { phone }
        val message = "\u041A\u043B\u0438\u0435\u043D\u0442 $displayClient \u043D\u0435 \u043D\u0430\u0439\u0434\u0435\u043D \u0432 \u0431\u0430\u0437\u0435 1\u0441. \u0412\u044B\u0431\u0435\u0440\u0438\u0442\u0435 \u0434\u0435\u0439\u0441\u0442\u0432\u0438\u0435"
        val openIntent = Intent(this, com.example.calltrack.ui.contactcard.ContactActionActivity::class.java).apply {
            addFlags(Intent.FLAG_ACTIVITY_NEW_TASK or Intent.FLAG_ACTIVITY_CLEAR_TOP or Intent.FLAG_ACTIVITY_SINGLE_TOP)
            putExtra(com.example.calltrack.ui.contactcard.ContactActionActivity.EXTRA_PHONE, phone)
        }
        val openPending = PendingIntent.getActivity(
            this,
            phone.hashCode(),
            openIntent,
            PendingIntent.FLAG_UPDATE_CURRENT or PendingIntent.FLAG_IMMUTABLE
        )

        val personalIntent = Intent(this, CallTrackingService::class.java).apply {
            action = ACTION_MARK_PERSONAL_FROM_NOTIFICATION
            putExtra(EXTRA_NOTIFICATION_PHONE, phone)
        }
        val personalPending = PendingIntent.getService(
            this,
            phone.hashCode() + 1,
            personalIntent,
            PendingIntent.FLAG_UPDATE_CURRENT or PendingIntent.FLAG_IMMUTABLE
        )

        val addTo1cIntent = Intent(this, com.example.calltrack.ui.contactcard.ContactActionActivity::class.java).apply {
            addFlags(Intent.FLAG_ACTIVITY_NEW_TASK or Intent.FLAG_ACTIVITY_CLEAR_TOP or Intent.FLAG_ACTIVITY_SINGLE_TOP)
            putExtra(com.example.calltrack.ui.contactcard.ContactActionActivity.EXTRA_PHONE, phone)
            putExtra(com.example.calltrack.ui.contactcard.ContactActionActivity.EXTRA_SHOW_ADD_TO_1C_DIALOG, true)
        }
        val addTo1cPending = PendingIntent.getActivity(
            this,
            phone.hashCode() + 2,
            addTo1cIntent,
            PendingIntent.FLAG_UPDATE_CURRENT or PendingIntent.FLAG_IMMUTABLE
        )

        val notification = NotificationCompat.Builder(this, POST_CALL_CHANNEL_ID)
            .setSmallIcon(R.drawable.ic_clover)
            .setContentTitle("\u041A\u043B\u0438\u0435\u043D\u0442 $displayClient \u043D\u0435 \u043D\u0430\u0439\u0434\u0435\u043D")
            .setContentText(message)
            .setStyle(NotificationCompat.BigTextStyle().bigText(message))
            .setCategory(NotificationCompat.CATEGORY_REMINDER)
            .setPriority(NotificationCompat.PRIORITY_HIGH)
            .setAutoCancel(true)
            .setContentIntent(openPending)
            .addAction(0, "\u041F\u043E\u043C\u0435\u0442\u0438\u0442\u044C \u043A\u0430\u043A \u043B\u0438\u0447\u043D\u044B\u0439 \u043A\u043E\u043D\u0442\u0430\u043A\u0442", personalPending)
            .addAction(0, "\u0414\u043E\u0431\u0430\u0432\u0438\u0442\u044C \u0432 1\u0441", addTo1cPending)
            .build()

        manager.notify(MISSING_CLIENT_NOTIFICATION_ID, notification)
        saveNotificationCenterItem(
            title = "\u041A\u043B\u0438\u0435\u043D\u0442 $displayClient \u043D\u0435 \u043D\u0430\u0439\u0434\u0435\u043D",
            message = message,
            type = NotificationType.MISSING_CLIENT,
            targetScreen = NotificationTargets.PERSONAL_CONTACT,
            payloadJson = JSONObject().apply {
                put("phone", phone)
                put("client", displayClient)
            }.toString()
        )
    }

    private fun saveNotificationCenterItem(
        title: String,
        message: String,
        type: NotificationType,
        targetScreen: String,
        entityId: Long? = null,
        payloadJson: String = ""
    ) {
        val repository = (application as? App)?.notificationRepository ?: return
        scope.launch {
            repository.insertNotification(
                NotificationEntity(
                    title = title,
                    message = message,
                    type = type,
                    targetScreen = targetScreen,
                    entityId = entityId,
                    payloadJson = payloadJson
                )
            )
        }
    }

    private fun buildPostCallNotificationId(callId: Long): Int {
        val stablePart = (callId and 0x7FFFFFFF).toInt()
        return POST_CALL_NOTIFICATION_ID_BASE + (stablePart % 100000)
    }

    private fun resolveContactName(phone: String): String {
        if (phone.isBlank() || phone == "\u041D\u0435\u0438\u0437\u0432\u0435\u0441\u0442\u043D\u043E") return phone
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

    private fun readLatestCallEntitiesAfter(minTimestampExclusive: Long): List<CallEntity> {
        val startedAt = System.currentTimeMillis()
        var scannedRows = 0
        AppLogger.log(this, "PERF", "readLatestCallEntitiesAfter started, minTs=$minTimestampExclusive")

        if (ContextCompat.checkSelfPermission(this, Manifest.permission.READ_CALL_LOG) != PackageManager.PERMISSION_GRANTED) {
            AppLogger.log(
                this,
                "PERF",
                "readLatestCallEntitiesAfter finished in ${System.currentTimeMillis() - startedAt} ms, scanned=0, calls=0, no READ_CALL_LOG permission"
            )
            return emptyList()
        }

        val projection = arrayOf(
            CallLog.Calls.NUMBER,
            CallLog.Calls.TYPE,
            CallLog.Calls.DURATION,
            CallLog.Calls.DATE
        )

        val result = mutableListOf<CallEntity>()
        val selection = "${CallLog.Calls.DATE} > ?"
        val selectionArgs = arrayOf(minTimestampExclusive.toString())
        runCatching {
            contentResolver.query(
                CallLog.Calls.CONTENT_URI,
                projection,
                selection,
                selectionArgs,
                "${CallLog.Calls.DATE} DESC"
            )?.use { cursor ->
                while (cursor.moveToNext() && scannedRows < CALL_LOG_QUERY_LIMIT) {
                    scannedRows++
                    val number = cursor.getString(cursor.getColumnIndexOrThrow(CallLog.Calls.NUMBER)).orEmpty()
                    val typeInt = cursor.getInt(cursor.getColumnIndexOrThrow(CallLog.Calls.TYPE))
                    val duration = cursor.getLong(cursor.getColumnIndexOrThrow(CallLog.Calls.DURATION))
                    val timestamp = cursor.getLong(cursor.getColumnIndexOrThrow(CallLog.Calls.DATE))

                    if (timestamp > minTimestampExclusive) {
                        val (type, note) = mapCallType(typeInt, duration)
                        result.add(
                            CallEntity(
                                phone = if (number.isBlank()) "\u041D\u0435\u0438\u0437\u0432\u0435\u0441\u0442\u043D\u043E" else number,
                                type = type,
                                duration = duration,
                                note = note,
                                timestamp = timestamp
                            )
                        )
                    }
                }
            }
        }.onFailure { error ->
            AppLogger.log(this, "PERF", "readLatestCallEntitiesAfter query failed: ${error.message}")
        }

        val sortedResult = result.sortedBy { it.timestamp }
        AppLogger.log(
            this,
            "PERF",
            "readLatestCallEntitiesAfter finished in ${System.currentTimeMillis() - startedAt} ms, scanned=$scannedRows, calls=${sortedResult.size}"
        )
        return sortedResult
    }

    private fun mapCallType(typeInt: Int, duration: Long): Pair<String, String> {
        val callTypeString = when (typeInt) {
            CallLog.Calls.INCOMING_TYPE -> {
                if (duration < 2L) "\u041F\u0440\u043E\u043F\u0443\u0449\u0435\u043D\u043D\u044B\u0439" else "\u0412\u0445\u043E\u0434\u044F\u0449\u0438\u0439"
            }
            CallLog.Calls.OUTGOING_TYPE -> {
                if (duration < 2L) "\u041D\u0435\u043E\u0442\u0432\u0435\u0447\u0435\u043D\u043D\u044B\u0439" else "\u0418\u0441\u0445\u043E\u0434\u044F\u0449\u0438\u0439"
            }
            CallLog.Calls.MISSED_TYPE -> "\u041F\u0440\u043E\u043F\u0443\u0449\u0435\u043D\u043D\u044B\u0439"
            CallLog.Calls.REJECTED_TYPE -> "\u0421\u0431\u0440\u043E\u0448\u0435\u043D\u043D\u044B\u0439"
            else -> "\u041D\u0435\u043E\u0442\u0432\u0435\u0447\u0435\u043D\u043D\u044B\u0439"
        }
        Log.d("CALL_TYPE", "\u0422\u0438\u043F: $callTypeString, duration: $duration")
        return callTypeString to ""
    }

    @RequiresApi(Build.VERSION_CODES.VANILLA_ICE_CREAM)
    override fun onTimeout(startId: Int, fgsType: Int) {
        // Android 15 завершает foreground service с тайм-аутом, если приложение само не остановится.
        // Явно убираем сервис из foreground и завершаем его, чтобы система не уронила процесс.
        StabilityDiagnostics.mark(this, "service_timeout", "startId=$startId; type=$fgsType")
        stopForeground(STOP_FOREGROUND_REMOVE)
        stopSelf(startId)
    }

    override fun onDestroy() {
        StabilityDiagnostics.mark(this, "service_destroyed")
        AppLogger.log(this, "STABILITY", "\u0421\u0435\u0440\u0432\u0438\u0441 \u043E\u0442\u0441\u043B\u0435\u0436\u0438\u0432\u0430\u043D\u0438\u044F \u043E\u0441\u0442\u0430\u043D\u043E\u0432\u043B\u0435\u043D; \u0432\u043E\u0441\u0441\u0442\u0430\u043D\u043E\u0432\u043B\u0435\u043D\u0438\u0435 \u043A\u043E\u043D\u0442\u0440\u043E\u043B\u0438\u0440\u0443\u0435\u0442 WorkManager")
        scope.cancel()
        if (::tracker.isInitialized) {
            runCatching { tracker.stop() }
                .onFailure { error -> AppLogger.log(this, "WARN", "\u041E\u0448\u0438\u0431\u043A\u0430 \u043E\u0441\u0442\u0430\u043D\u043E\u0432\u043A\u0438 \u043D\u0430\u0431\u043B\u044E\u0434\u0435\u043D\u0438\u044F \u0437\u0430 \u0437\u0432\u043E\u043D\u043A\u0430\u043C\u0438: ${error.message}", error) }
        }
        super.onDestroy()
    }

    override fun onTaskRemoved(rootIntent: Intent?) {
        StabilityDiagnostics.mark(this, "task_removed")
        super.onTaskRemoved(rootIntent)
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
        private const val SERVICE_HEARTBEAT_INTERVAL_MS = 60_000L
        const val ACTION_MARK_PERSONAL_FROM_NOTIFICATION =
            "com.example.calltrack.ACTION_MARK_PERSONAL_FROM_NOTIFICATION"

        const val EXTRA_NOTIFICATION_PHONE =
            "extra_notification_phone"

        const val MISSING_CLIENT_NOTIFICATION_ID = 2001

        private const val POST_CALL_CHANNEL_ID = "postcall"
        private const val POST_CALL_NOTIFICATION_ID_BASE = 1000
        private const val CALL_CAPTURE_RETRY_COUNT = 25
        private const val CALL_CAPTURE_RETRY_DELAY_MS = 300L
        private const val CALL_LOG_QUERY_LIMIT = 50
        private const val BACKGROUND_COMMAND_POLL_INTERVAL_MS = 5 * 60 * 1000L
    }
}
