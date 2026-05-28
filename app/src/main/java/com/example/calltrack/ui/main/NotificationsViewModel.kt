package com.example.calltrack.ui.main

import androidx.lifecycle.ViewModel
import androidx.lifecycle.ViewModelProvider
import androidx.lifecycle.viewModelScope
import com.example.calltrack.data.local.AppNotificationEntity
import com.example.calltrack.data.repository.NotificationRepository
import kotlinx.coroutines.flow.MutableStateFlow
import kotlinx.coroutines.flow.SharingStarted
import kotlinx.coroutines.flow.StateFlow
import kotlinx.coroutines.flow.combine
import kotlinx.coroutines.flow.stateIn
import kotlinx.coroutines.launch

class NotificationsViewModel(private val repo: NotificationRepository) : ViewModel() {
    enum class Filter { ALL, UNREAD, REMINDER, ERROR }
    private val filter = MutableStateFlow(Filter.ALL)

    val notifications: StateFlow<List<AppNotificationEntity>> = combine(repo.getAllNotifications(), filter) { items, f ->
        when (f) {
            Filter.ALL -> items
            Filter.UNREAD -> items.filter { !it.isRead }
            Filter.REMINDER -> items.filter { it.type == "REMINDER" }
            Filter.ERROR -> items.filter { it.type == "SYNC_ERROR" }
        }
    }.stateIn(viewModelScope, SharingStarted.WhileSubscribed(5000), emptyList())

    val unreadCount = repo.getUnreadCount().stateIn(viewModelScope, SharingStarted.WhileSubscribed(5000), 0)
    fun setFilter(value: Filter) { filter.value = value }
    fun markAsRead(id: Long) = viewModelScope.launch { repo.markAsRead(id) }
    fun markAllAsRead() = viewModelScope.launch { repo.markAllAsRead() }
    fun delete(id: Long) = viewModelScope.launch { repo.deleteById(id) }

    class Factory(private val repo: NotificationRepository) : ViewModelProvider.Factory {
        override fun <T : ViewModel> create(modelClass: Class<T>): T = NotificationsViewModel(repo) as T
    }
}
