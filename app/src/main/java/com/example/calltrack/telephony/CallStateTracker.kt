package com.example.calltrack.telephony

import android.content.Context
import android.os.Build
import android.telephony.PhoneStateListener
import android.telephony.TelephonyCallback
import android.telephony.TelephonyManager
import android.util.Log

class CallStateTracker(
    private val context: Context,
    private val listener: (Int, String?) -> Unit
) {
    private val telephonyManager = context.getSystemService(TelephonyManager::class.java)
    private var callback: TelephonyCallback? = null
    private var phoneStateListener: PhoneStateListener? = null

    fun start(): Boolean {
        if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.S) {
            val cb = object : TelephonyCallback(), TelephonyCallback.CallStateListener {
                override fun onCallStateChanged(state: Int) {
                    listener(state, null)
                }
            }
            callback = cb
            return try {
                telephonyManager.registerTelephonyCallback(context.mainExecutor, cb)
                true
            } catch (se: SecurityException) {
                Log.e("CallStateTracker", "Нет разрешения на подписку CallState", se)
                false
            }
        } else {
            @Suppress("DEPRECATION")
            val legacy = object : PhoneStateListener() {
                override fun onCallStateChanged(state: Int, phoneNumber: String?) {
                    listener(state, phoneNumber)
                }
            }
            phoneStateListener = legacy
            @Suppress("DEPRECATION")
            return try {
                telephonyManager.listen(legacy, PhoneStateListener.LISTEN_CALL_STATE)
                true
            } catch (se: SecurityException) {
                Log.e("CallStateTracker", "Нет разрешения на legacy CallState", se)
                false
            }
        }
    }

    fun stop() {
        if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.S) {
            callback?.let { telephonyManager.unregisterTelephonyCallback(it) }
        } else {
            @Suppress("DEPRECATION")
            phoneStateListener?.let { telephonyManager.listen(it, PhoneStateListener.LISTEN_NONE) }
        }
    }
}
