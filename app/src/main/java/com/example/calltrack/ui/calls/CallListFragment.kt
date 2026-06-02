package com.example.calltrack.ui.calls

import android.app.AlertDialog
import android.app.DatePickerDialog
import android.app.TimePickerDialog
import android.content.Intent
import android.net.Uri
import android.os.Bundle
import android.provider.ContactsContract
import android.text.InputFilter
import android.util.Log
import android.widget.EditText
import android.widget.LinearLayout
import android.widget.Toast
import androidx.fragment.app.Fragment
import androidx.fragment.app.activityViewModels
import androidx.lifecycle.lifecycleScope
import androidx.recyclerview.widget.ItemTouchHelper
import androidx.recyclerview.widget.LinearLayoutManager
import androidx.recyclerview.widget.RecyclerView
import android.view.LayoutInflater
import android.view.View
import android.view.ViewGroup
import com.example.calltrack.App
import com.example.calltrack.data.local.CallEntity
import com.example.calltrack.databinding.FragmentCallListBinding
import com.example.calltrack.reminder.ReminderScheduler
import com.example.calltrack.ui.main.MainActivity
import com.example.calltrack.ui.main.MainViewModel
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.launch
import kotlinx.coroutines.withContext
import java.text.SimpleDateFormat
import java.util.Calendar
import java.util.Date
import java.util.Locale

class CallListFragment : Fragment() {

    private var _binding: FragmentCallListBinding? = null
    private val binding get() = _binding!!

    private val adapter by lazy {
        CallAdapter(
            onItemClick = { item ->
                if (item.call.phone.isNotBlank() && item.call.phone != "Неизвестно") {
                    (requireActivity() as MainActivity).openContactCard(item.call.phone)
                }
            },
            onCommentClick = { item -> showCommentDialog(item) },
            onReminderClick = { item -> showReminderDialog(item) }
        )
    }

    private val viewModel: MainViewModel by activityViewModels {
        MainViewModel.Factory((requireActivity().application as App).repository)
    }

    override fun onCreateView(inflater: LayoutInflater, container: ViewGroup?, savedInstanceState: Bundle?): View {
        _binding = FragmentCallListBinding.inflate(inflater, container, false)
        return binding.root
    }

    override fun onViewCreated(view: View, savedInstanceState: Bundle?) {
        binding.recyclerView.layoutManager = LinearLayoutManager(requireContext())
        binding.recyclerView.adapter = adapter
        attachSwipeToCall()

        // При открытии экрана «Последние» подтягиваем записи из стандартной звонилки Android.
        // Google Sheets здесь не читаем: таблица нужна только для отправки/истории, а список берём из CallLog.
        viewLifecycleOwner.lifecycleScope.launch {
            runCatching { viewModel.refreshRecentCallsFromDevice() }
                .onFailure { Log.e("CALL_LOG", "Ошибка загрузки последних звонков из системной звонилки", it) }
        }

        viewModel.calls.observe(viewLifecycleOwner) { calls ->
            viewLifecycleOwner.lifecycleScope.launch {
                adapter.submitList(resolveCallItems(calls))
            }
        }
    }

    private fun showCommentDialog(item: RecentCallListItem.CallRow) {
        val input = EditText(requireContext()).apply {
            hint = "Комментарий"
            filters = arrayOf(InputFilter.LengthFilter(500))
            setText(item.call.note)
        }
        AlertDialog.Builder(requireContext())
            .setTitle("Комментарий")
            .setView(input)
            .setPositiveButton("Ок") { dialog, _ ->
                val text = input.text.toString().trim()
                viewLifecycleOwner.lifecycleScope.launch {
                    viewModel.saveCommentForCall(item.call.id, item.call.phone, text)
                }
                dialog.dismiss()
            }
            .setNegativeButton("Отмена", null)
            .show()
    }

    private fun showReminderDialog(item: RecentCallListItem.CallRow) {
        val container = LinearLayout(requireContext()).apply {
            orientation = LinearLayout.VERTICAL
            setPadding(40, 20, 40, 0)
        }
        val textInput = EditText(requireContext()).apply {
            hint = "Текст напоминания"
            filters = arrayOf(InputFilter.LengthFilter(100))
        }
        val dateInput = EditText(requireContext()).apply {
            hint = "Дата и время"
            isFocusable = false
            isClickable = true
        }
        container.addView(textInput)
        container.addView(dateInput)

        var remindAt: Long? = null
        val formatter = SimpleDateFormat("dd.MM.yyyy HH:mm", Locale.getDefault())
        dateInput.setOnClickListener {
            val now = Calendar.getInstance()
            DatePickerDialog(
                requireContext(),
                { _, year, month, day ->
                    TimePickerDialog(
                        requireContext(),
                        { _, hour, minute ->
                            val selected = Calendar.getInstance().apply {
                                set(Calendar.YEAR, year)
                                set(Calendar.MONTH, month)
                                set(Calendar.DAY_OF_MONTH, day)
                                set(Calendar.HOUR_OF_DAY, hour)
                                set(Calendar.MINUTE, minute)
                                set(Calendar.SECOND, 0)
                            }
                            remindAt = selected.timeInMillis
                            dateInput.setText(formatter.format(selected.time))
                        },
                        now.get(Calendar.HOUR_OF_DAY),
                        now.get(Calendar.MINUTE),
                        true
                    ).show()
                },
                now.get(Calendar.YEAR),
                now.get(Calendar.MONTH),
                now.get(Calendar.DAY_OF_MONTH)
            ).show()
        }

        AlertDialog.Builder(requireContext())
            .setTitle("Напоминание")
            .setView(container)
            .setPositiveButton("Ок") { dialog, _ ->
                val text = textInput.text.toString().trim()
                val at = remindAt
                if (text.isBlank() || at == null) {
                    Toast.makeText(requireContext(), "Заполните текст и дату", Toast.LENGTH_SHORT).show()
                } else {
                    viewLifecycleOwner.lifecycleScope.launch {
                        viewModel.saveReminderForCall(item.call.id, item.call.phone, item.contactName, text, at)
                        ReminderScheduler.schedule(requireContext(), item.call.phone, item.contactName, at, text)
                    }
                    dialog.dismiss()
                }
            }
            .setNegativeButton("Отмена", null)
            .show()
    }

