package com.example.calltrack.accessibility

import android.content.Context
import android.util.Log
import com.example.calltrack.data.local.CallDatabase
import com.example.calltrack.data.local.MaxMessageDao
import com.example.calltrack.data.local.MaxMessageEntity
import com.example.calltrack.notification.MaxIncomingMessage
import kotlinx.coroutines.CoroutineScope
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.SupervisorJob
import kotlinx.coroutines.launch
import java.util.UUID

class MaxMessageLocalStore internal constructor(
    private val dao: MaxMessageDao,
    private val scope: CoroutineScope,
    private val logEvent: (String) -> Unit
) {
    constructor(context: Context) : this(
        CallDatabase.getInstance(context.applicationContext).maxMessageDao(),
        CoroutineScope(SupervisorJob() + Dispatchers.IO),
        { event -> Log.i(TAG, event); Unit }
    )

    fun saveOutgoing(contactName: String, occurredAt: Long) {
        val message = MaxMessageEntity(
            sourceEventId = "max-message-out-${occurredAt}-${UUID.randomUUID()}",
            direction = "outgoing",
            contactName = contactName,
            occurredAt = occurredAt
        )
        scope.launch { persist(message) }
    }

    internal fun saveIncoming(messages: List<MaxIncomingMessage>) {
        scope.launch {
            for (message in messages) {
                persist(MaxMessageEntity(
                    sourceEventId = message.sourceEventId,
                    direction = "incoming",
                    contactName = message.senderName,
                    shortcutId = message.shortcutId,
                    occurredAt = message.occurredAt,
                    messageText = "",
                    senderName = message.senderName,
                    conversationTitle = "",
                    isGroupConversation = false,
                    maxUserId = message.maxUserId
                ))
            }
        }
    }

    private suspend fun persist(message: MaxMessageEntity) {
        try {
            // Unique sourceEventId + IGNORE is the persistent, cross-process deduplication boundary.
            val id = dao.insert(message)
            logEvent(if (id == -1L) "duplicateMessageIgnored" else if (message.direction == "incoming") "incomingMessageSaved" else "outgoingMessageSaved")
        } catch (cancelled: kotlinx.coroutines.CancellationException) {
            throw cancelled
        } catch (_: Exception) {
            // SQLite errors may contain bound values. Never log the exception or its message.
            logEvent("messageSaveFailed")
        }
    }

    private companion object {
        const val TAG = "CalltrackMAXMessageStore"
    }
}
