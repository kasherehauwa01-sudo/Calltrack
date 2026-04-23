package com.example.calltrack.data.repository

import android.content.Context
import android.os.Looper
import android.util.Log
import com.example.calltrack.BuildConfig
import kotlinx.coroutines.CoroutineScope
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.SupervisorJob
import kotlinx.coroutines.launch
import okhttp3.OkHttpClient
import okhttp3.Request

class ClientDirectory(context: Context) {
    private val ioScope = CoroutineScope(SupervisorJob() + Dispatchers.IO)
    private val httpClient = OkHttpClient()
    private val lock = Any()

    @Volatile
    private var loaded = false

    @Volatile
    private var phoneToClient: Map<String, String> = emptyMap()

    init {
        // Прогреваем кэш в фоне, чтобы UI не блокировался сетью.
        ioScope.launch { ensureLoaded() }
    }

    fun findClientName(rawPhone: String): String {
        val normalized = normalizePhone(rawPhone)
        if (normalized.isBlank()) return ""

        // На main-потоке сеть не трогаем, возвращаем текущий кэш.
        if (!isMainThread()) {
            ensureLoaded()
        }
        return phoneToClient[normalized].orEmpty()
    }

    private fun ensureLoaded() {
        if (loaded) return
        synchronized(lock) {
            if (loaded) return
            phoneToClient = loadFromGoogleSheets()
            loaded = true
        }
    }

    private fun loadFromGoogleSheets(): Map<String, String> {
        val result = linkedMapOf<String, String>()
        val spreadsheetId = BuildConfig.CLIENT_DIRECTORY_SPREADSHEET_ID.trim()
        if (spreadsheetId.isBlank()) return result

        val gids = BuildConfig.CLIENT_DIRECTORY_SHEET_GIDS
            .split(',')
            .map { it.trim() }
            .filter { it.isNotBlank() }

        gids.forEach { gid ->
            val url = "https://docs.google.com/spreadsheets/d/$spreadsheetId/export?format=csv&gid=$gid"
            val csv = downloadCsv(url) ?: return@forEach
            parseCsvToMap(csv, result)
        }

        return result
    }

    private fun downloadCsv(url: String): String? {
        val request = Request.Builder().url(url).build()
        return runCatching {
            httpClient.newCall(request).execute().use { response ->
                if (!response.isSuccessful) return null
                response.body?.string()
            }
        }.getOrNull()
    }

    private fun parseCsvToMap(csv: String, target: MutableMap<String, String>) {
        val lines = csv.lineSequence()
            .map { it.trimEnd('\r') }
            .filter { it.isNotBlank() }
            .toList()

        if (lines.isEmpty()) return

        val delimiter = resolveDelimiter(lines.first())
        val header = parseCsvLine(lines.first(), delimiter)
        val normalizedHeader = header.map { normalizeHeader(it) }
        val phoneIndex = normalizedHeader.indexOfFirst { it.contains("телефон") || it.contains("phone") }
        val clientIndex = normalizedHeader.indexOfFirst { it.contains("клиент") || it.contains("client") }
        if (phoneIndex < 0 || clientIndex < 0) {
            Log.w("ClientDirectory", "Не найдены колонки 'Телефон/Клиент'. Header=$header")
            return
        }

        lines.drop(1).forEach { line ->
            val cols = parseCsvLine(line, delimiter)
            if (phoneIndex >= cols.size || clientIndex >= cols.size) return@forEach

            val phone = normalizePhone(cols[phoneIndex])
            val client = cols[clientIndex].trim()
            if (phone.isBlank() || client.isBlank()) return@forEach

            // Первое совпадение оставляем (приоритет — порядок gid в конфиге).
            target.putIfAbsent(phone, client)
        }

        Log.d("ClientDirectory", "Загружено клиентов: ${target.size}")
    }

    private fun parseCsvLine(line: String, delimiter: Char): List<String> {
        val out = mutableListOf<String>()
        val sb = StringBuilder()
        var inQuotes = false
        var i = 0

        while (i < line.length) {
            val ch = line[i]
            when {
                ch == '"' -> {
                    if (inQuotes && i + 1 < line.length && line[i + 1] == '"') {
                        sb.append('"')
                        i++
                    } else {
                        inQuotes = !inQuotes
                    }
                }

                ch == delimiter && !inQuotes -> {
                    out += sb.toString().trim()
                    sb.setLength(0)
                }

                else -> sb.append(ch)
            }
            i++
        }

        out += sb.toString().trim()
        return out
    }

    private fun resolveDelimiter(headerLine: String): Char {
        val commaCount = headerLine.count { it == ',' }
        val semicolonCount = headerLine.count { it == ';' }
        return if (semicolonCount > commaCount) ';' else ','
    }

    private fun normalizeHeader(value: String): String {
        return value
            .replace("\uFEFF", "")
            .trim()
            .lowercase()
    }

    private fun normalizePhone(phone: String): String {
        return phone.replace(Regex("[^0-9]"), "").takeLast(10)
    }

    private fun isMainThread(): Boolean = Looper.getMainLooper() == Looper.myLooper()
}
