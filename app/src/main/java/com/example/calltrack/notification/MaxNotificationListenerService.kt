package com.example.calltrack.notification

import android.app.Notification
import android.app.Person
import android.os.Build
import android.os.Bundle
import android.service.notification.NotificationListenerService
import android.service.notification.StatusBarNotification
import android.util.Log
import com.example.calltrack.App
import com.example.calltrack.auth.AuthStore
import com.example.calltrack.data.local.CallEntity
import com.example.calltrack.service.CalltrackRecoveryManager
import kotlinx.coroutines.CoroutineScope
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.SupervisorJob
import kotlinx.coroutines.cancel
import kotlinx.coroutines.launch

/** Production-обработчик звонков MAX. Уведомления других приложений игнорируются. */
class MaxNotificationListenerService : NotificationListenerService() {

    private val scope = CoroutineScope(SupervisorJob() + Dispatchers.IO)
    private lateinit var sessionStore: MaxCallSessionStore
    private var session: MaxCallSession? = null
    private var resolution: ResolvedContact? = null

    override fun onCreate() {
        super.onCreate()
        sessionStore = MaxCallSessionStore(this)
        session = sessionStore.load()
        sessionStore.loadCompleted()?.let(::queueCompletedCall)
        // Уже сохранённые в Room звонки повторно отправит общая очередь.
        CalltrackRecoveryManager.schedulePendingSync(this)
    }

    override fun onDestroy() {
        scope.cancel()
        super.onDestroy()
    }

    override fun onNotificationPosted(sbn: StatusBarNotification?) {
        if (sbn?.packageName != MAX_PACKAGE) return
        runCatching { handlePosted(sbn) }
            .onFailure { Log.e(TAG, "event=POSTED error=${safeError(it)}") }
    }

    override fun onNotificationRemoved(sbn: StatusBarNotification?) {
        if (sbn?.packageName != MAX_PACKAGE) return
        runCatching { finishSession(sbn) }
            .onFailure { Log.e(TAG, "event=REMOVED error=${safeError(it)}") }
    }

    private fun handlePosted(sbn: StatusBarNotification) {
        val extras = sbn.notification.extras ?: Bundle.EMPTY
        if (!isMaxCallNotification(extras)) return
        val name = extractCallPerson(extras)?.name?.toString()?.trim().orEmpty()
        if (name.isEmpty()) return
        updateSession(sbn, extras, name)
    }

    private fun updateSession(sbn: StatusBarNotification, extras: Bundle, name: String) {
        val callType = extras.safeInt(CALL_TYPE_KEY) ?: return
        val previous = session?.takeIf { it.notificationKey == sbn.key }
        val updated = MaxCallStateMachine.posted(
            current = previous,
            notificationKey = sbn.key,
            contactName = name,
            callType = callType,
            isVideo = extras.safeBoolean(CALL_IS_VIDEO_KEY),
            at = System.currentTimeMillis()
        ) ?: return

        if (previous == null) {
            resolution = MaxContactResolver(this).resolve(name)
            Log.i(TAG, "sessionCreated direction=${updated.direction.name.lowercase()}")
            Log.i(TAG, "contactResolved status=${resolution?.status?.wireValue}")
        } else if (previous.answeredAt == null && updated.answeredAt != null) {
            Log.i(TAG, "callAnswered")
        }
        session = updated
        sessionStore.save(updated)
    }

    private fun finishSession(sbn: StatusBarNotification) {
        val current = session?.takeIf { it.notificationKey == sbn.key } ?: return
        session = null
        sessionStore.clearActive()
        val result = current.finish(System.currentTimeMillis())
        sessionStore.saveCompleted(result)
        Log.i(TAG, "callEnded status=${result.status.name.lowercase()} duration=${result.durationSeconds}")
        queueCompletedCall(result)
    }

