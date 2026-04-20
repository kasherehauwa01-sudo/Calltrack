package com.example.calltrack.data.local

import androidx.room.Dao
import androidx.room.Insert
import androidx.room.OnConflictStrategy
import androidx.room.Query
import kotlinx.coroutines.flow.Flow

@Dao
interface CallDao {
    @Insert(onConflict = OnConflictStrategy.REPLACE)
    suspend fun insert(call: CallEntity): Long

    @Query("SELECT * FROM calls ORDER BY timestamp DESC")
    fun observeAll(): Flow<List<CallEntity>>

    @Query("SELECT * FROM calls WHERE uploaded = 0")
    suspend fun getPending(): List<CallEntity>

    @Query("UPDATE calls SET uploaded = 1 WHERE id = :id")
    suspend fun markUploaded(id: Long)
}
