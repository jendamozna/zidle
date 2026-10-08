<?php
// Customer e-mails (payment details, reminder, expiry, cancellation, tickets).
// Delivery: SMTP when SMTP_HOST is set (PHPMailer), otherwise PHP mail().
declare(strict_types=1);

use PHPMailer\PHPMailer\Exception as MailerException;
use PHPMailer\PHPMailer\PHPMailer;

const MAIL_FROM_NAME = 'Moje židle 2026';

/**
 * Sends one e-mail. $html is optional (multipart/alternative with $text);
 * $inline are embedded images: [['cid' => 'ticket-qr', 'data' => <binary>, 'name' => 'x.png', 'type' => 'image/png']].
 * Returns false (and logs the reason) when the message could not be handed over.
 */
function deliver_mail(string $to, string $subject, string $text, ?string $html = null, array $inline = []): bool
{
    require_once __DIR__ . '/../vendor/autoload.php';
    $mail = new PHPMailer(true);
    try {
        $mail->CharSet = PHPMailer::CHARSET_UTF8;
        $mail->Timeout = 15;
        $host = trim((string) config('SMTP_HOST'));
        if ($host !== '') {
            $port = (int) config('SMTP_PORT');
            $mail->isSMTP();
            $mail->Host = $host;
            $mail->Port = $port;
            $mail->SMTPAuth = filter_var(config('SMTP_AUTH'), FILTER_VALIDATE_BOOLEAN);
            $mail->Username = (string) (config('SMTP_USER') ?: config('SMTP_SENDER'));
            $mail->Password = (string) config('SMTP_PASSWORD');
            // 465 = implicit TLS; other ports use STARTTLS whenever the server offers it.
            $mail->SMTPSecure = $port === 465 ? PHPMailer::ENCRYPTION_SMTPS : '';
            $mail->SMTPAutoTLS = true;
            // Never let a slow or silent SMTP server block a request for long
            // (PHPMailer otherwise waits up to 300 s for each reply).
            $mail->getSMTPInstance()->Timelimit = 15;
            $from = (string) config('SMTP_SENDER');
        } else {
            $mail->isMail();
            $from = (string) config('MAIL_FROM');
        }
        $mail->setFrom($from, MAIL_FROM_NAME);
        if (filter_var((string) config('CONTACT_EMAIL'), FILTER_VALIDATE_EMAIL)) {
            $mail->addReplyTo((string) config('CONTACT_EMAIL'), MAIL_FROM_NAME);
        }
        $mail->addAddress($to);
        $mail->Subject = $subject;
        if ($html !== null) {
            $mail->isHTML(true);
            $mail->Body = $html;
            $mail->AltBody = $text;
        } else {
            $mail->Body = $text;
        }
        foreach ($inline as $file) {
            $mail->addStringEmbeddedImage($file['data'], $file['cid'], $file['name'], PHPMailer::ENCODING_BASE64, $file['type']);
        }
        return $mail->send();
    } catch (MailerException $e) {
        error_log('[zidle] Mail to ' . $to . ' failed: ' . $mail->ErrorInfo);
        return false;
    }
}

function mail_enabled(): bool
{
    return filter_var(config('MAIL_ENABLED'), FILTER_VALIDATE_BOOLEAN);
}

/** "Kontakt: farnost@example.com · +420 …" or '' when no contact is configured. */
function contact_line(): string
{
    $parts = array_filter([trim((string) config('CONTACT_EMAIL')), trim((string) config('CONTACT_PHONE'))]);
    return $parts ? 'Kontakt: ' . implode(' · ', $parts) : '';
}