    private fun queueCompletedCall(result: MaxCallResult) {
        scope.launch {
            runCatching {
                val contact = resolution ?: MaxContactResolver(this@MaxNotificationListenerService)
                    .resolve(result.maxContactName)
                resolution = null
                val repository = (application as App).repository
                val entity = CallEntity(
                    phone = contact.phone?.let(repository::normalizePhone).orEmpty(),
                    type = when {
                        result.direction == MaxCallDirection.OUTGOING -> "Исходящий"
                        result.status == MaxCallStatus.MISSED -> "Пропущенный"
                        else -> "Входящий"
                    },
                    duration = result.durationSeconds,
                    note = "",
                    timestamp = result.startedAt,
                    source = "max",
                    sourceEventId = result.sourceEventId,
                    contactName = result.maxContactName,
                    direction = result.direction.name.lowercase(),
                    status = result.status.name.lowercase(),
                    answeredAt = result.answeredAt,
                    endedAt = result.endedAt,
                    ringingDurationSeconds = result.ringingDurationSeconds,
                    isVideo = result.isVideo,
                    contactResolutionStatus = contact.status.wireValue
                )
                val id = repository.saveCall(entity)
                sessionStore.clearCompleted(result.sourceEventId)
                Log.i(TAG, "callQueued sourceEventId=${result.sourceEventId}")
                CalltrackRecoveryManager.schedulePendingSync(this@MaxNotificationListenerService)
                if (AuthStore(this@MaxNotificationListenerService).isAuthenticated) {
                    if (repository.syncCallById(id)) {
                        Log.i(TAG, "callUploaded sourceEventId=${result.sourceEventId}")
                    } else {
                        Log.w(TAG, "callUploadFailed sourceEventId=${result.sourceEventId}")
                    }
                }
            }.onFailure {
                // Completed result остаётся в store и будет восстановлен при новом создании сервиса.
                Log.e(TAG, "callUploadFailed sourceEventId=${result.sourceEventId} error=${safeError(it)}")
                CalltrackRecoveryManager.schedulePendingSync(this@MaxNotificationListenerService)
            }
        }
    }

    private fun isMaxCallNotification(extras: Bundle): Boolean =
        extras.safeText(Notification.EXTRA_TEMPLATE) == CALL_STYLE_TEMPLATE ||
            extras.containsKey(CALL_TYPE_KEY) || extras.containsKey(CALL_PERSON_KEY)

    private fun Bundle.safeText(key: String): String? =
        runCatching { getCharSequence(key)?.toString() }.getOrNull()

    private fun Bundle.safeInt(key: String): Int? = runCatching {
        when (val value = get(key)) {
            is Number -> value.toInt()
            is String -> value.toIntOrNull()
            else -> null
        }
    }.getOrNull()

    private fun Bundle.safeBoolean(key: String): Boolean = runCatching {
        when (val value = get(key)) {
            is Boolean -> value
            is Number -> value.toInt() != 0
            is String -> value.toBooleanStrictOrNull() ?: false
            else -> false
        }
    }.getOrDefault(false)

    @Suppress("DEPRECATION")
    private fun extractCallPerson(extras: Bundle): Person? {
        if (Build.VERSION.SDK_INT < Build.VERSION_CODES.P) return null
        return runCatching {
            if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.TIRAMISU) {
                extras.getParcelable(CALL_PERSON_KEY, Person::class.java)
            } else {
                extras.getParcelable(CALL_PERSON_KEY) as? Person
            }
        }.getOrNull()
    }

    private fun safeError(error: Throwable): String =
        "${error::class.java.simpleName}:${error.message.orEmpty()}"

    companion object {
        private const val TAG = "CalltrackMAX"
        private const val MAX_PACKAGE = "ru.oneme.app"
        private const val CALL_STYLE_TEMPLATE = "android.app.Notification\$CallStyle"
        private const val CALL_TYPE_KEY = "android.callType"
        private const val CALL_IS_VIDEO_KEY = "android.callIsVideo"
        private const val CALL_PERSON_KEY = "android.callPerson"
    }
}
