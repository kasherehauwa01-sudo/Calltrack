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
        binding.btnAddTo1c.setOnClickListener {
            AlertDialog.Builder(this)
                .setMessage("Чтобы определить клиента, добавьте номер телефона в его карточку в 1С.")
                .setPositiveButton("Ок") { _, _ -> finish() }
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
                    Toast.makeText(this@ContactActionActivity, "Пометка личного контакта убрана", Toast.LENGTH_SHORT).show()
                } else {
                    repository.markAsPersonalContact(phone)
                    isPersonal = true
                    Toast.makeText(this@ContactActionActivity, "Контакт помечен как личный", Toast.LENGTH_SHORT).show()
                }
                renderPersonalButton()
            }
        }
    }

    private fun renderPersonalButton() {
        if (isPersonal) {
            binding.btnMarkPersonal.text = "Убрать пометку \"Личный контакт\""
            binding.btnMarkPersonal.setBackgroundColor(0xFF9E9E9E.toInt())
        } else {
            binding.btnMarkPersonal.text = "Пометить как личный контакт"
            binding.btnMarkPersonal.setBackgroundColor(0xFF4CAF50.toInt())
        }
    }

    companion object {
        const val EXTRA_PHONE = "extra_phone"
    }
}
