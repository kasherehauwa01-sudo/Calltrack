package com.example.calltrack.notification

import android.Manifest
import android.app.Notification
import android.app.Person
import android.content.pm.PackageManager
import android.net.Uri
import android.os.Build
import android.os.Bundle
import android.provider.ContactsContract
import android.service.notification.NotificationListenerService
import android.service.notification.StatusBarNotification
import android.util.Log
import androidx.core.content.ContextCompat
import java.util.Locale

/** Временная диагностика уведомлений MAX. Данные выводятся только в Logcat. */
class MaxNotificationListenerService : NotificationListenerService() {

    private val lookedUpNamesByNotification = mutableMapOf<String, MutableSet<String>>()

    override fun onNotificationPosted(sbn: StatusBarNotification?) {
        if (sbn?.packageName != MAX_PACKAGE) return
        runCatching { logPosted(sbn) }
            .onFailure { Log.e(TAG, "event=POSTED diagnosticError=${safeError(it)}") }
    }

    override fun onNotificationRemoved(sbn: StatusBarNotification?) {
        if (sbn?.packageName != MAX_PACKAGE) return
        synchronized(lookedUpNamesByNotification) {
            lookedUpNamesByNotification.remove(sbn.key)
        }
        runCatching {
            Log.i(TAG, buildString {
                append("event=REMOVED")
                append(" packageName=").append(sbn.packageName)
                append(" id=").append(sbn.id)
                append(" tag=").append(sbn.tag)
                append(" key=").append(sbn.key)
                append(" postTime=").append(sbn.postTime)
                append(" removedAt=").append(System.currentTimeMillis())
            })
        }.onFailure { Log.e(TAG, "event=REMOVED diagnosticError=${safeError(it)}") }
    }

    private fun logPosted(sbn: StatusBarNotification) {
        val notification = sbn.notification
        val extras = notification.extras ?: Bundle.EMPTY
        val person = extractCallPerson(extras)
        Log.i(TAG, buildString {
            append("event=POSTED")
            append(" packageName=").append(sbn.packageName)
            append(" id=").append(sbn.id)
            append(" tag=").append(sbn.tag)
            append(" key=").append(sbn.key)
            append(" postTime=").append(sbn.postTime)
            append(" channelId=").append(notification.channelId)
            append(" category=").append(notification.category)
            append(" flags=").append(notification.flags)
            append(" when=").append(notification.`when`)
            append(" isMaxCall=").append(isMaxCallNotification(extras))
            append(" title=").append(extras.safeText(Notification.EXTRA_TITLE))
            append(" text=").append(extras.safeText(Notification.EXTRA_TEXT))
            append(" subText=").append(extras.safeText(Notification.EXTRA_SUB_TEXT))
            append(" infoText=").append(extras.safeText(Notification.EXTRA_INFO_TEXT))
            append(" template=").append(extras.safeText(Notification.EXTRA_TEMPLATE))
            append(" callType=").append(extras.safeScalar(CALL_TYPE_KEY))
            append(" callIsVideo=").append(extras.safeScalar(CALL_IS_VIDEO_KEY))
            append(" extrasKeys=").append(extras.keySet().sorted())
            if (person != null) {
                append(" person.name=").append(person.name)
                append(" person.uri=").append(person.uri)
                append(" person.key=").append(person.key)
                append(" person.isBot=").append(person.isBot)
                append(" person.isImportant=").append(person.isImportant)
            } else {
                append(" person=null")
            }
        })

        val personName = person?.name?.toString()?.takeIf { it.isNotBlank() }
        if (extras.safeText(Notification.EXTRA_TEMPLATE) == CALL_STYLE_TEMPLATE && personName != null) {
            lookupContactOnce(sbn, personName)
        }
    }

    private fun lookupContactOnce(sbn: StatusBarNotification, maxName: String) {
        val normalizedName = MaxContactNameMatcher.normalize(maxName)
        val shouldLookup = synchronized(lookedUpNamesByNotification) {
            lookedUpNamesByNotification.getOrPut(sbn.key) { mutableSetOf() }
                .add(normalizedName.lowercase(Locale.ROOT))
        }
        if (!shouldLookup) return

        if (ContextCompat.checkSelfPermission(this, Manifest.permission.READ_CONTACTS) != PackageManager.PERMISSION_GRANTED) {
            Log.i(TAG, "contactLookup permission=denied")
            return
        }

        runCatching { findExactContacts(maxName) }
            .onFailure { Log.e(TAG, "contactLookup error=${it::class.java.simpleName}") }
    }

