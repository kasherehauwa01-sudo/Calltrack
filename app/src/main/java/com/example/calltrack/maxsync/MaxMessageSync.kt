package com.example.calltrack.maxsync

import com.example.calltrack.data.local.MaxMessageEntity
import com.google.gson.annotations.SerializedName

/** Explicit network allowlist: neither messageText nor sender/conversation labels can be serialized. */
internal data class MaxMessageUpload(
    @SerializedName("source_event_id") val sourceEventId: String,
    val direction: String,
    @SerializedName("contact_name") val contactName: String,
    val phone: String,
    @SerializedName("max_user_id") val maxUserId: String,
    @SerializedName("shortcut_id") val shortcutId: String,
    @SerializedName("occurred_at") val occurredAt: Long
)
internal data class MaxSyncCutoff(val timestamp: Long, val legacyMaxId: Long)
internal object MaxMessageSyncPolicy {
    const val BATCH_SIZE = 50
    fun eligible(row: MaxMessageEntity, cutoff: MaxSyncCutoff): Boolean =
        !row.uploaded && !row.isGroupConversation && row.id > cutoff.legacyMaxId &&
            row.createdAt >= cutoff.timestamp && row.occurredAt >= cutoff.timestamp &&
            row.direction in setOf("incoming", "outgoing") && row.contactName.isNotBlank()
    fun payload(row: MaxMessageEntity) = MaxMessageUpload(row.sourceEventId, row.direction,
        row.contactName, row.phone, row.maxUserId, row.shortcutId, row.occurredAt)
}

/** Injectable bounded sync core. Only IDs both sent and explicitly acknowledged can be marked. */
internal class MaxMessageSync(
    private val pending: suspend (MaxSyncCutoff, Int) -> List<MaxMessageEntity>,
    private val upload: suspend (List<MaxMessageUpload>) -> Set<String>,
    private val mark: suspend (String) -> Unit
) {
    suspend fun batch(cutoff: MaxSyncCutoff): Boolean {
        val rows = pending(cutoff, MaxMessageSyncPolicy.BATCH_SIZE)
            .take(MaxMessageSyncPolicy.BATCH_SIZE).filter { MaxMessageSyncPolicy.eligible(it, cutoff) }
        if (rows.isEmpty()) return false
        val accepted = upload(rows.map(MaxMessageSyncPolicy::payload))
        for (row in rows) if (row.sourceEventId in accepted) mark(row.sourceEventId)
        check(rows.all { it.sourceEventId in accepted }) { "Incomplete MAX acknowledgement" }
        return rows.size == MaxMessageSyncPolicy.BATCH_SIZE
    }
}
