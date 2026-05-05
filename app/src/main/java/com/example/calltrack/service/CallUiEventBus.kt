package com.example.calltrack.service

import kotlinx.coroutines.flow.MutableSharedFlow
import kotlinx.coroutines.flow.asSharedFlow

data class CallCompletedUiEvent(
    val callId: Long,
    val phone: String,
    val type: String
)

object CallUiEventBus {
    private val _events = MutableSharedFlow<CallCompletedUiEvent>(extraBufferCapacity = 8)
    val events = _events.asSharedFlow()

    fun emit(event: CallCompletedUiEvent) {
        _events.tryEmit(event)
    }
}
