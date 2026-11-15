package com.keenetic.vpnremote;

import android.content.Context;
import android.content.SharedPreferences;
import org.json.JSONArray;
import org.json.JSONObject;

import java.io.BufferedReader;
import java.io.InputStreamReader;
import java.io.OutputStream;
import java.net.HttpURLConnection;
import java.net.URL;
import java.net.URLEncoder;
import java.nio.charset.StandardCharsets;
import java.util.ArrayList;
import java.util.HashMap;
import java.util.Iterator;
import java.util.List;
import java.util.Map;

public class ApiClient {
    public static final String PREFS_NAME = "keenetic_vpn_prefs";
    public static final String KEY_ROUTER_HOST = "router_host";
    public static final String DEFAULT_ROUTER_HOST = "192.168.1.1:90";

    public static class DeviceInfo {
        public String id;
        public String name;
        public String ip;
        public String icon;
        public String serverId;
        public String serverName;
        public String mode; // "smart", "full", "off"
        public boolean enabled;
        public boolean iptablesActive;

        public DeviceInfo(String id, String name, String ip, String icon, String serverId, String serverName, String mode, boolean enabled) {
            this.id = id;
            this.name = name;
            this.ip = ip;
            this.icon = icon != null && !icon.isEmpty() ? icon : "📺";
            this.serverId = serverId != null ? serverId : "";
            this.serverName = serverName != null ? serverName : "VLESS Reality";
            this.mode = mode != null ? mode : (enabled ? "smart" : "off");
            this.enabled = !"off".equals(this.mode);
            this.iptablesActive = this.enabled;
        }
    }

    public static class ServerInfo {
        public String id;
        public String name;
        public String group;
        public String protocol;
        public String transport;
        public String security;
        public String address;
        public int port;
        public String subUrl;
        public int ping = -2; // -2: not tested, -1: timeout, >=0: ms
        public String testStatus = "";
    }

    public static class TestResult {
        public boolean success;
        public String httpCode = "";
        public int latency = 0;
        public String message = "";
        public String error = "";
    }

    public static class StatusResult {
        public boolean success;
        public boolean xrayRunning;
        public List<DeviceInfo> devices = new ArrayList<>();
        public List<ServerInfo> servers = new ArrayList<>();
        public String errorMessage;
    }

    public static String getRouterHost(Context context) {
        SharedPreferences sp = context.getSharedPreferences(PREFS_NAME, Context.MODE_PRIVATE);
        return sp.getString(KEY_ROUTER_HOST, DEFAULT_ROUTER_HOST);
    }

    public static void setRouterHost(Context context, String host) {
        SharedPreferences sp = context.getSharedPreferences(PREFS_NAME, Context.MODE_PRIVATE);
        sp.edit().putString(KEY_ROUTER_HOST, host.trim()).apply();
    }

    public static String getBaseUrl(Context context) {
        String host = getRouterHost(context);
        if (!host.startsWith("http://") && !host.startsWith("https://")) {
            host = "http://" + host;
        }
        if (!host.endsWith("/menu.php")) {
            if (!host.endsWith("/")) host += "/";
            host += "menu.php";
        }
        return host;
    }

    private static String post(Context context, String postData) throws Exception {
        String urlStr = getBaseUrl(context);
        URL url = new URL(urlStr);
        HttpURLConnection conn = (HttpURLConnection) url.openConnection();
        conn.setRequestMethod("POST");
        conn.setConnectTimeout(6000);
        conn.setReadTimeout(12000);
        conn.setDoOutput(true);
        conn.setDoInput(true);
        conn.setRequestProperty("Content-Type", "application/x-www-form-urlencoded");

        try (OutputStream os = conn.getOutputStream()) {
            os.write(postData.getBytes(StandardCharsets.UTF_8));
            os.flush();
        }

        int code = conn.getResponseCode();
        if (code != 200) {
            throw new Exception("HTTP error code: " + code);
        }

        StringBuilder sb = new StringBuilder();
        try (BufferedReader br = new BufferedReader(new InputStreamReader(conn.getInputStream(), StandardCharsets.UTF_8))) {
            String line;
            while ((line = br.readLine()) != null) {
                sb.append(line).append('\n');
            }
        }
        return sb.toString();
    }

    public static StatusResult fetchStatus(Context context) {
        StatusResult res = new StatusResult();
        try {
            String jsonStr = post(context, "action=get_vpn_status");
            JSONObject obj = new JSONObject(jsonStr);
            res.success = obj.optBoolean("success", false);
            res.xrayRunning = obj.optBoolean("xray_running", false);

            JSONArray arr = obj.optJSONArray("devices");
            if (arr != null) {
                for (int i = 0; i < arr.length(); i++) {
                    JSONObject d = arr.getJSONObject(i);
                    boolean enabled = d.optBoolean("enabled", false);
                    String mode = d.optString("mode", enabled ? "smart" : "off");
                    DeviceInfo dev = new DeviceInfo(
                            d.optString("id"),
                            d.optString("name"),
                            d.optString("ip"),
                            d.optString("icon", "📺"),
                            d.optString("server", ""),
                            d.optString("server_name", "VLESS Reality"),
                            mode,
                            enabled
                    );
                    dev.iptablesActive = d.optBoolean("iptables_active", dev.enabled);
                    res.devices.add(dev);
                }
            }

            JSONArray srvArr = obj.optJSONArray("servers");
            if (srvArr != null) {
                for (int i = 0; i < srvArr.length(); i++) {
                    JSONObject s = srvArr.getJSONObject(i);
                    ServerInfo srv = new ServerInfo();
                    srv.id = s.optString("id");
                    srv.name = s.optString("name");
                    srv.group = s.optString("group", "VPN");
                    srv.protocol = s.optString("protocol", "vless");
                    srv.transport = s.optString("transport", "tcp");
                    srv.security = s.optString("security", "none");
                    srv.address = s.optString("address", "");
                    srv.port = s.optInt("port", 443);
                    srv.subUrl = s.optString("sub_url", "");
                    res.servers.add(srv);
                }
            }
        } catch (Exception e) {
            res.success = false;
            res.errorMessage = e.getMessage();
        }
        return res;
    }

