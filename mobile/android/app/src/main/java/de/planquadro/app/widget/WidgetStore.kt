package de.planquadro.app.widget

import android.content.Context
import org.json.JSONObject

/**
 * Gemeinsamer Speicher zwischen App (WebView → WidgetBridgePlugin) und App-Widget.
 *
 * Inhalt: die nicht vertrauliche Zusammenfassung aus mobile/src/platform/widget.ts
 * (eigene Tage: Datum, Kürzel, Bereichsname; Abwesenheiten nur als „Abwesend“).
 * Liegt in den privaten SharedPreferences der App (kein Export, Backups sind deaktiviert).
 */
object WidgetStore {
    private const val PREFS = "planquadro_widget"
    private const val KEY = "summary_v1"
    const val MAX_BYTES = 16 * 1024

    fun save(context: Context, json: String) {
        context.getSharedPreferences(PREFS, Context.MODE_PRIVATE).edit().putString(KEY, json).apply()
    }

    fun clear(context: Context) {
        context.getSharedPreferences(PREFS, Context.MODE_PRIVATE).edit().remove(KEY).apply()
    }

    fun load(context: Context): WidgetSummary? {
        val raw = context.getSharedPreferences(PREFS, Context.MODE_PRIVATE).getString(KEY, null) ?: return null
        return try {
            WidgetSummary.parse(JSONObject(raw))
        } catch (e: Exception) {
            null
        }
    }
}

data class WidgetDay(
    val date: String,
    val wd: String,
    val dm: String,
    val kind: String,
    val code: String,
    val label: String,
    val color: String,
    val fg: String,
    val holiday: String?,
)

data class WidgetSummary(val version: Int, val generatedAt: String, val today: String, val tenant: String, val days: List<WidgetDay>) {
    companion object {
        private val DATE = Regex("^\\d{4}-\\d{2}-\\d{2}$")
        private val HEX = Regex("^#[0-9a-fA-F]{6}$")

        /** Strenge Prüfung – unbekannte/ungültige Werte werden verworfen bzw. neutralisiert. */
        fun parse(o: JSONObject): WidgetSummary? {
            if (o.optInt("v") != 1) return null
            val arr = o.optJSONArray("days") ?: return null
            val days = mutableListOf<WidgetDay>()
            for (i in 0 until minOf(arr.length(), 14)) {
                val d = arr.optJSONObject(i) ?: continue
                val date = d.optString("date")
                if (!DATE.matches(date)) continue
                val kind = d.optString("kind").takeIf { it in setOf("assignment", "absence", "free") } ?: "free"
                days += WidgetDay(
                    date = date,
                    wd = d.optString("wd").take(3),
                    dm = d.optString("dm").take(6),
                    kind = kind,
                    code = d.optString("code").take(6),
                    label = d.optString("label").take(60),
                    color = d.optString("color").takeIf { HEX.matches(it) } ?: "#64748b",
                    fg = d.optString("fg").takeIf { HEX.matches(it) } ?: "#ffffff",
                    holiday = if (d.isNull("holiday")) null else d.optString("holiday").take(40),
                )
            }
            return WidgetSummary(1, o.optString("generated_at"), o.optString("today"), o.optString("tenant").take(60), days)
        }
    }
}
