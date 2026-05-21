package com.example.calltrack.ui.calls

import com.example.calltrack.data.local.CallEntity

sealed interface RecentCallListItem {
    data class Header(val title: String) : RecentCallListItem
    data class CallRow(
        val call: CallEntity,
        val contactName: String
    ) : RecentCallListItem
}
