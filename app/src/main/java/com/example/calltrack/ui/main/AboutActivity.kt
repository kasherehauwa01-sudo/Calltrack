package com.example.calltrack.ui.main

import android.content.Intent
import android.content.pm.PackageInfo
import android.content.pm.PackageManager
import android.os.Build
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

        val packageInfo: PackageInfo = if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.TIRAMISU) {
            packageManager.getPackageInfo(packageName, PackageManager.PackageInfoFlags.of(0))
        } else {
            @Suppress("DEPRECATION")
            packageManager.getPackageInfo(packageName, 0)
        }
        val updatedAtMillis = packageInfo.lastUpdateTime
        val formatter = SimpleDateFormat("dd.MM.yyyy HH:mm", Locale.getDefault())
        binding.tvReleaseDateValue.text = formatter.format(Date(updatedAtMillis))

        binding.btnBack.setOnClickListener { finish() }
        binding.btnUpdateApp.setOnClickListener {
            startActivity(
                Intent(this, MainActivity::class.java).putExtra(
                    MainActivity.EXTRA_RUN_UPDATE_CHECK,
                    true
                )
            )
            finish()
        }
        binding.btnOpenLogs.setOnClickListener {
            startActivity(Intent(this, LogsActivity::class.java))
        }
    }
}
