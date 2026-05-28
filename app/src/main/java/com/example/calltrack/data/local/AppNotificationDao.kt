package com.example.calltrack.data.local

import androidx.room.Dao
import androidx.room.Insert
import androidx.room.Query
import kotlinx.coroutines.flow.Flow

@Dao
interface AppNotificationDao {
    @Query("SELECT * FROM app_notifications ORDER BY createdAt DESC")
    fun getAllNotifications(): Flow<List<AppNotificationEntity>>

    @Query("SELECT * FROM app_notifications WHERE isRead = 0 ORDER BY createdAt DESC")
    fun getUnreadNotifications(): Flow<List<AppNotificationEntity>>

    @Query("SELECT * FROM app_notifications WHERE type = :type ORDER BY createdAt DESC")
    fun getByType(type: String): Flow<List<AppNotificationEntity>>

    @Query("SELECT COUNT(*) FROM app_notifications WHERE isRead = 0")
    fun getUnreadCount(): Flow<Int>

    @Query("UPDATE app_notifications SET isRead = 1 WHERE id = :id")
    suspend fun markAsRead(id: Long)

    @Query("UPDATE app_notifications SET isRead = 1 WHERE isRead = 0")
    suspend fun markAllAsRead()

    @Insert
    suspend fun insertNotification(notification: AppNotificationEntity)

    @Query("DELETE FROM app_notifications WHERE id = :id")
    suspend fun deleteById(id: Long)

    @Query("DELETE FROM app_notifications WHERE createdAt < :threshold")
    suspend fun deleteOldNotifications(threshold: Long)

    @Query("DELETE FROM app_notifications WHERE id NOT IN (SELECT id FROM app_notifications ORDER BY createdAt DESC LIMIT 500)")
    suspend fun trimToMax500()
}
