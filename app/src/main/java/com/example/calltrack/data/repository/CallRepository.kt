package com.example.calltrack.data.repository

import android.content.Context
import android.util.Log
import com.example.calltrack.BuildConfig
import com.example.calltrack.data.local.CallDao
import com.example.calltrack.data.local.CallEntity
import com.example.calltrack.data.remote.WebhookApi
import com.example.calltrack.data.remote.WebhookRequest
import kotlinx.coroutines.flow.Flow
import java.text.SimpleDateFormat
import java.util.Date
import java.util.Locale

class CallRepository(
    private val callDao: CallDao,
    private val webhookApi: WebhookApi,
    context: Context
) {
    val prefs = PrefsManager(context)

    private val dateFormat = SimpleDateFormat("dd.MM.yy", Locale.getDefault())
    private val timeFormat = SimpleDateFormat("HH:mm", Locale.getDefault())
    fun observeCalls(): Flow<List<CallEntity>> = callDao.observeAll()

    suspend fun saveCall(call: CallEntity) {
        callDao.insert(call)
    }

    suspend fun syncPending() {
        val managerName = prefs.getManagerName().ifBlank { "Не указан" }
        val pending = callDao.getPending()
        pending.forEach { entity ->
            runCatching {
                webhookApi.sendCall(
                    BuildConfig.WEBHOOK_URL,
                    WebhookRequest(
                        date = dateFormat.format(Date(entity.timestamp)),
                        time = timeFormat.format(Date(entity.timestamp)),
                        phone = entity.phone,
                        type = entity.type,
                        duration = entity.duration,
                        manager = managerName,
                        comment = "",
                        tag = "",
                        reminder = ""
                    )
                )
                callDao.markUploaded(entity.id)
                Log.d("CallRepository", "Webhook sent: id=${entity.id}, phone=${entity.phone}")
            }.onFailure {
                Log.e("CallRepository", "Webhook send failed for id=${entity.id}", it)
            }
        }
    }
}
