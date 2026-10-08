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
        'PAYMENT_DEADLINE_HOURS' => 72,  // unpaid reservations are cancelled after this
        'MAX_SEATS_PER_RESERVATION' => 20,

        // Bank account for the QR payment (Czech "QR Platba" / SPD format)
        'BANK_IBAN' => '',              // required, e.g. CZ6508000000192000145399
        'BANK_BIC' => '',
        'BANK_ACCOUNT_DISPLAY' => '',   // e.g. 19-2000145399/0800
        'PAYMENT_RECIPIENT' => 'Farnost',
        'PAYMENT_MESSAGE' => 'Moje zidle 2026',
        'PAYMENT_SPECIFIC_SYMBOL' => '', // specific symbol (SS) used for all payments, max 10 digits

        // Admin page (admin.php) – used by the accountant to confirm payments
        'ADMIN_PASSWORD' => '',
        // Ticket scanner (scanner.html) – used by organizers at the entrance
        'ORGANIZER_PASSWORD' => '',
        // Secret for signing ticket QR codes (long random string, never change after tickets are sent)
        'TICKET_SECRET' => '',

        // Confirmation e-mail with payment instructions (uses PHP mail())
        'MAIL_ENABLED' => false,
        'MAIL_FROM' => 'rezervace@example.com',
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
