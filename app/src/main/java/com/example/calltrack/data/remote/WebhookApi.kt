package com.example.calltrack.data.remote

import retrofit2.http.Body
import retrofit2.http.POST
import retrofit2.http.Url

interface WebhookApi {
    @POST
    suspend fun sendCall(@Url url: String, @Body request: WebhookRequest)
}
