package com.example.calltrack.data.local

import androidx.room.Entity
import androidx.room.PrimaryKey

@Entity(tableName = "call_history")
data class CallHistoryEntity(
    @PrimaryKey(autoGenerate = true) val id: Long = 0,
    val phone: String,
    val date: String,
    val time: String,
    val type: String,
    val duration: String,
    val manager: String,
    val note: String,
    val tag: String,
    val reminder: String,
    val reminderText: String,
    val client: String,
    val updatedAt: Long
)
