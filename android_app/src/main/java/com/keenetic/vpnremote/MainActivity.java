package com.keenetic.vpnremote;

import android.app.Activity;
import android.app.AlertDialog;
import android.content.Context;
import android.os.AsyncTask;
import android.os.Bundle;
import android.os.Vibrator;
import android.widget.Button;
import android.widget.EditText;
import android.widget.LinearLayout;
import android.widget.TextView;
import android.widget.Toast;

public class MainActivity extends Activity {

    private TextView tvConnectionStatus;

    // Dune Кухня
    private TextView tvDuneKuhTitle, tvDuneKuhIp, tvDuneKuhServer, badgeDuneKuh;
    private Button btnKuhSmart, btnKuhFull, btnKuhOff;

    // Dune Комната
    private TextView tvDuneKomnataTitle, tvDuneKomnataIp, tvDuneKomnataServer, badgeDuneKomnata;
    private Button btnKomnataSmart, btnKomnataFull, btnKomnataOff;

    // Container for other devices
    private LinearLayout containerOtherDevices;

    // Batch & Top Controls
    private Button btnAllSmart, btnAllFull, btnAllOff, btnRefresh, btnSettings;

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
        btnKuhSmart = findViewById(R.id.btnKuhSmart);
        btnKuhFull = findViewById(R.id.btnKuhFull);
        btnKuhOff = findViewById(R.id.btnKuhOff);

        tvDuneKomnataTitle = findViewById(R.id.tvDuneKomnataTitle);
        tvDuneKomnataIp = findViewById(R.id.tvDuneKomnataIp);
        tvDuneKomnataServer = findViewById(R.id.tvDuneKomnataServer);
        badgeDuneKomnata = findViewById(R.id.badgeDuneKomnata);
        btnKomnataSmart = findViewById(R.id.btnKomnataSmart);
        btnKomnataFull = findViewById(R.id.btnKomnataFull);
        btnKomnataOff = findViewById(R.id.btnKomnataOff);

        containerOtherDevices = findViewById(R.id.containerOtherDevices);

        btnAllSmart = findViewById(R.id.btnAllSmart);
        btnAllFull = findViewById(R.id.btnAllFull);
        btnAllOff = findViewById(R.id.btnAllOff);
        btnRefresh = findViewById(R.id.btnRefresh);
        btnSettings = findViewById(R.id.btnSettings);

        btnRefresh.setOnClickListener(v -> {
            vibrate();
            loadStatus();
        });

        btnSettings.setOnClickListener(v -> showSettingsDialog());

        // Kuh buttons
        btnKuhSmart.setOnClickListener(v -> {
            vibrate();
            setDeviceMode("dune_kuh", "smart");
        });
        btnKuhFull.setOnClickListener(v -> {
            vibrate();
            setDeviceMode("dune_kuh", "full");
        });
        btnKuhOff.setOnClickListener(v -> {
            vibrate();
            setDeviceMode("dune_kuh", "off");
        });

        // Komnata buttons
        btnKomnataSmart.setOnClickListener(v -> {
            vibrate();
            setDeviceMode("dune_komnata", "smart");
        });
        btnKomnataFull.setOnClickListener(v -> {
            vibrate();
            setDeviceMode("dune_komnata", "full");
        });
        btnKomnataOff.setOnClickListener(v -> {
            vibrate();
            setDeviceMode("dune_komnata", "off");
        });

