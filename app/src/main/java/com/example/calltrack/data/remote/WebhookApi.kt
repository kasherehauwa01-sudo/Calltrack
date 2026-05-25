package com.example.calltrack.data.remote

import okhttp3.ResponseBody
import retrofit2.Response
import retrofit2.http.Body
import retrofit2.http.GET
import retrofit2.http.POST
import retrofit2.http.Url

interface WebhookApi {
    @POST
    suspend fun sendCall(@Url url: String, @Body request: WebhookRequest): Response<ResponseBody>

    @GET
    suspend fun loadHistory(@Url url: String): List<CallHistoryItem>
}
