package com.example.calltrack.data.repository

/** The existing ClientDirectory phone lookup format. */
object ClientPhoneNumbers {
    fun lookupKey(phone: String): String = phone.replace(Regex("[^0-9]"), "").takeLast(10)

    /** Fail closed for short, extension-bearing or unsupported international numbers. */
    fun fromRussianContact(phone: String): String {
        if (!phone.matches(Regex("[+0-9()\\s\\p{Z}.\\-]+"))) return ""
        val digits = phone.replace(Regex("[^0-9]"), "")
        if (digits.length != 10 && !(digits.length == 11 && digits.first() in "78")) return ""
        return lookupKey(digits)
    }
}
