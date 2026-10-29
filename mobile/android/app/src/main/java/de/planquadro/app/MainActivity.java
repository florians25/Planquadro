package de.planquadro.app;

import android.os.Bundle;
import com.getcapacitor.BridgeActivity;
import de.planquadro.app.widget.WidgetBridgePlugin;

public class MainActivity extends BridgeActivity {

    @Override
    public void onCreate(Bundle savedInstanceState) {
        // App-eigene Plugins vor super.onCreate registrieren (Capacitor-Vorgabe)
        registerPlugin(WidgetBridgePlugin.class);
        super.onCreate(savedInstanceState);
    }
}
