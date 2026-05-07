package com.example.calltrack.ui.contactcard

import android.os.Bundle
import android.widget.TextView
import android.view.LayoutInflater
import android.view.View
import android.view.ViewGroup
import androidx.fragment.app.Fragment
import androidx.fragment.app.activityViewModels
import androidx.lifecycle.lifecycleScope
import com.example.calltrack.App
import com.example.calltrack.data.local.CallEntity
import com.example.calltrack.data.local.CallHistoryEntity
import com.example.calltrack.data.local.CommentEntity
import com.example.calltrack.data.local.ReminderEntity
import com.example.calltrack.databinding.FragmentContactHistoryBinding
import com.example.calltrack.ui.main.MainViewModel
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.launch
import kotlinx.coroutines.withContext
import java.text.SimpleDateFormat
import java.util.Date
import java.util.Locale
import com.google.android.material.card.MaterialCardView

class ContactHistoryFragment : Fragment() {

    private var _binding: FragmentContactHistoryBinding? = null
    private val binding get() = _binding!!

    private val viewModel: MainViewModel by activityViewModels {
        MainViewModel.Factory((requireActivity().application as App).repository)
    }

    private val dateTimeFormat = SimpleDateFormat("dd.MM.yyyy HH:mm", Locale.getDefault())
    private val historySortFormat = SimpleDateFormat("dd.MM.yyyy HH:mm:ss", Locale.getDefault())

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
                loadRemoteCalls(phone)
            }
            TYPE_REMINDERS -> {
                binding.tvTitle.text = "История напоминаний"
                loadRemoteReminders(phone)
            }
            else -> {
                binding.tvTitle.text = "История комментариев"
                loadRemoteComments(phone)
            }
        }
    }

    private fun formatCalls(calls: List<CallEntity>): String {
        if (calls.isEmpty()) return "Нет данных"
        return calls.joinToString("\n") {
            "• ${it.type} | ${dateTimeFormat.format(Date(it.timestamp))} | ${it.duration} сек"
        }
    }

    private fun loadRemoteCalls(phone: String) {
        loadCachedThenRefresh(
            phone = phone,
            onSuccess = { formatRemoteCallsFromCache(it) }
        )
    }

    private fun loadRemoteReminders(phone: String) {
        loadCachedThenRefresh(
            phone = phone,
            onSuccess = { items ->
                val reminders = items
                    .sortedByDescending { parseHistoryDateTime(it.date, it.time) }
                    .filter { it.reminder.isNotBlank() || it.reminderText.isNotBlank() }
                    .take(20)
                if (reminders.isEmpty()) listOf("Нет истории") else reminders.map {
                    "${normalizeDate(it.date)} | ${normalizeTime(it.time)} | ${it.reminder.ifBlank { it.reminderText }}"
                }
            }
        )
    }

    private fun loadRemoteComments(phone: String) {
        loadCachedThenRefresh(
            phone = phone,
            onSuccess = { items ->
                val comments = items
                    .sortedByDescending { parseHistoryDateTime(it.date, it.time) }
                    .filter { it.note.isNotBlank() || it.tag.isNotBlank() }
                    .take(20)
                if (comments.isEmpty()) listOf("Нет истории") else comments.map {
                    "${normalizeDate(it.date)} | ${normalizeTime(it.time)} | ${it.note.ifBlank { it.tag }}"
                }
            }
        )
    }

    private fun loadCachedThenRefresh(phone: String, onSuccess: (List<CallHistoryEntity>) -> List<String>) {
        renderHistoryCards(listOf("Загрузка..."))
        lifecycleScope.launch {
            val cached = withContext(Dispatchers.IO) { viewModel.getHistory(phone) }
            renderHistoryCards(onSuccess(cached))

            launch {
                val result = runCatching { withContext(Dispatchers.IO) { viewModel.refreshHistory(phone) } }
                if (result.isSuccess) {
                    val updated = withContext(Dispatchers.IO) { viewModel.getHistory(phone) }
                    renderHistoryCards(onSuccess(updated))
                } else if (cached.isEmpty()) {
                    renderHistoryCards(listOf("Нет интернета или ошибка загрузки"))
                }
            }
        }
    }

    private fun formatRemoteCallsFromCache(items: List<CallHistoryEntity>): List<String> {
        val latestCalls = items
            .sortedByDescending { parseHistoryDateTime(it.date, it.time) }
            .take(20)
        if (latestCalls.isEmpty()) return listOf("Нет истории")
        return latestCalls.map {
            "${normalizeDate(it.date)} | ${normalizeTime(it.time)} | ${it.type} | ${formatDuration(it.duration)}"
        }
    }

    private fun normalizeDate(date: String): String {
        val iso = Regex("""(\d{4})-(\d{2})-(\d{2})""").find(date)?.groupValues
        if (iso != null) {
            return "${iso[3]}.${iso[2]}.${iso[1]}"
        }
        val parts = date.split(".")
        if (parts.size == 3 && parts[2].length == 2) {
            return "${parts[0]}.${parts[1]}.20${parts[2]}"
        }
        return date
    }

    private fun normalizeTime(time: String): String {
        val hhmmss = Regex("""(\d{2}):(\d{2}):(\d{2})""").find(time)?.value
        if (hhmmss != null) return hhmmss
        val parts = time.split(":")
        return when (parts.size) {
            2 -> "${parts[0]}:${parts[1]}:00"
            3 -> time
            else -> time
        }
    }

    private fun parseHistoryDateTime(date: String, time: String): Long {
        val normalized = "${normalizeDate(date)} ${normalizeTime(time)}"
        return runCatching { historySortFormat.parse(normalized)?.time ?: 0L }.getOrDefault(0L)
    }

    private fun formatDuration(duration: String): String {
        val totalSeconds = duration.toLongOrNull() ?: return duration
        val minutes = totalSeconds / 60
        val seconds = totalSeconds % 60
        return "%d.%02d".format(minutes, seconds)
    }

    private fun renderHistoryCards(lines: List<String>) {
        binding.historyContainer.removeAllViews()
        lines.forEachIndexed { index, line ->
            val card = MaterialCardView(requireContext()).apply {
                radius = 14f
                cardElevation = 1f
                setCardBackgroundColor(resources.getColor(com.example.calltrack.R.color.background, null))
                val lp = ViewGroup.MarginLayoutParams(
                    ViewGroup.LayoutParams.MATCH_PARENT,
                    ViewGroup.LayoutParams.WRAP_CONTENT
                )
                if (index > 0) lp.topMargin = 10
                layoutParams = lp
            }
            val text = TextView(requireContext()).apply {
                textSize = 18f
                setTextColor(resources.getColor(com.example.calltrack.R.color.textPrimary, null))
                setPadding(24, 18, 24, 18)
                text = line
            }
            card.addView(text)
            binding.historyContainer.addView(card)
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
