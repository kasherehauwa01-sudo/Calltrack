package com.example.calltrack.auth

import android.content.Context
import android.os.Build
import com.example.calltrack.BuildConfig
import com.example.calltrack.data.repository.PrefsManager
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.withContext
import okhttp3.MediaType.Companion.toMediaType
import okhttp3.OkHttpClient
import okhttp3.Request
import okhttp3.RequestBody.Companion.toRequestBody
import org.json.JSONObject

class AndroidAuthClient(context: Context) {
    private val appContext = context.applicationContext
    private val store = AuthStore(context.applicationContext)
    private val client = OkHttpClient()
    private val endpoint = BuildConfig.SQL_API_BASE_URL.trimEnd('/') + "/android_auth_api.php"

    suspend fun login(login: String, pin: String): Result<Unit> = withContext(Dispatchers.IO) { runCatching {
        val json=JSONObject().put("login",login).put("pin",pin).put("device_name","${Build.MANUFACTURER} ${Build.MODEL}")
        val request=Request.Builder().url("$endpoint?action=login").post(json.toString().toRequestBody("application/json; charset=utf-8".toMediaType())).build()
        client.newCall(request).execute().use { response ->
            val body=JSONObject(response.body?.string().orEmpty());if(!response.isSuccessful)error(body.optString("message","\u041E\u0448\u0438\u0431\u043A\u0430 \u0432\u0445\u043E\u0434\u0430"))
            val data=body.getJSONObject("data");val user=data.getJSONObject("user")
            val managerPhone=user.optString("manager_user_phone");store.save(data.getString("token"),user.getLong("id"),user.getString("login"),user.getString("display_name"),user.getString("role"),managerPhone)
            PrefsManager(appContext).setManagerName(user.getString("display_name"));PrefsManager(appContext).setManagerPhone(managerPhone)
        }
    } }

    suspend fun validate(): Boolean = withContext(Dispatchers.IO) {
        if(!store.isAuthenticated)return@withContext false
        runCatching { client.newCall(authorized("$endpoint?action=me")).execute().use { response ->
            if(!response.isSuccessful)return@use false
            val user=JSONObject(response.body?.string().orEmpty()).getJSONObject("data").getJSONObject("user")
            val managerPhone=user.optString("manager_user_phone");store.save(store.token,user.getLong("id"),user.getString("login"),user.getString("display_name"),user.getString("role"),managerPhone)
            PrefsManager(appContext).setManagerName(user.getString("display_name"));PrefsManager(appContext).setManagerPhone(managerPhone);true
        } }.getOrDefault(false)
    }

    suspend fun logout() = withContext(Dispatchers.IO) {
        runCatching { client.newCall(authorized("$endpoint?action=logout", post=true)).execute().close() };store.clear();PrefsManager(appContext).setManagerName("");PrefsManager(appContext).setManagerPhone("")
    }

    private fun authorized(url:String,post:Boolean=false):Request {
        val builder=Request.Builder().url(url).header("Authorization","Bearer ${store.token}")
        if(post)builder.post(ByteArray(0).toRequestBody(null));return builder.build()
    }
}
