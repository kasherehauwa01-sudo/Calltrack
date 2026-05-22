package com.example.calltrack.data.repository

import android.content.Context
import androidx.datastore.preferences.core.booleanPreferencesKey
import androidx.datastore.preferences.core.edit
import androidx.datastore.preferences.core.stringPreferencesKey
import androidx.datastore.preferences.preferencesDataStore
import kotlinx.coroutines.flow.Flow
import kotlinx.coroutines.flow.first
import kotlinx.coroutines.flow.map

private val Context.dataStore by preferencesDataStore("settings")

class PrefsManager(private val context: Context) {
    private val onboardingKey = booleanPreferencesKey("onboarding_completed")
    private val managerKey = stringPreferencesKey("manager_name")
    private val managerPhoneKey = stringPreferencesKey("manager_phone")
    private val themeKey = stringPreferencesKey(PREF_THEME)

    val onboardingCompleted: Flow<Boolean> = context.dataStore.data.map { prefs ->
        prefs[onboardingKey] ?: false
    }

    val managerName: Flow<String> = context.dataStore.data.map { prefs ->
        prefs[managerKey].orEmpty()
    }

    val managerPhone: Flow<String> = context.dataStore.data.map { prefs ->
        prefs[managerPhoneKey].orEmpty()
    }

    val themeMode: Flow<String> = context.dataStore.data.map { prefs ->
        prefs[themeKey] ?: THEME_LIGHT
    }

    suspend fun setOnboardingCompleted(value: Boolean) {
        context.dataStore.edit { it[onboardingKey] = value }
    }

    suspend fun setManagerName(value: String) {
        context.dataStore.edit { it[managerKey] = value }
    }

    suspend fun setManagerPhone(value: String) {
        context.dataStore.edit { it[managerPhoneKey] = value }
    }

    suspend fun setThemeMode(value: String) {
        context.dataStore.edit { it[themeKey] = value }
    }

    suspend fun getManagerName(): String = managerName.first()
    suspend fun getManagerPhone(): String = managerPhone.first()

    suspend fun getThemeMode(): String = themeMode.first()

    companion object {
        const val PREF_THEME = "app_theme"
        const val THEME_LIGHT = "light"
        const val THEME_DARK = "dark"
        const val THEME_SYSTEM = "system"
    }
}
