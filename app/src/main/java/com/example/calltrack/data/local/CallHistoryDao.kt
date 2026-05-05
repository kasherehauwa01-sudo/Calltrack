package com.example.calltrack.data.local

import androidx.room.Dao
import androidx.room.Insert
import androidx.room.OnConflictStrategy
import androidx.room.Query

@Dao
interface CallHistoryDao {
    @Query("SELECT * FROM call_history WHERE phone = :phone ORDER BY date DESC, time DESC")
    suspend fun getByPhone(phone: String): List<CallHistoryEntity>

    @Insert(onConflict = OnConflictStrategy.REPLACE)
    suspend fun insertAll(list: List<CallHistoryEntity>)

    @Query("DELETE FROM call_history WHERE phone = :phone")
    suspend fun deleteByPhone(phone: String)
}
