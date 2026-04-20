package com.example.calltrack

import android.app.Application
import com.example.calltrack.data.local.CallDatabase
import com.example.calltrack.data.remote.ApiFactory
import com.example.calltrack.data.repository.CallRepository

class App : Application() {
    lateinit var repository: CallRepository
        private set

    override fun onCreate() {
        super.onCreate()
        val db = CallDatabase.getInstance(this)
        repository = CallRepository(
            callDao = db.callDao(),
            webhookApi = ApiFactory.createWebhookApi(),
            context = this
        )
    }
}
