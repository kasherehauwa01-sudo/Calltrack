package com.example.calltrack.ui.main

import android.Manifest
import android.content.ClipData
import android.content.DialogInterface
import android.content.Intent
import android.content.pm.PackageManager
import android.net.Uri
import android.os.Build
import android.os.Bundle
import android.os.PowerManager
import android.provider.Settings
import android.widget.PopupMenu
import android.widget.ProgressBar
import android.widget.TextView
import android.widget.Toast
import androidx.activity.result.contract.ActivityResultContracts
import androidx.activity.addCallback
import androidx.activity.viewModels
import androidx.appcompat.app.AlertDialog
import androidx.core.content.ContextCompat
import androidx.core.content.FileProvider
import androidx.core.view.ViewCompat
import androidx.core.view.WindowInsetsCompat
import androidx.lifecycle.lifecycleScope
import com.example.calltrack.App
import com.example.calltrack.BuildConfig
import com.example.calltrack.R
import com.example.calltrack.data.local.NotificationEntity
import com.example.calltrack.data.notification.NotificationTargets
import com.example.calltrack.databinding.ActivityMainBinding
import com.example.calltrack.logging.AppLogger
import com.example.calltrack.service.CallTrackingService
import com.example.calltrack.ui.calls.CallListFragment
import com.example.calltrack.ui.analytics.AnalyticsActivity
import com.example.calltrack.ui.base.BaseActivity
import com.example.calltrack.ui.base.installMojibakeRepair
import com.example.calltrack.ui.contacts.ContactsFragment
import com.example.calltrack.ui.contactcard.ContactCardFragment
import com.example.calltrack.ui.contactcard.ContactHistoryFragment
import com.example.calltrack.ui.dialpad.DialPadFragment
import com.example.calltrack.ui.notifications.NotificationBadgeManager
import com.example.calltrack.ui.notifications.NotificationsFragment
import com.example.calltrack.ui.onboarding.OnboardingFragment
import com.example.calltrack.ui.postcall.PostCallActivity
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.launch
import kotlinx.coroutines.withContext
import okhttp3.OkHttpClient
import okhttp3.Request
import org.json.JSONException
import org.json.JSONObject
import java.io.File
import java.io.IOException
import java.security.MessageDigest
import java.util.concurrent.TimeUnit

class MainActivity : BaseActivity() {

    private lateinit var binding: ActivityMainBinding
    private var pendingInstallApk: File? = null
    private var pendingInstallVersionCode: Int? = null
    private var pendingInstallVersionName: String? = null
    private var updateOperationRunning = false
    private var updateProgressDialog: AlertDialog? = null
    private var updateProgressBar: ProgressBar? = null
    private var updateProgressStatus: TextView? = null
    private var batteryOptimizationPromptShown = false
    private val viewModel: MainViewModel by viewModels {
        MainViewModel.Factory((application as App).repository)
    }
    private val updateHttpClient = OkHttpClient()
    private val updateDownloadHttpClient = updateHttpClient.newBuilder()
        .connectTimeout(30, TimeUnit.SECONDS)
        .readTimeout(5, TimeUnit.MINUTES)
        .writeTimeout(30, TimeUnit.SECONDS)
        .callTimeout(6, TimeUnit.MINUTES)
        .retryOnConnectionFailure(false)
        .build()

    private val permissionsLauncher = registerForActivityResult(
        ActivityResultContracts.RequestMultiplePermissions()
    ) {
        updateWarningState()
        (supportFragmentManager.findFragmentById(R.id.fragmentContainer) as? OnboardingFragment)?.onPermissionsUpdated()
    }
    private val unknownAppsLauncher = registerForActivityResult(
        ActivityResultContracts.StartActivityForResult()
    ) {
        updateWarningState()
        val apkFile = pendingInstallApk
        if (apkFile != null && (Build.VERSION.SDK_INT < Build.VERSION_CODES.O || packageManager.canRequestPackageInstalls())) {
            AppLogger.log(this, "UPDATE", "\u0420\u0430\u0437\u0440\u0435\u0448\u0435\u043D\u0438\u0435 \u043D\u0430 \u0443\u0441\u0442\u0430\u043D\u043E\u0432\u043A\u0443 APK \u043F\u043E\u043B\u0443\u0447\u0435\u043D\u043E, \u043F\u043E\u0432\u0442\u043E\u0440\u043D\u044B\u0439 \u0437\u0430\u043F\u0443\u0441\u043A \u0443\u0441\u0442\u0430\u043D\u043E\u0432\u043A\u0438")
            installApkFile(apkFile)
        } else {
            updateOperationRunning = false
            hideUpdateProgress()
            AppLogger.log(this, "WARN", "\u0420\u0430\u0437\u0440\u0435\u0448\u0435\u043D\u0438\u0435 \u043D\u0430 \u0443\u0441\u0442\u0430\u043D\u043E\u0432\u043A\u0443 APK \u043D\u0435 \u043F\u0440\u0435\u0434\u043E\u0441\u0442\u0430\u0432\u043B\u0435\u043D\u043E")
        }
    }
    private val batteryOptimizationLauncher = registerForActivityResult(
        ActivityResultContracts.StartActivityForResult()
    ) {
        updateWarningState()
        AppLogger.log(
            this,
            "STABILITY",
            if (isBatteryOptimizationDisabled()) "\u0424\u043E\u043D\u043E\u0432\u0430\u044F \u0440\u0430\u0431\u043E\u0442\u0430 \u0431\u0435\u0437 \u043E\u0433\u0440\u0430\u043D\u0438\u0447\u0435\u043D\u0438\u0439 \u0440\u0430\u0437\u0440\u0435\u0448\u0435\u043D\u0430" else "\u0418\u0441\u043A\u043B\u044E\u0447\u0435\u043D\u0438\u0435 \u0438\u0437 \u043E\u043F\u0442\u0438\u043C\u0438\u0437\u0430\u0446\u0438\u0438 \u0431\u0430\u0442\u0430\u0440\u0435\u0438 \u043D\u0435 \u043F\u0440\u0435\u0434\u043E\u0441\u0442\u0430\u0432\u043B\u0435\u043D\u043E"
        )
        (supportFragmentManager.findFragmentById(R.id.fragmentContainer) as? OnboardingFragment)?.onPermissionsUpdated()
    }
    private val apkInstallerLauncher = registerForActivityResult(
        ActivityResultContracts.StartActivityForResult()
    ) { result ->
        updateOperationRunning = false
        hideUpdateProgress()
        AppLogger.log(this, "UPDATE", "\u0421\u0438\u0441\u0442\u0435\u043C\u043D\u044B\u0439 \u0443\u0441\u0442\u0430\u043D\u043E\u0432\u0449\u0438\u043A APK \u0437\u0430\u0432\u0435\u0440\u0448\u0451\u043D: resultCode=${result.resultCode}")
        if (result.resultCode != RESULT_OK) {
            Toast.makeText(this, "\u0423\u0441\u0442\u0430\u043D\u043E\u0432\u043A\u0430 \u043D\u0435 \u0437\u0430\u0432\u0435\u0440\u0448\u0435\u043D\u0430. \u041F\u0440\u043E\u0432\u0435\u0440\u044C\u0442\u0435 \u0441\u043E\u043E\u0431\u0449\u0435\u043D\u0438\u0435 \u0441\u0438\u0441\u0442\u0435\u043C\u043D\u043E\u0433\u043E \u0443\u0441\u0442\u0430\u043D\u043E\u0432\u0449\u0438\u043A\u0430.", Toast.LENGTH_LONG).show()
        }
        lifecycleScope.launch { syncUpdateLogsToDashboard() }
    }

    override fun onCreate(savedInstanceState: Bundle?) {
        applySavedTheme()
        super.onCreate(savedInstanceState)
        AppLogger.log(this, "APP", "MainActivity \u0441\u043E\u0437\u0434\u0430\u043D\u0430")
        binding = ActivityMainBinding.inflate(layoutInflater)
        setContentView(binding.root)

        applyWindowInsets()
        setupBottomNav()
        setupSettingsButton()
        setupAnalyticsButton()
        setupNotificationButton()
        binding.btnTopBack.setOnClickListener { onBackPressedDispatcher.onBackPressed() }
        supportFragmentManager.addOnBackStackChangedListener { updateTopBackVisibility() }
        handleExternalNavigation(intent)

        viewModel.onboardingCompleted.observe(this) { completed ->
            if (!completed) {
                requestUnknownAppsPermissionIfNeeded()
                openFragment(OnboardingFragment.newInstance())
                binding.bottomNav.visibility = android.view.View.GONE
            } else {
                binding.bottomNav.visibility = android.view.View.VISIBLE
                if (savedInstanceState == null) binding.bottomNav.selectedItemId = R.id.nav_dial
                refreshPersonalContactsAfterAuthorization()
                lifecycleScope.launch { viewModel.sendUserTelemetry() }
                startTrackingService()
                requestBatteryOptimizationIfNeeded()
            }
            updateWarningState()
        }
    }

