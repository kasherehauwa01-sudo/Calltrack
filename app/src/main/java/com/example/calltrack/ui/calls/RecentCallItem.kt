package com.example.calltrack.ui.calls

import com.example.calltrack.data.local.CallEntity

data class RecentCallItem(
    val call: CallEntity,
    val contactName: String
)
