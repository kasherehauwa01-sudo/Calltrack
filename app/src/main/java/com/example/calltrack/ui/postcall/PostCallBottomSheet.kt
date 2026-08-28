package com.example.calltrack.ui.postcall

import android.Manifest
import android.app.DatePickerDialog
import android.app.TimePickerDialog
import android.content.Intent
import android.content.pm.PackageManager
import android.net.Uri
import android.os.Bundle
import android.text.InputFilter
import android.view.LayoutInflater
import android.view.View
import android.view.ViewGroup
import android.view.inputmethod.InputMethodManager
import androidx.appcompat.app.AlertDialog
import androidx.core.content.ContextCompat
import androidx.core.widget.doAfterTextChanged
import androidx.fragment.app.activityViewModels
import androidx.lifecycle.lifecycleScope
import com.example.calltrack.App
import com.example.calltrack.databinding.DialogPostCallBinding
import com.example.calltrack.databinding.DialogAddReminderBinding
import com.example.calltrack.reminder.ReminderScheduler
import com.example.calltrack.ui.main.MainViewModel
import com.example.calltrack.ui.base.installMojibakeRepair
import com.google.android.material.bottomsheet.BottomSheetDialogFragment
import kotlinx.coroutines.launch
import java.text.SimpleDateFormat
import java.util.Calendar
import java.util.Locale

class PostCallBottomSheet : BottomSheetDialogFragment() {

    override fun onStart() {
        dialog?.installMojibakeRepair()
        super.onStart()
    }

    override fun getTheme(): Int = com.example.calltrack.R.style.AppDialogTheme

    private var _binding: DialogPostCallBinding? = null
    private val binding get() = _binding!!

    private val viewModel: MainViewModel by activityViewModels {
        MainViewModel.Factory((requireActivity().application as App).repository)
    }

    private var reminderAtMillis: Long? = null
    private var reminderText: String = ""
    private val formatter = SimpleDateFormat("dd.MM.yyyy HH:mm", Locale.getDefault())

    override fun onCreateView(inflater: LayoutInflater, container: ViewGroup?, savedInstanceState: Bundle?): View {
        _binding = DialogPostCallBinding.inflate(inflater, container, false)
        return binding.root
    }

    override fun onViewCreated(view: View, savedInstanceState: Bundle?) {
        binding.etComment.filters = arrayOf(InputFilter.LengthFilter(500))
        binding.btnSave.isEnabled = false

        binding.groupOutcome.setOnCheckedStateChangeListener { _, checkedIds ->
            checkedIds.firstOrNull()?.let { animateSelection(it) }
            updateSaveState()
        }
        binding.btnAddReminder.setOnClickListener { showReminderDialog() }
        binding.etComment.doAfterTextChanged { updateSaveState() }
        binding.rootContent.setOnClickListener { hideKeyboard() }
        binding.etComment.setOnFocusChangeListener { _, hasFocus -> if (!hasFocus) hideKeyboard() }

        binding.btnSave.setOnClickListener {
            val callId = requireArguments().getLong(ARG_CALL_ID)
            val phone = requireArguments().getString(ARG_PHONE).orEmpty()
            val contactName = requireArguments().getString(ARG_NAME).orEmpty().ifBlank { phone }
            val tag = buildTag()
            val note = binding.etComment.text?.toString().orEmpty().trim()

            lifecycleScope.launch {
                viewModel.saveCallOutcome(callId, phone, contactName, tag, reminderAtMillis, note, reminderText)
                reminderAtMillis?.let {
                    ReminderScheduler.schedule(
                        requireContext(),
                        phone,
                        contactName,
                        it,
                        reminderText.ifBlank { "Перезвонить клиенту" }
                    )
                }
                dismissAllowingStateLoss()
            }
        }
        binding.btnCallNow.setOnClickListener { callSubscriberNow() }
    }

