package com.example.calltrack.data.local

import androidx.room.Entity
import androidx.room.PrimaryKey

@Entity(tableName = "comments")
data class CommentEntity(
    @PrimaryKey(autoGenerate = true) val id: Long = 0,
    val phone: String,
    val text: String,
    val createdAt: Long = System.currentTimeMillis()
)
