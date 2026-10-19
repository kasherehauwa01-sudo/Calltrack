package com.example.calltrack.data.local

import androidx.room.Dao
import androidx.room.Insert
import androidx.room.OnConflictStrategy
import androidx.room.Query

@Dao
interface MaxMessageDao {

    @Insert(onConflict = OnConflictStrategy.IGNORE)
    suspend fun insert(message: MaxMessageEntity): Long

    @Query("SELECT * FROM max_messages WHERE uploaded = 0 ORDER BY occurredAt ASC")
    suspend fun getPending(): List<MaxMessageEntity>

    @Query("SELECT * FROM max_messages WHERE sourceEventId = :sourceEventId LIMIT 1")
    suspend fun getBySourceEventId(sourceEventId: String): MaxMessageEntity?

    @Query("UPDATE max_messages SET uploaded = 1 WHERE id = :id")
    suspend fun markUploaded(id: Long)

    @Query("SELECT COUNT(*) FROM max_messages")
    suspend fun getCount(): Int

    @Query("SELECT * FROM max_messages ORDER BY occurredAt DESC LIMIT :limit")
    suspend fun getLatest(limit: Int = 50): List<MaxMessageEntity>
}
