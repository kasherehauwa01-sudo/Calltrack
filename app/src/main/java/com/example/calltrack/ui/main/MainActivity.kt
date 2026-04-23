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
import android.widget.PopupMenu
import android.widget.Toast
import androidx.activity.result.contract.ActivityResultContracts
import androidx.activity.viewModels
import androidx.appcompat.app.AppCompatActivity
import androidx.appcompat.app.AppCompatDelegate
import androidx.core.content.ContextCompat
import androidx.core.view.ViewCompat
import androidx.core.view.WindowInsetsCompat
import androidx.lifecycle.lifecycleScope
import androidx.lifecycle.repeatOnLifecycle
import com.example.calltrack.App
import com.example.calltrack.R
import com.example.calltrack.databinding.ActivityMainBinding
import com.example.calltrack.data.repository.PrefsManager
import com.example.calltrack.service.CallTrackingService
import com.example.calltrack.service.CallUiEventBus
import com.example.calltrack.ui.calls.CallListFragment
import com.example.calltrack.ui.contacts.ContactsFragment
import com.example.calltrack.ui.contactcard.ContactCardFragment
import com.example.calltrack.ui.contactcard.ContactHistoryFragment
import com.example.calltrack.ui.dialpad.DialPadFragment
import com.example.calltrack.ui.onboarding.OnboardingFragment
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.launch
import kotlinx.coroutines.runBlocking
import kotlinx.coroutines.withContext
import okhttp3.OkHttpClient
import okhttp3.Request
import org.json.JSONObject

class MainActivity : AppCompatActivity() {

    private lateinit var binding: ActivityMainBinding
    private lateinit var prefsManager: PrefsManager
    private var apkDownloadId: Long = -1L
    private val httpClient = OkHttpClient()
    private val viewModel: MainViewModel by viewModels {
        MainViewModel.Factory((application as App).repository)
    }

