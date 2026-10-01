package com.example.calltrack.notification

import org.junit.Assert.*
import org.junit.Test

class ContactResolutionRulesTest {
    @Test fun `single contact and phone resolves`() {
        val result = ContactResolutionRules.resolve(listOf(ContactCandidate(1, listOf("+79370000000"))))
        assertEquals(ContactResolutionStatus.RESOLVED, result.status)
        assertEquals("+79370000000", result.phone)
    }

    @Test fun `multiple phones are ambiguous`() {
        val result = ContactResolutionRules.resolve(listOf(ContactCandidate(1, listOf("1", "2"))))
        assertEquals(ContactResolutionStatus.AMBIGUOUS_MULTIPLE_PHONES, result.status)
        assertNull(result.phone)
    }

    @Test fun `multiple contacts are ambiguous`() {
        val result = ContactResolutionRules.resolve(listOf(ContactCandidate(1, listOf("1")), ContactCandidate(2, listOf("2"))))
        assertEquals(ContactResolutionStatus.AMBIGUOUS_MULTIPLE_CONTACTS, result.status)
        assertNull(result.phone)
    }

    @Test fun `missing contact remains a valid unresolved result`() {
        val result = ContactResolutionRules.resolve(emptyList())
        assertEquals(ContactResolutionStatus.NOT_FOUND, result.status)
        assertNull(result.phone)
    }
}
