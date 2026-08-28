package com.example.calltrack.ui.base

import java.nio.charset.Charset

/** Repairs UTF-8 text that was accidentally decoded as Windows-1251 upstream. */
internal object TextEncoding {
    private val windows1251: Charset = Charset.forName("windows-1251")
    private val mojibakeTokens = listOf(
        "Р°", "Р±", "РІ", "Рі", "Рґ", "Рµ", "Р¶", "Р·", "Рё", "Р№", "Рє", "Р»", "Рј",
        "РЅ", "Рѕ", "Рї", "СЂ", "СЃ", "С‚", "Сѓ", "С„", "С…", "С†", "С‡", "С€", "С‰",
        "С‹", "СЊ", "СЌ", "СЋ", "СЏ", "Рђ", "Р‘", "Р’", "Р“", "Р”", "Р•", "Р–", "Р—",
        "Р™", "Рљ", "Р›", "Рњ", "Рќ", "Рћ", "Рџ", "Р ", "РЎ", "Рў", "РЈ", "Р¤",
        "РҐ", "Р¦", "Р§", "РЁ", "Р©", "Р«", "Р¬", "Р­", "Р®", "РЇ"
    )

    fun repair(value: CharSequence): CharSequence {
        val source = value.toString()
        val sourceScore = mojibakeScore(source)
        if (sourceScore < 2) return value

        val repaired = runCatching {
            String(source.toByteArray(windows1251), Charsets.UTF_8)
        }.getOrNull() ?: return value

        return if (
            '�' !in repaired &&
            repaired.any { it in '\u0400'..'\u04FF' } &&
            mojibakeScore(repaired) < sourceScore
        ) repaired else value
    }

    private fun mojibakeScore(value: String): Int = mojibakeTokens.sumOf { token ->
        value.windowed(token.length).count(token::equals)
    }
}
