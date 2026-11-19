package com.example.calltrack.auth

import android.content.Context
import android.content.SharedPreferences
import android.util.Log
import androidx.security.crypto.EncryptedSharedPreferences
import androidx.security.crypto.MasterKey
import java.io.IOException
import java.security.GeneralSecurityException
import java.security.ProviderException

class AuthStore private constructor(private val openPreferences: () -> SharedPreferences) {
    constructor(context: Context) : this({ openEncryptedPreferences(context.applicationContext) })
    internal constructor(preferences: SharedPreferences) : this({ preferences })
    private val prefs by lazy(LazyThreadSafetyMode.SYNCHRONIZED) { openPreferences() }

    val token: String get() = access { it.getString(TOKEN_KEY, "").orEmpty() }
    val login: String get() = access { it.getString(LOGIN_KEY, "").orEmpty() }
    val isAuthenticated: Boolean get() = token.isNotBlank()

    fun save(token: String, id: Long, login: String, name: String, role: String) = access { storage ->
        require(token.isNotBlank())
        check(storage.edit().putString(TOKEN_KEY, token).putLong("user_id", id)
            .putString(LOGIN_KEY, login).putString("display_name", name).putString("role", role).commit()) {
            "Could not persist Android session"
        }
    }

    /** A delayed validation must never restore a logged-out session or overwrite a newer login. */
    fun updateIfCurrent(expectedToken: String, id: Long, login: String, name: String, role: String): Boolean = access { storage ->
        if (expectedToken.isBlank() || storage.getString(TOKEN_KEY, "") != expectedToken) return@access false
        save(expectedToken, id, login, name, role)
        true
    }

    fun clear() = access { storage ->
        check(storage.edit().clear().commit()) { "Could not persist explicit logout" }
    }

    private fun <T> access(block: (SharedPreferences) -> T): T = synchronized(recoveryLock) {
        try {
            block(prefs)
        } catch (error: Throwable) {
            if (error is EncryptedAuthStorageException) throw error
            if (!isRecoverableEncryptedStorageError(error)) throw error
            throw EncryptedAuthStorageException("Encrypted auth storage is temporarily unavailable; data preserved", error)
        }
    }

    companion object {
        private const val TAG = "AuthStore"
        internal const val PREFERENCES_NAME = "android_auth"
        private const val TOKEN_KEY = "token"
        private const val LOGIN_KEY = "login"
        private val recoveryLock = Any()

        private fun openEncryptedPreferences(context: Context): SharedPreferences = synchronized(recoveryLock) {
            openWithSingleRecoveryAttempt(
                open = { createEncryptedPreferences(context) },
                onRecoverableError = { error -> Log.w(TAG, "Encrypted auth storage is unavailable, retrying without deleting data", error) },
                onRetryError = { error -> Log.e(TAG, "Encrypted auth storage retry failed; data preserved", error) }
            )
        }

        private fun createEncryptedPreferences(context: Context): SharedPreferences {
            val encryptedPreferences = EncryptedSharedPreferences.create(
                context,
                PREFERENCES_NAME,
                MasterKey.Builder(context).setKeyScheme(MasterKey.KeyScheme.AES256_GCM).build(),
                EncryptedSharedPreferences.PrefKeyEncryptionScheme.AES256_SIV,
                EncryptedSharedPreferences.PrefValueEncryptionScheme.AES256_GCM
            )
            // Verify only credentials; an unrelated damaged profile field must not erase a token.
            encryptedPreferences.getString(TOKEN_KEY, "")
            return encryptedPreferences
        }

        internal fun isRecoverableEncryptedStorageError(error: Throwable): Boolean {
            val visited = HashSet<Throwable>()
            var current: Throwable? = error
            while (current != null && visited.add(current)) {
                val className = current.javaClass.name
                val comesFromEncryptedStorage = current.stackTrace.any(::isEncryptedStorageFrame)
                if (current is GeneralSecurityException || current is android.security.KeyStoreException) return true
                if (className.startsWith("com.google.crypto.tink.")) return true
                // AndroidX/Tink иногда оборачивают сбой keyset не в GeneralSecurityException,
                // а в SecurityException, ProviderException, IOException или IllegalArgumentException.
                // Ограничение по стеку не позволяет считать произвольную ошибку приложения crypto-сбоем.
                if (comesFromEncryptedStorage &&
                    (current is SecurityException || current is ProviderException || current is IOException || current is IllegalArgumentException)
                ) return true
                current = current.cause
            }
            return false
        }

        private fun isEncryptedStorageFrame(frame: StackTraceElement): Boolean =
            frame.className.startsWith("androidx.security.crypto.") ||
                frame.className.startsWith("com.google.crypto.tink.") ||
                frame.className.startsWith("android.security.keystore.") ||
                frame.className.contains("AndroidKeysetManager") ||
                frame.className.contains("EncryptedSharedPreferences")

        internal fun <T> openWithSingleRecoveryAttempt(
            open: () -> T,
            onRecoverableError: (Throwable) -> Unit = {},
            onRetryError: (Throwable) -> Unit = {}
        ): T {
            try {
                return open()
            } catch (firstError: Throwable) {
                if (!isRecoverableEncryptedStorageError(firstError)) throw firstError
                onRecoverableError(firstError)
                try {
                    return open()
                } catch (retryError: Throwable) {
                    onRetryError(retryError)
                    throw EncryptedAuthStorageException("Encrypted auth storage is unavailable; existing data was preserved", retryError)
                }
            }
        }
    }
}

class EncryptedAuthStorageException(message: String, cause: Throwable) : IllegalStateException(message, cause)
