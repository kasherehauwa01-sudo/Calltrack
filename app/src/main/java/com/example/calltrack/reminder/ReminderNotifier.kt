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
import com.example.calltrack.App
import com.example.calltrack.R
import com.example.calltrack.data.local.NotificationEntity
import com.example.calltrack.data.local.NotificationType
import com.example.calltrack.data.notification.NotificationTargets
import com.example.calltrack.ui.main.MainActivity
import kotlinx.coroutines.CoroutineScope
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.launch
import org.json.JSONObject

object ReminderNotifier {
    const val CHANNEL_ID = "reminder_channel"

    fun show(context: Context, phone: String, name: String, message: String) {
        val manager = context.getSystemService(NotificationManager::class.java)
        val soundUri = resolveSoundUri(context)
        val vibrationPattern = longArrayOf(0, 250, 180, 250)
        if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.O) {
            manager.createNotificationChannel(
                NotificationChannel(CHANNEL_ID, "\u041D\u0430\u043F\u043E\u043C\u0438\u043D\u0430\u043D\u0438\u044F", NotificationManager.IMPORTANCE_HIGH).apply {
                    enableVibration(true)
                    setVibrationPattern(vibrationPattern)
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
            .setContentTitle("\u041F\u043E\u0437\u0432\u043E\u043D\u0438\u0442\u044C \u043A\u043B\u0438\u0435\u043D\u0442\u0443")
            .setContentText(if (message.isBlank()) name.ifBlank { phone } else message)
            .setPriority(NotificationCompat.PRIORITY_HIGH)
            .setAutoCancel(true)
            .setSound(soundUri)
            .setVibrate(vibrationPattern)
            .addAction(0, "\u041E\u0442\u043A\u0440\u044B\u0442\u044C \u043A\u0430\u0440\u0442\u043E\u0447\u043A\u0443 \u043A\u043B\u0438\u0435\u043D\u0442\u0430", openCardPending)
            .addAction(0, "\u041F\u043E\u0437\u0432\u043E\u043D\u0438\u0442\u044C", callPending)
            .setContentIntent(openCardPending)
            .build()

        manager.notify(("reminder" + phone).hashCode(), notification)
        saveToNotificationCenter(context, phone, name, message)
    }

    private fun saveToNotificationCenter(context: Context, phone: String, name: String, message: String) {
        val app = context.applicationContext as? App ?: return
        val text = if (message.isBlank()) name.ifBlank { phone } else message
        val payload = JSONObject().apply {
            put("phone", phone)
            put("name", name)
        }.toString()
        CoroutineScope(Dispatchers.IO).launch {
            app.notificationRepository.insertNotification(
                NotificationEntity(
                    title = "\u041F\u043E\u0437\u0432\u043E\u043D\u0438\u0442\u044C \u043A\u043B\u0438\u0435\u043D\u0442\u0443",
                    message = text,
                    type = NotificationType.REMINDER,
                    targetScreen = NotificationTargets.REMINDER,
                    payloadJson = payload
                )
            )
        }
    }

    private fun resolveSoundUri(context: Context): Uri {
        val resId = context.resources.getIdentifier("voice", "raw", context.packageName)
        if (resId != 0) return Uri.parse("android.resource://${context.packageName}/$resId")
        return RingtoneManager.getDefaultUri(RingtoneManager.TYPE_NOTIFICATION)
    }
}
