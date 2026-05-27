package com.example.calltrack.ui.main

import android.graphics.Typeface
import android.view.LayoutInflater
import android.view.ViewGroup
import androidx.recyclerview.widget.RecyclerView
import com.example.calltrack.data.local.AppNotificationEntity
import com.example.calltrack.databinding.ItemNotificationBinding
import java.text.SimpleDateFormat
import java.util.Date
import java.util.Locale

class NotificationsAdapter(
    private val onClick: (AppNotificationEntity) -> Unit,
    private val onRead: (AppNotificationEntity) -> Unit
) : RecyclerView.Adapter<NotificationsAdapter.VH>() {
    private val items = mutableListOf<AppNotificationEntity>()
    private val fmt = SimpleDateFormat("dd.MM HH:mm", Locale.getDefault())
    fun submit(list: List<AppNotificationEntity>) { items.clear(); items.addAll(list); notifyDataSetChanged() }
    fun getItem(pos: Int)=items[pos]
    override fun onCreateViewHolder(parent: ViewGroup, viewType: Int): VH = VH(ItemNotificationBinding.inflate(LayoutInflater.from(parent.context), parent, false))
    override fun getItemCount()=items.size
    override fun onBindViewHolder(h: VH, p: Int)=h.bind(items[p])
    inner class VH(private val b: ItemNotificationBinding): RecyclerView.ViewHolder(b.root){
        fun bind(it: AppNotificationEntity){
            b.tvTitle.text=it.title; b.tvMessage.text=it.message; b.tvMeta.text="${group(it.createdAt)} • ${it.type} • ${fmt.format(Date(it.createdAt))}"
            b.tvTitle.setTypeface(null, if (it.isRead) Typeface.NORMAL else Typeface.BOLD)
            b.unreadDot.alpha = if (it.isRead) 0f else 1f
            b.root.alpha= if (it.isRead) 0.85f else 1f
            b.root.setOnClickListener { onRead(it); onClick(it) }
        }
        private fun group(ts: Long): String { val d=Date(ts); val now=System.currentTimeMillis(); val day=24*60*60*1000L
            return when { now-ts < day -> "Сегодня"; now-ts < 2*day -> "Вчера"; else -> "Ранее" }
        }
    }
}
