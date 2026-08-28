package com.example.calltrack.ui.contactcard

import android.os.Bundle
import android.util.Log
import android.text.SpannableStringBuilder
import android.text.Spanned
import android.text.style.ForegroundColorSpan
import android.text.style.StyleSpan
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
import java.util.concurrent.TimeUnit
import android.graphics.Typeface
import com.google.android.material.card.MaterialCardView

class ContactHistoryFragment : Fragment() {

    private var _binding: FragmentContactHistoryBinding? = null
    private val binding get() = _binding!!

    private val viewModel: MainViewModel by activityViewModels {
        MainViewModel.Factory((requireActivity().application as App).repository)
    }

    private val dateTimeFormat = SimpleDateFormat("dd.MM.yyyy HH:mm", Locale.getDefault())
    private val historySortFormat = SimpleDateFormat("dd.MM.yyyy HH:mm:ss", Locale.getDefault())
    private val dayFormat = SimpleDateFormat("dd MMMM", Locale("ru"))
    private val timeShortFormat = SimpleDateFormat("HH:mm", Locale.getDefault())

    override fun onCreateView(inflater: LayoutInflater, container: ViewGroup?, savedInstanceState: Bundle?): View {
        _binding = FragmentContactHistoryBinding.inflate(inflater, container, false)
        return binding.root
    }

    override fun onViewCreated(view: View, savedInstanceState: Bundle?) {
        val phone = requireArguments().getString(ARG_PHONE).orEmpty()
        val type = requireArguments().getString(ARG_TYPE).orEmpty()
        Log.d(HISTORY_LOG_TAG, "\u041E\u0442\u043A\u0440\u044B\u0442 \u044D\u043A\u0440\u0430\u043D \u0438\u0441\u0442\u043E\u0440\u0438\u0438: type=$type, phone=$phone")

        binding.btnBack.setOnClickListener {
            requireActivity().onBackPressedDispatcher.onBackPressed()
        }

        when (type) {
            TYPE_CALLS -> {
                binding.tvTitle.text = "\u0418\u0441\u0442\u043E\u0440\u0438\u044F \u0437\u0432\u043E\u043D\u043A\u043E\u0432"
                loadStyledCallHistory(phone)
            }
            TYPE_REMINDERS -> {
                binding.tvTitle.text = "\u0418\u0441\u0442\u043E\u0440\u0438\u044F \u043D\u0430\u043F\u043E\u043C\u0438\u043D\u0430\u043D\u0438\u0439"
                loadRemoteReminders(phone)
            }
            else -> {
                binding.tvTitle.text = "\u0418\u0441\u0442\u043E\u0440\u0438\u044F \u043A\u043E\u043C\u043C\u0435\u043D\u0442\u0430\u0440\u0438\u0435\u0432"
                loadRemoteComments(phone)
            }
        }
    }

    private inline fun withBinding(block: (FragmentContactHistoryBinding) -> Unit) {
        val b = _binding ?: return
        if (!isAdded) return
        block(b)
    }

    private fun formatCalls(calls: List<CallEntity>): String {
        if (calls.isEmpty()) return "\u041D\u0435\u0442 \u0434\u0430\u043D\u043D\u044B\u0445"
        return calls.joinToString("\n") {
            "• ${it.type} | ${dateTimeFormat.format(Date(it.timestamp))} | ${it.duration} \u0441\u0435\u043A"
        }
    }

    private fun loadStyledCallHistory(phone: String) {
        renderHistoryCards(listOf("\u0417\u0430\u0433\u0440\u0443\u0437\u043A\u0430..."))
        lifecycleScope.launch {
            val calls = withContext(Dispatchers.IO) { viewModel.getDeviceCallHistory(phone) }
            renderCallCards(calls)
        }
    }

