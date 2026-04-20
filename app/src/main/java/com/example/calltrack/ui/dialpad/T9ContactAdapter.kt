package com.example.calltrack.ui.dialpad

import android.view.LayoutInflater
import android.view.ViewGroup
import androidx.recyclerview.widget.DiffUtil
import androidx.recyclerview.widget.ListAdapter
import androidx.recyclerview.widget.RecyclerView
import com.example.calltrack.databinding.ItemT9ContactBinding

class T9ContactAdapter(
    private val onClick: (T9ContactItem) -> Unit
) : ListAdapter<T9ContactItem, T9ContactAdapter.T9ViewHolder>(Diff()) {

    override fun onCreateViewHolder(parent: ViewGroup, viewType: Int): T9ViewHolder {
        val binding = ItemT9ContactBinding.inflate(LayoutInflater.from(parent.context), parent, false)
        return T9ViewHolder(binding, onClick)
    }

    override fun onBindViewHolder(holder: T9ViewHolder, position: Int) = holder.bind(getItem(position))

    class T9ViewHolder(
        private val binding: ItemT9ContactBinding,
        private val onClick: (T9ContactItem) -> Unit
    ) : RecyclerView.ViewHolder(binding.root) {
        fun bind(item: T9ContactItem) {
            binding.tvName.text = item.name
            binding.tvPhone.text = item.phone
            binding.root.setOnClickListener { onClick(item) }
        }
    }

    class Diff : DiffUtil.ItemCallback<T9ContactItem>() {
        override fun areItemsTheSame(oldItem: T9ContactItem, newItem: T9ContactItem): Boolean {
            return oldItem.contactId == newItem.contactId && oldItem.phone == newItem.phone
        }

        override fun areContentsTheSame(oldItem: T9ContactItem, newItem: T9ContactItem): Boolean {
            return oldItem == newItem
        }
    }
}