    override fun onNewIntent(intent: Intent) {
        super.onNewIntent(intent)
        handleExternalNavigation(intent)
    }

    override fun onResume() {
        super.onResume()
        AppLogger.log(this, "APP", "\u041F\u0440\u0438\u043B\u043E\u0436\u0435\u043D\u0438\u0435 \u043D\u0430 \u044D\u043A\u0440\u0430\u043D\u0435")
        lifecycleScope.launch { viewModel.sendUserTelemetry() }
    }

    override fun onPause() {
        AppLogger.log(this, "APP", "\u041F\u0440\u0438\u043B\u043E\u0436\u0435\u043D\u0438\u0435 \u0441\u0432\u0435\u0440\u043D\u0443\u0442\u043E")
        super.onPause()
    }

    private fun applyWindowInsets() {
        val navigationInitialBottomPadding = binding.bottomNav.paddingBottom
        ViewCompat.setOnApplyWindowInsetsListener(binding.root) { root, insets ->
            val bars = insets.getInsets(WindowInsetsCompat.Type.systemBars())
            // Фон нижней панели доходит до физического края экрана, а её
            // содержимое поднимается над системной навигацией внутренним padding.
            root.setPadding(0, 0, 0, 0)
            binding.statusBarOverlay.layoutParams = binding.statusBarOverlay.layoutParams.apply { height = bars.top }
            binding.bottomNav.setPadding(
                binding.bottomNav.paddingLeft,
                binding.bottomNav.paddingTop,
                binding.bottomNav.paddingRight,
                navigationInitialBottomPadding + bars.bottom
            )
            insets
        }
    }

    private fun handleExternalNavigation(intent: Intent?) {
        when {
            intent?.getBooleanExtra(EXTRA_OPEN_DIAL, false) == true -> {
                intent.removeExtra(EXTRA_OPEN_DIAL)
                openDialScreen()
                return
            }
            intent?.getBooleanExtra(EXTRA_OPEN_NOTIFICATIONS, false) == true -> {
                intent.removeExtra(EXTRA_OPEN_NOTIFICATIONS)
                openNotificationsScreen()
                return
            }
            intent?.getBooleanExtra(EXTRA_OPEN_SETTINGS, false) == true -> {
                intent.removeExtra(EXTRA_OPEN_SETTINGS)
                openSecondaryFragment(SettingsFragment.newInstance())
                return
            }
            intent?.getBooleanExtra(EXTRA_OPEN_USER, false) == true -> {
                intent.removeExtra(EXTRA_OPEN_USER)
                openSecondaryFragment(UserFragment.newInstance())
                return
            }
        }

        val phone = intent?.getStringExtra(EXTRA_OPEN_CONTACT_PHONE).orEmpty()
        if (phone.isNotBlank()) {
            openContactCard(phone)
            return
        }

        if (intent?.getBooleanExtra(EXTRA_RUN_UPDATE_CHECK, false) == true) {
            intent.removeExtra(EXTRA_RUN_UPDATE_CHECK)
            checkForUpdatesAndPrompt()
        }
    }

    private fun showUpdateProgress(status: String, progress: Int, indeterminate: Boolean = false) {
        if (updateProgressDialog == null) {
            val content = layoutInflater.inflate(R.layout.dialog_update_progress, null)
            updateProgressBar = content.findViewById(R.id.updateProgressBar)
            updateProgressStatus = content.findViewById(R.id.updateProgressStatus)

            updateProgressDialog = AlertDialog.Builder(this)
                .setTitle("бновление приложения")
                .setView(content)
                .setCancelable(false)
                .create()

            updateProgressDialog?.show()
        }

        updateProgressBar?.isIndeterminate = indeterminate
        if (!indeterminate) {
            updateProgressBar?.progress = progress.coerceIn(0, 100)
        }
        updateProgressStatus?.text = status
    }

    private fun hideUpdateProgress() {
        updateProgressDialog?.dismiss()
        updateProgressDialog = null
        updateProgressBar = null
        updateProgressStatus = null
    }

    private fun applySavedTheme() {
        // Тема применяется в BaseActivity из сохраненной настройки пользователя.
    }

    private fun setupAnalyticsButton() {
        binding.btnAnalytics.setOnClickListener {
            AppLogger.log(this, "UI", "\u041E\u0442\u043A\u0440\u044B\u0442 \u044D\u043A\u0440\u0430\u043D: \u0410\u043D\u0430\u043B\u0438\u0442\u0438\u043A\u0430")
            startActivity(Intent(this, AnalyticsActivity::class.java))
        }
    }

    private fun setupNotificationButton() {
        binding.btnNotifications.setOnClickListener {
            AppLogger.log(this, "UI", "\u041E\u0442\u043A\u0440\u044B\u0442 \u044D\u043A\u0440\u0430\u043D: \u0423\u0432\u0435\u0434\u043E\u043C\u043B\u0435\u043D\u0438\u044F")
            openNotificationsScreen()
        }
        NotificationBadgeManager(
            lifecycleOwner = this,
            scope = lifecycleScope,
            repository = (application as App).notificationRepository,
            badgeView = binding.notificationBadge
        ).start()
    }

    private fun setupSettingsButton() {
        binding.btnSettings.setOnClickListener { anchor ->
            AppLogger.log(this, "UI", "\u041D\u0430\u0436\u0430\u0442\u0430 \u043A\u043D\u043E\u043F\u043A\u0430: \u041D\u0430\u0441\u0442\u0440\u043E\u0439\u043A\u0438")
            PopupMenu(this, anchor).apply {
                menu.add(0, MENU_ABOUT_ID, 0, getString(R.string.about_app))
                menu.add(0, MENU_SETTINGS_ID, 1, getString(R.string.settings))
                menu.add(0, MENU_USER_ID, 2, getString(R.string.user))
                setOnMenuItemClickListener { menuItem ->
                    when (menuItem.itemId) {
                        MENU_ABOUT_ID -> startActivity(Intent(this@MainActivity, AboutActivity::class.java))
                        MENU_SETTINGS_ID -> {
                            AppLogger.log(this@MainActivity, "UI", "\u041E\u0442\u043A\u0440\u044B\u0442 \u044D\u043A\u0440\u0430\u043D: \u041D\u0430\u0441\u0442\u0440\u043E\u0439\u043A\u0438")
                            openSecondaryFragment(SettingsFragment.newInstance())
                        }
                        MENU_USER_ID -> {
                            AppLogger.log(this@MainActivity, "UI", "\u041E\u0442\u043A\u0440\u044B\u0442 \u044D\u043A\u0440\u0430\u043D: \u041F\u043E\u043B\u044C\u0437\u043E\u0432\u0430\u0442\u0435\u043B\u044C")
                            openSecondaryFragment(UserFragment.newInstance())
                        }
                        else -> false
                    }
                    true
                }
                show()
            }
        }
    }

