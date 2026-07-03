package com.example.calltrack.ui.analytics

import android.graphics.Color
import android.graphics.Canvas
import android.graphics.Paint
import android.graphics.RectF
import android.graphics.Typeface
import android.graphics.drawable.GradientDrawable
import android.os.Bundle
import android.view.View
import android.widget.Button
import android.widget.EditText
import android.widget.HorizontalScrollView
import android.widget.LinearLayout
import android.widget.ScrollView
import android.widget.TextView
import androidx.appcompat.app.AlertDialog
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
    private lateinit var tabRow: LinearLayout
    private lateinit var periodRow: LinearLayout
    private lateinit var typeRow: LinearLayout
    private lateinit var content: LinearLayout
    private var allCalls: List<CallEntity> = emptyList()
    private var clientNames: Map<String, String> = emptyMap()
    private var activeTab: AnalyticsTab = AnalyticsTab.DASHBOARD
    private var activePeriod: AnalyticsPeriod = AnalyticsPeriod.WEEK
    private var activeDetail: AnalyticsDetail = AnalyticsDetail.DAY
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
            setPadding(dp(16), statusBarHeight() + dp(16), dp(16), dp(16))
            setBackgroundColor(getColor(R.color.background))
        }
        scroll.addView(root)
        setContentView(scroll)

        val header = LinearLayout(this).apply { orientation = LinearLayout.HORIZONTAL }
        header.addView(Button(this).apply {
            text = "←"
            textSize = 20f
            background = rounded(Color.WHITE, dp(16))
            setOnClickListener { finish() }
        }, LinearLayout.LayoutParams(dp(52), dp(44)))
        header.addView(TextView(this).apply {
            text = "Аналитика"
            textSize = 28f
            typeface = Typeface.DEFAULT_BOLD
            setTextColor(getColor(R.color.textPrimary))
            setPadding(dp(12), 0, 0, 0)
        }, LinearLayout.LayoutParams(0, LinearLayout.LayoutParams.WRAP_CONTENT, 1f))
        root.addView(header)

        tabRow = LinearLayout(this).apply {
            orientation = LinearLayout.HORIZONTAL
            background = rounded(Color.rgb(229, 234, 243), dp(22))
            setPadding(dp(4))
        }
        root.addView(tabRow, LinearLayout.LayoutParams(LinearLayout.LayoutParams.MATCH_PARENT, dp(54)).apply { setMargins(0, dp(14), 0, dp(10)) })
        renderTabs()

        periodRow = LinearLayout(this).apply { orientation = LinearLayout.HORIZONTAL }
        root.addView(HorizontalScrollView(this).apply { isHorizontalScrollBarEnabled = false; addView(periodRow) })
        typeRow = LinearLayout(this).apply { orientation = LinearLayout.HORIZONTAL }
        root.addView(HorizontalScrollView(this).apply { isHorizontalScrollBarEnabled = false; addView(typeRow) })
        content = LinearLayout(this).apply { orientation = LinearLayout.VERTICAL }
        root.addView(content)
        renderControls()
    }

    private fun renderTabs() {
        tabRow.removeAllViews()
        tabRow.addView(tabButton("Дашборд", AnalyticsTab.DASHBOARD), LinearLayout.LayoutParams(0, LinearLayout.LayoutParams.MATCH_PARENT, 1f).apply { setMargins(0, 0, dp(4), 0) })
        tabRow.addView(tabButton("Контакты", AnalyticsTab.CONTACTS), LinearLayout.LayoutParams(0, LinearLayout.LayoutParams.MATCH_PARENT, 1f).apply { setMargins(dp(4), 0, 0, 0) })
    }

    private fun tabButton(label: String, tab: AnalyticsTab) = Button(this).apply {
        text = label
        textSize = 16f
        typeface = if (tab == activeTab) Typeface.DEFAULT_BOLD else Typeface.DEFAULT
        background = rounded(if (tab == activeTab) Color.WHITE else Color.TRANSPARENT, dp(18))
        elevation = if (tab == activeTab) dp(2).toFloat() else 0f
        setTextColor(if (tab == activeTab) getColor(R.color.primary) else getColor(R.color.textSecondary))
        setOnClickListener {
            activeTab = tab
            renderTabs()
            renderControls()
            renderContent()
        }
    }

    private fun renderControls() {
        periodRow.removeAllViews()
        AnalyticsPeriod.entries.forEach { period ->
            periodRow.addView(Button(this).apply {
                text = period.title
                background = rounded(if (period == activePeriod) getColor(R.color.primary) else Color.WHITE, dp(18))
                setTextColor(if (period == activePeriod) Color.WHITE else getColor(R.color.textPrimary))
                setOnClickListener {
                    activePeriod = period
                    normalizeDetailForPeriod()
                    renderControls()
                    renderContent()
                }
            }, pillParams())
        }

        typeRow.removeAllViews()
        typeRow.visibility = if (activeTab == AnalyticsTab.DASHBOARD) View.VISIBLE else View.GONE
        if (activeTab == AnalyticsTab.DASHBOARD) {
            listOf("Входящий", "Исходящий", "Пропущенный", "Неотвеченный", "Сброшенный").forEach { type ->
                typeRow.addView(Button(this).apply {
                    text = type.replace("енный", ".")
                    background = rounded(if (activeTypes.contains(type)) typeColor(type) else Color.WHITE, dp(18))
                    setTextColor(if (activeTypes.contains(type)) Color.WHITE else getColor(R.color.textPrimary))
                    setOnClickListener {
                        if (activeTypes.contains(type)) activeTypes.remove(type) else activeTypes.add(type)
                        renderControls()
                        renderContent()
                    }
                }, pillParams())
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
        val filtered = allCalls.filter { call -> inPeriod(call.timestamp) && !isPersonalCall(call) }
        if (activeTab == AnalyticsTab.DASHBOARD) renderDashboard(filtered.filter { activeTypes.contains(it.type) }) else renderContacts(filtered)
    }

    private fun renderDashboard(calls: List<CallEntity>) {
        val totalDuration = calls.sumOf { it.duration }
        addCard("Всего звонков", calls.size.toString())
        addCard("Входящие", calls.count { it.type == "Входящий" }.toString())
        addCard("Исходящие", calls.count { it.type == "Исходящий" }.toString())
        addCard("Средняя длительность", formatDuration(if (calls.isEmpty()) 0 else totalDuration / calls.size))
        addDetailControls()
        addSectionTitle("Звонки по периодам")
        addLegend()
        val grouped = groupCallsByDetail(calls)
        val maxBucket = max(1, grouped.maxOfOrNull { it.second.size } ?: 1)
        grouped.forEach { (label, bucketCalls) -> addStackedBar(label, bucketCalls.groupingBy { it.type }.eachCount(), maxBucket) }
        addSectionTitle("Типы звонков")
        addPieChart(calls.groupingBy { it.type }.eachCount())
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
            addCard(client, "Звонков: ${rows.size} • Длительность: ${formatDuration(duration)}", onClick = { showClientHistory(client, rows) })
            rows.sortedByDescending { it.timestamp }.take(5).forEach { call ->
                addCallEvent(call)
            }
        }
    }

    private fun addCard(title: String, value: String, onClick: (() -> Unit)? = null) {
        content.addView(TextView(this).apply {
            text = "$title\n$value"
            textSize = 18f
            typeface = Typeface.DEFAULT_BOLD
            setTextColor(getColor(R.color.textPrimary))
            background = rounded(Color.WHITE, dp(22))
            elevation = dp(2).toFloat()
            setPadding(dp(16))
            if (onClick != null) {
                setOnClickListener { onClick() }
                text = "$title ↗\n$value"
            }
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

    private fun addDetailControls() {
        if (!activePeriod.hasDetail) return
        content.addView(TextView(this).apply {
            text = "Детализация графика"
            textSize = 14f
            setTextColor(getColor(R.color.textSecondary))
            setPadding(0, dp(12), 0, dp(4))
        })
        val row = LinearLayout(this).apply {
            orientation = LinearLayout.HORIZONTAL
            background = rounded(Color.WHITE, dp(20))
            setPadding(dp(6))
        }
        AnalyticsDetail.entries.forEach { detail ->
            row.addView(Button(this).apply {
                text = detail.title
                background = rounded(if (detail == activeDetail) getColor(R.color.primary) else Color.rgb(243, 246, 250), dp(16))
                setTextColor(if (detail == activeDetail) Color.WHITE else getColor(R.color.textPrimary))
                setOnClickListener {
                    activeDetail = detail
                    renderContent()
                }
            }, LinearLayout.LayoutParams(0, dp(42), 1f).apply { setMargins(dp(3), 0, dp(3), 0) })
        }
        content.addView(row, LinearLayout.LayoutParams(LinearLayout.LayoutParams.MATCH_PARENT, LinearLayout.LayoutParams.WRAP_CONTENT).apply { setMargins(0, dp(4), 0, dp(4)) })
    }

    private fun addBar(label: String, value: Int, maxValue: Int, color: Int = getColor(R.color.primary)) {
        val row = LinearLayout(this).apply { orientation = LinearLayout.VERTICAL }
        row.addView(TextView(this).apply { text = "$label — $value"; setTextColor(getColor(R.color.textPrimary)) })
        val barWidth = max(dp(12), ((resources.displayMetrics.widthPixels - dp(48)) * value / maxValue.toFloat()).toInt())
        row.addView(View(this).apply { background = rounded(color, dp(6)) }, LinearLayout.LayoutParams(barWidth, dp(12)))
        content.addView(row, LinearLayout.LayoutParams(LinearLayout.LayoutParams.MATCH_PARENT, LinearLayout.LayoutParams.WRAP_CONTENT).apply { setMargins(0, dp(4), 0, dp(8)) })
    }

    private fun addStackedBar(label: String, values: Map<String, Int>, maxValue: Int) {
        val total = values.values.sum()
        val row = LinearLayout(this).apply { orientation = LinearLayout.VERTICAL }
        row.addView(TextView(this).apply { text = "$label — $total"; setTextColor(getColor(R.color.textPrimary)) })
        val bar = LinearLayout(this).apply { orientation = LinearLayout.HORIZONTAL; background = rounded(Color.WHITE, dp(8)) }
        listOf("Входящий", "Исходящий", "Пропущенный", "Неотвеченный", "Сброшенный").forEach { type ->
            val count = values[type] ?: 0
            if (count > 0) bar.addView(View(this).apply { setBackgroundColor(typeColor(type)) }, LinearLayout.LayoutParams(0, dp(14), count.toFloat()))
        }
        val barWidth = max(dp(28), ((resources.displayMetrics.widthPixels - dp(48)) * total / maxValue.toFloat()).toInt())
        row.addView(bar, LinearLayout.LayoutParams(barWidth, dp(14)))
        row.addView(TextView(this).apply { text = values.entries.joinToString("  ") { "${shortType(it.key)}: ${it.value}" }; textSize = 12f; setTextColor(getColor(R.color.textSecondary)) })
        content.addView(row, LinearLayout.LayoutParams(LinearLayout.LayoutParams.MATCH_PARENT, LinearLayout.LayoutParams.WRAP_CONTENT).apply { setMargins(0, dp(6), 0, dp(10)) })
    }

    private fun addPieChart(values: Map<String, Int>) {
        val ordered = listOf("Входящий", "Исходящий", "Пропущенный", "Неотвеченный", "Сброшенный")
            .mapNotNull { type -> values[type]?.takeIf { it > 0 }?.let { type to it } }
        if (ordered.isEmpty()) {
            addText("Нет данных для круговой диаграммы")
            return
        }
        val total = ordered.sumOf { it.second }.toFloat()
        content.addView(object : View(this) {
            private val paint = Paint(Paint.ANTI_ALIAS_FLAG)
            private val textPaint = Paint(Paint.ANTI_ALIAS_FLAG).apply {
                color = getColor(R.color.textPrimary)
                textSize = dp(12).toFloat()
                typeface = Typeface.DEFAULT_BOLD
            }

            override fun onDraw(canvas: Canvas) {
                super.onDraw(canvas)
                val size = minOf(width, height) - dp(32)
                val left = (width - size) / 2f
                val oval = RectF(left, dp(8).toFloat(), left + size, dp(8).toFloat() + size)
                var startAngle = -90f
                ordered.forEach { (type, count) ->
                    val sweep = 360f * count / total
                    paint.color = typeColor(type)
                    canvas.drawArc(oval, startAngle, sweep, true, paint)
                    startAngle += sweep
                }
                paint.color = Color.WHITE
                canvas.drawCircle(width / 2f, dp(8) + size / 2f, size * 0.27f, paint)
                canvas.drawText("Всего ${total.toInt()}", width / 2f - dp(34), dp(8) + size / 2f + dp(5), textPaint)
            }
        }, LinearLayout.LayoutParams(LinearLayout.LayoutParams.MATCH_PARENT, dp(220)).apply { setMargins(0, dp(6), 0, dp(10)) })
        addPieLegend(ordered, total)
    }

    private fun addPieLegend(values: List<Pair<String, Int>>, total: Float) {
        val legend = LinearLayout(this).apply {
            orientation = LinearLayout.VERTICAL
            background = rounded(Color.WHITE, dp(16))
            setPadding(dp(12), dp(8), dp(12), dp(8))
        }
        values.forEach { (type, count) ->
            legend.addView(TextView(this).apply {
                text = "■ $type — $count (${(count * 100 / total).toInt()}%)"
                textSize = 13f
                setTextColor(typeColor(type))
                setPadding(0, dp(3), 0, dp(3))
            })
        }
        content.addView(legend, LinearLayout.LayoutParams(LinearLayout.LayoutParams.MATCH_PARENT, LinearLayout.LayoutParams.WRAP_CONTENT).apply { setMargins(0, 0, 0, dp(8)) })
    }

    private fun addLegend() {
        val legend = LinearLayout(this).apply { orientation = LinearLayout.HORIZONTAL }
        listOf("Входящий", "Исходящий", "Пропущенный", "Неотвеченный", "Сброшенный").forEach { type ->
            legend.addView(TextView(this).apply { text = "■ ${shortType(type)}"; textSize = 12f; setTextColor(typeColor(type)); setPadding(0, 0, dp(8), 0) })
        }
        content.addView(legend)
    }

    private fun addText(textValue: String) {
        content.addView(TextView(this).apply {
            text = textValue
            textSize = 14f
            setTextColor(getColor(R.color.textSecondary))
            setPadding(0, dp(4), 0, dp(4))
        })
    }

    private fun addCallEvent(call: CallEntity) {
        val hasDetails = call.note.isNotBlank() || call.reminder.isNotBlank()
        val row = LinearLayout(this).apply {
            orientation = LinearLayout.HORIZONTAL
            background = rounded(Color.WHITE, dp(14))
            setPadding(dp(12), dp(8), dp(12), dp(8))
            setOnClickListener { showCallEditor(call) }
        }
        row.addView(TextView(this).apply {
            text = "${dateFormat.format(Date(call.timestamp))} • ${call.type} • ${formatDuration(call.duration)} • ${call.phone}"
            textSize = 14f
            setTextColor(if (hasDetails) getColor(R.color.textPrimary) else getColor(R.color.textSecondary))
        }, LinearLayout.LayoutParams(0, LinearLayout.LayoutParams.WRAP_CONTENT, 1f))
        if (hasDetails) {
            row.addView(View(this).apply {
                background = rounded(Color.rgb(239, 68, 68), dp(5))
            }, LinearLayout.LayoutParams(dp(10), dp(10)).apply { setMargins(dp(8), dp(5), 0, 0) })
        }
        content.addView(row, LinearLayout.LayoutParams(LinearLayout.LayoutParams.MATCH_PARENT, LinearLayout.LayoutParams.WRAP_CONTENT).apply { setMargins(0, dp(4), 0, dp(4)) })
    }

    private fun showClientHistory(client: String, rows: List<CallEntity>) {
        val events = rows.filter { it.note.isNotBlank() || it.reminder.isNotBlank() }.sortedByDescending { it.timestamp }
        val message = if (events.isEmpty()) "Событий с комментариями или напоминаниями нет" else events.joinToString("\n\n") {
            "${dateFormat.format(Date(it.timestamp))} • ${it.type} • ${it.phone}\nКомментарий: ${it.note.ifBlank { "—" }}\nНапоминание: ${it.reminder.ifBlank { "—" }}"
        }
        AlertDialog.Builder(this).setTitle(client).setMessage(message).setPositiveButton("Закрыть", null).show()
    }

    private fun showCallEditor(call: CallEntity) {
        val box = LinearLayout(this).apply { orientation = LinearLayout.VERTICAL; setPadding(dp(20)) }
        val comment = EditText(this).apply { hint = "Комментарий"; setText(call.note) }
        val reminder = EditText(this).apply { hint = "Напоминание"; setText(call.reminder) }
        box.addView(comment)
        box.addView(reminder)
        AlertDialog.Builder(this)
            .setTitle("${dateFormat.format(Date(call.timestamp))} • ${call.phone}")
            .setView(box)
            .setNegativeButton("Отмена", null)
            .setPositiveButton("Сохранить") { _, _ ->
                lifecycleScope.launch {
                    val db = CallDatabase.getInstance(this@AnalyticsActivity)
                    withContext(Dispatchers.IO) { db.callDao().updateOutcome(call.id, comment.text.toString(), call.tag, reminder.text.toString()) }
                    loadData()
                }
            }
            .show()
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

    private fun isPersonalCall(call: CallEntity): Boolean = clientNames[call.phone]?.trim()?.equals("Личный звонок", ignoreCase = true) == true

    private fun normalizeDetailForPeriod() {
        if (!activePeriod.hasDetail) activeDetail = AnalyticsDetail.DAY
    }

    private fun groupCallsByDetail(calls: List<CallEntity>): List<Pair<String, List<CallEntity>>> {
        return calls.groupBy { bucketStart(it.timestamp) }
            .toSortedMap()
            .map { (start, rows) -> bucketLabel(start) to rows }
    }

    private fun bucketStart(timestamp: Long): Long {
        val calendar = Calendar.getInstance().apply { timeInMillis = timestamp }
        calendar.set(Calendar.HOUR_OF_DAY, 0)
        calendar.set(Calendar.MINUTE, 0)
        calendar.set(Calendar.SECOND, 0)
        calendar.set(Calendar.MILLISECOND, 0)
        when (activeDetail) {
            AnalyticsDetail.DAY -> Unit
            AnalyticsDetail.WEEK -> {
                calendar.firstDayOfWeek = Calendar.MONDAY
                calendar.set(Calendar.DAY_OF_WEEK, Calendar.MONDAY)
            }
            AnalyticsDetail.MONTH -> calendar.set(Calendar.DAY_OF_MONTH, 1)
        }
        return calendar.timeInMillis
    }

    private fun bucketLabel(timestamp: Long): String = when (activeDetail) {
        AnalyticsDetail.DAY -> dateFormat.format(Date(timestamp))
        AnalyticsDetail.WEEK -> "Неделя с ${dateFormat.format(Date(timestamp))}"
        AnalyticsDetail.MONTH -> SimpleDateFormat("MM.yyyy", Locale("ru")).format(Date(timestamp))
    }

    private fun pillParams(): LinearLayout.LayoutParams = LinearLayout.LayoutParams(LinearLayout.LayoutParams.WRAP_CONTENT, dp(42)).apply { setMargins(0, dp(6), dp(8), dp(6)) }

    private fun typeColor(type: String): Int = when (type) {
        "Входящий" -> Color.rgb(16, 185, 129)
        "Исходящий" -> Color.rgb(59, 130, 246)
        "Пропущенный" -> Color.rgb(245, 158, 11)
        "Неотвеченный" -> Color.rgb(239, 68, 68)
        "Сброшенный" -> Color.rgb(139, 92, 246)
        else -> getColor(R.color.primary)
    }

    private fun shortType(type: String): String = when (type) {
        "Входящий" -> "Вх"
        "Исходящий" -> "Исх"
        "Пропущенный" -> "Проп"
        "Неотвеченный" -> "Неотв"
        "Сброшенный" -> "Сбр"
        else -> type
    }

    private fun rounded(color: Int, radius: Int): GradientDrawable = GradientDrawable().apply { setColor(color); cornerRadius = radius.toFloat() }
    private fun statusBarHeight(): Int = resources.getIdentifier("status_bar_height", "dimen", "android").takeIf { it > 0 }?.let { resources.getDimensionPixelSize(it) } ?: 0
    private fun formatDuration(seconds: Long): String = "${seconds / 60}:${String.format(Locale.US, "%02d", seconds % 60)}"
    private fun dp(value: Int): Int = (value * resources.displayMetrics.density).toInt()
}

private enum class AnalyticsTab { DASHBOARD, CONTACTS }
private enum class AnalyticsPeriod(val title: String, val hasDetail: Boolean = false) { TODAY("Сегодня"), WEEK("Неделя", true), MONTH("Месяц", true), YEAR("Год", true), ALL("Все") }
private enum class AnalyticsDetail(val title: String) { DAY("Дни"), WEEK("Недели"), MONTH("Месяцы") }
