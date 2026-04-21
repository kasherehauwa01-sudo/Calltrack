package com.example.calltrack.data.local

import android.content.Context
import androidx.room.Database
import androidx.room.Room
import androidx.room.RoomDatabase
import androidx.room.migration.Migration
import androidx.sqlite.db.SupportSQLiteDatabase

@Database(
    entities = [
        CallEntity::class,
        ContactEntity::class,
        ReminderEntity::class,
        CommentEntity::class
    ],
    version = 2
)
abstract class CallDatabase : RoomDatabase() {
    abstract fun callDao(): CallDao
    abstract fun contactDao(): ContactDao
    abstract fun reminderDao(): ReminderDao
    abstract fun commentDao(): CommentDao

    companion object {
        @Volatile
        private var INSTANCE: CallDatabase? = null

        private val MIGRATION_1_2 = object : Migration(1, 2) {
            override fun migrate(db: SupportSQLiteDatabase) {
                db.execSQL("ALTER TABLE calls ADD COLUMN tag TEXT NOT NULL DEFAULT ''")
                db.execSQL("ALTER TABLE calls ADD COLUMN reminder TEXT NOT NULL DEFAULT ''")
                db.execSQL(
                    """
                    CREATE TABLE IF NOT EXISTS contacts (
                        id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL,
                        phone TEXT NOT NULL,
                        name TEXT NOT NULL,
                        createdAt INTEGER NOT NULL
                    )
                    """.trimIndent()
                )
                db.execSQL(
                    """
                    CREATE TABLE IF NOT EXISTS reminders (
                        id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL,
                        phone TEXT NOT NULL,
                        contactName TEXT NOT NULL,
                        remindAt INTEGER NOT NULL,
                        status TEXT NOT NULL,
                        createdAt INTEGER NOT NULL
                    )
                    """.trimIndent()
                )
                db.execSQL(
                    """
                    CREATE TABLE IF NOT EXISTS comments (
                        id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL,
                        phone TEXT NOT NULL,
                        text TEXT NOT NULL,
                        createdAt INTEGER NOT NULL
                    )
                    """.trimIndent()
                )
            }
        }

        fun getInstance(context: Context): CallDatabase {
            return INSTANCE ?: synchronized(this) {
                INSTANCE ?: Room.databaseBuilder(
                    context.applicationContext,
                    CallDatabase::class.java,
                    "calltrack.db"
                ).addMigrations(MIGRATION_1_2)
                    .build().also { INSTANCE = it }
            }
        }
    }
}
