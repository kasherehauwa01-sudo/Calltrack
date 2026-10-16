package com.example.calltrack.data.local

import androidx.room.Room
import androidx.test.ext.junit.runners.AndroidJUnit4
import androidx.test.platform.app.InstrumentationRegistry
import kotlinx.coroutines.async
import kotlinx.coroutines.awaitAll
import kotlinx.coroutines.runBlocking
import org.junit.Assert.*
import org.junit.Test
import org.junit.runner.RunWith
import java.util.UUID

@RunWith(AndroidJUnit4::class)
class MaxMessageRoomTest {
    @Test fun realDaoDeduplicatesAcrossDatabaseReopenAndConcurrentInserts() = runBlocking {
        val context = InstrumentationRegistry.getInstrumentation().targetContext
        val name = "max-room-test-${UUID.randomUUID()}.db"
        val message = MaxMessageEntity(sourceEventId = "test-fingerprint", direction = "incoming",
            contactName = "Alice", senderName = "Alice", conversationTitle = "",
            messageText = "", maxUserId = "alice-id", isGroupConversation = false, occurredAt = 1000L)
        try {
            val first = Room.databaseBuilder(context, CallDatabase::class.java, name).build()
            try {
                val db = first
                val ids = List(20) { async { db.maxMessageDao().insert(message) } }.awaitAll()
                assertEquals(1, ids.count { it != -1L }); assertEquals(1, db.maxMessageDao().getCount())
            } finally { first.close() }
            val second = Room.databaseBuilder(context, CallDatabase::class.java, name).build()
            try {
                val db = second
                assertEquals(-1L, db.maxMessageDao().insert(message))
                assertEquals("", db.maxMessageDao().getLatest().single().messageText)
                assertEquals("alice-id", db.maxMessageDao().getLatest().single().maxUserId)
                val outgoing = MaxMessageEntity(sourceEventId = "outgoing-test", direction = "outgoing", contactName = "Contact", occurredAt = 2000L)
                assertTrue(db.maxMessageDao().insert(outgoing) != -1L)
                assertEquals(2, db.maxMessageDao().getCount())
            } finally { second.close() }
        } finally { context.deleteDatabase(name) }
    }
}
