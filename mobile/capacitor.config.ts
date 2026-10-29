import type { CapacitorConfig } from '@capacitor/cli';

const config: CapacitorConfig = {
  appId: 'de.planquadro.app',
  appName: 'Planquadro',
  webDir: 'dist',
  // Die Oberfläche wird ausschließlich aus dem App-Bundle geladen (kein server.url).
  // Die Server-Adresse des Mandanten gibt der Nutzer zur Laufzeit ein.
  server: {
    androidScheme: 'https',
    // Klartext-HTTP ist nur für localhost/10.0.2.2 erlaubt:
    // Android über res/xml/network_security_config.xml, iOS über NSAllowsLocalNetworking.
    cleartext: false,
  },
  android: {
    allowMixedContent: false,
    webContentsDebuggingEnabled: false,
  },
  ios: {
    contentInset: 'never',
  },
  plugins: {
    SplashScreen: {
      launchShowDuration: 1200,
      launchAutoHide: true,
      backgroundColor: '#14213D',
      showSpinner: false,
    },
    PushNotifications: {
      presentationOptions: ['badge', 'sound', 'alert'],
    },
    StatusBar: {
      overlaysWebView: false,
      style: 'DARK',
      backgroundColor: '#14213D',
    },
  },
};

export default config;
