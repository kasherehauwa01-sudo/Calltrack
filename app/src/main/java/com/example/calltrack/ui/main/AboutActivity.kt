package com.example.calltrack.ui.main

import android.content.Intent
import android.content.pm.PackageInfo
import android.content.pm.PackageManager
import android.os.Build
import android.os.Bundle
import android.widget.PopupMenu
import com.example.calltrack.R
import com.example.calltrack.ui.analytics.AnalyticsActivity
import com.example.calltrack.BuildConfig
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
        onBackPressedDispatcher.addCallback(this) { openMain(MainActivity.EXTRA_OPEN_DIAL) }

        val packageInfo: PackageInfo = if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.TIRAMISU) {
            packageManager.getPackageInfo(packageName, PackageManager.PackageInfoFlags.of(0))
        } else {
            @Suppress("DEPRECATION")
            packageManager.getPackageInfo(packageName, 0)
        }
        val updatedAtMillis = packageInfo.lastUpdateTime
        val formatter = SimpleDateFormat("dd.MM.yyyy HH:mm", Locale.getDefault())
        binding.tvVersionValue.text = "${BuildConfig.VERSION_NAME} (${BuildConfig.VERSION_CODE})"
        binding.tvReleaseDateValue.text = formatter.format(Date(updatedAtMillis))

        binding.btnBack.setOnClickListener { finish() }
        binding.btnAnalytics.setOnClickListener { startActivity(Intent(this, AnalyticsActivity::class.java)); finish() }
        binding.btnNotifications.setOnClickListener { openMain(MainActivity.EXTRA_OPEN_NOTIFICATIONS) }
        binding.btnMenu.setOnClickListener { anchor ->
            PopupMenu(this, anchor).apply {
                menu.add(0, 1, 0, getString(R.string.settings))
                menu.add(0, 2, 1, getString(R.string.user))
                setOnMenuItemClickListener { item ->
                    openMain(if (item.itemId == 1) MainActivity.EXTRA_OPEN_SETTINGS else MainActivity.EXTRA_OPEN_USER)
                    true
                }
                show()
            }
        }
        binding.btnLogs.setOnClickListener {
            startActivity(Intent(this, LogsActivity::class.java))
        }
        binding.btnUpdateApp.setOnClickListener {
            startActivity(
                Intent(this, MainActivity::class.java)
                    .addFlags(Intent.FLAG_ACTIVITY_CLEAR_TOP or Intent.FLAG_ACTIVITY_SINGLE_TOP)
                    .putExtra(
                        MainActivity.EXTRA_RUN_UPDATE_CHECK,
                        true
                    )
            )
            finish()
        }
    }

    private fun openMain(destinationExtra: String) {
        startActivity(
            Intent(this, MainActivity::class.java)
                .addFlags(Intent.FLAG_ACTIVITY_CLEAR_TOP or Intent.FLAG_ACTIVITY_SINGLE_TOP)
                .putExtra(destinationExtra, true)
        )
        finish()
    }
}
