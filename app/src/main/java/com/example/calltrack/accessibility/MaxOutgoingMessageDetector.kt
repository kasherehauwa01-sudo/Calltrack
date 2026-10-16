package com.example.calltrack.accessibility

import android.view.accessibility.AccessibilityEvent

/**
 * Detects an outgoing ordinary text message in MAX without reading
 * or storing the message text.
 *
 * Expected sequence:
 * 1. MAX input field contained text.
 * 2. MAX clears the whole input field at once.
 * 3. Shortly afterwards the messages RecyclerView reports a larger itemCount.
 */
class MaxOutgoingMessageDetector(
    private val onOutgoingMessageDetected: (eventTime: Long) -> Unit
) {

    private var knownInputLength = 0
    private var lastMessagesItemCount = -1

    private var pendingClearTime = -1L
    private var itemCountBeforeClear = -1

    fun onAccessibilityEvent(event: AccessibilityEvent) {
        when {
            isMessageInput(event) -> handleInputEvent(event)
            isMessagesList(event) -> handleMessagesListEvent(event)
        }

        expirePendingCandidate(event.eventTime)
    }

    private fun handleInputEvent(event: AccessibilityEvent) {
        if (event.eventType != AccessibilityEvent.TYPE_VIEW_TEXT_CHANGED) {
            return
        }

        val added = event.addedCount.coerceAtLeast(0)
        val removed = event.removedCount.coerceAtLeast(0)

        /*
         * Accessibility reports replacement-style counters for this MAX field.
         * We never inspect event.text. The resulting length can be derived from
         * the counters alone.
         */
        val resultingLength = added

        val looksLikeFullClear =
            knownInputLength > 0 &&
                added == 0 &&
                removed >= knownInputLength

        if (looksLikeFullClear) {
            pendingClearTime = event.eventTime
            itemCountBeforeClear = lastMessagesItemCount
        } else if (resultingLength > 0) {
            cancelPendingCandidate()
        }

        knownInputLength = resultingLength
    }

    private fun handleMessagesListEvent(event: AccessibilityEvent) {
        if (
            event.eventType != AccessibilityEvent.TYPE_VIEW_SCROLLED &&
            event.eventType != AccessibilityEvent.TYPE_WINDOW_CONTENT_CHANGED
        ) {
            return
        }

        val currentItemCount = event.itemCount

        if (currentItemCount < 0) {
            return
        }

        val candidateTime = pendingClearTime
        val baseline = itemCountBeforeClear

        if (
            candidateTime >= 0 &&
            event.eventTime >= candidateTime &&
            event.eventTime - candidateTime <= CONFIRMATION_WINDOW_MS &&
            baseline >= 0 &&
            currentItemCount > baseline
        ) {
            onOutgoingMessageDetected(event.eventTime)
            cancelPendingCandidate()
        }

        lastMessagesItemCount = currentItemCount
    }

    private fun expirePendingCandidate(now: Long) {
        if (
            pendingClearTime >= 0 &&
            now - pendingClearTime > CONFIRMATION_WINDOW_MS
        ) {
            cancelPendingCandidate()
        }
    }

    private fun cancelPendingCandidate() {
        pendingClearTime = -1L
        itemCountBeforeClear = -1
    }

    private fun isMessageInput(event: AccessibilityEvent): Boolean {
        return event.source?.viewIdResourceName == MESSAGE_INPUT_ID
    }

    private fun isMessagesList(event: AccessibilityEvent): Boolean {
        return event.source?.viewIdResourceName == MESSAGES_LIST_ID
    }

    private companion object {
        const val MESSAGE_INPUT_ID =
            "ru.oneme.app:id/oneme_message_input_edit_text"

        const val MESSAGES_LIST_ID =
            "ru.oneme.app:id/messages_list_recycler_view"

        const val CONFIRMATION_WINDOW_MS = 1_000L
    }
}