    private fun checkForUpdatesAndPrompt() {
        if (updateOperationRunning) {
            AppLogger.log(this, "UPDATE", "\u041F\u043E\u0432\u0442\u043E\u0440\u043D\u044B\u0439 \u0437\u0430\u043F\u0443\u0441\u043A \u043E\u0431\u043D\u043E\u0432\u043B\u0435\u043D\u0438\u044F \u043F\u0440\u043E\u043F\u0443\u0449\u0435\u043D: \u043E\u043F\u0435\u0440\u0430\u0446\u0438\u044F \u0443\u0436\u0435 \u0432\u044B\u043F\u043E\u043B\u043D\u044F\u0435\u0442\u0441\u044F")
            return
        }
        updateOperationRunning = true
        showUpdateProgress("\u041F\u0440\u043E\u0432\u0435\u0440\u043A\u0430 \u0430\u043A\u0442\u0443\u0430\u043B\u044C\u043D\u043E\u0441\u0442\u0438", 10, indeterminate = true)
        lifecycleScope.launch {
            AppLogger.log(this@MainActivity, "UI", "\u041D\u0430\u0436\u0430\u0442\u0430 \u043A\u043D\u043E\u043F\u043A\u0430: \u041E\u0431\u043D\u043E\u0432\u0438\u0442\u044C")
            AppLogger.log(this@MainActivity, "UPDATE", "\u041F\u0440\u043E\u0432\u0435\u0440\u043A\u0430 \u043E\u0431\u043D\u043E\u0432\u043B\u0435\u043D\u0438\u0439 \u0447\u0435\u0440\u0435\u0437 kvasmix.ru")
            AppLogger.log(this@MainActivity, "UPDATE", "\u0422\u0435\u043A\u0443\u0449\u0430\u044F \u0432\u0435\u0440\u0441\u0438\u044F: ${BuildConfig.VERSION_CODE} (${BuildConfig.VERSION_NAME})")
            Toast.makeText(this@MainActivity, "\u041F\u0440\u043E\u0432\u0435\u0440\u043A\u0430 \u043E\u0431\u043D\u043E\u0432\u043B\u0435\u043D\u0438\u0439...", Toast.LENGTH_SHORT).show()

            val updateResult = withContext(Dispatchers.IO) { fetchLatestUpdateInfo() }
            when (updateResult) {
                is UpdateCheckResult.NetworkError -> {
                    AppLogger.log(this@MainActivity, "ERROR", "\u0421\u0435\u0440\u0432\u0435\u0440 \u043E\u0431\u043D\u043E\u0432\u043B\u0435\u043D\u0438\u0439 \u043D\u0435\u0434\u043E\u0441\u0442\u0443\u043F\u0435\u043D: ${updateResult.reason}")
                    syncUpdateLogsToDashboard()
                    hideUpdateProgress()
                    Toast.makeText(this@MainActivity, "\u041D\u0435 \u0443\u0434\u0430\u043B\u043E\u0441\u044C \u043F\u0440\u043E\u0432\u0435\u0440\u0438\u0442\u044C \u043E\u0431\u043D\u043E\u0432\u043B\u0435\u043D\u0438\u044F: ${updateResult.reason}", Toast.LENGTH_LONG).show()
                    updateOperationRunning = false
                    return@launch
                }
                is UpdateCheckResult.InvalidResponse -> {
                    AppLogger.log(this@MainActivity, "ERROR", "\u041D\u0435\u043A\u043E\u0440\u0440\u0435\u043A\u0442\u043D\u044B\u0439 \u043E\u0442\u0432\u0435\u0442 \u0441\u0435\u0440\u0432\u0435\u0440\u0430 \u043E\u0431\u043D\u043E\u0432\u043B\u0435\u043D\u0438\u0439: ${updateResult.reason}")
                    syncUpdateLogsToDashboard()
                    hideUpdateProgress()
                    Toast.makeText(this@MainActivity, "\u041D\u0435\u043A\u043E\u0440\u0440\u0435\u043A\u0442\u043D\u044B\u0439 \u043E\u0442\u0432\u0435\u0442 \u0441\u0435\u0440\u0432\u0435\u0440\u0430: ${updateResult.reason}", Toast.LENGTH_LONG).show()
                    updateOperationRunning = false
                    return@launch
                }
                is UpdateCheckResult.Success -> {
                    val update = updateResult.info
                    AppLogger.log(this@MainActivity, "UPDATE", "\u0412\u0435\u0440\u0441\u0438\u044F \u043D\u0430 \u0441\u0435\u0440\u0432\u0435\u0440\u0435: ${update.versionCode} (${update.versionName})")
                    if (update.versionCode <= BuildConfig.VERSION_CODE) {
                        AppLogger.log(this@MainActivity, "UPDATE", "\u0412\u0435\u0440\u0441\u0438\u044F \u0430\u043A\u0442\u0443\u0430\u043B\u044C\u043D\u0430")
                        syncUpdateLogsToDashboard()
                        hideUpdateProgress()
                        AlertDialog.Builder(this@MainActivity)
                            .setMessage("\u0423 \u0432\u0430\u0441 \u0443\u0441\u0442\u0430\u043D\u043E\u0432\u043B\u0435\u043D\u0430 \u0430\u043A\u0442\u0443\u0430\u043B\u044C\u043D\u0430\u044F \u0432\u0435\u0440\u0441\u0438\u044F \u043F\u0440\u0438\u043B\u043E\u0436\u0435\u043D\u0438\u044F.")
                            .setPositiveButton("OK") { dialog: DialogInterface, _: Int -> dialog.dismiss() }
                            .show()
                        updateOperationRunning = false
                        return@launch
                    }
                    syncUpdateLogsToDashboard()
                    hideUpdateProgress()
                    showUpdateDialog(update)
                }
            }
        }
    }

    private suspend fun syncUpdateLogsToDashboard() {
        withContext(Dispatchers.IO) {
            runCatching { viewModel.sendUserTelemetry() }
                .onSuccess { AppLogger.log(this@MainActivity, "UPDATE", "\u041B\u043E\u0433\u0438 \u043E\u0431\u043D\u043E\u0432\u043B\u0435\u043D\u0438\u044F \u043E\u0442\u043F\u0440\u0430\u0432\u043B\u0435\u043D\u044B \u0432 \u0430\u0434\u043C\u0438\u043D-\u043F\u0430\u043D\u0435\u043B\u044C") }
                .onFailure { AppLogger.log(this@MainActivity, "ERROR", "\u041D\u0435 \u0443\u0434\u0430\u043B\u043E\u0441\u044C \u043E\u0442\u043F\u0440\u0430\u0432\u0438\u0442\u044C \u043B\u043E\u0433\u0438 \u043E\u0431\u043D\u043E\u0432\u043B\u0435\u043D\u0438\u044F \u0432 \u0430\u0434\u043C\u0438\u043D-\u043F\u0430\u043D\u0435\u043B\u044C: ${it.message}", it) }
        }
    }

    private fun showUpdateDialog(update: UpdateInfo) {
        val notes = update.releaseNotes
            .filter { it.isNotBlank() }
            .joinToString(separator = "\n") { "• $it" }
            .ifBlank { "Список изменений не указан." }
        val dialog = AlertDialog.Builder(this)
            .setTitle("Доступна версия ${update.versionName}")
            .setMessage(notes)
            .setPositiveButton("\u041E\u0431\u043D\u043E\u0432\u0438\u0442\u044C") { _: DialogInterface, _: Int ->
                startApkUpdateDownload(update)
            }
            .apply {
                if (!update.mandatory) {
                    setNegativeButton("\u041E\u0442\u043C\u0435\u043D\u0430") { dialog: DialogInterface, _: Int ->
                        updateOperationRunning = false
                        dialog.dismiss()
                    }
                }
            }
            .setOnCancelListener { updateOperationRunning = false }
            .create()
        dialog.installMojibakeRepair()
        dialog.show()
    }

