package com.example.calltrack.data.repository

import android.content.Context
import android.util.Log
import com.example.calltrack.BuildConfig
import com.example.calltrack.data.local.CallDao
import com.example.calltrack.data.local.CallEntity
import com.example.calltrack.data.local.CallHistoryDao
import com.example.calltrack.data.local.CallHistoryEntity
import com.example.calltrack.data.local.CommentDao
import com.example.calltrack.data.local.CommentEntity
import com.example.calltrack.data.local.ContactDao
import com.example.calltrack.data.local.ContactEntity
import com.example.calltrack.data.local.ReminderDao
import com.example.calltrack.data.local.ReminderEntity
import com.example.calltrack.data.remote.WebhookApi
import com.example.calltrack.data.remote.CallHistoryItem
import com.example.calltrack.data.remote.WebhookRequest
import kotlinx.coroutines.flow.Flow
import kotlinx.coroutines.sync.Mutex
import kotlinx.coroutines.sync.withLock
import java.text.SimpleDateFormat
import java.util.Date
import java.util.Locale

class CallRepository(
    private val callDao: CallDao,
    private val contactDao: ContactDao,
    private val reminderDao: ReminderDao,
    private val commentDao: CommentDao,
    private val callHistoryDao: CallHistoryDao,
    private val webhookApi: WebhookApi,
    context: Context
) {
    val prefs = PrefsManager(context)
    private val clientDirectory = ClientDirectory(context)

    private val dateFormat = SimpleDateFormat("dd.MM.yy", Locale.getDefault())
    private val timeFormat = SimpleDateFormat("HH:mm", Locale.getDefault())
    private val syncMutex = Mutex()

    fun observeCalls(): Flow<List<CallEntity>> = callDao.observeAll()
    fun observeCallsByPhone(phone: String): Flow<List<CallEntity>> = callDao.observeByPhone(phone)
    fun observeContact(phone: String): Flow<ContactEntity?> = contactDao.observeByPhone(phone)
    fun observeReminders(phone: String): Flow<List<ReminderEntity>> = reminderDao.observeByPhone(phone)
    fun observeComments(phone: String): Flow<List<CommentEntity>> = commentDao.observeByPhone(phone)
    fun findClientName(phone: String): String {
        val clientName = clientDirectory.findClientName(phone)
        Log.d("CLIENT_SEARCH", "Phone: $phone → Client: $clientName")
        return clientName
    }

    suspend fun loadHistoryFromRemote(phone: String): List<CallHistoryItem> {
        val normalizedPhone = normalizePhone(phone)
        if (normalizedPhone.isBlank()) return emptyList()
        val separator = if (BuildConfig.WEBHOOK_URL.contains("?")) "&" else "?"
        val url = "${BuildConfig.WEBHOOK_URL}${separator}phone=$normalizedPhone"
        return runCatching { webhookApi.loadHistory(url) }
            .onFailure { Log.e("CallRepository", "Не удалось загрузить историю по телефону=$normalizedPhone", it) }
            .getOrElse { emptyList() }
    }

    suspend fun getHistory(phone: String): List<CallHistoryEntity> {
        val normalized = normalizePhone(phone)
        val cached = callHistoryDao.getByPhone(normalized)
        if (cached.isNotEmpty()) return cached

        val remote = loadHistoryFromRemote(normalized)
        callHistoryDao.insertAll(remote.map { it.toEntity(normalized) })
        return callHistoryDao.getByPhone(normalized)
    }

    suspend fun refreshHistory(phone: String) {
        val normalized = normalizePhone(phone)
        val remote = loadHistoryFromRemote(normalized)
        callHistoryDao.deleteByPhone(normalized)
        callHistoryDao.insertAll(remote.map { it.toEntity(normalized) })
    }

    suspend fun saveCall(call: CallEntity): Long {
        ensureContact(call.phone)
        val duplicate = callDao.findRecentDuplicate(
            phone = call.phone,
            type = call.type,
            duration = call.duration,
            timestamp = call.timestamp
        )
        if (duplicate != null) {
            Log.d("CallRepository", "Пропускаем дубль звонка, используем id=${duplicate.id}")
            return duplicate.id
        }
        return callDao.insert(call)
    }

    suspend fun saveCallOutcome(
        callId: Long,
        phone: String,
        contactName: String,
        tag: String,
        reminderMillis: Long?,
        note: String
    ) {
        ensureContact(phone)
        val reminderText = reminderMillis?.let { "${dateFormat.format(Date(it))} ${timeFormat.format(Date(it))}" }.orEmpty()
        callDao.updateOutcome(callId, note, tag, reminderText)

        if (note.isNotBlank()) {
            commentDao.insert(CommentEntity(phone = phone, text = note))
        }

        if (reminderMillis != null) {
            reminderDao.insert(
                ReminderEntity(
                    phone = phone,
                    contactName = contactName,
                    message = "Перезвонить",
                    remindAt = reminderMillis,
                    status = "Активно"
                )
            )
        }
    }


    suspend fun addComment(phone: String, text: String) {
        if (phone.isBlank() || text.isBlank()) return
        ensureContact(phone)
        commentDao.insert(CommentEntity(phone = phone, text = text))
    }

    suspend fun addReminder(phone: String, contactName: String, text: String, remindAt: Long) {
        if (phone.isBlank() || text.isBlank()) return
        ensureContact(phone)
        reminderDao.insert(
            ReminderEntity(
                phone = phone,
                contactName = contactName,
                message = text,
                remindAt = remindAt,
                status = "Активно"
            )
        )
    }


    suspend fun saveCommentForCall(callId: Long, phone: String, text: String) {
        if (text.isBlank()) return
        val call = callDao.getById(callId) ?: return
        callDao.updateOutcome(callId, text, call.tag, call.reminder)
        commentDao.insert(CommentEntity(phone = phone, text = text))
    }

    suspend fun saveReminderForCall(callId: Long, phone: String, contactName: String, text: String, remindAt: Long) {
        if (text.isBlank()) return
        val call = callDao.getById(callId) ?: return
        val reminderText = "${dateFormat.format(Date(remindAt))} ${timeFormat.format(Date(remindAt))} | $text"
        callDao.updateOutcome(callId, call.note, call.tag, reminderText)
        reminderDao.insert(
            ReminderEntity(
                phone = phone,
                contactName = contactName,
                message = text,
                remindAt = remindAt,
                status = "Активно"
            )
        )
    }

    suspend fun syncPending() {
        syncMutex.withLock {
            val managerName = prefs.getManagerName().ifBlank { "Не указан" }
            val pending = callDao.getPending()
            val groupedPending = pending.groupBy { entity ->
                // Антидубль: на некоторых устройствах один завершённый звонок может попасть в БД несколько раз
                // с очень близким timestamp. Группируем такие записи в 5-секундное окно и отправляем один webhook.
                SyncFingerprint(
                    phone = entity.phone,
                    type = entity.type,
                    duration = entity.duration,
                    note = entity.note.trim(),
                    tag = entity.tag.trim(),
                    reminder = entity.reminder.trim(),
                    timestampBucket = entity.timestamp / 5_000L
                )
            }

            groupedPending.values.forEach { duplicates ->
                val entity = duplicates.first()
                runCatching {
                    val clientName = findClientName(entity.phone)
                    val reminderText = extractReminderText(entity.reminder)
                    webhookApi.sendCall(
                        BuildConfig.WEBHOOK_URL,
                        WebhookRequest(
                            callId = entity.id,
                            date = dateFormat.format(Date(entity.timestamp)),
                            time = timeFormat.format(Date(entity.timestamp)),
                            phone = entity.phone,
                            type = entity.type,
                            duration = entity.duration,
                            manager = managerName,
                            note = entity.note,
                            tag = entity.tag,
                            reminder = entity.reminder,
                            reminderText = reminderText,
                            client = clientName
                        )
                    )
                    callDao.markUploaded(duplicates.map { it.id })
                    Log.d(
                        "CallRepository",
                        "Webhook sent once for ${duplicates.size} record(s): ids=${duplicates.joinToString { it.id.toString() }}, phone=${entity.phone}"
                    )
                }.onFailure {
                    Log.e("CallRepository", "Webhook send failed for id=${entity.id}", it)
                }
            }
        }
    }

    private suspend fun ensureContact(phone: String) {
        if (phone.isBlank() || phone == "Неизвестно") return
        val client1c = clientDirectory.findClientName(phone)
        val exists = contactDao.findByPhone(phone)
        if (exists == null) {
            contactDao.insert(
                ContactEntity(
                    phone = phone,
                    name = phone,
                    client1c = client1c
                )
            )
            return
        }

        if (exists.client1c.isBlank() && client1c.isNotBlank()) {
            contactDao.updateClient1c(exists.id, client1c)
        }
    }

    private fun extractReminderText(reminderValue: String): String {
        if (reminderValue.isBlank()) return ""
        return reminderValue.substringAfter("|", reminderValue).trim()
    }

    fun normalizePhone(phone: String): String = phone.filter { it.isDigit() }.takeLast(10)

    private fun CallHistoryItem.toEntity(phone: String): CallHistoryEntity {
        return CallHistoryEntity(
            phone = phone,
            date = date,
            time = time,
            type = type,
            duration = duration,
            manager = manager,
            note = note,
            tag = tag,
            reminder = reminder,
            reminderText = reminderText,
            client = client,
            updatedAt = System.currentTimeMillis()
        )
    }

    private data class SyncFingerprint(
        val phone: String,
        val type: String,
        val duration: Long,
        val note: String,
        val tag: String,
        val reminder: String,
        val timestampBucket: Long
    )
}
