package com.example.calltrack.permissions

import android.content.Context

class PermissionOnboardingStore(context: Context) {
    private val preferences = context.getSharedPreferences(PREFERENCES_NAME, Context.MODE_PRIVATE)

    var completed: Boolean
        get() = preferences.getBoolean(KEY_COMPLETED, false)
        set(value) = preferences.edit().putBoolean(KEY_COMPLETED, value).apply()

    companion object {
        const val KEY_COMPLETED = "permissions_onboarding_completed"
        private const val PREFERENCES_NAME = "permissions_onboarding"
    }
}
