package com.example.calltrack.notification

import java.nio.ByteBuffer
import java.security.MessageDigest

/** Framework-independent input, extracted exclusively from Android MessagingStyle. */
internal data class MaxIncomingMessageCandidate(
    val text: String?,
    val senderName: String,
    val senderIdentity: String,
    val timestamp: Long,
    val isSelf: Boolean = false,
    val hasAttachment: Boolean = false
)

internal data class MaxIncomingMessage(
    val sourceEventId: String,
    val messageText: String,
    val senderName: String,
    val conversationTitle: String,
    val shortcutId: String,
    val isGroupConversation: Boolean,
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
        conversationTitle: String,
        shortcutId: String,
        isGroupConversation: Boolean,
        messages: List<MaxIncomingMessageCandidate>
    ): List<MaxIncomingMessage> {
        if (conversationIdentity.isBlank()) return emptyList()
        return messages.mapNotNull { message ->
            val text = message.text?.takeIf { it.isNotBlank() } ?: return@mapNotNull null
            if (message.isSelf || message.hasAttachment || message.timestamp <= 0L ||
                message.senderName.isBlank() || message.senderIdentity.isBlank()) return@mapNotNull null
            MaxIncomingMessage(
                sourceEventId = fingerprint(conversationIdentity, message.senderIdentity, message.timestamp, text),
                messageText = text,
                senderName = message.senderName.trim(),
                conversationTitle = conversationTitle.trim(),
                shortcutId = shortcutId,
                isGroupConversation = isGroupConversation,
                occurredAt = message.timestamp
            )
        }.distinctBy { it.sourceEventId }
    }

    private fun fingerprint(conversation: String, sender: String, timestamp: Long, text: String): String {
        val digest = MessageDigest.getInstance("SHA-256")
        // Length framing avoids collisions caused by delimiters inside a message or name.
        for (part in listOf("max-incoming-v1", MAX_PACKAGE, conversation, sender, timestamp.toString(), text)) {
            val bytes = part.toByteArray(Charsets.UTF_8)
            digest.update(ByteBuffer.allocate(4).putInt(bytes.size).array())
            digest.update(bytes)
        }
        return "max-message-in-" + digest.digest().joinToString("") { "%02x".format(it) }
    }
}
