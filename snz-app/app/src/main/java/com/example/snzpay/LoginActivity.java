package com.example.snzpay;

import android.app.Activity;
import android.content.Intent;
import android.graphics.Color;
import android.os.Bundle;
import android.view.View;
import android.widget.Button;
import android.widget.EditText;
import android.widget.TextView;

/** লাইসেন্স কী দিয়ে লগইন */
public class LoginActivity extends Activity {

    private EditText etKey;
    private Button btnLogin;
    private TextView tvMsg;

    @Override
    protected void onCreate(Bundle b) {
        super.onCreate(b);
        CrashLog.install(this);
        if (Session.loggedIn(this)) {
            openMain();
            return;
        }
        setContentView(R.layout.activity_login);
        etKey = findViewById(R.id.etKey);
        btnLogin = findViewById(R.id.btnLogin);
        tvMsg = findViewById(R.id.tvMsg);
        Ui.fitSystemBars(findViewById(R.id.loginRoot));

        Ui.box(findViewById(R.id.loginCard), Ui.CARD, Ui.LINE, 22);
        Ui.box(etKey, Ui.INPUT, Ui.LINE, 14);
        Ui.box(btnLogin, Ui.BRAND, null, 14);

        String why = Session.takeReason(this);
        if (!why.isEmpty()) show(why, Ui.AMBER);

        btnLogin.setOnClickListener(v -> doLogin());
    }

    private void doLogin() {
        final String key = etKey.getText().toString();
        btnLogin.setEnabled(false);
        btnLogin.setText("CHECKING…");
        tvMsg.setVisibility(View.GONE);
        new Thread(() -> {
            final String err = KeyCheck.login(getApplicationContext(), key);
            runOnUiThread(() -> {
                if (isFinishing()) return;
                btnLogin.setEnabled(true);
                btnLogin.setText("LOGIN");
                if (err == null) openMain();
                else show(err, Ui.RED);
            });
        }).start();
    }

    private void show(String msg, String color) {
        tvMsg.setText(msg);
        tvMsg.setTextColor(Color.parseColor(color));
        tvMsg.setVisibility(View.VISIBLE);
    }

    private void openMain() {
        startActivity(new Intent(this, MainActivity.class));
        finish();
    }
}
