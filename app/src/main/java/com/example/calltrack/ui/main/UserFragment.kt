package com.example.calltrack.ui.main

import android.os.Bundle
import android.text.InputFilter
import android.view.Gravity
import android.view.LayoutInflater
import android.view.View
import android.view.ViewGroup
import android.widget.EditText
import android.widget.ImageButton
import android.widget.LinearLayout
import android.widget.Toast
import androidx.appcompat.app.AlertDialog
import androidx.core.content.ContextCompat
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
        binding.btnBack.setOnClickListener {
            requireActivity().onBackPressedDispatcher.onBackPressed()
        }
        viewModel.managerName.observe(viewLifecycleOwner) { name ->
            binding.tvCurrentUser.text = name.ifBlank { "Не указан" }
        }
        viewModel.managerPhone.observe(viewLifecycleOwner) { phone ->
            binding.tvCurrentUserPhone.text = phone.ifBlank { "Не указан" }
        }


        binding.btnSwitchUser.setOnClickListener {
            showSwitchUserDialog(
                currentManager = binding.tvCurrentUser.text.toString(),
                currentPhone = binding.tvCurrentUserPhone.text.toString()
            )
        }
    }

    private fun showSwitchUserDialog(currentManager: String, currentPhone: String) {
        val inputName = EditText(requireContext()).apply {
            hint = "Введите ФИО"
            filters = arrayOf(InputFilter.LengthFilter(120))
            setText(if (currentManager == "Не указан") "" else currentManager)
            setSelection(text?.length ?: 0)
        }
        val inputPhone = EditText(requireContext()).apply {
            hint = "Номер телефона"
            inputType = android.text.InputType.TYPE_CLASS_PHONE
            filters = arrayOf(InputFilter.LengthFilter(30))
            setText(if (currentPhone == "Не указан") "+7" else currentPhone)
            setSelection(text?.length ?: 0)
        }
        val nameRow = buildInputRow(inputName) { inputName.setText("") }
        val phoneRow = buildInputRow(inputPhone) {
            inputPhone.setText("+7")
            inputPhone.setSelection(inputPhone.text?.length ?: 0)
        }
        val container = LinearLayout(requireContext()).apply {
            orientation = LinearLayout.VERTICAL
            setPadding(36, 20, 36, 0)
            addView(nameRow)
            addView(phoneRow)
        }

        AlertDialog.Builder(requireContext())
            .setTitle("Сменить пользователя")
            .setView(container)
            .setPositiveButton("Сохранить") { dialog, _ ->
                val fullName = inputName.text?.toString()?.trim().orEmpty()
                val phone = inputPhone.text?.toString()?.trim().orEmpty()
                if (fullName.isBlank()) {
                    Toast.makeText(requireContext(), "Поле ФИО обязательно", Toast.LENGTH_SHORT).show()
                } else if (phone.isBlank()) {
                    Toast.makeText(requireContext(), "Поле Номер телефона обязательно", Toast.LENGTH_SHORT).show()
                } else {
                    viewLifecycleOwner.lifecycleScope.launch {
                        viewModel.setManagerName(fullName)
                        viewModel.setManagerPhone(phone)
                        Toast.makeText(requireContext(), "Пользователь обновлён", Toast.LENGTH_SHORT).show()
                    }
                    dialog.dismiss()
                }
            }
            .setNegativeButton("Отмена", null)
            .show()
    }

    private fun buildInputRow(editText: EditText, onClear: () -> Unit): LinearLayout {
        val clear = ImageButton(requireContext()).apply {
            setImageResource(android.R.drawable.ic_menu_close_clear_cancel)
            background = null
            contentDescription = "Очистить"
            setOnClickListener { onClear() }
            setColorFilter(ContextCompat.getColor(requireContext(), android.R.color.darker_gray))
        }

        return LinearLayout(requireContext()).apply {
            orientation = LinearLayout.HORIZONTAL
            gravity = Gravity.CENTER_VERTICAL
            addView(editText, LinearLayout.LayoutParams(0, LinearLayout.LayoutParams.WRAP_CONTENT, 1f))
            addView(clear)
        }
    }

    override fun onDestroyView() {
        _binding = null
        super.onDestroyView()
    }

    companion object {
        fun newInstance() = UserFragment()
    }
}
