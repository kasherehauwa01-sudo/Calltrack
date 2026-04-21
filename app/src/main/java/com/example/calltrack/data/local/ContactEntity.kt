package com.example.calltrack.data.local

import androidx.room.Entity
import androidx.room.PrimaryKey

@Entity(tableName = "contacts")
data class ContactEntity(
    @PrimaryKey(autoGenerate = true) val id: Long = 0,
    val phone: String,
    val name: String,
    val createdAt: Long = System.currentTimeMillis()
)
