package com.example.calltrack.notification

import android.app.Notification
import android.os.Bundle
import android.os.Parcelable
import android.service.notification.StatusBarNotification
import android.util.Log

/** Temporary metadata-only diagnostics. No message detection, persistence or networking. */
internal object MaxNotificationMessageDiagnostics {
    private const val TAG = "CalltrackMAXMessageDiag"
    private const val MAX_PACKAGE = "ru.oneme.app"

    // Unknown application-defined keys may contain user data, so never print their names.
    private val knownExtraKeys = setOf(
        "android.title", "android.title.big", "android.text", "android.subText",
        "android.bigText", "android.infoText", "android.summaryText", "android.textLines",
        "android.messages", "android.messages.historic", "android.messagingUser",
        "android.messagingStyleUser", "android.selfDisplayName", "android.conversationTitle",
        "android.hiddenConversationTitle", "android.isGroupConversation", "android.template",
        "android.people", "android.people.list", "android.largeIcon", "android.largeIcon.big",
        "android.picture", "android.pictureIcon", "android.showWhen", "android.showChronometer",
        "android.chronometerCountDown", "android.progress", "android.progressMax",
        "android.progressIndeterminate", "android.colorized", "android.remoteInputHistory",
        "android.remoteInputHistoryItems", "android.callType", "android.callPerson",
        "android.callIsVideo", "android.verificationIcon", "android.verificationText",
        "android.appInfo", "android.reduced.images", "android.rebuild.contentView",
        "android.rebuild.bigView", "android.rebuild.headsUpView", "android.wearable.EXTENSIONS",
        "android.car.EXTENSIONS", "android.support.actionExtras", "android.support.localOnly",
        "android.support.groupKey", "android.support.isGroupSummary", "android.support.sortKey",
        "android.support.useSideChannel"
    )

    private val knownCategories = setOf(
        "alarm", "call", "email", "err", "event", "location_sharing", "msg", "missed_call",
        "navigation", "progress", "promo", "recommendation", "reminder", "service",
        "social", "status", "stopwatch", "sys", "transport", "workout"
    )

    private val knownTemplates = setOf(
        "android.app.Notification\$MessagingStyle", "android.app.Notification\$CallStyle",
        "android.app.Notification\$BigTextStyle", "android.app.Notification\$BigPictureStyle",
        "android.app.Notification\$InboxStyle", "android.app.Notification\$MediaStyle",
        "android.app.Notification\$DecoratedCustomViewStyle",
        "android.app.Notification\$DecoratedMediaCustomViewStyle"
    )

    fun logPosted(sbn: StatusBarNotification, extras: Bundle) {
        if (sbn.packageName != MAX_PACKAGE) return

        // Malformed parcelables must never prevent the existing call handler from running.
        runCatching {
            val n = sbn.notification
            val schema = extraSchema(extras.keySet()) { key -> extraType(extras, key) }

            Log.i(
                TAG,
                "event=POSTED packageName=$MAX_PACKAGE id=${sbn.id} " +
                    "tagPresent=${sbn.tag != null} keyPresent=${sbn.key != null} " +
                    "groupKeyPresent=${sbn.groupKey != null} isGroup=${sbn.isGroup} " +
                    "isClearable=${sbn.isClearable} isOngoing=${sbn.isOngoing} " +
                    "category=${safeCategory(n.category)} flags=${n.flags} priority=${n.priority} " +
                    "visibility=${n.visibility} groupPresent=${n.group != null} " +
                    "sortKeyPresent=${n.sortKey != null} channelIdPresent=${n.channelId != null} " +
                    "number=${n.number} when=${n.`when`} postTime=${sbn.postTime} " +
                    "timestamp=${System.currentTimeMillis()} " +
                    "containsTitle=${extras.containsKey(Notification.EXTRA_TITLE)} " +
                    "containsText=${extras.containsKey(Notification.EXTRA_TEXT)} " +
                    "containsSubText=${extras.containsKey(Notification.EXTRA_SUB_TEXT)} " +
                    "containsBigText=${extras.containsKey(Notification.EXTRA_BIG_TEXT)} " +
                    "containsMessages=${extras.containsKey(Notification.EXTRA_MESSAGES)} " +
                    "containsMessagingPerson=${extras.containsKey("android.messagingUser")} " +
                    "containsTemplate=${extras.containsKey(Notification.EXTRA_TEMPLATE)} " +
                    "template=${readTemplate(extras)} $schema"
            )
        }.onFailure {
            // Exception messages/stack traces can contain notification values.
            Log.w(TAG, "event=POSTED diagnostics=unavailable")
        }
    }

    internal fun safeCategory(value: String?): String = when {
        value == null -> "null"
        value in knownCategories -> value
        else -> "redacted"
    }

    internal fun safeTemplate(value: Any?): String = when {
        value == null -> "null"
        value is String && value in knownTemplates -> value
        else -> "redacted"
    }

    internal fun extraSchema(
        keys: Set<String>,
        typeForKey: (String) -> String
    ): String {
        val known = keys.filter { it in knownExtraKeys }.sorted()
        val fields = known.joinToString(",") { "$it:${typeForKey(it)}" }

        return "extras=[$fields] unknownExtraKeyCount=${keys.size - known.size}"
    }

    internal fun valueType(value: Any?): String = when (value) {
        null -> "null"
        is String -> "String"
        is CharSequence -> "CharSequence"
        is Boolean -> "Boolean"
        is Byte -> "Byte"
        is Short -> "Short"
        is Int -> "Int"
        is Long -> "Long"
        is Float -> "Float"
        is Double -> "Double"
        is Char -> "Char"
        is Bundle -> "Bundle"

        is Array<*> ->
            if (Parcelable::class.java.isAssignableFrom(value.javaClass.componentType!!)) {
                "Parcelable[]"
            } else {
                "Array"
            }

        is BooleanArray,
        is ByteArray,
        is ShortArray,
        is IntArray,
        is LongArray,
        is FloatArray,
        is DoubleArray,
        is CharArray -> "PrimitiveArray"

        is List<*> -> "List"

        is Parcelable ->
            if (value.javaClass.name == "android.app.Person") {
                "Person"
            } else {
                "Parcelable"
            }

        else -> "Other"
    }

    @Suppress("DEPRECATION")
    private fun extraType(extras: Bundle, key: String): String =
        runCatching {
            valueType(extras.get(key))
        }.getOrDefault("unreadable")

    @Suppress("DEPRECATION")
    private fun readTemplate(extras: Bundle): String =
        runCatching {
            safeTemplate(extras.get(Notification.EXTRA_TEMPLATE))
        }.getOrDefault("unreadable")
}