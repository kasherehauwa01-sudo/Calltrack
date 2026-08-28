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
        "РҐ", "Р¦", "Р§", "РЁ", "Р©", "Р«", "Р¬", "Р­", "Р®", "РЇ",
        "вЂ", "в„", "в„–"
    )
    private val inlineReplacements = mapOf(
        "вЂў" to "•",
        "вЂ”" to "—",
        "вЂ“" to "–",
        "в„–" to "№"
    )

    fun repair(value: CharSequence): CharSequence {
        val source = value.toString()
        var repaired = inlineReplacements.entries.fold(source) { text, (broken, correct) ->
            text.replace(broken, correct)
        }
        repeat(MAX_REPAIR_PASSES) {
            val next = repairOnce(repaired)
            if (next == repaired) return repaired
            repaired = next
        }
        return repaired
    }

    private fun repairOnce(source: String): String {
        val sourceScore = mojibakeScore(source)
        if (sourceScore < MIN_MOJIBAKE_SCORE) return source

        val repaired = runCatching {
            String(source.toByteArray(windows1251), Charsets.UTF_8)
        }.getOrNull() ?: return source

        return if (
            '�' !in repaired &&
            repaired.any { it in '\u0400'..'\u04FF' } &&
            mojibakeScore(repaired) < sourceScore
        ) repaired else source
    }

    private fun mojibakeScore(value: String): Int = mojibakeTokens.sumOf { token ->
        value.windowed(token.length).count(token::equals)
    }

    private const val MIN_MOJIBAKE_SCORE = 1
    private const val MAX_REPAIR_PASSES = 3
}
