package com.example.calltrack.data.local

import androidx.room.Entity
import androidx.room.PrimaryKey

@Entity(tableName = "calls")
data class CallEntity(
    @PrimaryKey(autoGenerate = true) val id: Long = 0,
    val phone: String,
    val type: String,
    val duration: Long,
    val note: String,
    val timestamp: Long,
    val uploaded: Boolean = false
)
