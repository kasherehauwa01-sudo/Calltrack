package com.example.calltrack.data.repository

import android.content.Context

class ClientDirectory(private val context: Context) {

    private val map: Map<String, String> by lazy { load() }

    fun findClientName(rawPhone: String): String {
        val normalized = normalize(rawPhone)
        if (normalized.isBlank()) return ""
        val short = normalized.takeLast(10)
        return map[normalized] ?: map[short] ?: ""
    }

    private fun load(): Map<String, String> {
        val result = mutableMapOf<String, String>()
        listOf("Contacts_kor.htm", "Contacts_opt.htm").forEach { fileName ->
            val html = runCatching {
                context.assets.open(fileName).bufferedReader().use { it.readText() }
            }.getOrNull() ?: return@forEach

            val rows = Regex("(?is)<tr[^>]*>(.*?)</tr>").findAll(html)
            rows.forEach { rowMatch ->
                val cells = Regex("(?is)<td[^>]*>(.*?)</td>").findAll(rowMatch.groupValues[1])
                    .map { stripHtml(it.groupValues[1]) }
                    .toList()
                if (cells.size < 2) return@forEach

                val phone = normalize(cells[0])
                val client = cells[1].trim()
                if (phone.isBlank() || client.isBlank()) return@forEach

                result.putIfAbsent(phone, client)
                if (phone.length > 10) {
                    result.putIfAbsent(phone.takeLast(10), client)
                }
            }
        }
        return result
    }

    private fun stripHtml(value: String): String {
        return value
            .replace(Regex("<[^>]+>"), " ")
            .replace("&nbsp;", " ")
            .trim()
    }

    private fun normalize(value: String): String = value.filter { it.isDigit() }
}
