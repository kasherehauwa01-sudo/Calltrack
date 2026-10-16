package com.example.calltrack.data.local

import androidx.sqlite.db.SupportSQLiteDatabase
import com.example.calltrack.notification.MaxIncomingMessageCandidate
import com.example.calltrack.notification.MaxIncomingMessageDetector
import org.junit.Assert.*
import org.junit.Test
import java.lang.reflect.Proxy
import java.nio.file.Files
import java.sql.Connection
import java.sql.DriverManager

/** Executes the production migration against real SQLite without requiring an emulator. */
class MaxMessageMigrationTest {
    private fun migrate(connection: Connection) {
        val db = Proxy.newProxyInstance(SupportSQLiteDatabase::class.java.classLoader,
            arrayOf(SupportSQLiteDatabase::class.java)) { _, method, args ->
            check(method.name == "execSQL")
            connection.createStatement().use { it.execute(args!![0] as String) }; null
        } as SupportSQLiteDatabase
        CallDatabase.MIGRATION_9_10.migrate(db)
    }

    @Test fun migrationPreservesRowsAndUniqueIndexAcrossReopen() {
        val file = Files.createTempFile("max-migration-", ".sqlite")
        val url = "jdbc:sqlite:$file"
        try {
            DriverManager.getConnection(url).use { c ->
                c.createStatement().use { s ->
                    s.execute("CREATE TABLE max_messages (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, sourceEventId TEXT NOT NULL, direction TEXT NOT NULL, contactName TEXT NOT NULL DEFAULT '', phone TEXT NOT NULL DEFAULT '', shortcutId TEXT NOT NULL DEFAULT '', occurredAt INTEGER NOT NULL, uploaded INTEGER NOT NULL DEFAULT 0, createdAt INTEGER NOT NULL)")
                    s.execute("CREATE UNIQUE INDEX index_max_messages_sourceEventId ON max_messages(sourceEventId)")
                    s.execute("CREATE INDEX index_max_messages_shortcutId ON max_messages(shortcutId)")
                    s.execute("CREATE INDEX index_max_messages_phone ON max_messages(phone)")
                    s.execute("INSERT INTO max_messages(sourceEventId,direction,contactName,occurredAt,createdAt) VALUES('old-outgoing','outgoing','Old contact',1,2)")
                }
                migrate(c)
                c.createStatement().executeQuery("SELECT * FROM max_messages").use { row ->
                    assertTrue(row.next()); assertEquals("Old contact", row.getString("contactName"))
                    assertEquals("outgoing", row.getString("direction")); assertEquals("", row.getString("messageText"))
                    assertEquals("", row.getString("senderName")); assertEquals("", row.getString("conversationTitle"))
                    assertEquals(0, row.getInt("isGroupConversation")); assertEquals(1L, row.getLong("occurredAt"))
                }
            }
            val candidates = listOf(MaxIncomingMessageCandidate("same", "Alice", "alice", 1000), MaxIncomingMessageCandidate("same", "Alice", "alice", 1001))
            val messages = MaxIncomingMessageDetector.extract("chat", "Team", "s", true, candidates)
            // Reopen the SQLite connection between deliveries; no in-memory deduplication exists here.
            repeat(3) {
                DriverManager.getConnection(url).use { c ->
                    c.prepareStatement("INSERT OR IGNORE INTO max_messages(sourceEventId,direction,contactName,occurredAt,createdAt,messageText,senderName,conversationTitle,isGroupConversation) VALUES(?,?,?,?,?,?,?,?,?)").use { insert ->
                        for (message in messages) {
                            insert.setString(1, message.sourceEventId); insert.setString(2, "incoming")
                            insert.setString(3, message.senderName); insert.setLong(4, message.occurredAt); insert.setLong(5, 2000)
                            insert.setString(6, message.messageText); insert.setString(7, message.senderName)
                            insert.setString(8, message.conversationTitle); insert.setInt(9, 1); insert.executeUpdate()
                        }
                    }
                    c.createStatement().executeQuery("SELECT COUNT(*) FROM max_messages").use { row -> row.next(); assertEquals(3, row.getInt(1)) }
                }
            }
        } finally { Files.deleteIfExists(file) }
    }
}
