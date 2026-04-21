package com.example.calltrack.ui.calls

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
import androidx.recyclerview.widget.ItemTouchHelper
import androidx.recyclerview.widget.LinearLayoutManager
import androidx.recyclerview.widget.RecyclerView
import com.example.calltrack.App
import com.example.calltrack.data.local.CallEntity
import com.example.calltrack.databinding.FragmentCallListBinding
import com.example.calltrack.ui.main.MainActivity
import com.example.calltrack.ui.main.MainViewModel
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.launch
import kotlinx.coroutines.withContext

class CallListFragment : Fragment() {

    private var _binding: FragmentCallListBinding? = null
    private val binding get() = _binding!!

    private val adapter by lazy {
        CallAdapter { item ->
            if (item.call.phone.isNotBlank() && item.call.phone != "Неизвестно") {
                (requireActivity() as MainActivity).openContactCard(item.call.phone)
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
        attachSwipeToCall()

        viewModel.calls.observe(viewLifecycleOwner) { calls ->
            viewLifecycleOwner.lifecycleScope.launch {
                adapter.submitList(resolveCallItems(calls))
            }
        }
    }

    private suspend fun resolveCallItems(calls: List<CallEntity>): List<RecentCallItem> = withContext(Dispatchers.IO) {
        val nameByPhone = mutableMapOf<String, String>()
        requireContext().contentResolver.query(
            ContactsContract.CommonDataKinds.Phone.CONTENT_URI,
            arrayOf(
                ContactsContract.CommonDataKinds.Phone.DISPLAY_NAME,
                ContactsContract.CommonDataKinds.Phone.NUMBER
            ),
            null,
            null,
            null
        )?.use { cursor ->
            val nameIdx = cursor.getColumnIndexOrThrow(ContactsContract.CommonDataKinds.Phone.DISPLAY_NAME)
            val phoneIdx = cursor.getColumnIndexOrThrow(ContactsContract.CommonDataKinds.Phone.NUMBER)
            while (cursor.moveToNext()) {
                val name = cursor.getString(nameIdx).orEmpty()
                val phone = normalizePhone(cursor.getString(phoneIdx).orEmpty())
                if (phone.isNotBlank() && name.isNotBlank()) {
                    nameByPhone.putIfAbsent(phone, name)
                }
            }
        }

        calls.map { call ->
            val normalized = normalizePhone(call.phone)
            RecentCallItem(
                call = call,
                contactName = nameByPhone[normalized] ?: call.phone
            )
        }
    }

    private fun attachSwipeToCall() {
        val callback = object : ItemTouchHelper.SimpleCallback(0, ItemTouchHelper.RIGHT) {
            override fun onMove(
                recyclerView: RecyclerView,
                viewHolder: RecyclerView.ViewHolder,
                target: RecyclerView.ViewHolder
            ): Boolean = false

            override fun onSwiped(viewHolder: RecyclerView.ViewHolder, direction: Int) {
                val item = adapter.getItemAt(viewHolder.bindingAdapterPosition)
                adapter.notifyItemChanged(viewHolder.bindingAdapterPosition)
                makeDirectCall(item.call.phone)
            }
        }
        ItemTouchHelper(callback).attachToRecyclerView(binding.recyclerView)
    }

    private fun makeDirectCall(rawPhone: String) {
        val phone = rawPhone.trim()
        if (phone.isBlank() || phone == "Неизвестно") return

        val callIntent = Intent(Intent.ACTION_CALL, Uri.parse("tel:$phone"))
        val fallbackDialIntent = Intent(Intent.ACTION_DIAL, Uri.parse("tel:$phone"))
        runCatching {
            startActivity(callIntent)
        }.onFailure {
            Toast.makeText(requireContext(), "Нет разрешения на прямой вызов, открываю набор", Toast.LENGTH_SHORT).show()
            runCatching { startActivity(fallbackDialIntent) }
        }
    }

    private fun normalizePhone(value: String): String = value.filter { it.isDigit() }

    override fun onDestroyView() {
        _binding = null
        super.onDestroyView()
    }

    companion object {
        fun newInstance() = CallListFragment()
    }
}
