package com.example.calltrack.auth

import okhttp3.OkHttpClient
import okhttp3.Request
import okhttp3.mockwebserver.MockResponse
import okhttp3.mockwebserver.MockWebServer
import okhttp3.mockwebserver.SocketPolicy
import org.junit.Assert.*
import org.junit.Test
import java.util.concurrent.TimeUnit

class SessionVerifierTest {
    private val success = """{"status":"success","data":{"user":{"id":7,"login":"manager@example.com","display_name":"Manager","role":"manager","user_phone":"web-user-7"}}}"""

    private fun check(code: Int, body: String, expected: SessionValidation) {
        MockWebServer().use { server ->
            server.enqueue(MockResponse().setResponseCode(code).setBody(body))
            val result = SessionVerifier(OkHttpClient(), server.url("/android_auth_api.php").toString()).validate("saved-token")
            assertEquals(expected, result.state)
            val request = server.takeRequest()
            assertEquals("Bearer saved-token", request.getHeader("Authorization"))
            assertEquals("/android_auth_api.php?action=me", request.path)
            assertEquals("GET", request.method)
            assertEquals(1, server.requestCount) // Never sends logout/revocation.
        }
    }

    @Test fun validSession() = check(200, success, SessionValidation.VALID)
    @Test fun proxyUnauthorizedDoesNotInvalidateSession() = check(401, "<html>Unauthorized</html>", SessionValidation.RETRYABLE)
    @Test fun unrelatedUnauthorizedDoesNotInvalidateSession() = check(401, """{"status":"error","message":"Proxy credentials required"}""", SessionValidation.RETRYABLE)
    @Test fun expiredOrRevokedSessionRequestsLogin() = check(401, """{"status":"error","message":"Требуется авторизация"}""", SessionValidation.REAUTHENTICATION_REQUIRED)
    @Test fun forbiddenIsNotLogout() = check(403, """{"status":"error"}""", SessionValidation.RETRYABLE)
    @Test fun rateLimitedIsNotLogout() = check(429, "", SessionValidation.RETRYABLE)
    @Test fun serverFailureIsNotLogout() = check(500, "", SessionValidation.RETRYABLE)
    @Test fun gatewayTimeoutIsNotLogout() = check(504, "<html>Gateway timeout</html>", SessionValidation.RETRYABLE)
    @Test fun malformedSuccessIsNotLogout() = check(200, """{"status":"success","data":{}}""", SessionValidation.RETRYABLE)
    @Test fun emptySuccessIsNotLogout() = check(200, "", SessionValidation.RETRYABLE)

    @Test fun noSessionMakesNoRequest() {
        MockWebServer().use { server ->
            assertEquals(SessionValidation.NO_SESSION, SessionVerifier(OkHttpClient(), server.url("/auth").toString()).validate("").state)
            assertEquals(0, server.requestCount)
        }
    }

    @Test fun networkTimeoutThenRecoveryKeepsSameToken() {
        MockWebServer().use { server ->
            val client = OkHttpClient.Builder().retryOnConnectionFailure(false)
                .readTimeout(150, TimeUnit.MILLISECONDS).callTimeout(500, TimeUnit.MILLISECONDS).build()
            server.enqueue(MockResponse().setSocketPolicy(SocketPolicy.NO_RESPONSE))
            val verifier = SessionVerifier(client, server.url("/auth").toString())
            assertEquals(SessionValidation.RETRYABLE, verifier.validate("saved-token").state)
            server.enqueue(MockResponse().setBody(success))
            assertEquals(SessionValidation.VALID, verifier.validate("saved-token").state)
            repeat(2) { assertEquals("Bearer saved-token", server.takeRequest().getHeader("Authorization")) }
        }
    }

    @Test fun interceptorDoesNotEraseTokenOn401() {
        MockWebServer().use { server ->
            var rejected = 0
            val client = OkHttpClient.Builder().addInterceptor(AuthInterceptor({ "saved-token" }, { rejected++ })).build()
            server.enqueue(MockResponse().setResponseCode(401))
            server.enqueue(MockResponse().setBody("ok"))
            repeat(2) {
                client.newCall(Request.Builder().url(server.url("/sync")).build()).execute().use { }
                assertEquals("Bearer saved-token", server.takeRequest().getHeader("Authorization"))
            }
            assertEquals(1, rejected)
        }
    }

    @Test fun unreadableCredentialsNeverSendAnonymousRequest() {
        MockWebServer().use { server ->
            val client = OkHttpClient.Builder().addInterceptor(AuthInterceptor({
                throw EncryptedAuthStorageException("temporarily locked", java.security.GeneralSecurityException())
            })).build()
            try {
                client.newCall(Request.Builder().url(server.url("/sync")).build()).execute().close()
                fail("Expected IOException")
            } catch (_: java.io.IOException) { }
            assertEquals(0, server.requestCount)
        }
    }
}
