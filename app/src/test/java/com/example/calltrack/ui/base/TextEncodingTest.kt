package com.example.calltrack.ui.base

import org.junit.Assert.assertEquals
import org.junit.Test
import java.nio.charset.Charset

class TextEncodingTest {
    private val windows1251 = Charset.forName("windows-1251")

    @Test
    fun repairsUtf8DecodedAsWindows1251() {
        val expected = "Результат звонка"
        val broken = String(expected.toByteArray(Charsets.UTF_8), windows1251)

        assertEquals(expected, TextEncoding.repair(broken).toString())
    }

    @Test
    fun leavesCorrectRussianTextUnchanged() {
        val expected = "История звонков"

        assertEquals(expected, TextEncoding.repair(expected).toString())
    }

    @Test
    fun preservesFormattingAroundRepairedText() {
        val expected = "Версия 1.0.15 — обновление доступно"
        val broken = String(expected.toByteArray(Charsets.UTF_8), windows1251)

        assertEquals(expected, TextEncoding.repair(broken).toString())
    }

    @Test
    fun repairsMojibakeBulletUsedInAnalyticsTimeline() {
        val expected = "28.08.2026 • Исходящий • 0:23"
        val broken = "28.08.2026 вЂў Исходящий вЂў 0:23"

        assertEquals(expected, TextEncoding.repair(broken).toString())
    }

    @Test
    fun repairsRepeatedEncodingDamage() {
        val expected = "События звонка"
        val brokenOnce = String(expected.toByteArray(Charsets.UTF_8), windows1251)
        val brokenTwice = String(brokenOnce.toByteArray(Charsets.UTF_8), windows1251)

        assertEquals(expected, TextEncoding.repair(brokenTwice).toString())
    }
}
