package com.example.calltrack.ui.main

import android.app.AlertDialog
import android.os.Bundle
import android.text.InputFilter
import android.view.LayoutInflater
import android.view.View
import android.view.ViewGroup
import android.widget.EditText
import android.widget.Toast
import androidx.fragment.app.Fragment
import androidx.fragment.app.activityViewModels
import androidx.lifecycle.lifecycleScope
import com.example.calltrack.App
import com.example.calltrack.databinding.FragmentUserBinding
import kotlinx.coroutines.launch

class UserFragment : Fragment() {

    private var _binding: FragmentUserBinding? = null
    private val binding get() = _binding!!

    private val viewModel: MainViewModel by activityViewModels {
        MainViewModel.Factory((requireActivity().application as App).repository)
    }

    override fun onCreateView(inflater: LayoutInflater, container: ViewGroup?, savedInstanceState: Bundle?): View {
        _binding = FragmentUserBinding.inflate(inflater, container, false)
        return binding.root
    }

    override fun onViewCreated(view: View, savedInstanceState: Bundle?) {
        viewModel.managerName.observe(viewLifecycleOwner) { name ->
            binding.tvCurrentUser.text = name.ifBlank { "Не указан" }
        }

        binding.btnSwitchUser.setOnClickListener {
            showSwitchUserDialog(binding.tvCurrentUser.text.toString())
        }
    }

    private fun showSwitchUserDialog(currentManager: String) {
        val input = EditText(requireContext()).apply {
            hint = "Введите ФИО"
            filters = arrayOf(InputFilter.LengthFilter(120))
            setText(if (currentManager == "Не указан") "" else currentManager)
            setSelection(text?.length ?: 0)
        }

        AlertDialog.Builder(requireContext())
            .setTitle("Сменить пользователя")
            .setView(input)
            .setPositiveButton("Сохранить") { dialog, _ ->
                val fullName = input.text?.toString()?.trim().orEmpty()
                if (fullName.isBlank()) {
                    Toast.makeText(requireContext(), "Поле ФИО обязательно", Toast.LENGTH_SHORT).show()
                } else {
                    viewLifecycleOwner.lifecycleScope.launch {
                        viewModel.setManagerName(fullName)
                        Toast.makeText(requireContext(), "Пользователь обновлён", Toast.LENGTH_SHORT).show()
                    }
                    dialog.dismiss()
                }
            }
            .setNegativeButton("Отмена", null)
            .show()
    }

    override fun onDestroyView() {
        _binding = null
        super.onDestroyView()
    }

    companion object {
        fun newInstance() = UserFragment()
    }
}
