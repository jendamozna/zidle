<?php
// Customer e-mails (payment details, reminder, expiry, cancellation, tickets).
// Delivery: SMTP when SMTP_HOST is set (PHPMailer), otherwise PHP mail().
declare(strict_types=1);

use PHPMailer\PHPMailer\Exception as MailerException;
use PHPMailer\PHPMailer\PHPMailer;

const MAIL_FROM_NAME = 'Koncert TZG 2026';
const MAIL_INK = '#140a24';
const MAIL_MUTED = '#5f5570';
const MAIL_ACCENT = '#c42aae';

/**
 * "Připomínka platby" → "TZG 2026: Připomínka platby" (the sender is already "Koncert TZG 2026");
 * "[TEST] TZG 2026: …" when $r is a reservation on a test run.
 */
function mail_subject(string $subject, ?array $r = null): string
{
    return ($r !== null && reservation_is_test($r) ? '[TEST] ' : '') . 'TZG 2026: ' . $subject;
}

/** The one HTML layout of every e-mail: header with $subtitle, white card with $content, optional $footer. */
function mail_layout(string $content, string $subtitle = '', string $footer = ''): string
{
    $h = static fn ($v) => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
    return '<!doctype html><html lang="cs"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width"></head>'
        . '<body style="margin:0;background:#fbf6f1;font-family:Arial,Helvetica,sans-serif;color:' . MAIL_INK . '">'
        . '<div style="max-width:520px;margin:0 auto;padding:24px 16px">'
        . '<p style="margin:0 0 4px;font-size:22px;font-weight:bold">Koncert TZG <span style="color:' . MAIL_ACCENT . '">2026</span></p>'
        . ($subtitle !== '' ? '<p style="margin:0 0 16px;color:' . MAIL_MUTED . '">' . $h($subtitle) . '</p>' : '<div style="height:12px"></div>')
        . '<div style="background:#ffffff;border-radius:18px;padding:24px;font-size:15px;line-height:1.55">' . $content . '</div>'
        . ($footer !== '' ? '<p style="margin:16px 0 0;text-align:center;color:' . MAIL_MUTED . ';font-size:13px;line-height:1.5">' . $footer . '</p>' : '')
        . '</div></body></html>';
}

/** Plain e-mail text → HTML for mail_layout(): paragraphs, line breaks, links; indented lines (codes) stand out. */
function mail_text_html(string $text): string
{
    $html = '';
    foreach (preg_split('/\n{2,}/', trim($text)) ?: [] as $paragraph) {
        $lines = array_map(static function (string $line): string {
            $safe = htmlspecialchars(trim($line), ENT_QUOTES, 'UTF-8');
            if (str_starts_with($line, '    ')) {
                return '<strong style="font-family:Consolas,Menlo,monospace;font-size:20px;letter-spacing:0.08em">' . $safe . '</strong>';
            }
            return preg_replace('~https?://[^\s<]*[^\s<.,;:)]~', '<a href="$0" style="color:' . MAIL_ACCENT . '">$0</a>', $safe) ?? $safe;
        }, explode("\n", $paragraph));
        $html .= '<p style="margin:0 0 14px">' . implode('<br>', $lines) . '</p>';
    }
    return $html;
}

/**
 * Sends one e-mail: $text plus HTML ($html, or $text in mail_layout() when null).
 * $inline are embedded images: [['cid' => 'ticket-qr', 'data' => <binary>, 'name' => 'x.png', 'type' => 'image/png']];
 * $attachments are files: [['data' => <binary>, 'name' => 'udalost.ics', 'type' => 'text/calendar; method=REQUEST']].
 * Returns false (and logs the reason) when the message could not be handed over.
 */
