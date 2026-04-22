package com.example.calltrack.data.repository

import android.content.Context
import android.util.Log
import com.example.calltrack.BuildConfig
import com.example.calltrack.data.local.CallDao
import com.example.calltrack.data.local.CallEntity
import com.example.calltrack.data.local.CommentDao
import com.example.calltrack.data.local.CommentEntity
import com.example.calltrack.data.local.ContactDao
import com.example.calltrack.data.local.ContactEntity
import com.example.calltrack.data.local.ReminderDao
import com.example.calltrack.data.local.ReminderEntity
import com.example.calltrack.data.remote.WebhookApi
import com.example.calltrack.data.remote.WebhookRequest
import kotlinx.coroutines.flow.Flow
import java.text.SimpleDateFormat
import java.util.Date
import java.util.Locale

class CallRepository(
    private val callDao: CallDao,
    private val contactDao: ContactDao,
    private val reminderDao: ReminderDao,
    private val commentDao: CommentDao,
    private val webhookApi: WebhookApi,
    context: Context
) {
    val prefs = PrefsManager(context)
    private val clientDirectory = ClientDirectory(context)

    private val dateFormat = SimpleDateFormat("dd.MM.yy", Locale.getDefault())
    private val timeFormat = SimpleDateFormat("HH:mm", Locale.getDefault())

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

    suspend fun saveCall(call: CallEntity): Long {
        ensureContact(call.phone)
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
        ensureContact(phone, contactName)
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
        ensureContact(phone, contactName)
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
        val managerName = prefs.getManagerName().ifBlank { "Не указан" }
        val pending = callDao.getPending()
        pending.forEach { entity ->
            runCatching {
                val clientName = findClientName(entity.phone)
                webhookApi.sendCall(
                    BuildConfig.WEBHOOK_URL,
                    WebhookRequest(
                        date = dateFormat.format(Date(entity.timestamp)),
                        time = timeFormat.format(Date(entity.timestamp)),
                        phone = entity.phone,
                        type = entity.type,
                        duration = entity.duration,
                        manager = managerName,
                        comment = entity.note,
                        note = entity.note,
                        tag = entity.tag,
                        reminder = entity.reminder,
                        client = clientName
                    )
                )
                callDao.markUploaded(entity.id)
                Log.d("CallRepository", "Webhook sent: id=${entity.id}, phone=${entity.phone}")
            }.onFailure {
                Log.e("CallRepository", "Webhook send failed for id=${entity.id}", it)
            }
        }
    }

    private suspend fun ensureContact(phone: String, name: String = "") {
        if (phone.isBlank() || phone == "Неизвестно") return
        val client1c = clientDirectory.findClientName(phone)
        val exists = contactDao.findByPhone(phone)
        if (exists == null) {
            contactDao.insert(
                ContactEntity(
                    phone = phone,
                    name = name.ifBlank { phone },
                    client1c = client1c
                )
            )
            return
        }

        if (exists.client1c.isBlank() && client1c.isNotBlank()) {
            contactDao.updateClient1c(exists.id, client1c)
        }
    }
}
