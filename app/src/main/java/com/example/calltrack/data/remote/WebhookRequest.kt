package com.example.calltrack.data.remote

data class WebhookRequest(
    val phone: String,
    val type: String,
    val duration: Long,
    val note: String,
    val date: String,
    val time: String
)
