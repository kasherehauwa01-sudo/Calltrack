package com.example.calltrack

import android.app.Application
import com.example.calltrack.data.local.CallDatabase
import com.example.calltrack.data.remote.ApiFactory
import com.example.calltrack.data.notification.NotificationRepository
import com.example.calltrack.data.repository.CallRepository
import com.example.calltrack.logging.AppLogger
import com.example.calltrack.service.CalltrackStabilityWorker
import com.google.android.material.color.DynamicColors
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
        DynamicColors.applyToActivitiesIfAvailable(this)
        AppLogger.install(this)
        // AppLogger сохраняет падение и обязательно передаёт его системному
        // обработчику Android. Не заменяем этот обработчик повторно.
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
            personalContactDao = db.personalContactDao(),
            webhookApi = ApiFactory.createWebhookApi(),
            context = this
        )
        CalltrackStabilityWorker.schedule(this)
    }
}
