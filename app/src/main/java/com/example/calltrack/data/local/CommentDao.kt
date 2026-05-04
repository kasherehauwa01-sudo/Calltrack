package com.example.calltrack.data.local

import androidx.room.Dao
import androidx.room.Insert
import androidx.room.OnConflictStrategy
import androidx.room.Query
import kotlinx.coroutines.flow.Flow

@Dao
interface CommentDao {
    @Insert(onConflict = OnConflictStrategy.REPLACE)
    suspend fun insert(comment: CommentEntity): Long

    @Query("SELECT * FROM comments WHERE phone = :phone ORDER BY createdAt DESC")
    fun observeByPhone(phone: String): Flow<List<CommentEntity>>
}
