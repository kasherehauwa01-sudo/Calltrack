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
import org.json.JSONObject
import java.net.URLEncoder
import java.nio.charset.StandardCharsets
import java.util.concurrent.TimeUnit

data class ClientCard(
    val name: String,
    val fields: List<Pair<String, String>>
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

    fun loadClientCards(rawPhone: String): List<ClientCard> {
        val normalizedPhone = normalizePhone(rawPhone)
        synchronized(clientCards) {
            clientCards[normalizedPhone]?.takeIf { System.currentTimeMillis() - it.first < CACHE_TTL_MS }?.let {
                AppLogger.log(appContext, "CLIENTS", "Android -> CallTrack: cache hit, phone=${normalizedPhone.takeLast(4).padStart(10, '*')}")
                return it.second
            }
        }
        val encodedPhone = URLEncoder.encode(rawPhone, StandardCharsets.UTF_8.name())
        val url = BuildConfig.SQL_API_BASE_URL.trimEnd('/') + "/client_directory.php?card=1&phone=$encodedPhone"
        val request = Request.Builder().url(url).build()
        val startedAt = System.nanoTime()
        AppLogger.log(appContext, "CLIENTS", "Android -> CallTrack: start, connectTimeout=5s, callTimeout=10s")
        return httpClient.newCall(request).execute().use { response ->
            val responseAt = System.nanoTime()
            val serverTiming = response.header("Server-Timing").orEmpty()
            AppLogger.log(appContext, "CLIENTS", "Android -> CallTrack: response=${response.code}, elapsed=${elapsedMs(startedAt)} ms, serverTiming=$serverTiming")
            if (!response.isSuccessful) error("HTTP ${response.code}")
            val body = response.body?.string().orEmpty()
            val bodyAt = System.nanoTime()
            AppLogger.log(appContext, "CLIENTS", "Android response read: ${elapsedMs(responseAt)} ms, bytes=${body.length}")
            val payload = JSONObject(body)
            if (!payload.optString("status").equals("success", ignoreCase = true)) {
                error(payload.optString("message", "API error"))
            }
            val cards = payload.optJSONArray("data") ?: return@use emptyList()
            val result = buildList {
                for (index in 0 until cards.length()) {
                    val card = cards.optJSONObject(index) ?: continue
                    val fieldsObject = card.optJSONObject("fields") ?: JSONObject()
                    val fields = buildList {
                        val keys = fieldsObject.keys()
                        while (keys.hasNext()) {
                            val key = keys.next()
                            val value = fieldsObject.optString(key).trim()
                            if (value.isNotEmpty()) add(key to value)
                        }
                    }
                    add(ClientCard(card.optString("name").trim(), fields))
                }
            }
            synchronized(clientCards) { clientCards[normalizedPhone] = System.currentTimeMillis() to result }
            AppLogger.log(appContext, "CLIENTS", "JSON parsed: ${elapsedMs(bodyAt)} ms; total request time: ${elapsedMs(startedAt)} ms; cards=${result.size}")
            result
        }
    }

    private fun elapsedMs(startedAt: Long): Long = TimeUnit.NANOSECONDS.toMillis(System.nanoTime() - startedAt)

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
