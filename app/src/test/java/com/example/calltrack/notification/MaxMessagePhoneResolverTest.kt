package com.example.calltrack.notification

import com.example.calltrack.data.repository.ClientPhoneNumbers
import org.junit.Assert.*
import org.junit.Test

class MaxMessagePhoneResolverTest {
    private fun resolve(vararg contacts: ContactCandidate) = MaxMessagePhoneResolver.resolveCandidates(contacts.toList())

    @Test fun singlePhoneUsesExistingClientLookupFormat() {
        val result = resolve(ContactCandidate(1, listOf("+7 (999) 123-45-67")))
        assertEquals(ContactResolutionStatus.RESOLVED, result.status)
        assertEquals("9991234567", result.phone)
        assertEquals(ClientPhoneNumbers.lookupKey("+7 (999) 123-45-67"), result.phone)
    }
    @Test fun missingAndSameNameDifferentContactsAreNotResolved() {
        assertNull(resolve().phone)
        assertNull(resolve(ContactCandidate(1,listOf("79991111111")),ContactCandidate(2,listOf("79992222222"))).phone)
        assertNull(resolve(ContactCandidate(1,listOf("79991111111")),ContactCandidate(2,listOf("79991111111"))).phone)
    }
    @Test fun multipleUniqueNumbersAreAmbiguousButFormattingDuplicatesAreOneNumber() {
        assertEquals(ContactResolutionStatus.AMBIGUOUS_MULTIPLE_PHONES,
            resolve(ContactCandidate(1,listOf("79991111111","79992222222"))).status)
        assertEquals("9991234567", resolve(ContactCandidate(1,listOf("+7 (999) 123-45-67","8 (999) 123-45-67"))).phone)
        assertEquals("9991234567", resolve(ContactCandidate(1,listOf("79991234567")),ContactCandidate(1,listOf("89991234567"))).phone)
    }
    @Test fun approximateOrReorderedNamesAreNotMatches() {
        for (name in listOf("Иван Петрович","Иван Петров Работа","Петров Иван")) {
            assertFalse(MaxContactNameMatcher.matches("Иван Петров", name))
        }
        assertTrue(MaxContactNameMatcher.matches(" Иван  Петров ", "иван петров"))
    }
    @Test fun permissionDeniedAndProviderFailureAreOptional() {
        assertEquals("", MaxMessagePhoneResolver { ResolvedContact(null,ContactResolutionStatus.PERMISSION_DENIED) }.resolve("Иван Петров"))
        assertEquals("", MaxMessagePhoneResolver { throw SecurityException("provider failure") }.resolve("Иван Петров"))
        assertEquals("", MaxMessagePhoneResolver { throw IllegalStateException("cursor failure") }.resolve("Иван Петров"))
        assertEquals("", MaxMessagePhoneResolver { error("blank name must not query contacts") }.resolve(" "))
    }
    @Test fun malformedShortForeignAndExtensionNumbersAreNotGuessed() {
        for (phone in listOf("123", "+1 999 123 45 67", "+44 20 7946 0000", "79991234567 доб. 3", "")) {
            assertEquals(phone, "", ClientPhoneNumbers.fromRussianContact(phone))
        }
        assertNull(resolve(ContactCandidate(1,listOf("79991234567","123"))).phone)
    }
}
