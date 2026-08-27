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
import java.util.concurrent.TimeUnit

object AppLogger {
    private const val TAG = "APP_LOG"
    private const val LOG_FILE = "app_logs.txt"
    private val retentionMs = TimeUnit.HOURS.toMillis(4)
    private val tsFormat = SimpleDateFormat("yyyy-MM-dd HH:mm:ss.SSS", Locale.getDefault())

    @Volatile
    private var installed = false
    private var lastPruneAt = 0L

    fun install(context: Context) {
        if (installed) return
        installed = true

        log(context, "INFO", "Приложение запущено")
        installCrashHandler(context)
        installNetworkLogging(context)
    }

    @Synchronized
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
            if (System.currentTimeMillis() - lastPruneAt >= PRUNE_INTERVAL_MS) {
                pruneOldEntries(file)
                lastPruneAt = System.currentTimeMillis()
            }
            trimIfTooLarge(file)
        }
        when (level) {
            "ERROR" -> Log.e(TAG, message, tr)
            "WARN" -> Log.w(TAG, message, tr)
            else -> Log.i(TAG, message, tr)
        }
    }

    @Synchronized
    fun readLogs(context: Context): String {
        val file = File(context.filesDir, LOG_FILE)
        if (!file.exists()) return "Логи пока отсутствуют"
        runCatching { pruneOldEntries(file) }
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

    private fun pruneOldEntries(file: File) {
        if (!file.exists()) return
        val lines = file.readLines()
        if (lines.isEmpty()) return

        val cutoff = System.currentTimeMillis() - retentionMs
        val kept = mutableListOf<String>()
        var currentEntry = mutableListOf<String>()
        var keepCurrent = false

        fun flush() {
            if (keepCurrent && currentEntry.isNotEmpty()) kept.addAll(currentEntry)
            currentEntry = mutableListOf()
            keepCurrent = false
        }

        lines.forEach { line ->
            val ts = parseEntryTimestamp(line)
            if (ts != null) {
                flush()
                keepCurrent = ts >= cutoff
            }
            currentEntry.add(line)
        }
        flush()

        val newText = kept.joinToString("\n").let { if (it.isBlank()) "" else "$it\n" }
        file.writeText(newText)
    }

    private fun parseEntryTimestamp(line: String): Long? {
        if (line.length < 23) return null
        val prefix = line.substring(0, 23)
        return runCatching { tsFormat.parse(prefix)?.time }.getOrNull()
    }

    private const val PRUNE_INTERVAL_MS = 60_000L
}
