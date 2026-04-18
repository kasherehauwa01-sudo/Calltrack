package com.example.calltrack.ui.dialpad

import android.content.Intent
import android.net.Uri
import android.os.Bundle
import android.view.LayoutInflater
import android.view.View
import android.view.ViewGroup
import androidx.fragment.app.Fragment
import androidx.fragment.app.activityViewModels
import com.example.calltrack.App
import com.example.calltrack.databinding.FragmentDialPadBinding
import com.example.calltrack.ui.main.MainViewModel
import com.example.calltrack.utils.CallUtils

class DialPadFragment : Fragment() {

    private var _binding: FragmentDialPadBinding? = null
    private val binding get() = _binding!!

    private val viewModel: MainViewModel by activityViewModels {
        MainViewModel.Factory((requireActivity().application as App).repository)
    }

    override fun onCreateView(inflater: LayoutInflater, container: ViewGroup?, savedInstanceState: Bundle?): View {
        _binding = FragmentDialPadBinding.inflate(inflater, container, false)
        return binding.root
    }

    override fun onViewCreated(view: View, savedInstanceState: Bundle?) {
        initKeypad()

        binding.btnBackspace.setOnClickListener {
            val current = binding.tvNumber.text.toString()
            if (current.isNotEmpty()) binding.tvNumber.text = current.dropLast(1)
        }

        binding.btnCall.setOnClickListener {
            val raw = binding.tvNumber.text.toString().trim()
            if (raw.isNotBlank()) {
                val formatted = CallUtils.formatPhone(raw)
                startActivity(Intent(Intent.ACTION_CALL, Uri.parse("tel:$formatted")))
            }
        }

        viewModel.dialNumber.observe(viewLifecycleOwner) {
            if (!it.isNullOrBlank()) binding.tvNumber.text = it
        }
    }

    private fun initKeypad() {
        val map = mapOf(
            binding.key1 to "1", binding.key2 to "2", binding.key3 to "3",
            binding.key4 to "4", binding.key5 to "5", binding.key6 to "6",
            binding.key7 to "7", binding.key8 to "8", binding.key9 to "9",
            binding.keyStar to "*", binding.key0 to "0", binding.keyHash to "#"
        )
        map.forEach { (button, value) ->
            button.setOnClickListener { binding.tvNumber.append(value) }
        }
        binding.key0.setOnLongClickListener {
            binding.tvNumber.append("+")
            true
        }
    }

    override fun onDestroyView() {
        _binding = null
        super.onDestroyView()
    }

    companion object {
        fun newInstance() = DialPadFragment()
    }
}
