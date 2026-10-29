package de.planquadro.app.widget

import com.getcapacitor.Plugin
import com.getcapacitor.PluginCall
import com.getcapacitor.PluginMethod
import com.getcapacitor.annotation.CapacitorPlugin
import org.json.JSONObject

/**
 * App-eigenes Capacitor-Plugin „PlanquadroWidget“ (JS: mobile/src/platform/widget.ts).
 * Registriert in MainActivity.
 */
@CapacitorPlugin(name = "PlanquadroWidget")
class WidgetBridgePlugin : Plugin() {

    @PluginMethod
    fun update(call: PluginCall) {
        val data = call.getString("data")
        if (data.isNullOrEmpty() || data.length > WidgetStore.MAX_BYTES) {
            call.reject("Ungültige Widget-Daten")
            return
        }
        val parsed = try {
            WidgetSummary.parse(JSONObject(data))
        } catch (e: Exception) {
            null
        }
        if (parsed == null) {
            call.reject("Ungültige Widget-Daten")
            return
        }
        WidgetStore.save(context, data)
        PlanWidgetProvider.refreshAll(context)
        call.resolve()
    }

    @PluginMethod
    fun clear(call: PluginCall) {
        WidgetStore.clear(context)
        PlanWidgetProvider.refreshAll(context)
        call.resolve()
    }
}