function deliver_mail(string $to, string $subject, string $text, ?string $html = null, array $inline = [], array $attachments = []): bool
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
        } else {
            $mail->isMail();
        }
        $mail->setFrom(mail_sender(), MAIL_FROM_NAME);
        if (filter_var((string) config('CONTACT_EMAIL'), FILTER_VALIDATE_EMAIL)) {
            $mail->addReplyTo((string) config('CONTACT_EMAIL'), MAIL_FROM_NAME);
        }
        $mail->addAddress($to);
        $mail->Subject = $subject;
        $mail->isHTML(true);
        $mail->Body = $html ?? mail_layout(mail_text_html($text), preg_replace('/^(\[TEST\] )?TZG 2026: /', '', $subject) ?? '');
        $mail->AltBody = $text;
        foreach ($inline as $file) {
            $mail->addStringEmbeddedImage($file['data'], $file['cid'], $file['name'], PHPMailer::ENCODING_BASE64, $file['type']);
        }
        foreach ($attachments as $file) {
            $mail->addStringAttachment($file['data'], $file['name'], PHPMailer::ENCODING_BASE64, $file['type']);
        }
        return $mail->send();
    } catch (MailerException $e) {
        app_log('Mail to ' . $to . ' failed: ' . $mail->ErrorInfo);
        return false;
    } catch (Throwable $e) {
        // E.g. mail() disabled by the host: the caller must still get false, never a 500.
        app_log('Mail to ' . $to . ' failed: ' . $e);
        return false;
    }
}

/** Sender address (From): SMTP_SENDER with SMTP, otherwise MAIL_FROM. */
function mail_sender(): string
{
    return (string) config(trim((string) config('SMTP_HOST')) !== '' ? 'SMTP_SENDER' : 'MAIL_FROM');
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
    return new DateTimeImmutable($utc, new DateTimeZone('UTC'))
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
        'Účet: ' . config('BANK_ACCOUNT_DISPLAY'), // no IBAN – it is in the QR code on the reservation page
        'Variabilní symbol: ' . payment_variable_symbol(),
        'Specifický symbol: ' . $r['specific_symbol'],
    ];
    $lines[] = 'Splatnost: ' . format_prague($r['expires_at']);
    return $lines;
}

/** Sends an e-mail (text + mail_layout() HTML) to the reservation's customer; false when not sent. */
function send_customer_email(array $r, string $subject, array $lines, array $attachments = []): bool
{
    if (!mail_enabled() || $r['email'] === '') {
        return false;
    }
    $body = implode("\n", array_merge(
        ["Dobrý den, {$r['first_name']} {$r['last_name']},", ''],
        $lines,
        ['', 'Koncert TZG 2026'],
        contact_line() !== '' ? [contact_line()] : []
    ));
    $ok = deliver_mail($r['email'], mail_subject($subject, $r), $body, null, [], $attachments);
    if (!$ok) {
        app_log("Failed to send '{$subject}' for reservation {$r['id']}");
    }
    return $ok;
}

/** QR Platba image as an attachment "QR_platba_<SS>.png", or null without BANK_IBAN. */
function payment_qr_attachment(array $r): ?array
{
    if (trim((string) config('BANK_IBAN')) === '') {
        return null;
    }
    return ['data' => qr_png(spd_string($r)), 'name' => 'QR_platba_' . $r['specific_symbol'] . '.png', 'type' => 'image/png'];
}

function send_payment_email(array $r): void
{
    $link = reservation_link($r);
    $qr = payment_qr_attachment($r);
    send_customer_email($r, 'Rezervace míst', array_merge(
        ['děkujeme za rezervaci míst: ' . implode('; ', seat_labels($r['seats'])) . '.', run_line($r), '', 'Platební údaje:'],
        payment_lines($r),
        ['', 'Pokud platba nedorazí, rezervace bude zrušena a místa uvolněna.'],
        $qr ? ['', 'QR kód pro platbu je v příloze ' . $qr['name'] . ' – načtěte ho v aplikaci banky.'] : [],
        $link ? ['', "Rezervace a QR kód online: {$link}"] : []
    ), $qr ? [$qr] : []);
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
        ['přijali jsme Vaši platbu ' . format_czk($received) . ' (SS ' . $r['specific_symbol'] . '), na celou částku ale chybí '
            . format_czk(amount_due($r)) . '.', run_line($r), '', 'Doplaťte prosím se stejným variabilním a specifickým symbolem:'],
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
        'přijali jsme Vaši platbu ' . format_czk($received) . ' (SS ' . $r['specific_symbol'] . '), ale '
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
        ['platba za Vaši rezervaci (SS ' . $r['specific_symbol'] . ') nedorazila včas, proto byla rezervace zrušena a místa uvolněna:',
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
