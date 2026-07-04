package com.example.calltrack.ui.main

import android.Manifest
import android.app.DownloadManager
import android.content.BroadcastReceiver
import android.content.Context
import android.content.DialogInterface
import android.content.Intent
import android.content.IntentFilter
import android.content.pm.PackageManager
import android.net.Uri
import android.os.Build
import android.os.Bundle
import android.os.Environment
import android.provider.Settings
import android.util.Log
import android.widget.PopupMenu
import android.widget.Toast
import androidx.activity.result.contract.ActivityResultContracts
import androidx.activity.viewModels
import androidx.appcompat.app.AlertDialog
import androidx.appcompat.app.AppCompatDelegate
import androidx.core.content.ContextCompat
import androidx.core.content.FileProvider
import androidx.lifecycle.lifecycleScope
import com.example.calltrack.App
import com.example.calltrack.BuildConfig
import com.example.calltrack.R
import com.example.calltrack.data.local.NotificationEntity
import com.example.calltrack.data.notification.NotificationTargets
import com.example.calltrack.data.repository.PrefsManager
import com.example.calltrack.databinding.ActivityMainBinding
import com.example.calltrack.logging.AppLogger
import com.example.calltrack.service.CallTrackingService
import com.example.calltrack.ui.calls.CallListFragment
import com.example.calltrack.ui.analytics.AnalyticsActivity
import com.example.calltrack.ui.base.BaseActivity
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
import org.json.JSONObject
import java.io.File

class MainActivity : BaseActivity() {

    private lateinit var binding: ActivityMainBinding
    private var apkDownloadId: Long = -1L
    private var updateCheckHandled = false
    private val viewModel: MainViewModel by viewModels {
        MainViewModel.Factory((application as App).repository)
    }
    private val updateHttpClient = OkHttpClient()

    private val permissionsLauncher = registerForActivityResult(
        ActivityResultContracts.RequestMultiplePermissions()
    ) { updateWarningState() }
    private val unknownAppsLauncher = registerForActivityResult(
        ActivityResultContracts.StartActivityForResult()
    ) { updateWarningState() }
    private val downloadCompleteReceiver = object : BroadcastReceiver() {
        override fun onReceive(context: Context?, intent: Intent?) {
            if (intent?.action != DownloadManager.ACTION_DOWNLOAD_COMPLETE) return
            val completedId = intent.getLongExtra(DownloadManager.EXTRA_DOWNLOAD_ID, -1L)
            if (completedId == apkDownloadId) {
                installDownloadedApk(completedId)
            }
        }
    }

    override fun onCreate(savedInstanceState: Bundle?) {
        applySavedTheme()
        super.onCreate(savedInstanceState)
        AppLogger.log(this, "APP", "MainActivity создана")
        binding = ActivityMainBinding.inflate(layoutInflater)
        setContentView(binding.root)

        applyWindowInsets()
        setupBottomNav()
        setupSettingsButton()
        setupAnalyticsButton()
        setupNotificationButton()
        registerDownloadReceiver()
        handleExternalNavigation(intent)
        if (intent.getBooleanExtra(EXTRA_RUN_UPDATE_CHECK, false)) {
            checkForUpdatesAndPrompt()
        }

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
        AppLogger.log(this, "APP", "Приложение на экране")
        lifecycleScope.launch { viewModel.sendUserTelemetry() }
    }

    override fun onPause() {
        AppLogger.log(this, "APP", "Приложение свернуто")
        super.onPause()
    }

    private fun applyWindowInsets() = applyInsets(binding.root, binding.statusBarOverlay)

    private fun handleExternalNavigation(intent: Intent?) {
        val phone = intent?.getStringExtra(EXTRA_OPEN_CONTACT_PHONE).orEmpty()
        if (phone.isNotBlank()) {
            openContactCard(phone)
            return
        }
        if (intent?.getBooleanExtra(EXTRA_RUN_UPDATE_CHECK, false) == true) {
            if (!updateCheckHandled) {
                updateCheckHandled = true
                checkForUpdatesAndPrompt()
            }
        }
    }


    private fun applySavedTheme() {
        AppCompatDelegate.setDefaultNightMode(AppCompatDelegate.MODE_NIGHT_NO)
    }

    private fun setupAnalyticsButton() {
        binding.btnAnalytics.setOnClickListener {
            AppLogger.log(this, "UI", "Открыт экран: Аналитика")
            startActivity(Intent(this, AnalyticsActivity::class.java))
        }
    }

