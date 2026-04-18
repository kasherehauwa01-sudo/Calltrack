package com.example.calltrack.ui.dialpad

import android.content.Intent
import android.net.Uri
import android.os.Bundle
import android.view.LayoutInflater
import android.view.View
import android.view.ViewGroup
import androidx.fragment.app.Fragment
import com.example.calltrack.databinding.FragmentDialPadBinding
import com.example.calltrack.utils.CallUtils

class DialPadFragment : Fragment() {

    private var _binding: FragmentDialPadBinding? = null
    private val binding get() = _binding!!

    override fun onCreateView(inflater: LayoutInflater, container: ViewGroup?, savedInstanceState: Bundle?): View {
        _binding = FragmentDialPadBinding.inflate(inflater, container, false)
        return binding.root
    }

    override fun onViewCreated(view: View, savedInstanceState: Bundle?) {
        binding.btnCall.setOnClickListener {
            val raw = binding.etPhone.text.toString().trim()
            val formatted = CallUtils.formatPhone(raw)
            val intent = Intent(Intent.ACTION_CALL, Uri.parse("tel:$formatted"))
            startActivity(intent)
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