/** Public contact for the website footer. */
function contact_public(): array
{
    return [
        'email' => trim((string) config('CONTACT_EMAIL')) ?: null,
        'phone' => trim((string) config('CONTACT_PHONE')) ?: null,
    ];
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
    $lines = (int) $r['paid_amount'] > 0
        ? ['Už jsme přijali: ' . format_czk((int) $r['paid_amount'] - (int) $r['refund_amount']) . ' z ' . format_czk((int) $r['amount'])]
        : [];
    $lines = [
        ...$lines,
        'Částka: ' . format_czk(amount_due($r)),
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
    $body = implode("\n", array_merge(
        ["Dobrý den, {$r['first_name']} {$r['last_name']},", ''],
        $lines,
        ['', 'Moje židle 2026'],
        contact_line() !== '' ? [contact_line()] : []
    ));
    $ok = deliver_mail($r['email'], $subject . ' – Moje židle 2026', $body);
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
    $missing = (int) $r['paid_amount'] > 0
        ? 'zatím nám chybí část platby za Vaši rezervaci ('
        : 'zatím jsme neobdrželi platbu za Vaši rezervaci (';
    return send_customer_email($r, 'Připomínka platby', array_merge(
        [$missing . implode('; ', seat_labels($r['seats'])) . ').', run_line($r), '', 'Platební údaje:'],
        payment_lines($r),
        ['', 'Pokud platba nedorazí, rezervace bude zrušena a místa uvolněna.',
         'Pokud jste již zaplatili, považujte tuto zprávu za bezpředmětnou.'],
        $link ? ['', "QR kód pro platbu: {$link}"] : []
    ));
}

/** Part of the price arrived: what is still missing and the payment details. */
function send_partial_payment_email(array $r, int $received): bool
{
    $link = reservation_link($r);
    return send_customer_email($r, 'Přijata část platby', array_merge(
        ['přijali jsme Vaši platbu ' . format_czk($received) . ' (VS ' . $r['variable_symbol'] . '), na celou částku ale chybí '
            . format_czk(amount_due($r)) . '.', run_line($r), '', 'Doplaťte prosím se stejným variabilním symbolem:'],
        payment_lines($r),
        ['', 'Vstupenku pošleme, jakmile dorazí celá částka.'],
        $link ? ['', "QR kód pro doplatek: {$link}"] : []
    ));
}

/** Money arrived that can't be used for the reservation ($reason: key of REFUND_REASONS); it goes back. */
function send_payment_refund_email(array $r, int $received, string $reason): bool
{
    $due = (int) $r['refund_amount'] - (int) $r['refunded_amount'];
    return send_customer_email($r, 'Vrácení platby', [
        'přijali jsme Vaši platbu ' . format_czk($received) . ' (VS ' . $r['variable_symbol'] . '), ale '
            . REFUND_REASONS[$reason] . '.',
        run_line($r),
        '',
        'Částku ' . format_czk($due) . ' Vám do ' . (int) config('REFUND_DAYS')
            . ' dnů pošleme zpět na účet, ze kterého platba přišla.',
    ]);
}

function send_expiry_email(array $r): bool
{
    $base = rtrim((string) config('PUBLIC_URL'), '/');
    return send_customer_email($r, 'Rezervace zrušena', array_merge(
        ['platba za Vaši rezervaci (VS ' . $r['variable_symbol'] . ') nedorazila včas, proto byla rezervace zrušena a místa uvolněna:',
         implode('; ', seat_labels($r['seats'])) . '.',
         run_line($r)],
        (int) $r['refund_amount'] > (int) $r['refunded_amount']
            ? ['', 'Částku ' . format_czk((int) $r['refund_amount'] - (int) $r['refunded_amount']) . ', která už dorazila, Vám do '
                . (int) config('REFUND_DAYS') . ' dnů pošleme zpět na účet, ze kterého platba přišla.']
            : [],
        ['', 'Pokud platba ještě dorazí a místa budou stále volná, rezervaci obnovíme; jinak Vám peníze pošleme zpět na účet, ze kterého přišly.'],
        $base !== '' ? ['', "Nová rezervace: {$base}/"] : []
    ));
}