    private fun buildTag(): String {
        val outcome = when (binding.groupOutcome.checkedChipId) {
            binding.chipOutcomeDeal.id -> "договорились"
            binding.chipOutcomeDecline.id -> "отказ"
            binding.chipOutcomeRecall.id -> "перезвонить"
            binding.chipOutcomePotential.id -> "потенциальный клиент"
            else -> ""
        }
        return outcome.takeIf { it.isNotBlank() }?.let { "Итог: $it" }.orEmpty()
    }

    private fun showReminderDialog() {
        val dialogBinding = DialogAddReminderBinding.inflate(layoutInflater)
        dialogBinding.etReminderText.filters = arrayOf(InputFilter.LengthFilter(100))
        dialogBinding.etReminderText.setText(reminderText)
        dialogBinding.tvReminderDate.text = reminderAtMillis?.let { formatter.format(java.util.Date(it)) } ?: "Дата и время не выбраны"
        dialogBinding.btnPickDate.setOnClickListener { pickDateTime(dialogBinding) }

        AlertDialog.Builder(requireContext())
            .setTitle("Добавить напоминание")
            .setView(dialogBinding.root)
            .setNegativeButton("Отмена", null)
            .setPositiveButton("Сохранить") { _, _ ->
                reminderText = dialogBinding.etReminderText.text?.toString().orEmpty().trim()
                updateReminderPreview()
                updateSaveState()
            }
            .show()
    }

    private fun pickDateTime(dialogBinding: DialogAddReminderBinding) {
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
                        reminderAtMillis = selected.timeInMillis
                        dialogBinding.tvReminderDate.text = formatter.format(selected.time)
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

    private fun updateSaveState() {
        val hasAnyChange =
            binding.groupOutcome.checkedChipId != View.NO_ID ||
                reminderAtMillis != null ||
                reminderText.isNotBlank() ||
                !binding.etComment.text.isNullOrBlank()
        binding.btnSave.isEnabled = hasAnyChange
    }

    private fun updateReminderPreview() {
        binding.tvReminderValue.text = if (reminderAtMillis == null && reminderText.isBlank()) {
            ""
        } else {
            "Напоминание: ${reminderText.ifBlank { "Без текста" }}\n${formatter.format(java.util.Date(reminderAtMillis ?: System.currentTimeMillis()))}"
        }
    }

    private fun animateSelection(viewId: Int) {
        binding.root.findViewById<View>(viewId)?.animate()?.scaleX(1.03f)?.scaleY(1.03f)?.setDuration(100)?.withEndAction {
            binding.root.findViewById<View>(viewId)?.animate()?.scaleX(1f)?.scaleY(1f)?.setDuration(100)?.start()
        }?.start()
    }

    private fun hideKeyboard() {
        val imm = requireContext().getSystemService(InputMethodManager::class.java)
        imm?.hideSoftInputFromWindow(view?.windowToken, 0)
        binding.etComment.clearFocus()
    }

    private fun callSubscriberNow() {
        val phone = requireArguments().getString(ARG_PHONE).orEmpty().trim()
        if (phone.isBlank()) {
            return
        }
        if (ContextCompat.checkSelfPermission(requireContext(), Manifest.permission.CALL_PHONE) != PackageManager.PERMISSION_GRANTED) {
            return
        }
        startActivity(Intent(Intent.ACTION_CALL, Uri.parse("tel:$phone")))
    }

    override fun onDestroyView() {
        _binding = null
        super.onDestroyView()
    }

    companion object {
        private const val ARG_CALL_ID = "arg_call_id"
        private const val ARG_PHONE = "arg_phone"
        private const val ARG_NAME = "arg_name"

        fun newInstance(callId: Long, phone: String, name: String = ""): PostCallBottomSheet {
            return PostCallBottomSheet().apply {
                arguments = Bundle().apply {
                    putLong(ARG_CALL_ID, callId)
                    putString(ARG_PHONE, phone)
                    putString(ARG_NAME, name)
                }
            }
        }
    }
}
