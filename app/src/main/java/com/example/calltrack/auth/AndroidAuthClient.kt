package com.example.calltrack.auth

import android.content.Context
import android.os.Build
import com.example.calltrack.BuildConfig
import com.example.calltrack.data.repository.PrefsManager
import com.example.calltrack.service.CalltrackRecoveryManager
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.delay
import kotlinx.coroutines.withContext
import okhttp3.MediaType.Companion.toMediaType
import okhttp3.OkHttpClient
import okhttp3.Request
import okhttp3.Response
import okhttp3.RequestBody.Companion.toRequestBody
import org.json.JSONObject
import java.io.IOException
import java.util.concurrent.TimeUnit

class AndroidAuthClient(context: Context) {
    private val appContext = context.applicationContext
    private val store = AuthStore(context.applicationContext)
    private val client = OkHttpClient.Builder()
        .connectTimeout(CONNECT_TIMEOUT_SECONDS, TimeUnit.SECONDS)
        .readTimeout(READ_TIMEOUT_SECONDS, TimeUnit.SECONDS)
        .retryOnConnectionFailure(true)
        .build()
    private val endpoint = BuildConfig.SQL_API_BASE_URL.trimEnd('/') + "/android_auth_api.php"
    val hasSavedSession: Boolean get() = store.isAuthenticated

    suspend fun login(login: String, pin: String): Result<Unit> = withContext(Dispatchers.IO) { runCatching {
        val json=JSONObject().put("login",login).put("pin",pin).put("device_name","${Build.MANUFACTURER} ${Build.MODEL}")
        val request=Request.Builder().url("$endpoint?action=login").post(json.toString().toRequestBody("application/json; charset=utf-8".toMediaType())).build()
        executeWithConnectionRetry(request).use { response ->
            val body=JSONObject(response.body?.string().orEmpty());if(!response.isSuccessful)error(body.optString("message","\u041E\u0448\u0438\u0431\u043A\u0430 \u0432\u0445\u043E\u0434\u0430"))
            val data=body.getJSONObject("data");val user=data.getJSONObject("user")
            store.save(data.getString("token"),user.getLong("id"),user.getString("login"),user.getString("display_name"),user.getString("role"))
            PrefsManager(appContext).setManagerName(user.getString("display_name"));PrefsManager(appContext).setManagerPhone(user.getString("user_phone"))
        }
    } }

    suspend fun validate(): Boolean = withContext(Dispatchers.IO) {
        if(!store.isAuthenticated)return@withContext false
        runCatching { client.newCall(authorized("$endpoint?action=me")).execute().use { response ->
            if(!response.isSuccessful)return@use false
            val user=JSONObject(response.body?.string().orEmpty()).getJSONObject("data").getJSONObject("user")
            store.save(store.token,user.getLong("id"),user.getString("login"),user.getString("display_name"),user.getString("role"))
            PrefsManager(appContext).setManagerName(user.getString("display_name"));PrefsManager(appContext).setManagerPhone(user.getString("user_phone"));true
        } }.getOrDefault(false)
    }

    suspend fun logout() = withContext(Dispatchers.IO) {
        runCatching { client.newCall(authorized("$endpoint?action=logout", post=true)).execute().close() };store.clear();CalltrackRecoveryManager.cancelAuthorizedWork(appContext);PrefsManager(appContext).setManagerName("");PrefsManager(appContext).setManagerPhone("")
    }

    private fun authorized(url:String,post:Boolean=false):Request {
        val builder=Request.Builder().url(url).header("Authorization","Bearer ${store.token}")
        if(post)builder.post(ByteArray(0).toRequestBody(null));return builder.build()
    }

    /**
     * OkHttp retries alternative routes, but it does not repeat a call after a transient
     * connection failure when the host has only one resolved address. The login endpoint
     * can safely issue another session token if a connection drops before its response.
     */
    private suspend fun executeWithConnectionRetry(request: Request): Response {
        var lastFailure: IOException? = null
        repeat(LOGIN_CONNECTION_ATTEMPTS) { attempt ->
            try {
                return client.newCall(request).execute()
            } catch (error: IOException) {
                lastFailure = error
                if (attempt < LOGIN_CONNECTION_ATTEMPTS - 1) delay(LOGIN_RETRY_DELAY_MS)
            }
        }
        throw IOException(
            "\u041D\u0435 \u0443\u0434\u0430\u043B\u043E\u0441\u044C \u043F\u043E\u0434\u043A\u043B\u044E\u0447\u0438\u0442\u044C\u0441\u044F \u043A \u0441\u0435\u0440\u0432\u0435\u0440\u0443 Calltrack. " +
                "\u041F\u0440\u043E\u0432\u0435\u0440\u044C\u0442\u0435 \u0438\u043D\u0442\u0435\u0440\u043D\u0435\u0442 \u0438 \u043F\u043E\u0432\u0442\u043E\u0440\u0438\u0442\u0435 \u0432\u0445\u043E\u0434.",
            lastFailure
        )
    }

    private companion object {
        const val CONNECT_TIMEOUT_SECONDS = 6L
        const val READ_TIMEOUT_SECONDS = 15L
        const val LOGIN_CONNECTION_ATTEMPTS = 3
        const val LOGIN_RETRY_DELAY_MS = 700L
    }
}
