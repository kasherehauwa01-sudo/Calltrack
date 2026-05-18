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
import android.text.InputFilter
import android.widget.EditText
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
        }
    }


    private fun applySavedTheme() {
        AppCompatDelegate.setDefaultNightMode(AppCompatDelegate.MODE_NIGHT_NO)
    }

    private fun setupSettingsButton() {
        binding.btnSettings.setOnClickListener { anchor ->
            PopupMenu(this, anchor).apply {
                menu.add(getString(R.string.about_app))
                menu.add(getString(R.string.update_app))
                menu.add(getString(R.string.user))
                setOnMenuItemClickListener { menuItem ->
                    when (menuItem.title) {
                        getString(R.string.about_app) -> startActivity(Intent(this@MainActivity, AboutActivity::class.java))
                        getString(R.string.user) -> openFragment(UserFragment.newInstance())
                        else -> checkForUpdatesAndPrompt()
                    }
                    true
                }
                show()
            }
        }
    }

    private fun checkForUpdatesAndPrompt() {
        lifecycleScope.launch {
            Toast.makeText(this@MainActivity, "Проверяем наличие новой версии…", Toast.LENGTH_SHORT).show()

            val latestFromApi = withContext(Dispatchers.IO) { fetchLatestReleaseInfo() }
            val latest = latestFromApi ?: ReleaseInfo(
                tag = FALLBACK_RELEASE_TAG,
                apkUrl = FALLBACK_RELEASE_APK_URL
            )

            if (latestFromApi == null) {
                Toast.makeText(
                    this@MainActivity,
                    "GitHub API временно недоступен, используем резервный источник",
                    Toast.LENGTH_LONG
                ).show()
            }

            if (!isRemoteVersionNewer(BuildConfig.VERSION_NAME, latest.tag)) {
                Toast.makeText(this@MainActivity, "У вас уже актуальная версия", Toast.LENGTH_SHORT).show()
                return@launch
            }

            AlertDialog.Builder(this@MainActivity)
                .setTitle("Найдено обновление")
                .setMessage("Установить?")
                .setPositiveButton("Да") { _, _ -> startApkUpdateDownload(latest.apkUrl) }
                .setNegativeButton("Нет") { _, _ -> binding.bottomNav.selectedItemId = R.id.nav_dial }
                .show()
        }
    }

    private fun startApkUpdateDownload(apkUrl: String) {
        lifecycleScope.launch {
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

            apkDownloadId = manager.enqueue(request)
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
                val tag = Regex("\"tag_name\"\s*:\s*\"([^\"]+)\"")
                    .find(body)
                    ?.groupValues
                    ?.get(1)
                    .orEmpty()
                val apkUrl = Regex("\"browser_download_url\"\s*:\s*\"([^\"]+\\.apk)\"")
                    .find(body)
                    ?.groupValues
                    ?.get(1)
                    ?.replace("\\/", "/")
                    .orEmpty()
                if (tag.isBlank() || apkUrl.isBlank()) return null
                ReleaseInfo(tag = tag, apkUrl = apkUrl)
            }
        }.getOrNull()
    }

    private fun isRemoteVersionNewer(current: String, remoteTag: String): Boolean {
        val currentNums = Regex("\\d+").findAll(current).map { it.value.toInt() }.toList()
        val remoteNums = Regex("\\d+").findAll(remoteTag).map { it.value.toInt() }.toList()
        val max = maxOf(currentNums.size, remoteNums.size)
        for (i in 0 until max) {
            val c = currentNums.getOrElse(i) { 0 }
            val r = remoteNums.getOrElse(i) { 0 }
            if (r > c) return true
            if (r < c) return false
        }
        return false
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

    fun completeOnboarding(managerName: String? = null) {
        lifecycleScope.launch {
            managerName?.let { viewModel.setManagerName(it) }
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
