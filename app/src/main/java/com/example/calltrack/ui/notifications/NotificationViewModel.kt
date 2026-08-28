package com.example.calltrack.ui.notifications

import androidx.lifecycle.ViewModel
import androidx.lifecycle.ViewModelProvider
import androidx.lifecycle.viewModelScope
import com.example.calltrack.data.local.NotificationEntity
import com.example.calltrack.data.local.NotificationType
import com.example.calltrack.data.notification.NotificationRepository
import kotlinx.coroutines.flow.MutableStateFlow
import kotlinx.coroutines.flow.SharingStarted
import kotlinx.coroutines.flow.StateFlow
import kotlinx.coroutines.flow.combine
import kotlinx.coroutines.flow.stateIn
import kotlinx.coroutines.launch
import java.util.Calendar

class NotificationViewModel(
    private val repository: NotificationRepository
) : ViewModel() {
    private val filter = MutableStateFlow(NotificationFilter.ALL)

    val unreadCount: StateFlow<Int> = repository.unreadCount.stateIn(
        scope = viewModelScope,
        started = SharingStarted.WhileSubscribed(5_000),
        initialValue = 0
    )

    val groupedNotifications: StateFlow<List<NotificationListItem>> = repository.notifications
        .combine(filter) { list, currentFilter -> list.applyFilter(currentFilter).toGroupedItems() }
        .stateIn(viewModelScope, SharingStarted.WhileSubscribed(5_000), emptyList())

    fun setFilter(value: NotificationFilter) {
        filter.value = value
    }

    fun markAsRead(id: Long) = viewModelScope.launch { repository.markAsRead(id) }

    fun markAllAsRead() = viewModelScope.launch { repository.markAllAsRead() }

    fun delete(id: Long) = viewModelScope.launch { repository.deleteById(id) }

    private fun List<NotificationEntity>.applyFilter(filter: NotificationFilter): List<NotificationEntity> {
        return when (filter) {
            NotificationFilter.ALL -> this
            NotificationFilter.CLIENT_NOT_FOUND -> filter { notification ->
                notification.type == NotificationType.MISSING_CLIENT || notification.hasText("\u043A\u043B\u0438\u0435\u043D\u0442", "\u043D\u0435 \u043D\u0430\u0439\u0434\u0435\u043D")
            }
            NotificationFilter.CALL_RESULT -> filter { notification ->
                notification.type == NotificationType.CALLBACK || notification.hasText("\u0440\u0435\u0437\u0443\u043B\u044C\u0442\u0430\u0442 \u0437\u0432\u043E\u043D\u043A\u0430")
            }
            NotificationFilter.UNREAD -> filter { !it.isRead }
            NotificationFilter.APP_UPDATE -> filter { it.type == NotificationType.APP_UPDATE }
        }
    }

    private fun NotificationEntity.hasText(vararg parts: String): Boolean {
        val normalizedText = "${title} ${message}".lowercase()
        return parts.all { part -> normalizedText.contains(part.lowercase()) }
    }

    private fun List<NotificationEntity>.toGroupedItems(): List<NotificationListItem> {
        val result = mutableListOf<NotificationListItem>()
        groupBy { groupTitle(it.createdAt) }.forEach { (title, items) ->
            result += NotificationListItem.Header(title)
            result += items.map { NotificationListItem.Item(it) }
        }
        return result
    }

    private fun groupTitle(timestamp: Long): String {
        val now = Calendar.getInstance()
        val item = Calendar.getInstance().apply { timeInMillis = timestamp }
        return when {
            now.get(Calendar.YEAR) == item.get(Calendar.YEAR) && now.get(Calendar.DAY_OF_YEAR) == item.get(Calendar.DAY_OF_YEAR) -> "\u0421\u0435\u0433\u043E\u0434\u043D\u044F"
            now.get(Calendar.YEAR) == item.get(Calendar.YEAR) && now.get(Calendar.DAY_OF_YEAR) - item.get(Calendar.DAY_OF_YEAR) == 1 -> "\u0412\u0447\u0435\u0440\u0430"
            else -> "\u0420\u0430\u043D\u0435\u0435"
        }
    }

    class Factory(private val repository: NotificationRepository) : ViewModelProvider.Factory {
        override fun <T : ViewModel> create(modelClass: Class<T>): T {
            @Suppress("UNCHECKED_CAST")
            return NotificationViewModel(repository) as T
        }
    }
}
