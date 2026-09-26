package com.example.calltrack.auth

import android.content.Context
import androidx.security.crypto.EncryptedSharedPreferences
import androidx.security.crypto.MasterKey

class AuthStore(context: Context) {
    private val prefs = EncryptedSharedPreferences.create(
        context,
        "android_auth",
        MasterKey.Builder(context).setKeyScheme(MasterKey.KeyScheme.AES256_GCM).build(),
        EncryptedSharedPreferences.PrefKeyEncryptionScheme.AES256_SIV,
        EncryptedSharedPreferences.PrefValueEncryptionScheme.AES256_GCM
    )

    val token: String get() = prefs.getString("token", "").orEmpty()
    val login: String get() = prefs.getString("login", "").orEmpty()
    val isAuthenticated: Boolean get() = token.isNotBlank()

    fun save(token: String, id: Long, login: String, name: String, role: String) {
        prefs.edit().putString("token", token).putLong("user_id", id).putString("login", login).putString("display_name", name)
            .putString("role", role).apply()
    }

    fun clear() = prefs.edit().clear().apply()
}
