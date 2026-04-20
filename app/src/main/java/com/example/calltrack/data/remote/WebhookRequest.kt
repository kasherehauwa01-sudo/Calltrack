package com.example.calltrack.data.remote

data class WebhookRequest(
    val date: String,
    val time: String,
    val phone: String,
    val type: String,
    val duration: Long,
    val manager: String,
    val comment: String,
    val tag: String,
    val reminder: String
)
