package com.example.calltrack.auth

import android.content.SharedPreferences
import org.junit.Assert.*
import org.junit.Test
import java.lang.reflect.Proxy

class AuthStoreSessionTest {
    private fun preferences(): SharedPreferences {
        val values = mutableMapOf<String, Any?>()
        val pending = mutableMapOf<String, Any?>()
        var clear = false
        lateinit var editor: SharedPreferences.Editor
        editor = Proxy.newProxyInstance(SharedPreferences.Editor::class.java.classLoader,
            arrayOf(SharedPreferences.Editor::class.java)) { _, method, args ->
            when (method.name) {
                "putString", "putLong" -> { pending[args[0] as String] = args[1]; editor }
                "clear" -> { clear = true; editor }
                "commit" -> { if (clear) values.clear(); values.putAll(pending); pending.clear(); clear = false; true }
                else -> throw UnsupportedOperationException(method.name)
            }
        } as SharedPreferences.Editor
        return Proxy.newProxyInstance(SharedPreferences::class.java.classLoader,
            arrayOf(SharedPreferences::class.java)) { _, method, args ->
            when (method.name) {
                "edit" -> editor
                "getString" -> values[args[0] as String] ?: args[1]
                else -> throw UnsupportedOperationException(method.name)
            }
        } as SharedPreferences
    }

    @Test fun sessionSurvivesAnotherStoreInstance() {
        val prefs = preferences()
        AuthStore(prefs).save("token-a", 1, "a", "A", "manager")
        assertEquals("token-a", AuthStore(prefs).token)
    }

    @Test fun delayedValidationDoesNotOverwriteNewLogin() {
        val prefs = preferences()
        val old = AuthStore(prefs)
        old.save("token-a", 1, "a", "A", "manager")
        AuthStore(prefs).save("token-b", 2, "b", "B", "manager")
        assertFalse(old.updateIfCurrent("token-a", 1, "a", "A", "manager"))
        assertEquals("token-b", old.token)
        assertEquals("b", old.login)
    }

    @Test fun delayedValidationDoesNotUndoExplicitLogout() {
        val prefs = preferences()
        val store = AuthStore(prefs)
        store.save("token-a", 1, "a", "A", "manager")
        AuthStore(prefs).clear()
        assertFalse(store.updateIfCurrent("token-a", 1, "a", "A", "manager"))
        assertFalse(store.isAuthenticated)
    }

    @Test fun currentValidationCanRefreshProfile() {
        val store = AuthStore(preferences())
        store.save("token-a", 1, "a", "A", "manager")
        assertTrue(store.updateIfCurrent("token-a", 1, "updated", "A", "manager"))
        assertEquals("token-a", store.token)
        assertEquals("updated", store.login)
    }
}
