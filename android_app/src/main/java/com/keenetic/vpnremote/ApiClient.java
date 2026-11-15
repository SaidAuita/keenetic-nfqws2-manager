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
import java.util.List;

public class ApiClient {
    public static final String PREFS_NAME = "keenetic_vpn_prefs";
    public static final String KEY_ROUTER_HOST = "router_host";
    public static final String DEFAULT_ROUTER_HOST = "192.168.1.1:90";

    public static class DeviceInfo {
        public String id;
        public String name;
        public String ip;
        public String icon;
        public String serverName;
        public String mode; // "smart", "full", "off"
        public boolean enabled;
        public boolean iptablesActive;

        public DeviceInfo(String id, String name, String ip, String icon, String serverName, String mode, boolean enabled) {
            this.id = id;
            this.name = name;
            this.ip = ip;
            this.icon = icon != null && !icon.isEmpty() ? icon : "📺";
            this.serverName = serverName != null ? serverName : "VLESS Reality";
            this.mode = mode != null ? mode : (enabled ? "smart" : "off");
            this.enabled = !"off".equals(this.mode);
            this.iptablesActive = this.enabled;
        }
    }

    public static class StatusResult {
        public boolean success;
        public boolean xrayRunning;
        public List<DeviceInfo> devices = new ArrayList<>();
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
        conn.setConnectTimeout(4000);
        conn.setReadTimeout(5000);
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
                            d.optString("server_name", "VLESS Reality"),
                            mode,
                            enabled
                    );
                    dev.iptablesActive = d.optBoolean("iptables_active", dev.enabled);
                    res.devices.add(dev);
                }
            }
        } catch (Exception e) {
            res.success = false;
            res.errorMessage = e.getMessage();
        }
        return res;
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
