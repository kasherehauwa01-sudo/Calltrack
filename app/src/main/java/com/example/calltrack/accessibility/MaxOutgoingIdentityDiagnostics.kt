package com.example.calltrack.accessibility

import android.graphics.Rect
import android.os.Build
import android.util.Log
import android.view.accessibility.AccessibilityNodeInfo

/** Temporary debug diagnostics. Never traverse message/input branches or infer MAX IDs. */
internal object MaxOutgoingIdentityDiagnostics {
    const val TAG = "CalltrackMAXOutgoingIdentityDiag"
    private const val MAX_PACKAGE = "ru.oneme.app"
    private const val PREFIX = "$MAX_PACKAGE:id/"
    private val sensitive = listOf(
        "message", "input", "bubble", "reply", "quote", "attachment", "caption",
        "draft", "remote_input", "media", "composer", "preview"
    )
    private val candidates = listOf("toolbar", "title", "subtitle", "header", "avatar", "profile", "conversation", "chat")

    internal fun enabled(debug: Boolean, packageName: String?) = debug && packageName == MAX_PACKAGE

    internal fun blocked(id: String?, className: String?, ancestorBlocked: Boolean): Boolean =
        ancestorBlocked || sensitive.any { id.orEmpty().substringAfter(":id/").contains(it, ignoreCase = true) } ||
            listOf("EditText", "RecyclerView", "ListView", "WebView").any { className.orEmpty().contains(it) }

    internal fun candidate(id: String?) = id?.startsWith(PREFIX) == true &&
        candidates.any { id.substringAfter(":id/").contains(it, ignoreCase = true) }

    // Names alone are insufficient: require a known title/subtitle, safe ancestry and top-of-window bounds.
    internal fun allowText(id: String?, branchBlocked: Boolean, inHeaderBounds: Boolean): Boolean =
        !branchBlocked && inHeaderBounds && id in setOf(
            "${PREFIX}oneme_toolbar_title", "${PREFIX}oneme_toolbar_subtitle"
        )

    fun log(root: AccessibilityNodeInfo?, eventTime: Long, debug: Boolean) {
        if (root == null) return
        try {
            if (!enabled(debug, root.packageName?.toString())) return
            val window = Rect().also(root::getBoundsInScreen)
            var visited = 0
            var logged = 0
            Log.d(TAG, "event=OUTGOING_MESSAGE_DETECTED eventTime=$eventTime root.className=${safe(root.className)}")
            fun visit(node: AccessibilityNodeInfo, parentId: String?, depth: Int) {
                if (visited >= 120 || logged >= 30 || depth > 8) return
                visited++
                val id = node.viewIdResourceName
                val clazz = node.className?.toString()
                // Prune before any content getter or child traversal; descendants cannot be visited.
                if (blocked(id, clazz, false) || node.isEditable || node.isPassword) return
                if (node.packageName?.toString() != MAX_PACKAGE) return
                val bounds = Rect().also(node::getBoundsInScreen)
                val inHeader = window.height() > 0 && !bounds.isEmpty &&
                    bounds.top >= window.top && bounds.bottom <= window.top + window.height() / 3
                if (candidate(id)) {
                    logged++
                    val textAllowed = allowText(id, false, inHeader)
                    Log.d(TAG, "node.depth=$depth viewId=${safe(id)} class=${safe(clazz)} " +
                        "packageName=${safe(node.packageName)} parentViewId=${safe(parentId)} " +
                        "clickable=${node.isClickable} enabled=${node.isEnabled} selected=${node.isSelected} " +
                        "focused=${node.isFocused} childCount=${node.childCount} textAllowed=$textAllowed")
                    if (textAllowed) {
                        Log.d(TAG, "node.text=${safe(node.text)} contentDescription=${safe(node.contentDescription)} " +
                            "uniqueId=${if (Build.VERSION.SDK_INT >= 33) safe(node.uniqueId) else "unsupported"} " +
                            "extrasKeys=${node.extras.keySet().take(24).map { safe(it) }} " +
                            "paneTitle=${if (Build.VERSION.SDK_INT >= 28) safe(node.paneTitle) else "unsupported"} " +
                            "tooltipText=${if (Build.VERSION.SDK_INT >= 28) safe(node.tooltipText) else "unsupported"} " +
                            "stateDescription=${if (Build.VERSION.SDK_INT >= 30) safe(node.stateDescription) else "unsupported"}")
                    }
                }
                for (i in 0 until minOf(node.childCount, 120)) {
                    if (visited >= 120 || logged >= 30) break
                    val child = node.getChild(i) ?: continue
                    try { visit(child, id, depth + 1) } finally { release(child) }
                }
            }
            visit(root, null, 0)
            Log.d(TAG, "end visited=$visited logged=$logged limits=120nodes/8depth/30candidates")
        } catch (_: Exception) {
            // Exception messages may contain UI data. Diagnostics must not interrupt outgoing storage.
            Log.d(TAG, "diagnosticsUnavailable")
        } finally {
            release(root)
        }
    }

    private fun safe(value: CharSequence?): String = value?.take(256)?.map {
        if (it.isISOControl() || it == '"' || it == '\\') ' ' else it
    }?.joinToString("")?.let { "\"$it\"" } ?: "null"

    private fun release(node: AccessibilityNodeInfo) {
        if (Build.VERSION.SDK_INT < 33) {
            @Suppress("DEPRECATION")
            node.recycle()
        }
    }
}
