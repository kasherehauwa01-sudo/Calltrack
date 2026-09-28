package com.example.calltrack.ui.contacts

import android.Manifest
import android.content.Intent
import android.content.pm.PackageManager
import android.net.Uri
import android.os.Bundle
import android.provider.ContactsContract
import android.view.LayoutInflater
import android.view.View
import android.view.ViewGroup
import android.widget.Toast
import androidx.core.widget.addTextChangedListener
import androidx.core.content.ContextCompat
import androidx.fragment.app.Fragment
import androidx.lifecycle.lifecycleScope
import androidx.recyclerview.widget.ItemTouchHelper
import androidx.recyclerview.widget.LinearLayoutManager
import androidx.recyclerview.widget.RecyclerView
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

    private var allContacts: List<ContactListItem> = emptyList()

    override fun onCreateView(inflater: LayoutInflater, container: ViewGroup?, savedInstanceState: Bundle?): View {
        _binding = FragmentContactsBinding.inflate(inflater, container, false)
        return binding.root
    }

    override fun onViewCreated(view: View, savedInstanceState: Bundle?) {
        binding.recyclerContacts.layoutManager = LinearLayoutManager(requireContext())
        binding.recyclerContacts.adapter = adapter
        attachSwipeToCall()

        binding.etSearch.addTextChangedListener {
            filterContacts(it?.toString().orEmpty())
        }

        viewLifecycleOwner.lifecycleScope.launch {
            allContacts = loadContacts()
            filterContacts("")
        }
    }

    private fun filterContacts(query: String) {
        val trimmed = query.trim()
        val filtered = if (trimmed.isBlank()) {
            allContacts
        } else {
            allContacts.filter {
                it.name.contains(trimmed, ignoreCase = true) || it.phone.contains(trimmed)
            }
        }
        adapter.submitList(filtered)
        binding.tvEmpty.visibility = if (filtered.isEmpty()) View.VISIBLE else View.GONE
    }

    private fun attachSwipeToCall() {
        val callback = object : ItemTouchHelper.SimpleCallback(0, ItemTouchHelper.RIGHT) {
            override fun onMove(
                recyclerView: RecyclerView,
                viewHolder: RecyclerView.ViewHolder,
                target: RecyclerView.ViewHolder
            ): Boolean = false

            override fun onSwiped(viewHolder: RecyclerView.ViewHolder, direction: Int) {
                val item = adapter.currentList.getOrNull(viewHolder.bindingAdapterPosition)
                adapter.notifyItemChanged(viewHolder.bindingAdapterPosition)
                item?.let { makeDirectCall(it.phone) }
            }
        }
        ItemTouchHelper(callback).attachToRecyclerView(binding.recyclerContacts)
    }

    private fun makeDirectCall(rawPhone: String) {
        val phone = rawPhone.trim()
        if (phone.isBlank()) return

        val callIntent = Intent(Intent.ACTION_DIAL, Uri.parse("tel:$phone"))
        runCatching {
            startActivity(callIntent)
        }.onFailure {
            Toast.makeText(requireContext(), "\u041D\u0435 \u0443\u0434\u0430\u043B\u043E\u0441\u044C \u043E\u0442\u043A\u0440\u044B\u0442\u044C \u0434\u043E\u0437\u0432\u043E\u043D", Toast.LENGTH_SHORT).show()
        }
    }

    private suspend fun loadContacts(): List<ContactListItem> = withContext(Dispatchers.IO) {
        val context = context ?: return@withContext emptyList()
        if (ContextCompat.checkSelfPermission(context, Manifest.permission.READ_CONTACTS) != PackageManager.PERMISSION_GRANTED) {
            return@withContext emptyList()
        }
        val list = mutableListOf<ContactListItem>()
        try {
            context.contentResolver.query(
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
                    name = cursor.getString(nameIdx).orEmpty().ifBlank { "\u0411\u0435\u0437 \u0438\u043C\u0435\u043D\u0438" },
                    phone = cursor.getString(phoneIdx).orEmpty()
                )
            }
            }
        } catch (_: SecurityException) {
            return@withContext emptyList()
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
