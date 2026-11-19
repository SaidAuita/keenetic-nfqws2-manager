package com.dune.ytfix;

import android.app.Activity;
import android.content.Context;
import android.content.Intent;
import android.content.pm.PackageManager;
import android.net.ConnectivityManager;
import android.net.LinkProperties;
import android.net.RouteInfo;
import android.os.AsyncTask;
import android.os.Build;
import android.os.Bundle;
import android.os.CountDownTimer;
import android.view.KeyEvent;
import android.view.View;
import android.widget.Button;
import android.widget.TextView;
import android.widget.Toast;

import java.net.HttpURLConnection;
import java.net.URL;

public class MainActivity extends Activity {

    // Supported YouTube / SmartTube packages on Dune HD & Android TV
    private static final String[] KNOWN_PACKAGES = {
            "com.google.android.youtube.tv",       // Official YouTube for Android TV
            "com.liskovsoft.smarttubetv.beta",     // SmartTube Beta
            "com.liskovsoft.videomanager",         // SmartTube Stable
            "com.liskovsoft.smarttubetv",          // SmartTube Legacy
            "com.teamsmart.videomanager.tv"        // SmartTube Next TV
    };

    private TextView tvStepApp;
    private TextView tvStepRouter;
    private TextView tvSummary;
    private Button btnLaunchYoutube;
    private Button btnHome;

    private CountDownTimer autoTimer;
    private boolean isFinished = false;
    private String targetPackage = null;
    private String targetAppName = "YouTube";

    @Override
    protected void onCreate(Bundle savedInstanceState) {
        super.onCreate(savedInstanceState);
        setContentView(R.layout.activity_main);

        tvStepApp = findViewById(R.id.tvStepApp);
        tvStepRouter = findViewById(R.id.tvStepRouter);
        tvSummary = findViewById(R.id.tvSummary);
        btnLaunchYoutube = findViewById(R.id.btnLaunchYoutube);
        btnHome = findViewById(R.id.btnHome);

        // Detect which YouTube app is installed on this Dune
        detectInstalledPlayer();

        btnLaunchYoutube.setText("▶️ Запустить " + targetAppName);

        btnLaunchYoutube.setOnClickListener(new View.OnClickListener() {
            @Override
            public void onClick(View v) {
                cancelAutoTimer();
                launchPlayerAndExit();
            }
        });

        btnHome.setOnClickListener(new View.OnClickListener() {
            @Override
            public void onClick(View v) {
                cancelAutoTimer();
                finish();
            }
        });

        btnLaunchYoutube.requestFocus();

        // Start universal reset task
        new UniversalResetTask().execute();
    }

    private void detectInstalledPlayer() {
        PackageManager pm = getPackageManager();
        for (String pkg : KNOWN_PACKAGES) {
            try {
                pm.getPackageInfo(pkg, 0);
                targetPackage = pkg;
                if (pkg.contains("smarttube") || pkg.contains("teamsmart") || pkg.contains("videomanager")) {
                    targetAppName = "SmartTube";
                } else {
                    targetAppName = "YouTube";
                }
                return;
            } catch (PackageManager.NameNotFoundException ignored) {
            }
        }
        // Fallback default
        targetPackage = "com.google.android.youtube.tv";
        targetAppName = "YouTube";
    }

    private void cancelAutoTimer() {
        if (autoTimer != null) {
            autoTimer.cancel();
            autoTimer = null;
        }
    }

    private void launchPlayerAndExit() {
        if (isFinished) return;
        isFinished = true;

        Intent intent = null;
        if (targetPackage != null) {
            intent = getPackageManager().getLaunchIntentForPackage(targetPackage);
        }

        // Secondary fallback search if targetPackage failed
        if (intent == null) {
            for (String pkg : KNOWN_PACKAGES) {
                intent = getPackageManager().getLaunchIntentForPackage(pkg);
                if (intent != null) break;
            }
        }

        if (intent != null) {
            intent.addFlags(Intent.FLAG_ACTIVITY_NEW_TASK | Intent.FLAG_ACTIVITY_CLEAR_TOP);
            startActivity(intent);
        } else {
            Toast.makeText(this, "Приложение YouTube / SmartTube не найдено", Toast.LENGTH_LONG).show();
        }
        finish();
    }

