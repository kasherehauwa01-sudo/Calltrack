package com.example.calltrack.data.local

import androidx.room.Dao
import androidx.room.Insert
import androidx.room.OnConflictStrategy
import androidx.room.Query

@Dao
interface PersonalContactDao {
    @Query("SELECT * FROM personal_contacts")
    suspend fun getAllOnce(): List<PersonalContactEntity>

    @Query("SELECT personalFlag FROM personal_contacts WHERE contactPhone = :contactPhone LIMIT 1")
    suspend fun getFlag(contactPhone: String): Int?

    @Insert(onConflict = OnConflictStrategy.REPLACE)
    suspend fun upsert(entity: PersonalContactEntity)

    @Insert(onConflict = OnConflictStrategy.REPLACE)
    suspend fun upsertAll(items: List<PersonalContactEntity>)

    @Query("DELETE FROM personal_contacts")
    suspend fun clearAll()
}
