package com.example.calltrack.ui.contactcard

import android.os.Bundle
import android.widget.Toast
import androidx.appcompat.app.AlertDialog
import androidx.appcompat.app.AppCompatActivity
import androidx.lifecycle.lifecycleScope
import com.example.calltrack.App
import com.example.calltrack.databinding.ActivityContactActionBinding
import kotlinx.coroutines.launch

class ContactActionActivity : AppCompatActivity() {

    private lateinit var binding: ActivityContactActionBinding
    private val repository by lazy { (application as App).repository }
    private var phone: String = ""
    private var isPersonal = false

    override fun onCreate(savedInstanceState: Bundle?) {
        super.onCreate(savedInstanceState)
        binding = ActivityContactActionBinding.inflate(layoutInflater)
        setContentView(binding.root)

        phone = intent.getStringExtra(EXTRA_PHONE).orEmpty()
        binding.tvPhone.text = phone

        binding.btnBack.setOnClickListener { finish() }

        if (intent.getBooleanExtra(EXTRA_SHOW_ADD_TO_1C_DIALOG, false)) {
            showAddTo1cInfoAndClose()
            return
        }
        binding.btnAddTo1c.setOnClickListener {
            AlertDialog.Builder(this)
                .setMessage("\u0427\u0442\u043E\u0431\u044B \u043E\u043F\u0440\u0435\u0434\u0435\u043B\u0438\u0442\u044C \u043A\u043B\u0438\u0435\u043D\u0442\u0430, \u0434\u043E\u0431\u0430\u0432\u044C\u0442\u0435 \u043D\u043E\u043C\u0435\u0440 \u0442\u0435\u043B\u0435\u0444\u043E\u043D\u0430 \u0432 \u0435\u0433\u043E \u043A\u0430\u0440\u0442\u043E\u0447\u043A\u0443 \u0432 1\u0421.")
                .setPositiveButton("\u041E\u043A", null)
                .show()
        }

        lifecycleScope.launch {
            isPersonal = repository.isPersonalContact(phone)
            renderPersonalButton()
        }

        binding.btnMarkPersonal.setOnClickListener {
            lifecycleScope.launch {
                if (isPersonal) {
                    repository.unmarkPersonalContact(phone)
                    isPersonal = false
                    Toast.makeText(this@ContactActionActivity, "\u041F\u043E\u043C\u0435\u0442\u043A\u0430 \u043B\u0438\u0447\u043D\u043E\u0433\u043E \u043A\u043E\u043D\u0442\u0430\u043A\u0442\u0430 \u0443\u0431\u0440\u0430\u043D\u0430", Toast.LENGTH_SHORT).show()
                } else {
                    repository.markAsPersonalContact(phone)
                    isPersonal = true
                    Toast.makeText(this@ContactActionActivity, "\u041A\u043E\u043D\u0442\u0430\u043A\u0442 \u043F\u043E\u043C\u0435\u0447\u0435\u043D \u043A\u0430\u043A \u043B\u0438\u0447\u043D\u044B\u0439", Toast.LENGTH_SHORT).show()
                }
                renderPersonalButton()
            }
        }
    }


    private fun showAddTo1cInfoAndClose() {
        AlertDialog.Builder(this)
            .setMessage("\u0427\u0442\u043E\u0431\u044B \u043E\u043F\u0440\u0435\u0434\u0435\u043B\u0438\u0442\u044C \u043A\u043B\u0438\u0435\u043D\u0442\u0430, \u0434\u043E\u0431\u0430\u0432\u044C\u0442\u0435 \u043D\u043E\u043C\u0435\u0440 \u0442\u0435\u043B\u0435\u0444\u043E\u043D\u0430 \u0432 \u0435\u0433\u043E \u043A\u0430\u0440\u0442\u043E\u0447\u043A\u0443 \u0432 1\u0421.")
            .setPositiveButton("\u041E\u043A") { _, _ -> finish() }
            .setOnCancelListener { finish() }
            .show()
    }

    private fun renderPersonalButton() {
        if (isPersonal) {
            binding.btnMarkPersonal.text = "\u0423\u0431\u0440\u0430\u0442\u044C \u043F\u043E\u043C\u0435\u0442\u043A\u0443 \"\u041B\u0438\u0447\u043D\u044B\u0439 \u043A\u043E\u043D\u0442\u0430\u043A\u0442\""
            binding.btnMarkPersonal.setBackgroundColor(0xFF9E9E9E.toInt())
        } else {
            binding.btnMarkPersonal.text = "\u041F\u043E\u043C\u0435\u0442\u0438\u0442\u044C \u043A\u0430\u043A \u043B\u0438\u0447\u043D\u044B\u0439 \u043A\u043E\u043D\u0442\u0430\u043A\u0442"
            binding.btnMarkPersonal.setBackgroundColor(0xFF4CAF50.toInt())
        }
    }

    companion object {
        const val EXTRA_PHONE = "extra_phone"
        const val EXTRA_SHOW_ADD_TO_1C_DIALOG = "extra_show_add_to_1c_dialog"
    }
}