    private fun setupNotificationButton() {
        binding.btnNotifications.setOnClickListener {
            AppLogger.log(this, "UI", "Открыт экран: Уведомления")
            supportFragmentManager.beginTransaction()
                .replace(R.id.fragmentContainer, NotificationsFragment.newInstance())
                .addToBackStack(null)
                .commit()
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
            AppLogger.log(this, "UI", "Нажата кнопка: Настройки")
            PopupMenu(this, anchor).apply {
                menu.add(0, MENU_ABOUT_ID, 0, getString(R.string.about_app))
                menu.add(0, MENU_USER_ID, 1, getString(R.string.user))
                setOnMenuItemClickListener { menuItem ->
                    when (menuItem.itemId) {
                        MENU_ABOUT_ID -> startActivity(Intent(this@MainActivity, AboutActivity::class.java))
                        MENU_USER_ID -> {
                            AppLogger.log(this@MainActivity, "UI", "Открыт экран: Пользователь")
                            openFragment(UserFragment.newInstance())
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
        lifecycleScope.launch {
            AppLogger.log(this@MainActivity, "UI", "Нажата кнопка: Обновить")
            AppLogger.log(this@MainActivity, "UPDATE", "Проверка обновлений")
            AppLogger.log(this@MainActivity, "UPDATE", "Текущая версия: ${BuildConfig.VERSION_NAME}")
            AppLogger.log(this@MainActivity, "NOTIFY", "Показ уведомления: Проверяем обновление")
            Toast.makeText(this@MainActivity, "Проверяем наличие новой версии…", Toast.LENGTH_SHORT).show()

            val latest = withContext(Dispatchers.IO) { fetchLatestReleaseInfo() }
            if (latest == null) {
                AppLogger.log(this@MainActivity, "ERROR", "Ошибка загрузки данных: не удалось получить данные о версии с сервера")
                AppLogger.log(this@MainActivity, "NOTIFY", "Показ уведомления: Ошибка сети")
                Toast.makeText(this@MainActivity, "Не удалось проверить обновление. Повторите позже", Toast.LENGTH_LONG).show()
                return@launch
            }

            AppLogger.log(this@MainActivity, "UPDATE", "Удаленная версия: ${latest.versionName} (${latest.versionCode})")
            if (latest.versionCode <= BuildConfig.VERSION_CODE) {
                AppLogger.log(this@MainActivity, "UPDATE", "Версия актуальна")
                AlertDialog.Builder(this@MainActivity)
                    .setMessage("У вас установлена актуальная версия приложения.")
                    .setPositiveButton("OK") { dialog: DialogInterface, _: Int ->
                        dialog.dismiss()
                    }
                    .show()
                return@launch
            }
            AppLogger.log(this@MainActivity, "UPDATE", "Найдена новая версия")

            val notes = latest.releaseNotes.takeIf { it.isNotEmpty() }?.joinToString("\n") { "• $it" }
                ?: "Список изменений не указан."
            AlertDialog.Builder(this@MainActivity)
                .setTitle("Доступна версия ${latest.versionName}")
                .setMessage("Список изменений:\n$notes")
                .setPositiveButton("Скачать и установить") { _: DialogInterface, _: Int ->
                    startApkUpdateDownload(latest.apkUrl)
                }
                .setNegativeButton(if (latest.mandatory) "Позже" else "Отмена") { dialog: DialogInterface, _: Int ->
                    dialog.dismiss()
                }
                .show()
        }
    }

    private fun startApkUpdateDownload(apkUrl: String) {
        lifecycleScope.launch {
            Log.d("UPDATE_FLOW", "startApkUpdateDownload вызван")
            AppLogger.log(this@MainActivity, "UPDATE", "Начата загрузка APK")
            AppLogger.log(this@MainActivity, "NOTIFY", "Показ уведомления: Началась загрузка")
            val manager = getSystemService(DownloadManager::class.java)
            val request = DownloadManager.Request(Uri.parse(apkUrl))
                .setTitle("Обновление Calltrack")
                .setDescription("Загрузка новой версии приложения")
                .setNotificationVisibility(DownloadManager.Request.VISIBILITY_VISIBLE_NOTIFY_COMPLETED)
                .setAllowedOverMetered(true)
                .setAllowedOverRoaming(true)
                .setMimeType("application/vnd.android.package-archive")
                .addRequestHeader("Accept", "application/vnd.android.package-archive")
                .setDestinationInExternalPublicDir(Environment.DIRECTORY_DOWNLOADS, APK_FILE_NAME)

            apkDownloadId = -1L
            apkDownloadId = manager.enqueue(request)
            AppLogger.log(this@MainActivity, "INFO", "Начата загрузка обновления. downloadId=$apkDownloadId, url=$apkUrl")
            Toast.makeText(this@MainActivity, "Началась загрузка обновления", Toast.LENGTH_SHORT).show()
        }
    }

    private fun fetchLatestReleaseInfo(): ReleaseInfo? {
        val request = Request.Builder()
            .url(UPDATE_API_URL)
            .addHeader("Accept", "application/json")
            .build()

        return runCatching {
            updateHttpClient.newCall(request).execute().use { response ->
                if (!response.isSuccessful) return null
                val body = response.body?.string().orEmpty()
                val json = JSONObject(body)
                if (json.optString("status") == "error") return null
                val versionName = json.optString("versionName").orEmpty()
                val versionCode = json.optInt("versionCode", 0)
                val apkUrl = json.optString("apk").orEmpty()
                if (versionName.isBlank() || versionCode <= 0 || apkUrl.isBlank()) return null
                val notes = mutableListOf<String>()
                val releaseNotes = json.optJSONArray("releaseNotes")
                if (releaseNotes != null) {
                    for (i in 0 until releaseNotes.length()) {
                        releaseNotes.optString(i).takeIf { it.isNotBlank() }?.let(notes::add)
                    }
                }
                ReleaseInfo(
                    versionName = versionName,
                    versionCode = versionCode,
                    mandatory = json.optBoolean("mandatory", false),
                    apkUrl = apkUrl,
                    releaseNotes = notes
                )
            }
        }.getOrNull()
    }

    private fun installDownloadedApk(downloadId: Long) {
        AppLogger.log(this, "UPDATE", "APK загружен, запуск установки")
        val manager = getSystemService(DownloadManager::class.java)
        val query = DownloadManager.Query().setFilterById(downloadId)
        var localUri: String? = null
        manager.query(query)?.use { cursor ->
            if (!cursor.moveToFirst()) return
            val status = cursor.getInt(cursor.getColumnIndexOrThrow(DownloadManager.COLUMN_STATUS))
            if (status != DownloadManager.STATUS_SUCCESSFUL) {
                Toast.makeText(this, "Не удалось загрузить обновление", Toast.LENGTH_SHORT).show()
                return
            }
            localUri = cursor.getString(cursor.getColumnIndexOrThrow(DownloadManager.COLUMN_LOCAL_URI))
        }

        val apkFile = localUri?.let { Uri.parse(it).path }?.let { File(it) }
        if (apkFile == null || !apkFile.exists()) {
            Toast.makeText(this, "Файл обновления не найден", Toast.LENGTH_SHORT).show()
            return
        }
        val apkUri = FileProvider.getUriForFile(
            this,
            "$packageName.fileprovider",
            apkFile
        )

        val installIntent = Intent(Intent.ACTION_VIEW).apply {
            setDataAndType(apkUri, "application/vnd.android.package-archive")
            addFlags(Intent.FLAG_ACTIVITY_NEW_TASK)
            addFlags(Intent.FLAG_GRANT_READ_URI_PERMISSION)
        }
        runCatching { startActivity(installIntent) }
            .onFailure {
                Toast.makeText(this, "Не удалось открыть установщик APK", Toast.LENGTH_LONG).show()
            }
    }

    private fun registerDownloadReceiver() {
        val filter = IntentFilter(DownloadManager.ACTION_DOWNLOAD_COMPLETE)
        if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.TIRAMISU) {
            registerReceiver(downloadCompleteReceiver, filter, RECEIVER_NOT_EXPORTED)
        } else {
            @Suppress("DEPRECATION")
            registerReceiver(downloadCompleteReceiver, filter)
        }
    }

    private fun setupBottomNav() {
        binding.bottomNav.setOnItemSelectedListener { item ->
            when (item.itemId) {
                R.id.nav_dial -> {
                    openFragment(DialPadFragment.newInstance())
                    true
                }
                R.id.nav_recent -> {
                    AppLogger.log(this, "UI", "Открыт экран: История звонков")
                    openFragment(CallListFragment.newInstance())
                    true
                }
                R.id.nav_contacts -> {
                    AppLogger.log(this, "UI", "Открыт экран: Контакты")
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
                .onSuccess { count -> AppLogger.log(this@MainActivity, "API", "Личные контакты загружены: $count") }
                .onFailure { error -> AppLogger.log(this@MainActivity, "ERROR", "Ошибка загрузки личных контактов: ${error.message}") }
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
            if (!hasAllPermissions()) add("Не выданы все разрешения")
            if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.O && !packageManager.canRequestPackageInstalls()) {
                add("Разрешите установку из неизвестных источников")
            }
        }
        binding.tvWarning.text = messages.joinToString("\n")
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

    private fun startTrackingService() {
        runCatching {
            ContextCompat.startForegroundService(this, Intent(this, CallTrackingService::class.java))
        }
        lifecycleScope.launch { viewModel.sync() }
    }

    override fun onDestroy() {
        AppLogger.log(this, "APP", "MainActivity уничтожена")
        runCatching { unregisterReceiver(downloadCompleteReceiver) }
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
        private const val MENU_ABOUT_ID = 1001
        private const val MENU_USER_ID = 1003
        private val UPDATE_API_URL = BuildConfig.SQL_API_BASE_URL + "update.php"
        private const val APK_FILE_NAME = "update.apk"
    }

    private data class ReleaseInfo(
        val versionName: String,
        val versionCode: Int,
        val mandatory: Boolean,
        val apkUrl: String,
        val releaseNotes: List<String>
    )
}
