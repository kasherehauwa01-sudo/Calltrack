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
import com.example.calltrack.App
import com.example.calltrack.BuildConfig
import com.example.calltrack.auth.AuthStore
import com.example.calltrack.data.local.CallEntity
import com.example.calltrack.service.CalltrackRecoveryManager
import kotlinx.coroutines.CoroutineScope
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.SupervisorJob
import kotlinx.coroutines.cancel
import kotlinx.coroutines.launch

/** Фиксация звонков MAX через общую локальную очередь и backend Calltrack. */
class MaxNotificationListenerService : NotificationListenerService() {

    private val scope = CoroutineScope(SupervisorJob() + Dispatchers.IO)
    private var session: MaxCallSession? = null
    private var resolution: ResolvedContact? = null

    override fun onCreate() {
        super.onCreate()
        val store = MaxCallSessionStore(this)
        session = store.load()
        store.loadCompleted()?.let(::queueCompletedCall)
    }

    override fun onDestroy() {
        scope.cancel()
        super.onDestroy()
    }

    override fun onNotificationPosted(sbn: StatusBarNotification?) {
        if (sbn?.packageName != MAX_PACKAGE) return
        runCatching { logPosted(sbn) }
            .onFailure { Log.e(TAG, "event=POSTED diagnosticError=${safeError(it)}") }
    }

    override fun onNotificationRemoved(sbn: StatusBarNotification?) {
        if (sbn?.packageName != MAX_PACKAGE) return
        runCatching { finishSession(sbn) }
            .onFailure { Log.e(TAG, "event=callEndFailed error=${safeError(it)}") }
        if (BuildConfig.DEBUG) runCatching {
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
        if (BuildConfig.DEBUG) Log.i(TAG, buildString {
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
            updateSession(sbn, extras, personName)
        }
    }

    private fun updateSession(sbn: StatusBarNotification, extras: Bundle, name: String) {
        val callType = (extras.safeScalar(CALL_TYPE_KEY) as? Number)?.toInt() ?: return
        val previous = session?.takeIf { it.notificationKey == sbn.key }
        val updated = MaxCallStateMachine.posted(
            previous, sbn.key, name, callType,
            extras.safeScalar(CALL_IS_VIDEO_KEY) as? Boolean ?: false,
            System.currentTimeMillis()
        ) ?: return
        if (previous == null) {
            resolution = MaxContactResolver(this).resolve(name)
            Log.i(TAG, "event=sessionCreated direction=${updated.direction.name.lowercase()}")
            Log.i(TAG, "event=contactResolved status=${resolution?.status?.wireValue} phone=${maskPhone(resolution?.phone)}")
        } else if (previous.state != MaxCallState.ACTIVE && updated.state == MaxCallState.ACTIVE) {
            Log.i(TAG, "event=callAnswered direction=incoming")
        }
        session = updated
        MaxCallSessionStore(this).save(updated)
    }

    private fun finishSession(sbn: StatusBarNotification) {
        val current = session?.takeIf { it.notificationKey == sbn.key } ?: return
        val result = MaxCallStateMachine.removed(current, System.currentTimeMillis()) ?: return
        val contact = resolution ?: MaxContactResolver(this).resolve(result.maxContactName)
        val store = MaxCallSessionStore(this)
        // Сначала надёжно фиксируем завершённое событие. При смерти процесса оно будет
        // повторно поставлено в общую Room-очередь с тем же идемпотентным event id.
        store.saveCompleted(result, contact)
        session = null
        store.clearActive()
        resolution = null
        Log.i(TAG, "event=callEnded direction=${result.direction.name.lowercase()} status=${result.status.name.lowercase()} duration=${result.durationSeconds}")
        queueCompletedCall(PendingMaxCall(result, contact))
    }

    private fun queueCompletedCall(pending: PendingMaxCall) {
        if (!AuthStore(this).isAuthenticated) return
        scope.launch {
            val result = pending.result
            val contact = pending.contact
            val repository = (application as App).repository
            val entity = CallEntity(
                phone = contact.phone?.let(repository::normalizePhone).orEmpty(),
                type = when {
                    result.direction == MaxCallDirection.OUTGOING -> "\u0418\u0441\u0445\u043E\u0434\u044F\u0449\u0438\u0439"
                    result.status == MaxCallStatus.MISSED -> "\u041F\u0440\u043E\u043F\u0443\u0449\u0435\u043D\u043D\u044B\u0439"
                    else -> "\u0412\u0445\u043E\u0434\u044F\u0449\u0438\u0439"
                },
                duration = result.durationSeconds,
                note = "",
                timestamp = result.startedAt,
                source = "max",
                sourceEventId = result.sourceEventId,
                contactName = result.maxContactName,
                direction = result.direction.name.lowercase(),
                status = result.status.name.lowercase(),
                answeredAt = result.answeredAt,
                endedAt = result.endedAt,
                ringingDurationSeconds = result.ringingDurationSeconds,
                isVideo = result.isVideo,
                contactResolutionStatus = contact.status.wireValue
            )
            val id = repository.saveCall(entity)
            MaxCallSessionStore(this@MaxNotificationListenerService).clearCompleted()
            Log.i(TAG, "event=callQueued sourceEventId=${result.sourceEventId}")
            val uploaded = repository.syncCallById(id)
            Log.i(TAG, "event=${if (uploaded) "callUploaded" else "callUploadFailed"} sourceEventId=${result.sourceEventId}")
            CalltrackRecoveryManager.schedulePendingSync(this@MaxNotificationListenerService)
        }
    }

    private fun maskPhone(phone: String?): String = when {
        phone.isNullOrBlank() -> "none"
        phone.length <= 4 -> "****"
        else -> "***${phone.takeLast(4)}"
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
