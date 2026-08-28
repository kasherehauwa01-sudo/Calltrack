package com.example.calltrack.ui.contactcard

import android.app.DatePickerDialog
import android.content.Intent
import android.net.Uri
import android.app.TimePickerDialog
import android.os.Bundle
import android.provider.ContactsContract
import android.text.InputFilter
import android.text.SpannableString
import android.text.style.UnderlineSpan
import android.view.LayoutInflater
import android.view.View
import android.view.ViewGroup
import android.widget.Toast
import android.widget.LinearLayout
import android.widget.ScrollView
import android.widget.TextView
import android.widget.ProgressBar
import androidx.appcompat.app.AlertDialog
import androidx.fragment.app.Fragment
import androidx.fragment.app.activityViewModels
import androidx.lifecycle.lifecycleScope
import com.example.calltrack.App
import com.example.calltrack.databinding.DialogAddCommentBinding
import com.example.calltrack.databinding.DialogAddReminderBinding
import com.example.calltrack.databinding.FragmentContactCardBinding
import com.example.calltrack.reminder.ReminderScheduler
import com.example.calltrack.ui.main.MainViewModel
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.launch
import kotlinx.coroutines.withContext
import java.text.SimpleDateFormat
import java.util.Calendar
import java.util.Date
import java.util.Locale

class ContactCardFragment : Fragment() {

    private var _binding: FragmentContactCardBinding? = null
    private val binding get() = _binding!!

    private val viewModel: MainViewModel by activityViewModels {
        MainViewModel.Factory((requireActivity().application as App).repository)
    }

    private val dateTimeFormat = SimpleDateFormat("dd.MM.yyyy HH:mm", Locale.getDefault())
    private var currentPhone: String = ""
    private var isPersonalContact: Boolean = false
    private var clientCardLoading: Boolean = false
    private var clientCardLoadingDialog: AlertDialog? = null

    override fun onCreateView(inflater: LayoutInflater, container: ViewGroup?, savedInstanceState: Bundle?): View {
        _binding = FragmentContactCardBinding.inflate(inflater, container, false)
        return binding.root
    }

