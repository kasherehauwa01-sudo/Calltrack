package com.example.calltrack.data.remote

import com.google.gson.annotations.SerializedName

data class WebhookRequest(
    val date: String,
    val time: String,
    val phone: String,
    val type: String,
    val duration: Long,
    val manager: String,
    val note: String,
    val tag: String,
    val reminder: String,
    @SerializedName("reminder_text")
    val reminderText: String,
    val client: String
)
