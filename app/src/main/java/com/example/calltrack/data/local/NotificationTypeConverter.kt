package com.example.calltrack.data.local

import androidx.room.TypeConverter

class NotificationTypeConverter {
    @TypeConverter
    fun fromNotificationType(type: NotificationType): String = type.name

    @TypeConverter
    fun toNotificationType(value: String): NotificationType {
        return runCatching { NotificationType.valueOf(value) }.getOrDefault(NotificationType.CALLBACK)
    }
}
