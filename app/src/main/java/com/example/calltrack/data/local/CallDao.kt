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

    @Query("SELECT MAX(timestamp) FROM calls")
    suspend fun getLatestTimestamp(): Long?

    @Query("SELECT * FROM calls WHERE phone = :phone ORDER BY timestamp DESC")
    fun observeByPhone(phone: String): Flow<List<CallEntity>>

    @Query("SELECT * FROM calls WHERE uploaded = 0")
    suspend fun getPending(): List<CallEntity>

    @Query("SELECT * FROM calls WHERE id = :id LIMIT 1")
    suspend fun getById(id: Long): CallEntity?

    @Query(
        "SELECT * FROM calls " +
            "WHERE phone = :phone AND type = :type AND duration = :duration " +
            "AND ABS(timestamp - :timestamp) <= :windowMs " +
            "ORDER BY id DESC LIMIT 1"
    )
    suspend fun findRecentDuplicate(
        phone: String,
        type: String,
        duration: Long,
        timestamp: Long,
        windowMs: Long = 5_000L
    ): CallEntity?

    @Query("UPDATE calls SET uploaded = 1 WHERE id = :id")
    suspend fun markUploaded(id: Long)

    @Query("UPDATE calls SET uploaded = 1 WHERE id IN (:ids)")
    suspend fun markUploaded(ids: List<Long>)

    @Query("UPDATE calls SET uploaded = 0 WHERE id = :id")
    suspend fun markPending(id: Long)

    @Query("UPDATE calls SET note = :note, tag = :tag, reminder = :reminder, uploaded = 0 WHERE id = :id")
    suspend fun updateOutcome(id: Long, note: String, tag: String, reminder: String)

    @Query("UPDATE calls SET uploaded = 0 WHERE phone = :phone")
    suspend fun markPendingByPhone(phone: String)
}
