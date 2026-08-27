package com.example.calltrack.ui.notifications

import android.graphics.Typeface
import android.view.LayoutInflater
import android.view.View
import android.view.ViewGroup
import androidx.recyclerview.widget.DiffUtil
import androidx.recyclerview.widget.ListAdapter
import androidx.recyclerview.widget.RecyclerView
import com.example.calltrack.R
import com.example.calltrack.data.local.NotificationEntity
import com.example.calltrack.databinding.ItemNotificationBinding
import com.example.calltrack.databinding.ItemNotificationHeaderBinding
import java.text.SimpleDateFormat
import java.util.Date
import java.util.Locale

class NotificationsAdapter(
    private val onClick: (NotificationEntity) -> Unit
) : ListAdapter<NotificationListItem, RecyclerView.ViewHolder>(Diff()) {

    override fun getItemViewType(position: Int): Int = when (getItem(position)) {
        is NotificationListItem.Header -> VIEW_TYPE_HEADER
        is NotificationListItem.Item -> VIEW_TYPE_NOTIFICATION
    }

    override fun onCreateViewHolder(parent: ViewGroup, viewType: Int): RecyclerView.ViewHolder {
        val inflater = LayoutInflater.from(parent.context)
        return if (viewType == VIEW_TYPE_HEADER) {
            HeaderViewHolder(ItemNotificationHeaderBinding.inflate(inflater, parent, false))
        } else {
            NotificationViewHolder(ItemNotificationBinding.inflate(inflater, parent, false), onClick)
        }
    }

    override fun onBindViewHolder(holder: RecyclerView.ViewHolder, position: Int) {
        when (val item = getItem(position)) {
            is NotificationListItem.Header -> (holder as HeaderViewHolder).bind(item.title)
            is NotificationListItem.Item -> (holder as NotificationViewHolder).bind(item.notification)
        }
    }

    fun notificationAt(position: Int): NotificationEntity? {
        if (position == RecyclerView.NO_POSITION || position < 0 || position >= itemCount) return null
        return (getItem(position) as? NotificationListItem.Item)?.notification
    }

    class HeaderViewHolder(private val binding: ItemNotificationHeaderBinding) : RecyclerView.ViewHolder(binding.root) {
        fun bind(title: String) {
            binding.tvHeader.text = title
        }
    }

    class NotificationViewHolder(
        private val binding: ItemNotificationBinding,
        private val onClick: (NotificationEntity) -> Unit
    ) : RecyclerView.ViewHolder(binding.root) {
        private val timeFormat = SimpleDateFormat("dd.MM.yyyy HH:mm", Locale.getDefault())

        fun bind(notification: NotificationEntity) {
            binding.tvTitle.text = notification.title
            binding.tvMessage.text = notification.message
            binding.tvMeta.text = "${typeLabel(notification.type.name)} • ${timeFormat.format(Date(notification.createdAt))}"
            binding.tvTitle.setTypeface(null, if (notification.isRead) Typeface.NORMAL else Typeface.BOLD)
            binding.unreadDot.visibility = if (notification.isRead) View.GONE else View.VISIBLE
            binding.contentRoot.setBackgroundResource(
                if (notification.isRead) R.drawable.bg_notification_read else R.drawable.bg_notification_unread
            )
            binding.root.setOnClickListener { onClick(notification) }
        }

        private fun typeLabel(type: String): String = when (type) {
            "MISSING_CLIENT" -> "Клиент"
            "REMINDER" -> "Напоминание"
            "MISSED_CALL" -> "Пропущенный"
            "PERSONAL_CONTACT" -> "Личный контакт"
            "CALLBACK" -> "Обратный звонок"
            "SYNC_ERROR" -> "Ошибка синхронизации"
            "APP_UPDATE" -> "Обновление"
            else -> type
        }
    }

    private class Diff : DiffUtil.ItemCallback<NotificationListItem>() {
        override fun areItemsTheSame(oldItem: NotificationListItem, newItem: NotificationListItem): Boolean {
            return when {
                oldItem is NotificationListItem.Header && newItem is NotificationListItem.Header -> oldItem.title == newItem.title
                oldItem is NotificationListItem.Item && newItem is NotificationListItem.Item -> oldItem.notification.id == newItem.notification.id
                else -> false
            }
        }

        override fun areContentsTheSame(oldItem: NotificationListItem, newItem: NotificationListItem): Boolean = oldItem == newItem
    }

    private companion object {
        private const val VIEW_TYPE_HEADER = 1
        private const val VIEW_TYPE_NOTIFICATION = 2
    }
}
