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
                        if (deviceId.equals(dev.id)) {
                            ApiClient.toggleDevice(context, dev.id, !dev.enabled);
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

        boolean kuhOn = false;
        boolean komnataOn = false;

        if (status != null && status.success) {
            for (ApiClient.DeviceInfo dev : status.devices) {
                if ("dune_kuh".equals(dev.id) || "192.168.1.145".equals(dev.ip)) {
                    kuhOn = dev.enabled;
                } else if ("dune_komnata".equals(dev.id) || "192.168.1.53".equals(dev.ip)) {
                    komnataOn = dev.enabled;
                }
            }
        }

        views.setTextViewText(R.id.widgetTextKuhState, kuhOn ? "🟢 VPN ВКЛ" : "⚪ БЕЗ VPN");
        views.setTextViewText(R.id.widgetTextKomnataState, komnataOn ? "🟢 VPN ВКЛ" : "⚪ БЕЗ VPN");

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
}
