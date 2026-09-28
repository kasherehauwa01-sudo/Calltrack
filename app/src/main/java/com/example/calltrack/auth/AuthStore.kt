package com.example.calltrack.auth

import android.content.Context
import android.content.SharedPreferences
import android.util.Log
import androidx.security.crypto.EncryptedSharedPreferences
import androidx.security.crypto.MasterKey
import java.io.IOException
import java.security.GeneralSecurityException
import java.security.KeyStore

class AuthStore(context: Context) {
    private val prefs = openEncryptedPreferences(context.applicationContext)

    val token: String get() = prefs.getString(TOKEN_KEY, "").orEmpty()
    val login: String get() = prefs.getString(LOGIN_KEY, "").orEmpty()
    val isAuthenticated: Boolean get() = token.isNotBlank()

    fun save(token: String, id: Long, login: String, name: String, role: String) {
        prefs.edit().putString("token", token).putLong("user_id", id).putString("login", login).putString("display_name", name)
            .putString("role", role).apply()
    }

    fun clear() = prefs.edit().clear().apply()

    companion object {
        private const val TAG = "AuthStore"
        internal const val PREFERENCES_NAME = "android_auth"
        private const val TOKEN_KEY = "token"
        private const val LOGIN_KEY = "login"
        private val recoveryLock = Any()

        private fun openEncryptedPreferences(context: Context): SharedPreferences = synchronized(recoveryLock) {
            openWithSingleRecoveryAttempt(
                open = { createEncryptedPreferences(context) },
                cleanup = { clearBrokenEncryptedStorage(context) },
                onRecoverableError = { error -> Log.w(TAG, "Encrypted auth storage is unreadable, recreating it", error) },
                onRetryError = { error -> Log.e(TAG, "Encrypted auth storage recreation failed", error) }
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
            // create() проверяет Tink keyset, а getAll() дополнительно заставляет
            // расшифровать сохранённые auth-значения до передачи storage вызывающему коду.
            encryptedPreferences.all
            return encryptedPreferences
        }

        private fun clearBrokenEncryptedStorage(context: Context) {
            // Оба служебных Tink keyset находятся внутри android_auth.xml, поэтому
            // удаляем только этот preference-файл, не затрагивая БД и другие настройки.
            if (!context.deleteSharedPreferences(PREFERENCES_NAME)) {
                Log.w(TAG, "Unable to delete unreadable auth preferences")
            }
            runCatching {
                KeyStore.getInstance("AndroidKeyStore").apply {
                    load(null)
                    if (containsAlias(MasterKey.DEFAULT_MASTER_KEY_ALIAS)) {
                        deleteEntry(MasterKey.DEFAULT_MASTER_KEY_ALIAS)
                    }
                }
            }.onFailure { Log.w(TAG, "Unable to remove unusable auth MasterKey", it) }
        }

        internal fun isRecoverableEncryptedStorageError(error: Throwable): Boolean {
            val visited = HashSet<Throwable>()
            var current: Throwable? = error
            while (current != null && visited.add(current)) {
                if (current is GeneralSecurityException || current is android.security.KeyStoreException) return true
                val className = current.javaClass.name
                if (className.startsWith("com.google.crypto.tink.") &&
                    (className.contains("Keyset", ignoreCase = true) || className.contains("Security", ignoreCase = true) || current is IOException)
                ) return true
                if (current is IOException && current.stackTrace.any { frame ->
                        frame.className.startsWith("com.google.crypto.tink.") ||
                            frame.className.contains("AndroidKeysetManager") ||
                            frame.className.contains("EncryptedSharedPreferences")
                    }
                ) return true
                current = current.cause
            }
            return false
        }

        internal fun <T> openWithSingleRecoveryAttempt(
            open: () -> T,
            cleanup: () -> Unit,
            onRecoverableError: (Throwable) -> Unit = {},
            onRetryError: (Throwable) -> Unit = {}
        ): T {
            try {
                return open()
            } catch (firstError: Throwable) {
                if (!isRecoverableEncryptedStorageError(firstError)) throw firstError
                onRecoverableError(firstError)
                cleanup()
                try {
                    return open()
                } catch (retryError: Throwable) {
                    onRetryError(retryError)
                    throw EncryptedAuthStorageException("Encrypted auth storage could not be recreated", retryError)
                }
            }
        }
    }
}

class EncryptedAuthStorageException(message: String, cause: Throwable) : IllegalStateException(message, cause)
