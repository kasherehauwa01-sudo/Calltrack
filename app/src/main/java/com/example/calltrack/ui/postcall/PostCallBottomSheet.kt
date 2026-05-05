package com.example.calltrack.ui.postcall

import android.app.DatePickerDialog
import android.app.TimePickerDialog
import android.os.Bundle
import android.text.InputFilter
import android.view.LayoutInflater
import android.view.View
import android.view.ViewGroup
import android.view.inputmethod.InputMethodManager
import android.widget.Toast
import androidx.core.widget.doAfterTextChanged
import androidx.fragment.app.activityViewModels
import androidx.lifecycle.lifecycleScope
import com.example.calltrack.App
import com.example.calltrack.databinding.DialogPostCallBinding
import com.example.calltrack.reminder.ReminderScheduler
import com.example.calltrack.ui.main.MainViewModel
import com.google.android.material.bottomsheet.BottomSheetDialogFragment
import kotlinx.coroutines.launch
import java.text.SimpleDateFormat
import java.util.Calendar
import java.util.Locale

class PostCallBottomSheet : BottomSheetDialogFragment() {

    override fun getTheme(): Int = com.example.calltrack.R.style.AppDialogTheme

    private var _binding: DialogPostCallBinding? = null
    private val binding get() = _binding!!

    private val viewModel: MainViewModel by activityViewModels {
        MainViewModel.Factory((requireActivity().application as App).repository)
    }

    private var reminderAtMillis: Long? = null
    private val formatter = SimpleDateFormat("dd.MM.yyyy HH:mm", Locale.getDefault())

    override fun onCreateView(inflater: LayoutInflater, container: ViewGroup?, savedInstanceState: Bundle?): View {
        _binding = DialogPostCallBinding.inflate(inflater, container, false)
        return binding.root
    }

    override fun onViewCreated(view: View, savedInstanceState: Bundle?) {
        binding.etComment.filters = arrayOf(InputFilter.LengthFilter(500))
        binding.btnSave.isEnabled = false

        binding.groupOutcome.setOnCheckedStateChangeListener { _, checkedIds ->
            val checkedId = checkedIds.firstOrNull() ?: View.NO_ID
            if (checkedId == View.NO_ID) {
                binding.cardReminder.visibility = View.GONE
                updateSaveState()
                return@setOnCheckedStateChangeListener
            }
            animateSelection(checkedId)
            val isRecall = checkedId == binding.chipOutcomeRecall.id
            binding.cardReminder.visibility = if (isRecall) View.VISIBLE else View.GONE
            if (!isRecall) {
                reminderAtMillis = null
                binding.tvReminderValue.text = ""
            }
            updateSaveState()
        }

        binding.groupTemp.setOnCheckedStateChangeListener { _, checkedIds ->
            checkedIds.firstOrNull()?.let { animateSelection(it) }
            updateSaveState()
        }
        binding.btnPickReminder.setOnClickListener { pickDateTime() }
        binding.etComment.doAfterTextChanged { updateSaveState() }
        binding.rootContent.setOnClickListener { hideKeyboard() }
        binding.etComment.setOnFocusChangeListener { _, hasFocus -> if (!hasFocus) hideKeyboard() }

        binding.btnSave.setOnClickListener {
            val callId = requireArguments().getLong(ARG_CALL_ID)
            val phone = requireArguments().getString(ARG_PHONE).orEmpty()
            val contactName = requireArguments().getString(ARG_NAME).orEmpty().ifBlank { phone }
            val tag = buildTag()
            val note = binding.etComment.text?.toString().orEmpty().trim()

            if (binding.groupOutcome.checkedChipId == binding.chipOutcomeRecall.id && reminderAtMillis == null) {
                Toast.makeText(requireContext(), "Для тега 'перезвонить' выберите дату и время", Toast.LENGTH_SHORT).show()
                return@setOnClickListener
            }

            lifecycleScope.launch {
                viewModel.saveCallOutcome(callId, phone, contactName, tag, reminderAtMillis, note)
                reminderAtMillis?.let { ReminderScheduler.schedule(requireContext(), phone, contactName, it, "Перезвонить клиенту") }
                viewModel.sync()
                dismissAllowingStateLoss()
            }
        }
    }

    private fun buildTag(): String {
        val temp = when (binding.groupTemp.checkedChipId) {
            binding.chipHot.id -> "горячий"
            binding.chipWarm.id -> "тёплый"
            binding.chipCold.id -> "холодный"
            else -> ""
        }
        val outcome = when (binding.groupOutcome.checkedChipId) {
            binding.chipOutcomeDeal.id -> "договорились"
            binding.chipOutcomeDecline.id -> "отказ"
            binding.chipOutcomeRecall.id -> "перезвонить"
            else -> ""
        }
        return listOf(
            temp.takeIf { it.isNotBlank() }?.let { "Температура: $it" },
            outcome.takeIf { it.isNotBlank() }?.let { "Итог: $it" }
        ).filterNotNull().joinToString("; ")
    }

    private fun pickDateTime() {
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
                        binding.tvReminderValue.text = formatter.format(selected.time)
                        updateSaveState()
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
            binding.groupTemp.checkedChipId != View.NO_ID ||
                binding.groupOutcome.checkedChipId != View.NO_ID ||
                reminderAtMillis != null ||
                !binding.etComment.text.isNullOrBlank()
        binding.btnSave.isEnabled = hasAnyChange
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
