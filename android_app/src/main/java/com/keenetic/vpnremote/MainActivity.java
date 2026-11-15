package com.keenetic.vpnremote;

import android.app.Activity;
import android.app.AlertDialog;
import android.content.Context;
import android.os.AsyncTask;
import android.os.Bundle;
import android.os.Vibrator;
import android.view.LayoutInflater;
import android.view.View;
import android.widget.Button;
import android.widget.EditText;
import android.widget.LinearLayout;
import android.widget.TextView;
import android.widget.Toast;

import java.util.List;

public class MainActivity extends Activity {

    private TextView tvConnectionStatus;
    private TextView tvDuneKuhTitle, tvDuneKuhIp, tvDuneKuhServer, badgeDuneKuh;
    private Button btnToggleDuneKuh;

    private TextView tvDuneKomnataTitle, tvDuneKomnataIp, tvDuneKomnataServer, badgeDuneKomnata;
    private Button btnToggleDuneKomnata;

    private LinearLayout containerOtherDevices;
    private Button btnAllOn, btnAllOff, btnRefresh, btnSettings;

    private ApiClient.DeviceInfo devKuh = null;
    private ApiClient.DeviceInfo devKomnata = null;
    private Vibrator vibrator;

    @Override
    protected void onCreate(Bundle savedInstanceState) {
        super.onCreate(savedInstanceState);
        setContentView(R.layout.activity_main);

        vibrator = (Vibrator) getSystemService(Context.VIBRATOR_SERVICE);

        tvConnectionStatus = findViewById(R.id.tvConnectionStatus);

        tvDuneKuhTitle = findViewById(R.id.tvDuneKuhTitle);
        tvDuneKuhIp = findViewById(R.id.tvDuneKuhIp);
        tvDuneKuhServer = findViewById(R.id.tvDuneKuhServer);
        badgeDuneKuh = findViewById(R.id.badgeDuneKuh);
        btnToggleDuneKuh = findViewById(R.id.btnToggleDuneKuh);

        tvDuneKomnataTitle = findViewById(R.id.tvDuneKomnataTitle);
        tvDuneKomnataIp = findViewById(R.id.tvDuneKomnataIp);
        tvDuneKomnataServer = findViewById(R.id.tvDuneKomnataServer);
        badgeDuneKomnata = findViewById(R.id.badgeDuneKomnata);
        btnToggleDuneKomnata = findViewById(R.id.btnToggleDuneKomnata);

        containerOtherDevices = findViewById(R.id.containerOtherDevices);

        btnAllOn = findViewById(R.id.btnAllOn);
        btnAllOff = findViewById(R.id.btnAllOff);
        btnRefresh = findViewById(R.id.btnRefresh);
        btnSettings = findViewById(R.id.btnSettings);

        btnRefresh.setOnClickListener(v -> {
            vibrate();
            loadStatus();
        });

        btnSettings.setOnClickListener(v -> showSettingsDialog());

        btnToggleDuneKuh.setOnClickListener(v -> {
            vibrate();
            if (devKuh != null) {
                toggleDevice(devKuh, !devKuh.enabled);
            } else {
                toggleDeviceById("dune_kuh", true);
            }
        });

        btnToggleDuneKomnata.setOnClickListener(v -> {
            vibrate();
            if (devKomnata != null) {
                toggleDevice(devKomnata, !devKomnata.enabled);
            } else {
                toggleDeviceById("dune_komnata", true);
            }
        });

        btnAllOn.setOnClickListener(v -> {
            vibrate();
            toggleAllDevices(true);
        });

        btnAllOff.setOnClickListener(v -> {
            vibrate();
            toggleAllDevices(false);
        });

        loadStatus();
    }

    private void vibrate() {
        if (vibrator != null && vibrator.hasVibrator()) {
            vibrator.vibrate(40);
        }
    }

