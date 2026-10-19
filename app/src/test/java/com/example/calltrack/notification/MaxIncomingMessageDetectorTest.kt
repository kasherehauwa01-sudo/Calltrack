package com.example.calltrack.notification

import org.junit.Assert.*
import org.junit.Test

class MaxIncomingMessageDetectorTest {
    private fun candidate(name: String = "Офис Менеджер ВР", user: String = "144673255", time: Long = 1792156405906L) =
        MaxIncomingMessageCandidate(name, user, time)
    private fun extract(message: MaxIncomingMessageCandidate = candidate(), shortcut: String = "166503071", group: Boolean? = false) =
        MaxIncomingMessageDetector.extract("notification:account:1", shortcut, group, listOf(message))
    private fun matches(template: String = MaxIncomingMessageDetector.MESSAGING_STYLE, pkg: String = "ru.oneme.app", category: String = "msg", messages: Boolean = true, call: Boolean = false, summary: Boolean = false, ongoing: Boolean = false) =
        MaxIncomingMessageDetector.isMessageNotification(pkg, category, template, messages, call, summary, ongoing)

    @Test fun personalMessageUsesActualPersonAndShortcutMetadata() {
        val message = extract().single()
        assertEquals("Офис Менеджер ВР", message.senderName)
        assertEquals("144673255", message.maxUserId); assertEquals("166503071", message.shortcutId)
        assertEquals(1792156405906L, message.occurredAt)
        assertTrue(message.sourceEventId.matches(Regex("max-message-in-v2-[0-9a-f]{64}")))
    }
    @Test fun messagingStyleIsAccepted() { assertTrue(matches()) }
    @Test fun callStyleAndCallMarkersAreRejected() {
        assertFalse(matches(template = "android.app.Notification\$CallStyle")); assertFalse(matches(call = true))
    }
    @Test fun secondaryBigTextIsRejected() { assertFalse(matches(template = "android.app.Notification\$BigTextStyle", messages = false, summary = true)) }
    @Test fun invalidCandidatesAreRejected() {
        assertFalse(matches(pkg = "other.app")); assertFalse(matches(category = "call")); assertFalse(matches(messages = false))
        assertFalse(matches(summary = true)); assertFalse(matches(ongoing = true))
    }
    @Test fun groupAndUnknownGroupFlagAreRejected() { assertTrue(extract(group = true).isEmpty()); assertTrue(extract(group = null).isEmpty()) }
    @Test fun absentOrBlankSenderDoesNotCreateFalseContact() { assertTrue(extract(candidate(name = "")).isEmpty()); assertTrue(extract(candidate(name = "   ")).isEmpty()) }
    @Test fun selfAndAttachmentsAndInvalidTimeAreRejected() {
        assertTrue(extract(candidate().copy(isSelf = true)).isEmpty())
        assertTrue(extract(candidate().copy(hasAttachment = true)).isEmpty()); assertTrue(extract(candidate(time = 0)).isEmpty())
    }
    @Test fun keylessSenderRequiresActualDialogShortcut() {
        val result = extract(candidate(user = "")).single()
        assertEquals("", result.maxUserId); assertTrue(extract(candidate(user = ""), shortcut = "").isEmpty())
    }
    @Test fun senderKeyIsPreferredOverDisplayName() { assertEquals(extract().single().sourceEventId, extract(candidate(name = "Renamed")).single().sourceEventId) }
    @Test fun repeatedDeliveryHasSameFingerprint() { assertEquals(extract(), extract()) }
    @Test fun differentTimestampProducesDifferentEvent() { assertNotEquals(extract().single().sourceEventId, extract(candidate(time = 1792156405907L)).single().sourceEventId) }
    @Test fun differentUserAtSameTimeProducesDifferentEvent() { assertNotEquals(extract().single().sourceEventId, extract(candidate(user = "other")).single().sourceEventId) }
    @Test fun differentShortcutProducesDifferentEvent() { assertNotEquals(extract().single().sourceEventId, extract(shortcut = "other").single().sourceEventId) }
    @Test fun messageContentCannotEnterMetadataDtoOrFingerprint() {
        assertTrue(MaxIncomingMessageCandidate::class.java.declaredFields.none { it.name.contains("text", ignoreCase = true) })
        assertTrue(MaxIncomingMessage::class.java.declaredFields.none { it.name.contains("text", ignoreCase = true) })
        assertFalse(extract().single().sourceEventId.contains("144673255"))
    }
    @Test fun historyUsesLastValidIncomingPersonWithoutSelfFallback() {
        val messages = listOf(candidate(time = 1), candidate(time = 2), candidate(name = "Вы", user = "self", time = 3).copy(isSelf = true))
        val result = MaxIncomingMessageDetector.extract("chat", "shortcut", false, messages).single()
        assertEquals(2L, result.occurredAt); assertEquals("144673255", result.maxUserId)
    }
}
