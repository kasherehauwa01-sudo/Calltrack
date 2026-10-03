package com.example.calltrack.notification

import android.content.Context

/** Сохраняет активную сессию и завершённый результат до постановки звонка в Room. */
class MaxCallSessionStore(context: Context) {
    private val prefs = context.getSharedPreferences(PREFERENCES_NAME, Context.MODE_PRIVATE)

    fun save(value: MaxCallSession) {
        prefs.edit()
            .putString("active_key", value.notificationKey)
            .putString("active_name", value.maxContactName)
            .putLong("active_started", value.startedAt)
            .putString("active_state", value.state.name)
            .putString("active_direction", value.direction.name)
            .putLong("active_answered", value.answeredAt ?: -1L)
            .putBoolean("active_video", value.isVideo)
            .apply()
    }

    fun load(): MaxCallSession? = runCatching {
        val key = prefs.getString("active_key", null) ?: return null
        MaxCallSession(
            notificationKey = key,
            maxContactName = prefs.getString("active_name", "").orEmpty(),
            startedAt = prefs.getLong("active_started", 0L),
            state = MaxCallState.valueOf(prefs.getString("active_state", "").orEmpty()),
            direction = MaxCallDirection.valueOf(prefs.getString("active_direction", "").orEmpty()),
            answeredAt = prefs.getLong("active_answered", -1L).takeIf { it >= 0 },
            isVideo = prefs.getBoolean("active_video", false)
        )
    }.getOrNull()

    fun clearActive() {
        prefs.edit()
            .remove("active_key").remove("active_name").remove("active_started")
            .remove("active_state").remove("active_direction").remove("active_answered")
            .remove("active_video")
            .apply()
    }

    fun saveCompleted(value: MaxCallResult) {
        prefs.edit()
            .putString("completed_event", value.sourceEventId)
            .putString("completed_name", value.maxContactName)
            .putString("completed_direction", value.direction.name)
            .putString("completed_status", value.status.name)
            .putLong("completed_started", value.startedAt)
            .putLong("completed_answered", value.answeredAt ?: -1L)
            .putLong("completed_ended", value.endedAt)
            .putLong("completed_duration", value.durationSeconds)
            .putLong("completed_ringing", value.ringingDurationSeconds ?: -1L)
            .putBoolean("completed_video", value.isVideo)
            .commit()
    }

    fun loadCompleted(): MaxCallResult? = runCatching {
        val eventId = prefs.getString("completed_event", null) ?: return null
        MaxCallResult(
            sourceEventId = eventId,
            maxContactName = prefs.getString("completed_name", "").orEmpty(),
            direction = MaxCallDirection.valueOf(prefs.getString("completed_direction", "").orEmpty()),
            status = MaxCallStatus.valueOf(prefs.getString("completed_status", "").orEmpty()),
            startedAt = prefs.getLong("completed_started", 0L),
            answeredAt = prefs.getLong("completed_answered", -1L).takeIf { it >= 0 },
            endedAt = prefs.getLong("completed_ended", 0L),
            durationSeconds = prefs.getLong("completed_duration", 0L),
            ringingDurationSeconds = prefs.getLong("completed_ringing", -1L).takeIf { it >= 0 },
            isVideo = prefs.getBoolean("completed_video", false)
        )
    }.getOrNull()

    fun clearCompleted(sourceEventId: String) {
        if (prefs.getString("completed_event", null) != sourceEventId) return
        prefs.edit()
            .remove("completed_event").remove("completed_name").remove("completed_direction")
            .remove("completed_status").remove("completed_started").remove("completed_answered")
            .remove("completed_ended").remove("completed_duration").remove("completed_ringing")
            .remove("completed_video")
            .apply()
    }

    companion object {
        private const val PREFERENCES_NAME = "max_call_session"
    }
}