    private void showSettingsDialog() {
        AlertDialog.Builder builder = new AlertDialog.Builder(this);
        builder.setTitle(R.string.settings_title);

        LinearLayout layout = new LinearLayout(this);
        layout.setOrientation(LinearLayout.VERTICAL);
        layout.setPadding(50, 20, 50, 10);

        TextView label = new TextView(this);
        label.setText(R.string.router_ip_label);
        label.setTextColor(0xFFCCCCCC);
        layout.addView(label);

        final EditText input = new EditText(this);
        input.setText(ApiClient.getRouterHost(this));
        input.setTextColor(0xFFFFFFFF);
        layout.addView(input);

        builder.setView(layout);
        builder.setPositiveButton("Сохранить", (dialog, which) -> {
            String newHost = input.getText().toString().trim();
            if (!newHost.isEmpty()) {
                ApiClient.setRouterHost(MainActivity.this, newHost);
                Toast.makeText(MainActivity.this, "Сохранено: " + newHost, Toast.LENGTH_SHORT).show();
                loadStatus();
            }
        });
        builder.setNegativeButton("Отмена", null);
        builder.show();
    }

    private void loadStatus() {
        tvConnectionStatus.setText("Запрос к " + ApiClient.getRouterHost(this) + "...");
        tvConnectionStatus.setTextColor(0xFF3B82F6);

        new AsyncTask<Void, Void, ApiClient.StatusResult>() {
            @Override
            protected ApiClient.StatusResult doInBackground(Void... voids) {
                return ApiClient.fetchStatus(MainActivity.this);
            }

            @Override
            protected void onPostExecute(ApiClient.StatusResult result) {
                renderStatus(result);
            }
        }.execute();
    }

    private void renderStatus(ApiClient.StatusResult result) {
        if (!result.success) {
            tvConnectionStatus.setText("⚠️ Ошибка связи с роутером (" + ApiClient.getRouterHost(this) + ")");
            tvConnectionStatus.setTextColor(0xFFEF4444);
            return;
        }

        tvConnectionStatus.setText("🟢 Роутер в сети • Xray " + (result.xrayRunning ? "работает" : "остановлен"));
        tvConnectionStatus.setTextColor(0xFF10B981);

        devKuh = null;
        devKomnata = null;
        containerOtherDevices.removeAllViews();

        for (ApiClient.DeviceInfo dev : result.devices) {
            if ("dune_kuh".equals(dev.id) || "192.168.1.145".equals(dev.ip)) {
                devKuh = dev;
                renderDeviceCard(dev, tvDuneKuhTitle, tvDuneKuhIp, tvDuneKuhServer, badgeDuneKuh, btnToggleDuneKuh);
            } else if ("dune_komnata".equals(dev.id) || "192.168.1.53".equals(dev.ip)) {
                devKomnata = dev;
                renderDeviceCard(dev, tvDuneKomnataTitle, tvDuneKomnataIp, tvDuneKomnataServer, badgeDuneKomnata, btnToggleDuneKomnata);
            } else {
                addOtherDeviceView(dev);
            }
        }
    }

    private void renderDeviceCard(ApiClient.DeviceInfo dev, TextView tvTitle, TextView tvIp,
                                  TextView tvServer, TextView badge, Button btn) {
        tvTitle.setText(dev.icon + " " + dev.name);
        tvIp.setText("IP: " + dev.ip);
        tvServer.setText("Сервер: " + dev.serverName);

        if (dev.enabled) {
            badge.setText("VPN АКТИВЕН");
            badge.setTextColor(0xFF10B981);
            badge.setBackgroundResource(R.drawable.badge_green);

            btn.setText("ВЫКЛЮЧИТЬ VPN");
            btn.setBackgroundResource(R.drawable.btn_red);
        } else {
            badge.setText("ПРЯМОЕ");
            badge.setTextColor(0xFF94A3B8);
            badge.setBackgroundResource(R.drawable.badge_gray);

            btn.setText("ВКЛЮЧИТЬ VPN");
            btn.setBackgroundResource(R.drawable.btn_green);
        }
    }

