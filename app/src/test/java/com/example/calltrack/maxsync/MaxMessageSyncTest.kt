package com.example.calltrack.maxsync

import com.example.calltrack.data.local.MaxMessageEntity
import com.google.gson.Gson
import kotlinx.coroutines.runBlocking
import org.junit.Assert.*
import org.junit.Test

class MaxMessageSyncTest {
    private val cutoff = MaxSyncCutoff(1000, 10)
    private fun row(id: Long = 11, direction: String = "incoming") = MaxMessageEntity(
        id = id, sourceEventId = "event-$id", direction = direction, contactName = "Contact",
        occurredAt = 2000, createdAt = 2000, messageText = "PRIVATE_BODY")

    @Test fun newPersonalIncomingAndOutgoingAreEligible() {
        assertTrue(MaxMessageSyncPolicy.eligible(row(), cutoff))
        assertTrue(MaxMessageSyncPolicy.eligible(row(direction = "outgoing"), cutoff))
    }
    @Test fun groupsAndLegacyAreExcludedEvenIfIncorrectlyMarkedPersonal() {
        assertFalse(MaxMessageSyncPolicy.eligible(row().copy(isGroupConversation = true), cutoff))
        assertFalse(MaxMessageSyncPolicy.eligible(row(10), cutoff))
        assertFalse(MaxMessageSyncPolicy.eligible(row().copy(createdAt = 999), cutoff))
        assertFalse(MaxMessageSyncPolicy.eligible(row().copy(occurredAt = 999), cutoff))
        assertFalse(MaxMessageSyncPolicy.eligible(row().copy(uploaded = true), cutoff))
    }
    @Test fun dtoCannotSerializeTextOrConversationOrManager() {
        val json = Gson().toJson(MaxMessageSyncPolicy.payload(row()))
        assertTrue(json.contains("source_event_id"))
        for (field in listOf("PRIVATE_BODY", "messageText", "message_text", "senderName", "conversationTitle", "manager", "user_phone")) assertFalse(field, json.contains(field))
    }
    @Test fun onlyAcceptedSentIdsAreMarkedAndRetryIsSafe() = runBlocking {
        val pending = mutableListOf(row(11), row(12))
        val marked = mutableListOf<String>()
        var partial = true
        val sync = MaxMessageSync(
            { _, _ -> pending.filterNot { it.sourceEventId in marked } },
            { if (partial) setOf("event-11", "not-sent") else setOf("event-12") },
            { marked.add(it) })
        try { sync.batch(cutoff); fail("partial acknowledgement must retry") } catch (_: IllegalStateException) { }
        assertEquals(listOf("event-11"), marked)
        partial = false
        assertFalse(sync.batch(cutoff))
        assertEquals(listOf("event-11", "event-12"), marked)
    }
    @Test fun networkFailureDoesNotMarkAnything() = runBlocking {
        val marked = mutableListOf<String>()
        val sync = MaxMessageSync({ _, _ -> listOf(row()) }, { throw java.io.IOException() }, { marked.add(it) })
        try { sync.batch(cutoff); fail("network failure") } catch (_: java.io.IOException) { }
        assertTrue(marked.isEmpty())
    }
    @Test fun batchIsBoundedAndGroupsNeverReachUpload() = runBlocking {
        var sent = 0
        val sync = MaxMessageSync({ _, limit -> assertEquals(50, limit); (11L..220L).map { row(it) } },
            { messages -> sent = messages.size; messages.map { it.sourceEventId }.toSet() }, { })
        assertTrue(sync.batch(cutoff)); assertEquals(50, sent)
        val filtered = MaxMessageSync({ _, _ -> listOf(row().copy(isGroupConversation = true), row(10)) },
            { fail("ineligible upload"); emptySet() }, { fail("ineligible mark") })
        assertFalse(filtered.batch(cutoff))
    }
}
