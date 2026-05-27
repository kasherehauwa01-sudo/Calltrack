package com.example.calltrack.data.repository

import com.example.calltrack.data.local.AppNotificationDao
import com.example.calltrack.data.local.AppNotificationEntity
import kotlinx.coroutines.flow.Flow

class NotificationRepository(private val dao: AppNotificationDao) {
    fun getAllNotifications(): Flow<List<AppNotificationEntity>> = dao.getAllNotifications()
    fun getUnreadNotifications(): Flow<List<AppNotificationEntity>> = dao.getUnreadNotifications()
    fun getByType(type: String): Flow<List<AppNotificationEntity>> = dao.getByType(type)
    fun getUnreadCount(): Flow<Int> = dao.getUnreadCount()

    suspend fun markAsRead(id: Long) = dao.markAsRead(id)
    suspend fun markAllAsRead() = dao.markAllAsRead()
    suspend fun deleteById(id: Long) = dao.deleteById(id)

    suspend fun insertNotification(
        title: String,
        message: String,
        type: NotificationType,
        targetScreen: String = "",
        entityId: String = "",
        payloadJson: String = ""
    ) {
        dao.insertNotification(
            AppNotificationEntity(
                title = title,
                message = message,
                type = type.name,
                targetScreen = targetScreen,
                entityId = entityId,
                payloadJson = payloadJson
            )
        )
        dao.deleteOldNotifications(System.currentTimeMillis() - 30L * 24 * 60 * 60 * 1000)
        dao.trimToMax500()
    }
}
