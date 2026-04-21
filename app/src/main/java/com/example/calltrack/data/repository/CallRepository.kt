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

    private val dateFormat = SimpleDateFormat("dd.MM.yy", Locale.getDefault())
    private val timeFormat = SimpleDateFormat("HH:mm", Locale.getDefault())

    fun observeCalls(): Flow<List<CallEntity>> = callDao.observeAll()
    fun observeCallsByPhone(phone: String): Flow<List<CallEntity>> = callDao.observeByPhone(phone)
    fun observeContact(phone: String): Flow<ContactEntity?> = contactDao.observeByPhone(phone)
    fun observeReminders(phone: String): Flow<List<ReminderEntity>> = reminderDao.observeByPhone(phone)
    fun observeComments(phone: String): Flow<List<CommentEntity>> = commentDao.observeByPhone(phone)

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
                    remindAt = reminderMillis,
                    status = "Активно"
                )
            )
        }
    }

    suspend fun syncPending() {
        val managerName = prefs.getManagerName().ifBlank { "Не указан" }
        val pending = callDao.getPending()
        pending.forEach { entity ->
            runCatching {
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
                        tag = entity.tag,
                        reminder = entity.reminder
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
        val exists = contactDao.findByPhone(phone)
        if (exists == null) {
            contactDao.insert(ContactEntity(phone = phone, name = name.ifBlank { phone }))
        }
    }
}
