package com.example.calltrack.notification

import android.app.Notification
import android.app.Person
import android.os.Build
import android.os.Bundle
import android.service.notification.NotificationListenerService
import android.service.notification.StatusBarNotification
import android.util.Log

/** Временная диагностика уведомлений MAX. Данные выводятся только в Logcat. */
class MaxNotificationListenerService : NotificationListenerService() {

    override fun onNotificationPosted(sbn: StatusBarNotification?) {
        if (sbn?.packageName != MAX_PACKAGE) return
        runCatching { logPosted(sbn) }
            .onFailure { Log.e(TAG, "event=POSTED diagnosticError=${safeError(it)}") }
    }

    override fun onNotificationRemoved(sbn: StatusBarNotification?) {
        if (sbn?.packageName != MAX_PACKAGE) return
        runCatching {
            Log.i(TAG, buildString {
                append("event=REMOVED")
                append(" packageName=").append(sbn.packageName)
                append(" id=").append(sbn.id)
                append(" tag=").append(sbn.tag)
                append(" key=").append(sbn.key)
                append(" postTime=").append(sbn.postTime)
                append(" removedAt=").append(System.currentTimeMillis())
            })
        }.onFailure { Log.e(TAG, "event=REMOVED diagnosticError=${safeError(it)}") }
    }

    private fun logPosted(sbn: StatusBarNotification) {
        val notification = sbn.notification
        val extras = notification.extras ?: Bundle.EMPTY
        val person = extractCallPerson(extras)
        Log.i(TAG, buildString {
            append("event=POSTED")
            append(" packageName=").append(sbn.packageName)
            append(" id=").append(sbn.id)
            append(" tag=").append(sbn.tag)
            append(" key=").append(sbn.key)
            append(" postTime=").append(sbn.postTime)
            append(" channelId=").append(notification.channelId)
            append(" category=").append(notification.category)
            append(" flags=").append(notification.flags)
            append(" when=").append(notification.`when`)
            append(" isMaxCall=").append(isMaxCallNotification(extras))
            append(" title=").append(extras.safeText(Notification.EXTRA_TITLE))
            append(" text=").append(extras.safeText(Notification.EXTRA_TEXT))
            append(" subText=").append(extras.safeText(Notification.EXTRA_SUB_TEXT))
            append(" infoText=").append(extras.safeText(Notification.EXTRA_INFO_TEXT))
            append(" template=").append(extras.safeText(Notification.EXTRA_TEMPLATE))
            append(" callType=").append(extras.safeScalar(CALL_TYPE_KEY))
            append(" callIsVideo=").append(extras.safeScalar(CALL_IS_VIDEO_KEY))
            append(" extrasKeys=").append(extras.keySet().sorted())
            if (person != null) {
                append(" person.name=").append(person.name)
                append(" person.uri=").append(person.uri)
                append(" person.key=").append(person.key)
                append(" person.isBot=").append(person.isBot)
                append(" person.isImportant=").append(person.isImportant)
            } else {
                append(" person=null")
            }
        })
    }

    private fun isMaxCallNotification(extras: Bundle): Boolean =
        extras.safeText(Notification.EXTRA_TEMPLATE) == CALL_STYLE_TEMPLATE ||
            extras.containsKey(CALL_TYPE_KEY) || extras.containsKey(CALL_PERSON_KEY)

    private fun Bundle.safeText(key: String): String? = runCatching { getCharSequence(key)?.toString() }.getOrNull()

    private fun Bundle.safeScalar(key: String): Any? = runCatching {
        when (val value = get(key)) {
            null, is String, is CharSequence, is Number, is Boolean -> value
            else -> value::class.java.name
        }
    }.getOrNull()

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

    private fun safeError(error: Throwable): String = "${error::class.java.simpleName}:${error.message.orEmpty()}"

    companion object {
        private const val TAG = "CalltrackMAX"
        private const val MAX_PACKAGE = "ru.oneme.app"
        private const val CALL_STYLE_TEMPLATE = "android.app.Notification\$CallStyle"
        private const val CALL_TYPE_KEY = "android.callType"
        private const val CALL_IS_VIDEO_KEY = "android.callIsVideo"
        private const val CALL_PERSON_KEY = "android.callPerson"
    }
}
