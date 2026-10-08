<?php
// Plain-text customer e-mails (payment details, reminder, expiry, cancellation).
declare(strict_types=1);

function mail_enabled(): bool
{
    return filter_var(config('MAIL_ENABLED'), FILTER_VALIDATE_BOOLEAN);
}

function format_czk(int $amount): string
{
    return number_format($amount, 0, ',', ' ') . ' Kč';
}

function format_prague(string $utc): string
{
    return (new DateTimeImmutable($utc, new DateTimeZone('UTC')))
        ->setTimezone(new DateTimeZone('Europe/Prague'))->format('j. n. Y H:i');
}

function reservation_link(array $r): ?string
{
    $base = rtrim((string) config('PUBLIC_URL'), '/');
    return $base === '' ? null : $base . '/?r=' . $r['token'];
}

/** "Termín: so 19. 12. 2026 18:00" for the reservation's run. */
function run_line(array $r): string
{
    $run = run_by_id((int) $r['run_id']);
    return 'Termín: ' . ($run ? run_label($run) : '–');
}

function payment_lines(array $r): array
{
    $lines = [
        'Částka: ' . format_czk((int) $r['amount']),
        'Účet: ' . config('BANK_ACCOUNT_DISPLAY') . ' (IBAN ' . config('BANK_IBAN') . ')',
        'Variabilní symbol: ' . $r['variable_symbol'],
    ];
    if (specific_symbol() !== '') {
        $lines[] = 'Specifický symbol: ' . specific_symbol();
    }
    $lines[] = 'Splatnost: ' . format_prague($r['expires_at']);
    return $lines;
}

/** Sends a plain-text e-mail to the reservation's customer; false when not sent. */
function send_customer_email(array $r, string $subject, array $lines): bool
{
    if (!mail_enabled() || $r['email'] === '') {
        return false;
    }
    $body = implode("\r\n", array_merge(
        ["Dobrý den, {$r['first_name']} {$r['last_name']},", ''],
        $lines,
        ['', 'Moje židle 2026']
    ));
    $headers = implode("\r\n", [
        'From: ' . config('MAIL_FROM'),
        'MIME-Version: 1.0',
        'Content-Type: text/plain; charset=UTF-8',
        'Content-Transfer-Encoding: 8bit',
    ]);
    $encodedSubject = '=?UTF-8?B?' . base64_encode($subject . ' – Moje židle 2026') . '?=';
    $ok = @mail($r['email'], $encodedSubject, $body, $headers);
    if (!$ok) {
        error_log("[zidle] Failed to send '{$subject}' for reservation {$r['id']}");
    }
    return $ok;
}

function send_payment_email(array $r): void
{
    $link = reservation_link($r);
    send_customer_email($r, 'Rezervace míst', array_merge(
        ['děkujeme za rezervaci míst: ' . implode('; ', seat_labels($r['seats'])) . '.', run_line($r), '', 'Platební údaje:'],
        payment_lines($r),
        ['', 'Pokud platba nedorazí, rezervace bude zrušena a místa uvolněna.'],
        $link ? ['', "QR kód pro platbu a zrušení rezervace: {$link}"] : []
    ));
}

function send_reminder_email(array $r): bool
{
    $link = reservation_link($r);
    return send_customer_email($r, 'Připomínka platby', array_merge(
        ['zatím jsme neobdrželi platbu za Vaši rezervaci (' . implode('; ', seat_labels($r['seats'])) . ').', run_line($r), '', 'Platební údaje:'],
        payment_lines($r),
        ['', 'Pokud platba nedorazí, rezervace bude zrušena a místa uvolněna.',
         'Pokud jste již zaplatili, považujte tuto zprávu za bezpředmětnou.'],
        $link ? ['', "QR kód pro platbu: {$link}"] : []
    ));
}

function send_expiry_email(array $r): bool
{
    $base = rtrim((string) config('PUBLIC_URL'), '/');
    return send_customer_email($r, 'Rezervace zrušena', array_merge(
        ['platba za Vaši rezervaci (VS ' . $r['variable_symbol'] . ') nedorazila včas, proto byla rezervace zrušena a místa uvolněna:',
         implode('; ', seat_labels($r['seats'])) . '.',
         run_line($r),
         '',
         'Pokud jste platbu přesto odeslali, ozvěte se nám prosím – vyřešíme to.'],
        $base !== '' ? ['', "Nová rezervace: {$base}/"] : []
    ));
}
