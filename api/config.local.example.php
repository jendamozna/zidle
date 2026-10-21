<?php
// Copy to config.local.php and fill in. SMTP, the contact e-mail/phone and PUBLIC_URL
// go to api/.env (copy .env.example); a key set here would override the .env value.
return [
    'DB_HOST' => '127.0.0.1',
    'DB_NAME' => 'zidle',
    'DB_USER' => 'zidle',
    'DB_PASS' => 'change-me',

    'BANK_IBAN' => 'CZ0000000000000000000000',
    'BANK_ACCOUNT_DISPLAY' => '000000000/0000',
    'PAYMENT_RECIPIENT' => 'Farnost',
    'PAYMENT_SPECIFIC_SYMBOL' => '2026',

    'ADMIN_PASSWORD' => 'change-me', // master login (empty e-mail) – needed to invite the first accountant
    'ORGANIZER_PASSWORD' => '',  // optional master password for all runs; organizers normally use invite links from admin
    'TICKET_SECRET' => 'paste output of: php -r "echo bin2hex(random_bytes(32));"',

    // E-mails (SMTP), contact e-mail and phone, PUBLIC_URL: in api/.env (see .env.example).
];
