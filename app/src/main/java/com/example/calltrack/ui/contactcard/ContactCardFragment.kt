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
import com.example.calltrack.databinding.FragmentContactCardBinding
import com.example.calltrack.ui.main.MainViewModel
import java.text.SimpleDateFormat
import java.util.Date
import java.util.Locale

class ContactCardFragment : Fragment() {

    private var _binding: FragmentContactCardBinding? = null
    private val binding get() = _binding!!

    private val viewModel: MainViewModel by activityViewModels {
        MainViewModel.Factory((requireActivity().application as App).repository)
    }

    private val dateTimeFormat = SimpleDateFormat("dd.MM.yyyy HH:mm", Locale.getDefault())

    override fun onCreateView(inflater: LayoutInflater, container: ViewGroup?, savedInstanceState: Bundle?): View {
        _binding = FragmentContactCardBinding.inflate(inflater, container, false)
        return binding.root
    }

    override fun onViewCreated(view: View, savedInstanceState: Bundle?) {
        val phone = requireArguments().getString(ARG_PHONE).orEmpty()
        binding.tvContactPhone.text = phone
        binding.tvContactName.text = phone

        viewModel.observeContact(phone).observe(viewLifecycleOwner) { contact ->
            binding.tvContactName.text = contact?.name?.ifBlank { phone } ?: phone
        }
        viewModel.observeCallsByPhone(phone).observe(viewLifecycleOwner) { calls ->
            binding.tvCallsHistory.text = formatCalls(calls)
        }
        viewModel.observeReminders(phone).observe(viewLifecycleOwner) { reminders ->
            binding.tvRemindersHistory.text = formatReminders(reminders)
        }
        viewModel.observeComments(phone).observe(viewLifecycleOwner) { comments ->
            binding.tvCommentsHistory.text = formatComments(comments)
        }
    }

    private fun formatCalls(calls: List<CallEntity>): String {
        if (calls.isEmpty()) return "Нет звонков"
        return calls.joinToString("\n") {
            "• ${it.type} | ${dateTimeFormat.format(Date(it.timestamp))} | ${it.duration} сек"
        }
    }

    private fun formatReminders(reminders: List<ReminderEntity>): String {
        if (reminders.isEmpty()) return "Нет напоминаний"
        return reminders.joinToString("\n") {
            "• ${dateTimeFormat.format(Date(it.remindAt))} | ${it.status}"
        }
    }

    private fun formatComments(comments: List<CommentEntity>): String {
        if (comments.isEmpty()) return "Нет комментариев"
        return comments.joinToString("\n") {
            "• ${it.text} (${dateTimeFormat.format(Date(it.createdAt))})"
        }
    }

    override fun onDestroyView() {
        _binding = null
        super.onDestroyView()
    }

    companion object {
        private const val ARG_PHONE = "arg_phone"
        fun newInstance(phone: String) = ContactCardFragment().apply {
            arguments = Bundle().apply { putString(ARG_PHONE, phone) }
        }
    }
}