    private suspend fun resolveCallItems(calls: List<CallEntity>): List<RecentCallListItem> = withContext(Dispatchers.IO) {
        val nameByPhone = mutableMapOf<String, String>()
        requireContext().contentResolver.query(
            ContactsContract.CommonDataKinds.Phone.CONTENT_URI,
            arrayOf(
                ContactsContract.CommonDataKinds.Phone.DISPLAY_NAME,
                ContactsContract.CommonDataKinds.Phone.NUMBER
            ),
            null,
            null,
            null
        )?.use { cursor ->
            val nameIdx = cursor.getColumnIndexOrThrow(ContactsContract.CommonDataKinds.Phone.DISPLAY_NAME)
            val phoneIdx = cursor.getColumnIndexOrThrow(ContactsContract.CommonDataKinds.Phone.NUMBER)
            while (cursor.moveToNext()) {
                val name = cursor.getString(nameIdx).orEmpty()
                val phone = normalizePhone(cursor.getString(phoneIdx).orEmpty())
                if (phone.isNotBlank() && name.isNotBlank()) {
                    nameByPhone.putIfAbsent(phone, name)
                    if (phone.length > 10) {
                        nameByPhone.putIfAbsent(phone.takeLast(10), name)
                    }
                }
            }
        }

        val rows = calls.map { call ->
            val normalized = normalizePhone(call.phone)
            val short = normalized.takeLast(10)
            RecentCallListItem.CallRow(
                call = call,
                contactName = nameByPhone[normalized] ?: nameByPhone[short] ?: call.phone
            )
        }

        val output = mutableListOf<RecentCallListItem>()
        var lastHeader: String? = null
        rows.forEach { item ->
            val header = buildDateHeader(item.call.timestamp)
            if (header != lastHeader) {
                output += RecentCallListItem.Header(header)
                lastHeader = header
            }
            output += item
        }
        output
    }

    private fun buildDateHeader(timestamp: Long): String {
        val callDate = Calendar.getInstance().apply {
            timeInMillis = timestamp
            set(Calendar.HOUR_OF_DAY, 0)
            set(Calendar.MINUTE, 0)
            set(Calendar.SECOND, 0)
            set(Calendar.MILLISECOND, 0)
        }
        val today = Calendar.getInstance().apply {
            set(Calendar.HOUR_OF_DAY, 0)
            set(Calendar.MINUTE, 0)
            set(Calendar.SECOND, 0)
            set(Calendar.MILLISECOND, 0)
        }

        val yesterday = (today.clone() as Calendar).apply { add(Calendar.DAY_OF_YEAR, -1) }
        return when (callDate.timeInMillis) {
            today.timeInMillis -> "Сегодня"
            yesterday.timeInMillis -> "Вчера"
            else -> SimpleDateFormat("dd.MM.yyyy", Locale.getDefault()).format(Date(timestamp))
        }
    }

    private fun attachSwipeToCall() {
        val callback = object : ItemTouchHelper.SimpleCallback(0, ItemTouchHelper.RIGHT) {
            override fun onMove(
                recyclerView: RecyclerView,
                viewHolder: RecyclerView.ViewHolder,
                target: RecyclerView.ViewHolder
            ): Boolean = false

            override fun onSwiped(viewHolder: RecyclerView.ViewHolder, direction: Int) {
                val item = adapter.getItemAt(viewHolder.bindingAdapterPosition)
                adapter.notifyItemChanged(viewHolder.bindingAdapterPosition)
                if (item is RecentCallListItem.CallRow) {
                    makeDirectCall(item.call.phone)
                }
            }

            override fun getSwipeDirs(recyclerView: RecyclerView, viewHolder: RecyclerView.ViewHolder): Int {
                val item = adapter.getItemAt(viewHolder.bindingAdapterPosition)
                return if (item is RecentCallListItem.Header) 0 else super.getSwipeDirs(recyclerView, viewHolder)
            }
        }
        ItemTouchHelper(callback).attachToRecyclerView(binding.recyclerView)
    }

    private fun makeDirectCall(rawPhone: String) {
        val phone = rawPhone.trim()
        if (phone.isBlank() || phone == "Неизвестно") return

        val callIntent = Intent(Intent.ACTION_CALL, Uri.parse("tel:$phone"))
        val fallbackDialIntent = Intent(Intent.ACTION_DIAL, Uri.parse("tel:$phone"))
        runCatching {
            startActivity(callIntent)
        }.onFailure {
            Toast.makeText(requireContext(), "Нет разрешения на прямой вызов, открываю набор", Toast.LENGTH_SHORT).show()
            runCatching { startActivity(fallbackDialIntent) }
        }
    }

    private fun normalizePhone(value: String): String = value.filter { it.isDigit() }

    override fun onDestroyView() {
        _binding = null
        super.onDestroyView()
    }

    companion object {
        fun newInstance() = CallListFragment()
    }
}