    private val permissionsLauncher = registerForActivityResult(
        ActivityResultContracts.RequestMultiplePermissions()
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
        prefsManager = PrefsManager(this)
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

    private fun applyWindowInsets() {
        ViewCompat.setOnApplyWindowInsetsListener(binding.root) { view, insets ->
            val systemBars = insets.getInsets(WindowInsetsCompat.Type.systemBars())
            view.setPadding(systemBars.left, systemBars.top, systemBars.right, systemBars.bottom)
            insets
        }
    }

    private fun handleExternalNavigation(intent: Intent?) {
        val phone = intent?.getStringExtra(EXTRA_OPEN_CONTACT_PHONE).orEmpty()
        if (phone.isNotBlank()) {
            openContactCard(phone)
        }
    }


    private fun applySavedTheme() {
        val mode = runBlocking { prefsManager.getThemeMode() }
        val nightMode = when (mode) {
            PrefsManager.THEME_LIGHT -> AppCompatDelegate.MODE_NIGHT_NO
            PrefsManager.THEME_DARK -> AppCompatDelegate.MODE_NIGHT_YES
            else -> AppCompatDelegate.MODE_NIGHT_NO
        }
        AppCompatDelegate.setDefaultNightMode(nightMode)
    }

    private fun setupSettingsButton() {
        binding.btnSettings.setOnClickListener { anchor ->
            PopupMenu(this, anchor).apply {
                menu.add(getString(R.string.about_app))
                menu.add(getString(R.string.update_app))
                setOnMenuItemClickListener { menuItem ->
                    when (menuItem.title) {
                        getString(R.string.about_app) -> startActivity(Intent(this@MainActivity, AboutActivity::class.java))
                        else -> startApkUpdateDownload()
                    }
                    true
                }
                show()
            }
        }
    }

    private fun startApkUpdateDownload() {
        lifecycleScope.launch {
            Toast.makeText(this@MainActivity, "Проверяем наличие новой версии…", Toast.LENGTH_SHORT).show()
            val latestApkUrl = resolveLatestApkUrl()
            if (latestApkUrl.isNullOrBlank()) {
                Toast.makeText(
                    this@MainActivity,
                    "Не удалось найти свежий APK в облаке",
                    Toast.LENGTH_LONG
                ).show()
                return@launch
            }

            val manager = getSystemService(DownloadManager::class.java)
            val request = DownloadManager.Request(Uri.parse(latestApkUrl))
                .setTitle("Обновление Calltrack")
                .setDescription("Загрузка новой версии приложения")
                .setNotificationVisibility(DownloadManager.Request.VISIBILITY_VISIBLE_NOTIFY_COMPLETED)
                .setAllowedOverMetered(true)
                .setAllowedOverRoaming(true)
                .setMimeType("application/vnd.android.package-archive")
                .setDestinationInExternalPublicDir(Environment.DIRECTORY_DOWNLOADS, APK_FILE_NAME)

            apkDownloadId = manager.enqueue(request)
            Toast.makeText(this@MainActivity, "Началась загрузка обновления", Toast.LENGTH_SHORT).show()
        }
    }

    private suspend fun resolveLatestApkUrl(): String? = withContext(Dispatchers.IO) {
        // 1) Пытаемся получить список файлов через API папки Mail.ru.
        val apiUrl = "https://cloud.mail.ru/api/v2/folder?weblink=$MAIL_PUBLIC_WEBLINK"
        val apiBody = httpGet(apiUrl)
        val fromApi = runCatching { parseLatestApkUrlFromApi(apiBody) }.getOrNull()
        if (!fromApi.isNullOrBlank()) return@withContext fromApi

        // 2) Fallback: парсим HTML страницы и ищем apk-ссылки.
        val html = httpGet(MAIL_PUBLIC_URL)
        parseLatestApkUrlFromHtml(html)
    }

    private fun parseLatestApkUrlFromApi(body: String?): String? {
        if (body.isNullOrBlank()) return null
        val root = JSONObject(body)
        val list = root.optJSONObject("body")?.optJSONArray("list") ?: return null

        var bestUrl: String? = null
        var bestMtime = Long.MIN_VALUE
        var bestName = ""

        for (i in 0 until list.length()) {
            val item = list.optJSONObject(i) ?: continue
            val itemType = item.optString("type")
            if (itemType != "file") continue

            val name = item.optString("name")
            if (!name.endsWith(".apk", ignoreCase = true)) continue

            val mtime = item.optLong("mtime", 0L)
            val direct = item.optString("url").takeIf { it.startsWith("http") }
            val weblink = item.optString("weblink").trim('/')
            val fallbackUrl = if (weblink.isNotBlank()) "https://cloud.mail.ru/public/$weblink?download=1" else null
            val candidateUrl = direct ?: fallbackUrl ?: continue

            if (mtime > bestMtime || (mtime == bestMtime && name > bestName)) {
                bestMtime = mtime
                bestName = name
                bestUrl = candidateUrl
            }
        }
        return bestUrl
    }

    private fun parseLatestApkUrlFromHtml(html: String?): String? {
        if (html.isNullOrBlank()) return null
        val regex = Regex("""https:\\/\\/[^"\\]+\.apk[^"\\]*""")
        val matches = regex.findAll(html).map { it.value.replace("\\/", "/") }.toList()
        return matches.lastOrNull()
    }

    private fun httpGet(url: String): String? {
        val request = Request.Builder()
            .url(url)
            .header("User-Agent", "Mozilla/5.0")
            .build()
        return runCatching {
            httpClient.newCall(request).execute().use { response ->
                if (!response.isSuccessful) return null
                response.body?.string()
            }
        }.getOrNull()
    }

    private fun installDownloadedApk(downloadId: Long) {
        val manager = getSystemService(DownloadManager::class.java)
        val query = DownloadManager.Query().setFilterById(downloadId)
        manager.query(query)?.use { cursor ->
            if (!cursor.moveToFirst()) return
            val status = cursor.getInt(cursor.getColumnIndexOrThrow(DownloadManager.COLUMN_STATUS))
            if (status != DownloadManager.STATUS_SUCCESSFUL) {
                Toast.makeText(this, "Не удалось загрузить обновление", Toast.LENGTH_SHORT).show()
                return
            }
        }

        val apkUri = manager.getUriForDownloadedFile(downloadId) ?: run {
            Toast.makeText(this, "Файл обновления не найден", Toast.LENGTH_SHORT).show()
            return
        }

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
        binding.tvWarning.text = if (!hasAllPermissions()) {
            "Не выданы все разрешения"
        } else {
            ""
        }
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
        private const val MAIL_PUBLIC_URL = "https://cloud.mail.ru/public/tY9v/KjTZ37U7u"
        private const val MAIL_PUBLIC_WEBLINK = "tY9v/KjTZ37U7u"
        private const val APK_FILE_NAME = "calltrack-update.apk"
    }
}
