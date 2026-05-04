package com.example.calltrack.reminder

import android.content.BroadcastReceiver
import android.content.Context
import android.content.Intent
import android.net.Uri

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
            else -> ReminderNotifier.show(context, phone, name, message)
        }
    }

    companion object {
        const val ACTION_SHOW_REMINDER = "com.example.calltrack.ACTION_SHOW_REMINDER"
        const val ACTION_CALL = "com.example.calltrack.ACTION_CALL_FROM_REMINDER"
    }
}
