package com.example.calltrack.data.local

import androidx.room.Entity
import androidx.room.Index
import androidx.room.PrimaryKey

@Entity(tableName = "calls", indices = [Index("sourceEventId")])
data class CallEntity(
    @PrimaryKey(autoGenerate = true) val id: Long = 0,
    val phone: String,
    val type: String,
    val duration: Long,
    val note: String,
    val tag: String = "",
    val reminder: String = "",
    val timestamp: Long,
    val uploaded: Boolean = false,
    val source: String = "phone",
    val sourceEventId: String = "",
    val contactName: String = "",
    val direction: String = "",
    val status: String = "",
    val answeredAt: Long? = null,
    val endedAt: Long? = null,
    val ringingDurationSeconds: Long? = null,
    val isVideo: Boolean = false,
    val contactResolutionStatus: String = ""
)
