<?php
// Copy to config.local.php and fill in.
return [
    'DB_HOST' => '127.0.0.1',
    'DB_NAME' => 'zidle',
    'DB_USER' => 'zidle',
    'DB_PASS' => 'change-me',

    'BANK_IBAN' => 'CZ0000000000000000000000',
    'BANK_ACCOUNT_DISPLAY' => '000000000/0000',
    'PAYMENT_RECIPIENT' => 'Farnost',
    'PAYMENT_SPECIFIC_SYMBOL' => '2026',

    // Fill in before the first start – the app refuses empty, short or placeholder values.
    'ADMIN_PASSWORD' => '',      // master login (empty e-mail), min. 12 characters – needed to invite the first accountant
    'ORGANIZER_PASSWORD' => '',  // optional master password for all runs (min. 12 characters); organizers normally use invite links from admin
    'TICKET_SECRET' => '',       // min. 32 characters, e.g. the output of: php -r "echo bin2hex(random_bytes(32));"

    // E-mails via SMTP
    'MAIL_ENABLED' => true,
    'SMTP_HOST' => 'smtp.example.com',
    'SMTP_PORT' => 587,                // 465 = implicit TLS (SMTPS), otherwise STARTTLS when offered
    'SMTP_AUTH' => true,
    'SMTP_SENDER' => 'rezervace@example.com',
    'SMTP_USER' => '',                 // only when the login differs from SMTP_SENDER
    'SMTP_PASSWORD' => 'change-me',

    // Contact of the organizers: website footer and every e-mail (Reply-To = CONTACT_EMAIL)
    'CONTACT_EMAIL' => 'farnost@example.com',
    'CONTACT_PHONE' => '+420 123 456 789',
    'PUBLIC_URL' => 'https://example.com/zidle/',
];
