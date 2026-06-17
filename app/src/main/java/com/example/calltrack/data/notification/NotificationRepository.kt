package com.example.calltrack.data.notification

import com.example.calltrack.data.local.NotificationDao
import com.example.calltrack.data.local.NotificationEntity
import kotlinx.coroutines.flow.Flow
import java.util.concurrent.TimeUnit

class NotificationRepository(
    private val notificationDao: NotificationDao
) {
    val notifications: Flow<List<NotificationEntity>> = notificationDao.getAllNotifications()
    val unreadCount: Flow<Int> = notificationDao.getUnreadCount()

    suspend fun insertNotification(notification: NotificationEntity): Long {
        val id = notificationDao.insertNotification(notification)
        cleanupOldNotifications()
        return id
    }

    suspend fun markAsRead(id: Long) = notificationDao.markAsRead(id)

    suspend fun markAllAsRead() = notificationDao.markAllAsRead()

    suspend fun deleteById(id: Long) = notificationDao.deleteById(id)

    suspend fun cleanupOldNotifications() {
        val cutoff = System.currentTimeMillis() - TimeUnit.DAYS.toMillis(30)
        notificationDao.deleteOldNotifications(cutoffMillis = cutoff, limit = MAX_NOTIFICATIONS)
    }

    private companion object {
        private const val MAX_NOTIFICATIONS = 500
    }
}
