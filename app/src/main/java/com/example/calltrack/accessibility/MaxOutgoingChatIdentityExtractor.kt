package com.example.calltrack.accessibility

import android.os.Build
import android.view.accessibility.AccessibilityNodeInfo

/** Reads only the two confirmed direct chat-toolbar labels. No cached identity or message content. */
internal object MaxOutgoingChatIdentityExtractor {
    internal const val PACKAGE = "ru.oneme.app"
    internal const val TOOLBAR = "$PACKAGE:id/chat__toolbar"
    internal const val TITLE = "$PACKAGE:id/oneme_toolbar_title"
    internal const val SUBTITLE = "$PACKAGE:id/oneme_toolbar_subtitle"
    private val groupStatus = Regex("[0-9]+\\s+из\\s+[0-9]+\\s+в\\s+сети")
    private val whitespace = Regex("[\\s\\p{Z}]+")
    private val excluded = listOf("message", "input", "bubble", "reply", "quote", "attachment", "media", "caption", "draft", "composer", "preview")

    internal data class Identity(val contactName: String, val isGroup: Boolean)

    internal fun classify(packageName: String?, toolbarPresent: Boolean, title: String?, subtitle: String?): Identity? {
        if (packageName != PACKAGE || !toolbarPresent) return null
        val name = title?.trim()?.takeIf { it.isNotEmpty() } ?: return null
        val status = subtitle?.replace(whitespace, " ")?.trim()?.takeIf { it.isNotEmpty() } ?: return null
        return Identity(name, groupStatus.matches(status))
    }

    /** Testable node boundary: text() is invoked exclusively for confirmed toolbar labels. */
    internal interface Node {
        val id: String?
        val packageName: String?
        val className: String?
        val editable: Boolean
        val password: Boolean
        val childCount: Int
        fun child(index: Int): Node?
        fun text(): String?
        fun release()
    }

    // Takes ownership of the root handle, including on invalid/exceptional paths.
    fun extract(root: AccessibilityNodeInfo?): Identity? = root?.let { extract(AndroidNode(it)) }

    internal fun extract(root: Node): Identity? {
        var visited = 0
        var toolbarCount = 0
        var result: Identity? = null
        var incomplete = false
        fun safe(node: Node): Boolean = node.packageName == PACKAGE && !node.editable && !node.password &&
            excluded.none { node.id.orEmpty().substringAfter(":id/").contains(it, true) } &&
            listOf("EditText", "RecyclerView", "ListView", "WebView").none { node.className.orEmpty().contains(it) }
        fun readToolbar(node: Node): Identity? {
            if (node.childCount !in 2..16) return null
            var title: String? = null
            var subtitle: String? = null
            var titles = 0
            var subtitles = 0
            for (i in 0 until node.childCount) {
                val child = node.child(i) ?: return null
                try {
                    if (!safe(child)) continue
                    when (child.id) {
                        TITLE -> { titles++; title = child.text() }
                        SUBTITLE -> { subtitles++; subtitle = child.text() }
                    }
                } finally { child.release() }
            }
            return if (titles == 1 && subtitles == 1) classify(PACKAGE, true, title, subtitle) else null
        }
        fun visit(node: Node, depth: Int) {
            if (!safe(node)) return
            if (visited >= 120 || depth > 8) { incomplete = true; return }
            visited++
            if (node.id == TOOLBAR) {
                toolbarCount++
                result = readToolbar(node)
                return // Never inspect toolbar descendants beyond the direct labels.
            }
            if (node.childCount > 120) { incomplete = true; return }
            for (i in 0 until node.childCount) {
                val child = node.child(i)
                if (child == null) { incomplete = true; continue }
                try { visit(child, depth + 1) } finally { child.release() }
                if (incomplete) return
            }
        }
        return try {
            visit(root, 0)
            if (!incomplete && toolbarCount == 1) result else null
        } catch (_: Exception) {
            // Fail closed. Exception strings can include UI data; do not log them.
            null
        } finally { root.release() }
    }

    private class AndroidNode(private val node: AccessibilityNodeInfo) : Node {
        override val id get() = node.viewIdResourceName
        override val packageName get() = node.packageName?.toString()
        override val className get() = node.className?.toString()
        override val editable get() = node.isEditable
        override val password get() = node.isPassword
        override val childCount get() = node.childCount
        override fun child(index: Int): Node? = node.getChild(index)?.let(::AndroidNode)
        override fun text() = node.text?.toString()
        override fun release() {
            if (Build.VERSION.SDK_INT < 33) {
                @Suppress("DEPRECATION")
                node.recycle()
            }
        }
    }
}
