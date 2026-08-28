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
            (requireActivity() as MainActivity).openDialScreen()
        }
        viewModel.managerName.observe(viewLifecycleOwner) { name ->
            binding.tvCurrentUser.text = name.ifBlank { "\u041D\u0435 \u0443\u043A\u0430\u0437\u0430\u043D" }
        }
        viewModel.managerPhone.observe(viewLifecycleOwner) { phone ->
            binding.tvCurrentUserPhone.text = phone.ifBlank { "\u041D\u0435 \u0443\u043A\u0430\u0437\u0430\u043D" }
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
            hint = "\u0412\u0432\u0435\u0434\u0438\u0442\u0435 \u0424\u0418\u041E"
            filters = arrayOf(InputFilter.LengthFilter(120))
            setText(if (currentManager == "\u041D\u0435 \u0443\u043A\u0430\u0437\u0430\u043D") "" else currentManager)
            setSelection(text?.length ?: 0)
        }
        val inputPhone = EditText(requireContext()).apply {
            hint = "\u041D\u043E\u043C\u0435\u0440 \u0442\u0435\u043B\u0435\u0444\u043E\u043D\u0430"
            inputType = android.text.InputType.TYPE_CLASS_PHONE
            filters = arrayOf(InputFilter.LengthFilter(30))
            setText(if (currentPhone == "\u041D\u0435 \u0443\u043A\u0430\u0437\u0430\u043D") "+7" else currentPhone)
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
            .setTitle("\u0421\u043C\u0435\u043D\u0438\u0442\u044C \u043F\u043E\u043B\u044C\u0437\u043E\u0432\u0430\u0442\u0435\u043B\u044F")
            .setView(container)
            .setPositiveButton("\u0421\u043E\u0445\u0440\u0430\u043D\u0438\u0442\u044C") { dialog, _ ->
                val fullName = inputName.text?.toString()?.trim().orEmpty()
                val phone = inputPhone.text?.toString()?.trim().orEmpty()
                if (fullName.isBlank()) {
                    Toast.makeText(requireContext(), "\u041F\u043E\u043B\u0435 \u0424\u0418\u041E \u043E\u0431\u044F\u0437\u0430\u0442\u0435\u043B\u044C\u043D\u043E", Toast.LENGTH_SHORT).show()
                } else if (phone.isBlank()) {
                    Toast.makeText(requireContext(), "\u041F\u043E\u043B\u0435 \u041D\u043E\u043C\u0435\u0440 \u0442\u0435\u043B\u0435\u0444\u043E\u043D\u0430 \u043E\u0431\u044F\u0437\u0430\u0442\u0435\u043B\u044C\u043D\u043E", Toast.LENGTH_SHORT).show()
                } else {
                    viewLifecycleOwner.lifecycleScope.launch {
                        viewModel.setManagerName(fullName)
                        viewModel.setManagerPhone(phone)
                        Toast.makeText(requireContext(), "\u041F\u043E\u043B\u044C\u0437\u043E\u0432\u0430\u0442\u0435\u043B\u044C \u043E\u0431\u043D\u043E\u0432\u043B\u0451\u043D", Toast.LENGTH_SHORT).show()
                    }
                    dialog.dismiss()
                }
            }
            .setNegativeButton("\u041E\u0442\u043C\u0435\u043D\u0430", null)
            .show()
    }

    private fun buildInputRow(editText: EditText, onClear: () -> Unit): LinearLayout {
        val clear = ImageButton(requireContext()).apply {
            setImageResource(android.R.drawable.ic_menu_close_clear_cancel)
            background = null
            contentDescription = "\u041E\u0447\u0438\u0441\u0442\u0438\u0442\u044C"
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
