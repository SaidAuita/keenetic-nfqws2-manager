package com.keenetic.vpnremote;

import android.app.Activity;
import android.app.AlertDialog;
import android.content.Context;
import android.os.AsyncTask;
import android.os.Bundle;
import android.os.Vibrator;
import android.view.Gravity;
import android.view.View;
import android.widget.Button;
import android.widget.EditText;
import android.widget.LinearLayout;
import android.widget.TextView;
import android.widget.Toast;

import java.util.ArrayList;
import java.util.HashMap;
import java.util.LinkedHashMap;
import java.util.List;
import java.util.Map;

public class MainActivity extends Activity {

    // Tab Switcher
    private Button btnTabRemote, btnTabServers;
    private LinearLayout layoutTabRemote, layoutTabServers;

    // Top Controls
    private Button btnRefresh, btnSettings;

    // Tab 1: Remote Views
    private TextView tvConnectionStatus;
    private TextView tvDuneKuhTitle, tvDuneKuhIp, tvDuneKuhServer, badgeDuneKuh;
    private Button btnKuhSmart, btnKuhFull, btnKuhOff;

    private TextView tvDuneKomnataTitle, tvDuneKomnataIp, tvDuneKomnataServer, badgeDuneKomnata;
    private Button btnKomnataSmart, btnKomnataFull, btnKomnataOff;

    private LinearLayout containerOtherDevices;
    private Button btnAllSmart, btnAllFull, btnAllOff;

    // Tab 2: Servers Views
    private TextView tvServersBanner;
    private Button btnPingAll, btnRefreshSubs, btnAddSub;
    private LinearLayout containerServers;

    // State
    private ApiClient.StatusResult lastStatus = null;
    private Map<String, Integer> serverPings = new HashMap<>();
    private Vibrator vibrator;
    private int currentTab = 0; // 0 = Remote, 1 = Servers

    @Override
    protected void onCreate(Bundle savedInstanceState) {
        super.onCreate(savedInstanceState);
        setContentView(R.layout.activity_main);

        vibrator = (Vibrator) getSystemService(Context.VIBRATOR_SERVICE);

        // Header controls
        btnSettings = findViewById(R.id.btnSettings);
        btnRefresh = findViewById(R.id.btnRefresh);

        // Tabs
        btnTabRemote = findViewById(R.id.btnTabRemote);
        btnTabServers = findViewById(R.id.btnTabServers);
        layoutTabRemote = findViewById(R.id.layoutTabRemote);
        layoutTabServers = findViewById(R.id.layoutTabServers);

        btnTabRemote.setOnClickListener(v -> {
            vibrate();
            switchTab(0);
        });

        btnTabServers.setOnClickListener(v -> {
            vibrate();
            switchTab(1);
        });

        // Tab 1: Remote views
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

        // Tab 2: Servers views
        tvServersBanner = findViewById(R.id.tvServersBanner);
        btnPingAll = findViewById(R.id.btnPingAll);
        btnRefreshSubs = findViewById(R.id.btnRefreshSubs);
        btnAddSub = findViewById(R.id.btnAddSub);
        containerServers = findViewById(R.id.containerServers);

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

        // Servers tab actions
        btnPingAll.setOnClickListener(v -> {
            vibrate();
            runPingAll();
        });

        btnRefreshSubs.setOnClickListener(v -> {
            vibrate();
            runRefreshSubscriptions();
        });

        btnAddSub.setOnClickListener(v -> {
            vibrate();
            showAddSubscriptionDialog();
        });

        loadStatus();
    }

