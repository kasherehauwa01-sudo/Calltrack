package com.example.calltrack.notification

import android.content.Context

/** Минимальное сохранение незавершённого звонка на случай уничтожения процесса. */
class MaxCallSessionStore(context: Context) {
    private val prefs = context.getSharedPreferences("max_call_session", Context.MODE_PRIVATE)

    fun save(value: MaxCallSession) {
        prefs.edit().putString("key", value.notificationKey).putString("name", value.maxContactName)
            .putLong("started", value.startedAt).putString("state", value.state.name)
            .putString("direction", value.direction.name).putLong("answered", value.answeredAt ?: -1L)
            .putBoolean("video", value.isVideo).putString("event_id", value.sourceEventId).apply()
    }

    fun load(): MaxCallSession? = runCatching {
        val key = prefs.getString("key", null) ?: return null
        MaxCallSession(
            key, prefs.getString("name", "").orEmpty(), prefs.getLong("started", 0L),
            MaxCallState.valueOf(prefs.getString("state", "")!!),
            MaxCallDirection.valueOf(prefs.getString("direction", "")!!),
            prefs.getLong("answered", -1L).takeIf { it >= 0 }, prefs.getBoolean("video", false),
            prefs.getString("event_id", null) ?: MaxCallEventId.create(key, prefs.getString("name", "").orEmpty(), prefs.getLong("started", 0L))
        )
    }.getOrNull()

    fun clearActive() = prefs.edit().remove("key").remove("name").remove("started").remove("state")
        .remove("direction").remove("answered").remove("video").remove("event_id").apply()

    fun saveCompleted(result: MaxCallResult, contact: ResolvedContact) {
        prefs.edit().putString("completed_id", result.sourceEventId)
            .putString("completed_name", result.maxContactName)
            .putString("completed_direction", result.direction.name)
            .putString("completed_status", result.status.name)
            .putLong("completed_started", result.startedAt)
            .putLong("completed_answered", result.answeredAt ?: -1L)
            .putLong("completed_ended", result.endedAt)
            .putLong("completed_duration", result.durationSeconds)
            .putLong("completed_ringing", result.ringingDurationSeconds ?: -1L)
            .putBoolean("completed_video", result.isVideo)
            .putString("completed_phone", contact.phone)
            .putString("completed_resolution", contact.status.name).commit()
    }

    fun loadCompleted(): PendingMaxCall? = runCatching {
        val id = prefs.getString("completed_id", null) ?: return null
        PendingMaxCall(
            MaxCallResult(
                id, prefs.getString("completed_name", "").orEmpty(),
                MaxCallDirection.valueOf(prefs.getString("completed_direction", "")!!),
                MaxCallStatus.valueOf(prefs.getString("completed_status", "")!!),
                prefs.getLong("completed_started", 0L),
                prefs.getLong("completed_answered", -1L).takeIf { it >= 0 },
                prefs.getLong("completed_ended", 0L), prefs.getLong("completed_duration", 0L),
                prefs.getLong("completed_ringing", -1L).takeIf { it >= 0 }, prefs.getBoolean("completed_video", false)
            ),
            ResolvedContact(prefs.getString("completed_phone", null), ContactResolutionStatus.valueOf(prefs.getString("completed_resolution", "ERROR")!!))
        )
    }.getOrNull()

    fun clearCompleted() = prefs.edit().remove("completed_id").remove("completed_name")
        .remove("completed_direction").remove("completed_status").remove("completed_started")
        .remove("completed_answered").remove("completed_ended").remove("completed_duration")
        .remove("completed_ringing").remove("completed_video").remove("completed_phone")
        .remove("completed_resolution").apply()
}

data class PendingMaxCall(val result: MaxCallResult, val contact: ResolvedContact)