    private fun startApkUpdateDownload(update: UpdateInfo) {
        showUpdateProgress("\u0417\u0430\u0433\u0440\u0443\u0437\u043A\u0430 \u043D\u043E\u0432\u043E\u0439 \u0432\u0435\u0440\u0441\u0438\u0438", 25)
        lifecycleScope.launch {
            AppLogger.log(this@MainActivity, "UPDATE", "\u041D\u0430\u0447\u0430\u0442\u0430 \u0437\u0430\u0433\u0440\u0443\u0437\u043A\u0430 APK \u0441 \u0441\u0435\u0440\u0432\u0435\u0440\u0430 kvasmix.ru: ${update.apkUrl}")
            Toast.makeText(this@MainActivity, "\u0417\u0430\u0433\u0440\u0443\u0437\u043A\u0430 \u043E\u0431\u043D\u043E\u0432\u043B\u0435\u043D\u0438\u044F...", Toast.LENGTH_SHORT).show()
            val result = withContext(Dispatchers.IO) {
                downloadApkToCache(update.apkDownloadUrls) { downloaded, total ->
                    val percent = if (total > 0L) (25 + downloaded * 60 / total).toInt() else 45
                    runOnUiThread { showUpdateProgress("\u0417\u0430\u0433\u0440\u0443\u0437\u043A\u0430 \u043D\u043E\u0432\u043E\u0439 \u0432\u0435\u0440\u0441\u0438\u0438", percent) }
                }
            }
            result
                .onSuccess { downloadedApkFile: File ->
                    AppLogger.log(this@MainActivity, "UPDATE", "APK \u0443\u0441\u043F\u0435\u0448\u043D\u043E \u0437\u0430\u0433\u0440\u0443\u0436\u0435\u043D \u0432 cache: ${downloadedApkFile.absolutePath}, size=${downloadedApkFile.length()}")
                    Toast.makeText(this@MainActivity, "\u041F\u043E\u0434\u0433\u043E\u0442\u043E\u0432\u043A\u0430 \u0443\u0441\u0442\u0430\u043D\u043E\u0432\u043A\u0438...", Toast.LENGTH_SHORT).show()
                    syncUpdateLogsToDashboard()
                    pendingInstallVersionCode = update.versionCode
                    pendingInstallVersionName = update.versionName
                    showUpdateProgress("\u0423\u0441\u0442\u0430\u043D\u043E\u0432\u043A\u0430 \u043D\u043E\u0432\u043E\u0439 \u0432\u0435\u0440\u0441\u0438\u0438", 90)
                    installApkFile(downloadedApkFile)
                }
                .onFailure { error ->
                    AppLogger.log(this@MainActivity, "ERROR", "\u041E\u0448\u0438\u0431\u043A\u0430 \u0437\u0430\u0433\u0440\u0443\u0437\u043A\u0438 \u043E\u0431\u043D\u043E\u0432\u043B\u0435\u043D\u0438\u044F: ${error.message}", error)
                    syncUpdateLogsToDashboard()
                    Toast.makeText(this@MainActivity, "\u041E\u0448\u0438\u0431\u043A\u0430 \u0437\u0430\u0433\u0440\u0443\u0437\u043A\u0438 \u043E\u0431\u043D\u043E\u0432\u043B\u0435\u043D\u0438\u044F: ${error.message.orEmpty()}", Toast.LENGTH_LONG).show()
                    updateOperationRunning = false
                    hideUpdateProgress()
                }
        }
    }

    private fun downloadApkToCache(apkUrls: List<String>, onProgress: (Long, Long) -> Unit): Result<File> {
        val candidateUrls = apkUrls.distinct()
        if (candidateUrls.isEmpty()) return Result.failure(IllegalStateException("\u041D\u0435\u0442 \u0434\u043E\u0441\u0442\u0443\u043F\u043D\u044B\u0445 \u0441\u0441\u044B\u043B\u043E\u043A APK"))
        var lastError: Throwable? = null
        repeat(APK_DOWNLOAD_MAX_ATTEMPTS) { attemptIndex ->
            val attempt = attemptIndex + 1
            val apkUrl = candidateUrls[attemptIndex.coerceAtMost(candidateUrls.lastIndex)]
            AppLogger.log(this, "UPDATE", "\u0417\u0430\u0433\u0440\u0443\u0437\u043A\u0430 APK, \u043F\u043E\u043F\u044B\u0442\u043A\u0430 $attempt/$APK_DOWNLOAD_MAX_ATTEMPTS: $apkUrl")
            val result = downloadApkToCache(apkUrl, onProgress)
            if (result.isSuccess) return result

            val error = result.exceptionOrNull()
            lastError = error
            AppLogger.log(this, "WARN", "\u041F\u043E\u043F\u044B\u0442\u043A\u0430 $attempt \u0437\u0430\u0432\u0435\u0440\u0448\u0438\u043B\u0430\u0441\u044C: ${error?.message.orEmpty()}", error)
            if (error !is IOException || attempt >= APK_DOWNLOAD_MAX_ATTEMPTS) {
                return result
            }
            AppLogger.log(this, "UPDATE", "\u041F\u043E\u0432\u0442\u043E\u0440 \u0437\u0430\u0433\u0440\u0443\u0437\u043A\u0438 \u0447\u0435\u0440\u0435\u0437 ${APK_DOWNLOAD_RETRY_DELAY_MS / 1000} \u0441\u0435\u043A.")
            Thread.sleep(APK_DOWNLOAD_RETRY_DELAY_MS)
        }
        return Result.failure(lastError ?: IllegalStateException("\u041D\u0435\u0442 \u0434\u043E\u0441\u0442\u0443\u043F\u043D\u044B\u0445 \u0441\u0441\u044B\u043B\u043E\u043A APK"))
    }

    private fun downloadApkToCache(apkUrl: String, onProgress: (Long, Long) -> Unit): Result<File> {
        return runCatching {
            val request = Request.Builder()
                .url(apkUrl)
                .addHeader("Accept", APK_MIME_TYPE)
                .build()

            val tempFile = File(cacheDir, "$APK_FILE_NAME.part").apply { delete() }
            val apkFile = File(cacheDir, APK_FILE_NAME).apply { delete() }
            val downloadedApk: File = updateDownloadHttpClient.newCall(request).execute().use { response ->
                AppLogger.log(
                    this,
                    "UPDATE",
                    "\u041E\u0442\u0432\u0435\u0442 \u0437\u0430\u0433\u0440\u0443\u0437\u043A\u0438 APK: code=${response.code}, contentType=${response.header("Content-Type").orEmpty()}, contentLength=${response.body?.contentLength() ?: -1}"
                )
                if (!response.isSuccessful) throw ApkHttpException(response.code)
                val body = response.body ?: throw IOException("\u041F\u0443\u0441\u0442\u043E\u0435 \u0442\u0435\u043B\u043E APK")
                var copiedBytes = 0L
                val contentLength = body.contentLength()
                var nextProgressLogBytes = APK_DOWNLOAD_LOG_STEP_BYTES
                AppLogger.log(this, "UPDATE", "\u041E\u0436\u0438\u0434\u0430\u0435\u043C\u044B\u0439 \u0440\u0430\u0437\u043C\u0435\u0440 APK: $contentLength bytes")
                body.byteStream().use { input ->
                    tempFile.outputStream().use { output ->
                        val buffer = ByteArray(DEFAULT_BUFFER_SIZE)
                        while (true) {
                            val read = input.read(buffer)
                            if (read < 0) break
                            output.write(buffer, 0, read)
                            copiedBytes += read
                            onProgress(copiedBytes, contentLength)
                            if (copiedBytes >= nextProgressLogBytes) {
                                AppLogger.log(this, "UPDATE", "\u0417\u0430\u0433\u0440\u0443\u0436\u0435\u043D\u043E: $copiedBytes / $contentLength bytes")
                                nextProgressLogBytes = ((copiedBytes / APK_DOWNLOAD_LOG_STEP_BYTES) + 1) * APK_DOWNLOAD_LOG_STEP_BYTES
                            }
                        }
                    }
                }
                if (copiedBytes <= 0L || tempFile.length() <= 0L) throw IOException("APK \u043D\u0435 \u0431\u044B\u043B \u0437\u0430\u043F\u0438\u0441\u0430\u043D \u0432 cache")
                if (contentLength >= 0L && copiedBytes != contentLength) {
                    throw IOException("APK \u0437\u0430\u0433\u0440\u0443\u0436\u0435\u043D \u043D\u0435 \u043F\u043E\u043B\u043D\u043E\u0441\u0442\u044C\u044E: \u043E\u0436\u0438\u0434\u0430\u043B\u043E\u0441\u044C $contentLength bytes, \u043F\u043E\u043B\u0443\u0447\u0435\u043D\u043E $copiedBytes bytes")
                }
                if (!tempFile.hasApkZipSignature()) {
                    val preview = tempFile.readTextPreview(MAX_UPDATE_LOG_BODY_CHARS)
                    tempFile.delete()
                    error("\u0421\u0435\u0440\u0432\u0435\u0440 \u0432\u0435\u0440\u043D\u0443\u043B \u043D\u0435 APK: contentType=${response.header("Content-Type").orEmpty()}, bytes=$copiedBytes, bodyPreview=$preview")
                }
                if (!tempFile.renameTo(apkFile)) error("\u041D\u0435 \u0443\u0434\u0430\u043B\u043E\u0441\u044C \u043F\u043E\u0434\u0433\u043E\u0442\u043E\u0432\u0438\u0442\u044C APK \u0444\u0430\u0439\u043B")
                AppLogger.log(this, "UPDATE", "APK \u043F\u043E\u043B\u043D\u043E\u0441\u0442\u044C\u044E \u0437\u0430\u0433\u0440\u0443\u0436\u0435\u043D: $copiedBytes bytes, file=${apkFile.absolutePath}")
                apkFile
            }

            downloadedApk
        }.onFailure {
            File(cacheDir, "$APK_FILE_NAME.part").delete()
        }
    }

