package com.example.calltrack.notification

import java.nio.ByteBuffer
import java.security.MessageDigest

/** Framework-independent input, extracted exclusively from Android MessagingStyle. */
internal data class MaxIncomingMessageCandidate(
    val senderName: String,
    val maxUserId: String,
    val timestamp: Long,
    val isSelf: Boolean = false,
    val hasAttachment: Boolean = false
)

internal data class MaxIncomingMessage(
    val sourceEventId: String,
    val senderName: String,
    val maxUserId: String,
    val shortcutId: String,
    val occurredAt: Long
)

internal object MaxIncomingMessageDetector {
    const val MAX_PACKAGE = "ru.oneme.app"
    const val MESSAGING_STYLE = "android.app.Notification\$MessagingStyle"

    fun isMessageNotification(
        packageName: String, category: String?, template: String?, hasMessages: Boolean,
        isCall: Boolean, isGroupSummary: Boolean, isOngoing: Boolean
    ): Boolean = packageName == MAX_PACKAGE && category == "msg" &&
        template == MESSAGING_STYLE && hasMessages && !isCall && !isGroupSummary && !isOngoing

    fun extract(
        conversationIdentity: String,
        shortcutId: String,
        isGroupConversation: Boolean?,
        messages: List<MaxIncomingMessageCandidate>
    ): List<MaxIncomingMessage> {
        // Null is not proof of a personal chat. Never inspect participants of a known group.
        if (isGroupConversation != false) return emptyList()
        val shortcut = shortcutId.trim()
        val message = messages.lastOrNull { candidate ->
            !candidate.isSelf && !candidate.hasAttachment && candidate.timestamp > 0L &&
                candidate.senderName.isNotBlank() &&
                (candidate.maxUserId.isNotBlank() || shortcut.isNotBlank())
        } ?: return emptyList()
        val conversation = conversationIdentity.ifBlank { if (shortcut.isNotBlank()) "shortcut:$shortcut" else "" }
        if (conversation.isBlank()) return emptyList()
        val userId = message.maxUserId.trim()
        // Name fallback is scoped to an actual MAX shortcut, never a global user ID.
        val senderIdentity = if (userId.isNotEmpty()) "key:$userId" else "scoped-name:${message.senderName.trim()}"
        return listOf(MaxIncomingMessage(
            sourceEventId = fingerprint(conversation, shortcut, senderIdentity, message.timestamp),
            senderName = message.senderName.trim(), maxUserId = userId,
            shortcutId = shortcut, occurredAt = message.timestamp
        ))
    }

    private fun fingerprint(conversation: String, shortcut: String, sender: String, timestamp: Long): String {
        val digest = MessageDigest.getInstance("SHA-256")
        // Metadata only. Length framing avoids ambiguous field separators.
        for (part in listOf("max-incoming-personal-v2", MAX_PACKAGE, "incoming", conversation, shortcut, sender, timestamp.toString())) {
            val bytes = part.toByteArray(Charsets.UTF_8)
            digest.update(ByteBuffer.allocate(4).putInt(bytes.size).array())
            digest.update(bytes)
        }
        return "max-message-in-v2-" + digest.digest().joinToString("") { "%02x".format(it) }
    }
}