    private void switchTab(int tabIndex) {
        currentTab = tabIndex;
        if (tabIndex == 0) {
            btnTabRemote.setBackgroundResource(R.drawable.btn_tab_active);
            btnTabRemote.setTextColor(0xFFF8FAFC);
            btnTabServers.setBackgroundResource(R.drawable.btn_tab_inactive);
            btnTabServers.setTextColor(0xFF94A3B8);

            layoutTabRemote.setVisibility(View.VISIBLE);
            layoutTabServers.setVisibility(View.GONE);
        } else {
            btnTabRemote.setBackgroundResource(R.drawable.btn_tab_inactive);
            btnTabRemote.setTextColor(0xFF94A3B8);
            btnTabServers.setBackgroundResource(R.drawable.btn_tab_active);
            btnTabServers.setTextColor(0xFFF8FAFC);

            layoutTabRemote.setVisibility(View.GONE);
            layoutTabServers.setVisibility(View.VISIBLE);

            if (serverPings.isEmpty()) {
                runPingAll();
            }
        }
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

    private void showAddSubscriptionDialog() {
        AlertDialog.Builder builder = new AlertDialog.Builder(this);
        builder.setTitle("➕ Добавить подписку VLESS");

        LinearLayout layout = new LinearLayout(this);
        layout.setOrientation(LinearLayout.VERTICAL);
        layout.setPadding(50, 20, 50, 10);

        TextView labelUrl = new TextView(this);
        labelUrl.setText("Ссылка на подписку (URL):");
        labelUrl.setTextColor(0xFFCCCCCC);
        layout.addView(labelUrl);

        final EditText inputUrl = new EditText(this);
        inputUrl.setHint("https://.../sub/token");
        inputUrl.setTextColor(0xFFFFFFFF);
        inputUrl.setHintTextColor(0xFF64748B);
        layout.addView(inputUrl);

        TextView labelGroup = new TextView(this);
        labelGroup.setText("Название группы (необязательно):");
        labelGroup.setTextColor(0xFFCCCCCC);
        labelGroup.setPadding(0, 20, 0, 0);
        layout.addView(labelGroup);

        final EditText inputGroup = new EditText(this);
        inputGroup.setHint("Например: YummyVPN или Мой VPN");
        inputGroup.setTextColor(0xFFFFFFFF);
        inputGroup.setHintTextColor(0xFF64748B);
        layout.addView(inputGroup);

        builder.setView(layout);
        builder.setPositiveButton("Импортировать", (dialog, which) -> {
            String url = inputUrl.getText().toString().trim();
            String group = inputGroup.getText().toString().trim();
            if (!url.isEmpty()) {
                importSubscriptionAsync(url, group);
            }
        });
        builder.setNegativeButton("Отмена", null);
        builder.show();
    }

    private void importSubscriptionAsync(final String url, final String group) {
        tvServersBanner.setText("Импорт подписки по ссылке...");
        tvServersBanner.setTextColor(0xFF3B82F6);

        new AsyncTask<Void, Void, Boolean>() {
            @Override
            protected Boolean doInBackground(Void... voids) {
                return ApiClient.importSubscription(MainActivity.this, url, group);
            }

            @Override
            protected void onPostExecute(Boolean success) {
                if (success) {
                    Toast.makeText(MainActivity.this, "Подписка успешно импортирована!", Toast.LENGTH_SHORT).show();
                } else {
                    Toast.makeText(MainActivity.this, "Ошибка импорта подписки", Toast.LENGTH_SHORT).show();
                }
                loadStatus();
            }
        }.execute();
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
                lastStatus = result;
                renderStatus(result);
            }
        }.execute();
    }

    private void renderStatus(ApiClient.StatusResult result) {
        if (!result.success) {
            tvConnectionStatus.setText("⚠️ Ошибка связи с роутером (" + ApiClient.getRouterHost(this) + ")");
            tvConnectionStatus.setTextColor(0xFFEF4444);
            tvServersBanner.setText("⚠️ Ошибка связи с роутером");
            tvServersBanner.setTextColor(0xFFEF4444);
            return;
        }

        tvConnectionStatus.setText("🟢 Роутер в сети • Xray " + (result.xrayRunning ? "работает" : "остановлен"));
        tvConnectionStatus.setTextColor(0xFF10B981);

        tvServersBanner.setText("Подключено серверов: " + result.servers.size() + " • Xray активен");
        tvServersBanner.setTextColor(0xFF10B981);

        containerOtherDevices.removeAllViews();

        for (ApiClient.DeviceInfo dev : result.devices) {
            if ("dune_kuh".equals(dev.id) || "192.168.1.29".equals(dev.ip) || "192.168.1.145".equals(dev.ip)) {
                renderDeviceCard(dev, tvDuneKuhTitle, tvDuneKuhIp, tvDuneKuhServer, badgeDuneKuh,
                        btnKuhSmart, btnKuhFull, btnKuhOff);
            } else if ("dune_komnata".equals(dev.id) || "192.168.1.53".equals(dev.ip)) {
                renderDeviceCard(dev, tvDuneKomnataTitle, tvDuneKomnataIp, tvDuneKomnataServer, badgeDuneKomnata,
                        btnKomnataSmart, btnKomnataFull, btnKomnataOff);
            } else {
                addOtherDeviceView(dev);
            }
        }

        renderServersList(result.servers);
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

    // ==========================================
    // TAB 2: SERVERS LIST & ACTIONS (HAPP STYLE)
    // ==========================================
    private void renderServersList(List<ApiClient.ServerInfo> servers) {
        containerServers.removeAllViews();

        if (servers == null || servers.isEmpty()) {
            TextView emptyTv = new TextView(this);
            emptyTv.setText("Список серверов пуст.\nНажмите «➕ Подписка», чтобы добавить ссылку.");
            emptyTv.setTextColor(0xFF94A3B8);
            emptyTv.setGravity(Gravity.CENTER);
            emptyTv.setPadding(20, 40, 20, 40);
            containerServers.addView(emptyTv);
            return;
        }

        // Determine which server is assigned to which device
        Map<String, List<String>> assignedMap = new HashMap<>();
        if (lastStatus != null && lastStatus.devices != null) {
            for (ApiClient.DeviceInfo d : lastStatus.devices) {
                if (d.serverId != null && !d.serverId.isEmpty()) {
                    List<String> list = assignedMap.get(d.serverId);
                    if (list == null) {
                        list = new ArrayList<>();
                        assignedMap.put(d.serverId, list);
                    }
                    list.add(d.name.contains("Кухня") ? "Кухня" : (d.name.contains("Комната") ? "Комната" : d.name));
                }
            }
        }

        // Group servers by group name (e.g. YummyVPN, ph-cu-s)
        Map<String, List<ApiClient.ServerInfo>> groups = new LinkedHashMap<>();
        for (ApiClient.ServerInfo s : servers) {
            String grp = s.group != null && !s.group.isEmpty() ? s.group : "VPN Серверы";
            List<ApiClient.ServerInfo> list = groups.get(grp);
            if (list == null) {
                list = new ArrayList<>();
                groups.put(grp, list);
            }
            list.add(s);
        }

        for (Map.Entry<String, List<ApiClient.ServerInfo>> entry : groups.entrySet()) {
            String groupName = entry.getKey();
            List<ApiClient.ServerInfo> groupServers = entry.getValue();

            // Group Header View
            LinearLayout groupHeader = new LinearLayout(this);
            groupHeader.setOrientation(LinearLayout.HORIZONTAL);
            groupHeader.setGravity(Gravity.CENTER_VERTICAL);
            groupHeader.setPadding(0, 16, 0, 8);

            TextView tvGrpTitle = new TextView(this);
            tvGrpTitle.setText("📦 " + groupName);
            tvGrpTitle.setTextColor(0xFF38BDF8);
            tvGrpTitle.setTextSize(14);
            tvGrpTitle.setTypeface(null, android.graphics.Typeface.BOLD);
            LinearLayout.LayoutParams p1 = new LinearLayout.LayoutParams(0, LinearLayout.LayoutParams.WRAP_CONTENT, 1f);
            tvGrpTitle.setLayoutParams(p1);
            groupHeader.addView(tvGrpTitle);

            TextView tvGrpCount = new TextView(this);
            tvGrpCount.setText(groupServers.size() + " серв.");
            tvGrpCount.setTextColor(0xFF94A3B8);
            tvGrpCount.setTextSize(12);
            groupHeader.addView(tvGrpCount);

            containerServers.addView(groupHeader);

            // Server Cards in this group
            for (final ApiClient.ServerInfo srv : groupServers) {
                View serverCard = createServerCardView(srv, assignedMap.get(srv.id));
                containerServers.addView(serverCard);
            }
        }
    }

    private View createServerCardView(final ApiClient.ServerInfo srv, List<String> assignedDevices) {
        LinearLayout card = new LinearLayout(this);
        card.setOrientation(LinearLayout.VERTICAL);
        card.setBackgroundResource(R.drawable.card_bg);
        card.setPadding(30, 26, 30, 26);
        LinearLayout.LayoutParams params = new LinearLayout.LayoutParams(
                LinearLayout.LayoutParams.MATCH_PARENT, LinearLayout.LayoutParams.WRAP_CONTENT);
        params.setMargins(0, 0, 0, 18);
        card.setLayoutParams(params);

        // TOP ROW: Icon + Name + Ping Badge
        LinearLayout topRow = new LinearLayout(this);
        topRow.setOrientation(LinearLayout.HORIZONTAL);
        topRow.setGravity(Gravity.CENTER_VERTICAL);

        TextView tvName = new TextView(this);
        tvName.setText(srv.name);
        tvName.setTextColor(0xFFF8FAFC);
        tvName.setTextSize(15);
        tvName.setTypeface(null, android.graphics.Typeface.BOLD);
        LinearLayout.LayoutParams pName = new LinearLayout.LayoutParams(0, LinearLayout.LayoutParams.WRAP_CONTENT, 1f);
        tvName.setLayoutParams(pName);
        topRow.addView(tvName);

        TextView tvPing = new TextView(this);
        Integer pingVal = serverPings.get(srv.id);
        if (pingVal == null) pingVal = srv.ping;

        if (pingVal == -2) {
            tvPing.setText("—");
            tvPing.setTextColor(0xFF94A3B8);
            tvPing.setBackgroundResource(R.drawable.badge_gray);
        } else if (pingVal == -1) {
            tvPing.setText("🔴 Таймаут");
            tvPing.setTextColor(0xFFEF4444);
            tvPing.setBackgroundResource(R.drawable.badge_red);
        } else if (pingVal < 100) {
            tvPing.setText("🟢 " + pingVal + " ms");
            tvPing.setTextColor(0xFF10B981);
            tvPing.setBackgroundResource(R.drawable.badge_green);
        } else {
            tvPing.setText("🟡 " + pingVal + " ms");
            tvPing.setTextColor(0xFFF59E0B);
            tvPing.setBackgroundResource(R.drawable.badge_yellow);
        }
        tvPing.setTextSize(11);
        tvPing.setTypeface(null, android.graphics.Typeface.BOLD);
        tvPing.setPadding(16, 6, 16, 6);
        topRow.addView(tvPing);

        card.addView(topRow);

        // SUB ROW: Protocol details + Assigned devices tags
        String protoDetails = srv.protocol.toUpperCase() + " / " + srv.transport.toUpperCase()
                + ("reality".equalsIgnoreCase(srv.security) ? " / REALITY" : ("tls".equalsIgnoreCase(srv.security) ? " / TLS" : ""));

        TextView tvDetails = new TextView(this);
        tvDetails.setText(protoDetails + " • " + srv.address + ":" + srv.port);
        tvDetails.setTextColor(0xFF94A3B8);
        tvDetails.setTextSize(12);
        tvDetails.setPadding(0, 6, 0, 10);
        card.addView(tvDetails);

        // Assigned devices badge if any
        if (assignedDevices != null && !assignedDevices.isEmpty()) {
            TextView tvAssigned = new TextView(this);
            tvAssigned.setText("📺 Активен для: " + String.join(", ", assignedDevices));
            tvAssigned.setTextColor(0xFFC084FC);
            tvAssigned.setBackgroundResource(R.drawable.badge_purple);
            tvAssigned.setTextSize(11);
            tvAssigned.setTypeface(null, android.graphics.Typeface.BOLD);
            tvAssigned.setPadding(16, 6, 16, 6);
            LinearLayout.LayoutParams lpAss = new LinearLayout.LayoutParams(
                    LinearLayout.LayoutParams.WRAP_CONTENT, LinearLayout.LayoutParams.WRAP_CONTENT);
            lpAss.setMargins(0, 0, 0, 12);
            tvAssigned.setLayoutParams(lpAss);
            card.addView(tvAssigned);
        }

        // Test status if tested
        if (srv.testStatus != null && !srv.testStatus.isEmpty()) {
            TextView tvTest = new TextView(this);
            tvTest.setText(srv.testStatus);
            tvTest.setTextColor(srv.testStatus.contains("200") || srv.testStatus.contains("работает") ? 0xFF10B981 : 0xFFEF4444);
            tvTest.setTextSize(12);
            tvTest.setPadding(0, 0, 0, 10);
            card.addView(tvTest);
        }

        // ACTIONS ROW: Test Youtube + Assign to TVs
        LinearLayout actRow = new LinearLayout(this);
        actRow.setOrientation(LinearLayout.HORIZONTAL);

        Button btnTestChannel = new Button(this);
        btnTestChannel.setText("⚡ Тест канала");
        btnTestChannel.setTextSize(11);
        btnTestChannel.setTypeface(null, android.graphics.Typeface.BOLD);
        btnTestChannel.setTextColor(0xFFF8FAFC);
        btnTestChannel.setBackgroundResource(R.drawable.btn_blue);
        LinearLayout.LayoutParams ap1 = new LinearLayout.LayoutParams(0, 105, 1.2f);
        ap1.setMargins(0, 0, 8, 0);
        btnTestChannel.setLayoutParams(ap1);
        btnTestChannel.setOnClickListener(v -> {
            vibrate();
            runTestServer(srv);
        });
        actRow.addView(btnTestChannel);

        Button btnAssignAll = new Button(this);
        btnAssignAll.setText("📺 На оба ТВ");
        btnAssignAll.setTextSize(11);
        btnAssignAll.setTypeface(null, android.graphics.Typeface.BOLD);
        btnAssignAll.setTextColor(0xFFF8FAFC);
        btnAssignAll.setBackgroundResource(R.drawable.btn_purple);
        LinearLayout.LayoutParams ap2 = new LinearLayout.LayoutParams(0, 105, 1.1f);
        ap2.setMargins(0, 0, 8, 0);
        btnAssignAll.setLayoutParams(ap2);
        btnAssignAll.setOnClickListener(v -> {
            vibrate();
            assignServerAsync(srv.id, "all", srv.name + " -> Все приставки");
        });
        actRow.addView(btnAssignAll);

        Button btnAssignKuh = new Button(this);
        btnAssignKuh.setText("🍳 Кухня");
        btnAssignKuh.setTextSize(10);
        btnAssignKuh.setTypeface(null, android.graphics.Typeface.BOLD);
        btnAssignKuh.setTextColor(0xFFCBD5E1);
        btnAssignKuh.setBackgroundResource(R.drawable.btn_gray);
        LinearLayout.LayoutParams ap3 = new LinearLayout.LayoutParams(0, 105, 0.9f);
        ap3.setMargins(0, 0, 6, 0);
        btnAssignKuh.setLayoutParams(ap3);
        btnAssignKuh.setOnClickListener(v -> {
            vibrate();
            assignServerAsync(srv.id, "kuh", srv.name + " -> Кухня");
        });
        actRow.addView(btnAssignKuh);

        Button btnAssignKomnata = new Button(this);
        btnAssignKomnata.setText("🛋️ Комната");
        btnAssignKomnata.setTextSize(10);
        btnAssignKomnata.setTypeface(null, android.graphics.Typeface.BOLD);
        btnAssignKomnata.setTextColor(0xFFCBD5E1);
        btnAssignKomnata.setBackgroundResource(R.drawable.btn_gray);
        LinearLayout.LayoutParams ap4 = new LinearLayout.LayoutParams(0, 105, 0.9f);
        btnAssignKomnata.setLayoutParams(ap4);
        btnAssignKomnata.setOnClickListener(v -> {
            vibrate();
            assignServerAsync(srv.id, "komnata", srv.name + " -> Комната");
        });
        actRow.addView(btnAssignKomnata);

        card.addView(actRow);

        return card;
    }

    private void runPingAll() {
        tvServersBanner.setText("⚡ Измерение пинга до всех серверов...");
        tvServersBanner.setTextColor(0xFF3B82F6);

        new AsyncTask<Void, Void, Map<String, Integer>>() {
            @Override
            protected Map<String, Integer> doInBackground(Void... voids) {
                return ApiClient.pingAllServers(MainActivity.this);
            }

            @Override
            protected void onPostExecute(Map<String, Integer> pings) {
                serverPings.putAll(pings);
                tvServersBanner.setText("⚡ Пинг обновлен для " + pings.size() + " серверов");
                tvServersBanner.setTextColor(0xFF10B981);
                if (lastStatus != null && lastStatus.servers != null) {
                    renderServersList(lastStatus.servers);
                }
            }
        }.execute();
    }

    private void runTestServer(final ApiClient.ServerInfo srv) {
        tvServersBanner.setText("Тестирование канала: " + srv.name + " (YouTube)...");
        tvServersBanner.setTextColor(0xFF3B82F6);
        Toast.makeText(this, "Тестирование туннеля " + srv.name + "...", Toast.LENGTH_SHORT).show();

        new AsyncTask<Void, Void, ApiClient.TestResult>() {
            @Override
            protected ApiClient.TestResult doInBackground(Void... voids) {
                return ApiClient.testVpnServer(MainActivity.this, srv.id);
            }

            @Override
            protected void onPostExecute(ApiClient.TestResult res) {
                if (res.success) {
                    srv.testStatus = "✅ " + res.message;
                    Toast.makeText(MainActivity.this, "✅ " + srv.name + ": " + res.message, Toast.LENGTH_LONG).show();
                    tvServersBanner.setText("✅ " + srv.name + ": Туннель активен (" + res.latency + " мс)");
                    tvServersBanner.setTextColor(0xFF10B981);
                } else {
                    srv.testStatus = "❌ " + (res.error != null ? res.error : "Ошибка туннеля");
                    Toast.makeText(MainActivity.this, "❌ " + srv.name + ": " + srv.testStatus, Toast.LENGTH_LONG).show();
                    tvServersBanner.setText("❌ " + srv.name + ": " + srv.testStatus);
                    tvServersBanner.setTextColor(0xFFEF4444);
                }
                if (lastStatus != null && lastStatus.servers != null) {
                    renderServersList(lastStatus.servers);
                }
            }
        }.execute();
    }

    private void runRefreshSubscriptions() {
        tvServersBanner.setText("🔄 Обновление подписок со стороны провайдеров...");
        tvServersBanner.setTextColor(0xFF3B82F6);

        new AsyncTask<Void, Void, String>() {
            @Override
            protected String doInBackground(Void... voids) {
                return ApiClient.refreshAllSubscriptions(MainActivity.this);
            }

            @Override
            protected void onPostExecute(String msg) {
                Toast.makeText(MainActivity.this, msg, Toast.LENGTH_LONG).show();
                loadStatus();
            }
        }.execute();
    }

    private void assignServerAsync(final String serverId, final String target, final String desc) {
        tvServersBanner.setText("Назначение: " + desc + "...");
        tvServersBanner.setTextColor(0xFF3B82F6);

        new AsyncTask<Void, Void, Boolean>() {
            @Override
            protected Boolean doInBackground(Void... voids) {
                return ApiClient.assignServer(MainActivity.this, serverId, target);
            }

            @Override
            protected void onPostExecute(Boolean success) {
                if (success) {
                    Toast.makeText(MainActivity.this, "Сервер назначен: " + desc, Toast.LENGTH_SHORT).show();
                } else {
                    Toast.makeText(MainActivity.this, "Ошибка назначения сервера", Toast.LENGTH_SHORT).show();
                }
                loadStatus();
            }
        }.execute();
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