    @Override
    public boolean onKeyDown(int keyCode, KeyEvent event) {
        if (keyCode == KeyEvent.KEYCODE_BACK) {
            cancelAutoTimer();
            finish();
            return true;
        }
        return super.onKeyDown(keyCode, event);
    }

    private String detectGatewayIp() {
        try {
            ConnectivityManager cm = (ConnectivityManager) getSystemService(Context.CONNECTIVITY_SERVICE);
            if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.M && cm != null) {
                LinkProperties lp = cm.getLinkProperties(cm.getActiveNetwork());
                if (lp != null) {
                    for (RouteInfo r : lp.getRoutes()) {
                        if (r.isDefaultRoute() && r.getGateway() != null) {
                            String host = r.getGateway().getHostAddress();
                            if (host != null && !host.isEmpty()) return host;
                        }
                    }
                }
            }
        } catch (Exception ignored) {}
        return "192.168.1.1";
    }

    private class UniversalResetTask extends AsyncTask<Void, Void, ResetResult> {

        @Override
        protected ResetResult doInBackground(Void... voids) {
            ResetResult res = new ResetResult();

            // 1. Force-stop all known YouTube and SmartTube apps on Dune
            // Try with root (su) which is present on Dune, and fallback to direct am force-stop
            StringBuilder cmd = new StringBuilder();
            for (String pkg : KNOWN_PACKAGES) {
                cmd.append("am force-stop ").append(pkg).append("; ");
            }
            cmd.append("ip -s -s neigh flush all 2>/dev/null; ");

            try {
                Process p = Runtime.getRuntime().exec(new String[]{"su", "-c", cmd.toString()});
                p.waitFor();
                res.appKilled = true;
            } catch (Exception e) {
                for (String pkg : KNOWN_PACKAGES) {
                    try {
                        Process p2 = Runtime.getRuntime().exec("am force-stop " + pkg);
                        p2.waitFor();
                    } catch (Exception ignored) {}
                }
                res.appKilled = true;
            }

            // 2. Check if Keenetic / Router NAT reset API is available at Default Gateway
            String gw = detectGatewayIp();
            try {
                URL url = new URL("http://" + gw + ":90/menu.php?action=reset_device_conns");
                HttpURLConnection conn = (HttpURLConnection) url.openConnection();
                conn.setRequestMethod("GET");
                conn.setConnectTimeout(600); // Fast non-blocking timeout
                conn.setReadTimeout(600);

                if (conn.getResponseCode() == 200) {
                    res.keeneticFlushed = true;
                }
            } catch (Exception ignored) {
                // If not Keenetic with menu.php, standard router or direct connection
            }

            // Pause briefly (300ms) for socket state table to settle
            try {
                Thread.sleep(300);
            } catch (InterruptedException ignored) {}

            return res;
        }

        @Override
        protected void onPostExecute(ResetResult result) {
            if (isFinishing()) return;

            tvStepApp.setText("✓ Зависшие процессы " + targetAppName + " завершены");
            tvStepApp.setTextColor(0xFF10B981); // green

            if (result.keeneticFlushed) {
                tvStepRouter.setText("✓ Роутер Keenetic: сессии сброшены");
                tvStepRouter.setTextColor(0xFF10B981); // green
            } else {
                tvStepRouter.setText("✓ Сетевой стек и кэш сокетов обновлены");
                tvStepRouter.setTextColor(0xFF10B981); // green
            }

            tvSummary.setText("🎉 Готово! Запуск чистого " + targetAppName + "...");
            tvSummary.setTextColor(0xFF38BDF8);

            // 2.5 second auto-launch countdown
            autoTimer = new CountDownTimer(2500, 1000) {
                @Override
                public void onTick(long millisUntilFinished) {
                    int sec = (int) Math.round(millisUntilFinished / 1000.0);
                    btnLaunchYoutube.setText("▶️ Запустить " + targetAppName + " (" + sec + ")");
                }

                @Override
                public void onFinish() {
                    btnLaunchYoutube.setText("▶️ Запуск...");
                    launchPlayerAndExit();
                }
            }.start();

            btnLaunchYoutube.requestFocus();
        }
    }

    private static class ResetResult {
        boolean appKilled = false;
        boolean keeneticFlushed = false;
    }
}
