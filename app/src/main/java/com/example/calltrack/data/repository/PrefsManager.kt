package com.example.calltrack.data.repository

import android.content.Context
import androidx.datastore.preferences.core.booleanPreferencesKey
import androidx.datastore.preferences.core.edit
import androidx.datastore.preferences.preferencesDataStore
import kotlinx.coroutines.flow.Flow
import kotlinx.coroutines.flow.map

private val Context.dataStore by preferencesDataStore("settings")

class PrefsManager(private val context: Context) {
    private val onboardingKey = booleanPreferencesKey("onboarding_completed")

    val onboardingCompleted: Flow<Boolean> = context.dataStore.data.map { prefs ->
        prefs[onboardingKey] ?: false
    }

    suspend fun setOnboardingCompleted(value: Boolean) {
        context.dataStore.edit { it[onboardingKey] = value }
    }
}
