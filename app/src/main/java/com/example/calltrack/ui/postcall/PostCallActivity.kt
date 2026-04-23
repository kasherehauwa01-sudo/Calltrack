package com.example.calltrack.ui.postcall

import android.app.DatePickerDialog
import android.app.TimePickerDialog
import android.os.Build
import android.os.Bundle
import android.text.InputFilter
import android.view.View
import android.view.WindowManager
import android.view.inputmethod.InputMethodManager
import android.widget.Toast
import androidx.core.widget.doAfterTextChanged
import androidx.appcompat.app.AppCompatActivity
import androidx.core.view.ViewCompat
import androidx.core.view.WindowInsetsCompat
import com.example.calltrack.App
import com.example.calltrack.databinding.DialogPostCallBinding
import com.example.calltrack.reminder.ReminderScheduler
import kotlinx.coroutines.CoroutineScope
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.SupervisorJob
import kotlinx.coroutines.launch
import java.text.SimpleDateFormat
import java.util.Calendar
import java.util.Locale

class PostCallActivity : AppCompatActivity() {

    private lateinit var binding: DialogPostCallBinding
    private var reminderAtMillis: Long? = null
    private val formatter = SimpleDateFormat("dd.MM.yyyy HH:mm", Locale.getDefault())
    private val saveScope = CoroutineScope(SupervisorJob() + Dispatchers.IO)

    override fun onCreate(savedInstanceState: Bundle?) {
        if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.O_MR1) {
            setShowWhenLocked(true)
            setTurnScreenOn(true)
        } else {
            @Suppress("DEPRECATION")
            window.addFlags(
                WindowManager.LayoutParams.FLAG_SHOW_WHEN_LOCKED or
                    WindowManager.LayoutParams.FLAG_TURN_SCREEN_ON
            )
        }
        window.addFlags(WindowManager.LayoutParams.FLAG_KEEP_SCREEN_ON)

        super.onCreate(savedInstanceState)
        binding = DialogPostCallBinding.inflate(layoutInflater)
        setContentView(binding.root)

        val subscriberName = intent.getStringExtra(EXTRA_NAME).orEmpty().ifBlank {
            intent.getStringExtra(EXTRA_PHONE).orEmpty()
        }
        binding.tvSubscriberName.text = subscriberName

        binding.etComment.filters = arrayOf(InputFilter.LengthFilter(500))
        binding.btnSave.isEnabled = false
        applySystemInsets()

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

        binding.btnPickReminder.setOnClickListener { pickDateTime() }
        binding.groupTemp.setOnCheckedStateChangeListener { _, checkedIds ->
            checkedIds.firstOrNull()?.let { animateSelection(it) }
            updateSaveState()
        }
        binding.etComment.doAfterTextChanged { updateSaveState() }
        binding.rootContent.setOnClickListener { hideKeyboard() }
        binding.etComment.setOnFocusChangeListener { _, hasFocus -> if (!hasFocus) hideKeyboard() }

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

            if (binding.groupOutcome.checkedChipId == binding.chipOutcomeRecall.id && reminderAtMillis == null) {
                Toast.makeText(this, "Для тега 'перезвонить' выберите дату и время", Toast.LENGTH_SHORT).show()
                return@setOnClickListener
            }

            finish()
            saveScope.launch {
                val repository = (application as App).repository
                repository.saveCallOutcome(callId, phone, contactName, tag, reminderAtMillis, note)
                reminderAtMillis?.let { ReminderScheduler.schedule(this@PostCallActivity, phone, contactName, it, "Перезвонить клиенту") }
                repository.syncPending()
            }
        }

        binding.btnCancel.setOnClickListener { finish() }
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
        findViewById<View>(viewId)?.animate()?.scaleX(1.03f)?.scaleY(1.03f)?.setDuration(100)?.withEndAction {
            findViewById<View>(viewId)?.animate()?.scaleX(1f)?.scaleY(1f)?.setDuration(100)?.start()
        }?.start()
    }

    private fun hideKeyboard() {
        val imm = getSystemService(InputMethodManager::class.java)
        imm?.hideSoftInputFromWindow(currentFocus?.windowToken, 0)
        currentFocus?.clearFocus()
    }

    private fun applySystemInsets() {
        val initialTop = binding.rootContent.paddingTop
        val initialBottom = binding.rootContent.paddingBottom
        ViewCompat.setOnApplyWindowInsetsListener(binding.root) { _, insets ->
            val systemBars = insets.getInsets(WindowInsetsCompat.Type.systemBars())
            binding.rootContent.setPadding(
                binding.rootContent.paddingLeft,
                initialTop + systemBars.top,
                binding.rootContent.paddingRight,
                initialBottom + systemBars.bottom
            )
            insets
        }
        ViewCompat.requestApplyInsets(binding.root)
    }

    companion object {
        const val EXTRA_CALL_ID = "extra_call_id"
        const val EXTRA_PHONE = "extra_phone"
        const val EXTRA_NAME = "extra_name"
    }
}