    private void addOtherDeviceView(final ApiClient.DeviceInfo dev) {
        LinearLayout card = new LinearLayout(this);
        card.setOrientation(LinearLayout.VERTICAL);
        card.setBackgroundResource(R.drawable.card_bg);
        card.setPadding(40, 40, 40, 40);
        LinearLayout.LayoutParams params = new LinearLayout.LayoutParams(
                LinearLayout.LayoutParams.MATCH_PARENT, LinearLayout.LayoutParams.WRAP_CONTENT);
        params.setMargins(0, 0, 0, 30);
        card.setLayoutParams(params);

        TextView tvTitle = new TextView(this);
        tvTitle.setText(dev.icon + " " + dev.name + " (" + dev.ip + ")");
        tvTitle.setTextColor(0xFFF8FAFC);
        tvTitle.setTextSize(16);
        tvTitle.setTypeface(null, android.graphics.Typeface.BOLD);
        card.addView(tvTitle);

        TextView tvServer = new TextView(this);
        tvServer.setText("Сервер: " + dev.serverName + " • Статус: " + (dev.enabled ? "VPN ВКЛ" : "ПРЯМОЕ"));
        tvServer.setTextColor(dev.enabled ? 0xFF10B981 : 0xFF94A3B8);
        tvServer.setTextSize(12);
        tvServer.setPadding(0, 8, 0, 16);
        card.addView(tvServer);

        Button btn = new Button(this);
        btn.setText(dev.enabled ? "ВЫКЛЮЧИТЬ VPN" : "ВКЛЮЧИТЬ VPN");
        btn.setBackgroundResource(dev.enabled ? R.drawable.btn_red : R.drawable.btn_green);
        btn.setTextColor(0xFFFFFFFF);
        btn.setOnClickListener(v -> {
            vibrate();
            toggleDevice(dev, !dev.enabled);
        });
        card.addView(btn);

        containerOtherDevices.addView(card);
    }

    private void toggleDevice(final ApiClient.DeviceInfo dev, final boolean enable) {
        tvConnectionStatus.setText("Переключение " + dev.name + " -> " + (enable ? "VPN ВКЛ" : "ВЫКЛ") + "...");
        tvConnectionStatus.setTextColor(0xFF3B82F6);

        new AsyncTask<Void, Void, Boolean>() {
            @Override
            protected Boolean doInBackground(Void... voids) {
                return ApiClient.toggleDevice(MainActivity.this, dev.id, enable);
            }

            @Override
            protected void onPostExecute(Boolean success) {
                if (success) {
                    dev.enabled = enable;
                    loadStatus();
                } else {
                    Toast.makeText(MainActivity.this, "Ошибка переключения " + dev.name, Toast.LENGTH_SHORT).show();
                    loadStatus();
                }
            }
        }.execute();
    }

    private void toggleDeviceById(final String id, final boolean enable) {
        new AsyncTask<Void, Void, Boolean>() {
            @Override
            protected Boolean doInBackground(Void... voids) {
                return ApiClient.toggleDevice(MainActivity.this, id, enable);
            }

            @Override
            protected void onPostExecute(Boolean success) {
                loadStatus();
            }
        }.execute();
    }

    private void toggleAllDevices(final boolean enable) {
        tvConnectionStatus.setText("Переключение всех приставок -> " + (enable ? "VPN ВКЛ" : "ВЫКЛ") + "...");
        tvConnectionStatus.setTextColor(0xFF3B82F6);

        new AsyncTask<Void, Void, Boolean>() {
            @Override
            protected Boolean doInBackground(Void... voids) {
                return ApiClient.toggleAll(MainActivity.this, enable);
            }

            @Override
            protected void onPostExecute(Boolean success) {
                Toast.makeText(MainActivity.this, enable ? "VPN включен на всех приставках" : "VPN выключен на всех приставках", Toast.LENGTH_SHORT).show();
                loadStatus();
            }
        }.execute();
    }
}
