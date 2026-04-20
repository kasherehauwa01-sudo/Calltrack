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
    private var step = 1

    override fun onCreateView(
        inflater: LayoutInflater,
        container: ViewGroup?,
        savedInstanceState: Bundle?
    ): View {
        _binding = FragmentOnboardingBinding.inflate(inflater, container, false)
        return binding.root
    }

    override fun onViewCreated(view: View, savedInstanceState: Bundle?) {
        updateUi()
        binding.btnPrimary.setOnClickListener { onPrimaryClick() }
        binding.btnSecondary.setOnClickListener {
            val intent = Intent(Settings.ACTION_APPLICATION_DETAILS_SETTINGS).apply {
                data = Uri.fromParts("package", requireContext().packageName, null)
            }
            startActivity(intent)
        }
    }


    override fun onResume() {
        super.onResume()
        val host = activity as? MainActivity ?: return
        if (step == 2 && host.hasAllPermissions()) {
            step = 3
            host.completeOnboarding()
            updateUi()
        }
    }

    private fun onPrimaryClick() {
        val host = requireActivity() as MainActivity
        when (step) {
            1 -> step = 2
            2 -> {
                host.requestRequiredPermissions()
                if (host.hasAllPermissions()) {
                    step = 3
                    host.completeOnboarding()
                }
            }
            3 -> host.completeOnboarding()
        }
        updateUi()
    }

    private fun updateUi() {
        when (step) {
            1 -> {
                binding.tvTitle.text = "Добро пожаловать"
                binding.tvDescription.text = "Приложение отслеживает звонки и отправляет аналитику. Для старта выдайте разрешения."
                binding.btnPrimary.text = "Начать настройку"
                binding.btnSecondary.visibility = View.GONE
            }
            2 -> {
                binding.tvTitle.text = "Разрешения"
                binding.tvDescription.text = "Выдайте все разрешения. Если выбрано 'Не спрашивать', откройте настройки приложения."
                binding.btnPrimary.text = "Запросить разрешения"
                binding.btnSecondary.visibility = View.VISIBLE
                binding.btnSecondary.text = "Открыть настройки"
            }
            3 -> {
                binding.tvTitle.text = "Готово"
                binding.tvDescription.text = "Разрешения получены. Переходим на основной экран с клавиатурой."
                binding.btnPrimary.text = "Открыть клавиатуру"
                binding.btnSecondary.visibility = View.GONE
            }
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
