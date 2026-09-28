package com.example.calltrack.ui.analytics

import androidx.activity.addCallback

import android.graphics.Color
import android.graphics.Canvas
import android.graphics.Paint
import android.graphics.RectF
import android.graphics.Typeface
import android.graphics.drawable.GradientDrawable
import android.content.Intent
import android.os.Bundle
import android.view.View
import android.widget.Button
import android.widget.EditText
import android.widget.HorizontalScrollView
import android.widget.ImageButton
import android.widget.LinearLayout
import android.widget.ScrollView
import android.widget.TextView
import android.widget.PopupMenu
import androidx.appcompat.app.AlertDialog
import androidx.core.view.setPadding
import androidx.lifecycle.lifecycleScope
import com.example.calltrack.R
import com.example.calltrack.BuildConfig
import com.example.calltrack.auth.AuthStore
import com.example.calltrack.data.local.CallDatabase
import com.example.calltrack.data.local.CallEntity
import com.example.calltrack.ui.base.BaseActivity
import com.example.calltrack.ui.base.TextEncoding
import com.example.calltrack.ui.base.installMojibakeRepair
import com.example.calltrack.ui.main.AboutActivity
import com.example.calltrack.ui.main.MainActivity
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.launch
import kotlinx.coroutines.withContext
import okhttp3.OkHttpClient
import okhttp3.Request
import org.json.JSONObject
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
    private var managerJournalCalls: List<CallEntity> = emptyList()
    private var managerEmails: List<TimelineEmail> = emptyList()
    private var managerSales: List<TimelineSale> = emptyList()
    private var managerSalesAvailable = true
    private var clientNames: Map<String, String> = emptyMap()
    private var managerJournalError: String? = null
    private val httpClient = OkHttpClient()
    private var activeTab: AnalyticsTab = AnalyticsTab.DASHBOARD
    private var activePeriod: AnalyticsPeriod = AnalyticsPeriod.MONTH
    private var activeDetail: AnalyticsDetail = AnalyticsDetail.DAY
    private val activeTypes = mutableSetOf("\u0412\u0445\u043E\u0434\u044F\u0449\u0438\u0439", "\u0418\u0441\u0445\u043E\u0434\u044F\u0449\u0438\u0439", "\u041F\u0440\u043E\u043F\u0443\u0449\u0435\u043D\u043D\u044B\u0439", "\u041D\u0435\u043E\u0442\u0432\u0435\u0447\u0435\u043D\u043D\u044B\u0439", "\u0421\u0431\u0440\u043E\u0448\u0435\u043D\u043D\u044B\u0439")
    private val dateFormat = SimpleDateFormat("dd.MM.yyyy", Locale("ru"))

    override fun onCreate(savedInstanceState: Bundle?) {
        super.onCreate(savedInstanceState)
        buildLayout()
        onBackPressedDispatcher.addCallback(this) { openMain(MainActivity.EXTRA_OPEN_DIAL) }
        loadData()
    }

    private fun buildLayout() {
        val scroll = ScrollView(this).apply { isFillViewport = true }
        root = LinearLayout(this).apply {
            orientation = LinearLayout.VERTICAL
            setPadding(dp(16), statusBarHeight() + dp(16), dp(16), dp(16))
            setBackgroundResource(R.drawable.bg_app_gradient)
        }
        scroll.addView(root)
        setContentView(scroll)

        val compactHeader = resources.configuration.screenWidthDp < 380
        val header = LinearLayout(this).apply {
            orientation = LinearLayout.HORIZONTAL
            gravity = android.view.Gravity.CENTER_VERTICAL
        }
        header.addView(topActionButton(R.drawable.ic_arrow_back, getString(R.string.back)) {
            finish()
        })
        header.addView(TextView(this).apply {
            text = "\u0410\u043D\u0430\u043B\u0438\u0442\u0438\u043A\u0430"
            textSize = if (compactHeader) 18f else 24f
            typeface = Typeface.DEFAULT_BOLD
            setTextColor(getColor(R.color.white))
            setPadding(dp(12), 0, 0, 0)
        }, LinearLayout.LayoutParams(0, LinearLayout.LayoutParams.WRAP_CONTENT, 1f))
        header.addView(topActionButton(R.drawable.ic_analytics, "\u0410\u043D\u0430\u043B\u0438\u0442\u0438\u043A\u0430") { })
        header.addView(topActionButton(R.drawable.ic_notifications, "\u0423\u0432\u0435\u0434\u043E\u043C\u043B\u0435\u043D\u0438\u044F") {
            openMain(MainActivity.EXTRA_OPEN_NOTIFICATIONS)
        })
        header.addView(topActionButton(R.drawable.ic_more_vert, "\u041C\u0435\u043D\u044E") { anchor ->
            PopupMenu(this, anchor).apply {
                menu.add(0, 1, 0, getString(R.string.about_app))
                menu.add(0, 2, 1, getString(R.string.settings))
                menu.add(0, 3, 2, getString(R.string.user))
                setOnMenuItemClickListener { item ->
                    when (item.itemId) {
                        1 -> { startActivity(Intent(this@AnalyticsActivity, AboutActivity::class.java)); finish() }
                        2 -> openMain(MainActivity.EXTRA_OPEN_SETTINGS)
                        3 -> openMain(MainActivity.EXTRA_OPEN_USER)
                    }
                    true
                }
                show()
            }
        })
        root.addView(header)

        tabRow = LinearLayout(this).apply {
            orientation = LinearLayout.HORIZONTAL
            background = rounded(getColor(R.color.surfaceVariant), dp(22))
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

    private fun topActionButton(icon: Int, description: String, action: (View) -> Unit): ImageButton =
        ImageButton(this).apply {
            setImageResource(icon)
            contentDescription = description
            setColorFilter(getColor(R.color.textPrimary))
            setPadding(dp(8))
            background = rounded(getColor(R.color.surface), dp(12))
            setOnClickListener { view -> action(view) }
            layoutParams = LinearLayout.LayoutParams(dp(40), dp(40)).apply {
                marginEnd = dp(if (resources.configuration.screenWidthDp < 380) 4 else 8)
            }
        }

    private fun openMain(destinationExtra: String) {
        startActivity(
            Intent(this, MainActivity::class.java)
                .addFlags(Intent.FLAG_ACTIVITY_CLEAR_TOP or Intent.FLAG_ACTIVITY_SINGLE_TOP)
                .putExtra(destinationExtra, true)
        )
        finish()
    }

    private fun renderTabs() {
        tabRow.removeAllViews()
        tabRow.addView(tabButton("\u0414\u0430\u0448\u0431\u043E\u0440\u0434", AnalyticsTab.DASHBOARD), LinearLayout.LayoutParams(0, LinearLayout.LayoutParams.MATCH_PARENT, 1f).apply { setMargins(0, 0, dp(4), 0) })
        tabRow.addView(tabButton("\u041A\u043E\u043D\u0442\u0430\u043A\u0442\u044B", AnalyticsTab.CONTACTS), LinearLayout.LayoutParams(0, LinearLayout.LayoutParams.MATCH_PARENT, 1f).apply { setMargins(dp(4), 0, 0, 0) })
    }

    private fun tabButton(label: String, tab: AnalyticsTab) = Button(this).apply {
        text = label
        textSize = 16f
        typeface = if (tab == activeTab) Typeface.DEFAULT_BOLD else Typeface.DEFAULT
        background = rounded(if (tab == activeTab) getColor(R.color.surface) else Color.TRANSPARENT, dp(18))
        elevation = if (tab == activeTab) dp(2).toFloat() else 0f
        setTextColor(if (tab == activeTab) getColor(R.color.primary) else getColor(R.color.textSecondary))
        setOnClickListener {
            activeTab = tab
            renderTabs()
            renderControls()
            if (tab == AnalyticsTab.CONTACTS) loadContactTimeline() else renderContent()
        }
    }

    private fun renderControls() {
        periodRow.removeAllViews()
        AnalyticsPeriod.entries.forEach { period ->
            periodRow.addView(Button(this).apply {
                text = period.title
                background = rounded(if (period == activePeriod) getColor(R.color.primary) else getColor(R.color.surface), dp(18))
                setTextColor(if (period == activePeriod) Color.WHITE else getColor(R.color.textPrimary))
                setOnClickListener {
                    activePeriod = period
                    normalizeDetailForPeriod()
                    renderControls()
                    if (activeTab == AnalyticsTab.CONTACTS) loadContactTimeline() else renderContent()
                }
            }, pillParams())
        }

        typeRow.removeAllViews()
        typeRow.visibility = if (activeTab == AnalyticsTab.DASHBOARD) View.VISIBLE else View.GONE
        if (activeTab == AnalyticsTab.DASHBOARD) {
            listOf("\u0412\u0445\u043E\u0434\u044F\u0449\u0438\u0439", "\u0418\u0441\u0445\u043E\u0434\u044F\u0449\u0438\u0439", "\u041F\u0440\u043E\u043F\u0443\u0449\u0435\u043D\u043D\u044B\u0439", "\u041D\u0435\u043E\u0442\u0432\u0435\u0447\u0435\u043D\u043D\u044B\u0439", "\u0421\u0431\u0440\u043E\u0448\u0435\u043D\u043D\u044B\u0439").forEach { type ->
                typeRow.addView(Button(this).apply {
                    text = type.replace("\u0435\u043D\u043D\u044B\u0439", ".")
                    background = rounded(if (activeTypes.contains(type)) typeColor(type) else getColor(R.color.surface), dp(18))
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
                val calls = db.callDao().getAllOnce().map { call ->
                    call.copy(
                        type = repairText(call.type),
                        note = repairText(call.note),
                        tag = repairText(call.tag),
                        reminder = repairText(call.reminder)
                    )
                }
                val contacts = db.contactDao().findAll().associate {
                    it.phone to repairText(it.client1c.ifBlank { it.name })
                }
                val journal = runCatching { loadManagerJournal() }
                Triple(calls, contacts, journal)
            }
            allCalls = result.first
            val journal = result.third
            managerJournalCalls = journal.getOrDefault(emptyList())
            managerJournalError = journal.exceptionOrNull()?.let { "\u041D\u0435 \u0443\u0434\u0430\u043B\u043E\u0441\u044C \u0437\u0430\u0433\u0440\u0443\u0437\u0438\u0442\u044C \u0436\u0443\u0440\u043D\u0430\u043B \u0437\u0432\u043E\u043D\u043A\u043E\u0432. \u041F\u0440\u043E\u0432\u0435\u0440\u044C\u0442\u0435 \u043F\u043E\u0434\u043A\u043B\u044E\u0447\u0435\u043D\u0438\u0435 \u043A \u0438\u043D\u0442\u0435\u0440\u043D\u0435\u0442\u0443." }
            clientNames = result.second + managerJournalCalls.associate { it.phone to it.tag.ifBlank { it.phone } }
            renderContent()
        }
    }

    private fun loadContactTimeline() {
        content.removeAllViews()
        addText("\u0417\u0430\u0433\u0440\u0443\u0437\u043A\u0430 \u0437\u0432\u043E\u043D\u043A\u043E\u0432 \u043A\u043B\u0438\u0435\u043D\u0442\u0430\u043C...")
        lifecycleScope.launch {
            val result = withContext(Dispatchers.IO) { runCatching { requestContactTimeline() } }
            result.onSuccess { timeline ->
                managerJournalCalls = timeline.calls
                managerEmails = timeline.emails
                managerSales = timeline.sales
                managerSalesAvailable = timeline.salesAvailable
                clientNames = clientNames + timeline.calls.associate { it.phone to it.tag.ifBlank { it.phone } }
                managerJournalError = null
            }.onFailure {
                managerJournalCalls = emptyList()
                managerEmails = emptyList()
                managerSales = emptyList()
                managerSalesAvailable = false
                managerJournalError = "\u041D\u0435 \u0443\u0434\u0430\u043B\u043E\u0441\u044C \u0437\u0430\u0433\u0440\u0443\u0437\u0438\u0442\u044C \u0437\u0432\u043E\u043D\u043A\u0438 \u043A\u043B\u0438\u0435\u043D\u0442\u0430\u043C. \u041F\u0440\u043E\u0432\u0435\u0440\u044C\u0442\u0435 \u043F\u043E\u0434\u043A\u043B\u044E\u0447\u0435\u043D\u0438\u0435 \u043A \u0438\u043D\u0442\u0435\u0440\u043D\u0435\u0442\u0443."
            }
            renderContent()
        }
    }

    private fun loadContactTimeline() {
        content.removeAllViews()
        addText("\u0417\u0430\u0433\u0440\u0443\u0437\u043A\u0430 \u0437\u0432\u043E\u043D\u043A\u043E\u0432 \u043A\u043B\u0438\u0435\u043D\u0442\u0430\u043C...")
        lifecycleScope.launch {
            val result = withContext(Dispatchers.IO) {
                runCatching { requestContactTimeline() }
                    .recoverCatching { requestCallsFallback() }
            }
            result.onSuccess { timeline ->
                managerJournalCalls = timeline.calls
                managerEmails = timeline.emails
                managerSales = timeline.sales
                managerSalesAvailable = timeline.salesAvailable
                clientNames = clientNames + timeline.calls.associate { it.phone to it.tag.ifBlank { it.phone } }
                managerJournalError = null
            }.onFailure {
                managerJournalCalls = emptyList()
                managerEmails = emptyList()
                managerSales = emptyList()
                managerSalesAvailable = false
                managerJournalError = "\u041D\u0435 \u0443\u0434\u0430\u043B\u043E\u0441\u044C \u0437\u0430\u0433\u0440\u0443\u0437\u0438\u0442\u044C \u0437\u0432\u043E\u043D\u043A\u0438 \u043A\u043B\u0438\u0435\u043D\u0442\u0430\u043C. \u041F\u0440\u043E\u0432\u0435\u0440\u044C\u0442\u0435 \u043F\u043E\u0434\u043A\u043B\u044E\u0447\u0435\u043D\u0438\u0435 \u043A \u0438\u043D\u0442\u0435\u0440\u043D\u0435\u0442\u0443."
            }
            renderContent()
        }
    }

    private fun requestContactTimeline(): ContactTimeline {
        val token = AuthStore(this).token
        check(token.isNotBlank()) { "Android session is missing" }
        val (from, to) = activePeriod.dateRange()
        val url = BuildConfig.SQL_API_BASE_URL.trimEnd('/') +
            "/android_client_timeline.php?date_from=$from&date_to=$to"
        val request = Request.Builder().url(url).header("Authorization", "Bearer $token").build()
        httpClient.newCall(request).execute().use { response ->
            val payload = JSONObject(response.body?.string().orEmpty())
            if (!response.isSuccessful || payload.optString("status") != "success") {
                error(payload.optString("message", "HTTP ${response.code}"))
            }
            val data = payload.getJSONObject("data")
            val calls = parseTimelineCalls(data.optJSONArray("calls"))
            val emailsJson = data.optJSONArray("emails")
            val emails = buildList {
                if (emailsJson == null) return@buildList
                for (index in 0 until emailsJson.length()) emailsJson.optJSONObject(index)?.let { row ->
                    add(TimelineEmail(row.optString("client_name").ifBlank { row.optString("client_email") }, row.optString("subject"), parseServerTimestamp(row.optString("sent_at"))))
                }
            }
            val salesJson = data.optJSONArray("sales")
            val sales = buildList {
                if (salesJson == null) return@buildList
                for (index in 0 until salesJson.length()) salesJson.optJSONObject(index)?.let { row ->
                    add(TimelineSale(row.optString("client"), row.optString("phone"), row.optString("total_amount"), parseServerTimestamp(row.optString("sale_date").take(10) + " 00:00:00")))
                }
            }
            return ContactTimeline(calls, emails, sales, data.optBoolean("sales_available", true))
        }
    }

    private fun requestCallsFallback(): ContactTimeline {
        val token = AuthStore(this).token
        val (from, to) = activePeriod.dateRange()
        val url = BuildConfig.SQL_API_BASE_URL.trimEnd('/') +
            "/get_calls.php?date_from=$from&date_to=$to&limit=0"
        val request = Request.Builder().url(url).header("Authorization", "Bearer $token").build()
        httpClient.newCall(request).execute().use { response ->
            val payload = JSONObject(response.body?.string().orEmpty())
            if (!response.isSuccessful || payload.optString("status") != "success") {
                error(payload.optString("message", "HTTP ${response.code}"))
            }
            return ContactTimeline(parseTimelineCalls(payload.optJSONArray("data")), emptyList(), emptyList(), false)
        }
    }

    private fun parseTimelineCalls(rows: org.json.JSONArray?): List<CallEntity> = buildList {
        if (rows == null) return@buildList
        for (index in 0 until rows.length()) {
            val row = rows.optJSONObject(index) ?: continue
            val timestamp = runCatching {
                SimpleDateFormat("yyyy-MM-dd HH:mm:ss", Locale.US).parse(
                    "${row.optString("call_date")} ${row.optString("call_time")}"
                )?.time
            }.getOrNull() ?: continue
            add(
                CallEntity(
                    id = row.optLong("id_db"),
                    phone = row.optString("phone"),
                    type = repairText(row.optString("call_type")),
                    duration = parseDurationSeconds(row.optString("duration")),
                    note = repairText(row.optString("comment")),
                    tag = repairText(row.optString("client")),
                    reminder = repairText(row.optString("reminder_text")),
                    timestamp = timestamp,
                    uploaded = true
                )
            )
        }
    }

    private fun parseServerTimestamp(value: String): Long = runCatching {
        SimpleDateFormat("yyyy-MM-dd HH:mm:ss", Locale.US).parse(value)?.time ?: 0L
    }.getOrDefault(0L)

    private fun parseDurationSeconds(value: String): Long {
        value.trim().toLongOrNull()?.let { return it }
        val parts = value.split(':').mapNotNull(String::toLongOrNull)
        return when (parts.size) {
            3 -> parts[0] * 3600 + parts[1] * 60 + parts[2]
            2 -> parts[0] * 60 + parts[1]
            else -> 0
        }
    }

    private fun renderContent() {
        content.removeAllViews()
        if (activeTab == AnalyticsTab.CONTACTS && managerJournalError != null) {
            addText(managerJournalError.orEmpty())
            return
        }
        val source = if (activeTab == AnalyticsTab.CONTACTS) managerJournalCalls else allCalls
        val filtered = source.filter { call -> inPeriod(call.timestamp) && !isPersonalCall(call) }
        if (activeTab == AnalyticsTab.DASHBOARD) renderDashboard(filtered.filter { activeTypes.contains(it.type) }) else renderContacts(filtered)
    }

    private fun renderDashboard(calls: List<CallEntity>) {
        val totalDuration = calls.sumOf { it.duration }
        addCard("\u0412\u0441\u0435\u0433\u043E \u0437\u0432\u043E\u043D\u043A\u043E\u0432", calls.size.toString())
        addCard("\u0412\u0445\u043E\u0434\u044F\u0449\u0438\u0435", calls.count { it.type == "\u0412\u0445\u043E\u0434\u044F\u0449\u0438\u0439" }.toString())
        addCard("\u0418\u0441\u0445\u043E\u0434\u044F\u0449\u0438\u0435", calls.count { it.type == "\u0418\u0441\u0445\u043E\u0434\u044F\u0449\u0438\u0439" }.toString())
        addCard("\u0421\u0440\u0435\u0434\u043D\u044F\u044F \u0434\u043B\u0438\u0442\u0435\u043B\u044C\u043D\u043E\u0441\u0442\u044C", formatDuration(if (calls.isEmpty()) 0 else totalDuration / calls.size))
        addDetailControls()
        addSectionTitle("\u0417\u0432\u043E\u043D\u043A\u0438 \u043F\u043E \u043F\u0435\u0440\u0438\u043E\u0434\u0430\u043C")
        addLegend()
        val grouped = groupCallsByDetail(calls)
        val maxBucket = max(1, grouped.maxOfOrNull { it.second.size } ?: 1)
        grouped.forEach { (label, bucketCalls) -> addStackedBar(label, bucketCalls.groupingBy { it.type }.eachCount(), maxBucket) }
        addSectionTitle("\u0422\u0438\u043F\u044B \u0437\u0432\u043E\u043D\u043A\u043E\u0432")
        addPieChart(calls.groupingBy { it.type }.eachCount())
    }

    private fun renderContacts(calls: List<CallEntity>) {
        addSectionTitle("\u0417\u0432\u043E\u043D\u043A\u0438 \u043A\u043B\u0438\u0435\u043D\u0442\u0430")
        if (!managerSalesAvailable) addText("\u0414\u0430\u043D\u043D\u044B\u0435 \u043E \u043F\u0440\u043E\u0434\u0430\u0436\u0430\u0445 \u0432\u0440\u0435\u043C\u0435\u043D\u043D\u043E \u043D\u0435\u0434\u043E\u0441\u0442\u0443\u043F\u043D\u044B")
        val clientCalls = calls.filter {
            it.duration >= 10 && (it.type == "\u0412\u0445\u043E\u0434\u044F\u0449\u0438\u0439" || it.type == "\u0418\u0441\u0445\u043E\u0434\u044F\u0449\u0438\u0439")
        }
        val clientKeys = (clientCalls.map { timelineClientKey(clientNames[it.phone].orEmpty(), it.phone) } +
            managerEmails.map { timelineClientKey(it.client, "") } +
            managerSales.map { timelineClientKey(it.client, it.phone) })
            .filter { it.isNotBlank() }.distinct()
            .sortedByDescending { key ->
                maxOf(
                    clientCalls.filter { timelineClientKey(clientNames[it.phone].orEmpty(), it.phone) == key }.maxOfOrNull { it.timestamp } ?: 0L,
                    managerEmails.filter { timelineClientKey(it.client, "") == key }.maxOfOrNull { it.timestamp } ?: 0L,
                    managerSales.filter { timelineClientKey(it.client, it.phone) == key }.maxOfOrNull { it.timestamp } ?: 0L
                )
            }
        if (clientKeys.isEmpty()) {
            addText("\u041D\u0435\u0442 \u0437\u0432\u043E\u043D\u043A\u043E\u0432 \u0437\u0430 \u0432\u044B\u0431\u0440\u0430\u043D\u043D\u044B\u0439 \u043F\u0435\u0440\u0438\u043E\u0434")
            return
        }
        clientKeys.forEach { key ->
            val rows = clientCalls.filter { timelineClientKey(clientNames[it.phone].orEmpty(), it.phone) == key }
            val emails = managerEmails.filter { timelineClientKey(it.client, "") == key }
            val sales = managerSales.filter { timelineClientKey(it.client, it.phone) == key }
            val client = rows.firstNotNullOfOrNull { clientNames[it.phone]?.takeIf(String::isNotBlank) }
                ?: emails.firstOrNull()?.client?.takeIf(String::isNotBlank)
                ?: sales.firstOrNull()?.client?.takeIf(String::isNotBlank)
                ?: sales.firstOrNull()?.phone?.takeIf(String::isNotBlank)
                ?: rows.firstOrNull()?.phone.orEmpty()
            val duration = rows.sumOf { it.duration }
            val card = LinearLayout(this).apply {
                orientation = LinearLayout.VERTICAL
                background = rounded(getColor(R.color.surface), dp(22))
                elevation = dp(2).toFloat()
                setPadding(dp(16))
            }
            card.addView(TextView(this).apply {
                text = client
                textSize = 17f
                typeface = Typeface.DEFAULT_BOLD
                setTextColor(getColor(R.color.textPrimary))
            })
            card.addView(TextView(this).apply {
                text = "\u0417\u0432\u043E\u043D\u043A\u043E\u0432: ${rows.size} \u2022 Email: ${emails.size} \u2022 \u041F\u0440\u043E\u0434\u0430\u0436: ${sales.size} \u2022 ${formatDuration(duration)}"
                textSize = 13f
                setTextColor(getColor(R.color.textSecondary))
            })
            val timeline = LinearLayout(this).apply { orientation = LinearLayout.HORIZONTAL }
            val events = rows.map { TimelineUiEvent(it.timestamp, callEventLabel(it), typeColor(it.type)) } +
                emails.map { TimelineUiEvent(it.timestamp, "Email\n${it.subject.ifBlank { "\u041E\u0442\u043F\u0440\u0430\u0432\u043B\u0435\u043D\u043E" }}", Color.rgb(245, 158, 11)) } +
                sales.map { TimelineUiEvent(it.timestamp, "\u041F\u0440\u043E\u0434\u0430\u0436\u0430\n${it.amount} \u20BD", Color.rgb(139, 92, 246)) }
            events.sortedBy { it.timestamp }.forEach { event ->
                timeline.addView(TextView(this).apply {
                    text = "${event.label}\n${dateFormat.format(Date(event.timestamp))}"
                    textSize = 13f
                    typeface = Typeface.DEFAULT_BOLD
                    setTextColor(getColor(R.color.textPrimary))
                    background = rounded(withAlpha(event.color, 45), dp(14))
                    setPadding(dp(12), dp(10), dp(12), dp(10))
                }, LinearLayout.LayoutParams(dp(156), LinearLayout.LayoutParams.WRAP_CONTENT).apply { setMargins(0, dp(10), dp(8), 0) })
            }
            card.addView(HorizontalScrollView(this).apply { isHorizontalScrollBarEnabled = true; addView(timeline) })
            content.addView(card, LinearLayout.LayoutParams(LinearLayout.LayoutParams.MATCH_PARENT, LinearLayout.LayoutParams.WRAP_CONTENT).apply { setMargins(0, dp(8), 0, dp(8)) })
        }
    }

    private fun callEventLabel(call: CallEntity): String = "${call.type} \u2022 ${formatDuration(call.duration)}"

    private fun timelineClientKey(name: String, phone: String): String {
        val normalizedName = name.trim().lowercase(Locale.ROOT).replace(Regex("\\s+"), " ")
        if (normalizedName.isNotBlank()) return "name:$normalizedName"
        val digits = phone.filter(Char::isDigit).takeLast(10)
        return if (digits.isNotBlank()) "phone:$digits" else ""
    }

    private fun withAlpha(color: Int, alpha: Int): Int = Color.argb(alpha, Color.red(color), Color.green(color), Color.blue(color))

    private fun addCard(title: String, value: String, onClick: (() -> Unit)? = null) {
        content.addView(TextView(this).apply {
            text = "$title\n$value"
            textSize = 18f
            typeface = Typeface.DEFAULT_BOLD
            setTextColor(getColor(R.color.textPrimary))
            background = rounded(getColor(R.color.surface), dp(22))
            elevation = dp(2).toFloat()
            setPadding(dp(16))
            if (onClick != null) {
                setOnClickListener { onClick() }
                text = "$title \u2197\n$value"
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
            text = "\u0414\u0435\u0442\u0430\u043B\u0438\u0437\u0430\u0446\u0438\u044F \u0433\u0440\u0430\u0444\u0438\u043A\u0430"
            textSize = 14f
            setTextColor(getColor(R.color.textSecondary))
            setPadding(0, dp(12), 0, dp(4))
        })
        val row = LinearLayout(this).apply {
            orientation = LinearLayout.HORIZONTAL
            background = rounded(getColor(R.color.surface), dp(20))
            setPadding(dp(6))
        }
        AnalyticsDetail.entries.forEach { detail ->
            row.addView(Button(this).apply {
                text = detail.title
                background = rounded(if (detail == activeDetail) getColor(R.color.primary) else getColor(R.color.surfaceVariant), dp(16))
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
        row.addView(TextView(this).apply { text = "$label \u2014 $value"; setTextColor(getColor(R.color.textPrimary)) })
        val barWidth = max(dp(12), ((resources.displayMetrics.widthPixels - dp(48)) * value / maxValue.toFloat()).toInt())
        row.addView(View(this).apply { background = rounded(color, dp(6)) }, LinearLayout.LayoutParams(barWidth, dp(12)))
        content.addView(row, LinearLayout.LayoutParams(LinearLayout.LayoutParams.MATCH_PARENT, LinearLayout.LayoutParams.WRAP_CONTENT).apply { setMargins(0, dp(4), 0, dp(8)) })
    }

    private fun addStackedBar(label: String, values: Map<String, Int>, maxValue: Int) {
        val total = values.values.sum()
        val row = LinearLayout(this).apply { orientation = LinearLayout.VERTICAL }
        row.addView(TextView(this).apply { text = "$label \u2014 $total"; setTextColor(getColor(R.color.textPrimary)) })
        val bar = LinearLayout(this).apply { orientation = LinearLayout.HORIZONTAL; background = rounded(getColor(R.color.surface), dp(8)) }
        listOf("\u0412\u0445\u043E\u0434\u044F\u0449\u0438\u0439", "\u0418\u0441\u0445\u043E\u0434\u044F\u0449\u0438\u0439", "\u041F\u0440\u043E\u043F\u0443\u0449\u0435\u043D\u043D\u044B\u0439", "\u041D\u0435\u043E\u0442\u0432\u0435\u0447\u0435\u043D\u043D\u044B\u0439", "\u0421\u0431\u0440\u043E\u0448\u0435\u043D\u043D\u044B\u0439").forEach { type ->
            val count = values[type] ?: 0
            if (count > 0) bar.addView(View(this).apply { setBackgroundColor(typeColor(type)) }, LinearLayout.LayoutParams(0, dp(14), count.toFloat()))
        }
        val barWidth = max(dp(28), ((resources.displayMetrics.widthPixels - dp(48)) * total / maxValue.toFloat()).toInt())
        row.addView(bar, LinearLayout.LayoutParams(barWidth, dp(14)))
        row.addView(TextView(this).apply { text = values.entries.joinToString("  ") { "${shortType(it.key)}: ${it.value}" }; textSize = 12f; setTextColor(getColor(R.color.textSecondary)) })
        content.addView(row, LinearLayout.LayoutParams(LinearLayout.LayoutParams.MATCH_PARENT, LinearLayout.LayoutParams.WRAP_CONTENT).apply { setMargins(0, dp(6), 0, dp(10)) })
    }

    private fun addPieChart(values: Map<String, Int>) {
        val ordered = listOf("\u0412\u0445\u043E\u0434\u044F\u0449\u0438\u0439", "\u0418\u0441\u0445\u043E\u0434\u044F\u0449\u0438\u0439", "\u041F\u0440\u043E\u043F\u0443\u0449\u0435\u043D\u043D\u044B\u0439", "\u041D\u0435\u043E\u0442\u0432\u0435\u0447\u0435\u043D\u043D\u044B\u0439", "\u0421\u0431\u0440\u043E\u0448\u0435\u043D\u043D\u044B\u0439")
            .mapNotNull { type -> values[type]?.takeIf { it > 0 }?.let { type to it } }
        if (ordered.isEmpty()) {
            addText("\u041D\u0435\u0442 \u0434\u0430\u043D\u043D\u044B\u0445 \u0434\u043B\u044F \u043A\u0440\u0443\u0433\u043E\u0432\u043E\u0439 \u0434\u0438\u0430\u0433\u0440\u0430\u043C\u043C\u044B")
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
                canvas.drawText("\u0412\u0441\u0435\u0433\u043E ${total.toInt()}", width / 2f - dp(34), dp(8) + size / 2f + dp(5), textPaint)
            }
        }, LinearLayout.LayoutParams(LinearLayout.LayoutParams.MATCH_PARENT, dp(220)).apply { setMargins(0, dp(6), 0, dp(10)) })
        addPieLegend(ordered, total)
    }

    private fun addPieLegend(values: List<Pair<String, Int>>, total: Float) {
        val legend = LinearLayout(this).apply {
            orientation = LinearLayout.VERTICAL
            background = rounded(getColor(R.color.surface), dp(16))
            setPadding(dp(12), dp(8), dp(12), dp(8))
        }
        values.forEach { (type, count) ->
            legend.addView(TextView(this).apply {
                text = "\u25A0 $type \u2014 $count (${(count * 100 / total).toInt()}%)"
                textSize = 13f
                setTextColor(typeColor(type))
                setPadding(0, dp(3), 0, dp(3))
            })
        }
        content.addView(legend, LinearLayout.LayoutParams(LinearLayout.LayoutParams.MATCH_PARENT, LinearLayout.LayoutParams.WRAP_CONTENT).apply { setMargins(0, 0, 0, dp(8)) })
    }

    private fun addLegend() {
        val legend = LinearLayout(this).apply { orientation = LinearLayout.HORIZONTAL }
        listOf("\u0412\u0445\u043E\u0434\u044F\u0449\u0438\u0439", "\u0418\u0441\u0445\u043E\u0434\u044F\u0449\u0438\u0439", "\u041F\u0440\u043E\u043F\u0443\u0449\u0435\u043D\u043D\u044B\u0439", "\u041D\u0435\u043E\u0442\u0432\u0435\u0447\u0435\u043D\u043D\u044B\u0439", "\u0421\u0431\u0440\u043E\u0448\u0435\u043D\u043D\u044B\u0439").forEach { type ->
            legend.addView(TextView(this).apply { text = "\u25A0 ${shortType(type)}"; textSize = 12f; setTextColor(typeColor(type)); setPadding(0, 0, dp(8), 0) })
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

    private fun addCallEvent(call: CallEntity, editable: Boolean = true) {
        val hasDetails = call.note.isNotBlank() || call.reminder.isNotBlank()
        val row = LinearLayout(this).apply {
            orientation = LinearLayout.HORIZONTAL
            background = rounded(getColor(R.color.surface), dp(14))
            setPadding(dp(12), dp(8), dp(12), dp(8))
            if (editable) setOnClickListener { showCallEditor(call) }
        }
        row.addView(TextView(this).apply {
            text = "${dateFormat.format(Date(call.timestamp))} \u2022 ${call.type} \u2022 ${formatDuration(call.duration)} \u2022 ${call.phone}"
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
        val message = if (events.isEmpty()) "\u0421\u043E\u0431\u044B\u0442\u0438\u0439 \u0441 \u043A\u043E\u043C\u043C\u0435\u043D\u0442\u0430\u0440\u0438\u044F\u043C\u0438 \u0438\u043B\u0438 \u043D\u0430\u043F\u043E\u043C\u0438\u043D\u0430\u043D\u0438\u044F\u043C\u0438 \u043D\u0435\u0442" else events.joinToString("\n\n") {
            "${dateFormat.format(Date(it.timestamp))} \u2022 ${it.type} \u2022 ${it.phone}\n\u041A\u043E\u043C\u043C\u0435\u043D\u0442\u0430\u0440\u0438\u0439: ${it.note.ifBlank { "\u2014" }}\n\u041D\u0430\u043F\u043E\u043C\u0438\u043D\u0430\u043D\u0438\u0435: ${it.reminder.ifBlank { "\u2014" }}"
        }
        val dialog = AlertDialog.Builder(this)
            .setTitle(repairText(client))
            .setMessage(repairText(message))
            .setPositiveButton("\u0417\u0430\u043A\u0440\u044B\u0442\u044C", null)
            .create()
        dialog.installMojibakeRepair()
        dialog.show()
    }

    private fun showCallEditor(call: CallEntity) {
        val box = LinearLayout(this).apply { orientation = LinearLayout.VERTICAL; setPadding(dp(20)) }
        val comment = EditText(this).apply { hint = "\u041A\u043E\u043C\u043C\u0435\u043D\u0442\u0430\u0440\u0438\u0439"; setText(call.note) }
        val reminder = EditText(this).apply { hint = "\u041D\u0430\u043F\u043E\u043C\u0438\u043D\u0430\u043D\u0438\u0435"; setText(call.reminder) }
        box.addView(comment)
        box.addView(reminder)
        val dialog = AlertDialog.Builder(this)
            .setTitle("${dateFormat.format(Date(call.timestamp))} \u2022 ${call.phone}")
            .setView(box)
            .setNegativeButton("\u041E\u0442\u043C\u0435\u043D\u0430", null)
            .setPositiveButton("\u0421\u043E\u0445\u0440\u0430\u043D\u0438\u0442\u044C") { _, _ ->
                lifecycleScope.launch {
                    val db = CallDatabase.getInstance(this@AnalyticsActivity)
                    withContext(Dispatchers.IO) { db.callDao().updateOutcome(call.id, comment.text.toString(), call.tag, reminder.text.toString()) }
                    loadData()
                }
            }
            .create()
        dialog.installMojibakeRepair()
        dialog.show()
    }

    private fun repairText(value: CharSequence): String = TextEncoding.repair(value).toString()

    private fun inPeriod(timestamp: Long): Boolean {
        if (activePeriod == AnalyticsPeriod.ALL) return true
        if (activePeriod == AnalyticsPeriod.YESTERDAY) {
            val today = Calendar.getInstance().apply {
                set(Calendar.HOUR_OF_DAY, 0); set(Calendar.MINUTE, 0); set(Calendar.SECOND, 0); set(Calendar.MILLISECOND, 0)
            }.timeInMillis
            return timestamp in (today - 24 * 60 * 60 * 1000) until today
        }
        val from = Calendar.getInstance().apply {
            set(Calendar.HOUR_OF_DAY, 0); set(Calendar.MINUTE, 0); set(Calendar.SECOND, 0); set(Calendar.MILLISECOND, 0)
            when (activePeriod) {
                AnalyticsPeriod.TODAY -> Unit
                AnalyticsPeriod.YESTERDAY -> add(Calendar.DAY_OF_YEAR, -1)
                AnalyticsPeriod.WEEK -> add(Calendar.DAY_OF_YEAR, -6)
                AnalyticsPeriod.MONTH -> set(Calendar.DAY_OF_MONTH, 1)
                AnalyticsPeriod.YEAR -> set(Calendar.DAY_OF_YEAR, 1)
                AnalyticsPeriod.ALL -> Unit
            }
        }.timeInMillis
        return timestamp >= from
    }

    private fun isPersonalCall(call: CallEntity): Boolean = clientNames[call.phone]?.trim()?.equals("\u041B\u0438\u0447\u043D\u044B\u0439 \u0437\u0432\u043E\u043D\u043E\u043A", ignoreCase = true) == true

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
        AnalyticsDetail.WEEK -> "\u041D\u0435\u0434\u0435\u043B\u044F \u0441 ${dateFormat.format(Date(timestamp))}"
        AnalyticsDetail.MONTH -> SimpleDateFormat("MM.yyyy", Locale("ru")).format(Date(timestamp))
    }

    private fun pillParams(): LinearLayout.LayoutParams = LinearLayout.LayoutParams(LinearLayout.LayoutParams.WRAP_CONTENT, dp(42)).apply { setMargins(0, dp(6), dp(8), dp(6)) }

    private fun typeColor(type: String): Int = when (type) {
        "\u0412\u0445\u043E\u0434\u044F\u0449\u0438\u0439" -> Color.rgb(16, 185, 129)
        "\u0418\u0441\u0445\u043E\u0434\u044F\u0449\u0438\u0439" -> Color.rgb(59, 130, 246)
        "\u041F\u0440\u043E\u043F\u0443\u0449\u0435\u043D\u043D\u044B\u0439" -> Color.rgb(245, 158, 11)
        "\u041D\u0435\u043E\u0442\u0432\u0435\u0447\u0435\u043D\u043D\u044B\u0439" -> Color.rgb(239, 68, 68)
        "\u0421\u0431\u0440\u043E\u0448\u0435\u043D\u043D\u044B\u0439" -> Color.rgb(139, 92, 246)
        else -> getColor(R.color.primary)
    }

    private fun shortType(type: String): String = when (type) {
        "\u0412\u0445\u043E\u0434\u044F\u0449\u0438\u0439" -> "\u0412\u0445"
        "\u0418\u0441\u0445\u043E\u0434\u044F\u0449\u0438\u0439" -> "\u0418\u0441\u0445"
        "\u041F\u0440\u043E\u043F\u0443\u0449\u0435\u043D\u043D\u044B\u0439" -> "\u041F\u0440\u043E\u043F"
        "\u041D\u0435\u043E\u0442\u0432\u0435\u0447\u0435\u043D\u043D\u044B\u0439" -> "\u041D\u0435\u043E\u0442\u0432"
        "\u0421\u0431\u0440\u043E\u0448\u0435\u043D\u043D\u044B\u0439" -> "\u0421\u0431\u0440"
        else -> type
    }

    private fun rounded(color: Int, radius: Int): GradientDrawable = GradientDrawable().apply { setColor(color); cornerRadius = radius.toFloat() }
    private fun statusBarHeight(): Int = resources.getIdentifier("status_bar_height", "dimen", "android").takeIf { it > 0 }?.let { resources.getDimensionPixelSize(it) } ?: 0
    private fun formatDuration(seconds: Long): String = "${seconds / 60}:${String.format(Locale.US, "%02d", seconds % 60)}"
    private fun dp(value: Int): Int = (value * resources.displayMetrics.density).toInt()
}

private enum class AnalyticsTab { DASHBOARD, CONTACTS }
private enum class AnalyticsPeriod(val title: String, val hasDetail: Boolean = false) {
    TODAY("\u0421\u0435\u0433\u043E\u0434\u043D\u044F"), YESTERDAY("\u0412\u0447\u0435\u0440\u0430"), WEEK("\u041D\u0435\u0434\u0435\u043B\u044F", true), MONTH("\u041C\u0435\u0441\u044F\u0446", true), YEAR("\u0413\u043E\u0434", true), ALL("\u0412\u0441\u0435");

    fun dateRange(): Pair<String, String> {
        val to = Calendar.getInstance()
        val from = Calendar.getInstance()
        when (this) {
            TODAY -> Unit
            YESTERDAY -> { from.add(Calendar.DAY_OF_YEAR, -1); to.add(Calendar.DAY_OF_YEAR, -1) }
            WEEK -> from.add(Calendar.DAY_OF_YEAR, -6)
            MONTH -> from.set(Calendar.DAY_OF_MONTH, 1)
            YEAR -> from.set(Calendar.DAY_OF_YEAR, 1)
            ALL -> from.set(2020, Calendar.JANUARY, 1)
        }
        val format = SimpleDateFormat("yyyy-MM-dd", Locale.US)
        return format.format(from.time) to format.format(to.time)
    }
}
private enum class AnalyticsDetail(val title: String) { DAY("\u0414\u043D\u0438"), WEEK("\u041D\u0435\u0434\u0435\u043B\u0438"), MONTH("\u041C\u0435\u0441\u044F\u0446\u044B") }
private data class ContactTimeline(val calls: List<CallEntity>, val emails: List<TimelineEmail>, val sales: List<TimelineSale>, val salesAvailable: Boolean)
private data class TimelineEmail(val client: String, val subject: String, val timestamp: Long)
private data class TimelineSale(val client: String, val phone: String, val amount: String, val timestamp: Long)
private data class TimelineUiEvent(val timestamp: Long, val label: String, val color: Int)
