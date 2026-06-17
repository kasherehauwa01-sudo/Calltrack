package com.example.calltrack.data.local

import androidx.room.Entity
import androidx.room.PrimaryKey

@Entity(tableName = "personal_contacts")
data class PersonalContactEntity(
    @PrimaryKey val contactPhone: String,
    val personalFlag: Int,
    val updatedAt: Long = System.currentTimeMillis()
)
