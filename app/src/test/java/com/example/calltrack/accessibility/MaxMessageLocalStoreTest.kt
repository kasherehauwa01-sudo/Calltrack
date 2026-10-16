package com.example.calltrack.accessibility

import com.example.calltrack.data.local.MaxMessageDao
import com.example.calltrack.data.local.MaxMessageEntity
import com.example.calltrack.notification.MaxIncomingMessageCandidate
import com.example.calltrack.notification.MaxIncomingMessageDetector
import kotlinx.coroutines.*
import org.junit.Assert.*
import org.junit.Test
import java.util.concurrent.ConcurrentHashMap

class MaxMessageLocalStoreTest {
    private class TestDao : MaxMessageDao {
        val rows = ConcurrentHashMap<String, MaxMessageEntity>()
        override suspend fun insert(message: MaxMessageEntity): Long = if (rows.putIfAbsent(message.sourceEventId, message) == null) 1 else -1
        override suspend fun getPending() = rows.values.filter { !it.uploaded }
        override suspend fun getBySourceEventId(sourceEventId: String) = rows[sourceEventId]
        override suspend fun markUploaded(id: Long) = Unit
        override suspend fun getCount() = rows.size
        override suspend fun getLatest(limit: Int) = rows.values.sortedByDescending { it.occurredAt }.take(limit)
    }
    private fun messages() = MaxIncomingMessageDetector.extract("chat", "Team", "shortcut", true,
        listOf(MaxIncomingMessageCandidate("private body", "private sender", "key:sender", 1000L)))

    @Test fun repeatedHistoryAndRecreatedStoreReuseDaoDeduplication() = runBlocking {
        val dao = TestDao(); val logs = mutableListOf<String>()
        val first = MaxMessageLocalStore(dao, this, logs::add)
        first.saveIncoming(messages()); coroutineContext.job.children.toList().joinAll()
        MaxMessageLocalStore(dao, this, logs::add).saveIncoming(messages())
        coroutineContext.job.children.toList().joinAll()
        assertEquals(1, dao.getCount()); assertTrue(logs.contains("duplicateMessageIgnored"))
        val item = dao.getLatest().single()
        assertEquals("incoming", item.direction); assertEquals("private body", item.messageText)
        assertEquals("private sender", item.senderName); assertEquals("Team", item.conversationTitle)
        assertEquals(item.senderName, item.contactName); assertTrue(item.isGroupConversation); assertFalse(item.uploaded)
        assertFalse(logs.joinToString().contains("private")); assertFalse(logs.joinToString().contains("Team"))
    }
    @Test fun outgoingStillUsesSameTableAndDirectionWithoutReadingText() = runBlocking {
        val dao = TestDao(); val store = MaxMessageLocalStore(dao, this) { }
        store.saveOutgoing("Contact", 1000); store.saveIncoming(messages())
        coroutineContext.job.children.toList().joinAll()
        val outgoing = dao.getLatest().first { it.direction == "outgoing" }
        assertEquals("Contact", outgoing.contactName); assertEquals(1000L, outgoing.occurredAt)
        assertTrue(outgoing.sourceEventId.startsWith("max-message-out-1000-"))
        assertEquals("", outgoing.messageText); assertEquals(2, dao.getCount())
    }
    @Test fun rapidConcurrentPostedMessagesAreInsertedOnlyOnce() = runBlocking {
        val dao = TestDao(); val store = MaxMessageLocalStore(dao, this) { }
        repeat(30) { store.saveIncoming(messages()) }
        coroutineContext.job.children.toList().joinAll()
        assertEquals(1, dao.getCount())
    }
}