        // Batch buttons
        btnAllSmart.setOnClickListener(v -> {
            vibrate();
            toggleAllDevices("smart");
        });
        btnAllFull.setOnClickListener(v -> {
            vibrate();
            toggleAllDevices("full");
        });
        btnAllOff.setOnClickListener(v -> {
            vibrate();
            toggleAllDevices("off");
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

        containerOtherDevices.removeAllViews();

        for (ApiClient.DeviceInfo dev : result.devices) {
            if ("dune_kuh".equals(dev.id) || "192.168.1.145".equals(dev.ip)) {
                renderDeviceCard(dev, tvDuneKuhTitle, tvDuneKuhIp, tvDuneKuhServer, badgeDuneKuh,
                        btnKuhSmart, btnKuhFull, btnKuhOff);
            } else if ("dune_komnata".equals(dev.id) || "192.168.1.53".equals(dev.ip)) {
                renderDeviceCard(dev, tvDuneKomnataTitle, tvDuneKomnataIp, tvDuneKomnataServer, badgeDuneKomnata,
                        btnKomnataSmart, btnKomnataFull, btnKomnataOff);
            } else {
                addOtherDeviceView(dev);
            }
        }
    }

    private void renderDeviceCard(ApiClient.DeviceInfo dev, TextView tvTitle, TextView tvIp,
                                  TextView tvServer, TextView badge,
                                  Button btnSmart, Button btnFull, Button btnOff) {
        tvTitle.setText(dev.icon + " " + dev.name);
        tvIp.setText("IP: " + dev.ip);

        String mode = dev.mode != null ? dev.mode : (dev.enabled ? "smart" : "off");

        if ("smart".equals(mode)) {
            badge.setText("🎯 УМНЫЙ (YT)");
            badge.setTextColor(0xFF10B981);
            badge.setBackgroundResource(R.drawable.badge_green);
            tvServer.setText("🎯 YouTube через VPN • Кинопоиск/Иви напрямую\nСервер: " + dev.serverName);

            btnSmart.setBackgroundResource(R.drawable.btn_green);
            btnSmart.setTextColor(0xFFFFFFFF);

            btnFull.setBackgroundResource(R.drawable.btn_gray);
            btnFull.setTextColor(0xFF94A3B8);

            btnOff.setBackgroundResource(R.drawable.btn_gray);
            btnOff.setTextColor(0xFF94A3B8);
        } else if ("full".equals(mode)) {
            badge.setText("🛡️ ПОЛНЫЙ VPN");
            badge.setTextColor(0xFFC084FC);
            badge.setBackgroundResource(R.drawable.badge_purple);
            tvServer.setText("🛡️ Весь трафик приставки через VPN\nСервер: " + dev.serverName);

            btnSmart.setBackgroundResource(R.drawable.btn_gray);
            btnSmart.setTextColor(0xFF94A3B8);

            btnFull.setBackgroundResource(R.drawable.btn_purple);
            btnFull.setTextColor(0xFFFFFFFF);

            btnOff.setBackgroundResource(R.drawable.btn_gray);
            btnOff.setTextColor(0xFF94A3B8);
        } else {
            badge.setText("⚪ ВЫКЛЮЧЕН");
            badge.setTextColor(0xFF94A3B8);
            badge.setBackgroundResource(R.drawable.badge_gray);
            tvServer.setText("⚪ Прямое подключение без VPN\nСервер: " + dev.serverName);

            btnSmart.setBackgroundResource(R.drawable.btn_gray);
            btnSmart.setTextColor(0xFF94A3B8);

            btnFull.setBackgroundResource(R.drawable.btn_gray);
            btnFull.setTextColor(0xFF94A3B8);

            btnOff.setBackgroundResource(R.drawable.btn_red);
            btnOff.setTextColor(0xFFFFFFFF);
        }
    }

    private void addOtherDeviceView(final ApiClient.DeviceInfo dev) {
        LinearLayout card = new LinearLayout(this);
        card.setOrientation(LinearLayout.VERTICAL);
        card.setBackgroundResource(R.drawable.card_bg);
        card.setPadding(35, 35, 35, 35);
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

        String mode = dev.mode != null ? dev.mode : (dev.enabled ? "smart" : "off");
        TextView tvSub = new TextView(this);
        tvSub.setTextSize(12);
        tvSub.setPadding(0, 6, 0, 14);

        if ("smart".equals(mode)) {
            tvSub.setText("🎯 Умный YouTube • Сервер: " + dev.serverName);
            tvSub.setTextColor(0xFF10B981);
        } else if ("full".equals(mode)) {
            tvSub.setText("🛡️ Полный VPN • Сервер: " + dev.serverName);
            tvSub.setTextColor(0xFFC084FC);
        } else {
            tvSub.setText("⚪ Выключен (прямое) • Сервер: " + dev.serverName);
            tvSub.setTextColor(0xFF94A3B8);
        }
        card.addView(tvSub);

        LinearLayout row = new LinearLayout(this);
        row.setOrientation(LinearLayout.HORIZONTAL);

        Button bSmart = new Button(this);
        bSmart.setText("🎯 Умный");
        bSmart.setTextSize(11);
        bSmart.setTypeface(null, android.graphics.Typeface.BOLD);
        bSmart.setTextColor("smart".equals(mode) ? 0xFFFFFFFF : 0xFF94A3B8);
        bSmart.setBackgroundResource("smart".equals(mode) ? R.drawable.btn_green : R.drawable.btn_gray);
        LinearLayout.LayoutParams lp1 = new LinearLayout.LayoutParams(0, 110, 1.2f);
        lp1.setMargins(0, 0, 10, 0);
        bSmart.setLayoutParams(lp1);
        bSmart.setOnClickListener(v -> {
            vibrate();
            setDeviceMode(dev.id, "smart");
        });
        row.addView(bSmart);

        Button bFull = new Button(this);
        bFull.setText("🛡️ Полный");
        bFull.setTextSize(11);
        bFull.setTypeface(null, android.graphics.Typeface.BOLD);
        bFull.setTextColor("full".equals(mode) ? 0xFFFFFFFF : 0xFF94A3B8);
        bFull.setBackgroundResource("full".equals(mode) ? R.drawable.btn_purple : R.drawable.btn_gray);
        LinearLayout.LayoutParams lp2 = new LinearLayout.LayoutParams(0, 110, 1f);
        lp2.setMargins(0, 0, 10, 0);
        bFull.setLayoutParams(lp2);
        bFull.setOnClickListener(v -> {
            vibrate();
            setDeviceMode(dev.id, "full");
        });
        row.addView(bFull);

        Button bOff = new Button(this);
        bOff.setText("⚪ Выкл");
        bOff.setTextSize(11);
        bOff.setTypeface(null, android.graphics.Typeface.BOLD);
        bOff.setTextColor("off".equals(mode) ? 0xFFFFFFFF : 0xFF94A3B8);
        bOff.setBackgroundResource("off".equals(mode) ? R.drawable.btn_red : R.drawable.btn_gray);
        LinearLayout.LayoutParams lp3 = new LinearLayout.LayoutParams(0, 110, 0.8f);
        bOff.setLayoutParams(lp3);
        bOff.setOnClickListener(v -> {
            vibrate();
            setDeviceMode(dev.id, "off");
        });
        row.addView(bOff);

        card.addView(row);
        containerOtherDevices.addView(card);
    }

    private void setDeviceMode(final String deviceId, final String mode) {
        String modeDesc = "smart".equals(mode) ? "Умный YouTube" : ("full".equals(mode) ? "Полный VPN" : "Выключение");
        tvConnectionStatus.setText("Переключение " + deviceId + " -> " + modeDesc + "...");
        tvConnectionStatus.setTextColor(0xFF3B82F6);

        new AsyncTask<Void, Void, Boolean>() {
            @Override
            protected Boolean doInBackground(Void... voids) {
                return ApiClient.setDeviceMode(MainActivity.this, deviceId, mode);
            }

            @Override
            protected void onPostExecute(Boolean success) {
                if (!success) {
                    Toast.makeText(MainActivity.this, "Ошибка переключения режима", Toast.LENGTH_SHORT).show();
                }
                loadStatus();
            }
        }.execute();
    }

    private void toggleAllDevices(final String mode) {
        boolean enable = !"off".equals(mode);
        String modeDesc = "smart".equals(mode) ? "Умный YouTube" : ("full".equals(mode) ? "Полный VPN" : "Выключение");
        tvConnectionStatus.setText("Переключение всех приставок -> " + modeDesc + "...");
        tvConnectionStatus.setTextColor(0xFF3B82F6);

        new AsyncTask<Void, Void, Boolean>() {
            @Override
            protected Boolean doInBackground(Void... voids) {
                return ApiClient.toggleAll(MainActivity.this, enable, mode);
            }

            @Override
            protected void onPostExecute(Boolean success) {
                Toast.makeText(MainActivity.this, "Режим (" + modeDesc + ") применен ко всем приставкам", Toast.LENGTH_SHORT).show();
                loadStatus();
            }
        }.execute();
    }
}
