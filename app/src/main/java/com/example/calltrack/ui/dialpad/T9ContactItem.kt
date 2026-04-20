package com.example.calltrack.ui.dialpad

data class T9ContactItem(
    val contactId: Long,
    val name: String,
    val phone: String,
    val t9Digits: String
)
