package com.example.calltrack.notification

import org.junit.Assert.*
import org.junit.Test

class MaxIncomingMessageDetectorTest {
    private fun candidate(text: String? = "Hello", time: Long = 1000L) =
        MaxIncomingMessageCandidate(text, "Alice", "key:alice", time)

    private fun extract(vararg messages: MaxIncomingMessageCandidate, chat: String = "chat:one", group: Boolean = false) =
        MaxIncomingMessageDetector.extract(chat, if (group) "Team" else "", "shortcut", group, messages.toList())

    private fun matches(template: String = MaxIncomingMessageDetector.MESSAGING_STYLE, pkg: String = "ru.oneme.app", category: String = "msg", messages: Boolean = true, call: Boolean = false, summary: Boolean = false, ongoing: Boolean = false) =
        MaxIncomingMessageDetector.isMessageNotification(pkg, category, template, messages, call, summary, ongoing)

    @Test fun messagingStyleIsAccepted() { assertTrue(matches()) }
    @Test fun callStyleAndCallMarkersAreRejected() {
        assertFalse(matches(template = "android.app.Notification\$CallStyle"))
        assertFalse(matches(call = true))
    }
    @Test fun secondaryBigTextIsRejected() { assertFalse(matches(template = "android.app.Notification\$BigTextStyle", messages = false, summary = true)) }
    @Test fun onlyMaxMessageHistoryIsAccepted() {
        assertFalse(matches(pkg = "other.app")); assertFalse(matches(category = "call"))
        assertFalse(matches(messages = false)); assertFalse(matches(summary = true)); assertFalse(matches(ongoing = true))
    }
    @Test fun singleTextMessageIsPreserved() {
        val item = extract(candidate("  Hello\nworld  ")).single()
        assertEquals("  Hello\nworld  ", item.messageText); assertEquals("Alice", item.senderName)
        assertEquals(1000L, item.occurredAt)
        assertTrue(item.sourceEventId.matches(Regex("max-message-in-[0-9a-f]{64}")))
        assertFalse(item.sourceEventId.contains("Alice"))
    }
    @Test fun historyContainsEveryDistinctMessage() {
        assertEquals(3, extract(candidate("one"), candidate("two", 1001), candidate("three", 1002)).size)
    }
    @Test fun repeatedPostedHasIdenticalPersistentKeys() {
        assertEquals(extract(candidate()).single().sourceEventId, extract(candidate()).single().sourceEventId)
        assertEquals(1, extract(candidate(), candidate()).size)
    }
    @Test fun differentTextsAtSameTimeRemainDistinct() { assertEquals(2, extract(candidate("one"), candidate("two")).size) }
    @Test fun identicalTextsAtDifferentTimesRemainDistinct() { assertEquals(2, extract(candidate(), candidate(time = 1001)).size) }
    @Test fun emptyTextAndAttachmentsAreIgnored() {
        assertTrue(extract(candidate(null), candidate(""), candidate(" \n"), candidate().copy(hasAttachment = true)).isEmpty())
    }
    @Test fun selfMessagesAndUnknownSendersAreIgnored() {
        assertTrue(extract(candidate().copy(isSelf = true), candidate().copy(senderName = ""), candidate().copy(timestamp = 0)).isEmpty())
    }
    @Test fun groupSenderAndConversationAreSeparate() {
        val item = extract(candidate(), group = true).single()
        assertEquals("Team", item.conversationTitle); assertEquals("Alice", item.senderName); assertTrue(item.isGroupConversation)
    }
    @Test fun differentConversationsAndSendersHaveDifferentKeys() {
        assertNotEquals(extract(candidate(), chat = "one").single().sourceEventId, extract(candidate(), chat = "two").single().sourceEventId)
        assertNotEquals(extract(candidate()).single().sourceEventId, extract(candidate().copy(senderIdentity = "key:bob")).single().sourceEventId)
    }
    @Test fun displayTitleChangesDoNotChangeIdentity() {
        val first = extract(candidate()).single()
        val second = MaxIncomingMessageDetector.extract("chat:one", "New title", "shortcut", false, listOf(candidate())).single()
        assertEquals(first.sourceEventId, second.sourceEventId)
    }
    @Test fun ambiguousFieldDelimitersDoNotCollide() {
        val first = extract(candidate().copy(senderIdentity = "b|c"), chat = "a").single()
        val second = extract(candidate().copy(senderIdentity = "c"), chat = "a|b").single()
        assertNotEquals(first.sourceEventId, second.sourceEventId)
    }
}
