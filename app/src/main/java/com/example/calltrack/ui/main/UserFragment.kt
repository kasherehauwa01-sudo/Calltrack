package com.example.calltrack.ui.main

import android.os.Bundle
import android.view.LayoutInflater
import android.view.View
import android.view.ViewGroup
import androidx.fragment.app.Fragment
import androidx.fragment.app.activityViewModels
import com.example.calltrack.App
import com.example.calltrack.auth.AuthStore
import com.example.calltrack.databinding.FragmentUserBinding

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
        binding.btnBack.setOnClickListener { (requireActivity() as MainActivity).openDialScreen() }
        viewModel.managerName.observe(viewLifecycleOwner) { name ->
            binding.tvCurrentUser.text = name.ifBlank { "\u041D\u0435 \u0443\u043A\u0430\u0437\u0430\u043D" }
        }
        binding.tvCurrentUserLogin.text = AuthStore(requireContext()).login.ifBlank { "\u041D\u0435 \u0443\u043A\u0430\u0437\u0430\u043D" }
    }

    override fun onDestroyView() {
        _binding = null
        super.onDestroyView()
    }

    companion object {
        fun newInstance() = UserFragment()
    }
}
