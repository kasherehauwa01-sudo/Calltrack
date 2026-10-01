package com.example.calltrack.notification

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
    val isVideo: Boolean = false
) {
    fun onActive(at: Long): MaxCallSession = copy(
        state = MaxCallState.ACTIVE,
        answeredAt = answeredAt ?: at
    )

    fun finish(at: Long): MaxCallResult {
        val answered = answeredAt
        return MaxCallResult(
            sourceEventId = "max:${notificationKey.hashCode().toUInt().toString(16)}:$startedAt",
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
        callType: Int,
        isVideo: Boolean,
        at: Long
    ): MaxCallSession? = when (callType) {
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
}
