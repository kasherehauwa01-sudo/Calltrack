package com.example.calltrack.ui.calls

import android.view.LayoutInflater
import android.view.ViewGroup
import androidx.recyclerview.widget.DiffUtil
import androidx.recyclerview.widget.ListAdapter
import androidx.recyclerview.widget.RecyclerView
import com.example.calltrack.databinding.ItemCallBinding
import com.example.calltrack.databinding.ItemCallDateHeaderBinding
import java.text.SimpleDateFormat
import java.util.Date
import java.util.Locale

class CallAdapter(
    private val onItemClick: (RecentCallListItem.CallRow) -> Unit,
    private val onCommentClick: (RecentCallListItem.CallRow) -> Unit,
    private val onReminderClick: (RecentCallListItem.CallRow) -> Unit
) : ListAdapter<RecentCallListItem, RecyclerView.ViewHolder>(Diff()) {

    override fun getItemViewType(position: Int): Int {
        return when (getItem(position)) {
            is RecentCallListItem.Header -> VIEW_TYPE_HEADER
            is RecentCallListItem.CallRow -> VIEW_TYPE_CALL
        }
    }

    override fun onCreateViewHolder(parent: ViewGroup, viewType: Int): RecyclerView.ViewHolder {
        return if (viewType == VIEW_TYPE_HEADER) {
            val binding = ItemCallDateHeaderBinding.inflate(LayoutInflater.from(parent.context), parent, false)
            HeaderViewHolder(binding)
        } else {
            val binding = ItemCallBinding.inflate(LayoutInflater.from(parent.context), parent, false)
            CallViewHolder(binding, onItemClick, onCommentClick, onReminderClick)
        }
    }

    override fun onBindViewHolder(holder: RecyclerView.ViewHolder, position: Int) {
        when (val item = getItem(position)) {
            is RecentCallListItem.Header -> (holder as HeaderViewHolder).bind(item)
            is RecentCallListItem.CallRow -> (holder as CallViewHolder).bind(item)
        }
    }

    fun getItemAt(position: Int): RecentCallListItem = getItem(position)

    class HeaderViewHolder(private val binding: ItemCallDateHeaderBinding) : RecyclerView.ViewHolder(binding.root) {
        fun bind(item: RecentCallListItem.Header) {
            binding.tvHeader.text = item.title
        }
    }

    class CallViewHolder(
        private val binding: ItemCallBinding,
        private val onItemClick: (RecentCallListItem.CallRow) -> Unit,
        private val onCommentClick: (RecentCallListItem.CallRow) -> Unit,
        private val onReminderClick: (RecentCallListItem.CallRow) -> Unit
    ) : RecyclerView.ViewHolder(binding.root) {
        private val timeFormat = SimpleDateFormat("HH.mm.ss", Locale.getDefault())

        fun bind(item: RecentCallListItem.CallRow) {
            binding.tvName.text = item.contactName
            binding.tvClient1c.text = item.client1cName
            binding.tvPhone.text = item.call.phone
            val callTime = timeFormat.format(Date(item.call.timestamp))
            binding.tvType.text = "${item.call.type} • ${item.call.duration} сек • $callTime"
            binding.tvNote.text = item.call.note
            binding.root.setOnClickListener { onItemClick(item) }
            binding.btnComment.setOnClickListener { onCommentClick(item) }
            binding.btnReminder.setOnClickListener { onReminderClick(item) }
        }
    }

    class Diff : DiffUtil.ItemCallback<RecentCallListItem>() {
        override fun areItemsTheSame(oldItem: RecentCallListItem, newItem: RecentCallListItem): Boolean {
            return when {
                oldItem is RecentCallListItem.Header && newItem is RecentCallListItem.Header -> oldItem.title == newItem.title
                oldItem is RecentCallListItem.CallRow && newItem is RecentCallListItem.CallRow -> oldItem.call.id == newItem.call.id
                else -> false
            }
        }

        override fun areContentsTheSame(oldItem: RecentCallListItem, newItem: RecentCallListItem) = oldItem == newItem
    }

    companion object {
        private const val VIEW_TYPE_HEADER = 0
        private const val VIEW_TYPE_CALL = 1
    }
}
