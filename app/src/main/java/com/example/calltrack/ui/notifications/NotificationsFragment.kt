package com.example.calltrack.ui.notifications

import android.os.Bundle
import android.view.LayoutInflater
import android.view.View
import android.view.ViewGroup
import androidx.fragment.app.Fragment
import androidx.fragment.app.viewModels
import androidx.lifecycle.Lifecycle
import androidx.lifecycle.lifecycleScope
import androidx.lifecycle.repeatOnLifecycle
import androidx.recyclerview.widget.ItemTouchHelper
import androidx.recyclerview.widget.LinearLayoutManager
import androidx.recyclerview.widget.RecyclerView
import com.example.calltrack.App
import com.example.calltrack.R
import com.example.calltrack.databinding.FragmentNotificationsBinding
import com.example.calltrack.ui.main.MainActivity
import kotlinx.coroutines.Job
import kotlinx.coroutines.launch

class NotificationsFragment : Fragment() {
    private var _binding: FragmentNotificationsBinding? = null
    private val binding get() = _binding!!
    private var collectJob: Job? = null
    private lateinit var adapter: NotificationsAdapter

    private val viewModel: NotificationViewModel by viewModels {
        NotificationViewModel.Factory((requireActivity().application as App).notificationRepository)
    }

    override fun onCreateView(inflater: LayoutInflater, container: ViewGroup?, savedInstanceState: Bundle?): View {
        _binding = FragmentNotificationsBinding.inflate(inflater, container, false)
        return binding.root
    }

    override fun onViewCreated(view: View, savedInstanceState: Bundle?) {
        adapter = NotificationsAdapter { notification ->
            viewModel.markAsRead(notification.id)
            (activity as? MainActivity)?.openNotificationTarget(notification)
        }
        binding.recyclerNotifications.layoutManager = LinearLayoutManager(requireContext())
        binding.recyclerNotifications.adapter = adapter
        setupSwipeActions()
        setupFilters()
        binding.btnBack.setOnClickListener { (requireActivity() as MainActivity).openDialScreen() }
        binding.btnMarkAllRead.setOnClickListener { viewModel.markAllAsRead() }
        collectNotifications()
    }

    private fun setupFilters() {
        binding.chipGroupFilters.setOnCheckedStateChangeListener { _, checkedIds ->
            val filter = when (checkedIds.firstOrNull()) {
                R.id.chipCallResult -> NotificationFilter.CALL_RESULT
                R.id.chipUnread -> NotificationFilter.UNREAD
                else -> NotificationFilter.CLIENT_NOT_FOUND
            }
            viewModel.setFilter(filter)
        }
    }

    private fun collectNotifications() {
        collectJob?.cancel()
        collectJob = viewLifecycleOwner.lifecycleScope.launch {
            viewLifecycleOwner.repeatOnLifecycle(Lifecycle.State.STARTED) {
                viewModel.groupedNotifications.collect { items ->
                    if (_binding == null) return@collect
                    adapter.submitList(items)
                    binding.tvEmpty.visibility = if (items.isEmpty()) View.VISIBLE else View.GONE
                }
            }
        }
    }

    private fun setupSwipeActions() {
        val callback = object : ItemTouchHelper.SimpleCallback(0, ItemTouchHelper.RIGHT or ItemTouchHelper.LEFT) {
            override fun onMove(
                recyclerView: RecyclerView,
                viewHolder: RecyclerView.ViewHolder,
                target: RecyclerView.ViewHolder
            ): Boolean = false

            override fun getSwipeDirs(recyclerView: RecyclerView, viewHolder: RecyclerView.ViewHolder): Int {
                return if (adapter.notificationAt(viewHolder.bindingAdapterPosition) == null) 0 else super.getSwipeDirs(recyclerView, viewHolder)
            }

            override fun onSwiped(viewHolder: RecyclerView.ViewHolder, direction: Int) {
                val notification = adapter.notificationAt(viewHolder.bindingAdapterPosition) ?: return
                if (direction == ItemTouchHelper.RIGHT) {
                    viewModel.markAsRead(notification.id)
                } else {
                    viewModel.delete(notification.id)
                }
            }
        }
        ItemTouchHelper(callback).attachToRecyclerView(binding.recyclerNotifications)
    }

    override fun onDestroyView() {
        collectJob?.cancel()
        binding.recyclerNotifications.adapter = null
        _binding = null
        super.onDestroyView()
    }

    companion object {
        fun newInstance() = NotificationsFragment()
    }
}
