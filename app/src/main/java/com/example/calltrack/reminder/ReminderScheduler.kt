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
            Log.w("ReminderScheduler", "\u041D\u0430\u043F\u043E\u043C\u0438\u043D\u0430\u043D\u0438\u0435 \u043D\u0435 \u0437\u0430\u043F\u043B\u0430\u043D\u0438\u0440\u043E\u0432\u0430\u043D\u043E: \u0432\u0440\u0435\u043C\u044F \u0443\u0436\u0435 \u043F\u0440\u043E\u0448\u043B\u043E ($triggerAtMillis)")
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
