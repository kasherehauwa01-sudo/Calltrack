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
import androidx.core.content.ContextCompat
import androidx.lifecycle.lifecycleScope
import com.example.calltrack.App
import com.example.calltrack.R
import com.example.calltrack.databinding.ActivityMainBinding
import com.example.calltrack.service.CallTrackingService
import com.example.calltrack.ui.calls.CallListFragment
import com.example.calltrack.ui.dialpad.DialPadFragment
import com.example.calltrack.ui.onboarding.OnboardingFragment
import kotlinx.coroutines.launch

class MainActivity : AppCompatActivity() {

    private lateinit var binding: ActivityMainBinding
    private val viewModel: MainViewModel by viewModels {
        MainViewModel.Factory((application as App).repository)
    }

    private val permissionsLauncher = registerForActivityResult(
        ActivityResultContracts.RequestMultiplePermissions()
    ) { updateWarningState() }

    override fun onCreate(savedInstanceState: Bundle?) {
        super.onCreate(savedInstanceState)
        binding = ActivityMainBinding.inflate(layoutInflater)
        setContentView(binding.root)

        setupBottomNav()
        setupSettingsButton()

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


    private fun setupSettingsButton() {
        binding.btnSettings.setOnClickListener { anchor ->
            PopupMenu(this, anchor).apply {
                menu.add(getString(R.string.update_app))
                setOnMenuItemClickListener {
                    Toast.makeText(this@MainActivity, R.string.update_soon, Toast.LENGTH_SHORT).show()
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
                    val contactsIntent = Intent(Intent.ACTION_VIEW).apply {
                        type = android.provider.ContactsContract.Contacts.CONTENT_TYPE
                    }
                    runCatching { startActivity(contactsIntent) }
                    false
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
        // На Android 14+ запуск FGS может быть отклонён системой, если момент запуска невалидный.
        // Чтобы не уронить приложение, оборачиваем запуск в runCatching.
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
            if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.TIRAMISU) add(Manifest.permission.POST_NOTIFICATIONS)
        }.toTypedArray()
    }
}