    private class ApkHttpException(code: Int) : Exception("HTTP $code")

    private fun File.hasApkZipSignature(): Boolean {
        // APK — это ZIP-архив, поэтому первые байты должны начинаться с PK.
        if (!exists() || length() < 2L) return false
        inputStream().use { input ->
            return input.read() == 0x50 && input.read() == 0x4B
        }
    }

    private fun File.readTextPreview(maxChars: Int): String {
        // Используется только для логов ошибок, чтобы не пытаться установить JSON/HTML как APK.
        return inputStream().buffered().use { input ->
            val buffer = ByteArray(maxChars.coerceAtLeast(1))
            val read = input.read(buffer)
            if (read <= 0) "" else String(buffer, 0, read, Charsets.UTF_8)
        }.replace(Regex("\\s+"), " ").take(maxChars)
    }

    private fun installApkFile(downloadedApkFile: File) {
        AppLogger.log(this, "UPDATE", "\u041F\u043E\u0434\u0433\u043E\u0442\u043E\u0432\u043A\u0430 \u0443\u0441\u0442\u0430\u043D\u043E\u0432\u043A\u0438 APK: path=${downloadedApkFile.absolutePath}, exists=${downloadedApkFile.exists()}, size=${downloadedApkFile.length()}")
        if (!downloadedApkFile.exists() || downloadedApkFile.length() <= 0L) {
            AppLogger.log(this, "ERROR", "APK \u0444\u0430\u0439\u043B \u043E\u0442\u0441\u0443\u0442\u0441\u0442\u0432\u0443\u0435\u0442 \u0438\u043B\u0438 \u043F\u0443\u0441\u0442\u043E\u0439")
            Toast.makeText(this, "\u041E\u0448\u0438\u0431\u043A\u0430 \u0437\u0430\u0433\u0440\u0443\u0437\u043A\u0438 \u043E\u0431\u043D\u043E\u0432\u043B\u0435\u043D\u0438\u044F.", Toast.LENGTH_SHORT).show()
            updateOperationRunning = false
            hideUpdateProgress()
            return
        }
        val validationError = validateDownloadedApk(downloadedApkFile)
        if (validationError != null) {
            AppLogger.log(this, "ERROR", "APK \u043D\u0435 \u043C\u043E\u0436\u0435\u0442 \u043E\u0431\u043D\u043E\u0432\u0438\u0442\u044C \u043F\u0440\u0438\u043B\u043E\u0436\u0435\u043D\u0438\u0435: $validationError")
            Toast.makeText(this, validationError, Toast.LENGTH_LONG).show()
            updateOperationRunning = false
            hideUpdateProgress()
            return
        }
        if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.O && !packageManager.canRequestPackageInstalls()) {
            pendingInstallApk = downloadedApkFile
            AppLogger.log(this, "WARN", "\u041D\u0435\u0442 \u0440\u0430\u0437\u0440\u0435\u0448\u0435\u043D\u0438\u044F \u043D\u0430 \u0443\u0441\u0442\u0430\u043D\u043E\u0432\u043A\u0443 APK \u0438\u0437 \u043D\u0435\u0438\u0437\u0432\u0435\u0441\u0442\u043D\u044B\u0445 \u0438\u0441\u0442\u043E\u0447\u043D\u0438\u043A\u043E\u0432, \u043E\u0442\u043A\u0440\u044B\u0432\u0430\u0435\u043C \u043D\u0430\u0441\u0442\u0440\u043E\u0439\u043A\u0438")
            Toast.makeText(this, "\u0420\u0430\u0437\u0440\u0435\u0448\u0438\u0442\u0435 \u0443\u0441\u0442\u0430\u043D\u043E\u0432\u043A\u0443 \u043F\u0440\u0438\u043B\u043E\u0436\u0435\u043D\u0438\u044F. \u041F\u043E\u0441\u043B\u0435 \u0440\u0430\u0437\u0440\u0435\u0448\u0435\u043D\u0438\u044F \u0443\u0441\u0442\u0430\u043D\u043E\u0432\u043A\u0430 \u043F\u0440\u043E\u0434\u043E\u043B\u0436\u0438\u0442\u0441\u044F \u0430\u0432\u0442\u043E\u043C\u0430\u0442\u0438\u0447\u0435\u0441\u043A\u0438.", Toast.LENGTH_LONG).show()
            val intent = Intent(
                Settings.ACTION_MANAGE_UNKNOWN_APP_SOURCES,
                Uri.parse("package:$packageName")
            )
            unknownAppsLauncher.launch(intent)
            return
        }

