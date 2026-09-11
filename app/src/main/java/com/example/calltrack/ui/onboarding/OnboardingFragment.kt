package com.example.calltrack.ui.onboarding

import android.content.Intent
import android.net.Uri
import android.os.Bundle
import android.provider.Settings
import android.view.LayoutInflater
import android.view.View
import android.view.ViewGroup
import androidx.fragment.app.Fragment
import com.example.calltrack.databinding.FragmentOnboardingBinding
import com.example.calltrack.ui.main.MainActivity

class OnboardingFragment : Fragment() {

    private var _binding: FragmentOnboardingBinding? = null
    private val binding get() = _binding!!
    private var stage: Stage = Stage.PERMISSIONS
    private var permissionsRequested = false
    private var batteryOptimizationSkipped = false

    override fun onCreateView(
        inflater: LayoutInflater,
        container: ViewGroup?,
        savedInstanceState: Bundle?
    ): View {
        _binding = FragmentOnboardingBinding.inflate(inflater, container, false)
        return binding.root
    }

    override fun onViewCreated(view: View, savedInstanceState: Bundle?) {
        val host = requireActivity() as MainActivity
        moveToNextStage(host)

        binding.btnPrimary.setOnClickListener {
            when (stage) {
                Stage.PERMISSIONS -> {
                    host.requestRequiredPermissions()
                    permissionsRequested = true
                }
                Stage.BATTERY -> host.requestBatteryOptimizationIfNeeded(force = true)
                Stage.COMPLETE -> host.completeOnboarding()
            }
        }

        binding.btnSecondary.setOnClickListener {
            if (stage == Stage.BATTERY) {
                batteryOptimizationSkipped = true
                moveToNextStage(host)
            } else {
                val intent = Intent(Settings.ACTION_APPLICATION_DETAILS_SETTINGS).apply {
                    data = Uri.fromParts("package", requireContext().packageName, null)
                }
                startActivity(intent)
            }
        }

        if (stage == Stage.PERMISSIONS && !permissionsRequested) {
            host.requestRequiredPermissions()
            permissionsRequested = true
        }
    }

    override fun onResume() {
        super.onResume()
        onPermissionsUpdated()
    }

    fun onPermissionsUpdated() {
        val host = activity as? MainActivity ?: return
        if (stage == Stage.PERMISSIONS && host.hasAllPermissions()) {
            moveToNextStage(host)
        } else if (stage == Stage.BATTERY && host.isBatteryOptimizationDisabled()) {
            moveToNextStage(host)
        }
    }

    private fun moveToNextStage(host: MainActivity) {
        stage = nextStage(host)
        if (stage == Stage.COMPLETE) host.completeOnboarding() else updateUi()
    }

    private fun updateUi() {
        when (stage) {
            Stage.PERMISSIONS -> {
                binding.tvTitle.text = "\u041D\u0443\u0436\u043D\u044B \u0440\u0430\u0437\u0440\u0435\u0448\u0435\u043D\u0438\u044F"
                binding.tvDescription.text = "\u0414\u043B\u044F \u0440\u0430\u0431\u043E\u0442\u044B \u043F\u0440\u0438\u043B\u043E\u0436\u0435\u043D\u0438\u044F \u0432\u044B\u0434\u0430\u0439\u0442\u0435 \u043D\u0435\u043E\u0431\u0445\u043E\u0434\u0438\u043C\u044B\u0435 \u0440\u0430\u0437\u0440\u0435\u0448\u0435\u043D\u0438\u044F."
                binding.btnPrimary.text = "\u041F\u043E\u0432\u0442\u043E\u0440\u0438\u0442\u044C \u0437\u0430\u043F\u0440\u043E\u0441"
                binding.btnPrimary.visibility = View.VISIBLE
                binding.btnSecondary.text = "\u041E\u0442\u043A\u0440\u044B\u0442\u044C \u043D\u0430\u0441\u0442\u0440\u043E\u0439\u043A\u0438"
                binding.btnSecondary.visibility = View.VISIBLE
            }
            Stage.BATTERY -> {
                binding.tvTitle.text = "\u0420\u0430\u0431\u043E\u0442\u0430 \u0432 \u0444\u043E\u043D\u0435"
                binding.tvDescription.text = "\u0420\u0430\u0437\u0440\u0435\u0448\u0438\u0442\u0435 CallTrack \u0440\u0430\u0431\u043E\u0442\u0430\u0442\u044C \u0431\u0435\u0437 \u043E\u0433\u0440\u0430\u043D\u0438\u0447\u0435\u043D\u0438\u044F \u0431\u0430\u0442\u0430\u0440\u0435\u0438, \u0447\u0442\u043E\u0431\u044B \u0437\u0432\u043E\u043D\u043A\u0438 \u043F\u0440\u043E\u0434\u043E\u043B\u0436\u0430\u043B\u0438 \u0444\u0438\u043A\u0441\u0438\u0440\u043E\u0432\u0430\u0442\u044C\u0441\u044F \u0438 \u043E\u0442\u043F\u0440\u0430\u0432\u043B\u044F\u0442\u044C\u0441\u044F \u043D\u0430 \u0434\u0430\u0448\u0431\u043E\u0440\u0434."
                binding.btnPrimary.text = "\u0420\u0430\u0437\u0440\u0435\u0448\u0438\u0442\u044C"
                binding.btnPrimary.visibility = View.VISIBLE
                binding.btnSecondary.text = "\u041F\u0440\u043E\u0434\u043E\u043B\u0436\u0438\u0442\u044C \u0431\u0435\u0437 \u0440\u0430\u0437\u0440\u0435\u0448\u0435\u043D\u0438\u044F"
                binding.btnSecondary.visibility = View.VISIBLE
            }
            Stage.COMPLETE -> Unit
        }
    }

    private fun nextStage(host: MainActivity): Stage = when {
        !host.hasAllPermissions() -> Stage.PERMISSIONS
        !batteryOptimizationSkipped && !host.isBatteryOptimizationDisabled() -> Stage.BATTERY
        else -> Stage.COMPLETE
    }

    override fun onDestroyView() {
        _binding = null
        super.onDestroyView()
    }

    companion object {
        fun newInstance() = OnboardingFragment()
    }

    private enum class Stage {
        PERMISSIONS,
        BATTERY,
        COMPLETE
    }
}
