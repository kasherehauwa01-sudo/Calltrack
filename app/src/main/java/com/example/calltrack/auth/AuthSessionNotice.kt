package com.example.calltrack.auth

import android.app.NotificationChannel
import android.app.NotificationManager
import android.app.PendingIntent
import android.content.Context
import android.content.Intent
import androidx.core.app.NotificationCompat
import com.example.calltrack.ui.auth.LoginActivity

/** Contains no credentials or client data. Notification permission may be denied. */
internal object AuthSessionNotice {
    private const val CHANNEL = "session_sync"
    private const val ID = 8042

    fun show(context: Context) {
        val manager = context.getSystemService(NotificationManager::class.java)
        manager.createNotificationChannel(NotificationChannel(CHANNEL, "Авторизация и синхронизация", NotificationManager.IMPORTANCE_DEFAULT))
        val intent = PendingIntent.getActivity(context, ID, Intent(context, LoginActivity::class.java),
            PendingIntent.FLAG_UPDATE_CURRENT or PendingIntent.FLAG_IMMUTABLE)
        runCatching {
            manager.notify(ID, NotificationCompat.Builder(context, CHANNEL)
                .setSmallIcon(android.R.drawable.stat_notify_error)
                .setContentTitle("Синхронизация требует внимания")
                .setContentText("Сервер отклонил запрос. Откройте Calltrack для проверки входа.")
                .setStyle(NotificationCompat.BigTextStyle().bigText("Сервер отклонил запрос. Откройте Calltrack для проверки входа. Неотправленные записи сохранены."))
                .setContentIntent(intent).setOnlyAlertOnce(true).setAutoCancel(true).build())
        }
    }

    fun clear(context: Context) {
        context.getSystemService(NotificationManager::class.java).cancel(ID)
    }
}
