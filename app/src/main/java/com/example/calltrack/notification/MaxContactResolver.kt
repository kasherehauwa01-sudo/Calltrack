package com.example.calltrack.notification

import android.Manifest
import android.content.Context
import android.content.pm.PackageManager
import android.net.Uri
import android.provider.ContactsContract
import androidx.core.content.ContextCompat

enum class ContactResolutionStatus(val wireValue: String) {
    RESOLVED("resolved"), AMBIGUOUS_MULTIPLE_PHONES("ambiguous_multiple_phones"),
    AMBIGUOUS_MULTIPLE_CONTACTS("ambiguous_multiple_contacts"), NOT_FOUND("not_found"),
    PERMISSION_DENIED("permission_denied"), ERROR("error")
}

data class ResolvedContact(val phone: String?, val status: ContactResolutionStatus)
data class ContactCandidate(val id: Long, val phones: List<String>)

object ContactResolutionRules {
    fun resolve(matches: List<ContactCandidate>): ResolvedContact = when {
        matches.isEmpty() -> ResolvedContact(null, ContactResolutionStatus.NOT_FOUND)
        matches.size > 1 -> ResolvedContact(null, ContactResolutionStatus.AMBIGUOUS_MULTIPLE_CONTACTS)
        matches.single().phones.size != 1 -> ResolvedContact(
            null,
            if (matches.single().phones.isEmpty()) ContactResolutionStatus.NOT_FOUND
            else ContactResolutionStatus.AMBIGUOUS_MULTIPLE_PHONES
        )
        else -> ResolvedContact(matches.single().phones.single(), ContactResolutionStatus.RESOLVED)
    }
}

class MaxContactResolver(private val context: Context) {
    fun resolve(name: String): ResolvedContact {
        if (ContextCompat.checkSelfPermission(context, Manifest.permission.READ_CONTACTS) != PackageManager.PERMISSION_GRANTED) {
            return ResolvedContact(null, ContactResolutionStatus.PERMISSION_DENIED)
        }
        return runCatching { ContactResolutionRules.resolve(findMatches(name)) }
            .getOrElse { ResolvedContact(null, ContactResolutionStatus.ERROR) }
    }

    private fun findMatches(name: String): List<ContactCandidate> {
        val result = mutableListOf<ContactCandidate>()
        val uri = Uri.withAppendedPath(ContactsContract.Contacts.CONTENT_FILTER_URI, Uri.encode(MaxContactNameMatcher.normalize(name)))
        context.contentResolver.query(uri, arrayOf(ContactsContract.Contacts._ID, ContactsContract.Contacts.DISPLAY_NAME_PRIMARY), null, null, null)?.use { cursor ->
            val idIndex = cursor.getColumnIndex(ContactsContract.Contacts._ID)
            val nameIndex = cursor.getColumnIndex(ContactsContract.Contacts.DISPLAY_NAME_PRIMARY)
            if (idIndex < 0 || nameIndex < 0) return@use
            while (cursor.moveToNext()) {
                val displayName = if (cursor.isNull(nameIndex)) null else cursor.getString(nameIndex)
                if (displayName != null && MaxContactNameMatcher.matches(name, displayName)) {
                    val id = cursor.getLong(idIndex)
                    result += ContactCandidate(id, findPhones(id))
                }
            }
        }
        return result
    }

    private fun findPhones(contactId: Long): List<String> {
        val result = mutableListOf<String>()
        context.contentResolver.query(
            ContactsContract.CommonDataKinds.Phone.CONTENT_URI,
            arrayOf(ContactsContract.CommonDataKinds.Phone.NUMBER, ContactsContract.CommonDataKinds.Phone.NORMALIZED_NUMBER),
            "${ContactsContract.CommonDataKinds.Phone.CONTACT_ID} = ?", arrayOf(contactId.toString()), null
        )?.use { cursor ->
            val rawIndex = cursor.getColumnIndex(ContactsContract.CommonDataKinds.Phone.NUMBER)
            val normalizedIndex = cursor.getColumnIndex(ContactsContract.CommonDataKinds.Phone.NORMALIZED_NUMBER)
            while (cursor.moveToNext()) {
                val normalized = normalizedIndex.takeIf { it >= 0 && !cursor.isNull(it) }?.let(cursor::getString)
                val raw = rawIndex.takeIf { it >= 0 && !cursor.isNull(it) }?.let(cursor::getString)
                (normalized ?: raw)?.takeIf(String::isNotBlank)?.let(result::add)
            }
        }
        return result.distinct()
    }
}
