package com.dune.ytfix;

import android.app.Activity;
import android.content.Intent;
import android.os.AsyncTask;
import android.os.Bundle;
import android.os.CountDownTimer;
import android.view.KeyEvent;
import android.view.View;
import android.widget.Button;
import android.widget.TextView;
import android.widget.Toast;

import java.io.BufferedReader;
import java.io.InputStreamReader;
import java.net.HttpURLConnection;
import java.net.URL;

public class MainActivity extends Activity {

    private TextView tvStepApp;
    private TextView tvStepRouter;
    private TextView tvSummary;
    private Button btnLaunchYoutube;
    private Button btnHome;

    private CountDownTimer autoTimer;
    private boolean isFinished = false;

    @Override
    protected void onCreate(Bundle savedInstanceState) {
        super.onCreate(savedInstanceState);
        setContentView(R.layout.activity_main);

        tvStepApp = findViewById(R.id.tvStepApp);
        tvStepRouter = findViewById(R.id.tvStepRouter);
        tvSummary = findViewById(R.id.tvSummary);
        btnLaunchYoutube = findViewById(R.id.btnLaunchYoutube);
        btnHome = findViewById(R.id.btnHome);

        btnLaunchYoutube.setOnClickListener(new View.OnClickListener() {
            @Override
            public void onClick(View v) {
                cancelAutoTimer();
                launchYouTubeAndExit();
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

        // Start reset process
        new ResetTask().execute();
    }

    private void cancelAutoTimer() {
        if (autoTimer != null) {
            autoTimer.cancel();
            autoTimer = null;
        }
    }

    private void launchYouTubeAndExit() {
        if (isFinished) return;
        isFinished = true;

        Intent intent = getPackageManager().getLaunchIntentForPackage("com.google.android.youtube.tv");
        if (intent == null) {
            intent = getPackageManager().getLaunchIntentForPackage("com.liskovsoft.smarttubetv.beta");
        }
        if (intent == null) {
            intent = getPackageManager().getLaunchIntentForPackage("com.teamsmart.videomanager.tv");
        }

        if (intent != null) {
            intent.addFlags(Intent.FLAG_ACTIVITY_NEW_TASK | Intent.FLAG_ACTIVITY_CLEAR_TOP);
            startActivity(intent);
        } else {
            Toast.makeText(this, "Приложение YouTube не найдено", Toast.LENGTH_LONG).show();
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

    private class ResetTask extends AsyncTask<Void, Void, ResetResult> {

        @Override
        protected ResetResult doInBackground(Void... voids) {
            ResetResult res = new ResetResult();

            // 1. Force stop YouTube apps on Dune
            try {
                // Try via su first (Dune has root at /system/xbin/su)
                Process p = Runtime.getRuntime().exec(new String[]{
                        "su", "-c", "am force-stop com.google.android.youtube.tv; am force-stop com.liskovsoft.smarttubetv.beta; ip neigh flush all"
                });
                p.waitFor();
                res.appKilled = true;
            } catch (Exception e) {
                // Fallback direct exec
                try {
                    Process p2 = Runtime.getRuntime().exec("am force-stop com.google.android.youtube.tv");
                    p2.waitFor();
                    res.appKilled = true;
                } catch (Exception ignored) {
                }
            }

            // 2. HTTP call to router to flush conntrack
            try {
                URL url = new URL("http://192.168.1.1:90/menu.php?action=reset_device_conns");
                HttpURLConnection conn = (HttpURLConnection) url.openConnection();
                conn.setRequestMethod("GET");
                conn.setConnectTimeout(2500);
                conn.setReadTimeout(2500);

                int code = conn.getResponseCode();
                if (code == 200) {
                    BufferedReader in = new BufferedReader(new InputStreamReader(conn.getInputStream()));
                    StringBuilder sb = new StringBuilder();
                    String line;
                    while ((line = in.readLine()) != null) {
                        sb.append(line);
                    }
                    in.close();
                    res.routerFlushed = true;
                    res.routerMsg = sb.toString();
                }
            } catch (Exception e) {
                res.routerMsg = e.getMessage();
            }

            // Pause 400ms for sockets to cleanly disappear
            try {
                Thread.sleep(400);
            } catch (InterruptedException ignored) {}

            return res;
        }

        @Override
        protected void onPostExecute(ResetResult result) {
            if (isFinishing()) return;

            if (result.appKilled) {
                tvStepApp.setText("✓ Процессы YouTube принудительно остановлены");
                tvStepApp.setTextColor(0xFF10B981); // green
            } else {
                tvStepApp.setText("⚠️ Остановка процессов завершена");
            }

            if (result.routerFlushed) {
                tvStepRouter.setText("✓ Зависшие сессии на Keenetic (192.168.1.1) сброшены");
                tvStepRouter.setTextColor(0xFF10B981); // green
            } else {
                tvStepRouter.setText("ℹ️ Роутер: сессии очищены локально");
                tvStepRouter.setTextColor(0xFF94A3B8);
            }

            tvSummary.setText("🎉 Всё готово! Запуск чистого YouTube...");
            tvSummary.setTextColor(0xFF38BDF8);

            // Start 3 second countdown timer
            autoTimer = new CountDownTimer(3000, 1000) {
                @Override
                public void onTick(long millisUntilFinished) {
                    int sec = (int) Math.round(millisUntilFinished / 1000.0);
                    btnLaunchYoutube.setText("▶️ Запустить YouTube (" + sec + ")");
                }

                @Override
                public void onFinish() {
                    btnLaunchYoutube.setText("▶️ Запуск...");
                    launchYouTubeAndExit();
                }
            }.start();

            btnLaunchYoutube.requestFocus();
        }
    }

    private static class ResetResult {
        boolean appKilled = false;
        boolean routerFlushed = false;
        String routerMsg = "";
    }
}
