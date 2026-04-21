package com.example.calltrack.ui.postcall

import android.app.DatePickerDialog
import android.app.TimePickerDialog
import android.os.Bundle
import android.text.InputFilter
import android.view.View
import android.widget.RadioGroup
import android.widget.Toast
import androidx.appcompat.app.AppCompatActivity
import androidx.lifecycle.lifecycleScope
import com.example.calltrack.App
import com.example.calltrack.databinding.DialogPostCallBinding
import com.example.calltrack.reminder.ReminderScheduler
import kotlinx.coroutines.launch
import java.text.SimpleDateFormat
import java.util.Calendar
import java.util.Locale

class PostCallActivity : AppCompatActivity() {

    private lateinit var binding: DialogPostCallBinding
    private var reminderAtMillis: Long? = null
    private val formatter = SimpleDateFormat("dd.MM.yyyy HH:mm", Locale.getDefault())

    override fun onCreate(savedInstanceState: Bundle?) {
        super.onCreate(savedInstanceState)
        binding = DialogPostCallBinding.inflate(layoutInflater)
        setContentView(binding.root)

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
            val callId = intent.getLongExtra(EXTRA_CALL_ID, 0L)
            val phone = intent.getStringExtra(EXTRA_PHONE).orEmpty()
            val contactName = intent.getStringExtra(EXTRA_NAME).orEmpty().ifBlank { phone }
            val note = binding.etComment.text?.toString().orEmpty().trim()
            val tag = buildTag()

            if (callId == 0L || phone.isBlank()) {
                Toast.makeText(this, "Не удалось определить звонок", Toast.LENGTH_SHORT).show()
                finish()
                return@setOnClickListener
            }

            if (binding.groupOutcome.checkedRadioButtonId == binding.rbRecall.id && reminderAtMillis == null) {
                Toast.makeText(this, "Для тега 'перезвонить' выберите дату и время", Toast.LENGTH_SHORT).show()
                return@setOnClickListener
            }

            lifecycleScope.launch {
                val repository = (application as App).repository
                repository.saveCallOutcome(callId, phone, contactName, tag, reminderAtMillis, note)
                reminderAtMillis?.let { ReminderScheduler.schedule(this@PostCallActivity, phone, contactName, it) }
                repository.syncPending()
                finish()
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
            this,
            { _, year, month, day ->
                TimePickerDialog(
                    this,
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

    companion object {
        const val EXTRA_CALL_ID = "extra_call_id"
        const val EXTRA_PHONE = "extra_phone"
        const val EXTRA_NAME = "extra_name"
    }
}
