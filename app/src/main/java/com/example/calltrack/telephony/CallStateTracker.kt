package com.example.calltrack.telephony

import android.Manifest
import android.content.Context
import android.content.pm.PackageManager
import android.os.Build
import android.telephony.PhoneStateListener
import android.telephony.TelephonyCallback
import android.telephony.TelephonyManager
import androidx.core.content.ContextCompat

class CallStateTracker(
    private val context: Context,
    private val listener: (Int, String?) -> Unit
) {
    private val telephonyManager = context.getSystemService(TelephonyManager::class.java)
    private var callback: TelephonyCallback? = null
    private var phoneStateListener: PhoneStateListener? = null

    fun start() {
        if (ContextCompat.checkSelfPermission(context, Manifest.permission.READ_PHONE_STATE) != PackageManager.PERMISSION_GRANTED) return
        try {
        if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.S) {
            val cb = object : TelephonyCallback(), TelephonyCallback.CallStateListener {
                override fun onCallStateChanged(state: Int) {
                    listener(state, null)
                }
            }
            callback = cb
            telephonyManager.registerTelephonyCallback(context.mainExecutor, cb)
        } else {
            @Suppress("DEPRECATION")
            val legacy = object : PhoneStateListener() {
                override fun onCallStateChanged(state: Int, phoneNumber: String?) {
                    listener(state, phoneNumber)
                }
            }
            phoneStateListener = legacy
            @Suppress("DEPRECATION")
            telephonyManager.listen(legacy, PhoneStateListener.LISTEN_CALL_STATE)
        }
        } catch (_: SecurityException) {
            callback = null
            phoneStateListener = null
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
