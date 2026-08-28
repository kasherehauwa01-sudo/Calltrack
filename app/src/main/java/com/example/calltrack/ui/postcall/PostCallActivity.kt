package com.example.calltrack.ui.postcall

import android.Manifest
import android.app.DatePickerDialog
import android.app.TimePickerDialog
import android.content.Intent
import android.net.Uri
import android.content.pm.PackageManager
import android.os.Bundle
import android.text.InputFilter
import android.view.View
import android.view.inputmethod.InputMethodManager
import android.widget.Toast
import androidx.appcompat.app.AlertDialog
import androidx.core.content.ContextCompat
import androidx.core.widget.doAfterTextChanged
import com.example.calltrack.App
import com.example.calltrack.databinding.DialogAddReminderBinding
import com.example.calltrack.databinding.DialogPostCallBinding
import com.example.calltrack.reminder.ReminderScheduler
import com.example.calltrack.ui.base.BaseActivity
import kotlinx.coroutines.CoroutineScope
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.SupervisorJob
import kotlinx.coroutines.launch
import java.text.SimpleDateFormat
import java.util.Calendar
import java.util.Locale

class PostCallActivity : BaseActivity() {

    private lateinit var binding: DialogPostCallBinding
    private var reminderAtMillis: Long? = null
    private var reminderText: String = ""
    private val formatter = SimpleDateFormat("dd.MM.yyyy HH:mm", Locale.getDefault())
    private val saveScope = CoroutineScope(SupervisorJob() + Dispatchers.IO)

    override fun onCreate(savedInstanceState: Bundle?) {
        super.onCreate(savedInstanceState)
        binding = DialogPostCallBinding.inflate(layoutInflater)
        setContentView(binding.root)

        val subscriberName = intent.getStringExtra(EXTRA_NAME).orEmpty().ifBlank {
            intent.getStringExtra(EXTRA_PHONE).orEmpty()
        }
        binding.tvSubscriberName.text = subscriberName

        binding.etComment.filters = arrayOf(InputFilter.LengthFilter(500))
        binding.btnSave.isEnabled = false
        applyInsets(binding.root, binding.statusBarOverlay)

        binding.groupOutcome.setOnCheckedStateChangeListener { _, checkedIds ->
            checkedIds.firstOrNull()?.let { animateSelection(it) }
            updateSaveState()
        }
        binding.btnAddReminder.setOnClickListener { showReminderDialog() }
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
                Toast.makeText(this, "\u041D\u0435 \u0443\u0434\u0430\u043B\u043E\u0441\u044C \u043E\u043F\u0440\u0435\u0434\u0435\u043B\u0438\u0442\u044C \u0437\u0432\u043E\u043D\u043E\u043A", Toast.LENGTH_SHORT).show()
                finish()
                return@setOnClickListener
            }

            finish()
            saveScope.launch {
                val repository = (application as App).repository
                repository.saveCallOutcome(callId, phone, contactName, tag, reminderAtMillis, note, reminderText)
                reminderAtMillis?.let {
                    ReminderScheduler.schedule(
                        this@PostCallActivity,
                        phone,
                        contactName,
                        it,
                        reminderText.ifBlank { "\u041F\u0435\u0440\u0435\u0437\u0432\u043E\u043D\u0438\u0442\u044C \u043A\u043B\u0438\u0435\u043D\u0442\u0443" }
                    )
                }
            }
        }

        binding.btnCancel.setOnClickListener { finish() }
        binding.btnCallNow.setOnClickListener { callSubscriberNow() }
    }

    private fun buildTag(): String {
        val outcome = when (binding.groupOutcome.checkedChipId) {
            binding.chipOutcomeDeal.id -> "\u0434\u043E\u0433\u043E\u0432\u043E\u0440\u0438\u043B\u0438\u0441\u044C"
            binding.chipOutcomeDecline.id -> "\u043E\u0442\u043A\u0430\u0437"
            binding.chipOutcomeRecall.id -> "\u043F\u0435\u0440\u0435\u0437\u0432\u043E\u043D\u0438\u0442\u044C"
            binding.chipOutcomePotential.id -> "\u043F\u043E\u0442\u0435\u043D\u0446\u0438\u0430\u043B\u044C\u043D\u044B\u0439 \u043A\u043B\u0438\u0435\u043D\u0442"
            else -> ""
        }
        return outcome.takeIf { it.isNotBlank() }?.let { "\u0418\u0442\u043E\u0433: $it" }.orEmpty()
    }

    private fun showReminderDialog() {
        val dialogBinding = DialogAddReminderBinding.inflate(layoutInflater)
        dialogBinding.etReminderText.filters = arrayOf(InputFilter.LengthFilter(100))
        dialogBinding.etReminderText.setText(reminderText)
        dialogBinding.tvReminderDate.text = reminderAtMillis?.let { formatter.format(java.util.Date(it)) } ?: "\u0414\u0430\u0442\u0430 \u0438 \u0432\u0440\u0435\u043C\u044F \u043D\u0435 \u0432\u044B\u0431\u0440\u0430\u043D\u044B"
        dialogBinding.btnPickDate.setOnClickListener { pickDateTime(dialogBinding) }

        AlertDialog.Builder(this)
            .setTitle("\u0414\u043E\u0431\u0430\u0432\u0438\u0442\u044C \u043D\u0430\u043F\u043E\u043C\u0438\u043D\u0430\u043D\u0438\u0435")
            .setView(dialogBinding.root)
            .setNegativeButton("\u041E\u0442\u043C\u0435\u043D\u0430", null)
            .setPositiveButton("\u0421\u043E\u0445\u0440\u0430\u043D\u0438\u0442\u044C") { _, _ ->
                reminderText = dialogBinding.etReminderText.text?.toString().orEmpty().trim()
                updateReminderPreview()
                updateSaveState()
            }
            .show()
    }

    private fun pickDateTime(dialogBinding: DialogAddReminderBinding) {
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
            "\u041D\u0430\u043F\u043E\u043C\u0438\u043D\u0430\u043D\u0438\u0435: ${reminderText.ifBlank { "\u0411\u0435\u0437 \u0442\u0435\u043A\u0441\u0442\u0430" }}\n${formatter.format(java.util.Date(reminderAtMillis ?: System.currentTimeMillis()))}"
        }
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

    private fun callSubscriberNow() {
        val phone = intent.getStringExtra(EXTRA_PHONE).orEmpty().trim()
        if (phone.isBlank()) {
            Toast.makeText(this, "\u041D\u043E\u043C\u0435\u0440 \u0442\u0435\u043B\u0435\u0444\u043E\u043D\u0430 \u043D\u0435 \u043D\u0430\u0439\u0434\u0435\u043D", Toast.LENGTH_SHORT).show()
            return
        }
        if (ContextCompat.checkSelfPermission(this, Manifest.permission.CALL_PHONE) != PackageManager.PERMISSION_GRANTED) {
            Toast.makeText(this, "\u041D\u0435\u0442 \u0440\u0430\u0437\u0440\u0435\u0448\u0435\u043D\u0438\u044F \u043D\u0430 \u0437\u0432\u043E\u043D\u043E\u043A", Toast.LENGTH_SHORT).show()
            return
        }
        startActivity(Intent(Intent.ACTION_CALL, Uri.parse("tel:$phone")))
    }

    companion object {
        const val EXTRA_CALL_ID = "extra_call_id"
        const val EXTRA_PHONE = "extra_phone"
        const val EXTRA_NAME = "extra_name"
    }
}