    override fun onViewCreated(view: View, savedInstanceState: Bundle?) {
        val phone = requireArguments().getString(ARG_PHONE).orEmpty()
        currentPhone = phone
        binding.tvContactPhone.text = phone
        binding.tvContactName.text = phone
        binding.tvClient1c.text = viewModel.findClientName(phone).ifBlank { "—" }
        binding.rowClient1c.setOnClickListener {
            val clientName = binding.tvClient1c.text.toString().trim()
            if (clientName.isNotBlank() && clientName != "—" && clientName != "\u041B\u0438\u0447\u043D\u044B\u0439") {
                showClientCard(phone, clientName)
            }
        }

        binding.btnBack.setOnClickListener {
            requireActivity().onBackPressedDispatcher.onBackPressed()
        }
        binding.btnCall.setOnClickListener {
            if (currentPhone.isNotBlank()) {
                startActivity(Intent(Intent.ACTION_DIAL, Uri.parse("tel:$currentPhone")))
            }
        }
        binding.btnAddComment.setOnClickListener { showAddCommentDialog(phone) }
        binding.btnAddReminder.setOnClickListener { showAddReminderDialog(phone) }
        binding.btnMarkPersonal.setOnClickListener {
            viewLifecycleOwner.lifecycleScope.launch {
                if (isPersonalContact) {
                    if (viewModel.unmarkPersonalContact(phone)) {
                        binding.tvClient1c.text = "—"
                        isPersonalContact = false
                        renderPersonalButtonState()
                        renderClientLinkState()
                        Toast.makeText(requireContext(), "\u041F\u043E\u043C\u0435\u0442\u043A\u0430 \u043B\u0438\u0447\u043D\u043E\u0433\u043E \u043A\u043E\u043D\u0442\u0430\u043A\u0442\u0430 \u0443\u0431\u0440\u0430\u043D\u0430", Toast.LENGTH_SHORT).show()
                    } else {
                        Toast.makeText(requireContext(), "\u041D\u0435 \u0443\u0434\u0430\u043B\u043E\u0441\u044C \u0443\u0431\u0440\u0430\u0442\u044C \u043F\u043E\u043C\u0435\u0442\u043A\u0443 \u043B\u0438\u0447\u043D\u043E\u0433\u043E \u043A\u043E\u043D\u0442\u0430\u043A\u0442\u0430", Toast.LENGTH_SHORT).show()
                    }
                } else {
                    if (viewModel.markAsPersonalContact(phone)) {
                        binding.tvClient1c.text = "\u041B\u0438\u0447\u043D\u044B\u0439"
                        isPersonalContact = true
                        renderPersonalButtonState()
                        renderClientLinkState()
                        Toast.makeText(requireContext(), "\u041A\u043E\u043D\u0442\u0430\u043A\u0442 \u043F\u043E\u043C\u0435\u0447\u0435\u043D \u043A\u0430\u043A \u043B\u0438\u0447\u043D\u044B\u0439", Toast.LENGTH_SHORT).show()
                    } else {
                        Toast.makeText(requireContext(), "\u041D\u0435 \u0443\u0434\u0430\u043B\u043E\u0441\u044C \u043F\u043E\u043C\u0435\u0442\u0438\u0442\u044C \u043A\u043E\u043D\u0442\u0430\u043A\u0442 \u043A\u0430\u043A \u043B\u0438\u0447\u043D\u044B\u0439", Toast.LENGTH_SHORT).show()
                    }
                }
            }
        }
        binding.rowCallsHistory.setOnClickListener {
            (requireActivity() as com.example.calltrack.ui.main.MainActivity)
                .openContactHistory(phone, ContactHistoryFragment.TYPE_CALLS)
        }
        binding.rowRemindersHistory.setOnClickListener {
            (requireActivity() as com.example.calltrack.ui.main.MainActivity)
                .openContactHistory(phone, ContactHistoryFragment.TYPE_REMINDERS)
        }
        binding.rowCommentsHistory.setOnClickListener {
            (requireActivity() as com.example.calltrack.ui.main.MainActivity)
                .openContactHistory(phone, ContactHistoryFragment.TYPE_COMMENTS)
        }

        viewLifecycleOwner.lifecycleScope.launch {
            val fallbackName = findContactNameInPhoneBook(phone)
            if (binding.tvContactName.text.toString() == phone && !fallbackName.isNullOrBlank()) {
                binding.tvContactName.text = fallbackName
            }
            if (binding.tvClient1c.text.toString() == "—") {
                // Общий справочник прогревается асинхронно и при первом открытии
                // карточки ещё может быть пуст. Точечный запрос по телефону ждём
                // в IO-потоке, чтобы сразу показать актуальное имя из SQLite-кэша.
                val client = withContext(Dispatchers.IO) {
                    viewModel.loadClientCards(phone).firstOrNull()?.name.orEmpty()
                }
                if (client.isNotBlank()) {
                    binding.tvClient1c.text = client
                    renderClientLinkState()
                }
            }
        }

        viewModel.observeContact(phone).observe(viewLifecycleOwner) { contact ->
            val currentName = binding.tvContactName.text.toString()
            binding.tvContactName.text = contact?.name?.ifBlank { currentName } ?: currentName
            val fallbackClient = viewModel.findClientName(phone).ifBlank { "—" }
            binding.tvClient1c.text = contact?.client1c?.ifBlank { fallbackClient } ?: fallbackClient
            isPersonalContact = binding.tvClient1c.text.toString() == "\u041B\u0438\u0447\u043D\u044B\u0439"
            renderPersonalButtonState()
            renderClientLinkState()
        }
        renderClientLinkState()
    }

    private fun renderClientLinkState() {
        val name = binding.tvClient1c.text.toString().trim()
        val clickable = name.isNotBlank() && name != "—" && name != "\u041B\u0438\u0447\u043D\u044B\u0439"
        // Нажатие обрабатывает вся строка, иначе дочерний TextView перехватывает
        // событие и обработчик карточки не вызывается.
        binding.tvClient1c.isClickable = false
        binding.tvClient1c.isFocusable = false
        binding.rowClient1c.isClickable = clickable
        binding.rowClient1c.isFocusable = clickable
        binding.tvClient1c.text = if (clickable) {
            SpannableString(name).apply { setSpan(UnderlineSpan(), 0, length, 0) }
        } else {
            name
        }
    }

