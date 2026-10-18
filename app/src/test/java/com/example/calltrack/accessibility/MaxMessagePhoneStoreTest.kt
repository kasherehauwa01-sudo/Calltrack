package com.example.calltrack.accessibility

import com.example.calltrack.data.local.MaxMessageDao
import com.example.calltrack.data.local.MaxMessageEntity
import com.example.calltrack.maxsync.MaxMessageSyncPolicy
import com.example.calltrack.notification.*
import kotlinx.coroutines.*
import org.junit.Assert.*
import org.junit.Test

class MaxMessagePhoneStoreTest {
    private class TestDao : MaxMessageDao {
        val rows = linkedMapOf<String,MaxMessageEntity>()
        override suspend fun insert(message: MaxMessageEntity): Long =
            if (rows.containsKey(message.sourceEventId)) -1 else { rows[message.sourceEventId]=message;rows.size.toLong() }
        override suspend fun getPending() = rows.values.filter { !it.uploaded }
        override suspend fun getBySourceEventId(sourceEventId: String) = rows[sourceEventId]
        override suspend fun markUploaded(id: Long) = Unit
        override suspend fun getCount() = rows.size
        override suspend fun getLatest(limit: Int) = rows.values.toList().takeLast(limit)
    }
    private fun incoming() = MaxIncomingMessageDetector.extract("chat","shortcut",false,
        listOf(MaxIncomingMessageCandidate("Иван Петров","user-123",1000L)))

    @Test fun incomingAndOutgoingAreEnrichedWithoutAlteringIdentityOrText() = runBlocking {
        val dao=TestDao()
        val resolver=MaxMessagePhoneResolver { name ->
            assertTrue(MaxContactNameMatcher.matches(name,"Иван Петров"))
            MaxMessagePhoneResolver.resolveCandidates(listOf(ContactCandidate(1,listOf("+7 (999) 123-45-67"))))
        }
        val store=MaxMessageLocalStore(dao,this,{},resolver::resolve)
        val messages=incoming()
        store.saveIncoming(messages);store.saveOutgoing("Иван Петров",2000L)
        coroutineContext.job.children.toList().joinAll()
        assertEquals(2,dao.rows.size)
        val incoming=dao.rows.values.single { it.direction=="incoming" }
        val outgoing=dao.rows.values.single { it.direction=="outgoing" }
        assertEquals(messages.single().sourceEventId,incoming.sourceEventId)
        assertEquals("user-123",incoming.maxUserId);assertEquals("shortcut",incoming.shortcutId)
        assertEquals("",outgoing.maxUserId);assertEquals("",outgoing.shortcutId);assertEquals("",outgoing.senderName)
        for (row in dao.rows.values) {
            assertEquals("9991234567",row.phone)
            assertEquals("",row.messageText);assertEquals("",row.conversationTitle)
            assertFalse(row.isGroupConversation);assertFalse(row.uploaded)
            assertEquals(row.phone,MaxMessageSyncPolicy.payload(row).phone)
        }
    }
    @Test fun missingContactPermissionOrProviderErrorNeverDropsEitherDirection() = runBlocking {
        for (lookup in listOf<(String)->String>({ "" }, { throw SecurityException() }, { throw IllegalStateException() })) {
            val dao=TestDao();val store=MaxMessageLocalStore(dao,this,{},lookup)
            store.saveIncoming(incoming());store.saveOutgoing("Иван Петров",2000)
            coroutineContext.job.children.toList().joinAll()
            assertEquals(2,dao.rows.size)
            assertTrue(dao.rows.values.all { it.phone=="" })
        }
    }
    @Test fun ambiguousContactDoesNotPreventSaveAndRepeatedIncomingStillDeduplicates() = runBlocking {
        val dao=TestDao()
        val resolver=MaxMessagePhoneResolver { MaxMessagePhoneResolver.resolveCandidates(
            listOf(ContactCandidate(1,listOf("79991111111")),ContactCandidate(2,listOf("79992222222")))) }
        val store=MaxMessageLocalStore(dao,this,{},resolver::resolve)
        store.saveIncoming(incoming());store.saveIncoming(incoming());store.saveOutgoing("Иван Петров",2000)
        coroutineContext.job.children.toList().joinAll()
        assertEquals(2,dao.rows.size);assertTrue(dao.rows.values.all { it.phone=="" })
    }
}
