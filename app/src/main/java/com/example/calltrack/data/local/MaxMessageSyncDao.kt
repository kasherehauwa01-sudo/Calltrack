package com.example.calltrack.data.local

import androidx.room.Dao
import androidx.room.Query

@Dao
interface MaxMessageSyncDao {
    @Query("SELECT COALESCE(MAX(id), 0) FROM max_messages")
    suspend fun maxId(): Long

    @Query("""SELECT * FROM max_messages WHERE uploaded = 0 AND isGroupConversation = 0
        AND id > :legacyMaxId AND createdAt >= :cutoff AND occurredAt >= :cutoff
        AND direction IN ('incoming', 'outgoing') AND TRIM(contactName) <> ''
        ORDER BY id ASC LIMIT :limit""")
    suspend fun pending(cutoff: Long, legacyMaxId: Long, limit: Int): List<MaxMessageEntity>

    @Query("UPDATE max_messages SET uploaded = 1 WHERE sourceEventId = :sourceEventId")
    suspend fun markAccepted(sourceEventId: String)
}