        pendingInstallApk = null
        val apkUri = FileProvider.getUriForFile(this, "$packageName.fileprovider", downloadedApkFile)
        val installIntent = Intent(Intent.ACTION_INSTALL_PACKAGE).apply {
            setDataAndType(apkUri, APK_MIME_TYPE)
            addFlags(Intent.FLAG_GRANT_READ_URI_PERMISSION)
            clipData = ClipData.newRawUri("Calltrack update", apkUri)
            putExtra(Intent.EXTRA_RETURN_RESULT, true)
            putExtra(Intent.EXTRA_NOT_UNKNOWN_SOURCE, true)
        }
        runCatching { apkInstallerLauncher.launch(installIntent) }
            .onSuccess { AppLogger.log(this, "UPDATE", "\u0421\u0438\u0441\u0442\u0435\u043C\u043D\u044B\u0439 \u0443\u0441\u0442\u0430\u043D\u043E\u0432\u0449\u0438\u043A APK \u043E\u0442\u043A\u0440\u044B\u0442") }
            .onFailure {
                updateOperationRunning = false
                hideUpdateProgress()
                AppLogger.log(this, "ERROR", "\u041D\u0435 \u0443\u0434\u0430\u043B\u043E\u0441\u044C \u043E\u0442\u043A\u0440\u044B\u0442\u044C \u0443\u0441\u0442\u0430\u043D\u043E\u0432\u0449\u0438\u043A APK: ${it.message}", it)
                Toast.makeText(this, "\u041D\u0435 \u0443\u0434\u0430\u043B\u043E\u0441\u044C \u043E\u0442\u043A\u0440\u044B\u0442\u044C \u0443\u0441\u0442\u0430\u043D\u043E\u0432\u0449\u0438\u043A APK", Toast.LENGTH_LONG).show()
            }
    }

    private fun validateDownloadedApk(apkFile: File): String? {
        val flags = if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.P) {
            PackageManager.GET_SIGNING_CERTIFICATES
        } else {
            @Suppress("DEPRECATION")
            PackageManager.GET_SIGNATURES
        }
        val archive = packageManager.getPackageArchiveInfo(apkFile.absolutePath, flags)
            ?: return "\u0421\u043A\u0430\u0447\u0430\u043D\u043D\u044B\u0439 \u0444\u0430\u0439\u043B \u043D\u0435 \u044F\u0432\u043B\u044F\u0435\u0442\u0441\u044F \u043A\u043E\u0440\u0440\u0435\u043A\u0442\u043D\u044B\u043C APK."
        if (archive.packageName != packageName) {
            return "APK \u043F\u0440\u0435\u0434\u043D\u0430\u0437\u043D\u0430\u0447\u0435\u043D \u0434\u043B\u044F \u0434\u0440\u0443\u0433\u043E\u0433\u043E \u043F\u0440\u0438\u043B\u043E\u0436\u0435\u043D\u0438\u044F (${archive.packageName})."
        }
        val current = packageManager.getPackageInfo(packageName, flags)
        val archiveSignatures = archive.signatureDigests()
        val currentSignatures = current.signatureDigests()
        if (archiveSignatures.isEmpty() || archiveSignatures != currentSignatures) {
            return "APK \u043F\u043E\u0434\u043F\u0438\u0441\u0430\u043D \u0434\u0440\u0443\u0433\u0438\u043C \u043A\u043B\u044E\u0447\u043E\u043C. \u0417\u0430\u0433\u0440\u0443\u0437\u0438\u0442\u0435 \u0441\u0431\u043E\u0440\u043A\u0443, \u043F\u043E\u0434\u043F\u0438\u0441\u0430\u043D\u043D\u0443\u044E \u0442\u0435\u043C \u0436\u0435 \u043A\u043B\u044E\u0447\u043E\u043C, \u0447\u0442\u043E \u0438 \u0443\u0441\u0442\u0430\u043D\u043E\u0432\u043B\u0435\u043D\u043D\u043E\u0435 \u043F\u0440\u0438\u043B\u043E\u0436\u0435\u043D\u0438\u0435."
        }
        val archiveVersion = if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.P) archive.longVersionCode else {
            @Suppress("DEPRECATION") archive.versionCode.toLong()
        }
        val expectedVersion = pendingInstallVersionCode
        if (expectedVersion != null && archiveVersion != expectedVersion.toLong()) {
            return "\u0421\u0435\u0440\u0432\u0435\u0440 \u043E\u0431\u044A\u044F\u0432\u0438\u043B \u0432\u0435\u0440\u0441\u0438\u044E $expectedVersion (${pendingInstallVersionName.orEmpty()}), " +
                "\u043D\u043E \u0432\u043D\u0443\u0442\u0440\u0438 \u0441\u043A\u0430\u0447\u0430\u043D\u043D\u043E\u0433\u043E APK \u0443\u043A\u0430\u0437\u0430\u043D versionCode=$archiveVersion. " +
                "\u041F\u0435\u0440\u0435\u0441\u043E\u0431\u0435\u0440\u0438\u0442\u0435 APK \u0441 \u043F\u0440\u0430\u0432\u0438\u043B\u044C\u043D\u044B\u043C versionCode \u0438 \u0437\u0430\u0433\u0440\u0443\u0437\u0438\u0442\u0435 \u0435\u0433\u043E \u0437\u0430\u043D\u043E\u0432\u043E."
        }
        if (archiveVersion <= BuildConfig.VERSION_CODE.toLong()) {
            return "\u0412\u043D\u0443\u0442\u0440\u0438 APK \u0443\u043A\u0430\u0437\u0430\u043D versionCode=$archiveVersion, \u0443\u0441\u0442\u0430\u043D\u043E\u0432\u043B\u0435\u043D\u043D\u0430\u044F \u0432\u0435\u0440\u0441\u0438\u044F=${BuildConfig.VERSION_CODE}. " +
                "\u0418\u043C\u044F \u0444\u0430\u0439\u043B\u0430 \u0438 \u0432\u0435\u0440\u0441\u0438\u044F \u0432 update.json \u043D\u0435 \u0438\u0437\u043C\u0435\u043D\u044F\u044E\u0442 \u0432\u0435\u0440\u0441\u0438\u044E \u0432\u043D\u0443\u0442\u0440\u0438 APK."
        }
        AppLogger.log(this, "UPDATE", "APK \u043F\u0440\u043E\u0432\u0435\u0440\u0435\u043D: package=${archive.packageName}, versionCode=$archiveVersion, \u043F\u043E\u0434\u043F\u0438\u0441\u044C \u0441\u043E\u0432\u043F\u0430\u0434\u0430\u0435\u0442")
        return null
    }

    private fun android.content.pm.PackageInfo.signatureDigests(): Set<String> {
        val signatures = if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.P) {
            signingInfo?.apkContentsSigners.orEmpty()
        } else {
            @Suppress("DEPRECATION")
            this.signatures.orEmpty()
        }
        return signatures.mapTo(linkedSetOf()) { signature ->
            MessageDigest.getInstance("SHA-256").digest(signature.toByteArray())
                .joinToString("") { byte -> "%02x".format(byte) }
        }
    }

    private fun fetchLatestUpdateInfo(): UpdateCheckResult {
        AppLogger.log(this, "UPDATE", "\u0417\u0430\u043F\u0440\u043E\u0441 \u043C\u0435\u0442\u0430\u0434\u0430\u043D\u043D\u044B\u0445 \u043E\u0431\u043D\u043E\u0432\u043B\u0435\u043D\u0438\u044F: $UPDATE_API_URL")
        val request = Request.Builder()
            .url(UPDATE_API_URL)
            .addHeader("Accept", "application/json")
            .build()

        return try {
            updateHttpClient.newCall(request).execute().use { response ->
                val body = response.body?.string().orEmpty()
                val preview = body.take(MAX_UPDATE_LOG_BODY_CHARS)
                AppLogger.log(
                    this,
                    "UPDATE",
                    "\u041E\u0442\u0432\u0435\u0442 \u0441\u0435\u0440\u0432\u0435\u0440\u0430 \u043E\u0431\u043D\u043E\u0432\u043B\u0435\u043D\u0438\u0439: code=${response.code}, contentType=${response.header("Content-Type").orEmpty()}, bodyLength=${body.length}, bodyPreview=$preview"
                )
                if (!response.isSuccessful) {
                    return UpdateCheckResult.NetworkError("HTTP ${response.code}")
                }
                parseUpdateInfo(body)
            }
        } catch (error: JSONException) {
            AppLogger.log(this, "ERROR", "JSON \u0441\u0435\u0440\u0432\u0435\u0440\u0430 \u043E\u0431\u043D\u043E\u0432\u043B\u0435\u043D\u0438\u0439 \u043F\u043E\u0432\u0440\u0435\u0436\u0434\u0435\u043D: ${error.message}", error)
            UpdateCheckResult.InvalidResponse("JSON parse error: ${error.message}")
        } catch (error: Exception) {
            AppLogger.log(this, "ERROR", "\u041E\u0448\u0438\u0431\u043A\u0430 \u0437\u0430\u043F\u0440\u043E\u0441\u0430 \u043E\u0431\u043D\u043E\u0432\u043B\u0435\u043D\u0438\u0439: ${error.message}", error)
            UpdateCheckResult.NetworkError(error.message ?: error.javaClass.simpleName)
        }
    }

    private fun parseUpdateInfo(body: String): UpdateCheckResult {
        if (body.isBlank()) return UpdateCheckResult.InvalidResponse("\u041F\u0443\u0441\u0442\u043E\u0435 \u0442\u0435\u043B\u043E \u043E\u0442\u0432\u0435\u0442\u0430")

        val json = JSONObject(body)
        val status = json.optString("status").trim()
        if (status.isNotBlank() && !status.equals("ok", ignoreCase = true) && !status.equals("success", ignoreCase = true)) {
            return UpdateCheckResult.InvalidResponse("status=$status")
        }

        val versionCode = json.optFlexibleInt("versionCode", "version_code")
        val versionName = json.optFlexibleString("versionName", "version_name")
        val rawApkUrl = json.optFlexibleString("apk", "apkUrl", "apk_url", "url")
        if (versionCode == null) return UpdateCheckResult.InvalidResponse("\u041D\u0435 \u043D\u0430\u0439\u0434\u0435\u043D versionCode/version_code")
        if (versionName.isBlank()) return UpdateCheckResult.InvalidResponse("\u041D\u0435 \u043D\u0430\u0439\u0434\u0435\u043D versionName/version_name")
        if (rawApkUrl.isBlank()) return UpdateCheckResult.InvalidResponse("\u041D\u0435 \u043D\u0430\u0439\u0434\u0435\u043D\u0430 \u0441\u0441\u044B\u043B\u043A\u0430 apk")
        if (!rawApkUrl.startsWith("https://", ignoreCase = true) && !rawApkUrl.startsWith("http://", ignoreCase = true)) {
            return UpdateCheckResult.InvalidResponse("\u041D\u0435\u043A\u043E\u0440\u0440\u0435\u043A\u0442\u043D\u0430\u044F \u0441\u0441\u044B\u043B\u043A\u0430 apk=$rawApkUrl")
        }
        val apkUrl = normalizeUpdateApkUrl(rawApkUrl, versionCode)

        val releaseNotes = json.optFlexibleStringList("releaseNotes", "release_notes")
        val mandatory = json.optFlexibleBoolean("mandatory", false)
        AppLogger.log(
            this,
            "UPDATE",
            "\u041C\u0435\u0442\u0430\u0434\u0430\u043D\u043D\u044B\u0435 \u043E\u0431\u043D\u043E\u0432\u043B\u0435\u043D\u0438\u044F \u0440\u0430\u0441\u043F\u043E\u0437\u043D\u0430\u043D\u044B: versionCode=$versionCode, versionName=$versionName, mandatory=$mandatory, notes=${releaseNotes.size}, apk=$apkUrl"
        )
        return UpdateCheckResult.Success(
            UpdateInfo(
                versionCode = versionCode,
                versionName = versionName,
                apkUrl = apkUrl,
                rawApkUrl = rawApkUrl,
                releaseNotes = releaseNotes,
                mandatory = mandatory
            )
        )
    }

    private fun normalizeUpdateApkUrl(apkUrl: String, versionCode: Int): String {
        // Сервер должен отдавать APK через api/update.php?download=1. Если в старом
        // update.json осталась прямая ссылка /updates/*.apk, принудительно переводим
        // её на proxy-эндпоинт, чтобы не ловить 502 от прямой раздачи каталога updates.
        val uri = Uri.parse(apkUrl)
        val normalizedUrl = if (uri.host.equals("kvasmix.ru", ignoreCase = true)
            && (uri.path ?: "").startsWith("/vr/calltrack/updates/")) {
            "$UPDATE_API_URL?download=1&versionCode=$versionCode"
        } else {
            apkUrl
        }
        if (normalizedUrl != apkUrl) {
            AppLogger.log(this, "UPDATE", "\u0421\u0441\u044B\u043B\u043A\u0430 APK \u0437\u0430\u043C\u0435\u043D\u0435\u043D\u0430 \u043D\u0430 \u0441\u0435\u0440\u0432\u0435\u0440\u043D\u044B\u0439 download endpoint: $normalizedUrl")
        }
        return normalizedUrl
    }

    private fun JSONObject.optFlexibleString(vararg names: String): String {
        for (name in names) {
            if (has(name) && !isNull(name)) return optString(name).trim()
        }
        return ""
    }

    private fun JSONObject.optFlexibleInt(vararg names: String): Int? {
        for (name in names) {
            if (!has(name) || isNull(name)) continue
            val raw = opt(name)
            when (raw) {
                is Number -> return raw.toInt()
                is String -> raw.trim().toIntOrNull()?.let { return it }
            }
        }
        return null
    }

    private fun JSONObject.optFlexibleBoolean(name: String, defaultValue: Boolean): Boolean {
        if (!has(name) || isNull(name)) return defaultValue
        val raw = opt(name)
        return when (raw) {
            is Boolean -> raw
            is Number -> raw.toInt() != 0
            is String -> raw.equals("true", ignoreCase = true) || raw == "1" || raw.equals("yes", ignoreCase = true)
            else -> defaultValue
        }
    }

    private fun JSONObject.optFlexibleStringList(vararg names: String): List<String> {
        for (name in names) {
            if (!has(name) || isNull(name)) continue
            val array = optJSONArray(name)
            if (array != null) {
                return buildList {
                    for (i in 0 until array.length()) {
                        val note = array.optString(i).trim()
                        if (note.isNotBlank()) add(note)
                    }
                }
            }
            val text = optString(name).trim()
            if (text.isNotBlank()) return text.lines().map { it.trim() }.filter { it.isNotBlank() }
        }
        return emptyList()
    }

    private fun setupBottomNav() {
        binding.bottomNav.setOnItemSelectedListener { item ->
            when (item.itemId) {
                R.id.nav_dial -> {
                    binding.btnTopBack.visibility = android.view.View.GONE
                    openFragment(DialPadFragment.newInstance())
                    true
                }
                R.id.nav_recent -> {
                    binding.btnTopBack.visibility = android.view.View.GONE
                    AppLogger.log(this, "UI", "\u041E\u0442\u043A\u0440\u044B\u0442 \u044D\u043A\u0440\u0430\u043D: \u0418\u0441\u0442\u043E\u0440\u0438\u044F \u0437\u0432\u043E\u043D\u043A\u043E\u0432")
                    openFragment(CallListFragment.newInstance())
                    true
                }
                R.id.nav_contacts -> {
                    binding.btnTopBack.visibility = android.view.View.GONE
                    AppLogger.log(this, "UI", "\u041E\u0442\u043A\u0440\u044B\u0442 \u044D\u043A\u0440\u0430\u043D: \u041A\u043E\u043D\u0442\u0430\u043A\u0442\u044B")
                    openFragment(ContactsFragment.newInstance())
                    true
                }
                else -> return@setOnItemSelectedListener false
            }
        }
    }

    private fun refreshPersonalContactsAfterAuthorization() {
        lifecycleScope.launch {
            runCatching { viewModel.refreshPersonalContacts() }
                .onSuccess { count -> AppLogger.log(this@MainActivity, "API", "\u041B\u0438\u0447\u043D\u044B\u0435 \u043A\u043E\u043D\u0442\u0430\u043A\u0442\u044B \u0437\u0430\u0433\u0440\u0443\u0436\u0435\u043D\u044B: $count") }
                .onFailure { error -> AppLogger.log(this@MainActivity, "ERROR", "\u041E\u0448\u0438\u0431\u043A\u0430 \u0437\u0430\u0433\u0440\u0443\u0437\u043A\u0438 \u043B\u0438\u0447\u043D\u044B\u0445 \u043A\u043E\u043D\u0442\u0430\u043A\u0442\u043E\u0432: ${error.message}") }
        }
    }

    fun requestRequiredPermissions() {
        permissionsLauncher.launch(requiredPermissions())
    }

    fun completeOnboarding(managerName: String? = null, managerPhone: String? = null) {
        lifecycleScope.launch {
            managerName?.let { viewModel.setManagerName(it) }
            managerPhone?.let { viewModel.setManagerPhone(it) }
            viewModel.markOnboardingCompleted()
            refreshPersonalContactsAfterAuthorization()
        }
    }

    fun hasAllPermissions(): Boolean = requiredPermissions().all {
        ContextCompat.checkSelfPermission(this, it) == PackageManager.PERMISSION_GRANTED
    }

    fun setDialNumber(number: String) {
        viewModel.setDialNumber(number)
        binding.bottomNav.selectedItemId = R.id.nav_dial
    }

    fun openDialScreen() {
        supportFragmentManager.popBackStack(null, androidx.fragment.app.FragmentManager.POP_BACK_STACK_INCLUSIVE)
        binding.btnTopBack.visibility = android.view.View.GONE
        if (binding.bottomNav.selectedItemId == R.id.nav_dial) {
            openFragment(DialPadFragment.newInstance())
        } else {
            binding.bottomNav.selectedItemId = R.id.nav_dial
        }
    }

    private fun openNotificationsScreen() {
        openSecondaryFragment(NotificationsFragment.newInstance())
    }

    private fun openSecondaryFragment(fragment: androidx.fragment.app.Fragment) {
        binding.btnTopBack.visibility = android.view.View.VISIBLE
        supportFragmentManager.beginTransaction()
            .replace(R.id.fragmentContainer, fragment)
            .addToBackStack(null)
            .commit()
    }

    private fun updateTopBackVisibility() {
        binding.btnTopBack.visibility = when (supportFragmentManager.findFragmentById(R.id.fragmentContainer)) {
            is SettingsFragment, is UserFragment, is NotificationsFragment -> android.view.View.VISIBLE
            else -> android.view.View.GONE
        }
    }

    fun openContactCard(phone: String) {
        supportFragmentManager.beginTransaction()
            .replace(R.id.fragmentContainer, ContactCardFragment.newInstance(phone))
            .addToBackStack(null)
            .commit()
    }

    fun openContactHistory(phone: String, type: String) {
        supportFragmentManager.beginTransaction()
            .replace(R.id.fragmentContainer, ContactHistoryFragment.newInstance(phone, type))
            .addToBackStack(null)
            .commit()
    }

    fun openNotificationTarget(notification: NotificationEntity) {
        val payload = runCatching { JSONObject(notification.payloadJson) }.getOrNull()
        val phone = payload?.optString("phone").orEmpty()
        when (notification.targetScreen) {
            NotificationTargets.CONTACT_CARD,
            NotificationTargets.PERSONAL_CONTACT -> {
                if (phone.isNotBlank()) openContactCard(phone) else binding.bottomNav.selectedItemId = R.id.nav_contacts
            }
            NotificationTargets.REMINDER -> {
                if (phone.isNotBlank()) openContactHistory(phone, ContactHistoryFragment.TYPE_REMINDERS) else binding.bottomNav.selectedItemId = R.id.nav_recent
            }
            NotificationTargets.CALL_HISTORY -> {
                if (phone.isNotBlank()) openContactHistory(phone, ContactHistoryFragment.TYPE_CALLS) else binding.bottomNav.selectedItemId = R.id.nav_recent
            }
            NotificationTargets.CALL_DETAIL -> {
                if (notification.entityId != null && phone.isNotBlank()) {
                    startActivity(
                        Intent(this, PostCallActivity::class.java).apply {
                            putExtra(PostCallActivity.EXTRA_CALL_ID, notification.entityId)
                            putExtra(PostCallActivity.EXTRA_PHONE, phone)
                            putExtra(PostCallActivity.EXTRA_NAME, payload?.optString("name").orEmpty().ifBlank { phone })
                        }
                    )
                } else {
                    binding.bottomNav.selectedItemId = R.id.nav_recent
                }
            }
            NotificationTargets.APP_UPDATE -> {
                startActivity(Intent(this, AboutActivity::class.java))
            }
            else -> binding.bottomNav.selectedItemId = R.id.nav_recent
        }
    }

    private fun openFragment(fragment: androidx.fragment.app.Fragment) {
        supportFragmentManager.beginTransaction()
            .replace(R.id.fragmentContainer, fragment)
            .commit()
    }

    private fun updateWarningState() {
        val messages = buildList {
            if (!hasAllPermissions()) add("\u041D\u0435 \u0432\u044B\u0434\u0430\u043D\u044B \u0432\u0441\u0435 \u0440\u0430\u0437\u0440\u0435\u0448\u0435\u043D\u0438\u044F")
            if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.O && !packageManager.canRequestPackageInstalls()) {
                add("\u0420\u0430\u0437\u0440\u0435\u0448\u0438\u0442\u0435 \u0443\u0441\u0442\u0430\u043D\u043E\u0432\u043A\u0443 \u0438\u0437 \u043D\u0435\u0438\u0437\u0432\u0435\u0441\u0442\u043D\u044B\u0445 \u0438\u0441\u0442\u043E\u0447\u043D\u0438\u043A\u043E\u0432")
            }
            if (!isBatteryOptimizationDisabled()) add("\u0420\u0430\u0437\u0440\u0435\u0448\u0438\u0442\u0435 CallTrack \u0440\u0430\u0431\u043E\u0442\u0430\u0442\u044C \u0431\u0435\u0437 \u043E\u0433\u0440\u0430\u043D\u0438\u0447\u0435\u043D\u0438\u044F \u0431\u0430\u0442\u0430\u0440\u0435\u0438")
        }
        val warningText = messages.joinToString("\n")
        binding.tvWarning.text = warningText
        binding.tvWarning.visibility = if (warningText.isBlank()) android.view.View.GONE else android.view.View.VISIBLE
    }

    private fun requestUnknownAppsPermissionIfNeeded() {
        if (Build.VERSION.SDK_INT < Build.VERSION_CODES.O) return
        if (packageManager.canRequestPackageInstalls()) return
        val intent = Intent(
            Settings.ACTION_MANAGE_UNKNOWN_APP_SOURCES,
            Uri.parse("package:$packageName")
        )
        unknownAppsLauncher.launch(intent)
    }

    fun isBatteryOptimizationDisabled(): Boolean {
        if (Build.VERSION.SDK_INT < Build.VERSION_CODES.M) return true
        val powerManager = getSystemService(PowerManager::class.java)
        return powerManager.isIgnoringBatteryOptimizations(packageName)
    }

    fun requestBatteryOptimizationIfNeeded(force: Boolean = false) {
        if (Build.VERSION.SDK_INT < Build.VERSION_CODES.M || isBatteryOptimizationDisabled() || (!force && batteryOptimizationPromptShown)) return
        batteryOptimizationPromptShown = true
        AppLogger.log(this, "STABILITY", "\u0417\u0430\u043F\u0440\u043E\u0441 \u0440\u0430\u0437\u0440\u0435\u0448\u0435\u043D\u0438\u044F \u043D\u0430 \u0444\u043E\u043D\u043E\u0432\u0443\u044E \u0440\u0430\u0431\u043E\u0442\u0443 \u0431\u0435\u0437 \u043E\u0433\u0440\u0430\u043D\u0438\u0447\u0435\u043D\u0438\u044F \u0431\u0430\u0442\u0430\u0440\u0435\u0438")
        val directRequest = Intent(
            Settings.ACTION_REQUEST_IGNORE_BATTERY_OPTIMIZATIONS,
            Uri.parse("package:$packageName")
        )
        runCatching { batteryOptimizationLauncher.launch(directRequest) }
            .onFailure {
                AppLogger.log(this, "WARN", "\u041F\u0440\u044F\u043C\u043E\u0439 \u0437\u0430\u043F\u0440\u043E\u0441 \u043E\u043F\u0442\u0438\u043C\u0438\u0437\u0430\u0446\u0438\u0438 \u0431\u0430\u0442\u0430\u0440\u0435\u0438 \u043D\u0435\u0434\u043E\u0441\u0442\u0443\u043F\u0435\u043D, \u043E\u0442\u043A\u0440\u044B\u0432\u0430\u0435\u043C \u0441\u043F\u0438\u0441\u043E\u043A \u043D\u0430\u0441\u0442\u0440\u043E\u0435\u043A", it)
                batteryOptimizationLauncher.launch(Intent(Settings.ACTION_IGNORE_BATTERY_OPTIMIZATION_SETTINGS))
            }
    }

    private fun startTrackingService() {
        runCatching {
            ContextCompat.startForegroundService(this, Intent(this, CallTrackingService::class.java))
        }
        lifecycleScope.launch { viewModel.sync() }
    }

    override fun onDestroy() {
        AppLogger.log(this, "APP", "MainActivity \u0443\u043D\u0438\u0447\u0442\u043E\u0436\u0435\u043D\u0430")
        super.onDestroy()
    }

    private fun requiredPermissions(): Array<String> {
        return buildList {
            add(Manifest.permission.READ_PHONE_STATE)
            add(Manifest.permission.READ_CALL_LOG)
            add(Manifest.permission.READ_CONTACTS)
            add(Manifest.permission.CALL_PHONE)
            if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.TIRAMISU) add(Manifest.permission.POST_NOTIFICATIONS)
        }.toTypedArray()
    }

    companion object {
        const val EXTRA_OPEN_CONTACT_PHONE = "extra_open_contact_phone"
        const val EXTRA_RUN_UPDATE_CHECK = "extra_run_update_check"
        const val EXTRA_OPEN_DIAL = "extra_open_dial"
        const val EXTRA_OPEN_NOTIFICATIONS = "extra_open_notifications"
        const val EXTRA_OPEN_SETTINGS = "extra_open_settings"
        const val EXTRA_OPEN_USER = "extra_open_user"
        private const val MENU_ABOUT_ID = 1001
        private const val MENU_SETTINGS_ID = 1002
        private const val MENU_USER_ID = 1003
        private const val UPDATE_API_URL = "https://kvasmix.ru/vr/calltrack/api/update.php"
        private const val APK_FILE_NAME = "calltrack-update.apk"
        private const val APK_MIME_TYPE = "application/vnd.android.package-archive"
        private const val MAX_UPDATE_LOG_BODY_CHARS = 1000
        private const val APK_DOWNLOAD_MAX_ATTEMPTS = 2
        private const val APK_DOWNLOAD_RETRY_DELAY_MS = 2_000L
        private const val APK_DOWNLOAD_LOG_STEP_BYTES = 1024L * 1024L
    }

    private sealed interface UpdateCheckResult {
        data class Success(val info: UpdateInfo) : UpdateCheckResult
        data class NetworkError(val reason: String) : UpdateCheckResult
        data class InvalidResponse(val reason: String) : UpdateCheckResult
    }

    private data class UpdateInfo(
        val versionCode: Int,
        val versionName: String,
        val apkUrl: String,
        val rawApkUrl: String,
        val releaseNotes: List<String>,
        val mandatory: Boolean
    ) {
        val apkDownloadUrls: List<String> = listOf(apkUrl, rawApkUrl).distinct()
    }
}
