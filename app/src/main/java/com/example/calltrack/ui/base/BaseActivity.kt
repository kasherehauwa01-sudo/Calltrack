package com.example.calltrack.ui.base

import android.content.res.Configuration
import android.graphics.Color
import android.os.Bundle
import android.view.View
import androidx.appcompat.app.AppCompatActivity
import androidx.appcompat.app.AppCompatDelegate
import androidx.core.view.ViewCompat
import com.example.calltrack.data.repository.PrefsManager
import kotlinx.coroutines.runBlocking
import androidx.core.view.WindowCompat
import androidx.core.view.WindowInsetsCompat
import androidx.core.view.WindowInsetsControllerCompat

abstract class BaseActivity : AppCompatActivity() {
    private var mojibakeViewRepair: MojibakeViewRepair? = null

    override fun onCreate(savedInstanceState: Bundle?) {
        applyThemeMode()
        super.onCreate(savedInstanceState)
        WindowCompat.setDecorFitsSystemWindows(window, false)
        window.statusBarColor = Color.TRANSPARENT
        val isNightMode = (resources.configuration.uiMode and Configuration.UI_MODE_NIGHT_MASK) == Configuration.UI_MODE_NIGHT_YES
        WindowInsetsControllerCompat(window, window.decorView).isAppearanceLightStatusBars = !isNightMode
    }

    override fun onStart() {
        super.onStart()
        mojibakeViewRepair = MojibakeViewRepair(window.decorView).also { it.start() }
    }

    override fun onStop() {
        mojibakeViewRepair?.stop()
        mojibakeViewRepair = null
        super.onStop()
    }

    private fun applyThemeMode() {
        val mode = runBlocking { PrefsManager(applicationContext).getThemeMode() }
        val nightMode = when (mode) {
            PrefsManager.THEME_LIGHT -> AppCompatDelegate.MODE_NIGHT_NO
            PrefsManager.THEME_DARK -> AppCompatDelegate.MODE_NIGHT_YES
            else -> AppCompatDelegate.MODE_NIGHT_FOLLOW_SYSTEM
        }
        AppCompatDelegate.setDefaultNightMode(nightMode)
    }

    protected fun applyInsets(root: View, statusBarOverlay: View? = null) {
        ViewCompat.setOnApplyWindowInsetsListener(root) { v, insets ->
            val bars = insets.getInsets(WindowInsetsCompat.Type.systemBars())
            v.setPadding(v.paddingLeft, bars.top, v.paddingRight, bars.bottom)
            statusBarOverlay?.let { overlay ->
                overlay.layoutParams = overlay.layoutParams.apply {
                    height = bars.top
                }
            }
            insets
        }
    }
}
