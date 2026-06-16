package com.example.calltrack.data.repository

import android.Manifest
import android.content.Context
import android.content.pm.PackageManager
import android.provider.CallLog
import android.util.Log
import androidx.core.content.ContextCompat
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
import java.net.URLEncoder
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
    private val appContext = context.applicationContext
    val prefs = PrefsManager(context)
    private val clientDirectory = ClientDirectory(context)

    private val dateFormat = SimpleDateFormat("dd.MM.yyyy", Locale.getDefault())
    private val timeFormat = SimpleDateFormat("HH:mm", Locale.getDefault())
    private val syncMutex = Mutex()
    private val personalContactsHttpClient = OkHttpClient()

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

    suspend fun loadHistoryFromRemote(phone: String): List<CallHistoryItem> {
        val normalizedPhone = normalizePhone(phone)
        val apiPhone = normalizePhoneForHistoryApi(phone)
        if (normalizedPhone.isBlank() && apiPhone.isBlank()) return emptyList()

        val managerPhone = normalizePhone(prefs.getManagerPhone())
        val apiUserPhone = normalizePhoneForHistoryApi(prefs.getManagerPhone())
        Log.d(
            HISTORY_LOG_TAG,
            "Начало загрузки истории из Calltrack: rawPhone=$phone, normalizedPhone=$normalizedPhone, apiPhone=$apiPhone, " +
                "managerPhone=$managerPhone, apiUserPhone=$apiUserPhone"
        )
        com.example.calltrack.logging.AppLogger.log(appContext, "API", "Запрос истории звонков из таблицы Calltrack")

        val sqlUrl = buildSqlHistoryUrl(apiPhone.ifBlank { normalizedPhone }, apiUserPhone.ifBlank { managerPhone })
        val sqlLoaded = fetchHistoryFromUrl(sqlUrl, normalizedPhone)
        if (sqlLoaded.isNotEmpty()) {
            Log.d(HISTORY_LOG_TAG, "Успешная загрузка истории из SQL API: url=$sqlUrl, records=${sqlLoaded.size}")
            return sqlLoaded
        }

        // LEGACY_GAS: старые Apps Script URL оставлены только как fallback на период миграции на SQL API.
        for (url in buildLegacyGasCallHistoryUrls(normalizedPhone, apiPhone, managerPhone, apiUserPhone)) {
            val loaded = fetchHistoryFromUrl(url, normalizedPhone)
            if (loaded.isNotEmpty()) {
                Log.d(HISTORY_LOG_TAG, "Успешная загрузка истории через LEGACY_GAS: url=$url, records=${loaded.size}")
                return loaded
            }
        }

        Log.d(HISTORY_LOG_TAG, "История из SQL API и LEGACY_GAS не найдена: rawPhone=$phone, normalizedPhone=$normalizedPhone, apiPhone=$apiPhone")
        return emptyList()
    }

    private fun buildSqlHistoryUrl(phone: String, userPhone: String): String {
        val query = "phone=${urlEncode(phone)}&user_phone=${urlEncode(userPhone)}"
        return sqlApiUrl("get_history.php") + "?" + query
    }

    private fun buildLegacyGasCallHistoryUrls(
        normalizedPhone: String,
        apiPhone: String,
        managerPhone: String,
        apiUserPhone: String
    ): List<String> {
        // LEGACY_GAS: старый Apps Script endpoint используется только как fallback.
        val baseUrl = BuildConfig.WEBHOOK_URL
        val separator = if (baseUrl.contains("?")) "&" else "?"
        val contactPhones = listOf(normalizedPhone, apiPhone).filter { it.isNotBlank() }.distinct()
        val userPhones = listOf(managerPhone, apiUserPhone).filter { it.isNotBlank() }.distinct()
        val userQueries = mutableListOf("")
        userPhones.forEach { phone ->
            userQueries += "manager_phone=$phone&"
            userQueries += "user_phone=$phone&"
        }

        val urls = mutableListOf<String>()
        userQueries.distinct().forEach { userQuery ->
            contactPhones.forEach { contactPhone ->
                // Основные варианты для скрипта таблицы Calltrack.
                urls += "${baseUrl}${separator}${userQuery}phone=$contactPhone"
                urls += "${baseUrl}${separator}${userQuery}contact_phone=$contactPhone"
                // Запасные варианты на случай, если опубликованный doGet ожидает action.
                urls += "${baseUrl}${separator}${userQuery}action=history&phone=$contactPhone"
                urls += "${baseUrl}${separator}${userQuery}action=history&contact_phone=$contactPhone"
            }
        }
        return urls.distinct()
    }

    private suspend fun fetchHistoryFromUrl(url: String, normalizedPhone: String): List<CallHistoryItem> {
        Log.d(HISTORY_LOG_TAG, "URL запроса истории: $url")
        val result: Result<List<CallHistoryItem>> = runCatching {
            withContext(Dispatchers.IO) {
                val request = Request.Builder().url(url).get().build()
                val response = personalContactsHttpClient.newCall(request).execute()
                try {
                    val body = response.body?.string().orEmpty()
                    Log.d(HISTORY_LOG_TAG, "Код ответа истории: code=${response.code}, url=$url")
                    Log.d(HISTORY_LOG_TAG, "Полный JSON ответа истории: $body")
                    com.example.calltrack.logging.AppLogger.log(appContext, "API", "RAW Calltrack history response: ${body.take(500)}")

                    val parsed: List<CallHistoryItem> = parseHistoryResponse(body)
                    Log.d(HISTORY_LOG_TAG, "Количество записей после парсинга: ${parsed.size}, url=$url")
                    val filtered: List<CallHistoryItem> = parsed.filterForHistoryScreen(normalizedPhone)
                    Log.d(HISTORY_LOG_TAG, "Количество записей после фильтрации: ${filtered.size}, url=$url")
                    filtered
                } finally {
                    response.close()
                }
            }
        }
        result.onFailure {
            Log.e(HISTORY_LOG_TAG, "Ошибка HTTP/coroutine/Gson при загрузке истории: url=$url", it)
            Log.e("CallRepository", "Fallback загрузки истории из Calltrack не удался", it)
            com.example.calltrack.logging.AppLogger.log(appContext, "ERROR", "Ошибка загрузки истории: ${it.message}")
        }
        return result.getOrElse { emptyList<CallHistoryItem>() }
    }

    private fun parseHistoryResponse(raw: String): List<CallHistoryItem> {
        val text = raw.trim()
        if (text.isBlank()) return emptyList()
        if (text.startsWith("<")) return emptyList()

        val token = runCatching { JSONTokener(text).nextValue() }.getOrNull() ?: return emptyList()
        val arr = when (token) {
            is JSONArray -> token
            is JSONObject -> token.firstArray("data", "rows", "history", "calls", "items", "result") ?: JSONArray()
            else -> JSONArray()
        }

        val items = mutableListOf<CallHistoryItem>()
        for (i in 0 until arr.length()) {
            val row = arr.opt(i)
            val item = when (row) {
                is JSONObject -> row.toCallHistoryItem()
                is JSONArray -> row.toCallHistoryItem()
                else -> null
            }
            if (item != null) items += item
        }
        return items
    }

    private fun JSONObject.toCallHistoryItem(): CallHistoryItem {
        return CallHistoryItem(
            date = firstString("date", "call_date", "Дата"),
            time = firstString("time", "call_time", "Время"),
            phone = firstString("phone", "contact_phone", "Номер телефона", "Телефон"),
            type = firstString("type", "call_type", "Тип звонка", "Тип"),
            duration = firstString("duration", "Длительность"),
            manager = firstString("manager", "Менеджер"),
            note = firstString("note", "comment", "comments", "comment_text", "Комментарий", "Комментарии", "Коментарий", "Коментарии"),
            tag = firstString("tag", "tags", "Тег", "Теги"),
            reminder = firstString("reminder", "reminders", "Напоминание", "Напоминания"),
            reminderText = firstString("reminder_text", "reminderText", "reminder_texts", "Текст напоминания", "Тексты напоминаний"),
            client = firstString("client", "Клиент"),
            callId = firstString("call_id", "ID", "id"),
            userPhone = firstString("user_phone", "manager_phone", "Номер телефона пользователя")
        )
    }

    private fun JSONArray.toCallHistoryItem(): CallHistoryItem? {
        if (length() < CALL_HISTORY_MIN_ARRAY_COLUMNS) return null
        return CallHistoryItem(
            date = optString(0),
            time = optString(1),
            phone = optString(2),
            type = optString(3),
            duration = optString(4),
            manager = optString(5),
            note = optString(6),
            tag = optString(7),
            reminder = optString(8),
            reminderText = optString(9),
            client = optString(10)
        )
    }

    private fun JSONObject.firstArray(vararg keys: String): JSONArray? {
        keys.forEach { key -> optJSONArray(key)?.let { return it } }
        return null
    }

    private fun JSONObject.firstString(vararg keys: String): String {
        keys.forEach { key ->
            if (has(key) && !isNull(key)) {
                val value = optString(key).trim()
                if (value.isNotBlank()) return value
            }
        }
        return ""
    }

    private fun List<CallHistoryItem>.filterForHistoryScreen(normalizedPhone: String): List<CallHistoryItem> {
        return filter { item ->
            // Пустые строки появляются, когда Retrofit получил объекты с русскими названиями колонок
            // и не смог разложить их по @SerializedName. Такие строки отбрасываем и даём fallback-парсеру
            // прочитать колонки «Комментарии» и «Напоминания» вручную.
            if (!item.hasHistoryContent()) return@filter false
            if (item.isHeaderRow()) return@filter false

            val itemPhone = normalizePhone(item.phone)
            itemPhone.isBlank() || itemPhone == normalizedPhone
        }
    }

    private fun CallHistoryItem.hasHistoryContent(): Boolean {
        return listOf(date, time, phone, type, duration, manager, note, tag, reminder, reminderText, client, callId, userPhone)
            .any { it.isNotBlank() }
    }

    private fun CallHistoryItem.isHeaderRow(): Boolean {
        return normalizeHeader(date) == "дата" ||
            normalizeHeader(phone) in setOf("номертелефона", "телефон", "phone", "contactphone") ||
            normalizeHeader(note) in setOf("комментарий", "комментарии", "коментарий", "коментарии", "comment", "comments") ||
            normalizeHeader(reminder) in setOf("напоминание", "напоминания", "reminder", "reminders")
    }

    private fun normalizeHeader(value: String): String {
        return value.filter { it.isLetterOrDigit() }.lowercase(Locale.getDefault())
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

    suspend fun getStoredReminders(phone: String): List<ReminderEntity> = withContext(Dispatchers.IO) {
        val normalizedPhone = normalizePhone(phone)
        if (normalizedPhone.isBlank()) return@withContext emptyList<ReminderEntity>()

        reminderDao.getAllOnce()
            .filter { reminder -> normalizePhone(reminder.phone) == normalizedPhone && reminder.message.isNotBlank() }
            .sortedByDescending { it.remindAt }
    }

    suspend fun refreshRemindersFromRemote(phone: String): List<ReminderEntity> = withContext(Dispatchers.IO) {
        val normalizedPhone = normalizePhone(phone)
        if (normalizedPhone.isBlank()) return@withContext emptyList<ReminderEntity>()

        Log.d(HISTORY_LOG_TAG, "Начало импорта напоминаний из Calltrack во внутреннюю память: phone=$phone, normalized=$normalizedPhone")
        val remoteItems = loadHistoryFromRemote(phone)
        val remoteReminders = remoteItems
            .mapNotNull { item -> item.toReminderEntity(normalizedPhone) }
        Log.d(HISTORY_LOG_TAG, "Напоминаний из таблицы после парсинга: ${remoteReminders.size}, phone=$phone")

        val existingFingerprints = reminderDao.getAllOnce()
            .filter { reminder -> normalizePhone(reminder.phone) == normalizedPhone }
            .map { reminder -> reminder.reminderFingerprint() }
            .toMutableSet()

        var inserted = 0
        remoteReminders.forEach { reminder ->
            val fingerprint = reminder.reminderFingerprint()
            if (fingerprint !in existingFingerprints) {
                // Напоминания из Google Sheets сохраняем во внутреннюю БД,
                // чтобы история напоминаний восстанавливалась после переустановки приложения.
                reminderDao.insert(reminder)
                existingFingerprints += fingerprint
                inserted++
            }
        }
        Log.d(HISTORY_LOG_TAG, "Импорт напоминаний завершён: inserted=$inserted, totalRemote=${remoteReminders.size}, phone=$phone")
        getStoredReminders(normalizedPhone)
    }

    suspend fun getStoredComments(phone: String): List<CommentEntity> = withContext(Dispatchers.IO) {
        val normalizedPhone = normalizePhone(phone)
        if (normalizedPhone.isBlank()) return@withContext emptyList<CommentEntity>()

        commentDao.getAllOnce()
            .filter { comment -> normalizePhone(comment.phone) == normalizedPhone && comment.text.isNotBlank() }
            .sortedByDescending { it.createdAt }
    }

    suspend fun refreshCommentsFromRemote(phone: String): List<CommentEntity> = withContext(Dispatchers.IO) {
        val normalizedPhone = normalizePhone(phone)
        if (normalizedPhone.isBlank()) return@withContext emptyList<CommentEntity>()

        Log.d(HISTORY_LOG_TAG, "Начало импорта комментариев из Calltrack во внутреннюю память: phone=$phone, normalized=$normalizedPhone")
        val remoteItems = loadHistoryFromRemote(phone)
        val remoteComments = remoteItems
            .filter { item -> item.note.isNotBlank() }
            .map { item ->
                CommentEntity(
                    phone = normalizedPhone,
                    text = item.note.trim(),
                    createdAt = parseHistoryTimestamp(item.date, item.time)
                )
            }
        Log.d(HISTORY_LOG_TAG, "Комментариев из таблицы после парсинга: ${remoteComments.size}, phone=$phone")

        val existingFingerprints = commentDao.getAllOnce()
            .filter { comment -> normalizePhone(comment.phone) == normalizedPhone }
            .map { comment -> comment.commentFingerprint() }
            .toMutableSet()

        var inserted = 0
        remoteComments.forEach { comment ->
            val fingerprint = comment.commentFingerprint()
            if (fingerprint !in existingFingerprints) {
                // Комментарии из Google Sheets сохраняем во внутреннюю БД, чтобы экран истории
                // открывался из памяти приложения даже без повторного сетевого запроса.
                commentDao.insert(comment)
                existingFingerprints += fingerprint
                inserted++
            }
        }
        Log.d(HISTORY_LOG_TAG, "Импорт комментариев завершён: inserted=$inserted, totalRemote=${remoteComments.size}, phone=$phone")
        getStoredComments(normalizedPhone)
    }

    suspend fun getDeviceCallHistory(phone: String, limit: Int = DEVICE_CONTACT_HISTORY_LIMIT): List<CallHistoryEntity> =
        withContext(Dispatchers.IO) {
            val normalizedPhone = normalizePhone(phone)
            if (normalizedPhone.isBlank()) return@withContext emptyList()
            if (ContextCompat.checkSelfPermission(appContext, Manifest.permission.READ_CALL_LOG) != PackageManager.PERMISSION_GRANTED) {
                Log.w("CallRepository", "Нет разрешения READ_CALL_LOG для истории звонков карточки контакта")
                return@withContext emptyList()
            }

            val projection = arrayOf(
                CallLog.Calls.NUMBER,
                CallLog.Calls.TYPE,
                CallLog.Calls.DURATION,
                CallLog.Calls.DATE
            )
            val result = mutableListOf<CallHistoryEntity>()

            runCatching {
                appContext.contentResolver.query(
                    CallLog.Calls.CONTENT_URI,
                    projection,
                    null,
                    null,
                    "${CallLog.Calls.DATE} DESC"
                )?.use { cursor ->
                    val numberIdx = cursor.getColumnIndexOrThrow(CallLog.Calls.NUMBER)
                    val typeIdx = cursor.getColumnIndexOrThrow(CallLog.Calls.TYPE)
                    val durationIdx = cursor.getColumnIndexOrThrow(CallLog.Calls.DURATION)
                    val dateIdx = cursor.getColumnIndexOrThrow(CallLog.Calls.DATE)

                    while (cursor.moveToNext() && result.size < limit) {
                        val rawPhone = cursor.getString(numberIdx).orEmpty()
                        if (normalizePhone(rawPhone) != normalizedPhone) continue

                        val duration = cursor.getLong(durationIdx)
                        val timestamp = cursor.getLong(dateIdx)
                        val (type, note) = mapDeviceCallType(cursor.getInt(typeIdx), duration)
                        // Карточка контакта должна показывать историю из стандартной звонилки Android,
                        // поэтому формируем элементы экрана напрямую из CallLog, без чтения Google Sheets.
                        result += CallHistoryEntity(
                            phone = normalizedPhone,
                            date = dateFormat.format(Date(timestamp)),
                            time = timeFormat.format(Date(timestamp)),
                            type = type,
                            duration = duration.toString(),
                            manager = "",
                            note = note,
                            tag = "",
                            reminder = "",
                            reminderText = "",
                            client = "",
                            updatedAt = timestamp
                        )
                    }
                }
            }.onFailure {
                Log.e("CallRepository", "Не удалось загрузить историю звонков контакта из стандартной звонилки", it)
            }

            result
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
            // Если дубль был заранее подтянут из системной звонилки как историческая запись,
            // он мог быть помечен uploaded=true. При реальном завершении звонка возвращаем его в очередь,
            // чтобы syncPending отправил запись в Google Sheets.
            callDao.markPending(duplicate.id)
            Log.d("CallRepository", "Пропускаем дубль звонка, используем id=${duplicate.id} и ставим его в очередь отправки")
            return duplicate.id
        }
        return callDao.insert(call)
    }

    suspend fun importRecentCallsFromDevice(limit: Int = DEVICE_RECENT_CALLS_LIMIT): Int = withContext(Dispatchers.IO) {
        if (ContextCompat.checkSelfPermission(appContext, Manifest.permission.READ_CALL_LOG) != PackageManager.PERMISSION_GRANTED) {
            Log.w("CallRepository", "Нет разрешения READ_CALL_LOG для загрузки экрана Последние из звонилки")
            return@withContext 0
        }

        val projection = arrayOf(
            CallLog.Calls.NUMBER,
            CallLog.Calls.TYPE,
            CallLog.Calls.DURATION,
            CallLog.Calls.DATE
        )
        var scanned = 0

        runCatching {
            appContext.contentResolver.query(
                CallLog.Calls.CONTENT_URI,
                projection,
                null,
                null,
                "${CallLog.Calls.DATE} DESC"
            )?.use { cursor ->
                val numberIdx = cursor.getColumnIndexOrThrow(CallLog.Calls.NUMBER)
                val typeIdx = cursor.getColumnIndexOrThrow(CallLog.Calls.TYPE)
                val durationIdx = cursor.getColumnIndexOrThrow(CallLog.Calls.DURATION)
                val dateIdx = cursor.getColumnIndexOrThrow(CallLog.Calls.DATE)

                while (cursor.moveToNext() && scanned < limit) {
                    val phone = cursor.getString(numberIdx).orEmpty().ifBlank { "Неизвестно" }
                    val duration = cursor.getLong(durationIdx)
                    val timestamp = cursor.getLong(dateIdx)
                    val (type, note) = mapDeviceCallType(cursor.getInt(typeIdx), duration)
                    val call = CallEntity(
                        phone = phone,
                        type = type,
                        duration = duration,
                        note = note,
                        timestamp = timestamp,
                        // Исторические записи из звонилки нужны для отображения на экране «Последние».
                        // Не отправляем их пачкой в Google Sheets, пока пользователь не изменит заметку/напоминание.
                        uploaded = true
                    )
                    val duplicate = callDao.findRecentDuplicate(
                        phone = call.phone,
                        type = call.type,
                        duration = call.duration,
                        timestamp = call.timestamp
                    )
                    if (duplicate == null) {
                        // Экран «Последние» должен брать звонки из системной звонилки,
                        // поэтому не прогреваем справочник клиентов из Google Sheets при импорте.
                        callDao.insert(call)
                    }
                    scanned++
                }
            }
        }.onFailure {
            Log.e("CallRepository", "Не удалось загрузить последние звонки из системной звонилки", it)
        }

        scanned
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
        syncCallById(callId)
    }


    suspend fun addComment(phone: String, text: String) {
        if (phone.isBlank() || text.isBlank()) return
        ensureContact(phone)
        commentDao.insert(CommentEntity(phone = phone, text = text))

        val latestCall = findLatestCallForPhone(phone)
        if (latestCall == null) {
            Log.w("CallRepository", "Комментарий сохранён локально, но звонок для отправки в таблицу не найден: phone=$phone")
            return
        }

        // Комментарий из карточки контакта привязываем к последнему звонку этого номера,
        // чтобы в таблице обновилась колонка «Комментарий» в строке с конкретным call_id.
        callDao.updateOutcome(latestCall.id, text, latestCall.tag, latestCall.reminder)
        syncCallById(latestCall.id)
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

        val latestCall = findLatestCallForPhone(phone)
        if (latestCall == null) {
            Log.w("CallRepository", "Напоминание сохранено локально, но звонок для отправки в таблицу не найден: phone=$phone")
            return
        }

        val reminderValue = "${dateFormat.format(Date(remindAt))} ${timeFormat.format(Date(remindAt))} | $text"
        // Напоминание из карточки контакта привязываем к последнему звонку этого номера,
        // чтобы в таблице обновились колонки «Напоминание»/«Текст напоминания» по call_id.
        callDao.updateOutcome(latestCall.id, latestCall.note, latestCall.tag, reminderValue)
        syncCallById(latestCall.id)
    }

    suspend fun markAsPersonalContact(phone: String) {
        if (phone.isBlank() || phone == "Неизвестно") return
        ensureContact(phone)
        updatePersonalContactLocal(phone, isPersonal = true)
        syncPersonalContactToRemote(phone, true, enqueueOnFailure = true)
        val pendingCount = markCallsPendingForNormalizedPhone(phone)
        Log.d("CallRepository", "Личный контакт: поставили в очередь $pendingCount звонков для обновления колонки Клиент")
        syncPending()
        flushPendingPersonalContactsSync()
    }

    suspend fun markCallsPendingForPhoneResync(phone: String) {
        if (phone.isBlank() || phone == "Неизвестно") return
        markCallsPendingForNormalizedPhone(phone)
        syncPending()
    }

    suspend fun unmarkPersonalContact(phone: String) {
        if (phone.isBlank() || phone == "Неизвестно") return
        ensureContact(phone)
        updatePersonalContactLocal(phone, isPersonal = false)
        syncPersonalContactToRemote(phone, false, enqueueOnFailure = true)
        val pendingCount = markCallsPendingForNormalizedPhone(phone)
        Log.d("CallRepository", "Личный контакт снят: поставили в очередь $pendingCount звонков для очистки колонки Клиент")
        syncPending()
        flushPendingPersonalContactsSync()
    }

    private suspend fun updatePersonalContactLocal(phone: String, isPersonal: Boolean) {
        val normalizedPhone = normalizePhone(phone)
        val value = if (isPersonal) "Личный" else ""
        contactDao.updateClient1cByPhone(phone, value)
        contactDao.findAll()
            .filter { contact -> normalizePhone(contact.phone) == normalizedPhone }
            .forEach { contact -> contactDao.updateClient1c(contact.id, value) }
    }

    private suspend fun markCallsPendingForNormalizedPhone(phone: String): Int {
        val normalizedPhone = normalizePhone(phone)
        if (normalizedPhone.isBlank()) return 0
        val calls = callDao.getAllOnce().filter { call -> normalizePhone(call.phone) == normalizedPhone }
        calls.forEach { call -> callDao.markPending(call.id) }
        return calls.size
    }

    private suspend fun syncPersonalContactToRemote(phone: String, isPersonal: Boolean, enqueueOnFailure: Boolean): Boolean {
        val managerPhone = prefs.getManagerPhone().ifBlank { return false }
        val managerName = prefs.getManagerName().ifBlank { "Не указан" }
        val normalizedManagerPhone = normalizePhone(managerPhone)
        val normalizedContactPhone = normalizePhone(phone)
        val personalFlag = if (isPersonal) 1 else 0
        val clientValue = if (isPersonal) PERSONAL_CALL_CLIENT_VALUE else ""
        val personalPayload = JSONObject().apply {
            // Для листа calltrack_mop / «Личные контакты» передаём все ключи явно:
            // строка должна быть уникальной по пользователю приложения и номеру контакта.
            put("action", "upsert_personal_contact")
            put("operation", if (isPersonal) "set_personal" else "clear_personal")
            put("spreadsheet", "calltrack_mop")
            put("sheet", "Личные контакты")
            put("target_sheet", "Личные контакты")
            put("manager_phone", normalizedManagerPhone)
            put("user_phone", normalizedManagerPhone)
            put("raw_user_phone", managerPhone)
            put("manager_name", managerName)
            put("user_name", managerName)
            put("contact_phone", normalizedContactPhone)
            put("phone", normalizedContactPhone)
            put("raw_contact_phone", phone)
            put("personal_flag", personalFlag)
            put("is_personal", personalFlag)
            put("Признак личного", personalFlag)
            put("client", clientValue)
            put("client_value", clientValue)
            put("apply_to_calls", 1)
            put("key_columns", JSONArray().put("user_phone").put("contact_phone"))
        }
        val calltrackPayload = JSONObject().apply {
            put("action", "update_client_by_phone")
            put("operation", if (isPersonal) "set_personal_call" else "clear_personal_call")
            put("phone", normalizedContactPhone)
            put("contact_phone", normalizedContactPhone)
            put("manager_phone", normalizedManagerPhone)
            put("user_phone", normalizedManagerPhone)
            put("manager_name", managerName)
            put("user_name", managerName)
            put("phone_column", "Номер телефона")
            put("client_column", "Клиент")
            put("client", clientValue)
            put("client_value", clientValue)
            put("clear_value", PERSONAL_CALL_CLIENT_VALUE)
            put("personal_flag", personalFlag)
            put("is_personal", personalFlag)
            put("Признак личного", personalFlag)
            put("update_all_rows", 1)
            put("restore_from_directory", if (isPersonal) 0 else 1)
        }

        val result: Result<Boolean> = runCatching {
            withContext(Dispatchers.IO) {
                val personalRequest = Request.Builder()
                    .url(BuildConfig.PERSONAL_CONTACTS_WEBHOOK_URL)
                    .post(personalPayload.toString().toRequestBody("application/json; charset=utf-8".toMediaType()))
                    .build()
                val personalOk = personalContactsHttpClient.newCall(personalRequest).execute().use { response ->
                    val bodyText = response.body?.string().orEmpty()
                    val accepted = isWebhookAccepted(response.isSuccessful, bodyText)
                    com.example.calltrack.logging.AppLogger.log(appContext, "API", "Ответ сервера личных контактов: ${response.code}")
                    Log.d(
                        "CallRepository",
                        "Обновление calltrack_mop/Личные контакты: user=$normalizedManagerPhone, " +
                            "phone=$normalizedContactPhone, personalFlag=$personalFlag, code=${response.code}, " +
                            "accepted=$accepted, body=${bodyText.take(300)}"
                    )
                    accepted
                }

                // LEGACY_GAS: массовое обновление колонки «Клиент» в Google Sheets оставлено как fallback
                // до появления аналогичного SQL endpoint для личных контактов.
                val calltrackRequest = Request.Builder()
                    .url(BuildConfig.WEBHOOK_URL)
                    .post(calltrackPayload.toString().toRequestBody("application/json; charset=utf-8".toMediaType()))
                    .build()
                val calltrackOk = personalContactsHttpClient.newCall(calltrackRequest).execute().use { response ->
                    val bodyText = response.body?.string().orEmpty()
                    val accepted = isWebhookAccepted(response.isSuccessful, bodyText)
                    Log.d(
                        "CallRepository",
                        "Массовое обновление колонки Клиент в Calltrack: phone=$normalizedContactPhone, " +
                            "isPersonal=$isPersonal, code=${response.code}, accepted=$accepted, body=${bodyText.take(300)}"
                    )
                    accepted
                }

                personalOk && calltrackOk
            }
        }
        return result.getOrElse {
            com.example.calltrack.logging.AppLogger.log(appContext, "ERROR", "Ошибка отправки: ${it.message}")
            Log.e("CallRepository", "Не удалось отправить личный контакт и обновить колонку Клиент в Calltrack", it)
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
            val items = mutableListOf<PersonalSyncItem>()
            for (i in 0 until arr.length()) {
                val o = arr.optJSONObject(i) ?: continue
                items += PersonalSyncItem(
                    managerPhone = o.optString("manager_phone"),
                    managerName = o.optString("manager_name"),
                    contactPhone = o.optString("contact_phone"),
                    isPersonal = o.optBoolean("is_personal", true)
                )
            }
            items
        }.getOrElse { emptyList<PersonalSyncItem>() }
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


    private suspend fun findLatestCallForPhone(phone: String): CallEntity? {
        val normalizedPhone = normalizePhone(phone)
        if (normalizedPhone.isBlank()) return null
        return callDao.getAllOnce().firstOrNull { call -> normalizePhone(call.phone) == normalizedPhone }
    }

    suspend fun saveCommentForCall(callId: Long, phone: String, text: String) {
        val call = callDao.getById(callId) ?: return
        callDao.updateOutcome(callId, text, call.tag, call.reminder)
        if (text.isNotBlank()) {
            commentDao.insert(CommentEntity(phone = phone, text = text))
        }
        // Комментарий меняет конкретный звонок, поэтому отправляем в таблицу строку с тем же call_id.
        syncCallById(callId)
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
        // Напоминание меняет конкретный звонок, поэтому отправляем в таблицу строку с тем же call_id.
        syncCallById(callId)
    }

    suspend fun syncCallById(callId: Long) {
        syncMutex.withLock {
            val entity = callDao.getById(callId) ?: return@withLock
            val managerName = prefs.getManagerName().ifBlank { "Не указан" }
            val managerPhone = prefs.getManagerPhone().ifBlank { "Не указан" }
            if (sendCallToWebhook(entity, managerName, managerPhone)) {
                callDao.markUploaded(entity.id)
                com.example.calltrack.logging.AppLogger.log(appContext, "API", "CALL MARKED AS SYNCED BY ID: id=${entity.id}")
            }
        }
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
                if (sendCallToWebhook(entity, managerName, managerPhone)) {
                    callDao.markUploaded(duplicates.map { it.id })
                    com.example.calltrack.logging.AppLogger.log(appContext, "API", "CALL MARKED AS SYNCED: ids=${duplicates.joinToString { it.id.toString() }}")
                    Log.d(
                        "CallRepository",
                        "Webhook sent once for ${duplicates.size} record(s): ids=${duplicates.joinToString { it.id.toString() }}, phone=${entity.phone}"
                    )
                }
            }
        }
    }

    private suspend fun sendCallToWebhook(entity: CallEntity, managerName: String, managerPhone: String): Boolean {
        Log.d("WEBHOOK", "Отправка звонка в SQL API: $entity")
        val personalMarked = isPersonalContact(entity.phone)
        val clientName = if (personalMarked) PERSONAL_CALL_CLIENT_VALUE else findClientName(entity.phone)
        val reminderText = extractReminderText(entity.reminder)
        val callId = buildWebhookCallId(entity)

        if (sendCallToSqlApi(entity, managerName, managerPhone, clientName, reminderText, callId)) {
            return true
        }

        // LEGACY_GAS: Google Apps Script оставлен только как fallback, если SQL API временно недоступен.
        return sendCallToLegacyGas(entity, managerName, managerPhone, clientName, reminderText, callId)
    }

    private suspend fun sendCallToSqlApi(
        entity: CallEntity,
        managerName: String,
        managerPhone: String,
        clientName: String,
        reminderText: String,
        callId: String
    ): Boolean {
        return runCatching {
            val payload = JSONObject().apply {
                put("date", dateFormat.format(Date(entity.timestamp)))
                put("time", timeFormat.format(Date(entity.timestamp)))
                put("phone", normalizePhone(entity.phone))
                put("type", entity.type)
                put("duration", entity.duration)
                put("manager", managerName)
                put("comment", entity.note)
                put("tag", entity.tag)
                put("reminder", entity.reminder)
                put("reminder_text", reminderText)
                put("client", clientName)
                put("call_id", callId)
                put("user_phone", normalizePhone(managerPhone))
            }
            com.example.calltrack.logging.AppLogger.log(
                appContext,
                "API",
                "Отправка данных в SQL API: call_id=$callId, phone=${entity.phone}, type=${entity.type}"
            )
            withContext(Dispatchers.IO) {
                val request = Request.Builder()
                    .url(sqlApiUrl("add_call.php"))
                    .post(payload.toString().toRequestBody("application/json; charset=utf-8".toMediaType()))
                    .build()
                personalContactsHttpClient.newCall(request).execute().use { response ->
                    val bodyText = response.body?.string().orEmpty()
                    if (!isSqlApiAccepted(response.isSuccessful, bodyText)) {
                        throw IllegalStateException(
                            "SQL API rejected: code=${response.code}, body=${bodyText.take(400)}"
                        )
                    }
                    com.example.calltrack.logging.AppLogger.log(appContext, "API", "Ответ SQL API: code=${response.code}")
                    Log.d("WEBHOOK", "Отправлено в SQL API: phone=${entity.phone}, id=${entity.id}, call_id=$callId")
                    true
                }
            }
        }.onFailure {
            Log.e("WEBHOOK", "Ошибка отправки в SQL API: id=${entity.id}", it)
            Log.e("CallRepository", "SQL API send failed for id=${entity.id}", it)
            com.example.calltrack.logging.AppLogger.log(appContext, "ERROR", "Ошибка SQL API: ${it.message}")
        }.getOrDefault(false)
    }

    private suspend fun sendCallToLegacyGas(
        entity: CallEntity,
        managerName: String,
        managerPhone: String,
        clientName: String,
        reminderText: String,
        callId: String
    ): Boolean {
        return runCatching {
            val response = webhookApi.sendCall(
                BuildConfig.WEBHOOK_URL,
                WebhookRequest(
                    callId = callId,
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
            )
            val bodyText = response.body()?.string().orEmpty()
            if (!isWebhookAccepted(response.isSuccessful, bodyText)) {
                throw IllegalStateException(
                    "LEGACY_GAS webhook rejected: code=${response.code()}, body=${bodyText.take(400)}"
                )
            }

            com.example.calltrack.logging.AppLogger.log(appContext, "API", "Ответ LEGACY_GAS: code=${response.code()}")
            Log.d("WEBHOOK", "Отправлено через LEGACY_GAS: phone=${entity.phone}, id=${entity.id}")
            true
        }.onFailure {
            Log.e("WEBHOOK", "Ошибка при вызове LEGACY_GAS webhookApi.sendCall", it)
            Log.e("WEBHOOK", "Ошибка отправки: id=${entity.id}", it)
            Log.e("CallRepository", "LEGACY_GAS webhook send failed for id=${entity.id}", it)
            com.example.calltrack.logging.AppLogger.log(appContext, "ERROR", "Ошибка отправки: ${it.message}")
            com.example.calltrack.logging.AppLogger.log(appContext, "API", "Повторная отправка данных")
        }.getOrDefault(false)
    }

    private fun buildWebhookCallId(entity: CallEntity): String = "${entity.id}_${entity.timestamp}"

    private fun sqlApiUrl(endpoint: String): String {
        return BuildConfig.SQL_API_BASE_URL.trimEnd('/') + "/" + endpoint.trimStart('/')
    }

    private fun urlEncode(value: String): String {
        return URLEncoder.encode(value, "UTF-8")
    }

    private fun isSqlApiAccepted(isSuccessful: Boolean, bodyText: String): Boolean {
        if (!isSuccessful) return false
        val token = runCatching { JSONTokener(bodyText.trim()).nextValue() }.getOrNull()
        if (token is JSONObject) {
            return token.optString("status").equals("success", ignoreCase = true)
        }
        val normalized = bodyText.lowercase(Locale.getDefault())
        return normalized.contains("\"status\":\"success\"") || normalized.contains("'status':'success")
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

    private fun parseHistoryTimestamp(date: String, time: String): Long {
        return parseHistoryTimestampOrNull(date, time) ?: System.currentTimeMillis()
    }

    private fun parseHistoryTimestampOrNull(date: String, time: String): Long? {
        val datePart = date.trim()
        val timePart = time.trim()
        if (datePart.isBlank() && timePart.isBlank()) return null

        val rawDateTime = listOf(datePart, timePart).filter { it.isNotBlank() }.joinToString(" ")
        val formats = listOf(
            "dd.MM.yyyy HH:mm:ss",
            "dd.MM.yyyy HH:mm",
            "dd.MM.yy HH:mm:ss",
            "dd.MM.yy HH:mm",
            "yyyy-MM-dd HH:mm:ss",
            "yyyy-MM-dd HH:mm",
            "yyyy-MM-dd'T'HH:mm:ss",
            "dd.MM.yyyy",
            "dd.MM.yy",
            "yyyy-MM-dd"
        )

        formats.forEach { pattern ->
            val parsed = runCatching {
                SimpleDateFormat(pattern, Locale.getDefault()).parse(rawDateTime)?.time
            }.getOrNull()
            if (parsed != null) return parsed
        }
        return null
    }

    private fun CallHistoryItem.toReminderEntity(normalizedPhone: String): ReminderEntity? {
        val rawReminder = reminder.trim()
        val rawReminderText = reminderText.trim()
        if (rawReminder.isBlank() && rawReminderText.isBlank()) return null

        val message = when {
            rawReminderText.isNotBlank() -> rawReminderText
            rawReminder.contains("|") -> rawReminder.substringAfter("|").trim()
            else -> rawReminder
        }.ifBlank { return null }

        val remindAt = parseReminderTimestamp(rawReminder, date, time)
        return ReminderEntity(
            phone = normalizedPhone,
            contactName = client,
            message = message,
            remindAt = remindAt,
            status = "Активно",
            createdAt = parseHistoryTimestamp(date, time)
        )
    }

    private fun parseReminderTimestamp(reminderValue: String, fallbackDate: String, fallbackTime: String): Long {
        val reminderDateTime = reminderValue.substringBefore("|").trim()
        if (reminderDateTime.any { it.isDigit() }) {
            parseHistoryTimestampOrNull(reminderDateTime, "")?.let { return it }
        }
        return parseHistoryTimestamp(fallbackDate, fallbackTime)
    }

    private fun ReminderEntity.reminderFingerprint(): String {
        return "${normalizePhone(phone)}|${message.trim()}|$remindAt"
    }

    private fun CommentEntity.commentFingerprint(): String {
        return "${normalizePhone(phone)}|${text.trim()}|$createdAt"
    }

    private fun mapDeviceCallType(typeInt: Int, duration: Long): Pair<String, String> {
        val callTypeString = when (typeInt) {
            CallLog.Calls.INCOMING_TYPE -> if (duration < 2L) "Пропущенный" else "Входящий"
            CallLog.Calls.OUTGOING_TYPE -> if (duration < 2L) "Неотвеченный" else "Исходящий"
            CallLog.Calls.MISSED_TYPE -> "Пропущенный"
            CallLog.Calls.REJECTED_TYPE -> "Сброшенный"
            else -> "Неотвеченный"
        }
        return callTypeString to ""
    }

    fun normalizePhone(phone: String): String = phone.filter { it.isDigit() }.takeLast(10)

    private fun normalizePhoneForHistoryApi(phone: String): String {
        val digits = phone.filter { it.isDigit() }
        return when {
            digits.length == 10 -> "7$digits"
            digits.length == 11 && digits.startsWith("8") -> "7${digits.drop(1)}"
            digits.length == 11 && digits.startsWith("7") -> digits
            digits.length > 11 -> normalizePhoneForHistoryApi(digits.takeLast(11))
            else -> digits
        }
    }

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

    private companion object {
        private const val HISTORY_LOG_TAG = "COMMENT_HISTORY"
        private const val DEVICE_RECENT_CALLS_LIMIT = 100
        private const val DEVICE_CONTACT_HISTORY_LIMIT = 100
        private const val CALL_HISTORY_MIN_ARRAY_COLUMNS = 5
        private const val PERSONAL_CALL_CLIENT_VALUE = "Личный звонок"
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
