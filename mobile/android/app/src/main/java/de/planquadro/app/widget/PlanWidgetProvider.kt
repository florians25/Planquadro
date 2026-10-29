package de.planquadro.app.widget

import android.app.PendingIntent
import android.appwidget.AppWidgetManager
import android.appwidget.AppWidgetProvider
import android.content.ComponentName
import android.content.Context
import android.content.Intent
import android.graphics.Color
import android.net.Uri
import android.view.View
import android.widget.RemoteViews
import de.planquadro.app.MainActivity
import de.planquadro.app.R
import java.text.SimpleDateFormat
import java.util.Date
import java.util.Locale

/**
 * App-Widget „Planquadro – Diese Woche“ (RemoteViews).
 *
 * Zeigt heute und die nächsten Tage aus der Zusammenfassung, die die App nach jedem Sync
 * schreibt (WidgetStore). Tippen öffnet die App am jeweiligen Tag (`planquadro://tag/…`),
 * Tippen auf den Rahmen öffnet „Mein Plan“.
 */
class PlanWidgetProvider : AppWidgetProvider() {

    override fun onUpdate(context: Context, manager: AppWidgetManager, ids: IntArray) {
        val views = build(context)
        for (id in ids) manager.updateAppWidget(id, views)
    }

    companion object {
        const val ROWS = 5
        private val ROW_IDS = arrayOf(
            intArrayOf(R.id.row_1, R.id.row_1_date, R.id.row_1_badge_bg, R.id.row_1_code, R.id.row_1_label),
            intArrayOf(R.id.row_2, R.id.row_2_date, R.id.row_2_badge_bg, R.id.row_2_code, R.id.row_2_label),
            intArrayOf(R.id.row_3, R.id.row_3_date, R.id.row_3_badge_bg, R.id.row_3_code, R.id.row_3_label),
            intArrayOf(R.id.row_4, R.id.row_4_date, R.id.row_4_badge_bg, R.id.row_4_code, R.id.row_4_label),
            intArrayOf(R.id.row_5, R.id.row_5_date, R.id.row_5_badge_bg, R.id.row_5_code, R.id.row_5_label),
        )

        /** Alle platzierten Widgets neu zeichnen (nach einem Sync oder beim Abmelden). */
        fun refreshAll(context: Context) {
            val manager = AppWidgetManager.getInstance(context)
            val ids = manager.getAppWidgetIds(ComponentName(context, PlanWidgetProvider::class.java))
            if (ids.isEmpty()) return
            val views = build(context)
            for (id in ids) manager.updateAppWidget(id, views)
        }

        private fun open(context: Context, uri: String, requestCode: Int): PendingIntent {
            val intent = Intent(Intent.ACTION_VIEW, Uri.parse(uri), context, MainActivity::class.java)
                .addFlags(Intent.FLAG_ACTIVITY_NEW_TASK or Intent.FLAG_ACTIVITY_SINGLE_TOP)
            return PendingIntent.getActivity(context, requestCode, intent, PendingIntent.FLAG_UPDATE_CURRENT or PendingIntent.FLAG_IMMUTABLE)
        }

        private fun color(hex: String, fallback: Int): Int = try {
            Color.parseColor(hex)
        } catch (e: IllegalArgumentException) {
            fallback
        }

        private fun badge(views: RemoteViews, bgId: Int, codeId: Int, day: WidgetDay) {
            when (day.kind) {
                "assignment" -> {
                    views.setViewVisibility(bgId, View.VISIBLE)
                    views.setInt(bgId, "setColorFilter", color(day.color, Color.GRAY))
                    views.setTextViewText(codeId, day.code)
                    views.setTextColor(codeId, color(day.fg, Color.WHITE))
                }
                "absence" -> {
                    views.setViewVisibility(bgId, View.VISIBLE)
                    views.setInt(bgId, "setColorFilter", Color.parseColor("#64748B"))
                    views.setTextViewText(codeId, "–")
                    views.setTextColor(codeId, Color.WHITE)
                }
                else -> {
                    views.setViewVisibility(bgId, View.INVISIBLE)
                    views.setTextViewText(codeId, "")
                }
            }
        }

        private fun label(day: WidgetDay): String {
            val base = day.label.ifEmpty { "frei" }
            return if (day.holiday != null && day.kind == "free") day.holiday else if (day.holiday != null) "$base · ${day.holiday}" else base
        }

        /** Baut die RemoteViews (auch für die Debug-Vorschau WidgetPreviewActivity). */
        fun build(context: Context): RemoteViews {
            val views = RemoteViews(context.packageName, R.layout.widget_plan)
            views.setOnClickPendingIntent(R.id.widget_root, open(context, "planquadro://plan", 100))

            val summary = WidgetStore.load(context)
            // Bereits vergangene Tage überspringen, falls die App länger nicht synchronisiert hat
            val todayIso = SimpleDateFormat("yyyy-MM-dd", Locale.US).format(Date())
            val days = summary?.days?.filter { it.date >= todayIso } ?: emptyList()
            val today = days.firstOrNull()

            if (today == null) {
                views.setViewVisibility(R.id.widget_today, View.GONE)
                for (row in ROW_IDS) views.setViewVisibility(row[0], View.GONE)
                views.setViewVisibility(R.id.widget_empty, View.VISIBLE)
                return views
            }
            views.setViewVisibility(R.id.widget_empty, View.GONE)
            views.setViewVisibility(R.id.widget_today, View.VISIBLE)
            views.setTextViewText(R.id.today_date, "${today.wd} ${today.dm}")
            views.setTextViewText(R.id.today_label, label(today))
            badge(views, R.id.today_badge_bg, R.id.today_code, today)
            views.setOnClickPendingIntent(R.id.widget_today, open(context, "planquadro://tag/${today.date}", 101))
            views.setContentDescription(R.id.widget_today, "Heute, ${today.wd} ${today.dm}: ${label(today)}")

            val rest = days.drop(1)
            ROW_IDS.forEachIndexed { i, row ->
                val d = rest.getOrNull(i)
                if (d == null) {
                    views.setViewVisibility(row[0], View.GONE)
                } else {
                    views.setViewVisibility(row[0], View.VISIBLE)
                    views.setTextViewText(row[1], "${d.wd} ${d.dm}")
                    badge(views, row[2], row[3], d)
                    views.setTextViewText(row[4], label(d))
                    views.setOnClickPendingIntent(row[0], open(context, "planquadro://tag/${d.date}", 110 + i))
                    views.setContentDescription(row[0], "${d.wd} ${d.dm}: ${label(d)}")
                }
            }
            return views
        }
    }
}