    private fun showClientCard(phone: String, clientName: String) {
        if (clientCardLoading) return
        clientCardLoading = true
        clientCardLoadingDialog = AlertDialog.Builder(requireContext())
            .setTitle("\u041A\u0430\u0440\u0442\u043E\u0447\u043A\u0430 \u043A\u043B\u0438\u0435\u043D\u0442\u0430")
            .setMessage("\u0417\u0430\u0433\u0440\u0443\u0437\u043A\u0430 \u0434\u0430\u043D\u043D\u044B\u0445...")
            .setView(ProgressBar(requireContext()))
            .setCancelable(false)
            .show()
        viewLifecycleOwner.lifecycleScope.launch {
            val cards = runCatching { viewModel.loadClientCards(phone) }.getOrElse { error ->
                clientCardLoading = false
                clientCardLoadingDialog?.dismiss()
                clientCardLoadingDialog = null
                Toast.makeText(requireContext(), "\u041D\u0435 \u0443\u0434\u0430\u043B\u043E\u0441\u044C \u0437\u0430\u0433\u0440\u0443\u0437\u0438\u0442\u044C \u043A\u0430\u0440\u0442\u043E\u0447\u043A\u0443: ${error.message}", Toast.LENGTH_LONG).show()
                return@launch
            }
            clientCardLoading = false
            clientCardLoadingDialog?.dismiss()
            clientCardLoadingDialog = null
            val card = cards.firstOrNull { it.name == clientName } ?: cards.firstOrNull()
            if (card == null) {
                Toast.makeText(requireContext(), "\u041A\u0430\u0440\u0442\u043E\u0447\u043A\u0430 \u043E\u0442\u0441\u0443\u0442\u0441\u0442\u0432\u0443\u0435\u0442 \u0432 \u043B\u043E\u043A\u0430\u043B\u044C\u043D\u043E\u043C \u043A\u044D\u0448\u0435 Clients", Toast.LENGTH_LONG).show()
                return@launch
            }
            val padding = (16 * resources.displayMetrics.density).toInt()
            val content = LinearLayout(requireContext()).apply {
                orientation = LinearLayout.VERTICAL
                setPadding(padding, padding / 2, padding, padding / 2)
            }
            card.fields.forEach { (label, value) ->
                content.addView(TextView(requireContext()).apply {
                    text = label
                    textSize = 13f
                    setTextColor(0xFF6B7280.toInt())
                    setPadding(0, padding / 2, 0, 2)
                })
                content.addView(TextView(requireContext()).apply {
                    text = value
                    textSize = 17f
                    setTextColor(0xFF1F2937.toInt())
                    setTextIsSelectable(true)
                })
            }
            AlertDialog.Builder(requireContext())
                .setTitle(card.name.ifBlank { clientName })
                .setView(ScrollView(requireContext()).apply { addView(content) })
                .setPositiveButton("\u0417\u0430\u043A\u0440\u044B\u0442\u044C", null)
                .show()
        }
    }

    private fun renderPersonalButtonState() {
        if (isPersonalContact) {
            binding.btnMarkPersonal.text = "\u0423\u0431\u0440\u0430\u0442\u044C \u043F\u043E\u043C\u0435\u0442\u043A\u0443 \"\u041B\u0438\u0447\u043D\u044B\u0439 \u043A\u043E\u043D\u0442\u0430\u043A\u0442\""
            binding.btnMarkPersonal.setBackgroundColor(0xFF9E9E9E.toInt())
        } else {
            binding.btnMarkPersonal.text = "\u041F\u043E\u043C\u0435\u0442\u0438\u0442\u044C \u043A\u0430\u043A \u043B\u0438\u0447\u043D\u044B\u0439 \u043A\u043E\u043D\u0442\u0430\u043A\u0442"
            binding.btnMarkPersonal.setBackgroundColor(0xFF4CAF50.toInt())
        }
    }

    private fun showAddCommentDialog(phone: String) {
        val dialogBinding = DialogAddCommentBinding.inflate(layoutInflater)
        dialogBinding.etComment.filters = arrayOf(InputFilter.LengthFilter(500))

        AlertDialog.Builder(requireContext())
            .setTitle("\u0414\u043E\u0431\u0430\u0432\u0438\u0442\u044C \u043A\u043E\u043C\u043C\u0435\u043D\u0442\u0430\u0440\u0438\u0439")
            .setView(dialogBinding.root)
            .setNegativeButton("\u041E\u0442\u043C\u0435\u043D\u0438\u0442\u044C", null)
            .setPositiveButton("\u0421\u043E\u0445\u0440\u0430\u043D\u0438\u0442\u044C") { _, _ ->
                val text = dialogBinding.etComment.text?.toString().orEmpty().trim()
                if (text.isBlank()) {
                    Toast.makeText(requireContext(), "\u041A\u043E\u043C\u043C\u0435\u043D\u0442\u0430\u0440\u0438\u0439 \u043F\u0443\u0441\u0442\u043E\u0439", Toast.LENGTH_SHORT).show()
                    return@setPositiveButton
                }
                viewLifecycleOwner.lifecycleScope.launch {
                    viewModel.addComment(phone, text)
                    Toast.makeText(requireContext(), "\u041A\u043E\u043C\u043C\u0435\u043D\u0442\u0430\u0440\u0438\u0439 \u0441\u043E\u0445\u0440\u0430\u043D\u0451\u043D", Toast.LENGTH_SHORT).show()
                }
            }
            .show()
    }

