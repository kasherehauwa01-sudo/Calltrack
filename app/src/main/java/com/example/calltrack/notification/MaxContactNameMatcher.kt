package com.example.calltrack.notification

/** Точное сопоставление имён MAX и телефонной книги без нечёткого поиска. */
object MaxContactNameMatcher {
    fun normalize(name: String): String = name.trim().replace(Regex("\\s+"), " ")

    fun matches(maxName: String, contactName: String): Boolean =
        normalize(maxName).equals(normalize(contactName), ignoreCase = true)
}
