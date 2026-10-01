package com.example.calltrack.notification

import org.junit.Assert.*
import org.junit.Test

class MaxCallStateMachineTest {
    @Test fun `incoming active removed is answered incoming`() {
        val ringing = MaxCallStateMachine.posted(null, "key", "Имя", 1, false, 1_000)!!
        val active = MaxCallStateMachine.posted(ringing, "key", "Имя", 2, false, 4_000)!!
        val result = active.finish(14_000)
        assertEquals(MaxCallDirection.INCOMING, result.direction)
        assertEquals(MaxCallStatus.ANSWERED, result.status)
        assertEquals(10, result.durationSeconds)
        assertEquals(3, result.ringingDurationSeconds)
    }

    @Test fun `incoming removed is missed`() {
        val result = MaxCallStateMachine.posted(null, "key", "Имя", 1, false, 1_000)!!.finish(6_000)
        assertEquals(MaxCallStatus.MISSED, result.status)
        assertEquals(0, result.durationSeconds)
    }

    @Test fun `active without incoming is outgoing`() {
        val result = MaxCallStateMachine.posted(null, "key", "Имя", 2, false, 1_000)!!.finish(6_000)
        assertEquals(MaxCallDirection.OUTGOING, result.direction)
    }

    @Test fun `repeated posted keeps one session and initial timestamp`() {
        val first = MaxCallStateMachine.posted(null, "key", "Имя", 1, false, 1_000)!!
        val repeated = MaxCallStateMachine.posted(first, "key", "Имя", 1, false, 2_000)!!
        assertEquals(1_000, repeated.startedAt)
        assertEquals(first.notificationKey, repeated.notificationKey)
    }

    @Test fun `messaging missed notification does not create second call`() {
        val ringing = MaxCallStateMachine.posted(null, "key", "Имя", 1, false, 1_000)!!
        val afterMessage = MaxCallStateMachine.posted(ringing, "message-key", "Имя", null, false, 2_000, isCallStyle = false)
        assertSame(ringing, afterMessage)
        assertEquals(MaxCallStatus.MISSED, afterMessage!!.finish(3_000).status)
    }

    @Test fun `many active updates keep first answer time`() {
        val first = MaxCallStateMachine.posted(null, "key", "Имя", 2, false, 1_000)!!
        val second = MaxCallStateMachine.posted(first, "key", "Имя", 2, false, 4_000)!!
        assertEquals(1_000, second.answeredAt)
        assertEquals(first.sourceEventId, second.sourceEventId)
    }

    @Test fun `duplicate incoming and active updates keep one incoming call`() {
        var session = MaxCallStateMachine.posted(null, "key", "Имя", 1, false, 1_000)
        session = MaxCallStateMachine.posted(session, "key", "Имя", 1, false, 2_000)
        session = MaxCallStateMachine.posted(session, "key", "Имя", 2, false, 4_000)
        session = MaxCallStateMachine.posted(session, "key", "Имя", 2, false, 5_000)
        val result = MaxCallStateMachine.removed(session, 9_000)!!
        assertEquals(MaxCallDirection.INCOMING, result.direction)
        assertEquals(MaxCallStatus.ANSWERED, result.status)
        assertEquals(4L, result.answeredAt?.div(1_000))
        assertEquals(5L, result.durationSeconds)
    }

    @Test fun `second removed after session cleanup produces no result`() {
        var session = MaxCallStateMachine.posted(null, "key", "Имя", 2, false, 1_000)
        assertNotNull(MaxCallStateMachine.removed(session, 3_000))
        session = null
        assertNull(MaxCallStateMachine.removed(session, 4_000))
    }

    @Test fun `restored ringing session remains incoming after answer`() {
        val beforeRestart = MaxCallStateMachine.posted(null, "key", "Имя", 1, false, 1_000)!!
        val restored = beforeRestart.copy()
        val answered = MaxCallStateMachine.posted(restored, "key", "Имя", 2, false, 4_000)!!
        assertEquals(MaxCallDirection.INCOMING, answered.direction)
        assertEquals(beforeRestart.sourceEventId, answered.sourceEventId)
    }

    @Test fun `restored active session keeps answer time and event id`() {
        val beforeRestart = MaxCallStateMachine.posted(null, "key", "Имя", 2, false, 1_000)!!
        val restored = beforeRestart.copy()
        val result = MaxCallStateMachine.removed(restored, 6_000)!!
        assertEquals(5L, result.durationSeconds)
        assertEquals(beforeRestart.sourceEventId, result.sourceEventId)
    }

    @Test fun `reused notification id can produce different stable events`() {
        val first = MaxCallStateMachine.posted(null, "same-key", "Имя", 2, false, 1_000)!!.finish(2_000)
        val second = MaxCallStateMachine.posted(null, "same-key", "Имя", 2, false, 3_000)!!.finish(4_000)
        assertNotEquals(first.sourceEventId, second.sourceEventId)
        assertTrue(first.sourceEventId.matches(Regex("max:[0-9a-f]{64}")))
    }
}