    private fun showAddReminderDialog(phone: String) {
        val dialogBinding = DialogAddReminderBinding.inflate(layoutInflater)
        dialogBinding.etReminderText.filters = arrayOf(InputFilter.LengthFilter(100))
        var reminderAt: Long? = null

        dialogBinding.btnPickDate.setOnClickListener {
            pickReminderDateTime { selectedAt ->
                reminderAt = selectedAt
                dialogBinding.tvReminderDate.text = dateTimeFormat.format(Date(selectedAt))
            }
        }

        AlertDialog.Builder(requireContext())
            .setTitle("\u0414\u043E\u0431\u0430\u0432\u0438\u0442\u044C \u043D\u0430\u043F\u043E\u043C\u0438\u043D\u0430\u043D\u0438\u0435")
            .setView(dialogBinding.root)
            .setNegativeButton("\u041E\u0442\u043C\u0435\u043D\u0438\u0442\u044C", null)
            .setPositiveButton("\u0421\u043E\u0445\u0440\u0430\u043D\u0438\u0442\u044C") { _, _ ->
                val reminderText = dialogBinding.etReminderText.text?.toString().orEmpty().trim()
                val remindAt = reminderAt
                saveReminder(phone, reminderText, remindAt)
            }
            .show()
    }

    private fun saveReminder(phone: String, reminderText: String, remindAt: Long?) {
        if (reminderText.isBlank()) {
            Toast.makeText(requireContext(), "\u0412\u0432\u0435\u0434\u0438\u0442\u0435 \u0442\u0435\u043A\u0441\u0442 \u043D\u0430\u043F\u043E\u043C\u0438\u043D\u0430\u043D\u0438\u044F", Toast.LENGTH_SHORT).show()
            return
        }
        if (remindAt == null) {
            Toast.makeText(requireContext(), "\u0412\u044B\u0431\u0435\u0440\u0438\u0442\u0435 \u0434\u0430\u0442\u0443 \u0438 \u0432\u0440\u0435\u043C\u044F \u043D\u0430\u043F\u043E\u043C\u0438\u043D\u0430\u043D\u0438\u044F", Toast.LENGTH_SHORT).show()
            return
        }

        val contactName = binding.tvContactName.text.toString().ifBlank { phone }
        viewLifecycleOwner.lifecycleScope.launch {
            viewModel.addReminder(phone, contactName, reminderText, remindAt)
            ReminderScheduler.schedule(requireContext(), phone, contactName, remindAt, reminderText)
            Toast.makeText(requireContext(), "\u041D\u0430\u043F\u043E\u043C\u0438\u043D\u0430\u043D\u0438\u0435 \u0441\u043E\u0445\u0440\u0430\u043D\u0435\u043D\u043E", Toast.LENGTH_SHORT).show()
        }
    }

    private fun pickReminderDateTime(onSelected: (Long) -> Unit) {
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
                        onSelected(selected.timeInMillis)
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


    private suspend fun findContactNameInPhoneBook(phone: String): String? = withContext(Dispatchers.IO) {
        val target = normalizePhone(phone).takeLast(10)
        if (target.isBlank()) return@withContext null

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
                val candidate = normalizePhone(cursor.getString(phoneIdx).orEmpty()).takeLast(10)
                if (candidate == target) {
                    return@withContext cursor.getString(nameIdx).orEmpty().ifBlank { null }
                }
            }
        }
        null
    }

    private fun normalizePhone(value: String): String = value.filter { it.isDigit() }

    override fun onDestroyView() {
        clientCardLoadingDialog?.dismiss()
        clientCardLoadingDialog = null
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
