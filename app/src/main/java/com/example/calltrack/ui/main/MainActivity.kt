package com.example.calltrack.ui.main

import android.Manifest
import android.app.role.RoleManager
import android.content.Intent
import android.content.pm.PackageManager
import android.os.Build
import android.os.Bundle
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
import com.example.calltrack.ui.contacts.ContactsFragment
import com.example.calltrack.ui.dialpad.DialPadFragment
import com.example.calltrack.ui.onboarding.OnboardingFragment
import kotlinx.coroutines.launch

class MainActivity : AppCompatActivity() {

    private lateinit var binding: ActivityMainBinding
    private val viewModel: MainViewModel by viewModels {
        MainViewModel.Factory((application as App).repository)
    }

    private val roleRequestLauncher =
        registerForActivityResult(ActivityResultContracts.StartActivityForResult()) {
            updateWarningState()
        }

    private val permissionsLauncher = registerForActivityResult(
        ActivityResultContracts.RequestMultiplePermissions()
    ) { updateWarningState() }

    override fun onCreate(savedInstanceState: Bundle?) {
        super.onCreate(savedInstanceState)
        binding = ActivityMainBinding.inflate(layoutInflater)
        setContentView(binding.root)

        setupBottomNav()

        viewModel.onboardingCompleted.observe(this) { completed ->
            if (!completed) {
                openFragment(OnboardingFragment.newInstance())
                binding.bottomNav.visibility = android.view.View.GONE
            } else {
                binding.bottomNav.visibility = android.view.View.VISIBLE
                if (savedInstanceState == null) {
                    binding.bottomNav.selectedItemId = R.id.nav_dial
                }
                startTrackingService()
            }
            updateWarningState()
        }
    }

    private fun setupBottomNav() {
        binding.bottomNav.setOnItemSelectedListener { item ->
            when (item.itemId) {
                R.id.nav_dial -> openFragment(DialPadFragment.newInstance())
                R.id.nav_recent -> openFragment(CallListFragment.newInstance())
                R.id.nav_contacts -> openFragment(ContactsFragment.newInstance())
                else -> return@setOnItemSelectedListener false
            }
            true
        }
    }

    fun requestRequiredPermissions() {
        permissionsLauncher.launch(requiredPermissions())
    }

    fun requestDefaultDialerRole() {
        val roleManager = getSystemService(RoleManager::class.java)
        if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.Q && !roleManager.isRoleHeld(RoleManager.ROLE_DIALER)) {
            roleRequestLauncher.launch(roleManager.createRequestRoleIntent(RoleManager.ROLE_DIALER))
        }
    }

    fun completeOnboarding() {
        lifecycleScope.launch {
            viewModel.markOnboardingCompleted()
        }
    }

    fun hasAllPermissions(): Boolean = requiredPermissions().all {
        ContextCompat.checkSelfPermission(this, it) == PackageManager.PERMISSION_GRANTED
    }

    fun isDefaultDialer(): Boolean {
        val roleManager = getSystemService(RoleManager::class.java)
        return if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.Q) {
            roleManager.isRoleHeld(RoleManager.ROLE_DIALER)
        } else {
            true
        }
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
        val warning = mutableListOf<String>()
        if (!hasAllPermissions()) warning += "Не выданы все разрешения"
        if (!isDefaultDialer()) warning += "Приложение не назначено как звонилка по умолчанию"
        binding.tvWarning.text = warning.joinToString("\n")
    }

    private fun startTrackingService() {
        ContextCompat.startForegroundService(this, Intent(this, CallTrackingService::class.java))
        lifecycleScope.launch { viewModel.sync() }
    }

    private fun requiredPermissions(): Array<String> {
        return buildList {
            add(Manifest.permission.READ_PHONE_STATE)
            add(Manifest.permission.READ_CALL_LOG)
            add(Manifest.permission.CALL_PHONE)
            add(Manifest.permission.RECORD_AUDIO)
            if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.TIRAMISU) add(Manifest.permission.POST_NOTIFICATIONS)
        }.toTypedArray()
    }
}
