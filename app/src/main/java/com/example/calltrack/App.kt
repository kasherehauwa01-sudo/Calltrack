package com.example.calltrack

import android.app.Application
import android.util.Log
import com.example.calltrack.data.local.CallDatabase
import com.example.calltrack.data.remote.ApiFactory
import com.example.calltrack.data.notification.NotificationRepository
import com.example.calltrack.data.repository.CallRepository
import com.example.calltrack.logging.AppLogger
import kotlinx.coroutines.CoroutineScope
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.SupervisorJob
import kotlinx.coroutines.launch

class App : Application() {
    lateinit var repository: CallRepository
        private set
    lateinit var notificationRepository: NotificationRepository
        private set

    override fun onCreate() {
        super.onCreate()
        AppLogger.install(this)
        Thread.setDefaultUncaughtExceptionHandler { _, e ->
            AppLogger.log(this, "CRASH", Log.getStackTraceString(e))
        }
        val db = CallDatabase.getInstance(this)
        notificationRepository = NotificationRepository(db.notificationDao())
        CoroutineScope(SupervisorJob() + Dispatchers.IO).launch {
            notificationRepository.cleanupOldNotifications()
        }
        repository = CallRepository(
            callDao = db.callDao(),
            contactDao = db.contactDao(),
            reminderDao = db.reminderDao(),
            commentDao = db.commentDao(),
            callHistoryDao = db.callHistoryDao(),
            webhookApi = ApiFactory.createWebhookApi(),
            context = this
        )
    }
}
