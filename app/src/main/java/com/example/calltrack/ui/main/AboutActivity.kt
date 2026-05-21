package com.example.calltrack.ui.main

import android.content.Intent
import android.os.Bundle
import com.example.calltrack.databinding.ActivityAboutBinding
import com.example.calltrack.ui.base.BaseActivity
import java.text.SimpleDateFormat
import java.util.Date
import java.util.Locale

class AboutActivity : BaseActivity() {

    private lateinit var binding: ActivityAboutBinding

    override fun onCreate(savedInstanceState: Bundle?) {
        super.onCreate(savedInstanceState)
        binding = ActivityAboutBinding.inflate(layoutInflater)
        setContentView(binding.root)
        applyInsets(binding.root, binding.statusBarOverlay)

        val updatedAtMillis = packageManager.getPackageInfo(packageName, 0).lastUpdateTime
        val formatter = SimpleDateFormat("dd.MM.yyyy HH:mm", Locale.getDefault())
        binding.tvReleaseDateValue.text = formatter.format(Date(updatedAtMillis))

        binding.btnBack.setOnClickListener { finish() }
        binding.btnOpenLogs.setOnClickListener {
            startActivity(Intent(this, LogsActivity::class.java))
        }
    }
}
