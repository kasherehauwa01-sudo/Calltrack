package com.example.calltrack.reminder

import android.app.NotificationChannel
import android.app.NotificationManager
import android.app.PendingIntent
import android.media.AudioAttributes
import android.media.RingtoneManager
import android.content.Context
import android.content.Intent
import android.net.Uri
import android.os.Build
import androidx.core.app.NotificationCompat
import com.example.calltrack.R
import com.example.calltrack.ui.main.MainActivity

object ReminderNotifier {
    const val CHANNEL_ID = "reminder_channel"

    fun show(context: Context, phone: String, name: String, message: String) {
        val manager = context.getSystemService(NotificationManager::class.java)
        val soundUri = resolveSoundUri(context)
        val vibrationPattern = longArrayOf(0, 250, 180, 250)
        if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.O) {
            manager.createNotificationChannel(
                NotificationChannel(CHANNEL_ID, "Напоминания", NotificationManager.IMPORTANCE_HIGH).apply {
                    enableVibration(true)
                    vibrationPattern = vibrationPattern
                    setSound(
                        soundUri,
                        AudioAttributes.Builder()
                            .setUsage(AudioAttributes.USAGE_NOTIFICATION)
                            .setContentType(AudioAttributes.CONTENT_TYPE_SONIFICATION)
                            .build()
                    )
                }
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
            action = ReminderReceiver.ACTION_CALL
            putExtra(ReminderScheduler.EXTRA_PHONE, phone)
        }
        val callPending = PendingIntent.getBroadcast(
            context,
            (phone.hashCode() * 31),
            callIntent,
            PendingIntent.FLAG_UPDATE_CURRENT or PendingIntent.FLAG_IMMUTABLE
        )

        val notification = NotificationCompat.Builder(context, CHANNEL_ID)
            .setSmallIcon(R.drawable.ic_clover)
            .setContentTitle("Позвонить клиенту")
            .setContentText(if (message.isBlank()) name.ifBlank { phone } else message)
            .setPriority(NotificationCompat.PRIORITY_HIGH)
            .setAutoCancel(true)
            .setSound(soundUri)
            .setVibrate(vibrationPattern)
            .addAction(0, "Открыть карточку клиента", openCardPending)
            .addAction(0, "Позвонить", callPending)
            .setContentIntent(openCardPending)
            .build()

        manager.notify(("reminder" + phone).hashCode(), notification)
    }

    private fun resolveSoundUri(context: Context): Uri {
        val resId = context.resources.getIdentifier("voice", "raw", context.packageName)
        if (resId != 0) return Uri.parse("android.resource://${context.packageName}/$resId")
        return RingtoneManager.getDefaultUri(RingtoneManager.TYPE_NOTIFICATION)
    }
}
