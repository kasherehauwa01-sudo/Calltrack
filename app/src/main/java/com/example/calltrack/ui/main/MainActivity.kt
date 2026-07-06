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
import android.provider.Settings
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
import org.json.JSONException
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
            AppLogger.log(this@MainActivity, "UPDATE", "Проверка обновлений через kvasmix.ru")
            AppLogger.log(this@MainActivity, "UPDATE", "Текущая версия: ${BuildConfig.VERSION_CODE} (${BuildConfig.VERSION_NAME})")
            Toast.makeText(this@MainActivity, "Проверка обновлений...", Toast.LENGTH_SHORT).show()

            val updateResult = withContext(Dispatchers.IO) { fetchLatestUpdateInfo() }
            when (updateResult) {
                is UpdateCheckResult.NetworkError -> {
                    AppLogger.log(this@MainActivity, "ERROR", "Сервер обновлений недоступен: ${updateResult.reason}")
                    syncUpdateLogsToDashboard()
                    Toast.makeText(this@MainActivity, "Не удалось проверить наличие обновлений.", Toast.LENGTH_LONG).show()
                    return@launch
                }
                is UpdateCheckResult.InvalidResponse -> {
                    AppLogger.log(this@MainActivity, "ERROR", "Некорректный ответ сервера обновлений: ${updateResult.reason}")
                    syncUpdateLogsToDashboard()
                    Toast.makeText(this@MainActivity, "Некорректный ответ сервера обновлений.", Toast.LENGTH_LONG).show()
                    return@launch
                }
                is UpdateCheckResult.Success -> {
                    val update = updateResult.info
                    AppLogger.log(this@MainActivity, "UPDATE", "Версия на сервере: ${update.versionCode} (${update.versionName})")
                    if (update.versionCode <= BuildConfig.VERSION_CODE) {
                        AppLogger.log(this@MainActivity, "UPDATE", "Версия актуальна")
                        syncUpdateLogsToDashboard()
                        AlertDialog.Builder(this@MainActivity)
                            .setMessage("У вас установлена актуальная версия приложения.")
                            .setPositiveButton("OK") { dialog: DialogInterface, _: Int -> dialog.dismiss() }
                            .show()
                        return@launch
                    }
                    syncUpdateLogsToDashboard()
                    showUpdateDialog(update)
                }
            }
        }
    }

    private suspend fun syncUpdateLogsToDashboard() {
        withContext(Dispatchers.IO) {
            runCatching { viewModel.sendUserTelemetry() }
                .onSuccess { AppLogger.log(this@MainActivity, "UPDATE", "Логи обновления отправлены в админ-панель") }
                .onFailure { AppLogger.log(this@MainActivity, "ERROR", "Не удалось отправить логи обновления в админ-панель: ${it.message}", it) }
        }
        dialogBuilder.show()
    }

    private fun showUpdateDialog(update: UpdateInfo) {
        val notes = update.releaseNotes
            .filter { it.isNotBlank() }
            .joinToString(separator = "\n") { "• $it" }
            .ifBlank { "Список изменений не указан." }
        val dialogBuilder = AlertDialog.Builder(this)
            .setTitle("Доступна версия ${update.versionName}")
            .setMessage(notes)
            .setPositiveButton("Обновить") { _: DialogInterface, _: Int ->
                startApkUpdateDownload(update.apkUrl)
            }
        if (!update.mandatory) {
            dialogBuilder.setNegativeButton("Отмена") { dialog: DialogInterface, _: Int -> dialog.dismiss() }
        }
        dialogBuilder.show()
    }

    private fun showUpdateDialog(update: UpdateInfo) {
        val notes = update.releaseNotes
            .filter { it.isNotBlank() }
            .joinToString(separator = "\n") { "• $it" }
            .ifBlank { "Список изменений не указан." }
        val dialogBuilder = AlertDialog.Builder(this)
            .setTitle("Доступна версия ${update.versionName}")
            .setMessage(notes)
            .setPositiveButton("Обновить") { _: DialogInterface, _: Int ->
                startApkUpdateDownload(update.apkUrl)
            }
        if (!update.mandatory) {
            dialogBuilder.setNegativeButton("Отмена") { dialog: DialogInterface, _: Int -> dialog.dismiss() }
        }
        dialogBuilder.show()
    }

    private fun startApkUpdateDownload(apkUrl: String) {
        lifecycleScope.launch {
            AppLogger.log(this@MainActivity, "UPDATE", "Начата загрузка APK с сервера kvasmix.ru")
            Toast.makeText(this@MainActivity, "Загрузка обновления...", Toast.LENGTH_SHORT).show()
            val apkFile = File(cacheDir, APK_FILE_NAME).apply { delete() }
            val manager = getSystemService(DownloadManager::class.java)
            val request = DownloadManager.Request(Uri.parse(apkUrl))
                .setTitle("Обновление Calltrack")
                .setDescription("Загрузка новой версии приложения")
                .setNotificationVisibility(DownloadManager.Request.VISIBILITY_VISIBLE_NOTIFY_COMPLETED)
                .setAllowedOverMetered(true)
                .setAllowedOverRoaming(true)
                .setMimeType(APK_MIME_TYPE)
                .addRequestHeader("Accept", APK_MIME_TYPE)
                .setDestinationUri(Uri.fromFile(apkFile))

            apkDownloadId = manager.enqueue(request)
            AppLogger.log(this@MainActivity, "INFO", "Начата загрузка обновления. downloadId=$apkDownloadId, url=$apkUrl")
        }
    }

    private fun fetchLatestUpdateInfo(): UpdateCheckResult {
        AppLogger.log(this, "UPDATE", "Запрос метаданных обновления: $UPDATE_API_URL")
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
                    "Ответ сервера обновлений: code=${response.code}, contentType=${response.header("Content-Type").orEmpty()}, bodyLength=${body.length}, bodyPreview=$preview"
                )
                if (!response.isSuccessful) {
                    return UpdateCheckResult.NetworkError("HTTP ${response.code}")
                }
                parseUpdateInfo(body)
            }
        } catch (error: JSONException) {
            AppLogger.log(this, "ERROR", "JSON сервера обновлений поврежден: ${error.message}", error)
            UpdateCheckResult.InvalidResponse("JSON parse error: ${error.message}")
        } catch (error: Exception) {
            AppLogger.log(this, "ERROR", "Ошибка запроса обновлений: ${error.message}", error)
            UpdateCheckResult.NetworkError(error.message ?: error.javaClass.simpleName)
        }
    }

    private fun parseUpdateInfo(body: String): UpdateCheckResult {
        if (body.isBlank()) return UpdateCheckResult.InvalidResponse("Пустое тело ответа")

        val json = JSONObject(body)
        val status = json.optString("status").trim()
        if (status.isNotBlank() && !status.equals("ok", ignoreCase = true) && !status.equals("success", ignoreCase = true)) {
            return UpdateCheckResult.InvalidResponse("status=$status")
        }

        val versionCode = json.optFlexibleInt("versionCode", "version_code")
        val versionName = json.optFlexibleString("versionName", "version_name")
        val apkUrl = json.optFlexibleString("apk", "apkUrl", "apk_url", "url")
        if (versionCode == null) return UpdateCheckResult.InvalidResponse("Не найден versionCode/version_code")
        if (versionName.isBlank()) return UpdateCheckResult.InvalidResponse("Не найден versionName/version_name")
        if (apkUrl.isBlank()) return UpdateCheckResult.InvalidResponse("Не найдена ссылка apk")
        if (!apkUrl.startsWith("https://", ignoreCase = true) && !apkUrl.startsWith("http://", ignoreCase = true)) {
            return UpdateCheckResult.InvalidResponse("Некорректная ссылка apk=$apkUrl")
        }

        val releaseNotes = json.optFlexibleStringList("releaseNotes", "release_notes")
        val mandatory = json.optFlexibleBoolean("mandatory", false)
        AppLogger.log(
            this,
            "UPDATE",
            "Метаданные обновления распознаны: versionCode=$versionCode, versionName=$versionName, mandatory=$mandatory, notes=${releaseNotes.size}, apk=$apkUrl"
        )
        return UpdateCheckResult.Success(
            UpdateInfo(
                versionCode = versionCode,
                versionName = versionName,
                apkUrl = apkUrl,
                releaseNotes = releaseNotes,
                mandatory = mandatory
            )
        )
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

    private fun installDownloadedApk(downloadId: Long) {
        AppLogger.log(this, "UPDATE", "APK загружен, подготовка установки")
        Toast.makeText(this, "Подготовка установки...", Toast.LENGTH_SHORT).show()
        val manager = getSystemService(DownloadManager::class.java)
        val query = DownloadManager.Query().setFilterById(downloadId)
        var localUri: String? = null
        manager.query(query)?.use { cursor ->
            if (!cursor.moveToFirst()) return
            val status = cursor.getInt(cursor.getColumnIndexOrThrow(DownloadManager.COLUMN_STATUS))
            if (status != DownloadManager.STATUS_SUCCESSFUL) {
                Toast.makeText(this, "Ошибка загрузки обновления.", Toast.LENGTH_SHORT).show()
                return
            }
            localUri = cursor.getString(cursor.getColumnIndexOrThrow(DownloadManager.COLUMN_LOCAL_URI))
        }

        val apkFile = localUri?.let { Uri.parse(it).path }?.let { File(it) }
        if (apkFile == null || !apkFile.exists()) {
            Toast.makeText(this, "Ошибка загрузки обновления.", Toast.LENGTH_SHORT).show()
            return
        }
        val apkUri = FileProvider.getUriForFile(
            this,
            "$packageName.fileprovider",
            apkFile
        )

        val installIntent = Intent(Intent.ACTION_VIEW).apply {
            setDataAndType(apkUri, APK_MIME_TYPE)
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
        private const val UPDATE_API_URL = "https://kvasmix.ru/vr/calltrack/api/update.php"
        private const val APK_FILE_NAME = "calltrack-update.apk"
        private const val APK_MIME_TYPE = "application/vnd.android.package-archive"
        private const val MAX_UPDATE_LOG_BODY_CHARS = 1000
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
        val releaseNotes: List<String>,
        val mandatory: Boolean
    )
}
