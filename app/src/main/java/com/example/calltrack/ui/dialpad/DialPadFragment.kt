package com.example.calltrack.ui.dialpad

import android.content.Intent
import android.net.Uri
import android.os.Bundle
import android.provider.ContactsContract
import android.view.LayoutInflater
import android.view.View
import android.view.ViewGroup
import android.widget.Toast
import androidx.fragment.app.Fragment
import androidx.fragment.app.activityViewModels
import androidx.lifecycle.lifecycleScope
import androidx.recyclerview.widget.LinearLayoutManager
import com.example.calltrack.App
import com.example.calltrack.databinding.FragmentDialPadBinding
import com.example.calltrack.ui.main.MainViewModel
import com.example.calltrack.utils.CallUtils
import com.example.calltrack.utils.T9Mapper
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.Job
import kotlinx.coroutines.launch
import kotlinx.coroutines.withContext

class DialPadFragment : Fragment() {

    private var _binding: FragmentDialPadBinding? = null
    private val binding get() = _binding!!

    private val viewModel: MainViewModel by activityViewModels {
        MainViewModel.Factory((requireActivity().application as App).repository)
    }

    private val t9Adapter = T9ContactAdapter { item ->
        binding.tvNumber.text = item.phone
        viewModel.setDialNumber(item.phone)
    }

    private var allContacts: List<T9ContactItem> = emptyList()
    private var searchJob: Job? = null

    override fun onCreateView(inflater: LayoutInflater, container: ViewGroup?, savedInstanceState: Bundle?): View {
        _binding = FragmentDialPadBinding.inflate(inflater, container, false)
        return binding.root
    }

    override fun onViewCreated(view: View, savedInstanceState: Bundle?) {
        binding.recyclerT9.layoutManager = LinearLayoutManager(requireContext())
        binding.recyclerT9.adapter = t9Adapter

        initKeypad()
        loadContacts()

        binding.btnBackspace.setOnClickListener {
            val current = binding.tvNumber.text.toString()
            if (current.isNotEmpty()) {
                binding.tvNumber.text = current.dropLast(1)
                onNumberChanged()
            }
        }

        binding.btnCall.setOnClickListener {
            val raw = binding.tvNumber.text.toString().trim()
            if (raw.isBlank()) {
                Toast.makeText(requireContext(), "Введите номер", Toast.LENGTH_SHORT).show()
                return@setOnClickListener
            }
            val formatted = CallUtils.formatPhone(raw)
            startActivity(Intent(Intent.ACTION_DIAL, Uri.parse("tel:$formatted")))
        }

        viewModel.dialNumber.observe(viewLifecycleOwner) {
            if (!it.isNullOrBlank()) {
                binding.tvNumber.text = it
                onNumberChanged()
            }
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
            button.setOnClickListener {
                binding.tvNumber.append(value)
                onNumberChanged()
            }
        }
        binding.key0.setOnLongClickListener {
            binding.tvNumber.append("+")
            onNumberChanged()
            true
        }
    }

    private fun loadContacts() {
        viewLifecycleOwner.lifecycleScope.launch {
            allContacts = withContext(Dispatchers.IO) {
                val list = mutableListOf<T9ContactItem>()
                requireContext().contentResolver.query(
                    ContactsContract.CommonDataKinds.Phone.CONTENT_URI,
                    arrayOf(
                        ContactsContract.CommonDataKinds.Phone.CONTACT_ID,
                        ContactsContract.CommonDataKinds.Phone.DISPLAY_NAME,
                        ContactsContract.CommonDataKinds.Phone.NUMBER
                    ),
                    null,
                    null,
                    ContactsContract.CommonDataKinds.Phone.DISPLAY_NAME + " ASC"
                )?.use { cursor ->
                    val idIdx = cursor.getColumnIndexOrThrow(ContactsContract.CommonDataKinds.Phone.CONTACT_ID)
                    val nameIdx = cursor.getColumnIndexOrThrow(ContactsContract.CommonDataKinds.Phone.DISPLAY_NAME)
                    val phoneIdx = cursor.getColumnIndexOrThrow(ContactsContract.CommonDataKinds.Phone.NUMBER)
                    while (cursor.moveToNext()) {
                        val id = cursor.getLong(idIdx)
                        val name = cursor.getString(nameIdx).orEmpty()
                        val phone = cursor.getString(phoneIdx).orEmpty()
                        list += T9ContactItem(
                            contactId = id,
                            name = name,
                            phone = phone,
                            t9Digits = T9Mapper.nameToDigits(name)
                        )
                    }
                }
                list
            }
            onNumberChanged()
        }
    }

    private fun onNumberChanged() {
        val query = binding.tvNumber.text.toString().filter { it.isDigit() }
        searchJob?.cancel()
        searchJob = viewLifecycleOwner.lifecycleScope.launch {
            val filtered = withContext(Dispatchers.Default) {
                if (query.isBlank()) {
                    emptyList()
                } else {
                    allContacts.filter {
                        it.t9Digits.startsWith(query) || it.phone.filter { ch -> ch.isDigit() }.startsWith(query)
                    }.take(30)
                }
            }
            t9Adapter.submitList(filtered)
            binding.tvNoResults.visibility = if (query.isNotBlank() && filtered.isEmpty()) View.VISIBLE else View.GONE
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
