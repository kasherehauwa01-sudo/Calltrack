package com.example.calltrack.data.local

import androidx.room.ColumnInfo
import androidx.room.Entity
import androidx.room.Index
import androidx.room.PrimaryKey

@Entity(
    tableName = "max_messages",
    indices = [
        Index(value = ["sourceEventId"], unique = true),
        Index("shortcutId"),
        Index("phone")
    ]
)
data class MaxMessageEntity(
    @PrimaryKey(autoGenerate = true) val id: Long = 0,
    val sourceEventId: String,
    val direction: String,
    val contactName: String = "",
    val phone: String = "",
    val shortcutId: String = "",
    val occurredAt: Long,
    val uploaded: Boolean = false,
    val createdAt: Long = System.currentTimeMillis(),
    @ColumnInfo(defaultValue = "''") val messageText: String = "",
    @ColumnInfo(defaultValue = "''") val senderName: String = "",
    @ColumnInfo(defaultValue = "''") val conversationTitle: String = "",
    @ColumnInfo(defaultValue = "0") val isGroupConversation: Boolean = false,
    @ColumnInfo(defaultValue = "''") val maxUserId: String = ""
)