    private fun loadRemoteReminders(phone: String) {
        Log.d(HISTORY_LOG_TAG, "\u041D\u0430\u0447\u0430\u043B\u043E \u0437\u0430\u0433\u0440\u0443\u0437\u043A\u0438 \u0438\u0441\u0442\u043E\u0440\u0438\u0438 \u043D\u0430\u043F\u043E\u043C\u0438\u043D\u0430\u043D\u0438\u0439: phone=$phone")
        renderHistoryCards(listOf("\u0417\u0430\u0433\u0440\u0443\u0437\u043A\u0430..."))
        lifecycleScope.launch {
            runCatching {
                val cached = withContext(Dispatchers.IO) { viewModel.getStoredReminders(phone) }
                Log.d(HISTORY_LOG_TAG, "\u041D\u0430\u043F\u043E\u043C\u0438\u043D\u0430\u043D\u0438\u044F \u0438\u0437 \u0432\u043D\u0443\u0442\u0440\u0435\u043D\u043D\u0435\u0439 \u043F\u0430\u043C\u044F\u0442\u0438: records=${cached.size}, phone=$phone")
                renderReminderCards(cached)

                val refreshResult = runCatching {
                    withContext(Dispatchers.IO) { viewModel.refreshRemindersFromRemote(phone) }
                }
                if (refreshResult.isSuccess) {
                    val updated = refreshResult.getOrDefault(emptyList<ReminderEntity>())
                    Log.d(HISTORY_LOG_TAG, "\u041D\u0430\u043F\u043E\u043C\u0438\u043D\u0430\u043D\u0438\u044F \u0438\u0437 \u0442\u0430\u0431\u043B\u0438\u0446\u044B \u0441\u043E\u0445\u0440\u0430\u043D\u0435\u043D\u044B \u0432 \u043F\u0430\u043C\u044F\u0442\u044C: records=${updated.size}, phone=$phone")
                    renderReminderCards(updated)
                } else {
                    Log.e(HISTORY_LOG_TAG, "\u041E\u0448\u0438\u0431\u043A\u0430 \u043E\u0431\u043D\u043E\u0432\u043B\u0435\u043D\u0438\u044F \u043D\u0430\u043F\u043E\u043C\u0438\u043D\u0430\u043D\u0438\u0439 \u0438\u0437 \u0442\u0430\u0431\u043B\u0438\u0446\u044B: phone=$phone", refreshResult.exceptionOrNull())
                    if (cached.isEmpty()) renderHistoryCards(listOf("\u041D\u0435\u0442 \u0438\u043D\u0442\u0435\u0440\u043D\u0435\u0442\u0430 \u0438\u043B\u0438 \u043E\u0448\u0438\u0431\u043A\u0430 \u0437\u0430\u0433\u0440\u0443\u0437\u043A\u0438"))
                }
            }.onFailure {
                Log.e(HISTORY_LOG_TAG, "\u041E\u0448\u0438\u0431\u043A\u0430 coroutine \u043D\u0430 \u044D\u043A\u0440\u0430\u043D\u0435 \u0438\u0441\u0442\u043E\u0440\u0438\u0438 \u043D\u0430\u043F\u043E\u043C\u0438\u043D\u0430\u043D\u0438\u0439: phone=$phone", it)
                renderHistoryCards(listOf("\u041E\u0448\u0438\u0431\u043A\u0430 \u0437\u0430\u0433\u0440\u0443\u0437\u043A\u0438 \u0438\u0441\u0442\u043E\u0440\u0438\u0438"))
            }
        }
    }

    private fun renderReminderCards(reminders: List<ReminderEntity>) {
        val latestReminders = reminders
            .filter { it.message.isNotBlank() }
            .sortedByDescending { it.remindAt }
            .take(20)
        Log.d(HISTORY_LOG_TAG, "\u0417\u0430\u043F\u0438\u0441\u0435\u0439 \u043D\u0430\u043F\u043E\u043C\u0438\u043D\u0430\u043D\u0438\u0439 \u043F\u043E\u0441\u043B\u0435 \u0444\u0438\u043B\u044C\u0442\u0440\u0430\u0446\u0438\u0438 UI: ${latestReminders.size}, source=${reminders.size}")
        if (latestReminders.isEmpty()) {
            renderHistoryCards(listOf("\u041D\u0435\u0442 \u0438\u0441\u0442\u043E\u0440\u0438\u0438"))
            return
        }
        renderHistoryCards(
            latestReminders.map { reminder ->
                "${dateTimeFormat.format(Date(reminder.remindAt))} | ${reminder.status} | ${reminder.message}"
            }
        )
    }

