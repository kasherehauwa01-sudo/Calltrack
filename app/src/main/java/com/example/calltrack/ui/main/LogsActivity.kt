package com.example.calltrack.ui.main

import android.content.Intent
import android.content.ClipData
import android.content.ClipboardManager
import android.content.ClipDescription
import android.os.Bundle
import android.widget.Toast
import androidx.core.content.FileProvider
import com.example.calltrack.databinding.ActivityLogsBinding
import com.example.calltrack.logging.AppLogger
import com.example.calltrack.ui.base.BaseActivity

class LogsActivity : BaseActivity() {
    private lateinit var binding: ActivityLogsBinding

    override fun onCreate(savedInstanceState: Bundle?) {
        super.onCreate(savedInstanceState)
        binding = ActivityLogsBinding.inflate(layoutInflater)
        setContentView(binding.root)
        applyInsets(binding.root, binding.statusBarOverlay)

        binding.tvLogs.text = AppLogger.readLogs(this)
        binding.btnBack.setOnClickListener { finish() }
        binding.btnShare.setOnClickListener { shareLogs() }
        binding.btnCopy.setOnClickListener { copyLogs() }
    }

    private fun shareLogs() {
        val file = AppLogger.logFile(this)
        if (!file.exists()) {
            Toast.makeText(this, "\u041B\u043E\u0433\u0438 \u043F\u043E\u043A\u0430 \u043E\u0442\u0441\u0443\u0442\u0441\u0442\u0432\u0443\u044E\u0442", Toast.LENGTH_SHORT).show()
            return
        }

        val uri = FileProvider.getUriForFile(this, "$packageName.fileprovider", file)
        val send = Intent(Intent.ACTION_SEND).apply {
            type = "text/plain"
            putExtra(Intent.EXTRA_STREAM, uri)
            putExtra(Intent.EXTRA_SUBJECT, "\u041B\u043E\u0433\u0438 \u043F\u0440\u0438\u043B\u043E\u0436\u0435\u043D\u0438\u044F Calltrack")
            clipData = ClipData.newUri(contentResolver, "app_logs", uri)
            addFlags(Intent.FLAG_GRANT_READ_URI_PERMISSION)
        }
        val chooser = Intent.createChooser(send, "\u041F\u043E\u0434\u0435\u043B\u0438\u0442\u044C\u0441\u044F \u043B\u043E\u0433\u0430\u043C\u0438")
        if (chooser.resolveActivity(packageManager) == null) {
            Toast.makeText(this, "\u041D\u0435\u0442 \u0434\u043E\u0441\u0442\u0443\u043F\u043D\u044B\u0445 \u043F\u0440\u0438\u043B\u043E\u0436\u0435\u043D\u0438\u0439 \u0434\u043B\u044F \u043E\u0442\u043F\u0440\u0430\u0432\u043A\u0438", Toast.LENGTH_SHORT).show()
            return
        }
        startActivity(chooser)
    }

    private fun copyLogs() {
        val logsText = AppLogger.readLogs(this)
        if (logsText.isBlank()) {
            Toast.makeText(this, "\u041B\u043E\u0433\u0438 \u043F\u0443\u0441\u0442\u044B\u0435", Toast.LENGTH_SHORT).show()
            return
        }

        val clipboard = getSystemService(CLIPBOARD_SERVICE) as ClipboardManager
        val clip = ClipData(ClipDescription("app_logs", arrayOf("text/plain")), ClipData.Item(logsText))
        clipboard.setPrimaryClip(clip)
        Toast.makeText(this, "\u041B\u043E\u0433\u0438 \u0441\u043A\u043E\u043F\u0438\u0440\u043E\u0432\u0430\u043D\u044B", Toast.LENGTH_SHORT).show()
    }
}
