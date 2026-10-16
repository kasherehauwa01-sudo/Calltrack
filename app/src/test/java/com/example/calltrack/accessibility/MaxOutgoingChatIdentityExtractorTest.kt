package com.example.calltrack.accessibility

import org.junit.Assert.*
import org.junit.Test

class MaxOutgoingChatIdentityExtractorTest {
    private val extractor = MaxOutgoingChatIdentityExtractor
    private class FakeNode(
        override val id: String? = null,
        private val value: String? = null,
        val children: List<FakeNode> = emptyList(),
        override val packageName: String? = MaxOutgoingChatIdentityExtractor.PACKAGE,
        override val className: String? = "android.view.View",
        override val editable: Boolean = false,
        override val password: Boolean = false
    ) : MaxOutgoingChatIdentityExtractor.Node {
        var textReads = 0
        var childReads = 0
        var released = false
        override val childCount get() = children.size
        override fun child(index: Int): FakeNode { childReads++; return children[index] }
        override fun text(): String? { textReads++; return value }
        override fun release() { released = true }
    }
    private fun title(text: String?) = FakeNode(extractor.TITLE, text)
    private fun subtitle(text: String?) = FakeNode(extractor.SUBTITLE, text)
    private fun toolbar(vararg children: FakeNode) = FakeNode(extractor.TOOLBAR, children = children.toList())
    private fun root(vararg children: FakeNode) = FakeNode(children = children.toList())

    @Test fun personalStatuses() {
        for (status in listOf("В сети", "3 мин назад", "был(а) недавно")) {
            val result = extractor.extract(root(toolbar(title("Офис Менеджер ВР"), subtitle(status))))
            assertEquals("Офис Менеджер ВР", result?.contactName)
            assertEquals(false, result?.isGroup)
        }
    }
    @Test fun confirmedGroupsAndArbitraryCounts() {
        for ((name, status) in listOf("Семья" to "1 из 8 в сети", "Маркетинг. Согласование." to "1 из 3 в сети", "Группа" to "2 из 15 в сети", "Группа" to "10 из 25 в сети")) {
            assertEquals(true, extractor.extract(root(toolbar(title(name), subtitle(status))))?.isGroup)
        }
    }
    @Test fun groupWhitespaceIsNormalized() {
        for (status in listOf("1 из 8 в сети", " 1 из 8 в сети ", "1  из  8  в сети", "1\u00a0из\u202f8 в\tсети")) {
            assertEquals(true, extractor.classify(extractor.PACKAGE, true, "Группа", status)?.isGroup)
        }
    }
    @Test fun incompleteHeaderFailsClosed() {
        for (node in listOf(root(), root(toolbar(subtitle("В сети"))), root(toolbar(title(" "), subtitle("В сети"))), root(toolbar(title("Имя"))), root(toolbar(title("Имя"), subtitle(" "))))) {
            assertNull(extractor.extract(node))
            assertTrue(node.released)
        }
        assertNull(extractor.classify(extractor.PACKAGE, false, "Имя", "В сети"))
    }
    @Test fun wrongPackageFailsClosed() {
        val foreign = FakeNode(children = listOf(toolbar(title("Имя"), subtitle("В сети"))), packageName = "other.app")
        assertNull(extractor.extract(foreign))
        assertEquals(0, foreign.childReads)
    }
    @Test fun labelsOutsideToolbarAndNestedLabelsAreNotRead() {
        val outside = title("Not a contact")
        val nested = title("Not direct")
        assertNull(extractor.extract(root(outside, toolbar(FakeNode(children = listOf(nested)), subtitle("В сети")))))
        assertEquals(0, outside.textReads)
        assertEquals(0, nested.textReads)
    }
    @Test fun messageInputAndContentBranchesAreNeverTraversedOrRead() {
        val forbidden = listOf("messages_list_recycler_view", "oneme_message_input_edit_text", "message_bubble", "reply_preview", "quoted_message", "attachments", "media_caption", "draft", "remote_input").map {
            FakeNode("ru.oneme.app:id/$it", "private", listOf(toolbar(title("Wrong"), subtitle("В сети"))))
        }
        val anonymousInput = FakeNode(value = "private", className = "android.widget.EditText")
        assertNull(extractor.extract(root(*(forbidden + anonymousInput).toTypedArray())))
        for (node in forbidden + anonymousInput) {
            assertEquals(0, node.textReads)
            assertEquals(0, node.childReads)
        }
    }
    @Test fun unrelatedToolbarChildrenHaveNoTextAccess() {
        val icon = FakeNode("ru.oneme.app:id/oneme_right_third_icon_button", "private")
        val name = title(" Имя ")
        val status = subtitle("В сети")
        assertEquals("Имя", extractor.extract(root(toolbar(icon, name, status)))?.contactName)
        assertEquals(0, icon.textReads)
        assertEquals(1, name.textReads)
        assertEquals(1, status.textReads)
    }
    @Test fun duplicateLabelsOrToolbarsAreAmbiguous() {
        assertNull(extractor.extract(root(toolbar(title("A"), title("B"), subtitle("В сети")))))
        assertNull(extractor.extract(root(toolbar(title("A"), subtitle("В сети")), toolbar(title("B"), subtitle("В сети")))))
    }
    @Test fun noIdentityIsCachedAcrossCalls() {
        assertNotNull(extractor.extract(root(toolbar(title("A"), subtitle("В сети")))))
        assertNull(extractor.extract(root()))
    }
}