    private fun loadRemoteComments(phone: String) {
        Log.d(HISTORY_LOG_TAG, "\u041D\u0430\u0447\u0430\u043B\u043E \u0437\u0430\u0433\u0440\u0443\u0437\u043A\u0438 \u0438\u0441\u0442\u043E\u0440\u0438\u0438 \u043A\u043E\u043C\u043C\u0435\u043D\u0442\u0430\u0440\u0438\u0435\u0432: phone=$phone")
        renderHistoryCards(listOf("\u0417\u0430\u0433\u0440\u0443\u0437\u043A\u0430..."))
        lifecycleScope.launch {
            runCatching {
                val cached = withContext(Dispatchers.IO) { viewModel.getStoredComments(phone) }
                Log.d(HISTORY_LOG_TAG, "\u041A\u043E\u043C\u043C\u0435\u043D\u0442\u0430\u0440\u0438\u0438 \u0438\u0437 \u0432\u043D\u0443\u0442\u0440\u0435\u043D\u043D\u0435\u0439 \u043F\u0430\u043C\u044F\u0442\u0438: records=${cached.size}, phone=$phone")
                renderCommentCards(cached)

                val refreshResult = runCatching {
                    withContext(Dispatchers.IO) { viewModel.refreshCommentsFromRemote(phone) }
                }
                if (refreshResult.isSuccess) {
                    val updated = refreshResult.getOrDefault(emptyList<CommentEntity>())
                    Log.d(HISTORY_LOG_TAG, "\u041A\u043E\u043C\u043C\u0435\u043D\u0442\u0430\u0440\u0438\u0438 \u0438\u0437 \u0442\u0430\u0431\u043B\u0438\u0446\u044B \u0441\u043E\u0445\u0440\u0430\u043D\u0435\u043D\u044B \u0432 \u043F\u0430\u043C\u044F\u0442\u044C: records=${updated.size}, phone=$phone")
                    renderCommentCards(updated)
                } else {
                    Log.e(HISTORY_LOG_TAG, "\u041E\u0448\u0438\u0431\u043A\u0430 \u043E\u0431\u043D\u043E\u0432\u043B\u0435\u043D\u0438\u044F \u043A\u043E\u043C\u043C\u0435\u043D\u0442\u0430\u0440\u0438\u0435\u0432 \u0438\u0437 \u0442\u0430\u0431\u043B\u0438\u0446\u044B: phone=$phone", refreshResult.exceptionOrNull())
                    if (cached.isEmpty()) renderHistoryCards(listOf("\u041D\u0435\u0442 \u0438\u043D\u0442\u0435\u0440\u043D\u0435\u0442\u0430 \u0438\u043B\u0438 \u043E\u0448\u0438\u0431\u043A\u0430 \u0437\u0430\u0433\u0440\u0443\u0437\u043A\u0438"))
                }
            }.onFailure {
                Log.e(HISTORY_LOG_TAG, "\u041E\u0448\u0438\u0431\u043A\u0430 coroutine \u043D\u0430 \u044D\u043A\u0440\u0430\u043D\u0435 \u0438\u0441\u0442\u043E\u0440\u0438\u0438 \u043A\u043E\u043C\u043C\u0435\u043D\u0442\u0430\u0440\u0438\u0435\u0432: phone=$phone", it)
                renderHistoryCards(listOf("\u041E\u0448\u0438\u0431\u043A\u0430 \u0437\u0430\u0433\u0440\u0443\u0437\u043A\u0438 \u0438\u0441\u0442\u043E\u0440\u0438\u0438"))
            }
        }
    }

    private fun renderCommentCards(comments: List<CommentEntity>) {
        val latestComments = comments
            .filter { it.text.isNotBlank() }
            .sortedByDescending { it.createdAt }
            .take(20)
        Log.d(HISTORY_LOG_TAG, "\u0417\u0430\u043F\u0438\u0441\u0435\u0439 \u043A\u043E\u043C\u043C\u0435\u043D\u0442\u0430\u0440\u0438\u0435\u0432 \u043F\u043E\u0441\u043B\u0435 \u0444\u0438\u043B\u044C\u0442\u0440\u0430\u0446\u0438\u0438 UI: ${latestComments.size}, source=${comments.size}")
        if (latestComments.isEmpty()) {
            renderHistoryCards(listOf("\u041D\u0435\u0442 \u0438\u0441\u0442\u043E\u0440\u0438\u0438"))
            return
        }
        renderHistoryCards(
            latestComments.map { comment ->
                "${dateTimeFormat.format(Date(comment.createdAt))} | ${comment.text}"
            }
        )
    }

