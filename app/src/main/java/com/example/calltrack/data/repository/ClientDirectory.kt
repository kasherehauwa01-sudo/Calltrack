package com.example.calltrack.data.repository

import android.content.Context
import android.os.Looper
import android.util.Log
import com.example.calltrack.BuildConfig
import com.example.calltrack.logging.AppLogger
import kotlinx.coroutines.CoroutineScope
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.SupervisorJob
import kotlinx.coroutines.launch
import okhttp3.OkHttpClient
import okhttp3.Request
import org.json.JSONArray
import org.json.JSONObject
import java.util.concurrent.TimeUnit

data class ClientCard(
    val name: String,
    val phone: String,
    val fields: Map<String, String>
)

class ClientDirectory(context: Context) {
    private val appContext = context.applicationContext
    private val ioScope = CoroutineScope(SupervisorJob() + Dispatchers.IO)
    private val httpClient = OkHttpClient.Builder()
        .connectTimeout(5, TimeUnit.SECONDS)
        .readTimeout(10, TimeUnit.SECONDS)
        .callTimeout(10, TimeUnit.SECONDS)
        .retryOnConnectionFailure(true)
        .build()
    private val lock = Any()

    @Volatile
    private var loadedAt = 0L

    @Volatile
    private var phoneToClient: Map<String, String> = emptyMap()
    private val clientCards = mutableMapOf<String, Pair<Long, List<ClientCard>>>()

    init {
        // Прогреваем справочник проекта clients в фоне, не блокируя UI.
        ioScope.launch { ensureLoaded() }
    }

    fun findClientName(rawPhone: String): String {
        val normalized = normalizePhone(rawPhone)
        if (normalized.isBlank()) return ""
        if (!isMainThread()) {
            ensureLoaded()
        } else if (directoryExpired()) {
            ioScope.launch { ensureLoaded() }
        }
        return phoneToClient[normalized].orEmpty()
    }

    /** Загружает все карточки Clients, найденные по одному номеру телефона. */
    fun loadClientCards(rawPhone: String): List<ClientCard> {
        val normalized = normalizePhone(rawPhone)
        if (normalized.length != 10) return emptyList()
        val baseUrl = BuildConfig.SQL_API_BASE_URL.trimEnd('/')
        val url = "$baseUrl/test_clients.php?phone=${java.net.URLEncoder.encode(rawPhone, "UTF-8")}"
        val request = Request.Builder().url(url).build()
        return runCatching {
            httpClient.newCall(request).execute().use { response ->
                if (!response.isSuccessful) error("HTTP ${response.code}")
                val payload = JSONObject(response.body?.string().orEmpty())
                if (!payload.optString("status").equals("success", ignoreCase = true)) {
                    error(payload.optString("message", "API error"))
                }
                val data = payload.optJSONObject("data") ?: return@use emptyList()
                if (data.optBoolean("pending")) return@use emptyList()
                val matches = data.optJSONArray("matches") ?: return@use emptyList()
                parseClientCards(matches)
            }
        }.onFailure {
            Log.e("ClientDirectory", "Ошибка загрузки карточек Clients", it)
        }.getOrDefault(emptyList())
    }

    private fun parseClientCards(matches: JSONArray): List<ClientCard> = buildList {
        for (index in 0 until matches.length()) {
            val match = matches.optJSONObject(index) ?: continue
            val fieldsJson = match.optJSONObject("fields")
            val fields = linkedMapOf<String, String>()
            fieldsJson?.keys()?.forEach { key ->
                formatFieldValue(fieldsJson.opt(key)).takeIf(String::isNotBlank)?.let { fields[key] = it }
            }
            add(ClientCard(match.optString("name"), match.optString("phone"), fields))
        }
    }

    private fun formatFieldValue(value: Any?): String = when (value) {
        null, JSONObject.NULL -> ""
        is JSONArray -> (0 until value.length()).map { formatFieldValue(value.opt(it)) }.filter(String::isNotBlank).joinToString("\n")
        is JSONObject -> value.keys().asSequence().mapNotNull { key ->
            formatFieldValue(value.opt(key)).takeIf(String::isNotBlank)?.let { "$key: $it" }
        }.joinToString("\n")
        is Boolean -> if (value) "Да" else "Нет"
        else -> value.toString().trim()
    }

    private fun ensureLoaded() {
        if (!directoryExpired()) return
        synchronized(lock) {
            if (!directoryExpired()) return
            val loaded = loadFromClientsProject()
            if (loaded.isNotEmpty()) phoneToClient = loaded
            loadedAt = System.currentTimeMillis()
        }
    }

    private fun loadFromClientsProject(): Map<String, String> {
        val url = BuildConfig.SQL_API_BASE_URL.trimEnd('/') + "/client_directory.php"
        val request = Request.Builder().url(url).build()
        return runCatching {
            httpClient.newCall(request).execute().use { response ->
                if (!response.isSuccessful) error("HTTP ${response.code}")
                val payload = JSONObject(response.body?.string().orEmpty())
                if (!payload.optString("status").equals("success", ignoreCase = true)) {
                    error(payload.optString("message", "API error"))
                }
                val result = linkedMapOf<String, String>()
                val clients = payload.optJSONArray("data") ?: return@use result
                for (index in 0 until clients.length()) {
                    val client = clients.optJSONObject(index) ?: continue
                    val name = client.optString("name").trim()
                    val phones = client.optJSONArray("phones") ?: continue
                    if (name.isBlank()) continue
                    for (phoneIndex in 0 until phones.length()) {
                        val phone = normalizePhone(phones.optString(phoneIndex))
                        if (phone.isNotBlank()) result.putIfAbsent(phone, name)
                    }
                }
                Log.d("ClientDirectory", "Из проекта clients загружено номеров: ${result.size}")
                result
            }
        }.onFailure {
            Log.e("ClientDirectory", "Ошибка загрузки справочника проекта clients", it)
        }.getOrDefault(emptyMap())
    }

    private fun directoryExpired(): Boolean = System.currentTimeMillis() - loadedAt >= CACHE_TTL_MS

    private fun normalizePhone(phone: String): String {
        return phone.replace(Regex("[^0-9]"), "").takeLast(10)
    }

    private fun isMainThread(): Boolean = Looper.getMainLooper() == Looper.myLooper()

    companion object {
        private const val CACHE_TTL_MS = 5 * 60 * 1000L
    }
}
