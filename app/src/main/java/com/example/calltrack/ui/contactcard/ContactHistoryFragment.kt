package com.example.calltrack.ui.contactcard

import android.os.Bundle
import android.view.LayoutInflater
import android.view.View
import android.view.ViewGroup
import androidx.fragment.app.Fragment
import androidx.fragment.app.activityViewModels
import com.example.calltrack.App
import com.example.calltrack.data.local.CallEntity
import com.example.calltrack.data.local.CommentEntity
import com.example.calltrack.data.local.ReminderEntity
import com.example.calltrack.databinding.FragmentContactHistoryBinding
import com.example.calltrack.ui.main.MainViewModel
import java.text.SimpleDateFormat
import java.util.Date
import java.util.Locale

class ContactHistoryFragment : Fragment() {

    private var _binding: FragmentContactHistoryBinding? = null
    private val binding get() = _binding!!

    private val viewModel: MainViewModel by activityViewModels {
        MainViewModel.Factory((requireActivity().application as App).repository)
    }

    private val dateTimeFormat = SimpleDateFormat("dd.MM.yyyy HH:mm", Locale.getDefault())

    override fun onCreateView(inflater: LayoutInflater, container: ViewGroup?, savedInstanceState: Bundle?): View {
        _binding = FragmentContactHistoryBinding.inflate(inflater, container, false)
        return binding.root
    }

    override fun onViewCreated(view: View, savedInstanceState: Bundle?) {
        val phone = requireArguments().getString(ARG_PHONE).orEmpty()
        val type = requireArguments().getString(ARG_TYPE).orEmpty()

        binding.btnBack.setOnClickListener {
            requireActivity().onBackPressedDispatcher.onBackPressed()
        }

        when (type) {
            TYPE_CALLS -> {
                binding.tvTitle.text = "История звонков"
                viewModel.observeCallsByPhone(phone).observe(viewLifecycleOwner) { binding.tvHistory.text = formatCalls(it) }
            }
            TYPE_REMINDERS -> {
                binding.tvTitle.text = "История напоминаний"
                viewModel.observeReminders(phone).observe(viewLifecycleOwner) { binding.tvHistory.text = formatReminders(it) }
            }
            else -> {
                binding.tvTitle.text = "История комментариев"
                viewModel.observeComments(phone).observe(viewLifecycleOwner) { binding.tvHistory.text = formatComments(it) }
            }
        }
    }

    private fun formatCalls(calls: List<CallEntity>): String {
        if (calls.isEmpty()) return "Нет данных"
        return calls.joinToString("\n") {
            "• ${it.type} | ${dateTimeFormat.format(Date(it.timestamp))} | ${it.duration} сек"
        }
    }

    private fun formatReminders(reminders: List<ReminderEntity>): String {
        if (reminders.isEmpty()) return "Нет данных"
        return reminders.joinToString("\n") {
            "• ${dateTimeFormat.format(Date(it.remindAt))} | ${it.status} | ${it.message}"
        }
    }

    private fun formatComments(comments: List<CommentEntity>): String {
        if (comments.isEmpty()) return "Нет данных"
        return comments.joinToString("\n") {
            "• ${it.text} (${dateTimeFormat.format(Date(it.createdAt))})"
        }
    }

    override fun onDestroyView() {
        _binding = null
        super.onDestroyView()
    }

    companion object {
        const val TYPE_CALLS = "calls"
        const val TYPE_REMINDERS = "reminders"
        const val TYPE_COMMENTS = "comments"
        private const val ARG_PHONE = "arg_phone"
        private const val ARG_TYPE = "arg_type"

        fun newInstance(phone: String, type: String) = ContactHistoryFragment().apply {
            arguments = Bundle().apply {
                putString(ARG_PHONE, phone)
                putString(ARG_TYPE, type)
            }
        }
    }
}
