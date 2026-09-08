package com.example.calltrack.data.repository

import com.example.calltrack.BuildConfig
import okhttp3.OkHttpClient
import okhttp3.Request
import org.json.JSONObject
import java.util.concurrent.TimeUnit

data class ProductCard(val ean13: String, val name: String, val fields: List<Pair<String, String>>)

class ProductDirectory {
    private val httpClient = OkHttpClient.Builder().callTimeout(15, TimeUnit.SECONDS).build()

    fun findByEan13(ean13: String): ProductCard? {
        if (!ean13.matches(Regex("\\d{13}"))) return null
        val url = BuildConfig.SQL_API_BASE_URL.trimEnd('/') + "/product_by_ean.php?ean13=" + ean13
        val request = Request.Builder().url(url).get().build()
        return httpClient.newCall(request).execute().use { response ->
            if (!response.isSuccessful) return@use null
            val payload = JSONObject(response.body?.string().orEmpty())
            val product = payload.optJSONObject("data") ?: return@use null
            val fieldsObject = product.optJSONObject("fields") ?: JSONObject()
            val fields = fieldsObject.keys().asSequence().mapNotNull { key ->
                fieldsObject.optString(key).takeIf(String::isNotBlank)?.let { key to it }
            }.toList()
            ProductCard(ean13, product.optString("name").ifBlank { ean13 }, fields)
        }
    }
}
