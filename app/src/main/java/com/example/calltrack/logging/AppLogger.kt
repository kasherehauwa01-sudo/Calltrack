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

        log(context, "INFO", "\u041F\u0440\u0438\u043B\u043E\u0436\u0435\u043D\u0438\u0435 \u0437\u0430\u043F\u0443\u0449\u0435\u043D\u043E")
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
        if (!file.exists()) return "\u041B\u043E\u0433\u0438 \u043F\u043E\u043A\u0430 \u043E\u0442\u0441\u0443\u0442\u0441\u0442\u0432\u0443\u044E\u0442"
        runCatching { pruneOldEntries(file) }
        return runCatching { file.readText() }.getOrElse { "\u041D\u0435 \u0443\u0434\u0430\u043B\u043E\u0441\u044C \u043F\u0440\u043E\u0447\u0438\u0442\u0430\u0442\u044C \u043B\u043E\u0433: ${it.message}" }
    }

    fun logFile(context: Context): File = File(context.filesDir, LOG_FILE)

    private fun installCrashHandler(context: Context) {
        val defaultHandler = Thread.getDefaultUncaughtExceptionHandler()
        Thread.setDefaultUncaughtExceptionHandler { thread, throwable ->
            log(context, "ERROR", "\u041A\u0440\u0438\u0442\u0438\u0447\u0435\u0441\u043A\u043E\u0435 \u043F\u0430\u0434\u0435\u043D\u0438\u0435 \u043F\u0440\u0438\u043B\u043E\u0436\u0435\u043D\u0438\u044F \u0432 \u043F\u043E\u0442\u043E\u043A\u0435 ${thread.name}", throwable)
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
                log(context, "INFO", "\u0421\u0435\u0442\u044C \u0434\u043E\u0441\u0442\u0443\u043F\u043D\u0430")
            }

            override fun onLost(network: Network) {
                log(context, "WARN", "\u041F\u043E\u0442\u0435\u0440\u044F\u043D\u043E \u0441\u0435\u0442\u0435\u0432\u043E\u0435 \u0441\u043E\u0435\u0434\u0438\u043D\u0435\u043D\u0438\u0435")
            }

            override fun onUnavailable() {
                log(context, "WARN", "\u0421\u0435\u0442\u044C \u043D\u0435\u0434\u043E\u0441\u0442\u0443\u043F\u043D\u0430")
            }
        })

        if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.N) {
            cm.registerDefaultNetworkCallback(object : ConnectivityManager.NetworkCallback() {
                override fun onLost(network: Network) {
                    log(context, "WARN", "\u041F\u043E\u0442\u0435\u0440\u044F\u043D\u043E \u043E\u0441\u043D\u043E\u0432\u043D\u043E\u0435 \u0441\u0435\u0442\u0435\u0432\u043E\u0435 \u0441\u043E\u0435\u0434\u0438\u043D\u0435\u043D\u0438\u0435")
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
