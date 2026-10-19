package com.example.calltrack.maxsync

import android.content.Context
import com.example.calltrack.BuildConfig
import com.example.calltrack.auth.AuthInterceptor
import com.example.calltrack.data.local.CallDatabase
import com.google.gson.Gson
import kotlinx.coroutines.sync.Mutex
import kotlinx.coroutines.sync.withLock
import okhttp3.MediaType.Companion.toMediaType
import okhttp3.OkHttpClient
import okhttp3.Request
import okhttp3.RequestBody.Companion.toRequestBody
import java.util.concurrent.TimeUnit

internal object MaxMessageSyncRuntime {
    private val lock = Mutex()
    // No body/header logging and no redirects to a potentially different auth destination.
    private fun client(context: Context) = OkHttpClient.Builder()
        .addInterceptor(AuthInterceptor(context)).followRedirects(false).followSslRedirects(false)
        .connectTimeout(10, TimeUnit.SECONDS).readTimeout(20, TimeUnit.SECONDS)
        .callTimeout(30, TimeUnit.SECONDS).build()

    suspend fun cutoff(context: Context): MaxSyncCutoff = lock.withLock {
        val prefs = context.getSharedPreferences("max_message_sync_v1", Context.MODE_PRIVATE)
        if (!prefs.contains("enabled_at")) {
            val timestamp = System.currentTimeMillis()
            val maxId = CallDatabase.getInstance(context).maxMessageSyncDao().maxId()
            // Commit both fields before inserting any new event. Failed persistence fails closed.
            check(prefs.edit().putLong("enabled_at", timestamp).putLong("legacy_max_id", maxId).commit())
        }
        MaxSyncCutoff(prefs.getLong("enabled_at", Long.MAX_VALUE), prefs.getLong("legacy_max_id", Long.MAX_VALUE))
    }

    suspend fun run(context: Context): Boolean {
        val cutoff = cutoff(context)
        check(com.example.calltrack.auth.AuthStore(context).isAuthenticated) { "Android session required" }
        val dao = CallDatabase.getInstance(context).maxMessageSyncDao()
        val http = client(context)
        val gson = Gson()
        val url = BuildConfig.SQL_API_BASE_URL.trimEnd('/') + "/add_max_messages.php"
        check(url.startsWith("https://"))
        val sync = MaxMessageSync(
            pending = { boundary, limit -> dao.pending(boundary.timestamp, boundary.legacyMaxId, limit) },
            upload = { messages ->
                val body = gson.toJson(mapOf("messages" to messages))
                val request = Request.Builder().url(url).post(body.toRequestBody("application/json; charset=utf-8".toMediaType())).build()
                http.newCall(request).execute().use { response ->
                    check(response.isSuccessful) { "MAX sync failed" }
                    val acknowledgement = gson.fromJson(response.body?.string(), Acknowledgement::class.java)
                    check(acknowledgement?.status == "success" && acknowledgement.accepted != null)
                    acknowledgement.accepted.toSet()
                }
            },
            mark = dao::markAccepted
        )
        // At most 150 events per run. A retry/periodic job resumes the persistent queue.
        repeat(3) { if (!sync.batch(cutoff)) return false }
        return true
    }
    private data class Acknowledgement(val status: String?, val accepted: List<String>?)
}
