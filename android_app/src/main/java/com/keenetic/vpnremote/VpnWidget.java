package com.keenetic.vpnremote;

import android.app.PendingIntent;
import android.appwidget.AppWidgetManager;
import android.appwidget.AppWidgetProvider;
import android.content.ComponentName;
import android.content.Context;
import android.content.Intent;
import android.os.AsyncTask;
import android.widget.RemoteViews;

public class VpnWidget extends AppWidgetProvider {

    public static final String ACTION_TOGGLE_KUH = "com.keenetic.vpnremote.ACTION_TOGGLE_KUH";
    public static final String ACTION_TOGGLE_KOMNATA = "com.keenetic.vpnremote.ACTION_TOGGLE_KOMNATA";

    @Override
    public void onUpdate(Context context, AppWidgetManager appWidgetManager, int[] appWidgetIds) {
        for (int id : appWidgetIds) {
            updateWidgetView(context, appWidgetManager, id, null);
        }
        refreshStatusAsync(context);
    }

    @Override
    public void onReceive(Context context, Intent intent) {
        super.onReceive(context, intent);

        String action = intent.getAction();
        if (ACTION_TOGGLE_KUH.equals(action)) {
            toggleAsync(context, "dune_kuh");
        } else if (ACTION_TOGGLE_KOMNATA.equals(action)) {
            toggleAsync(context, "dune_komnata");
        }
    }

    private void toggleAsync(final Context context, final String deviceId) {
        new AsyncTask<Void, Void, Void>() {
            @Override
            protected Void doInBackground(Void... voids) {
                ApiClient.StatusResult status = ApiClient.fetchStatus(context);
                if (status.success) {
                    for (ApiClient.DeviceInfo dev : status.devices) {
                        if (deviceId.equals(dev.id) || (deviceId.contains("kuh") && (dev.ip.endsWith(".29") || dev.ip.endsWith(".145")))
                                || (deviceId.contains("komnata") && dev.ip.endsWith(".53"))) {
                            // If enabled (smart or full) -> turn off
                            // If off -> turn on in "smart" mode (YouTube VPN + Russian direct)
                            String targetMode = "off".equals(dev.mode) ? "smart" : "off";
                            ApiClient.setDeviceMode(context, dev.id, targetMode);
                            break;
                        }
                    }
                }
                return null;
            }

            @Override
            protected void onPostExecute(Void aVoid) {
                refreshStatusAsync(context);
            }
        }.execute();
    }

    private void refreshStatusAsync(final Context context) {
        new AsyncTask<Void, Void, ApiClient.StatusResult>() {
            @Override
            protected ApiClient.StatusResult doInBackground(Void... voids) {
                return ApiClient.fetchStatus(context);
            }

            @Override
            protected void onPostExecute(ApiClient.StatusResult result) {
                AppWidgetManager manager = AppWidgetManager.getInstance(context);
                int[] ids = manager.getAppWidgetIds(new ComponentName(context, VpnWidget.class));
                for (int id : ids) {
                    updateWidgetView(context, manager, id, result);
                }
            }
        }.execute();
    }

    private void updateWidgetView(Context context, AppWidgetManager manager, int widgetId, ApiClient.StatusResult status) {
        RemoteViews views = new RemoteViews(context.getPackageName(), R.layout.widget_layout);

        String kuhMode = "off";
        String komnataMode = "off";

        if (status != null && status.success) {
            for (ApiClient.DeviceInfo dev : status.devices) {
                if ("dune_kuh".equals(dev.id) || "192.168.1.29".equals(dev.ip) || "192.168.1.145".equals(dev.ip)) {
                    kuhMode = dev.mode != null ? dev.mode : (dev.enabled ? "smart" : "off");
                } else if ("dune_komnata".equals(dev.id) || "192.168.1.53".equals(dev.ip)) {
                    komnataMode = dev.mode != null ? dev.mode : (dev.enabled ? "smart" : "off");
                }
            }
        }

        views.setTextViewText(R.id.widgetTextKuhState, formatWidgetMode(kuhMode));
        views.setTextViewText(R.id.widgetTextKomnataState, formatWidgetMode(komnataMode));

        Intent intentKuh = new Intent(context, VpnWidget.class);
        intentKuh.setAction(ACTION_TOGGLE_KUH);
        PendingIntent piKuh = PendingIntent.getBroadcast(context, 101, intentKuh, PendingIntent.FLAG_UPDATE_CURRENT | PendingIntent.FLAG_IMMUTABLE);
        views.setOnClickPendingIntent(R.id.widgetBtnKuh, piKuh);

        Intent intentKomnata = new Intent(context, VpnWidget.class);
        intentKomnata.setAction(ACTION_TOGGLE_KOMNATA);
        PendingIntent piKomnata = PendingIntent.getBroadcast(context, 102, intentKomnata, PendingIntent.FLAG_UPDATE_CURRENT | PendingIntent.FLAG_IMMUTABLE);
        views.setOnClickPendingIntent(R.id.widgetBtnKomnata, piKomnata);

        manager.updateAppWidget(widgetId, views);
    }

    private String formatWidgetMode(String mode) {
        if ("smart".equals(mode)) {
            return "🎯 Умный (YT)";
        } else if ("full".equals(mode)) {
            return "🛡️ Полный VPN";
        } else {
            return "⚪ БЕЗ VPN";
        }
    }
}
