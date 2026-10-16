package com.example.calltrack.notification

import org.junit.Assert.*
import org.junit.Test

class MaxIncomingIdentityDiagnosticsTest {
    @Test fun controlCharactersCannotInjectLogLines() {
        val output = MaxIncomingIdentityDiagnostics.quoted("Alice\nkey=forged\r\t\u0000\u2028\u2029")
        assertEquals("\"Alice\\nkey=forged\\r\\t???\"", output)
        assertFalse(output.contains('\n')); assertFalse(output.contains('\r'))
    }
    @Test fun longIdentityFieldsAreBounded() {
        assertEquals("\"" + "a".repeat(256) + "\"[truncated]", MaxIncomingIdentityDiagnostics.quoted("a".repeat(300)))
        assertEquals("null", MaxIncomingIdentityDiagnostics.quoted(null))
    }
    @Test fun keyNamesAreSortedAndEscaped() {
        assertEquals("[\"android.messages\",\"android.text\",\"custom\\nkey\"]",
            MaxIncomingIdentityDiagnostics.keyNames(setOf("custom\nkey", "android.text", "android.messages")))
    }
}
