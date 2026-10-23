<?php
// Default configuration. Override values in config.local.php (not committed)
// or through environment variables of the same name.

return (static function (): array {
    $defaults = [
        'DB_HOST' => '127.0.0.1',
        'DB_PORT' => '3306',
        'DB_NAME' => 'zidle',
        'DB_USER' => 'zidle',
        'DB_PASS' => '',

        'SEAT_PRICE' => 300,             // CZK per adult seat (from 18 years)
        'CHILD_SEAT_PRICE' => 150,       // CZK per child seat (4–17 years); up to 3 years on a lap for free, no seat
        'PAYMENT_DEADLINE_HOURS' => 72,  // due date shown to the customer
        'PAYMENT_GRACE_HOURS' => 48,     // unpaid reservations are cancelled this long after the due date (bank transfer delay)
        'REFUND_DAYS' => 14,             // refunds are promised within this many days (e-mails)
        'DATA_RETENTION_DAYS' => 30,     // personal data is deleted this many days after the start of the last run
        'MAX_SEATS_PER_RESERVATION' => 20,

        // Calendar event (udalost.ics) attached to the ticket e-mail
        'EVENT_LOCATION' => '',          // place shown in the calendar, e.g. "Kostel sv. Václava, Náměstí 1, Praha"; '' = none
        'EVENT_DURATION_MINUTES' => 120, // end of the calendar event = run start + this

        // Spam / bot protection
        // Seats one IP may reserve (so one script can't hold a whole run); without ALTCHA a quarter of it.
        // Generous enough for several families booking from one shared network (parish Wi-Fi).
        'SEATS_PER_IP_PER_HOUR' => 40,
        'SEATS_PER_IP_PER_DAY' => 80,
        'ALTCHA_CHALLENGES_PER_IP_PER_HOUR' => 300,
        'CODE_CHECKS_PER_IP_PER_HOUR' => 30,   // discount codes checked in the form ("Použít"), per IP
        'PENDING_RESERVATIONS_PER_EMAIL' => 2, // unpaid reservations one e-mail may hold at once in one run
        'LOGIN_ATTEMPTS_PER_15_MIN' => 10,     // admin and scanner login, invitation, forgotten password, per IP
        'FORM_MIN_SECONDS' => 3,               // reservation sent sooner after loading the page = bot
        // Invisible ALTCHA proof-of-work on the reservation form
        'ALTCHA_ENABLED' => true,
        'ALTCHA_COST' => 1000,                 // PBKDF2 iterations per attempt
        'ALTCHA_COUNTER_MAX' => 6000,          // attempts needed: random between max/3 and max (~1–2 s on a computer)

        // Bank account for the QR payment (Czech "QR Platba" / SPD format)
        'BANK_ACCOUNT' => '',           // required, Czech account "[prefix-]number/bank", e.g. 19-2000145399/0800; the IBAN is derived from it
        'BANK_BIC' => '',
        'PAYMENT_RECIPIENT' => 'Farnost',
        'PAYMENT_MESSAGE' => 'Těšín zpívá gospel',
        'PAYMENT_VARIABLE_SYMBOL' => '3211', // variable symbol (VS) of all payments, max 10 digits; each reservation has its own specific symbol (SS)

        // One-time token for api/deploy.php, which creates the first Správce (admin account) while
        // none exists; at least 32 characters, '' = deploy disabled. Everybody else is invited in the admin.
        'DEPLOY_TOKEN' => '',
        // Check-in window around the start of a run; outside it the scanner warns
        // and checks in only after an explicit confirmation.
        'SCAN_WINDOW_BEFORE_MINUTES' => 60,
        'SCAN_WINDOW_AFTER_MINUTES' => 60,
        // Secret for signing ticket QR codes (long random string, never change after tickets are sent)
        'TICKET_SECRET' => '',

        // E-mails to customers. With SMTP_HOST set they are sent via SMTP, otherwise via PHP mail().
        'MAIL_ENABLED' => false,
        'MAIL_FROM' => 'rezervace@example.com', // sender for PHP mail() (SMTP uses SMTP_SENDER)
        'MAIL_FROM_NAME' => 'Těšín zpívá gospel', // sender name (From, Reply-To, calendar organizer)
        'SMTP_HOST' => '',               // e.g. smtp.example.com; '' = use PHP mail()
        'SMTP_PORT' => 587,              // 465 = implicit TLS (SMTPS); others use STARTTLS when offered
        'SMTP_AUTH' => true,             // log in with SMTP_USER (or SMTP_SENDER) / SMTP_PASSWORD
        'SMTP_SENDER' => '',             // sender address (From), e.g. rezervace@example.com
        'SMTP_USER' => '',               // SMTP login when it differs from SMTP_SENDER (e.g. 'apikey'); '' = SMTP_SENDER
        'SMTP_PASSWORD' => '',

        // Contact of the organizers: shown in the website footer and in every e-mail;
        // CONTACT_EMAIL is also the Reply-To address of e-mails.
        'CONTACT_EMAIL' => '',
        'CONTACT_PHONE' => '',
        'PUBLIC_URL' => '',              // e.g. https://example.com/zidle/ – used for links in e-mails

        // Folder for the PHP error log (php-error-YYYY-MM.log). Best outside the web root;
        // the default api/logs/ is closed to the web by .htaccess. '' = the server's own log.
        'LOG_DIR' => __DIR__ . '/logs',
        // Show uncaught errors on the 500 page (with file paths) – only briefly, to find a problem
        // when the log cannot be read; never leave on.
        'DEBUG' => false,

        // Allowed CORS origin when the frontend runs on another domain ('' = same origin only)
        'CORS_ORIGIN' => '',
    ];

    $local = is_file(__DIR__ . '/config.local.php') ? require __DIR__ . '/config.local.php' : [];

    $config = [];
    foreach ($defaults as $key => $value) {
        $env = getenv($key);
        $config[$key] = $local[$key] ?? ($env !== false ? $env : $value);
    }

    return $config;
})();
