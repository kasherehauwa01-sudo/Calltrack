package com.example.calltrack.ui.notifications

import android.view.View
import androidx.lifecycle.Lifecycle
import androidx.lifecycle.LifecycleOwner
import androidx.lifecycle.repeatOnLifecycle
import com.example.calltrack.data.notification.NotificationRepository
import kotlinx.coroutines.CoroutineScope
import kotlinx.coroutines.launch

class NotificationBadgeManager(
    private val lifecycleOwner: LifecycleOwner,
    private val scope: CoroutineScope,
    private val repository: NotificationRepository,
    private val badgeView: View
) {
    fun start() {
        scope.launch {
            lifecycleOwner.repeatOnLifecycle(Lifecycle.State.STARTED) {
                repository.unreadCount.collect { unreadCount ->
                    badgeView.visibility = if (unreadCount > 0) View.VISIBLE else View.GONE
                }
            }
        }
    }
}
