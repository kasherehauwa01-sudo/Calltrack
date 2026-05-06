package com.example.calltrack.data.remote

import com.google.gson.annotations.SerializedName

data class CallHistoryItem(
    @SerializedName("date") val date: String = "",
    @SerializedName("time") val time: String = "",
    @SerializedName("phone") val phone: String = "",
    @SerializedName("type") val type: String = "",
    @SerializedName("duration") val duration: String = "",
    @SerializedName("manager") val manager: String = "",
    @SerializedName("note") val comment: String = "",
    @SerializedName("tag") val tag: String = "",
    @SerializedName("reminder") val reminder: String = "",
    @SerializedName("reminder_text") val reminderText: String = "",
    @SerializedName("client") val client: String = ""
)
