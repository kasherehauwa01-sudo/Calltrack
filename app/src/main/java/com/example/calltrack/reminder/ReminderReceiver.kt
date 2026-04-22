package com.example.calltrack.reminder

import android.app.NotificationChannel
import android.app.NotificationManager
import android.app.PendingIntent
import android.content.BroadcastReceiver
import android.content.Context
import android.content.Intent
import android.net.Uri
import android.os.Build
import androidx.core.app.NotificationCompat
import com.example.calltrack.R
import com.example.calltrack.ui.main.MainActivity

class ReminderReceiver : BroadcastReceiver() {

    override fun onReceive(context: Context, intent: Intent) {
        val phone = intent.getStringExtra(ReminderScheduler.EXTRA_PHONE).orEmpty()
        val name = intent.getStringExtra(ReminderScheduler.EXTRA_NAME).orEmpty().ifBlank { phone }
        val message = intent.getStringExtra(ReminderScheduler.EXTRA_MESSAGE).orEmpty()

        when (intent.action) {
            ACTION_CALL -> {
                val dialIntent = Intent(Intent.ACTION_DIAL, Uri.parse("tel:$phone")).apply {
                    addFlags(Intent.FLAG_ACTIVITY_NEW_TASK)
                }
                context.startActivity(dialIntent)
            }
            else -> showNotification(context, phone, name, message)
        }
    }

    private fun showNotification(context: Context, phone: String, name: String, message: String) {
        val manager = context.getSystemService(NotificationManager::class.java)
        if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.O) {
            manager.createNotificationChannel(
                NotificationChannel(CHANNEL_ID, "Напоминания", NotificationManager.IMPORTANCE_HIGH)
            )
        }

        val openCardIntent = Intent(context, MainActivity::class.java).apply {
            putExtra(MainActivity.EXTRA_OPEN_CONTACT_PHONE, phone)
            addFlags(Intent.FLAG_ACTIVITY_NEW_TASK or Intent.FLAG_ACTIVITY_CLEAR_TOP)
        }
        val openCardPending = PendingIntent.getActivity(
            context,
            phone.hashCode(),
            openCardIntent,
            PendingIntent.FLAG_UPDATE_CURRENT or PendingIntent.FLAG_IMMUTABLE
        )

        val callIntent = Intent(context, ReminderReceiver::class.java).apply {
            action = ACTION_CALL
            putExtra(ReminderScheduler.EXTRA_PHONE, phone)
        }
        val callPending = PendingIntent.getBroadcast(
            context,
            (phone.hashCode() * 31),
            callIntent,
            PendingIntent.FLAG_UPDATE_CURRENT or PendingIntent.FLAG_IMMUTABLE
        )

        val notification = NotificationCompat.Builder(context, CHANNEL_ID)
            .setSmallIcon(R.drawable.ic_spyglass)
            .setContentTitle("Напоминание")
            .setContentText(if (message.isBlank()) "Позвонить клиенту \"$name\"" else message)
            .setPriority(NotificationCompat.PRIORITY_HIGH)
            .setAutoCancel(true)
            .addAction(0, "Открыть карточку клиента", openCardPending)
            .addAction(0, "Позвонить", callPending)
            .setContentIntent(openCardPending)
            .build()

        manager.notify(("reminder" + phone).hashCode(), notification)
    }

    companion object {
        const val CHANNEL_ID = "calltrack_reminders"
        const val ACTION_SHOW_REMINDER = "com.example.calltrack.ACTION_SHOW_REMINDER"
        const val ACTION_CALL = "com.example.calltrack.ACTION_CALL_FROM_REMINDER"
    }
}
