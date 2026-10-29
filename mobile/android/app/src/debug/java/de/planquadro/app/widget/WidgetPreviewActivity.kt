package de.planquadro.app.widget

import android.graphics.Color
import android.graphics.drawable.GradientDrawable
import android.os.Bundle
import android.util.TypedValue
import android.view.Gravity
import android.view.ViewGroup
import android.widget.FrameLayout
import androidx.appcompat.app.AppCompatActivity

/**
 * Debug-Hilfe für CI-Screenshots: zeichnet exakt die RemoteViews des App-Widgets
 * (PlanWidgetProvider.build) auf einem Startbildschirm-ähnlichen Hintergrund.
 *
 * Grund: Ein Widget lässt sich ohne UI-Testframework nicht per adb auf den Launcher legen.
 * Die Vorschau nutzt dieselbe Layout-Datei, dieselben Daten (WidgetStore) und dieselbe
 * Render-Funktion wie das echte Widget.
 */
class WidgetPreviewActivity : AppCompatActivity() {

    private fun dp(v: Float): Int = TypedValue.applyDimension(TypedValue.COMPLEX_UNIT_DIP, v, resources.displayMetrics).toInt()

    override fun onCreate(savedInstanceState: Bundle?) {
        super.onCreate(savedInstanceState)
        val root = FrameLayout(this)
        root.background = GradientDrawable(
            GradientDrawable.Orientation.TL_BR,
            intArrayOf(Color.parseColor("#14213D"), Color.parseColor("#2557D6"), Color.parseColor("#4C7BF0")),
        )
        val views = PlanWidgetProvider.build(this)
        val widget = views.apply(this, root)
        val lp = FrameLayout.LayoutParams(dp(340f), ViewGroup.LayoutParams.WRAP_CONTENT, Gravity.CENTER_HORIZONTAL or Gravity.TOP)
        lp.topMargin = dp(140f)
        root.addView(widget, lp)
        setContentView(root)
    }
}