    public static Map<String, Integer> pingAllServers(Context context) {
        Map<String, Integer> map = new HashMap<>();
        try {
            String jsonStr = post(context, "action=ping_all_servers");
            JSONObject obj = new JSONObject(jsonStr);
            if (obj.optBoolean("success", false)) {
                JSONObject pingObj = obj.optJSONObject("ping");
                if (pingObj != null) {
                    Iterator<String> keys = pingObj.keys();
                    while (keys.hasNext()) {
                        String key = keys.next();
                        map.put(key, pingObj.optInt(key, -1));
                    }
                }
            }
        } catch (Exception ignored) {}
        return map;
    }

    public static TestResult testVpnServer(Context context, String serverId) {
        TestResult res = new TestResult();
        try {
            String postData = "action=test_vpn_server&id=" + URLEncoder.encode(serverId, "UTF-8");
            String jsonStr = post(context, postData);
            JSONObject obj = new JSONObject(jsonStr);
            res.success = obj.optBoolean("success", false);
            res.httpCode = obj.optString("http_code", "");
            res.latency = obj.optInt("latency", 0);
            res.message = obj.optString("message", "");
            res.error = obj.optString("error", "");
        } catch (Exception e) {
            res.success = false;
            res.error = e.getMessage();
        }
        return res;
    }

    public static String refreshAllSubscriptions(Context context) {
        try {
            String jsonStr = post(context, "action=refresh_all_subscriptions");
            JSONObject obj = new JSONObject(jsonStr);
            if (obj.optBoolean("success", false)) {
                return obj.optString("message", "Подписки успешно обновлены!");
            } else {
                return "Ошибка: " + obj.optString("error", "Сбой обновления");
            }
        } catch (Exception e) {
            return "Ошибка: " + e.getMessage();
        }
    }

    public static boolean importSubscription(Context context, String url, String group) {
        try {
            String postData = "action=import_subscription&url=" + URLEncoder.encode(url, "UTF-8")
                    + (group != null && !group.isEmpty() ? "&group=" + URLEncoder.encode(group, "UTF-8") : "");
            String jsonStr = post(context, postData);
            JSONObject obj = new JSONObject(jsonStr);
            return obj.optBoolean("success", false);
        } catch (Exception e) {
            return false;
        }
    }

    public static boolean assignServer(Context context, String serverId, String target) {
        try {
            String postData = "action=assign_server_to_devices&server_id=" + URLEncoder.encode(serverId, "UTF-8")
                    + "&target=" + URLEncoder.encode(target, "UTF-8");
            String jsonStr = post(context, postData);
            JSONObject obj = new JSONObject(jsonStr);
            return obj.optBoolean("success", false);
        } catch (Exception e) {
            return false;
        }
    }

    public static boolean setDeviceMode(Context context, String deviceId, String mode) {
        try {
            String postData = "action=set_device_vpn_mode&id=" + URLEncoder.encode(deviceId, "UTF-8")
                    + "&mode=" + URLEncoder.encode(mode, "UTF-8");
            String jsonStr = post(context, postData);
            JSONObject obj = new JSONObject(jsonStr);
            return obj.optBoolean("success", false);
        } catch (Exception e) {
            return false;
        }
    }

    public static boolean toggleDevice(Context context, String deviceId, boolean enable) {
        return setDeviceMode(context, deviceId, enable ? "smart" : "off");
    }

    public static boolean toggleAll(Context context, boolean enable, String mode) {
        try {
            String postData = "action=toggle_all_vpn&enable=" + (enable ? "1" : "0")
                    + (mode != null && !mode.isEmpty() ? "&mode=" + URLEncoder.encode(mode, "UTF-8") : "");
            String jsonStr = post(context, postData);
            JSONObject obj = new JSONObject(jsonStr);
            if (obj.optBoolean("success", false)) {
                return true;
            }
        } catch (Exception ignored) {}

        // Fallback
        StatusResult status = fetchStatus(context);
        if (!status.success || status.devices.isEmpty()) {
            return false;
        }
        boolean allOk = true;
        for (DeviceInfo dev : status.devices) {
            boolean ok = setDeviceMode(context, dev.id, enable ? (mode != null ? mode : "smart") : "off");
            if (!ok) allOk = false;
        }
        return allOk;
    }
}
