package com.example.calltrack.ui.main

import android.Manifest
import android.content.Context
import android.content.Intent
import android.content.pm.PackageManager
import android.net.Uri
import android.os.Build
import android.os.Bundle
import android.os.PowerManager
import android.provider.Settings
import android.view.LayoutInflater
import android.view.View
import android.view.ViewGroup
import androidx.activity.result.contract.ActivityResultContracts
import androidx.annotation.StringRes
import androidx.core.content.ContextCompat
import androidx.fragment.app.Fragment
import com.example.calltrack.R
import com.example.calltrack.databinding.FragmentPermissionsBinding
import com.example.calltrack.databinding.ItemPermissionBinding
import com.example.calltrack.service.CalltrackRecoveryManager
import com.example.calltrack.service.RecoveryReason

class PermissionsFragment : Fragment() {

    private var _binding: FragmentPermissionsBinding? = null
    private val binding get() = _binding!!
    private var pendingRuntimePermission: String? = null
    private val permanentlyDeniedPermissions = mutableSetOf<String>()

    private val runtimePermissionLauncher = registerForActivityResult(ActivityResultContracts.RequestPermission()) { granted ->
        val permission = pendingRuntimePermission
        pendingRuntimePermission = null
        if (!granted && permission != null && !shouldShowRequestPermissionRationale(permission)) {
            permanentlyDeniedPermissions += permission
        }
        refreshPermissions()
    }

    private val settingsLauncher = registerForActivityResult(ActivityResultContracts.StartActivityForResult()) {
        refreshPermissions()
    }

    override fun onCreateView(inflater: LayoutInflater, container: ViewGroup?, savedInstanceState: Bundle?): View {
        _binding = FragmentPermissionsBinding.inflate(inflater, container, false)
        return binding.root
    }

    override fun onViewCreated(view: View, savedInstanceState: Bundle?) {
        binding.btnBack.setOnClickListener { requireActivity().onBackPressedDispatcher.onBackPressed() }
        refreshPermissions()
    }

    override fun onResume() {
        super.onResume()
        if (_binding != null) {
            refreshPermissions()
            CalltrackRecoveryManager.recover(requireContext(), RecoveryReason.APP_START)
        }
    }

    private fun refreshPermissions() {
        val container = binding.permissionsContainer
        container.removeAllViews()
        permissionItems().forEach { item ->
            val row = ItemPermissionBinding.inflate(layoutInflater, container, false)
            row.tvPermissionTitle.setText(item.title)
            row.tvPermissionDescription.setText(item.description)
            val granted = item.isGranted()
            row.tvPermissionStatus.setText(if (granted) R.string.permission_granted else R.string.permission_not_granted)
            row.tvPermissionStatus.setTextColor(ContextCompat.getColor(requireContext(), if (granted) R.color.successColor else R.color.errorColor))
            row.btnGrant.visibility = if (granted) View.GONE else View.VISIBLE
            row.btnGrant.setOnClickListener { item.grant() }
            container.addView(row.root)
        }
    }

    private fun permissionItems(): List<PermissionItem> = buildList {
        addRuntimePermission(R.string.permission_phone_state, R.string.permission_phone_state_description, Manifest.permission.READ_PHONE_STATE)
        addRuntimePermission(R.string.permission_call_log, R.string.permission_call_log_description, Manifest.permission.READ_CALL_LOG)
        addRuntimePermission(R.string.permission_contacts, R.string.permission_contacts_description, Manifest.permission.READ_CONTACTS)
        addRuntimePermission(R.string.permission_phone_calls, R.string.permission_phone_calls_description, Manifest.permission.CALL_PHONE)
        if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.TIRAMISU) {
            addRuntimePermission(R.string.permission_notifications, R.string.permission_notifications_description, Manifest.permission.POST_NOTIFICATIONS)
        }
        add(PermissionItem(R.string.permission_battery, R.string.permission_battery_description, ::isBatteryOptimizationDisabled, ::requestBatteryOptimizationExclusion))
        if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.O) {
            add(PermissionItem(R.string.permission_install_packages, R.string.permission_install_packages_description, { requireContext().packageManager.canRequestPackageInstalls() }) {
                openSettings(Intent(Settings.ACTION_MANAGE_UNKNOWN_APP_SOURCES, packageUri()))
            })
        }
    }

    private fun MutableList<PermissionItem>.addRuntimePermission(@StringRes title: Int, @StringRes description: Int, permission: String) {
        add(PermissionItem(title, description, { ContextCompat.checkSelfPermission(requireContext(), permission) == PackageManager.PERMISSION_GRANTED }) {
            if (permission in permanentlyDeniedPermissions) {
                openApplicationSettings()
            } else {
                pendingRuntimePermission = permission
                runtimePermissionLauncher.launch(permission)
            }
        })
    }

    private fun isBatteryOptimizationDisabled(): Boolean {
        val powerManager = requireContext().getSystemService(PowerManager::class.java)
        return powerManager.isIgnoringBatteryOptimizations(requireContext().packageName)
    }

    private fun requestBatteryOptimizationExclusion() {
        openSettings(
            Intent(Settings.ACTION_REQUEST_IGNORE_BATTERY_OPTIMIZATIONS, packageUri()),
            Intent(Settings.ACTION_IGNORE_BATTERY_OPTIMIZATION_SETTINGS)
        )
    }

    private fun openApplicationSettings() {
        openSettings(Intent(Settings.ACTION_APPLICATION_DETAILS_SETTINGS, packageUri()))
    }

    private fun openSettings(primary: Intent, fallback: Intent? = null) {
        runCatching { settingsLauncher.launch(primary) }
            .onFailure { if (fallback != null) runCatching { settingsLauncher.launch(fallback) } }
    }

    private fun packageUri(): Uri = Uri.parse("package:${requireContext().packageName}")

    override fun onDestroyView() {
        _binding = null
        super.onDestroyView()
    }

    private data class PermissionItem(
        @StringRes val title: Int,
        @StringRes val description: Int,
        val isGranted: () -> Boolean,
        val grant: () -> Unit
    )

    companion object {
        fun newInstance() = PermissionsFragment()
    }
}
