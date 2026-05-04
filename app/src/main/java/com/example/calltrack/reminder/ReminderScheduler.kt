package com.example.calltrack.reminder

import android.app.AlarmManager
import android.app.PendingIntent
import android.content.Context
import android.content.Intent
import android.os.Build
import android.util.Log

object ReminderScheduler {
    const val EXTRA_PHONE = "extra_phone"
    const val EXTRA_NAME = "extra_name"
    const val EXTRA_MESSAGE = "extra_message"

    fun schedule(context: Context, phone: String, name: String, triggerAtMillis: Long, message: String = "") {
        val alarmManager = context.getSystemService(AlarmManager::class.java)
        val intent = Intent(context, ReminderReceiver::class.java).apply {
            action = ReminderReceiver.ACTION_SHOW_REMINDER
            putExtra(EXTRA_PHONE, phone)
            putExtra(EXTRA_NAME, name)
            putExtra(EXTRA_MESSAGE, message)
        }
        val requestCode = (phone + triggerAtMillis).hashCode()
        val pending = PendingIntent.getBroadcast(
            context,
            requestCode,
            intent,
            PendingIntent.FLAG_UPDATE_CURRENT or PendingIntent.FLAG_IMMUTABLE
        )
        runCatching {
            if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.S && !alarmManager.canScheduleExactAlarms()) {
                // Без exact-прав на Android 12+ ставим максимально близкий fallback.
                alarmManager.setAndAllowWhileIdle(AlarmManager.RTC_WAKEUP, triggerAtMillis, pending)
            } else {
                alarmManager.setExactAndAllowWhileIdle(AlarmManager.RTC_WAKEUP, triggerAtMillis, pending)
            }
        }.onFailure {
            Log.e("ReminderScheduler", "Не удалось запланировать напоминание на $triggerAtMillis", it)
        }
    }
}
