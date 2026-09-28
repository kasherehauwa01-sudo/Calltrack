package com.example.calltrack.auth

import org.junit.Assert.assertEquals
import org.junit.Assert.assertFalse
import org.junit.Assert.assertSame
import org.junit.Assert.assertTrue
import org.junit.Assert.fail
import org.junit.Test
import java.security.GeneralSecurityException
import javax.crypto.AEADBadTagException

class AuthStoreRecoveryTest {

    @Test
    fun nestedCryptoErrorIsRecoverable() {
        val error = RuntimeException("wrapper", GeneralSecurityException("keyset", AEADBadTagException("bad tag")))
        assertTrue(AuthStore.isRecoverableEncryptedStorageError(error))
    }

    @Test
    fun unrelatedRuntimeErrorIsNotRecoverable() {
        assertFalse(AuthStore.isRecoverableEncryptedStorageError(RuntimeException("programming error")))
    }

    @Test
    fun successfulOpenDoesNotCleanup() {
        var opens = 0
        var cleanups = 0
        val storage = Any()
        val result = AuthStore.openWithSingleRecoveryAttempt(
            open = { opens++; storage },
            cleanup = { cleanups++ }
        )
        assertSame(storage, result)
        assertEquals(1, opens)
        assertEquals(0, cleanups)
    }

    @Test
    fun cryptoFailureCleansOnlyOnceAndReturnsFreshStorage() {
        var opens = 0
        var cleanups = 0
        val result = AuthStore.openWithSingleRecoveryAttempt(
            open = {
                opens++
                if (opens == 1) throw AEADBadTagException("bad tag")
                "fresh-empty-auth-storage"
            },
            cleanup = { cleanups++ }
        )
        assertEquals("fresh-empty-auth-storage", result)
        assertEquals(2, opens)
        assertEquals(1, cleanups)
    }

    @Test
    fun retryFailureDoesNotStartRecoveryLoop() {
        var opens = 0
        var cleanups = 0
        try {
            AuthStore.openWithSingleRecoveryAttempt<Any>(
                open = { opens++; throw AEADBadTagException("bad tag") },
                cleanup = { cleanups++ }
            )
            fail("Expected EncryptedAuthStorageException")
        } catch (error: EncryptedAuthStorageException) {
            assertTrue(error.cause is AEADBadTagException)
        }
        assertEquals(2, opens)
        assertEquals(1, cleanups)
    }

    @Test
    fun unrelatedFailureDoesNotDestroyStorage() {
        var cleanups = 0
        val expected = RuntimeException("programming error")
        try {
            AuthStore.openWithSingleRecoveryAttempt<Any>(
                open = { throw expected },
                cleanup = { cleanups++ }
            )
            fail("Expected original RuntimeException")
        } catch (error: RuntimeException) {
            assertSame(expected, error)
        }
        assertEquals(0, cleanups)
    }
}
