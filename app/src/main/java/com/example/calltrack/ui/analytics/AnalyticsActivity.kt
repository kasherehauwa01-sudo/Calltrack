package com.example.calltrack.ui.analytics

import android.graphics.Color
import android.os.Bundle
import android.view.View
import android.widget.Button
import android.widget.LinearLayout
import android.widget.ScrollView
import android.widget.TextView
import androidx.core.view.setPadding
import androidx.lifecycle.lifecycleScope
import com.example.calltrack.R
import com.example.calltrack.data.local.CallDatabase
import com.example.calltrack.data.local.CallEntity
import com.example.calltrack.ui.base.BaseActivity
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.launch
import kotlinx.coroutines.withContext
import java.text.SimpleDateFormat
import java.util.Calendar
import java.util.Date
import java.util.Locale
import kotlin.math.max

class AnalyticsActivity : BaseActivity() {
    private lateinit var root: LinearLayout
    private lateinit var periodRow: LinearLayout
    private lateinit var typeRow: LinearLayout
    private lateinit var content: LinearLayout
    private var allCalls: List<CallEntity> = emptyList()
    private var clientNames: Map<String, String> = emptyMap()
    private var activeTab: AnalyticsTab = AnalyticsTab.DASHBOARD
    private var activePeriod: AnalyticsPeriod = AnalyticsPeriod.WEEK
    private val activeTypes = mutableSetOf("Входящий", "Исходящий", "Пропущенный", "Неотвеченный", "Сброшенный")
    private val dateFormat = SimpleDateFormat("dd.MM.yyyy", Locale("ru"))

    override fun onCreate(savedInstanceState: Bundle?) {
        super.onCreate(savedInstanceState)
        buildLayout()
        loadData()
    }

    private fun buildLayout() {
        val scroll = ScrollView(this)
        root = LinearLayout(this).apply {
            orientation = LinearLayout.VERTICAL
            setPadding(dp(16))
            setBackgroundColor(getColor(R.color.background))
        }
        scroll.addView(root)
        setContentView(scroll)

        val header = LinearLayout(this).apply { orientation = LinearLayout.HORIZONTAL }
        header.addView(Button(this).apply {
            text = "←"
            setOnClickListener { finish() }
        }, LinearLayout.LayoutParams(dp(52), dp(44)))
        header.addView(TextView(this).apply {
            text = "Аналитика"
            textSize = 24f
            setTextColor(getColor(R.color.textPrimary))
            setPadding(dp(12), 0, 0, 0)
        }, LinearLayout.LayoutParams(0, LinearLayout.LayoutParams.WRAP_CONTENT, 1f))
        root.addView(header)

        val tabs = LinearLayout(this).apply { orientation = LinearLayout.HORIZONTAL }
        tabs.addView(tabButton("Дашборд", AnalyticsTab.DASHBOARD), LinearLayout.LayoutParams(0, dp(44), 1f))
        tabs.addView(tabButton("Контакты", AnalyticsTab.CONTACTS), LinearLayout.LayoutParams(0, dp(44), 1f))
        root.addView(tabs)

        periodRow = LinearLayout(this).apply { orientation = LinearLayout.HORIZONTAL }
        root.addView(periodRow)
        typeRow = LinearLayout(this).apply { orientation = LinearLayout.HORIZONTAL }
        root.addView(typeRow)
        content = LinearLayout(this).apply { orientation = LinearLayout.VERTICAL }
        root.addView(content)
        renderControls()
    }

    private fun tabButton(label: String, tab: AnalyticsTab) = Button(this).apply {
        text = label
        setOnClickListener {
            activeTab = tab
            renderControls()
            renderContent()
        }
    }

    private fun renderControls() {
        periodRow.removeAllViews()
        AnalyticsPeriod.entries.forEach { period ->
            periodRow.addView(Button(this).apply {
                text = period.title
                isSelected = period == activePeriod
                setOnClickListener {
                    activePeriod = period
                    renderContent()
                }
            }, LinearLayout.LayoutParams(0, dp(42), 1f))
        }

        typeRow.removeAllViews()
        typeRow.visibility = if (activeTab == AnalyticsTab.DASHBOARD) View.VISIBLE else View.GONE
        if (activeTab == AnalyticsTab.DASHBOARD) {
            listOf("Входящий", "Исходящий", "Пропущенный", "Неотвеченный", "Сброшенный").forEach { type ->
                typeRow.addView(Button(this).apply {
                    text = type.replace("енный", ".")
                    isSelected = activeTypes.contains(type)
                    setOnClickListener {
                        if (activeTypes.contains(type)) activeTypes.remove(type) else activeTypes.add(type)
                        renderControls()
                        renderContent()
                    }
                }, LinearLayout.LayoutParams(0, dp(42), 1f))
            }
        }
    }

    private fun loadData() {
        lifecycleScope.launch {
            val db = CallDatabase.getInstance(this@AnalyticsActivity)
            val result = withContext(Dispatchers.IO) {
                val calls = db.callDao().getAllOnce()
                val contacts = db.contactDao().findAll().associate { it.phone to (it.client1c.ifBlank { it.name }) }
                calls to contacts
            }
            allCalls = result.first
            clientNames = result.second
            renderContent()
        }
    }

