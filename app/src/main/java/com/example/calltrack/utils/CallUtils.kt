package com.example.calltrack.utils

import android.telephony.PhoneNumberUtils
import com.example.calltrack.domain.CallType
import java.util.concurrent.TimeUnit

object CallUtils {
    fun formatPhone(raw: String): String = PhoneNumberUtils.formatNumber(raw, "RU") ?: raw

    fun defineCallType(startedFromApp: Boolean, wasRinging: Boolean, durationSec: Long): CallType {
        return when {
            !startedFromApp && durationSec > 0 -> CallType.OUTGOING
            !startedFromApp && !wasRinging -> CallType.UNANSWERED
            wasRinging && durationSec == 0L -> CallType.MISSED
            wasRinging -> CallType.INCOMING
            else -> CallType.OUTGOING
        }
    }

    fun calculateDurationSec(startMs: Long, endMs: Long): Long {
        return TimeUnit.MILLISECONDS.toSeconds((endMs - startMs).coerceAtLeast(0L))
    }
}
