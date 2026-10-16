package com.example.calltrack.accessibility

import org.junit.Assert.*
import org.junit.Test

class MaxOutgoingIdentityDiagnosticsTest {
    private val policy = MaxOutgoingIdentityDiagnostics
    private fun id(name: String) = "ru.oneme.app:id/$name"

    @Test fun inputIsBlocked() {
        assertTrue(policy.blocked(id("oneme_message_input_edit_text"), "android.widget.EditText", false))
    }
    @Test fun messagesListIsBlocked() {
        assertTrue(policy.blocked(id("messages_list_recycler_view"), null, false))
        assertTrue(policy.blocked(null, "androidx.recyclerview.widget.RecyclerView", false))
    }
    @Test fun blockedAncestorExcludesEvenToolbar() {
        assertTrue(policy.blocked(id("oneme_toolbar_title"), "android.widget.TextView", true))
        assertFalse(policy.allowText(id("oneme_toolbar_title"), true, true))
    }
    @Test fun knownHeaderIsAllowed() {
        for (name in listOf("oneme_toolbar_title", "oneme_toolbar_subtitle")) {
            assertTrue(policy.candidate(id(name)))
            assertTrue(policy.allowText(id(name), false, true))
        }
        assertTrue(policy.candidate(id("oneme_toolbar")))
        assertFalse(policy.allowText(id("oneme_toolbar"), false, true))
    }
    @Test fun unknownTextViewAndGuessedChatTitleHaveNoTextAccess() {
        assertFalse(policy.allowText(id("unknown"), false, true))
        assertFalse(policy.allowText(id("chat_title"), false, true))
        assertFalse(policy.allowText(id("oneme_toolbar_title"), false, false))
    }
    @Test fun contentBranchesAreBlocked() {
        for (name in listOf("message_bubble", "reply_preview", "quoted_message", "attachments", "media_caption", "draft", "remote_input")) {
            assertTrue(name, policy.blocked(id(name), null, false))
        }
    }
    @Test fun debugAndPackageAreBothRequired() {
        assertTrue(policy.enabled(true, "ru.oneme.app"))
        assertFalse(policy.enabled(false, "ru.oneme.app"))
        assertFalse(policy.enabled(true, "other.app"))
        assertFalse(policy.enabled(true, null))
    }
}
