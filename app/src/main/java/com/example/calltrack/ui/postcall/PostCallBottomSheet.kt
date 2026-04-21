package com.example.calltrack.ui.postcall

import android.app.DatePickerDialog
import android.app.TimePickerDialog
import android.os.Bundle
import android.text.InputFilter
import android.view.LayoutInflater
import android.view.View
import android.view.ViewGroup
import android.widget.RadioGroup
import android.widget.Toast
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

        binding.groupOutcome.setOnCheckedChangeListener { _: RadioGroup, checkedId: Int ->
            binding.btnPickReminder.visibility = if (checkedId == binding.rbRecall.id) View.VISIBLE else View.GONE
            if (checkedId != binding.rbRecall.id) {
                reminderAtMillis = null
                binding.tvReminderValue.text = ""
            }
        }

        binding.btnPickReminder.setOnClickListener { pickDateTime() }

        binding.btnSave.setOnClickListener {
            val callId = requireArguments().getLong(ARG_CALL_ID)
            val phone = requireArguments().getString(ARG_PHONE).orEmpty()
            val contactName = requireArguments().getString(ARG_NAME).orEmpty().ifBlank { phone }
            val tag = buildTag()
            val note = binding.etComment.text?.toString().orEmpty().trim()

            if (binding.groupOutcome.checkedRadioButtonId == binding.rbRecall.id && reminderAtMillis == null) {
                Toast.makeText(requireContext(), "Для тега 'перезвонить' выберите дату и время", Toast.LENGTH_SHORT).show()
                return@setOnClickListener
            }

            lifecycleScope.launch {
                viewModel.saveCallOutcome(callId, phone, contactName, tag, reminderAtMillis, note)
                reminderAtMillis?.let { ReminderScheduler.schedule(requireContext(), phone, contactName, it) }
                viewModel.sync()
                dismissAllowingStateLoss()
            }
        }
    }

    private fun buildTag(): String {
        val temp = when (binding.groupTemp.checkedRadioButtonId) {
            binding.rbHot.id -> "горячий"
            binding.rbWarm.id -> "тёплый"
            binding.rbCold.id -> "холодный"
            else -> ""
        }
        val outcome = when (binding.groupOutcome.checkedRadioButtonId) {
            binding.rbDeal.id -> "договорились"
            binding.rbDecline.id -> "отказ"
            binding.rbRecall.id -> "перезвонить"
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
