package com.example.calltrack.notification

import android.content.Context

/** Минимальное сохранение незавершённого звонка на случай уничтожения процесса. */
class MaxCallSessionStore(context: Context) {
    private val prefs = context.getSharedPreferences("max_call_session", Context.MODE_PRIVATE)

    fun save(value: MaxCallSession) {
        prefs.edit().putString("key", value.notificationKey).putString("name", value.maxContactName)
            .putLong("started", value.startedAt).putString("state", value.state.name)
            .putString("direction", value.direction.name).putLong("answered", value.answeredAt ?: -1L)
            .putBoolean("video", value.isVideo).apply()
    }

    fun load(): MaxCallSession? = runCatching {
        val key = prefs.getString("key", null) ?: return null
        MaxCallSession(
            key, prefs.getString("name", "").orEmpty(), prefs.getLong("started", 0L),
            MaxCallState.valueOf(prefs.getString("state", "")!!),
            MaxCallDirection.valueOf(prefs.getString("direction", "")!!),
            prefs.getLong("answered", -1L).takeIf { it >= 0 }, prefs.getBoolean("video", false)
        )
    }.getOrNull()

    fun clear() = prefs.edit().clear().apply()
}
