package com.example.calltrack.notification

import android.Manifest
import android.content.Context
import android.content.pm.PackageManager
import android.net.Uri
import android.provider.ContactsContract
import android.util.Log
import com.example.calltrack.BuildConfig
import com.example.calltrack.data.repository.ClientPhoneNumbers
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

    /** Same provider and exact-name filtering as calls, with message-specific number normalization. */
    internal fun resolveMessage(name: String): ResolvedContact {
        val granted = ContextCompat.checkSelfPermission(context, Manifest.permission.READ_CONTACTS) == PackageManager.PERMISSION_GRANTED
        if (BuildConfig.DEBUG) Log.d(MaxMessagePhoneResolver.PHONE_DEBUG_TAG, "resolveMessage name=[$name] normalizedName=[${MaxContactNameMatcher.normalize(name)}] READ_CONTACTS granted=$granted")
        if (!granted) {
            if (BuildConfig.DEBUG) Log.d(MaxMessagePhoneResolver.PHONE_DEBUG_TAG, "resolveMessage status=PERMISSION_DENIED")
            return ResolvedContact(null, ContactResolutionStatus.PERMISSION_DENIED)
        }
        val result = MaxMessagePhoneResolver.resolveCandidates(findMatches(name, useRawWhenNormalizedBlank = true))
        if (BuildConfig.DEBUG) Log.d(MaxMessagePhoneResolver.PHONE_DEBUG_TAG, "resolveMessage status=${result.status} phone=[${result.phone}]")
        return result
    }

    private fun findMatches(name: String, useRawWhenNormalizedBlank: Boolean = false): List<ContactCandidate> {
        val result = mutableListOf<ContactCandidate>()
        val uri = Uri.withAppendedPath(ContactsContract.Contacts.CONTENT_FILTER_URI, Uri.encode(MaxContactNameMatcher.normalize(name)))
        if (BuildConfig.DEBUG && useRawWhenNormalizedBlank) Log.d(MaxMessagePhoneResolver.PHONE_DEBUG_TAG, "findMatches querying CONTENT_FILTER_URI normalizedName=[${MaxContactNameMatcher.normalize(name)}]")
        context.contentResolver.query(uri, arrayOf(ContactsContract.Contacts._ID, ContactsContract.Contacts.DISPLAY_NAME_PRIMARY), null, null, null)?.use { cursor ->
            if (BuildConfig.DEBUG && useRawWhenNormalizedBlank) Log.d(MaxMessagePhoneResolver.PHONE_DEBUG_TAG, "findMatches CONTENT_FILTER_URI rows=${cursor.count}")
            val idIndex = cursor.getColumnIndex(ContactsContract.Contacts._ID)
            val nameIndex = cursor.getColumnIndex(ContactsContract.Contacts.DISPLAY_NAME_PRIMARY)
            if (idIndex < 0 || nameIndex < 0) {
                if (BuildConfig.DEBUG && useRawWhenNormalizedBlank) Log.w(MaxMessagePhoneResolver.PHONE_DEBUG_TAG, "findMatches missingColumns idIndex=$idIndex nameIndex=$nameIndex")
                return@use
            }
            while (cursor.moveToNext()) {
                val displayName = if (cursor.isNull(nameIndex)) null else cursor.getString(nameIndex)
                if (BuildConfig.DEBUG && useRawWhenNormalizedBlank) Log.d(MaxMessagePhoneResolver.PHONE_DEBUG_TAG, "findMatches contactId=${cursor.getLong(idIndex)} DISPLAY_NAME_PRIMARY=[$displayName] matches=${displayName != null && MaxContactNameMatcher.matches(name, displayName)}")
                if (displayName != null && MaxContactNameMatcher.matches(name, displayName)) {
                    val id = cursor.getLong(idIndex)
                    result += ContactCandidate(id, findPhones(id, useRawWhenNormalizedBlank))
                }
            }
        } ?: run {
            if (BuildConfig.DEBUG && useRawWhenNormalizedBlank) Log.w(MaxMessagePhoneResolver.PHONE_DEBUG_TAG, "findMatches CONTENT_FILTER_URI cursor=null rows=0")
        }
        if (BuildConfig.DEBUG && useRawWhenNormalizedBlank) Log.d(MaxMessagePhoneResolver.PHONE_DEBUG_TAG, "findMatches matchedContacts=${result.size}")
        return result
    }

    private fun findPhones(contactId: Long, useRawWhenNormalizedBlank: Boolean): List<String> {
        val result = mutableListOf<String>()
        if (BuildConfig.DEBUG && useRawWhenNormalizedBlank) Log.d(MaxMessagePhoneResolver.PHONE_DEBUG_TAG, "findPhones querying contactId=$contactId")
        context.contentResolver.query(
            ContactsContract.CommonDataKinds.Phone.CONTENT_URI,
            arrayOf(ContactsContract.CommonDataKinds.Phone.NUMBER, ContactsContract.CommonDataKinds.Phone.NORMALIZED_NUMBER),
            "${ContactsContract.CommonDataKinds.Phone.CONTACT_ID} = ?", arrayOf(contactId.toString()), null
        )?.use { cursor ->
            if (BuildConfig.DEBUG && useRawWhenNormalizedBlank) Log.d(MaxMessagePhoneResolver.PHONE_DEBUG_TAG, "findPhones contactId=$contactId phoneRows=${cursor.count}")
            val rawIndex = cursor.getColumnIndex(ContactsContract.CommonDataKinds.Phone.NUMBER)
            val normalizedIndex = cursor.getColumnIndex(ContactsContract.CommonDataKinds.Phone.NORMALIZED_NUMBER)
            if (BuildConfig.DEBUG && useRawWhenNormalizedBlank) Log.d(MaxMessagePhoneResolver.PHONE_DEBUG_TAG, "findPhones contactId=$contactId rawIndex=$rawIndex normalizedIndex=$normalizedIndex")
            while (cursor.moveToNext()) {
                val normalized = normalizedIndex.takeIf { it >= 0 && !cursor.isNull(it) }?.let(cursor::getString)
                val raw = rawIndex.takeIf { it >= 0 && !cursor.isNull(it) }?.let(cursor::getString)
                val number = if (useRawWhenNormalizedBlank) normalized?.takeIf(String::isNotBlank) ?: raw else normalized ?: raw
                if (BuildConfig.DEBUG && useRawWhenNormalizedBlank) Log.d(MaxMessagePhoneResolver.PHONE_DEBUG_TAG, "findPhones contactId=$contactId NUMBER=[$raw] NORMALIZED_NUMBER=[$normalized] selected=[$number] fromRussianContact=[${number?.let(ClientPhoneNumbers::fromRussianContact)}]")
                number?.takeIf(String::isNotBlank)?.let(result::add)
            }
        } ?: run {
            if (BuildConfig.DEBUG && useRawWhenNormalizedBlank) Log.w(MaxMessagePhoneResolver.PHONE_DEBUG_TAG, "findPhones contactId=$contactId cursor=null phoneRows=0")
        }
        if (BuildConfig.DEBUG && useRawWhenNormalizedBlank) Log.d(MaxMessagePhoneResolver.PHONE_DEBUG_TAG, "findPhones contactId=$contactId distinctProviderNumbers=${result.distinct().size}")
        return result.distinct()
    }
}
