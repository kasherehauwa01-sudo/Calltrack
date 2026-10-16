package com.example.calltrack.notification

import android.app.Notification
import android.app.Person
import android.content.Context
import android.os.Build
import android.os.Process
import android.service.notification.StatusBarNotification
import androidx.test.ext.junit.runners.AndroidJUnit4
import androidx.test.platform.app.InstrumentationRegistry
import org.junit.Assert.*
import org.junit.Assume.assumeTrue
import org.junit.Test
import org.junit.runner.RunWith

/** Real framework Message/Bundle restoration; run on an Android 26+ device or emulator. */
@RunWith(AndroidJUnit4::class)
class MaxIncomingNotificationExtractorTest {
    private val context: Context get() = InstrumentationRegistry.getInstrumentation().targetContext

    @Suppress("DEPRECATION")
    private fun notification(style: Notification.Style): StatusBarNotification {
        val n = Notification.Builder(context, "max-incoming-test")
            .setSmallIcon(android.R.drawable.ic_dialog_info).setCategory(Notification.CATEGORY_MESSAGE)
            .setShortcutId("chat").setStyle(style).build()
        return StatusBarNotification("ru.oneme.app", "ru.oneme.app", 1, "chat", Process.myUid(), Process.myPid(), n, Process.myUserHandle(), null, 9000L)
    }

    @Suppress("DEPRECATION")
    @Test fun messagingHistoryIsRestoredAndSelfAndEmptyMessagesAreSkipped() {
        val style = Notification.MessagingStyle("Me")
            .addMessage("old", 1000, "Alice").addMessage("new", 1001, "Alice")
            .addMessage("", 1002, "Alice").addMessage("local reply", 1003, null as CharSequence?)
        val sbn = notification(style)
        val first = MaxIncomingNotificationExtractor.extract(sbn)
        assertEquals(listOf("old", "new"), first.map { it.messageText })
        assertEquals(first, MaxIncomingNotificationExtractor.extract(sbn))
    }

    @Test fun bigTextSecondaryIsIgnored() {
        assertTrue(MaxIncomingNotificationExtractor.extract(notification(Notification.BigTextStyle().bigText("body"))).isEmpty())
    }

    @Test fun personAndConversationRemainSeparate() {
        assumeTrue(Build.VERSION.SDK_INT >= 28)
        val self = Person.Builder().setName("Me").setKey("self").build()
        val sender = Person.Builder().setName("Alice").setKey("alice").build()
        val style = Notification.MessagingStyle(self).setConversationTitle("Team").setGroupConversation(true)
            .addMessage(Notification.MessagingStyle.Message("body", 1000, sender))
        val item = MaxIncomingNotificationExtractor.extract(notification(style)).single()
        assertEquals("Team", item.conversationTitle); assertEquals("Alice", item.senderName)
        assertTrue(item.isGroupConversation)
    }
}