    private fun loadCachedThenRefresh(
        phone: String,
        historyType: String,
        onSuccess: (List<CallHistoryEntity>) -> List<String>
    ) {
        renderHistoryCards(listOf("\u0417\u0430\u0433\u0440\u0443\u0437\u043A\u0430..."))
        lifecycleScope.launch {
            runCatching {
                val cached = withContext(Dispatchers.IO) { viewModel.getHistory(phone) }
                Log.d(HISTORY_LOG_TAG, "\u041A\u044D\u0448 \u0438\u0441\u0442\u043E\u0440\u0438\u0438 $historyType: records=${cached.size}, phone=$phone")
                renderHistoryCards(onSuccess(cached))

                val refreshResult = runCatching { withContext(Dispatchers.IO) { viewModel.refreshHistory(phone) } }
                if (refreshResult.isSuccess) {
                    val updated = withContext(Dispatchers.IO) { viewModel.getHistory(phone) }
                    Log.d(HISTORY_LOG_TAG, "\u0423\u0441\u043F\u0435\u0448\u043D\u0430\u044F \u0437\u0430\u0433\u0440\u0443\u0437\u043A\u0430 \u0438\u0441\u0442\u043E\u0440\u0438\u0438 $historyType: records=${updated.size}, phone=$phone")
                    renderHistoryCards(onSuccess(updated))
                } else {
                    Log.e(HISTORY_LOG_TAG, "\u041E\u0448\u0438\u0431\u043A\u0430 \u043E\u0431\u043D\u043E\u0432\u043B\u0435\u043D\u0438\u044F \u0438\u0441\u0442\u043E\u0440\u0438\u0438 $historyType: phone=$phone", refreshResult.exceptionOrNull())
                    if (cached.isEmpty()) renderHistoryCards(listOf("\u041D\u0435\u0442 \u0438\u043D\u0442\u0435\u0440\u043D\u0435\u0442\u0430 \u0438\u043B\u0438 \u043E\u0448\u0438\u0431\u043A\u0430 \u0437\u0430\u0433\u0440\u0443\u0437\u043A\u0438"))
                }
            }.onFailure {
                Log.e(HISTORY_LOG_TAG, "\u041E\u0448\u0438\u0431\u043A\u0430 coroutine \u043D\u0430 \u044D\u043A\u0440\u0430\u043D\u0435 \u0438\u0441\u0442\u043E\u0440\u0438\u0438 $historyType: phone=$phone", it)
                renderHistoryCards(listOf("\u041E\u0448\u0438\u0431\u043A\u0430 \u0437\u0430\u0433\u0440\u0443\u0437\u043A\u0438 \u0438\u0441\u0442\u043E\u0440\u0438\u0438"))
            }
        }
    }

