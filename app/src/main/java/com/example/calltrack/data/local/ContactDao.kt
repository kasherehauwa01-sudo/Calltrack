package com.example.calltrack.data.local

import androidx.room.Dao
import androidx.room.Insert
import androidx.room.OnConflictStrategy
import androidx.room.Query
import kotlinx.coroutines.flow.Flow

@Dao
interface ContactDao {
    @Insert(onConflict = OnConflictStrategy.REPLACE)
    suspend fun insert(contact: ContactEntity): Long

    @Query("SELECT * FROM contacts WHERE phone = :phone ORDER BY id DESC LIMIT 1")
    suspend fun findByPhone(phone: String): ContactEntity?

    @Query("SELECT * FROM contacts WHERE phone = :phone ORDER BY id DESC LIMIT 1")
    fun observeByPhone(phone: String): Flow<ContactEntity?>

    @Query("UPDATE contacts SET client1c = :client1c WHERE id = :id")
    suspend fun updateClient1c(id: Long, client1c: String)

    @Query("UPDATE contacts SET client1c = :client1c WHERE phone = :phone")
    suspend fun updateClient1cByPhone(phone: String, client1c: String)
}
