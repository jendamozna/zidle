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

        'SEAT_PRICE' => 300,             // CZK per seat
        'PAYMENT_DEADLINE_HOURS' => 72,  // due date shown to the customer
        'PAYMENT_GRACE_HOURS' => 48,     // unpaid reservations are cancelled this long after the due date (bank transfer delay)
        'REFUND_DAYS' => 14,             // refunds are promised within this many days (e-mails)
        'DATA_RETENTION_DAYS' => 30,     // personal data is deleted this many days after the start of the last run
        'MAX_SEATS_PER_RESERVATION' => 20,

        // Spam / bot protection
        'RESERVATIONS_PER_IP_PER_HOUR' => 5,
        'PENDING_RESERVATIONS_PER_EMAIL' => 2, // unpaid reservations one e-mail may hold at once in one run
        'LOGIN_ATTEMPTS_PER_15_MIN' => 10,     // admin and organizer login, per IP
        'FORM_MIN_SECONDS' => 3,               // reservation sent sooner after loading the page = bot
        // Invisible ALTCHA proof-of-work on the reservation form
        'ALTCHA_ENABLED' => true,
        'ALTCHA_COST' => 1000,                 // PBKDF2 iterations per attempt
        'ALTCHA_COUNTER_MAX' => 6000,          // attempts needed: random between max/3 and max (~1–2 s on a computer)

        // Bank account for the QR payment (Czech "QR Platba" / SPD format)
        'BANK_IBAN' => '',              // required, e.g. CZ6508000000192000145399
        'BANK_BIC' => '',
        'BANK_ACCOUNT_DISPLAY' => '',   // e.g. 19-2000145399/0800
        'PAYMENT_RECIPIENT' => 'Farnost',
        'PAYMENT_MESSAGE' => 'Moje zidle 2026',
        'PAYMENT_SPECIFIC_SYMBOL' => '', // specific symbol (SS) used for all payments, max 10 digits

        // Admin page (admin.php) – used by the accountant to confirm payments
        'ADMIN_PASSWORD' => '',
        // Ticket scanner (scanner.html): organizers sign in with invite links from admin.
        // Optional master password for all runs ('' = disabled).
        'ORGANIZER_PASSWORD' => '',
        // Check-in window around the start of a run; outside it the scanner warns
        // and checks in only after an explicit confirmation.
        'SCAN_WINDOW_BEFORE_MINUTES' => 60,
        'SCAN_WINDOW_AFTER_MINUTES' => 60,
        // Secret for signing ticket QR codes (long random string, never change after tickets are sent)
        'TICKET_SECRET' => '',

        // E-mails to customers. With SMTP_HOST set they are sent via SMTP, otherwise via PHP mail().
        'MAIL_ENABLED' => false,
        'MAIL_FROM' => 'rezervace@example.com', // sender for PHP mail() (SMTP uses SMTP_SENDER)
        'SMTP_HOST' => '',               // e.g. smtp.example.com; '' = use PHP mail()
        'SMTP_PORT' => 587,              // 465 = implicit TLS (SMTPS); others use STARTTLS when offered
        'SMTP_AUTH' => true,             // log in with SMTP_SENDER / SMTP_PASSWORD
        'SMTP_SENDER' => '',             // sender address and SMTP login, e.g. rezervace@example.com
        'SMTP_PASSWORD' => '',
        'PUBLIC_URL' => '',              // e.g. https://example.com/zidle/ – used for links in e-mails

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
