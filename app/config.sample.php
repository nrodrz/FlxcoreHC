<?php
/**
 * FlxCoreHC configuration. Copy to app/config.php and edit.
 * On cPanel, create the database and user in "MySQL Databases" first.
 */
return [
    // Database (cPanel format is usually cpaneluser_dbname / cpaneluser_dbuser)
    'db_host' => 'localhost',
    'db_name' => 'flxcorehc',
    'db_user' => 'flxcorehc',
    'db_pass' => 'CHANGE-ME',

    // Absolute path for uploaded credential documents — MUST be outside public_html.
    // 'production' (default) fails closed: crypto keys required, no error details in browser.
    // Set to 'dev' ONLY for local development.
    'app_env'   => 'production',

    // Set true if the app runs behind a TLS-terminating proxy that doesn't set HTTPS/X-Forwarded-Proto.
    'force_secure_cookies' => false,

    'docs_path' => __DIR__ . '/storage/docs',

    // Reminders: when false, cron logs emails instead of sending (safe default).
    'mail_enabled' => false,

    // When false, the public /register page is closed and new organizations are
    // created only by a platform administrator. Set true for open self-service signup.
    'allow_open_registration' => false,

    // Billing (Stripe). Leave blank to disable billing UI. Map each plan to a Stripe
    // Price ID under Platform admin -> Manage plans, and point a webhook at /webhooks/stripe.
    'app_url' => 'https://yourdomain.com',
    'stripe_secret_key' => '',
    'stripe_publishable_key' => '',
    'stripe_webhook_secret' => '',
    'trial_days' => 14,   // free trial length for new orgs (0 = none)
    'grace_days' => 7,    // days after a failed payment before the account goes read-only

    // Pricing lever: do producer self-service logins consume a plan seat?
    //   true  — per-seat pricing; every producer login counts against max_users
    //   false — producer logins are free; only owner/manager/staff/agent seats count
    'producer_seats_billable' => true,
    'mail_from'    => 'alerts@yourdomain.com',

    // Reminder schedule: days before expiry at which reminder e-mails go out (whole numbers 1-365, at most 8).
    // Leave this line out to use the default, 60, 30, 14 and 7. (v1.172.3: an older, never-used setting of a different name was removed.)
    // 'reminder_thresholds' => [60, 30, 14, 7],

    // Credentials move to "expiring" status this many days before expiry.
    'expiring_window_days' => 90,

    // PHI/PII encryption keys (audit finding C1). Generate with:
    //   php database/generate_keys.php
    // Leave empty in dev to run in passthrough (UNENCRYPTED) mode.
    // In production these MUST be set, and config.php MUST live outside the web root.
    'crypto_data_key'  => '',
    'crypto_index_key' => '',

    // Session timeouts (audit H4)
    'session_idle_seconds'     => 1800,   // sign out after 30 min inactivity
    'session_absolute_seconds' => 43200,  // hard sign-out after 12 h
];
