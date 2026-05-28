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
import com.google.gson.Gson
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.flow.Flow
import kotlinx.coroutines.sync.Mutex
import kotlinx.coroutines.sync.withLock
import kotlinx.coroutines.withContext
import okhttp3.MediaType.Companion.toMediaType
import okhttp3.OkHttpClient
import okhttp3.Request
import okhttp3.RequestBody.Companion.toRequestBody
import org.json.JSONArray
import org.json.JSONObject
import org.json.JSONTokener
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
    context: Context,
    private val notificationRepository: NotificationRepository? = null
) {
    private val appContext = context.applicationContext
    val prefs = PrefsManager(context)
    private val clientDirectory = ClientDirectory(context)

    private val dateFormat = SimpleDateFormat("dd.MM.yy", Locale.getDefault())
    private val timeFormat = SimpleDateFormat("HH:mm", Locale.getDefault())
    private val syncMutex = Mutex()
    private val personalContactsHttpClient = OkHttpClient()
    private val gson = Gson()

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

    suspend fun isPersonalContact(phone: String): Boolean {
        if (phone.isBlank() || phone == "Неизвестно") return false

        val direct = contactDao.findByPhone(phone)
        if (direct?.client1c == "Личный") return true

        val normalized = normalizePhone(phone)
        if (normalized.isBlank()) return false

        return contactDao.findAll().any { contact ->
            contact.client1c == "Личный" && normalizePhone(contact.phone) == normalized
        }
    }



    suspend fun saveAppNotification(
        title: String,
        message: String,
        type: NotificationType,
        targetScreen: String = "",
        entityId: String = "",
        payloadJson: String = ""
    ) {
        notificationRepository?.insertNotification(title, message, type, targetScreen, entityId, payloadJson)
    }

    suspend fun loadHistoryFromRemote(phone: String): List<CallHistoryItem> {
        val normalizedPhone = normalizePhone(phone)
        if (normalizedPhone.isBlank()) return emptyList()
        val separator = if (BuildConfig.WEBHOOK_URL.contains("?")) "&" else "?"
        val url = "${BuildConfig.WEBHOOK_URL}${separator}phone=$normalizedPhone"
        com.example.calltrack.logging.AppLogger.log(appContext, "API", "Запрос данных из таблицы")

        val retrofitResult = runCatching { webhookApi.loadHistory(url) }
            .onSuccess { com.example.calltrack.logging.AppLogger.log(appContext, "API", "Получено записей: ${it.size}") }
            .onFailure {
                Log.e("CallRepository", "Не удалось загрузить историю по телефону=$normalizedPhone", it)
                com.example.calltrack.logging.AppLogger.log(appContext, "ERROR", "Ошибка загрузки данных: ${it.message}")
            }
            .getOrElse { emptyList() }

        if (retrofitResult.isNotEmpty()) return retrofitResult

        return fetchHistoryFallback(url)
    }

    private suspend fun fetchHistoryFallback(url: String): List<CallHistoryItem> {
        return runCatching {
            withContext(Dispatchers.IO) {
                val request = Request.Builder().url(url).get().build()
                personalContactsHttpClient.newCall(request).execute().use { response ->
                    val body = response.body?.string().orEmpty()
                    com.example.calltrack.logging.AppLogger.log(appContext, "API", "RAW history response: ${body.take(500)}")
                    parseHistoryResponse(body)
                }
            }
        }.onFailure {
            Log.e("CallRepository", "Fallback загрузки истории не удался", it)
        }.getOrElse { emptyList() }
    }

    private fun parseHistoryResponse(raw: String): List<CallHistoryItem> {
        val text = raw.trim()
        if (text.isBlank()) return emptyList()
        if (text.startsWith("<")) {
            com.example.calltrack.logging.AppLogger.log(appContext, "API", "History response is HTML, fallback to empty list")
            return emptyList()
        }

        val arr = runCatching {
            val root = JSONTokener(text).nextValue()
            when (root) {
                is JSONArray -> root
                is JSONObject -> root.optJSONArray("data") ?: root.optJSONArray("rows") ?: JSONArray()
                else -> JSONArray()
            }
        }.onFailure {
            com.example.calltrack.logging.AppLogger.log(appContext, "ERROR", "Malformed history JSON: ${it.message}; raw=${text.take(500)}")
        }.getOrElse { JSONArray() }

        return buildList {
            for (i in 0 until arr.length()) {
                val o = arr.optJSONObject(i) ?: continue
                add(
                    CallHistoryItem(
                        date = o.optString("date"),
                        time = o.optString("time"),
                        phone = o.optString("phone"),
                        type = o.optString("type"),
                        duration = o.optString("duration"),
                        manager = o.optString("manager"),
                        note = o.optString("note"),
                        tag = o.optString("tag"),
                        reminder = o.optString("reminder"),
                        reminderText = o.optString("reminder_text"),
                        client = o.optString("client")
                    )
                )
            }
        }
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

    suspend fun getLatestSavedCallTimestamp(): Long = callDao.getLatestTimestamp() ?: 0L

    suspend fun saveCallOutcome(
        callId: Long,
        phone: String,
        contactName: String,
        tag: String,
        reminderMillis: Long?,
        note: String,
        reminderMessage: String = "Перезвонить"
    ) {
        ensureContact(phone)
        val reminderValue = reminderMillis?.let {
            val dateTime = "${dateFormat.format(Date(it))} ${timeFormat.format(Date(it))}"
            if (reminderMessage.isBlank()) dateTime else "$dateTime | $reminderMessage"
        }.orEmpty()
        callDao.updateOutcome(callId, note, tag, reminderValue)

        if (note.isNotBlank()) {
            commentDao.insert(CommentEntity(phone = phone, text = note))
        }

        if (reminderMillis != null) {
            reminderDao.insert(
                ReminderEntity(
                    phone = phone,
                    contactName = contactName,
                    message = reminderMessage.ifBlank { "Перезвонить" },
                    remindAt = reminderMillis,
                    status = "Активно"
                )
            )
        }
        syncPending()
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

    suspend fun markAsPersonalContact(phone: String) {
        if (phone.isBlank() || phone == "Неизвестно") return
        ensureContact(phone)
        contactDao.updateClient1cByPhone(phone, "Личный")
        syncPersonalContactToRemote(phone, true, enqueueOnFailure = true)
        flushPendingPersonalContactsSync()
    }

    suspend fun markCallsPendingForPhoneResync(phone: String) {
        if (phone.isBlank() || phone == "Неизвестно") return
        callDao.markPendingByPhone(phone)
        syncPending()
    }

    suspend fun unmarkPersonalContact(phone: String) {
        if (phone.isBlank() || phone == "Неизвестно") return
        ensureContact(phone)
        contactDao.updateClient1cByPhone(phone, "")
        syncPersonalContactToRemote(phone, false, enqueueOnFailure = true)
        flushPendingPersonalContactsSync()
    }

    private suspend fun syncPersonalContactToRemote(phone: String, isPersonal: Boolean, enqueueOnFailure: Boolean): Boolean {
        val managerPhone = prefs.getManagerPhone().ifBlank { return false }
        val managerName = prefs.getManagerName().ifBlank { "Не указан" }
        val normalizedManagerPhone = normalizePhone(managerPhone)
        val normalizedContactPhone = normalizePhone(phone)
        val payload = JSONObject().apply {
            put("manager_phone", normalizedManagerPhone)
            put("manager_name", managerName)
            put("contact_phone", normalizedContactPhone)
            put("is_personal", if (isPersonal) "1" else "0")
        }
        val body = payload.toString().toRequestBody("application/json; charset=utf-8".toMediaType())
        val request = Request.Builder()
            .url(BuildConfig.PERSONAL_CONTACTS_WEBHOOK_URL)
            .post(body)
            .build()
        return runCatching {
            withContext(Dispatchers.IO) {
                personalContactsHttpClient.newCall(request).execute().use { response ->
                    com.example.calltrack.logging.AppLogger.log(appContext, "API", "Ответ сервера: ${response.code}")
                    response.isSuccessful
                }
            }
        }.getOrElse {
            com.example.calltrack.logging.AppLogger.log(appContext, "ERROR", "Ошибка отправки: ${it.message}")
            Log.e("CallRepository", "Не удалось отправить личный контакт в Calltrack_mop", it)
            if (enqueueOnFailure) {
                enqueuePendingPersonalSync(
                    PersonalSyncItem(
                        managerPhone = normalizedManagerPhone,
                        managerName = managerName,
                        contactPhone = normalizedContactPhone,
                        isPersonal = isPersonal
                    )
                )
            }
            false
        }
    }

    private suspend fun flushPendingPersonalContactsSync() {
        val pending = readPendingPersonalSync()
        if (pending.isEmpty()) return
        val stillPending = mutableListOf<PersonalSyncItem>()
        pending.forEach { item ->
            val ok = syncPersonalContactToRemote(item.contactPhone, item.isPersonal, enqueueOnFailure = false)
            if (!ok) stillPending.add(item)
        }
        writePendingPersonalSync(stillPending)
    }

    private suspend fun enqueuePendingPersonalSync(item: PersonalSyncItem) {
        val list = readPendingPersonalSync().toMutableList()
        list.removeAll { it.managerPhone == item.managerPhone && it.contactPhone == item.contactPhone }
        list.add(item)
        writePendingPersonalSync(list)
    }

    private suspend fun readPendingPersonalSync(): List<PersonalSyncItem> {
        val raw = prefs.getPendingPersonalSync()
        if (raw.isBlank()) return emptyList()
        return runCatching {
            val arr = JSONArray(raw)
            buildList {
                for (i in 0 until arr.length()) {
                    val o = arr.optJSONObject(i) ?: continue
                    add(
                        PersonalSyncItem(
                            managerPhone = o.optString("manager_phone"),
                            managerName = o.optString("manager_name"),
                            contactPhone = o.optString("contact_phone"),
                            isPersonal = o.optBoolean("is_personal", true)
                        )
                    )
                }
            }
        }.getOrElse { emptyList() }
    }

    private suspend fun writePendingPersonalSync(items: List<PersonalSyncItem>) {
        val arr = JSONArray()
        items.forEach { item ->
            arr.put(
                JSONObject().apply {
                    put("manager_phone", item.managerPhone)
                    put("manager_name", item.managerName)
                    put("contact_phone", item.contactPhone)
                    put("is_personal", item.isPersonal)
                }
            )
        }
        prefs.setPendingPersonalSync(arr.toString())
    }


    suspend fun saveCommentForCall(callId: Long, phone: String, text: String) {
        if (text.isBlank()) return
        val call = callDao.getById(callId) ?: return
        callDao.updateOutcome(callId, text, call.tag, call.reminder)
        commentDao.insert(CommentEntity(phone = phone, text = text))
        syncPending()
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
        syncPending()
    }

    suspend fun syncPending() {
        syncMutex.withLock {
            val managerName = prefs.getManagerName().ifBlank { "Не указан" }
            val managerPhone = prefs.getManagerPhone().ifBlank { "Не указан" }
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
                Log.d("WEBHOOK", "Отправка webhook: $entity")
                runCatching {
                    val personalMarked = contactDao.findByPhone(entity.phone)?.client1c == "Личный"
                    val clientName = if (personalMarked) "Личный звонок" else findClientName(entity.phone)
                    val reminderText = extractReminderText(entity.reminder)
                    com.example.calltrack.logging.AppLogger.log(appContext, "API", "Отправка данных в таблицу: id=${entity.id}, phone=${entity.phone}, type=${entity.type}")
                    val payload = WebhookRequest(
                        callId = "${entity.id}_${entity.timestamp}",
                        date = dateFormat.format(Date(entity.timestamp)),
                        time = timeFormat.format(Date(entity.timestamp)),
                        phone = normalizePhone(entity.phone),
                        type = entity.type,
                        duration = entity.duration,
                        manager = managerName,
                        userPhone = managerPhone,
                        note = entity.note,
                        tag = entity.tag,
                        reminder = entity.reminder,
                        reminderText = reminderText,
                        client = clientName
                    )

                    val response = webhookApi.sendCall(BuildConfig.WEBHOOK_URL, payload)
                    val bodyText = response.body()?.string().orEmpty().ifBlank { response.errorBody()?.string().orEmpty() }
                    var accepted = isWebhookAccepted(response.isSuccessful, bodyText)

                    if (!accepted) {
                        com.example.calltrack.logging.AppLogger.log(
                            appContext,
                            "API",
                            "Retrofit webhook rejected, fallback OkHttp: code=${response.code()}, body=${bodyText.take(400)}"
                        )
                        val (fallbackCode, fallbackBody) = sendCallViaOkHttp(payload)
                        accepted = isWebhookAccepted(fallbackCode in 200..299, fallbackBody)
                        if (!accepted) {
                            throw IllegalStateException(
                                "Calltrack webhook rejected (retrofit+fallback): retrofitCode=${response.code()}, fallbackCode=$fallbackCode, fallbackBody=${fallbackBody.take(400)}"
                            )
                        }
                    }

                    callDao.markUploaded(duplicates.map { it.id })
                    com.example.calltrack.logging.AppLogger.log(appContext, "API", "Ответ сервера: code=${response.code()}")
                    com.example.calltrack.logging.AppLogger.log(appContext, "API", "CALL MARKED AS SYNCED: ids=${duplicates.joinToString { it.id.toString() }}")
                    Log.d(
                        "CallRepository",
                        "Webhook sent once for ${duplicates.size} record(s): ids=${duplicates.joinToString { it.id.toString() }}, phone=${entity.phone}"
                    )
                }.onSuccess {
                    Log.d("WEBHOOK", "Отправлено: phone=${entity.phone}, id=${entity.id}")
                }.onFailure {
                    Log.e("WEBHOOK", "Ошибка при вызове webhookApi.sendCall", it)
                    Log.e("WEBHOOK", "Ошибка отправки: id=${entity.id}", it)
                    Log.e("CallRepository", "Webhook send failed for id=${entity.id}", it)
                    com.example.calltrack.logging.AppLogger.log(appContext, "ERROR", "Ошибка отправки: ${it.message}")
                    com.example.calltrack.logging.AppLogger.log(appContext, "API", "Повторная отправка данных")
                }
            }
        }
    }


    private suspend fun sendCallViaOkHttp(requestBody: WebhookRequest): Pair<Int, String> = withContext(Dispatchers.IO) {
        val json = gson.toJson(requestBody)
        val request = Request.Builder()
            .url(BuildConfig.WEBHOOK_URL)
            .post(json.toRequestBody("application/json; charset=utf-8".toMediaType()))
            .build()

        personalContactsHttpClient.newCall(request).execute().use { response ->
            val text = response.body?.string().orEmpty()
            response.code to text
        }
    }

    private fun isWebhookAccepted(isSuccessful: Boolean, bodyText: String): Boolean {
        if (!isSuccessful) return false
        val normalized = bodyText.lowercase(Locale.getDefault())
        if (normalized.contains("не удалось найти функцию скрипта")) return false
        if (normalized.contains("ошибка")) return false
        return true
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

    private data class PersonalSyncItem(
        val managerPhone: String,
        val managerName: String,
        val contactPhone: String,
        val isPersonal: Boolean
    )
}
