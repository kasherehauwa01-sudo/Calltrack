package com.example.calltrack.logging

import android.content.Context
import android.net.ConnectivityManager
import android.net.Network
import android.net.NetworkCapabilities
import android.net.NetworkRequest
import android.os.Build
import android.util.Log
import java.io.File
import java.text.SimpleDateFormat
import java.util.Date
import java.util.Locale

object AppLogger {
    private const val TAG = "APP_LOG"
    private const val LOG_FILE = "app_logs.txt"
    private val tsFormat = SimpleDateFormat("yyyy-MM-dd HH:mm:ss.SSS", Locale.getDefault())

    @Volatile
    private var installed = false

    fun install(context: Context) {
        if (installed) return
        installed = true

        log(context, "INFO", "Приложение запущено")
        installCrashHandler(context)
        installNetworkLogging(context)
    }

    fun log(context: Context, level: String, message: String, tr: Throwable? = null) {
        val ts = tsFormat.format(Date())
        val line = buildString {
            append(ts).append(" [").append(level).append("] ").append(message)
            if (tr != null) append("\n").append(Log.getStackTraceString(tr))
            append("\n")
        }
        runCatching {
            val file = File(context.filesDir, LOG_FILE)
            file.appendText(line)
            trimIfTooLarge(file)
        }
        when (level) {
            "ERROR" -> Log.e(TAG, message, tr)
            "WARN" -> Log.w(TAG, message, tr)
            else -> Log.i(TAG, message, tr)
        }
    }

    fun readLogs(context: Context): String {
        val file = File(context.filesDir, LOG_FILE)
        if (!file.exists()) return "Логи пока отсутствуют"
        return runCatching { file.readText() }.getOrElse { "Не удалось прочитать лог: ${it.message}" }
    }

    fun logFile(context: Context): File = File(context.filesDir, LOG_FILE)

    private fun installCrashHandler(context: Context) {
        val defaultHandler = Thread.getDefaultUncaughtExceptionHandler()
        Thread.setDefaultUncaughtExceptionHandler { thread, throwable ->
            log(context, "ERROR", "Критическое падение приложения в потоке ${thread.name}", throwable)
            defaultHandler?.uncaughtException(thread, throwable)
        }
    }

    private fun installNetworkLogging(context: Context) {
        val cm = context.getSystemService(Context.CONNECTIVITY_SERVICE) as ConnectivityManager
        val request = NetworkRequest.Builder()
            .addCapability(NetworkCapabilities.NET_CAPABILITY_INTERNET)
            .build()
        cm.registerNetworkCallback(request, object : ConnectivityManager.NetworkCallback() {
            override fun onAvailable(network: Network) {
                log(context, "INFO", "Сеть доступна")
            }

            override fun onLost(network: Network) {
                log(context, "WARN", "Потеряно сетевое соединение")
            }

            override fun onUnavailable() {
                log(context, "WARN", "Сеть недоступна")
            }
        })

        if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.N) {
            cm.registerDefaultNetworkCallback(object : ConnectivityManager.NetworkCallback() {
                override fun onLost(network: Network) {
                    log(context, "WARN", "Потеряно основное сетевое соединение")
                }
            })
        }
    }

    private fun trimIfTooLarge(file: File) {
        val maxBytes = 1_500_000
        if (file.length() <= maxBytes) return
        val text = file.readText()
        val keep = text.takeLast(maxBytes / 2)
        file.writeText(keep)
    }
}
