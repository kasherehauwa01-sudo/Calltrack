package com.example.calltrack.accessibility

import android.accessibilityservice.AccessibilityService
import android.os.Build
import android.util.Log
import android.view.accessibility.AccessibilityEvent

/** Temporary MAX diagnostics: metadata only. Message content is never read or stored. */
class MaxAccessibilityDiagnosticService : AccessibilityService() {

    private val messageStore by lazy {
        MaxMessageLocalStore(applicationContext)
    }

    private val outgoingMessageDetector =
        MaxOutgoingMessageDetector { eventTime ->
            val conversationTitle = currentConversationTitle()
            val occurredAt = System.currentTimeMillis()

            Log.i(
                TAG,
                "OUTGOING_MESSAGE_DETECTED " +
                    "eventTime=$eventTime " +
                    "timestamp=$occurredAt"
            )

            if (conversationTitle != null) {
                messageStore.saveOutgoing(
                    contactName = conversationTitle,
                    occurredAt = occurredAt
                )
            }
        }

    private var lastContentEventTime = Long.MIN_VALUE
    private var lastScrollEventTime = Long.MIN_VALUE

    override fun onAccessibilityEvent(event: AccessibilityEvent?) {
        if (event == null || event.packageName?.toString() != MAX_PACKAGE) return
        if (event.eventType !in EVENT_TYPES) return

        outgoingMessageDetector.onAccessibilityEvent(event)

        when (event.eventType) {
            AccessibilityEvent.TYPE_WINDOW_CONTENT_CHANGED -> {
                if (tooSoon(event.eventTime, lastContentEventTime)) return
                lastContentEventTime = event.eventTime
            }

            AccessibilityEvent.TYPE_VIEW_SCROLLED -> {
                if (tooSoon(event.eventTime, lastScrollEventTime)) return
                lastScrollEventTime = event.eventTime
            }
        }

        val source = event.source

        try {
            Log.i(
                TAG,
                "eventType=${AccessibilityEvent.eventTypeToString(event.eventType)} " +
                    "packageName=$MAX_PACKAGE " +
                    "className=${technicalName(event.className)} " +
                    "source.viewIdResourceName=${technicalName(source?.viewIdResourceName)} " +
                    "source.className=${technicalName(source?.className)} " +
                    "source.isClickable=${source?.isClickable} " +
                    "source.isEnabled=${source?.isEnabled} " +
                    "addedCount=${if (event.eventType == AccessibilityEvent.TYPE_VIEW_TEXT_CHANGED) event.addedCount else -1} " +
                    "removedCount=${if (event.eventType == AccessibilityEvent.TYPE_VIEW_TEXT_CHANGED) event.removedCount else -1} " +
                    "fromIndex=${event.fromIndex} " +
                    "toIndex=${event.toIndex} " +
                    "itemCount=${event.itemCount} " +
                    "scrollY=${event.scrollY} " +
                    "maxScrollY=${event.maxScrollY} " +
                    "eventTime=${event.eventTime} " +
                    "timestamp=${System.currentTimeMillis()}"
            )
        } finally {
            if (Build.VERSION.SDK_INT < 33) {
                @Suppress("DEPRECATION")
                source?.recycle()
            }
        }
    }

    private fun currentConversationTitle(): String? {
        val root = rootInActiveWindow ?: return null

        val nodes = root.findAccessibilityNodeInfosByViewId(
            "ru.oneme.app:id/oneme_toolbar_title"
        )

        return try {
            nodes.firstOrNull()
                ?.text
                ?.toString()
                ?.trim()
                ?.takeIf { it.isNotEmpty() }
        } finally {
            if (Build.VERSION.SDK_INT < 33) {
                @Suppress("DEPRECATION")
                nodes.forEach { it.recycle() }
            }
        }
    }

    override fun onInterrupt() = Unit

    private fun tooSoon(now: Long, previous: Long): Boolean =
        previous != Long.MIN_VALUE &&
            now >= previous &&
            now - previous < 250L

    private fun technicalName(value: CharSequence?): String {
        val name = value?.toString() ?: return "null"

        return if (
            name.length <= 200 &&
            TECHNICAL_NAME.matches(name)
        ) {
            name
        } else {
            "redacted"
        }
    }

    private companion object {
        const val TAG = "CalltrackMAXAccessibility"
        const val MAX_PACKAGE = "ru.oneme.app"

        val TECHNICAL_NAME = Regex("[A-Za-z0-9_.$:/]+")

        val EVENT_TYPES = setOf(
            AccessibilityEvent.TYPE_VIEW_CLICKED,
            AccessibilityEvent.TYPE_VIEW_TEXT_CHANGED,
            AccessibilityEvent.TYPE_WINDOW_CONTENT_CHANGED,
            AccessibilityEvent.TYPE_WINDOW_STATE_CHANGED,
            AccessibilityEvent.TYPE_VIEW_FOCUSED,
            AccessibilityEvent.TYPE_VIEW_SCROLLED
        )
    }
}
