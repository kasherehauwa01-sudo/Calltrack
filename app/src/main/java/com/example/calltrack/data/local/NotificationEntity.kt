package com.example.calltrack.data.local

import androidx.room.Entity
import androidx.room.PrimaryKey

@Entity(tableName = "notifications")
data class NotificationEntity(
    @PrimaryKey(autoGenerate = true) val id: Long = 0,
    val title: String,
    val message: String,
    val type: NotificationType,
    val isRead: Boolean = false,
    val createdAt: Long = System.currentTimeMillis(),
    val targetScreen: String,
    val entityId: Long? = null,
    val payloadJson: String = ""
)
