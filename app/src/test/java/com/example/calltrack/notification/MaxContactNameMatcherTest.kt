package com.example.calltrack.notification

import org.junit.Assert.assertFalse
import org.junit.Assert.assertTrue
import org.junit.Test

class MaxContactNameMatcherTest {
    @Test
    fun `одинаковые имена совпадают`() {
        assertTrue(MaxContactNameMatcher.matches("Света Склярова ВР", "Света Склярова ВР"))
    }

    @Test
    fun `пробелы по краям и подряд нормализуются`() {
        assertTrue(MaxContactNameMatcher.matches(" Света   Склярова ВР ", "Света Склярова ВР"))
    }

    @Test
    fun `регистр не влияет на совпадение`() {
        assertTrue(MaxContactNameMatcher.matches("света склярова вр", "Света Склярова ВР"))
    }

    @Test
    fun `частичное имя не совпадает`() {
        assertFalse(MaxContactNameMatcher.matches("Света Склярова", "Света Склярова ВР"))
    }
}
