package com.example.calltrack.ui.auth

import android.content.Intent
import android.os.Bundle
import android.view.View
import androidx.appcompat.app.AppCompatActivity
import androidx.lifecycle.lifecycleScope
import com.example.calltrack.auth.AndroidAuthClient
import com.example.calltrack.auth.EncryptedAuthStorageException
import com.example.calltrack.auth.SessionValidation
import com.example.calltrack.databinding.ActivityLoginBinding
import com.example.calltrack.logging.AppLogger
import com.example.calltrack.ui.main.MainActivity
import kotlinx.coroutines.CancellationException
import kotlinx.coroutines.launch

class LoginActivity : AppCompatActivity() {
    private lateinit var binding: ActivityLoginBinding

    override fun onCreate(savedInstanceState: Bundle?) {
        super.onCreate(savedInstanceState)
        binding = ActivityLoginBinding.inflate(layoutInflater)
        setContentView(binding.root)
        binding.retrySession.setOnClickListener { checkSavedSession() }
        binding.loginButton.setOnClickListener {
            val login = binding.loginEmail.text.toString().trim()
            val pin = binding.loginPin.text.toString()
            lifecycleScope.launch {
                setBusy(true)
                binding.loginStatus.text = ""
                try {
                    AndroidAuthClient(this@LoginActivity).login(login, pin)
                        .onSuccess { openApp() }
                        .onFailure { binding.loginStatus.text = it.message }
                } catch (error: EncryptedAuthStorageException) {
                    showStorageError(error)
                } finally {
                    setBusy(false)
                }
            }
        }
        checkSavedSession()
    }

    private fun checkSavedSession() {
        lifecycleScope.launch {
            setBusy(true)
            binding.loginStatus.text = "Проверка сохранённого входа…"
            binding.retrySession.visibility = View.GONE
            try {
                val auth = AndroidAuthClient(this@LoginActivity)
                when (auth.validate()) {
                    SessionValidation.VALID -> openApp()
                    SessionValidation.RETRYABLE -> {
                        // Keep collecting locally while the server/network is unavailable.
                        AppLogger.log(this@LoginActivity, "AUTH", "Session check temporarily unavailable; credentials retained")
                        openApp()
                    }
                    SessionValidation.REAUTHENTICATION_REQUIRED -> {
                        binding.loginStatus.text = "Сервер не подтвердил сессию. Повторите проверку или войдите снова. Неотправленные данные сохранены."
                        binding.retrySession.visibility = View.VISIBLE
                    }
                    SessionValidation.NO_SESSION -> binding.loginStatus.text = ""
                }
            } catch (error: CancellationException) {
                throw error
            } catch (error: EncryptedAuthStorageException) {
                showStorageError(error)
            } finally {
                setBusy(false)
            }
        }
    }

    private fun showStorageError(error: EncryptedAuthStorageException) {
        AppLogger.log(this, "AUTH", "Session storage unavailable; preserved: ${error.cause?.javaClass?.simpleName}")
        binding.loginStatus.text = "Не удалось прочитать сохранённый вход. Разблокируйте телефон и повторите проверку. Данные не удалены."
        binding.retrySession.visibility = View.VISIBLE
    }

    private fun setBusy(busy: Boolean) {
        binding.loginButton.isEnabled = !busy
        binding.loginEmail.isEnabled = !busy
        binding.loginPin.isEnabled = !busy
        binding.retrySession.isEnabled = !busy
    }

    private fun openApp() {
        startActivity(Intent(this, MainActivity::class.java))
        finish()
    }
}
