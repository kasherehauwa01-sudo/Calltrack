package com.example.calltrack.data.local

import androidx.room.Entity
import androidx.room.PrimaryKey

@Entity(tableName = "reminders")
data class ReminderEntity(
    @PrimaryKey(autoGenerate = true) val id: Long = 0,
    val phone: String,
    val contactName: String,
    val remindAt: Long,
    val status: String,
    val createdAt: Long = System.currentTimeMillis()
)
