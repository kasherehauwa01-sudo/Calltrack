package com.example.calltrack.data.repository

import android.content.Context
import com.example.calltrack.BuildConfig
import com.example.calltrack.data.local.CallDao
import com.example.calltrack.data.local.CallEntity
import com.example.calltrack.data.remote.WebhookApi
import com.example.calltrack.data.remote.WebhookRequest
import kotlinx.coroutines.flow.Flow

class CallRepository(
    private val callDao: CallDao,
    private val webhookApi: WebhookApi,
    context: Context
) {
    val prefs = PrefsManager(context)

    fun observeCalls(): Flow<List<CallEntity>> = callDao.observeAll()

    suspend fun saveCall(call: CallEntity) {
        callDao.insert(call)
    }

    suspend fun syncPending() {
        val pending = callDao.getPending()
        pending.forEach { entity ->
            runCatching {
                webhookApi.sendCall(
                    BuildConfig.WEBHOOK_URL,
                    WebhookRequest(entity.phone, entity.type, entity.duration, entity.note)
                )
                callDao.markUploaded(entity.id)
            }
        }
    }
}
