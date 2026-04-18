package com.example.calltrack.ui.calls

import android.view.LayoutInflater
import android.view.ViewGroup
import androidx.recyclerview.widget.DiffUtil
import androidx.recyclerview.widget.ListAdapter
import androidx.recyclerview.widget.RecyclerView
import com.example.calltrack.data.local.CallEntity
import com.example.calltrack.databinding.ItemCallBinding

class CallAdapter(
    private val onItemClick: (CallEntity) -> Unit
) : ListAdapter<CallEntity, CallAdapter.CallViewHolder>(Diff()) {

    override fun onCreateViewHolder(parent: ViewGroup, viewType: Int): CallViewHolder {
        val binding = ItemCallBinding.inflate(LayoutInflater.from(parent.context), parent, false)
        return CallViewHolder(binding, onItemClick)
    }

    override fun onBindViewHolder(holder: CallViewHolder, position: Int) = holder.bind(getItem(position))

    class CallViewHolder(
        private val binding: ItemCallBinding,
        private val onItemClick: (CallEntity) -> Unit
    ) : RecyclerView.ViewHolder(binding.root) {
        fun bind(item: CallEntity) {
            binding.tvPhone.text = item.phone
            binding.tvType.text = "${item.type} • ${item.duration} сек"
            binding.tvNote.text = item.note
            binding.root.setOnClickListener { onItemClick(item) }
        }
    }

    class Diff : DiffUtil.ItemCallback<CallEntity>() {
        override fun areItemsTheSame(oldItem: CallEntity, newItem: CallEntity) = oldItem.id == newItem.id
        override fun areContentsTheSame(oldItem: CallEntity, newItem: CallEntity) = oldItem == newItem
    }
}
