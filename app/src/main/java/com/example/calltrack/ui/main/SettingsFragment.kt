package com.example.calltrack.ui.main

import android.os.Bundle
import android.view.LayoutInflater
import android.view.View
import android.view.ViewGroup
import androidx.appcompat.app.AlertDialog
import androidx.appcompat.app.AppCompatDelegate
import androidx.fragment.app.Fragment
import androidx.fragment.app.activityViewModels
import androidx.lifecycle.lifecycleScope
import com.example.calltrack.App
import com.example.calltrack.data.repository.PrefsManager
import com.example.calltrack.databinding.FragmentSettingsBinding
import kotlinx.coroutines.launch

class SettingsFragment : Fragment() {

    private var _binding: FragmentSettingsBinding? = null
    private val binding get() = _binding!!

    private val viewModel: MainViewModel by activityViewModels {
        MainViewModel.Factory((requireActivity().application as App).repository)
    }

    override fun onCreateView(inflater: LayoutInflater, container: ViewGroup?, savedInstanceState: Bundle?): View {
        _binding = FragmentSettingsBinding.inflate(inflater, container, false)
        return binding.root
    }

    override fun onViewCreated(view: View, savedInstanceState: Bundle?) {
        binding.btnBack.setOnClickListener {
            requireActivity().onBackPressedDispatcher.onBackPressed()
        }
        viewModel.themeMode.observe(viewLifecycleOwner) { mode ->
            binding.tvThemeMode.text = themeTitle(mode)
        }

        binding.btnThemeMode.setOnClickListener {
            showThemeDialog()
        }
    }

    private fun showThemeDialog() {
        val modes = arrayOf(PrefsManager.THEME_LIGHT, PrefsManager.THEME_DARK, PrefsManager.THEME_SYSTEM)
        val titles = modes.map(::themeTitle).toTypedArray()
        val current = viewModel.themeMode.value ?: PrefsManager.THEME_SYSTEM
        val checked = modes.indexOf(current).coerceAtLeast(0)
        AlertDialog.Builder(requireContext())
            .setTitle("Тема")
            .setSingleChoiceItems(titles, checked) { dialog, which ->
                val mode = modes[which]
                viewLifecycleOwner.lifecycleScope.launch {
                    viewModel.setThemeMode(mode)
                    AppCompatDelegate.setDefaultNightMode(themeNightMode(mode))
                }
                dialog.dismiss()
            }
            .setNegativeButton("Отмена", null)
            .show()
    }

    private fun themeTitle(mode: String): String = when (mode) {
        PrefsManager.THEME_LIGHT -> "Светлая"
        PrefsManager.THEME_DARK -> "Темная"
        else -> "Как в системе"
    }

    private fun themeNightMode(mode: String): Int = when (mode) {
        PrefsManager.THEME_LIGHT -> AppCompatDelegate.MODE_NIGHT_NO
        PrefsManager.THEME_DARK -> AppCompatDelegate.MODE_NIGHT_YES
        else -> AppCompatDelegate.MODE_NIGHT_FOLLOW_SYSTEM
    }

    override fun onDestroyView() {
        _binding = null
        super.onDestroyView()
    }

    companion object {
        fun newInstance() = SettingsFragment()
    }
}
