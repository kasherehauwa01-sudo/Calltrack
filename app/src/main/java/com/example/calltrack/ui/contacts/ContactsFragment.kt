package com.example.calltrack.ui.contacts

import android.content.Intent
import android.net.Uri
import android.os.Bundle
import android.provider.ContactsContract
import android.view.LayoutInflater
import android.view.View
import android.view.ViewGroup
import androidx.activity.result.contract.ActivityResultContracts
import androidx.fragment.app.Fragment
import com.example.calltrack.databinding.FragmentContactsBinding
import com.example.calltrack.ui.main.MainActivity

class ContactsFragment : Fragment() {

    private var _binding: FragmentContactsBinding? = null
    private val binding get() = _binding!!

    private val pickContactLauncher = registerForActivityResult(ActivityResultContracts.StartActivityForResult()) { result ->
        val dataUri = result.data?.data ?: return@registerForActivityResult
        val selected = getPhoneFromUri(dataUri)
        if (!selected.isNullOrBlank()) {
            binding.tvSelected.text = selected
            (requireActivity() as MainActivity).setDialNumber(selected)
        }
    }

    override fun onCreateView(inflater: LayoutInflater, container: ViewGroup?, savedInstanceState: Bundle?): View {
        _binding = FragmentContactsBinding.inflate(inflater, container, false)
        return binding.root
    }

    override fun onViewCreated(view: View, savedInstanceState: Bundle?) {
        binding.btnOpenContacts.setOnClickListener {
            val intent = Intent(Intent.ACTION_PICK, ContactsContract.CommonDataKinds.Phone.CONTENT_URI)
            pickContactLauncher.launch(intent)
        }
    }

    private fun getPhoneFromUri(uri: Uri): String? {
        requireContext().contentResolver.query(
            uri,
            arrayOf(ContactsContract.CommonDataKinds.Phone.NUMBER),
            null,
            null,
            null
        )?.use { cursor ->
            if (cursor.moveToFirst()) {
                val idx = cursor.getColumnIndexOrThrow(ContactsContract.CommonDataKinds.Phone.NUMBER)
                return cursor.getString(idx)
            }
        }
        return null
    }

    override fun onDestroyView() {
        _binding = null
        super.onDestroyView()
    }

    companion object {
        fun newInstance() = ContactsFragment()
    }
}
