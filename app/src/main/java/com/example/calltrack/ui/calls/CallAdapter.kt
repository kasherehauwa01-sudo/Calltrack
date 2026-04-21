package com.example.calltrack.ui.calls

import android.view.LayoutInflater
import android.view.ViewGroup
import androidx.recyclerview.widget.DiffUtil
import androidx.recyclerview.widget.ListAdapter
import androidx.recyclerview.widget.RecyclerView
import com.example.calltrack.databinding.ItemCallBinding

class CallAdapter(
    private val onItemClick: (RecentCallItem) -> Unit,
    private val onCommentClick: (RecentCallItem) -> Unit,
    private val onReminderClick: (RecentCallItem) -> Unit
) : ListAdapter<RecentCallItem, CallAdapter.CallViewHolder>(Diff()) {

    override fun onCreateViewHolder(parent: ViewGroup, viewType: Int): CallViewHolder {
        val binding = ItemCallBinding.inflate(LayoutInflater.from(parent.context), parent, false)
        return CallViewHolder(binding, onItemClick, onCommentClick, onReminderClick)
    }

    override fun onBindViewHolder(holder: CallViewHolder, position: Int) = holder.bind(getItem(position))

    fun getItemAt(position: Int): RecentCallItem = getItem(position)

    class CallViewHolder(
        private val binding: ItemCallBinding,
        private val onItemClick: (RecentCallItem) -> Unit,
        private val onCommentClick: (RecentCallItem) -> Unit,
        private val onReminderClick: (RecentCallItem) -> Unit
    ) : RecyclerView.ViewHolder(binding.root) {
        fun bind(item: RecentCallItem) {
            binding.tvName.text = item.contactName
            binding.tvPhone.text = item.call.phone
            binding.tvType.text = "${item.call.type} • ${item.call.duration} сек"
            binding.tvNote.text = item.call.note
            binding.root.setOnClickListener { onItemClick(item) }
            binding.btnComment.setOnClickListener { onCommentClick(item) }
            binding.btnReminder.setOnClickListener { onReminderClick(item) }
        }
    }

    class Diff : DiffUtil.ItemCallback<RecentCallItem>() {
        override fun areItemsTheSame(oldItem: RecentCallItem, newItem: RecentCallItem) = oldItem.call.id == newItem.call.id
        override fun areContentsTheSame(oldItem: RecentCallItem, newItem: RecentCallItem) = oldItem == newItem
    }
}
