package com.example.calltrack.data.local

import android.content.Context
import androidx.room.Database
import androidx.room.Room
import androidx.room.RoomDatabase
import androidx.room.TypeConverters
import androidx.room.migration.Migration
import androidx.sqlite.db.SupportSQLiteDatabase

@Database(
    entities = [
        CallEntity::class,
        ContactEntity::class,
        ReminderEntity::class,
        CommentEntity::class,
        CallHistoryEntity::class,
        NotificationEntity::class,
        PersonalContactEntity::class
    ],
    version = 8
)
@TypeConverters(NotificationTypeConverter::class)
abstract class CallDatabase : RoomDatabase() {
    abstract fun callDao(): CallDao
    abstract fun contactDao(): ContactDao
    abstract fun reminderDao(): ReminderDao
    abstract fun commentDao(): CommentDao
    abstract fun callHistoryDao(): CallHistoryDao
    abstract fun notificationDao(): NotificationDao
    abstract fun personalContactDao(): PersonalContactDao

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
                        client1c TEXT NOT NULL DEFAULT '',
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
                        message TEXT NOT NULL DEFAULT '',
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

        private val MIGRATION_2_3 = object : Migration(2, 3) {
            override fun migrate(db: SupportSQLiteDatabase) {
                db.execSQL("ALTER TABLE reminders ADD COLUMN message TEXT NOT NULL DEFAULT ''")
            }
        }


        private val MIGRATION_3_4 = object : Migration(3, 4) {
            override fun migrate(db: SupportSQLiteDatabase) {
                db.execSQL("ALTER TABLE contacts ADD COLUMN client1c TEXT NOT NULL DEFAULT ''")
            }
        }

        private val MIGRATION_4_5 = object : Migration(4, 5) {
            override fun migrate(db: SupportSQLiteDatabase) {
                db.execSQL(
                    """
                    CREATE TABLE IF NOT EXISTS call_history (
                        id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL,
                        phone TEXT NOT NULL,
                        date TEXT NOT NULL,
                        time TEXT NOT NULL,
                        type TEXT NOT NULL,
                        duration TEXT NOT NULL,
                        manager TEXT NOT NULL,
                        note TEXT NOT NULL,
                        tag TEXT NOT NULL,
                        reminder TEXT NOT NULL,
                        reminderText TEXT NOT NULL,
                        client TEXT NOT NULL,
                        updatedAt INTEGER NOT NULL
                    )
                    """.trimIndent()
                )
            }
        }

        private val MIGRATION_5_6 = object : Migration(5, 6) {
            override fun migrate(db: SupportSQLiteDatabase) {
                db.execSQL(
                    """
                    CREATE TABLE IF NOT EXISTS notifications (
                        id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL,
                        title TEXT NOT NULL,
                        message TEXT NOT NULL,
                        type TEXT NOT NULL,
                        isRead INTEGER NOT NULL,
                        createdAt INTEGER NOT NULL,
                        targetScreen TEXT NOT NULL,
                        entityId INTEGER,
                        payloadJson TEXT NOT NULL
                    )
                    """.trimIndent()
                )
            }
        }

        private val MIGRATION_6_7 = object : Migration(6, 7) {
            override fun migrate(db: SupportSQLiteDatabase) {
                db.execSQL(
                    """
                    CREATE TABLE IF NOT EXISTS personal_contacts (
                        contactPhone TEXT PRIMARY KEY NOT NULL,
                        personalFlag INTEGER NOT NULL,
                        updatedAt INTEGER NOT NULL
                    )
                    """.trimIndent()
                )
            }
        }

        private val MIGRATION_7_8 = object : Migration(7, 8) {
            override fun migrate(db: SupportSQLiteDatabase) {
                db.execSQL("ALTER TABLE calls ADD COLUMN source TEXT NOT NULL DEFAULT 'phone'")
                db.execSQL("ALTER TABLE calls ADD COLUMN sourceEventId TEXT NOT NULL DEFAULT ''")
                db.execSQL("ALTER TABLE calls ADD COLUMN contactName TEXT NOT NULL DEFAULT ''")
                db.execSQL("ALTER TABLE calls ADD COLUMN direction TEXT NOT NULL DEFAULT ''")
                db.execSQL("ALTER TABLE calls ADD COLUMN status TEXT NOT NULL DEFAULT ''")
                db.execSQL("ALTER TABLE calls ADD COLUMN answeredAt INTEGER")
                db.execSQL("ALTER TABLE calls ADD COLUMN endedAt INTEGER")
                db.execSQL("ALTER TABLE calls ADD COLUMN ringingDurationSeconds INTEGER")
                db.execSQL("ALTER TABLE calls ADD COLUMN isVideo INTEGER NOT NULL DEFAULT 0")
                db.execSQL("ALTER TABLE calls ADD COLUMN contactResolutionStatus TEXT NOT NULL DEFAULT ''")
                db.execSQL("CREATE INDEX IF NOT EXISTS index_calls_sourceEventId ON calls(sourceEventId)")
            }
        }

        fun getInstance(context: Context): CallDatabase {
            return INSTANCE ?: synchronized(this) {
                INSTANCE ?: Room.databaseBuilder(
                    context.applicationContext,
                    CallDatabase::class.java,
                    "calltrack.db"
                ).addMigrations(
                        MIGRATION_1_2,
                        MIGRATION_2_3,
                        MIGRATION_3_4,
                        MIGRATION_4_5,
                        MIGRATION_5_6,
                        MIGRATION_6_7,
                        MIGRATION_7_8
                    )
                    .build().also { INSTANCE = it }
            }
        }
    }
}
