package com.example.calltrack.ui.onboarding

import android.content.Intent
import android.net.Uri
import android.os.Bundle
import android.provider.Settings
import android.view.LayoutInflater
import android.view.View
import android.view.ViewGroup
import android.widget.Toast
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
        stage = nextStage(host)
        updateUi()

        binding.btnPrimary.setOnClickListener {
            when (stage) {
                Stage.PERMISSIONS -> {
                    host.requestRequiredPermissions()
                    permissionsRequested = true
                }
                Stage.BATTERY -> host.requestBatteryOptimizationIfNeeded(force = true)
                Stage.AUTH -> submitManagerName()
            }
        }

        binding.btnSecondary.setOnClickListener {
            if (stage == Stage.BATTERY) {
                batteryOptimizationSkipped = true
                stage = Stage.AUTH
                updateUi()
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
            stage = nextStage(host)
            updateUi()
        } else if (stage == Stage.BATTERY && host.isBatteryOptimizationDisabled()) {
            stage = Stage.AUTH
            updateUi()
        }
    }

    private fun submitManagerName() {
        val host = requireActivity() as MainActivity
        val fullName = binding.etManager.text.toString().trim()
        val phone = binding.etManagerPhone.text.toString().trim()
        if (fullName.isBlank()) {
            Toast.makeText(requireContext(), "\u041F\u043E\u043B\u0435 \u0424\u0418\u041E \u043E\u0431\u044F\u0437\u0430\u0442\u0435\u043B\u044C\u043D\u043E", Toast.LENGTH_SHORT).show()
            return
        }
        if (phone.isBlank()) {
            Toast.makeText(requireContext(), "\u041F\u043E\u043B\u0435 \u041D\u043E\u043C\u0435\u0440 \u0442\u0435\u043B\u0435\u0444\u043E\u043D\u0430 \u043E\u0431\u044F\u0437\u0430\u0442\u0435\u043B\u044C\u043D\u043E", Toast.LENGTH_SHORT).show()
            return
        }
        host.completeOnboarding(fullName, phone)
    }

    private fun updateUi() {
        when (stage) {
            Stage.PERMISSIONS -> {
                binding.tvTitle.text = "\u041D\u0443\u0436\u043D\u044B \u0440\u0430\u0437\u0440\u0435\u0448\u0435\u043D\u0438\u044F"
                binding.tvDescription.text = "\u0414\u043B\u044F \u0440\u0430\u0431\u043E\u0442\u044B \u043F\u0440\u0438\u043B\u043E\u0436\u0435\u043D\u0438\u044F \u0432\u044B\u0434\u0430\u0439\u0442\u0435 \u043D\u0435\u043E\u0431\u0445\u043E\u0434\u0438\u043C\u044B\u0435 \u0440\u0430\u0437\u0440\u0435\u0448\u0435\u043D\u0438\u044F."
                binding.etManager.visibility = View.GONE
                binding.etManagerPhone.visibility = View.GONE
                binding.btnPrimary.text = "\u041F\u043E\u0432\u0442\u043E\u0440\u0438\u0442\u044C \u0437\u0430\u043F\u0440\u043E\u0441"
                binding.btnPrimary.visibility = View.VISIBLE
                binding.btnSecondary.text = "\u041E\u0442\u043A\u0440\u044B\u0442\u044C \u043D\u0430\u0441\u0442\u0440\u043E\u0439\u043A\u0438"
                binding.btnSecondary.visibility = View.VISIBLE
            }
            Stage.AUTH -> {
                binding.tvTitle.text = "\u0410\u0432\u0442\u043E\u0440\u0438\u0437\u0430\u0446\u0438\u044F"
                binding.tvDescription.text = "\u0412\u0432\u0435\u0434\u0438\u0442\u0435 \u0424\u0418\u041E \u0438 \u043D\u043E\u043C\u0435\u0440 \u0442\u0435\u043B\u0435\u0444\u043E\u043D\u0430. \u041E\u0431\u0430 \u043F\u043E\u043B\u044F \u043E\u0431\u044F\u0437\u0430\u0442\u0435\u043B\u044C\u043D\u044B."
                binding.etManager.visibility = View.VISIBLE
                binding.etManagerPhone.visibility = View.VISIBLE
                if (binding.etManagerPhone.text.isNullOrBlank()) {
                    binding.etManagerPhone.setText("+7")
                    binding.etManagerPhone.setSelection(binding.etManagerPhone.text?.length ?: 0)
                }
                binding.btnPrimary.text = "\u041E\u043A"
                binding.btnPrimary.visibility = View.VISIBLE
                binding.btnSecondary.visibility = View.GONE
            }
            Stage.BATTERY -> {
                binding.tvTitle.text = "\u0420\u0430\u0431\u043E\u0442\u0430 \u0432 \u0444\u043E\u043D\u0435"
                binding.tvDescription.text = "\u0420\u0430\u0437\u0440\u0435\u0448\u0438\u0442\u0435 CallTrack \u0440\u0430\u0431\u043E\u0442\u0430\u0442\u044C \u0431\u0435\u0437 \u043E\u0433\u0440\u0430\u043D\u0438\u0447\u0435\u043D\u0438\u044F \u0431\u0430\u0442\u0430\u0440\u0435\u0438, \u0447\u0442\u043E\u0431\u044B \u0437\u0432\u043E\u043D\u043A\u0438 \u043F\u0440\u043E\u0434\u043E\u043B\u0436\u0430\u043B\u0438 \u0444\u0438\u043A\u0441\u0438\u0440\u043E\u0432\u0430\u0442\u044C\u0441\u044F \u0438 \u043E\u0442\u043F\u0440\u0430\u0432\u043B\u044F\u0442\u044C\u0441\u044F \u043D\u0430 \u0434\u0430\u0448\u0431\u043E\u0440\u0434."
                binding.etManager.visibility = View.GONE
                binding.etManagerPhone.visibility = View.GONE
                binding.btnPrimary.text = "\u0420\u0430\u0437\u0440\u0435\u0448\u0438\u0442\u044C"
                binding.btnPrimary.visibility = View.VISIBLE
                binding.btnSecondary.text = "\u041F\u0440\u043E\u0434\u043E\u043B\u0436\u0438\u0442\u044C \u0431\u0435\u0437 \u0440\u0430\u0437\u0440\u0435\u0448\u0435\u043D\u0438\u044F"
                binding.btnSecondary.visibility = View.VISIBLE
            }
        }
    }

    private fun nextStage(host: MainActivity): Stage = when {
        !host.hasAllPermissions() -> Stage.PERMISSIONS
        !batteryOptimizationSkipped && !host.isBatteryOptimizationDisabled() -> Stage.BATTERY
        else -> Stage.AUTH
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
        AUTH
    }
}
