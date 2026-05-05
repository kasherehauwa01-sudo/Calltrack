package com.example.calltrack.ui.contacts

import android.view.LayoutInflater
import android.view.ViewGroup
import androidx.recyclerview.widget.DiffUtil
import androidx.recyclerview.widget.ListAdapter
import androidx.recyclerview.widget.RecyclerView
import com.example.calltrack.databinding.ItemT9ContactBinding

class ContactsAdapter(
    private val onClick: (ContactListItem) -> Unit
) : ListAdapter<ContactListItem, ContactsAdapter.ContactViewHolder>(Diff()) {

    override fun onCreateViewHolder(parent: ViewGroup, viewType: Int): ContactViewHolder {
        val binding = ItemT9ContactBinding.inflate(LayoutInflater.from(parent.context), parent, false)
        return ContactViewHolder(binding, onClick)
    }

    override fun onBindViewHolder(holder: ContactViewHolder, position: Int) = holder.bind(getItem(position))

    class ContactViewHolder(
        private val binding: ItemT9ContactBinding,
        private val onClick: (ContactListItem) -> Unit
    ) : RecyclerView.ViewHolder(binding.root) {
        fun bind(item: ContactListItem) {
            binding.tvName.text = item.name
            binding.tvPhone.text = item.phone
            binding.root.setOnClickListener { onClick(item) }
        }
    }

    class Diff : DiffUtil.ItemCallback<ContactListItem>() {
        override fun areItemsTheSame(oldItem: ContactListItem, newItem: ContactListItem): Boolean {
            return oldItem.contactId == newItem.contactId && oldItem.phone == newItem.phone
        }

        override fun areContentsTheSame(oldItem: ContactListItem, newItem: ContactListItem): Boolean {
            return oldItem == newItem
        }
    }
}
