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
    private var permissionsRequested = false

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
        updateUi(hasPermissions = host.hasAllPermissions())

        binding.btnPrimary.setOnClickListener {
            host.requestRequiredPermissions()
            permissionsRequested = true
        }

        binding.btnSecondary.setOnClickListener {
            val intent = Intent(Settings.ACTION_APPLICATION_DETAILS_SETTINGS).apply {
                data = Uri.fromParts("package", requireContext().packageName, null)
            }
            startActivity(intent)
        }

        if (!host.hasAllPermissions() && !permissionsRequested) {
            host.requestRequiredPermissions()
            permissionsRequested = true
        }
    }

    override fun onResume() {
        super.onResume()
        val host = activity as? MainActivity ?: return
        val granted = host.hasAllPermissions()
        updateUi(granted)
        if (granted) {
            host.completeOnboarding()
        }
    }

    private fun updateUi(hasPermissions: Boolean) {
        if (hasPermissions) {
            binding.tvTitle.text = "Готово"
            binding.tvDescription.text = "Разрешения получены. Переходим на экран набора."
            binding.btnPrimary.visibility = View.GONE
            binding.btnSecondary.visibility = View.GONE
        } else {
            binding.tvTitle.text = "Нужны разрешения"
            binding.tvDescription.text = "Для работы приложения выдайте необходимые разрешения."
            binding.btnPrimary.text = "Повторить запрос"
            binding.btnPrimary.visibility = View.VISIBLE
            binding.btnSecondary.text = "Открыть настройки"
            binding.btnSecondary.visibility = View.VISIBLE
        }
    }

    override fun onDestroyView() {
        _binding = null
        super.onDestroyView()
    }

    companion object {
        fun newInstance() = OnboardingFragment()
    }
}
