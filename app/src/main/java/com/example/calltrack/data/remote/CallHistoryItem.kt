package com.example.calltrack.data.remote

import com.google.gson.annotations.SerializedName

data class CallHistoryItem(
    @SerializedName(value = "date", alternate = ["call_date"]) val date: String = "",
    @SerializedName(value = "time", alternate = ["call_time"]) val time: String = "",
    @SerializedName("phone") val phone: String = "",
    @SerializedName(value = "type", alternate = ["call_type"]) val type: String = "",
    @SerializedName("duration") val duration: String = "",
    @SerializedName("manager") val manager: String = "",
    @SerializedName(value = "note", alternate = ["comment"]) val note: String = "",
    @SerializedName("tag") val tag: String = "",
    @SerializedName("reminder") val reminder: String = "",
    @SerializedName("reminder_text") val reminderText: String = "",
    @SerializedName("client") val client: String = "",
    @SerializedName("call_id") val callId: String = "",
    @SerializedName("user_phone") val userPhone: String = ""
)
