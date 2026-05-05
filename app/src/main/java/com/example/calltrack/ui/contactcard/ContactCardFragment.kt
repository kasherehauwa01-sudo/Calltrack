package com.example.calltrack.ui.contactcard

import android.app.DatePickerDialog
import android.content.Intent
import android.net.Uri
import android.app.TimePickerDialog
import android.os.Bundle
import android.provider.ContactsContract
import android.text.InputFilter
import android.view.LayoutInflater
import android.view.View
import android.view.ViewGroup
import android.widget.Toast
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
                val client = viewModel.findClientName(phone)
                if (client.isNotBlank()) binding.tvClient1c.text = client
            }
        }

        viewModel.observeContact(phone).observe(viewLifecycleOwner) { contact ->
            val currentName = binding.tvContactName.text.toString()
            binding.tvContactName.text = contact?.name?.ifBlank { currentName } ?: currentName
            val fallbackClient = viewModel.findClientName(phone).ifBlank { "—" }
            binding.tvClient1c.text = contact?.client1c?.ifBlank { fallbackClient } ?: fallbackClient
        }
    }

    private fun showAddCommentDialog(phone: String) {
        val dialogBinding = DialogAddCommentBinding.inflate(layoutInflater)
        dialogBinding.etComment.filters = arrayOf(InputFilter.LengthFilter(500))

        AlertDialog.Builder(requireContext())
            .setTitle("Добавить комментарий")
            .setView(dialogBinding.root)
            .setNegativeButton("Отменить", null)
            .setPositiveButton("Сохранить") { _, _ ->
                val text = dialogBinding.etComment.text?.toString().orEmpty().trim()
                if (text.isBlank()) {
                    Toast.makeText(requireContext(), "Комментарий пустой", Toast.LENGTH_SHORT).show()
                    return@setPositiveButton
                }
                viewLifecycleOwner.lifecycleScope.launch {
                    viewModel.addComment(phone, text)
                    Toast.makeText(requireContext(), "Комментарий сохранён", Toast.LENGTH_SHORT).show()
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
            .setTitle("Добавить напоминание")
            .setView(dialogBinding.root)
            .setNegativeButton("Отменить", null)
            .setPositiveButton("Сохранить") { _, _ ->
                val reminderText = dialogBinding.etReminderText.text?.toString().orEmpty().trim()
                val remindAt = reminderAt
                saveReminder(phone, reminderText, remindAt)
            }
            .show()
    }

    private fun saveReminder(phone: String, reminderText: String, remindAt: Long?) {
        if (reminderText.isBlank()) {
            Toast.makeText(requireContext(), "Введите текст напоминания", Toast.LENGTH_SHORT).show()
            return
        }
        if (remindAt == null) {
            Toast.makeText(requireContext(), "Выберите дату и время напоминания", Toast.LENGTH_SHORT).show()
            return
        }

        val contactName = binding.tvContactName.text.toString().ifBlank { phone }
        viewLifecycleOwner.lifecycleScope.launch {
            viewModel.addReminder(phone, contactName, reminderText, remindAt)
            ReminderScheduler.schedule(requireContext(), phone, contactName, remindAt, reminderText)
            Toast.makeText(requireContext(), "Напоминание сохранено", Toast.LENGTH_SHORT).show()
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