    private fun renderCallCards(items: List<CallHistoryEntity>) {
        if (_binding == null || !isAdded) return
        val latestCalls = items
            .sortedByDescending { parseHistoryDateTime(it.date, it.time) }
            .take(20)
        if (latestCalls.isEmpty()) {
            renderHistoryCards(listOf("\u041D\u0435\u0442 \u0438\u0441\u0442\u043E\u0440\u0438\u0438"))
            return
        }
        binding.historyContainer.removeAllViews()

        var currentDay = ""
        latestCalls.forEachIndexed { index, call ->
            val callTime = parseHistoryDateTime(call.date, call.time)
            val dayLabel = dayLabel(callTime)
            if (dayLabel != currentDay) {
                currentDay = dayLabel
                val dayView = TextView(requireContext()).apply {
                    text = dayLabel
                    textSize = 15f
                    setTextColor(resources.getColor(com.example.calltrack.R.color.textSecondary, null))
                    setPadding(6, if (index == 0) 2 else 12, 6, 8)
                }
                binding.historyContainer.addView(dayView)
            }

            val card = MaterialCardView(requireContext()).apply {
                radius = 14f
                cardElevation = 2f
                setCardBackgroundColor(resources.getColor(android.R.color.white, null))
                val lp = ViewGroup.MarginLayoutParams(
                    ViewGroup.LayoutParams.MATCH_PARENT,
                    ViewGroup.LayoutParams.WRAP_CONTENT
                )
                if (index > 0) lp.topMargin = 10
                layoutParams = lp
            }
            val content = TextView(requireContext()).apply {
                val (icon, color) = typeStyle(call.type)
                val title = "$icon ${call.type}"
                val duration = formatDurationVerbose(call.duration)
                val dateTime = "${humanDate(callTime)}, ${timeShortFormat.format(Date(callTime))}"
                val topLine = "$title    ⏱ $duration"
                val value = SpannableStringBuilder("$topLine\n$dateTime")
                value.setSpan(StyleSpan(Typeface.BOLD), 0, topLine.length, Spanned.SPAN_EXCLUSIVE_EXCLUSIVE)
                value.setSpan(ForegroundColorSpan(resources.getColor(color, null)), 0, title.length, Spanned.SPAN_EXCLUSIVE_EXCLUSIVE)
                value.setSpan(
                    ForegroundColorSpan(resources.getColor(com.example.calltrack.R.color.textSecondary, null)),
                    topLine.length + 1,
                    value.length,
                    Spanned.SPAN_EXCLUSIVE_EXCLUSIVE
                )
                text = value
                textSize = 15f
                setTextColor(resources.getColor(com.example.calltrack.R.color.textPrimary, null))
                setPadding(28, 22, 28, 22)
                setLineSpacing(8f, 1f)
            }
            card.addView(content)
            binding.historyContainer.addView(card)
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

    private fun formatDurationVerbose(duration: String): String {
        val totalSeconds = duration.toLongOrNull() ?: return duration
        val minutes = totalSeconds / 60
        val seconds = totalSeconds % 60
        return if (minutes > 0) "${minutes} \u043C\u0438\u043D ${seconds} \u0441\u0435\u043A" else "${seconds} \u0441\u0435\u043A"
    }

    private fun dayLabel(timestamp: Long): String {
        val nowDays = TimeUnit.MILLISECONDS.toDays(System.currentTimeMillis())
        val itemDays = TimeUnit.MILLISECONDS.toDays(timestamp)
        return when (nowDays - itemDays) {
            0L -> "\u0421\u0435\u0433\u043E\u0434\u043D\u044F"
            1L -> "\u0412\u0447\u0435\u0440\u0430"
            else -> dayFormat.format(Date(timestamp))
        }
    }

    private fun humanDate(timestamp: Long): String {
        return SimpleDateFormat("dd MMMM", Locale("ru")).format(Date(timestamp))
    }

    private fun typeStyle(type: String): Pair<String, Int> {
        return when (type.lowercase(Locale.getDefault())) {
            "\u0432\u0445\u043E\u0434\u044F\u0449\u0438\u0439" -> "📥" to com.example.calltrack.R.color.buttonColor
            "\u0438\u0441\u0445\u043E\u0434\u044F\u0449\u0438\u0439" -> "📤" to android.R.color.holo_blue_dark
            "\u043F\u0440\u043E\u043F\u0443\u0449\u0435\u043D\u043D\u044B\u0439", "\u043D\u0435\u043E\u0442\u0432\u0435\u0447\u0435\u043D\u043D\u044B\u0439", "\u0441\u0431\u0440\u043E\u0448\u0435\u043D\u043D\u044B\u0439" -> "📵" to android.R.color.holo_red_dark
            else -> "📞" to com.example.calltrack.R.color.textPrimary
        }
    }

    private fun renderHistoryCards(lines: List<String>) {
        withBinding { binding ->
            runCatching {
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
            }.onFailure {
                // Игнорируем ошибки рендера, чтобы не падать при уходе со страницы
            }
        }
    }

    private fun formatReminders(reminders: List<ReminderEntity>): String {
        if (reminders.isEmpty()) return "\u041D\u0435\u0442 \u0434\u0430\u043D\u043D\u044B\u0445"
        return reminders.joinToString("\n") {
            "• ${dateTimeFormat.format(Date(it.remindAt))} | ${it.status} | ${it.message}"
        }
    }

    private fun formatComments(comments: List<CommentEntity>): String {
        if (comments.isEmpty()) return "\u041D\u0435\u0442 \u0434\u0430\u043D\u043D\u044B\u0445"
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
        private const val HISTORY_LOG_TAG = "COMMENT_HISTORY"

        fun newInstance(phone: String, type: String) = ContactHistoryFragment().apply {
            arguments = Bundle().apply {
                putString(ARG_PHONE, phone)
                putString(ARG_TYPE, type)
            }
        }
    }
}
