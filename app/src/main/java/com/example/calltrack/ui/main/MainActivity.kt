package com.example.calltrack.ui.main

import android.Manifest
import android.content.Intent
import android.content.pm.PackageManager
import android.os.Build
import android.os.Bundle
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
import com.example.calltrack.App
import com.example.calltrack.R
import com.example.calltrack.databinding.ActivityMainBinding
import com.example.calltrack.data.repository.PrefsManager
import com.example.calltrack.service.CallTrackingService
import com.example.calltrack.ui.calls.CallListFragment
import com.example.calltrack.ui.contacts.ContactsFragment
import com.example.calltrack.ui.contactcard.ContactCardFragment
import com.example.calltrack.ui.contactcard.ContactHistoryFragment
import com.example.calltrack.ui.dialpad.DialPadFragment
import com.example.calltrack.ui.onboarding.OnboardingFragment
import kotlinx.coroutines.launch
import kotlinx.coroutines.runBlocking

class MainActivity : AppCompatActivity() {

    private lateinit var binding: ActivityMainBinding
    private lateinit var prefsManager: PrefsManager
    private val viewModel: MainViewModel by viewModels {
        MainViewModel.Factory((application as App).repository)
    }

    private val permissionsLauncher = registerForActivityResult(
        ActivityResultContracts.RequestMultiplePermissions()
    ) { updateWarningState() }

    override fun onCreate(savedInstanceState: Bundle?) {
        prefsManager = PrefsManager(this)
        applySavedTheme()
        super.onCreate(savedInstanceState)
        binding = ActivityMainBinding.inflate(layoutInflater)
        setContentView(binding.root)

        applyWindowInsets()
        setupBottomNav()
        setupSettingsButton()
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
            else -> AppCompatDelegate.MODE_NIGHT_FOLLOW_SYSTEM
        }
        AppCompatDelegate.setDefaultNightMode(nightMode)
    }

    private fun openThemeDialog() {
        val labels = arrayOf("Системная", "Светлая", "Тёмная")
        val values = arrayOf(PrefsManager.THEME_SYSTEM, PrefsManager.THEME_LIGHT, PrefsManager.THEME_DARK)
        val current = runBlocking { prefsManager.getThemeMode() }
        val checked = values.indexOf(current).takeIf { it >= 0 } ?: 0

        androidx.appcompat.app.AlertDialog.Builder(this)
            .setTitle("Тема приложения")
            .setSingleChoiceItems(labels, checked) { dialog, which ->
                val selected = values[which]
                lifecycleScope.launch {
                    prefsManager.setThemeMode(selected)
                }
                val nightMode = when (selected) {
                    PrefsManager.THEME_LIGHT -> AppCompatDelegate.MODE_NIGHT_NO
                    PrefsManager.THEME_DARK -> AppCompatDelegate.MODE_NIGHT_YES
                    else -> AppCompatDelegate.MODE_NIGHT_FOLLOW_SYSTEM
                }
                AppCompatDelegate.setDefaultNightMode(nightMode)
                dialog.dismiss()
            }
            .setNegativeButton("Отмена", null)
            .show()
    }

    private fun setupSettingsButton() {
        binding.btnSettings.setOnClickListener { anchor ->
            PopupMenu(this, anchor).apply {
                menu.add(getString(R.string.theme_app))
                menu.add(getString(R.string.update_app))
                setOnMenuItemClickListener { menuItem ->
                    when (menuItem.title) {
                        getString(R.string.theme_app) -> openThemeDialog()
                        else -> Toast.makeText(this@MainActivity, R.string.update_soon, Toast.LENGTH_SHORT).show()
                    }
                    true
                }
                show()
            }
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

    private fun requiredPermissions(): Array<String> {
        return buildList {
            add(Manifest.permission.READ_PHONE_STATE)
            add(Manifest.permission.READ_CALL_LOG)
            add(Manifest.permission.READ_CONTACTS)
            add(Manifest.permission.RECORD_AUDIO)
            add(Manifest.permission.CALL_PHONE)
            if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.TIRAMISU) add(Manifest.permission.POST_NOTIFICATIONS)
        }.toTypedArray()
    }

    companion object {
        const val EXTRA_OPEN_CONTACT_PHONE = "extra_open_contact_phone"
    }
}
