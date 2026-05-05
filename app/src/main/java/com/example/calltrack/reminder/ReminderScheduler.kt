package com.example.calltrack.reminder

import android.content.Context
import android.util.Log
import androidx.work.Data
import androidx.work.OneTimeWorkRequestBuilder
import androidx.work.WorkManager
import java.util.concurrent.TimeUnit

object ReminderScheduler {
    const val EXTRA_PHONE = "extra_phone"
    const val EXTRA_NAME = "extra_name"
    const val EXTRA_MESSAGE = "extra_message"

    fun schedule(context: Context, phone: String, name: String, triggerAtMillis: Long, message: String = "") {
        val delay = triggerAtMillis - System.currentTimeMillis()
        if (delay <= 0L) {
            Log.w("ReminderScheduler", "Напоминание не запланировано: время уже прошло ($triggerAtMillis)")
            return
        }

        val inputData = Data.Builder()
            .putString(EXTRA_PHONE, phone)
            .putString(EXTRA_NAME, name)
            .putString(EXTRA_MESSAGE, message)
            .build()
        val request = OneTimeWorkRequestBuilder<ReminderWorker>()
            .setInputData(inputData)
            .setInitialDelay(delay, TimeUnit.MILLISECONDS)
            .build()

        WorkManager.getInstance(context).enqueue(request)
    }
}
