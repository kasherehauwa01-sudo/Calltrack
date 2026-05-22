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
        stage = if (host.hasAllPermissions()) Stage.AUTH else Stage.PERMISSIONS
        updateUi()

        binding.btnPrimary.setOnClickListener {
            when (stage) {
                Stage.PERMISSIONS -> {
                    host.requestRequiredPermissions()
                    permissionsRequested = true
                }
                Stage.AUTH -> submitManagerName()
            }
        }

        binding.btnSecondary.setOnClickListener {
            val intent = Intent(Settings.ACTION_APPLICATION_DETAILS_SETTINGS).apply {
                data = Uri.fromParts("package", requireContext().packageName, null)
            }
            startActivity(intent)
        }

        if (stage == Stage.PERMISSIONS && !permissionsRequested) {
            host.requestRequiredPermissions()
            permissionsRequested = true
        }
    }

    override fun onResume() {
        super.onResume()
        val host = activity as? MainActivity ?: return
        if (stage == Stage.PERMISSIONS && host.hasAllPermissions()) {
            stage = Stage.AUTH
            updateUi()
        }
    }

    private fun submitManagerName() {
        val host = requireActivity() as MainActivity
        val fullName = binding.etManager.text.toString().trim()
        val phone = binding.etManagerPhone.text.toString().trim()
        if (fullName.isBlank()) {
            Toast.makeText(requireContext(), "Поле ФИО обязательно", Toast.LENGTH_SHORT).show()
            return
        }
        if (phone.isBlank()) {
            Toast.makeText(requireContext(), "Поле Номер телефона обязательно", Toast.LENGTH_SHORT).show()
            return
        }
        host.completeOnboarding(fullName, phone)
    }

    private fun updateUi() {
        when (stage) {
            Stage.PERMISSIONS -> {
                binding.tvTitle.text = "Нужны разрешения"
                binding.tvDescription.text = "Для работы приложения выдайте необходимые разрешения."
                binding.etManager.visibility = View.GONE
                binding.etManagerPhone.visibility = View.GONE
                binding.btnPrimary.text = "Повторить запрос"
                binding.btnPrimary.visibility = View.VISIBLE
                binding.btnSecondary.text = "Открыть настройки"
                binding.btnSecondary.visibility = View.VISIBLE
            }
            Stage.AUTH -> {
                binding.tvTitle.text = "Авторизация"
                binding.tvDescription.text = "Введите ФИО и номер телефона. Оба поля обязательны."
                binding.etManager.visibility = View.VISIBLE
                binding.etManagerPhone.visibility = View.VISIBLE
                binding.btnPrimary.text = "Ок"
                binding.btnPrimary.visibility = View.VISIBLE
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

    private enum class Stage {
        PERMISSIONS,
        AUTH
    }
}
