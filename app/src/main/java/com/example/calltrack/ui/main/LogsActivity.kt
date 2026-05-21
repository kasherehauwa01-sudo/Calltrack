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
            Toast.makeText(this, "Логи пока отсутствуют", Toast.LENGTH_SHORT).show()
            return
        }

        val uri = FileProvider.getUriForFile(this, "$packageName.fileprovider", file)
        val send = Intent(Intent.ACTION_SEND).apply {
            type = "text/plain"
            putExtra(Intent.EXTRA_STREAM, uri)
            putExtra(Intent.EXTRA_TEXT, AppLogger.readLogs(this@LogsActivity))
            putExtra(Intent.EXTRA_SUBJECT, "Логи приложения Calltrack")
            clipData = ClipData.newUri(contentResolver, "app_logs", uri)
            addFlags(Intent.FLAG_GRANT_READ_URI_PERMISSION)
        }
        val chooser = Intent.createChooser(send, "Поделиться логами")
        if (chooser.resolveActivity(packageManager) == null) {
            Toast.makeText(this, "Нет доступных приложений для отправки", Toast.LENGTH_SHORT).show()
            return
        }
        startActivity(chooser)
    }

    private fun copyLogs() {
        val logsText = AppLogger.readLogs(this)
        if (logsText.isBlank()) {
            Toast.makeText(this, "Логи пустые", Toast.LENGTH_SHORT).show()
            return
        }

        val clipboard = getSystemService(CLIPBOARD_SERVICE) as ClipboardManager
        val clip = ClipData(ClipDescription("app_logs", arrayOf("text/plain")), ClipData.Item(logsText))
        clipboard.setPrimaryClip(clip)
        Toast.makeText(this, "Логи скопированы", Toast.LENGTH_SHORT).show()
    }
}
