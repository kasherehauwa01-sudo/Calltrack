package com.example.calltrack.accessibility

import android.content.Context
import android.util.Log
import com.example.calltrack.data.local.CallDatabase
import com.example.calltrack.data.local.MaxMessageEntity
import kotlinx.coroutines.CoroutineScope
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.SupervisorJob
import kotlinx.coroutines.launch
import java.util.UUID

class MaxMessageLocalStore(context: Context) {

    private val dao =
        CallDatabase.getInstance(context.applicationContext).maxMessageDao()

    private val scope =
        CoroutineScope(SupervisorJob() + Dispatchers.IO)

    fun saveOutgoing(
        contactName: String,
        occurredAt: Long
    ) {
        val sourceEventId =
            "max-message-out-${occurredAt}-${UUID.randomUUID()}"

        val message = MaxMessageEntity(
            sourceEventId = sourceEventId,
            direction = "outgoing",
            contactName = contactName,
            occurredAt = occurredAt
        )

        scope.launch {
            try {
                val id = dao.insert(message)

                Log.i(
                    TAG,
                    "MAX_MESSAGE_SAVED " +
                        "id=$id " +
                        "direction=outgoing " +
                        "contactName=$contactName " +
                        "occurredAt=$occurredAt"
                )
            } catch (error: Exception) {
                Log.e(
                    TAG,
                    "MAX_MESSAGE_SAVE_FAILED " +
                        "direction=outgoing " +
                        "occurredAt=$occurredAt",
                    error
                )
            }
        }
    }

    fun saveIncoming(
        contactName: String,
        shortcutId: String,
        occurredAt: Long
    ) {
        val sourceEventId =
            "max-message-in-$shortcutId-$occurredAt"

        val message = MaxMessageEntity(
            sourceEventId = sourceEventId,
            direction = "incoming",
            contactName = contactName,
            shortcutId = shortcutId,
            occurredAt = occurredAt
        )

        scope.launch {
            try {
                val id = dao.insert(message)

                if (id != -1L) {
                    Log.i(
                        TAG,
                        "MAX_MESSAGE_SAVED " +
                            "id=$id " +
                            "direction=incoming " +
                            "contactName=$contactName " +
                            "shortcutId=$shortcutId " +
                            "occurredAt=$occurredAt"
                    )
                } else {
                    Log.i(
                        TAG,
                        "MAX_MESSAGE_DUPLICATE " +
                            "direction=incoming " +
                            "shortcutId=$shortcutId " +
                            "occurredAt=$occurredAt"
                    )
                }
            } catch (error: Exception) {
                Log.e(
                    TAG,
                    "MAX_MESSAGE_SAVE_FAILED " +
                        "direction=incoming " +
                        "occurredAt=$occurredAt",
                    error
                )
            }
        }
    }

    private companion object {
        const val TAG = "CalltrackMAXMessageStore"
    }
}
