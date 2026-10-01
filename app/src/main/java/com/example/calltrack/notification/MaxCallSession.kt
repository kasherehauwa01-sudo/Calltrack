package com.example.calltrack.notification

import java.security.MessageDigest

enum class MaxCallState { INCOMING_RINGING, ACTIVE }
enum class MaxCallDirection { INCOMING, OUTGOING }
enum class MaxCallStatus { ANSWERED, MISSED }

data class MaxCallSession(
    val notificationKey: String,
    val maxContactName: String,
    val startedAt: Long,
    val state: MaxCallState,
    val direction: MaxCallDirection,
    val answeredAt: Long? = null,
    val isVideo: Boolean = false,
    val sourceEventId: String = MaxCallEventId.create(notificationKey, maxContactName, startedAt)
) {
    fun onActive(at: Long): MaxCallSession = copy(
        state = MaxCallState.ACTIVE,
        answeredAt = answeredAt ?: at
    )

    fun finish(at: Long): MaxCallResult {
        val answered = answeredAt
        return MaxCallResult(
            sourceEventId = sourceEventId,
            maxContactName = maxContactName,
            direction = direction,
            status = if (direction == MaxCallDirection.INCOMING && answered == null) MaxCallStatus.MISSED else MaxCallStatus.ANSWERED,
            startedAt = startedAt,
            answeredAt = answered,
            endedAt = at,
            durationSeconds = if (answered == null) 0 else ((at - answered).coerceAtLeast(0) / 1_000),
            ringingDurationSeconds = if (direction == MaxCallDirection.INCOMING) {
                (((answered ?: at) - startedAt).coerceAtLeast(0) / 1_000)
            } else null,
            isVideo = isVideo
        )
    }
}

object MaxCallEventId {
    fun create(notificationKey: String, contactName: String, startedAt: Long): String {
        val source = "$notificationKey\u0000${MaxContactNameMatcher.normalize(contactName)}\u0000$startedAt"
        val digest = MessageDigest.getInstance("SHA-256").digest(source.toByteArray(Charsets.UTF_8))
        return "max:" + digest.joinToString("") { "%02x".format(it) }
    }
}

data class MaxCallResult(
    val sourceEventId: String,
    val maxContactName: String,
    val direction: MaxCallDirection,
    val status: MaxCallStatus,
    val startedAt: Long,
    val answeredAt: Long?,
    val endedAt: Long,
    val durationSeconds: Long,
    val ringingDurationSeconds: Long?,
    val isVideo: Boolean
)

object MaxCallStateMachine {
    fun posted(
        current: MaxCallSession?,
        notificationKey: String,
        contactName: String,
        callType: Int?,
        isVideo: Boolean,
        at: Long,
        isCallStyle: Boolean = true
    ): MaxCallSession? = if (!isCallStyle) current else when (callType) {
        1 -> current ?: MaxCallSession(
            notificationKey, contactName, at, MaxCallState.INCOMING_RINGING,
            MaxCallDirection.INCOMING, isVideo = isVideo
        )
        2 -> current?.onActive(at) ?: MaxCallSession(
            notificationKey, contactName, at, MaxCallState.ACTIVE,
            MaxCallDirection.OUTGOING, answeredAt = at, isVideo = isVideo
        )
        else -> current
    }

    fun removed(current: MaxCallSession?, at: Long): MaxCallResult? = current?.finish(at)
}
