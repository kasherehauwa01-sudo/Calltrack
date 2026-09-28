package com.example.calltrack.ui.onboarding

import android.content.Intent
import android.net.Uri
import android.os.Bundle
import android.provider.Settings
import android.view.View
import androidx.activity.result.contract.ActivityResultContracts
import androidx.appcompat.app.AppCompatActivity
import androidx.lifecycle.lifecycleScope
import com.example.calltrack.App
import com.example.calltrack.R
import com.example.calltrack.databinding.ActivityPermissionsOnboardingBinding
import com.example.calltrack.permissions.AppPermissions
import com.example.calltrack.permissions.PermissionOnboardingStore
import com.example.calltrack.ui.auth.LoginActivity
import kotlinx.coroutines.launch

class PermissionsOnboardingActivity : AppCompatActivity() {
    private lateinit var binding: ActivityPermissionsOnboardingBinding
    private lateinit var store: PermissionOnboardingStore
    private var requestHandled = false

    private val permissionLauncher = registerForActivityResult(
        ActivityResultContracts.RequestMultiplePermissions()
    ) {
        requestHandled = true
        if (AppPermissions.missingRuntimePermissions(this).isEmpty()) {
            finishOnboarding()
        } else {
            binding.tvStatus.setText(R.string.permissions_onboarding_denied)
            binding.tvStatus.visibility = View.VISIBLE
            binding.btnPrimary.setText(R.string.continue_action)
            binding.btnSettings.visibility = View.VISIBLE
        }
    }

    override fun onCreate(savedInstanceState: Bundle?) {
        super.onCreate(savedInstanceState)
        store = PermissionOnboardingStore(this)
        if (store.completed) {
            openLogin()
            return
        }

        binding = ActivityPermissionsOnboardingBinding.inflate(layoutInflater)
        setContentView(binding.root)
        binding.btnPrimary.setOnClickListener {
            if (requestHandled) {
                finishOnboarding()
            } else {
                val missing = AppPermissions.missingRuntimePermissions(this)
                if (missing.isEmpty()) finishOnboarding() else permissionLauncher.launch(missing)
            }
        }
        binding.btnSettings.setOnClickListener {
            startActivity(Intent(Settings.ACTION_APPLICATION_DETAILS_SETTINGS).apply {
                data = Uri.fromParts("package", packageName, null)
            })
        }
    }

    private fun finishOnboarding() {
        store.completed = true
        lifecycleScope.launch {
            // Keep the legacy post-login onboarding from appearing as a second
            // permission flow. Authentication storage remains completely separate.
            (application as App).repository.prefs.setOnboardingCompleted(true)
            openLogin()
        }
    }

    private fun openLogin() {
        startActivity(Intent(this, LoginActivity::class.java))
        finish()
    }
}
