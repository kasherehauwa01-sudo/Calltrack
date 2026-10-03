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

    @Test fun `repeated active notifications keep one outgoing session`() {
        val first = MaxCallStateMachine.posted(null, "key", "Имя", 2, false, 1_000)!!
        val repeated = MaxCallStateMachine.posted(first, "key", "Имя", 2, false, 2_000)!!
        assertEquals(MaxCallDirection.OUTGOING, repeated.direction)
        assertEquals(1_000, repeated.startedAt)
        assertEquals(1_000, repeated.answeredAt)
        assertEquals(first.finish(3_000).sourceEventId, repeated.finish(3_000).sourceEventId)
    }

    @Test fun `repeated posted keeps one session and initial timestamp`() {
        val first = MaxCallStateMachine.posted(null, "key", "Имя", 1, false, 1_000)!!
        val repeated = MaxCallStateMachine.posted(first, "key", "Имя", 1, false, 2_000)!!
        assertEquals(1_000, repeated.startedAt)
        assertEquals(first.notificationKey, repeated.notificationKey)
    }

    @Test fun `reused notification id can produce different stable events`() {
        val first = MaxCallStateMachine.posted(null, "same-key", "Имя", 2, false, 1_000)!!.finish(2_000)
        val second = MaxCallStateMachine.posted(null, "same-key", "Имя", 2, false, 3_000)!!.finish(4_000)
        assertNotEquals(first.sourceEventId, second.sourceEventId)
    }
}
