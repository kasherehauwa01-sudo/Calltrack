package com.example.calltrack.data.repository

import android.content.Context
import org.jsoup.Jsoup

class ClientDirectory(private val context: Context) {

    // Файлы читаются и парсятся только один раз, затем поиск идёт по in-memory map.
    private val phoneToClient: Map<String, String> by lazy { loadPhoneToClientMap() }

    fun findClientName(rawPhone: String): String {
        val normalized = normalizePhone(rawPhone)
        if (normalized.isBlank()) return ""
        return phoneToClient[normalized].orEmpty()
    }

    private fun loadPhoneToClientMap(): Map<String, String> {
        val result = linkedMapOf<String, String>()

        // Приоритет: сначала Contacts_kor.htm, потом Contacts_opt.htm.
        val fileNames = listOf("Contacts_kor.htm", "Contacts_opt.htm")
        fileNames.forEach { fileName ->
            val html = runCatching {
                context.assets.open(fileName).bufferedReader().use { it.readText() }
            }.getOrNull() ?: return@forEach

            val doc = Jsoup.parse(html)
            val rows = doc.select("tr")
            if (rows.size < 2) return@forEach

            // По условию заголовки находятся во второй строке.
            val headerCells = rows[1].select("th,td").map { it.text().trim() }
            val phoneIndex = headerCells.indexOfFirst { it.equals("Телефон", ignoreCase = true) }
            val clientIndex = headerCells.indexOfFirst { it.equals("Клиент", ignoreCase = true) }
            if (phoneIndex < 0 || clientIndex < 0) return@forEach

            // Данные начинаются после строки с заголовками.
            rows.drop(2).forEach { row ->
                val cells = row.select("td")
                if (cells.isEmpty()) return@forEach
                if (phoneIndex >= cells.size || clientIndex >= cells.size) return@forEach

                val phone = normalizePhone(cells[phoneIndex].text())
                val client = cells[clientIndex].text().trim()
                if (phone.isBlank() || client.isBlank()) return@forEach

                // Сохраняем первое совпадение (kor имеет приоритет, т.к. обрабатывается первым).
                result.putIfAbsent(phone, client)
            }
        }

        return result
    }

    private fun normalizePhone(phone: String): String {
        return phone.replace(Regex("[^0-9]"), "").takeLast(10)
    }
}
