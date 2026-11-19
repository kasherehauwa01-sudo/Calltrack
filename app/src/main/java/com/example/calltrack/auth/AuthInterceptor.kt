package com.example.calltrack.auth

import android.content.Context
import okhttp3.Interceptor
import okhttp3.Response
import java.io.IOException

class AuthInterceptor internal constructor(
    private val tokenProvider: () -> String,
    private val onRejected: () -> Unit = {}
) : Interceptor {
    constructor(context: Context) : this(
        tokenProvider = { AuthStore(context.applicationContext).token },
        onRejected = {
            com.example.calltrack.logging.AppLogger.log(context.applicationContext, "AUTH", "Authenticated request rejected (401); session and pending records retained")
            AuthSessionNotice.show(context.applicationContext)
        }
    )

    override fun intercept(chain: Interceptor.Chain): Response {
        val token = try {
            tokenProvider()
        } catch (error: EncryptedAuthStorageException) {
            // Do not send an anonymous request when the saved token cannot be read.
            throw IOException("Android session storage is temporarily unavailable", error)
        }
        val request = if (token.isBlank()) chain.request() else chain.request().newBuilder()
            .header("Authorization", "Bearer $token").build()
        val response = chain.proceed(request)
        // A single endpoint/proxy 401 is not permission to delete credentials.
        // Leave pending data intact and let the normal worker retry.
        if (response.code == 401 && token.isNotBlank()) runCatching { onRejected() }
        return response
    }
}
