package com.example.calltrack.domain

enum class CallType(val title: String) {
    INCOMING("Входящий"),
    OUTGOING("Исходящий"),
    MISSED("Пропущенный"),
    UNANSWERED("Неотвеченный")
}
