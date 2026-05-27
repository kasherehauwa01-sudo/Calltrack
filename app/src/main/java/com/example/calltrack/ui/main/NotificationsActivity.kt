package com.example.calltrack.ui.main

import android.os.Bundle
import androidx.activity.viewModels
import androidx.appcompat.app.AppCompatActivity
import androidx.lifecycle.lifecycleScope
import androidx.recyclerview.widget.ItemTouchHelper
import androidx.recyclerview.widget.LinearLayoutManager
import androidx.recyclerview.widget.RecyclerView
import com.example.calltrack.App
import com.example.calltrack.data.local.AppNotificationEntity
import com.example.calltrack.databinding.ActivityNotificationsBinding
import kotlinx.coroutines.launch

class NotificationsActivity : AppCompatActivity() {
    private lateinit var binding: ActivityNotificationsBinding
    private val vm: NotificationsViewModel by viewModels {
        NotificationsViewModel.Factory((application as App).notificationRepository)
    }
    private val adapter = NotificationsAdapter(
        onClick = { openNotification(it) },
        onRead = { vm.markAsRead(it.id) }
    )

    override fun onCreate(savedInstanceState: Bundle?) {
        super.onCreate(savedInstanceState)
        binding = ActivityNotificationsBinding.inflate(layoutInflater)
        setContentView(binding.root)
        binding.btnBack.setOnClickListener { finish() }
        binding.recycler.layoutManager = LinearLayoutManager(this)
        binding.recycler.adapter = adapter
        binding.btnMarkAll.setOnClickListener { vm.markAllAsRead() }
        binding.filterAll.setOnClickListener { vm.setFilter(NotificationsViewModel.Filter.ALL) }
        binding.filterUnread.setOnClickListener { vm.setFilter(NotificationsViewModel.Filter.UNREAD) }
        binding.filterReminders.setOnClickListener { vm.setFilter(NotificationsViewModel.Filter.REMINDER) }
        binding.filterErrors.setOnClickListener { vm.setFilter(NotificationsViewModel.Filter.ERROR) }
        attachSwipes()

        lifecycleScope.launch {
            vm.notifications.collect { adapter.submit(it) }
        }
    }

    private fun openNotification(item: AppNotificationEntity) {
        vm.markAsRead(item.id)
        val i = MainActivity.createNotificationNavigationIntent(this, item.targetScreen, item.entityId)
        startActivity(i)
    }

    private fun attachSwipes() {
        val cb = object : ItemTouchHelper.SimpleCallback(0, ItemTouchHelper.LEFT or ItemTouchHelper.RIGHT) {
            override fun onMove(r: RecyclerView, v: RecyclerView.ViewHolder, t: RecyclerView.ViewHolder) = false
            override fun onSwiped(vh: RecyclerView.ViewHolder, direction: Int) {
                val item = adapter.getItem(vh.bindingAdapterPosition)
                if (direction == ItemTouchHelper.RIGHT) vm.markAsRead(item.id) else vm.delete(item.id)
            }
        }
        ItemTouchHelper(cb).attachToRecyclerView(binding.recycler)
    }
}
