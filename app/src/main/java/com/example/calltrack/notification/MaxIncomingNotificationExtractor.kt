package com.example.calltrack.notification

import android.app.Notification
import android.service.notification.StatusBarNotification
import androidx.core.app.NotificationCompat
import androidx.core.app.Person

/** Uses the public AndroidX MessagingStyle API on Android 26–35; never parses parcelable strings. */
internal object MaxIncomingNotificationExtractor {
    fun extract(sbn: StatusBarNotification): List<MaxIncomingMessage> {
        val n = sbn.notification
        val extras = n.extras ?: return emptyList()
        if (!MaxIncomingMessageDetector.isMessageNotification(
                sbn.packageName, n.category, extras.getString(Notification.EXTRA_TEMPLATE),
                extras.containsKey(Notification.EXTRA_MESSAGES),
                extras.containsKey("android.callType") || extras.containsKey("android.callPerson"),
                n.flags and Notification.FLAG_GROUP_SUMMARY != 0, n.flags and Notification.FLAG_ONGOING_EVENT != 0
            )) return emptyList()
        val style = NotificationCompat.MessagingStyle.extractMessagingStyleFromNotification(n) ?: return emptyList()
        val shortcut = n.shortcutId.orEmpty()
        // Notification key includes Android user/UID, package, ID and tag; unlike postTime it is stable on updates.
        val identity = if (shortcut.isNotBlank()) "shortcut:${sbn.uid}:$shortcut" else "notification:${sbn.key}"
        val candidates = style.messages.mapNotNull { message ->
            // An invalid entry must not discard the other messages in this notification.
            runCatching {
                val person = message.person
                val senderName = person?.name?.toString().orEmpty()
                val senderIdentity = if (person != null) personIdentity(person) else ""
                // Android represents the current user's own replies with a null sender.
                val isSelf = person == null || isSamePerson(person, style.user)
                MaxIncomingMessageCandidate(
                    text = message.text?.toString(), senderName = senderName,
                    senderIdentity = senderIdentity, timestamp = message.timestamp, isSelf = isSelf,
                    hasAttachment = message.dataMimeType != null || message.dataUri != null
                )
            }.getOrNull()
        }
        val isGroup = style.isGroupConversation
        return MaxIncomingMessageDetector.extract(
            identity, style.conversationTitle?.toString().orEmpty(), shortcut, isGroup, candidates
        )
    }

    private fun personIdentity(person: Person): String = when {
        !person.key.isNullOrBlank() -> "key:${person.key}"
        !person.uri.isNullOrBlank() -> "uri:${person.uri}"
        else -> "name:${person.name?.toString()?.trim().orEmpty()}"
    }

    private fun isSamePerson(sender: Person, self: Person): Boolean = when {
        !sender.key.isNullOrBlank() && !self.key.isNullOrBlank() -> sender.key == self.key
        !sender.uri.isNullOrBlank() && !self.uri.isNullOrBlank() -> sender.uri == self.uri
        else -> sender.name?.toString()?.trim() == self.name?.toString()?.trim()
    }
}
