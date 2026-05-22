package com.example.calltrack.ui.main

import android.Manifest
import android.app.DownloadManager
import android.content.BroadcastReceiver
import android.content.Context
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
import androidx.appcompat.app.AlertDialog
import androidx.activity.result.contract.ActivityResultContracts
import androidx.activity.viewModels
import androidx.appcompat.app.AlertDialog
import androidx.appcompat.app.AppCompatDelegate
import androidx.core.content.ContextCompat
import androidx.core.content.FileProvider
import androidx.lifecycle.lifecycleScope
import androidx.lifecycle.repeatOnLifecycle
import com.example.calltrack.App
import com.example.calltrack.R
import com.example.calltrack.data.repository.PrefsManager
import com.example.calltrack.databinding.ActivityMainBinding
import com.example.calltrack.logging.AppLogger
import com.example.calltrack.service.CallTrackingService
import com.example.calltrack.service.CallUiEventBus
import com.example.calltrack.ui.calls.CallListFragment
import com.example.calltrack.ui.base.BaseActivity
import com.example.calltrack.ui.contacts.ContactsFragment
import com.example.calltrack.ui.contactcard.ContactCardFragment
import com.example.calltrack.ui.contactcard.ContactHistoryFragment
import com.example.calltrack.ui.dialpad.DialPadFragment
import com.example.calltrack.ui.onboarding.OnboardingFragment
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.launch
import kotlinx.coroutines.withContext
import okhttp3.OkHttpClient
import okhttp3.Request
import java.io.File

class MainActivity : BaseActivity() {

    private lateinit var binding: ActivityMainBinding
    private var apkDownloadId: Long = -1L
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
        binding = ActivityMainBinding.inflate(layoutInflater)
        setContentView(binding.root)

        applyWindowInsets()
        setupBottomNav()
        setupSettingsButton()
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
                startTrackingService()
            }
            updateWarningState()
        }
    }

    override fun onNewIntent(intent: Intent) {
        super.onNewIntent(intent)
        handleExternalNavigation(intent)
    }

    private fun applyWindowInsets() = applyInsets(binding.root, binding.statusBarOverlay)

    private fun handleExternalNavigation(intent: Intent?) {
        val phone = intent?.getStringExtra(EXTRA_OPEN_CONTACT_PHONE).orEmpty()
        if (phone.isNotBlank()) {
            openContactCard(phone)
            return
        }
        if (intent?.getBooleanExtra(EXTRA_RUN_UPDATE_CHECK, false) == true) {
            checkForUpdatesAndPrompt()
        }
    }


    private fun applySavedTheme() {
        AppCompatDelegate.setDefaultNightMode(AppCompatDelegate.MODE_NIGHT_NO)
    }

    private fun setupSettingsButton() {
        binding.btnSettings.setOnClickListener { anchor ->
            PopupMenu(this, anchor).apply {
                menu.add(0, MENU_ABOUT_ID, 0, getString(R.string.about_app))
                menu.add(0, MENU_USER_ID, 1, getString(R.string.user))
                setOnMenuItemClickListener { menuItem ->
                    when (menuItem.itemId) {
                        MENU_ABOUT_ID -> startActivity(Intent(this@MainActivity, AboutActivity::class.java))
                        MENU_USER_ID -> openFragment(UserFragment.newInstance())
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
            AppLogger.log(this@MainActivity, "INFO", "Пользователь запустил проверку обновления")
            Toast.makeText(this@MainActivity, "Проверяем наличие новой версии…", Toast.LENGTH_SHORT).show()

            val latestFromApi = withContext(Dispatchers.IO) { fetchLatestReleaseInfo() }
            if (latestFromApi == null) {
                AppLogger.log(this@MainActivity, "WARN", "Не удалось получить данные о версии с GitHub")
                Toast.makeText(this@MainActivity, "Не удалось проверить обновление. Повторите позже", Toast.LENGTH_LONG).show()
                return@launch
            }

            val latest = latestFromApi
            if (!isRemoteVersionNewer(BuildConfig.VERSION_NAME, latest.tag)) {
                AppLogger.log(this@MainActivity, "INFO", "Обновление не требуется. Текущая версия актуальна")
                AlertDialog.Builder(this@MainActivity)
                    .setMessage("У Вас установлена актуальная версия приложения")
                    .setPositiveButton("OK", null)
                    .show()
                return@launch
            }

            AlertDialog.Builder(this@MainActivity)
                .setTitle("Найдена свежая версия")
                .setMessage("Установить?")
                .setPositiveButton("Да") { _, _ -> startApkUpdateDownload(latest.apkUrl) }
                .setNegativeButton("Нет", null)
                .show()
        }
    }

    private fun startApkUpdateDownload(apkUrl: String) {
        lifecycleScope.launch {
            Log.d("UPDATE_FLOW", "startApkUpdateDownload вызван")
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
            .url(LATEST_RELEASE_API)
            .addHeader("Accept", "application/vnd.github+json")
            .build()

        return runCatching {
            updateHttpClient.newCall(request).execute().use { response ->
                if (!response.isSuccessful) return null
                val body = response.body?.string().orEmpty()
                val json = org.json.JSONObject(body)
                val tag = json.optString("tag_name").orEmpty()

                var apkUrl = ""
                val assets = json.optJSONArray("assets")
                if (assets != null) {
                    for (i in 0 until assets.length()) {
                        val asset = assets.optJSONObject(i) ?: continue
                        val url = asset.optString("browser_download_url")
                        if (url.endsWith(".apk", ignoreCase = true)) {
                            apkUrl = url
                            break
                        }
                    }
                }

                if (tag.isBlank() || apkUrl.isBlank()) return null
                ReleaseInfo(tag = tag, apkUrl = apkUrl)
            }
        }.getOrNull()
    }

    private fun isRemoteVersionNewer(current: String, remoteTag: String): Boolean {
        val currentVersion = extractDotVersion(current)
        val remoteVersion = extractDotVersion(remoteTag)

        // Если любую из версий нельзя корректно распарсить в semver, считаем,
        // что обновление недоступно, чтобы не запускать скачивание ошибочно.
        if (remoteVersion == null) return false
        if (currentVersion == null) return false

        val max = maxOf(currentVersion.size, remoteVersion.size)
        for (i in 0 until max) {
            val c = currentVersion.getOrElse(i) { 0 }
            val r = remoteVersion.getOrElse(i) { 0 }
            if (r > c) return true
            if (r < c) return false
        }
        return false
    }

    private fun extractDotVersion(value: String): List<Int>? {
        val match = Regex("(\\d+(?:\\.\\d+)+)").find(value) ?: return null
        return match.groupValues[1].split('.').mapNotNull { it.toIntOrNull() }
    }

    private fun installDownloadedApk(downloadId: Long) {
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
                    openFragment(CallListFragment.newInstance())
                    true
                }
                R.id.nav_contacts -> {
                    openFragment(ContactsFragment.newInstance())
                    true
                }
                else -> return@setOnItemSelectedListener false
            }
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
        private const val LATEST_RELEASE_API =
            "https://api.github.com/repos/kasherehauwa01-sudo/Calltrack/releases/latest"
        private const val FALLBACK_RELEASE_TAG = "v05-05-26-01"
        private const val FALLBACK_RELEASE_APK_URL =
            "https://github.com/kasherehauwa01-sudo/Calltrack/releases/download/v05-05-26-01/CallTrack-1.0.apk"
        private const val APK_FILE_NAME = "update.apk"
    }

    private data class ReleaseInfo(
        val tag: String,
        val apkUrl: String
    )
}
