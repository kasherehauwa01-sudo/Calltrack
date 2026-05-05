package com.example.calltrack.reminder

import android.content.Context
import androidx.work.Worker
import androidx.work.WorkerParameters

class ReminderWorker(context: Context, params: WorkerParameters) : Worker(context, params) {
    override fun doWork(): Result {
        val phone = inputData.getString(ReminderScheduler.EXTRA_PHONE).orEmpty()
        val name = inputData.getString(ReminderScheduler.EXTRA_NAME).orEmpty()
        val message = inputData.getString(ReminderScheduler.EXTRA_MESSAGE).orEmpty()
        ReminderNotifier.show(applicationContext, phone, name, message)
        return Result.success()
    }
}
