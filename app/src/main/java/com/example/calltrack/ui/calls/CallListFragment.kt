package com.example.calltrack.ui.calls

import android.os.Bundle
import android.view.LayoutInflater
import android.view.View
import android.view.ViewGroup
import androidx.fragment.app.Fragment
import androidx.fragment.app.activityViewModels
import androidx.recyclerview.widget.LinearLayoutManager
import com.example.calltrack.App
import com.example.calltrack.databinding.FragmentCallListBinding
import com.example.calltrack.ui.main.MainActivity
import com.example.calltrack.ui.main.MainViewModel

class CallListFragment : Fragment() {

    private var _binding: FragmentCallListBinding? = null
    private val binding get() = _binding!!

    private val adapter by lazy {
        CallAdapter { item ->
            if (item.phone.isNotBlank() && item.phone != "Неизвестно") {
                (requireActivity() as MainActivity).setDialNumber(item.phone)
            }
        }
    }

    private val viewModel: MainViewModel by activityViewModels {
        MainViewModel.Factory((requireActivity().application as App).repository)
    }

    override fun onCreateView(inflater: LayoutInflater, container: ViewGroup?, savedInstanceState: Bundle?): View {
        _binding = FragmentCallListBinding.inflate(inflater, container, false)
        return binding.root
    }

    override fun onViewCreated(view: View, savedInstanceState: Bundle?) {
        binding.recyclerView.layoutManager = LinearLayoutManager(requireContext())
        binding.recyclerView.adapter = adapter
        viewModel.calls.observe(viewLifecycleOwner) { adapter.submitList(it) }
    }

    override fun onDestroyView() {
        _binding = null
        super.onDestroyView()
    }

    companion object {
        fun newInstance() = CallListFragment()
    }
}