    private fun renderContent() {
        content.removeAllViews()
        val filtered = allCalls.filter { call -> inPeriod(call.timestamp) }
        if (activeTab == AnalyticsTab.DASHBOARD) renderDashboard(filtered.filter { activeTypes.contains(it.type) }) else renderContacts(filtered)
    }

    private fun renderDashboard(calls: List<CallEntity>) {
        val totalDuration = calls.sumOf { it.duration }
        addCard("Всего звонков", calls.size.toString())
        addCard("Входящие", calls.count { it.type == "Входящий" }.toString())
        addCard("Исходящие", calls.count { it.type == "Исходящий" }.toString())
        addCard("Средняя длительность", formatDuration(if (calls.isEmpty()) 0 else totalDuration / calls.size))
        addSectionTitle("Звонки по дням")
        calls.groupBy { dateFormat.format(Date(it.timestamp)) }
            .toSortedMap(compareBy { parseDateKey(it) })
            .forEach { (day, dayCalls) -> addBar(day, dayCalls.size, max(1, calls.size)) }
        addSectionTitle("Типы звонков")
        calls.groupBy { it.type }.forEach { (type, rows) -> addBar(type, rows.size, max(1, calls.size)) }
    }

    private fun renderContacts(calls: List<CallEntity>) {
        addSectionTitle("Звонки клиента")
        val grouped = calls.groupBy { clientNames[it.phone] ?: it.phone }
            .toList()
            .sortedByDescending { (_, rows) -> rows.maxOfOrNull { it.timestamp } ?: 0L }
        if (grouped.isEmpty()) {
            addText("Нет звонков за выбранный период")
            return
        }
        grouped.forEach { (client, rows) ->
            val duration = rows.sumOf { it.duration }
            addCard(client, "Звонков: ${rows.size} • Длительность: ${formatDuration(duration)}")
            rows.sortedByDescending { it.timestamp }.take(5).forEach { call ->
                addText("${dateFormat.format(Date(call.timestamp))} • ${call.type} • ${formatDuration(call.duration)} • ${call.phone}")
            }
        }
    }

    private fun addCard(title: String, value: String) {
        content.addView(TextView(this).apply {
            text = "$title\n$value"
            textSize = 18f
            setTextColor(getColor(R.color.textPrimary))
            setBackgroundColor(getColor(R.color.surface))
            setPadding(dp(14))
        }, LinearLayout.LayoutParams(LinearLayout.LayoutParams.MATCH_PARENT, LinearLayout.LayoutParams.WRAP_CONTENT).apply { setMargins(0, dp(8), 0, dp(8)) })
    }

    private fun addSectionTitle(title: String) {
        content.addView(TextView(this).apply {
            text = title
            textSize = 20f
            setTextColor(getColor(R.color.textPrimary))
            setPadding(0, dp(14), 0, dp(8))
        })
    }

    private fun addBar(label: String, value: Int, maxValue: Int) {
        val row = LinearLayout(this).apply { orientation = LinearLayout.VERTICAL }
        row.addView(TextView(this).apply { text = "$label — $value"; setTextColor(getColor(R.color.textPrimary)) })
        val barWidth = max(dp(12), ((resources.displayMetrics.widthPixels - dp(48)) * value / maxValue.toFloat()).toInt())
        row.addView(View(this).apply { setBackgroundColor(getColor(R.color.primary)) }, LinearLayout.LayoutParams(barWidth, dp(10)))
        content.addView(row, LinearLayout.LayoutParams(LinearLayout.LayoutParams.MATCH_PARENT, LinearLayout.LayoutParams.WRAP_CONTENT).apply { setMargins(0, dp(4), 0, dp(8)) })
    }

    private fun addText(textValue: String) {
        content.addView(TextView(this).apply {
            text = textValue
            textSize = 14f
            setTextColor(getColor(R.color.textSecondary))
            setPadding(0, dp(4), 0, dp(4))
        })
    }

    private fun inPeriod(timestamp: Long): Boolean {
        if (activePeriod == AnalyticsPeriod.ALL) return true
        val from = Calendar.getInstance().apply {
            set(Calendar.HOUR_OF_DAY, 0); set(Calendar.MINUTE, 0); set(Calendar.SECOND, 0); set(Calendar.MILLISECOND, 0)
            when (activePeriod) {
                AnalyticsPeriod.TODAY -> Unit
                AnalyticsPeriod.WEEK -> add(Calendar.DAY_OF_YEAR, -6)
                AnalyticsPeriod.MONTH -> set(Calendar.DAY_OF_MONTH, 1)
                AnalyticsPeriod.YEAR -> set(Calendar.DAY_OF_YEAR, 1)
                AnalyticsPeriod.ALL -> Unit
            }
        }.timeInMillis
        return timestamp >= from
    }

    private fun formatDuration(seconds: Long): String = "${seconds / 60}:${String.format(Locale.US, "%02d", seconds % 60)}"
    private fun parseDateKey(value: String): Long = runCatching { dateFormat.parse(value)?.time ?: 0L }.getOrDefault(0L)
    private fun dp(value: Int): Int = (value * resources.displayMetrics.density).toInt()
}

private enum class AnalyticsTab { DASHBOARD, CONTACTS }
private enum class AnalyticsPeriod(val title: String) { TODAY("Сегодня"), WEEK("Неделя"), MONTH("Месяц"), YEAR("Год"), ALL("Все") }