    private fun findExactContacts(maxName: String) {
        val matches = mutableListOf<ContactMatch>()
        val uri = Uri.withAppendedPath(
            ContactsContract.Contacts.CONTENT_FILTER_URI,
            Uri.encode(MaxContactNameMatcher.normalize(maxName))
        )
        contentResolver.query(
            uri,
            arrayOf(ContactsContract.Contacts._ID, ContactsContract.Contacts.DISPLAY_NAME_PRIMARY),
            null,
            null,
            null
        )?.use { cursor ->
            val idColumn = cursor.getColumnIndex(ContactsContract.Contacts._ID)
            val nameColumn = cursor.getColumnIndex(ContactsContract.Contacts.DISPLAY_NAME_PRIMARY)
            if (idColumn < 0 || nameColumn < 0) error("Contacts cursor columns unavailable")
            while (cursor.moveToNext()) {
                if (cursor.isNull(idColumn) || cursor.isNull(nameColumn)) continue
                val displayName = cursor.getString(nameColumn) ?: continue
                if (MaxContactNameMatcher.matches(maxName, displayName)) {
                    matches += ContactMatch(cursor.getLong(idColumn), displayName)
                }
            }
        }

        Log.i(TAG, "contactLookup query=${quoted(maxName)} permission=granted matches=${matches.size}")
        matches.forEachIndexed { index, contact ->
            val phones = findPhones(contact.id)
            Log.i(
                TAG,
                "contactMatch index=$index contactId=${contact.id} " +
                    "displayName=${quoted(contact.displayName)} phones=${phones.size}"
            )
            phones.forEach { phone ->
                Log.i(
                    TAG,
                    "contactPhone contactId=${contact.id} raw=${quoted(phone.raw)} " +
                        "normalized=${quoted(phone.normalized)} type=${phone.type ?: "null"}"
                )
            }
        }
    }

    private fun findPhones(contactId: Long): List<ContactPhone> {
        val phones = mutableListOf<ContactPhone>()
        contentResolver.query(
            ContactsContract.CommonDataKinds.Phone.CONTENT_URI,
            arrayOf(
                ContactsContract.CommonDataKinds.Phone.NUMBER,
                ContactsContract.CommonDataKinds.Phone.NORMALIZED_NUMBER,
                ContactsContract.CommonDataKinds.Phone.TYPE
            ),
            "${ContactsContract.CommonDataKinds.Phone.CONTACT_ID} = ?",
            arrayOf(contactId.toString()),
            null
        )?.use { cursor ->
            val numberColumn = cursor.getColumnIndex(ContactsContract.CommonDataKinds.Phone.NUMBER)
            val normalizedColumn = cursor.getColumnIndex(ContactsContract.CommonDataKinds.Phone.NORMALIZED_NUMBER)
            val typeColumn = cursor.getColumnIndex(ContactsContract.CommonDataKinds.Phone.TYPE)
            while (cursor.moveToNext()) {
                val raw = numberColumn.takeIf { it >= 0 && !cursor.isNull(it) }?.let(cursor::getString)
                val normalized = normalizedColumn.takeIf { it >= 0 && !cursor.isNull(it) }?.let(cursor::getString)
                val type = typeColumn.takeIf { it >= 0 && !cursor.isNull(it) }?.let(cursor::getInt)
                phones += ContactPhone(raw, normalized, type)
            }
        }
        return phones
    }

    private fun quoted(value: String?): String = "\"${value.orEmpty().replace("\"", "\\\"")}\""

    private data class ContactMatch(val id: Long, val displayName: String)
    private data class ContactPhone(val raw: String?, val normalized: String?, val type: Int?)

    private fun isMaxCallNotification(extras: Bundle): Boolean =
        extras.safeText(Notification.EXTRA_TEMPLATE) == CALL_STYLE_TEMPLATE ||
            extras.containsKey(CALL_TYPE_KEY) || extras.containsKey(CALL_PERSON_KEY)

    private fun Bundle.safeText(key: String): String? = runCatching { getCharSequence(key)?.toString() }.getOrNull()

    private fun Bundle.safeScalar(key: String): Any? = runCatching {
        when (val value = get(key)) {
            null, is String, is CharSequence, is Number, is Boolean -> value
            else -> value::class.java.name
        }
    }.getOrNull()

    @Suppress("DEPRECATION")
    private fun extractCallPerson(extras: Bundle): Person? {
        if (Build.VERSION.SDK_INT < Build.VERSION_CODES.P) return null
        return runCatching {
            if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.TIRAMISU) {
                extras.getParcelable(CALL_PERSON_KEY, Person::class.java)
            } else {
                extras.getParcelable(CALL_PERSON_KEY) as? Person
            }
        }.getOrNull()
    }

    private fun safeError(error: Throwable): String = "${error::class.java.simpleName}:${error.message.orEmpty()}"

    companion object {
        private const val TAG = "CalltrackMAX"
        private const val MAX_PACKAGE = "ru.oneme.app"
        private const val CALL_STYLE_TEMPLATE = "android.app.Notification\$CallStyle"
        private const val CALL_TYPE_KEY = "android.callType"
        private const val CALL_IS_VIDEO_KEY = "android.callIsVideo"
        private const val CALL_PERSON_KEY = "android.callPerson"
    }
}
