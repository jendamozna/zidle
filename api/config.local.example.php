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
    'PAYMENT_VARIABLE_SYMBOL' => '3211', // VS of all payments; reservations are told apart by their SS

    // Fill in before the first start (min. 32 characters each), e.g. the output of: php -r "echo bin2hex(random_bytes(32));"
    'TICKET_SECRET' => '',
    'DEPLOY_TOKEN' => '',        // for POST api/deploy.php that creates the first Správce; may be removed afterwards

    // E-mails via SMTP
    'MAIL_ENABLED' => true,
    'MAIL_FROM_NAME' => 'Těšín zpívá gospel', // sender name
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

    // PHP error log php-error-YYYY-MM.log; best a folder outside the web root (default api/logs/, closed by .htaccess)
    // 'LOG_DIR' => '/home/user/logs/zidle',
    // 'DEBUG' => true,             // only briefly: the 500 page shows the error itself
];
