package com.example.calltrack

import android.app.Application
import com.example.calltrack.data.local.CallDatabase
import com.example.calltrack.data.remote.ApiFactory
import com.example.calltrack.data.repository.CallRepository
import com.example.calltrack.logging.AppLogger

class App : Application() {
    lateinit var repository: CallRepository
        private set

    override fun onCreate() {
        super.onCreate()
        AppLogger.install(this)
        val db = CallDatabase.getInstance(this)
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
