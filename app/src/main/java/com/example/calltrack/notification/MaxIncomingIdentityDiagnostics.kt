package com.example.calltrack.notification

import android.app.Notification
import android.os.Build
import android.os.Bundle
import android.service.notification.StatusBarNotification
import android.util.Log
import androidx.core.app.NotificationCompat
import androidx.core.app.Person

/** Temporary, explicitly enabled identity diagnostics. Never read Message.text or text extras. */
internal object MaxIncomingIdentityDiagnostics {
    private const val TAG = "CalltrackMAXIdentityDiag"

    fun isCandidate(sbn: StatusBarNotification, extras: Bundle): Boolean = runCatching {
        val n = sbn.notification
        MaxIncomingMessageDetector.isMessageNotification(
            sbn.packageName, n.category, extras.getString(Notification.EXTRA_TEMPLATE),
            extras.containsKey(Notification.EXTRA_MESSAGES),
            extras.containsKey("android.callType") || extras.containsKey("android.callPerson"),
            n.flags and Notification.FLAG_GROUP_SUMMARY != 0, n.flags and Notification.FLAG_ONGOING_EVENT != 0
        )
    }.getOrDefault(false)

    // The sole production call site is guarded by BuildConfig.DEBUG.
    fun logPosted(sbn: StatusBarNotification, extras: Bundle) {
        if (!isCandidate(sbn, extras)) return
        runCatching {
            val n = sbn.notification
            val style = NotificationCompat.MessagingStyle.extractMessagingStyleFromNotification(n)
            val lines = mutableListOf(
                "event=POSTED package=ru.oneme.app notificationId=${sbn.id} postTime=${sbn.postTime}",
                "category=${quoted(n.category)} flags=${n.flags} isGroupSummary=${n.flags and Notification.FLAG_GROUP_SUMMARY != 0}",
                "notificationTag=${quoted(sbn.tag)} notificationKey=${quoted(sbn.key)}",
                "shortcutId=${quoted(n.shortcutId)} channelId=${quoted(n.channelId)}",
                "group=${quoted(n.group)} groupKey=${quoted(sbn.groupKey)}",
                "template=${MaxIncomingMessageDetector.MESSAGING_STYLE}",
                "stylePresent=${style != null}",
                "extrasKeys=${keyNames(extras.keySet())}"
            )
            if (style != null) {
                lines += "conversationTitle=${quoted(style.conversationTitle)}"
                lines += "isGroupConversation=${style.isGroupConversation}"
                lines += personLines("styleUser", style.user)
                val messages = style.messages
                lines += "messagesCount=${messages.size}"
                val last = messages.lastOrNull()
                lines += personLines("messagePerson", last?.person)
                lines += "timestamp=${last?.timestamp}"
            }
            // Inspect only these explicitly identified Person containers, not android.messages.
            for (key in listOf("android.messagingStyleUser", "android.messagingUser")) {
                readPersonContainer(extras, key, lines)
            }
            // Known conversation metadata; no arbitrary String/CharSequence extra values are read.
            for (key in listOf("android.conversationTitle", "android.hiddenConversationTitle", "android.shortcutId", "android.conversationId")) {
                lines += "$key=${quoted(metadataText(extras, key))}"
            }
            // Values of people arrays and message arrays are deliberately not inspected.
            for (key in listOf("android.people", "android.people.list", "android.messages")) {
                lines += "$key.present=${extras.containsKey(key)}"
            }
            for (line in lines) Log.i(TAG, line)
        }.onFailure {
            // An exception may embed notification contents. Never print it or its stack trace.
            Log.w(TAG, "event=POSTED identityDiagnosticsUnavailable")
        }
    }

    private fun personLines(prefix: String, person: Person?): List<String> = listOf(
        "$prefix.present=${person != null}",
        "$prefix.name=${quoted(person?.name)}",
        "$prefix.key=${quoted(person?.key)}",
        "$prefix.uri=${quoted(person?.uri)}"
    )

    @Suppress("DEPRECATION")
    private fun readPersonContainer(extras: Bundle, key: String, lines: MutableList<String>) {
        val value = extras.get(key)
        when {
            value == null -> lines += "$key.present=false"
            value is Bundle -> {
                lines += "$key.type=Bundle keys=${keyNames(value.keySet())}"
                for (field in listOf("name", "key", "uri")) lines += "$key.$field=${quoted(metadataText(value, field))}"
            }
            value is Person -> lines += personLines(key, value)
            Build.VERSION.SDK_INT >= 28 && value is android.app.Person -> {
                lines += "$key.type=Person"
                lines += "$key.name=${quoted(value.name)}"
                lines += "$key.key=${quoted(value.key)}"
                lines += "$key.uri=${quoted(value.uri)}"
            }
            else -> lines += "$key.present=true type=Other"
        }
    }

    @Suppress("DEPRECATION")
    private fun metadataText(extras: Bundle, key: String): CharSequence? = extras.get(key) as? CharSequence

    internal fun quoted(value: CharSequence?): String {
        if (value == null) return "null"
        // Bound each field and escape controls to keep metadata from injecting logcat lines.
        val text = value.take(256).map { char ->
            when (char) {
                '\\' -> "\\\\"
                '"' -> "\\\""
                '\n' -> "\\n"
                '\r' -> "\\r"
                '\t' -> "\\t"
                else -> if (char.isISOControl() || char == '\u2028' || char == '\u2029') "?" else char.toString()
            }
        }.joinToString("")
        return "\"$text\"" + if (value.length > 256) "[truncated]" else ""
    }

    internal fun keyNames(keys: Set<String>): String = keys.sorted().joinToString(",", "[", "]") { quoted(it) }
}
