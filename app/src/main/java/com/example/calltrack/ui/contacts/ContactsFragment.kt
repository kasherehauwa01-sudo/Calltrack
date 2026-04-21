package com.example.calltrack.ui.contacts

import android.os.Bundle
import android.provider.ContactsContract
import android.view.LayoutInflater
import android.view.View
import android.view.ViewGroup
import androidx.fragment.app.Fragment
import androidx.lifecycle.lifecycleScope
import androidx.recyclerview.widget.LinearLayoutManager
import com.example.calltrack.databinding.FragmentContactsBinding
import com.example.calltrack.ui.main.MainActivity
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.launch
import kotlinx.coroutines.withContext

class ContactsFragment : Fragment() {

    private var _binding: FragmentContactsBinding? = null
    private val binding get() = _binding!!

    private val adapter = ContactsAdapter { contact ->
        if (contact.phone.isNotBlank()) {
            (requireActivity() as MainActivity).openContactCard(contact.phone)
        }
    }

    override fun onCreateView(inflater: LayoutInflater, container: ViewGroup?, savedInstanceState: Bundle?): View {
        _binding = FragmentContactsBinding.inflate(inflater, container, false)
        return binding.root
    }

    override fun onViewCreated(view: View, savedInstanceState: Bundle?) {
        binding.recyclerContacts.layoutManager = LinearLayoutManager(requireContext())
        binding.recyclerContacts.adapter = adapter

        viewLifecycleOwner.lifecycleScope.launch {
            val contacts = loadContacts()
            adapter.submitList(contacts)
            binding.tvEmpty.visibility = if (contacts.isEmpty()) View.VISIBLE else View.GONE
        }
    }

    private suspend fun loadContacts(): List<ContactListItem> = withContext(Dispatchers.IO) {
        val list = mutableListOf<ContactListItem>()
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
                list += ContactListItem(
                    contactId = cursor.getLong(idIdx),
                    name = cursor.getString(nameIdx).orEmpty().ifBlank { "Без имени" },
                    phone = cursor.getString(phoneIdx).orEmpty()
                )
            }
        }
        list
    }

    override fun onDestroyView() {
        _binding = null
        super.onDestroyView()
    }

    companion object {
        fun newInstance() = ContactsFragment()
    }
}
