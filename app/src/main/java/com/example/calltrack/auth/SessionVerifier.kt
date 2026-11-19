package com.example.calltrack.auth

import okhttp3.OkHttpClient
import okhttp3.Request
import org.json.JSONObject

enum class SessionValidation { VALID, RETRYABLE, REAUTHENTICATION_REQUIRED, NO_SESSION }

internal data class SessionProfile(val id: Long, val login: String, val name: String, val role: String, val phone: String)
internal data class SessionCheck(val state: SessionValidation, val profile: SessionProfile? = null)

/** Read-only verification. Network errors must never revoke or erase a session. */
internal class SessionVerifier(private val client: OkHttpClient, private val endpoint: String) {
    fun validate(token: String): SessionCheck {
        if (token.isBlank()) return SessionCheck(SessionValidation.NO_SESSION)
        return try {
            val request = Request.Builder().url("$endpoint?action=me")
                .header("Authorization", "Bearer $token").build()
            client.newCall(request).execute().use { response ->
                val body = runCatching { JSONObject(response.body?.string().orEmpty()) }.getOrNull()
                val rejected = response.code == 401 && body?.optString("status") == "error" &&
                    body.optString("message") in setOf("Требуется авторизация", "Android-сессия недействительна")
                if (rejected) return SessionCheck(SessionValidation.REAUTHENTICATION_REQUIRED)
                if (!response.isSuccessful || body?.optString("status") != "success") {
                    return SessionCheck(SessionValidation.RETRYABLE)
                }
                val user = body.getJSONObject("data").getJSONObject("user")
                val profile = SessionProfile(user.getLong("id"), user.getString("login"),
                    user.getString("display_name"), user.getString("role"), user.getString("user_phone"))
                if (profile.id <= 0 || profile.login.isBlank() || profile.phone.isBlank()) {
                    return SessionCheck(SessionValidation.RETRYABLE)
                }
                SessionCheck(SessionValidation.VALID, profile)
            }
        } catch (error: java.io.IOException) {
            SessionCheck(SessionValidation.RETRYABLE)
        } catch (error: org.json.JSONException) {
            SessionCheck(SessionValidation.RETRYABLE)
        }
    }
}
