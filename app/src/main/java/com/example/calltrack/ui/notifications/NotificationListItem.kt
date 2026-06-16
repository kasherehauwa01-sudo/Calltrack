package com.example.calltrack.ui.notifications

import com.example.calltrack.data.local.NotificationEntity

sealed class NotificationListItem {
    data class Header(val title: String) : NotificationListItem()
    data class Item(val notification: NotificationEntity) : NotificationListItem()
}
