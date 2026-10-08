<?php
// Ticket QR codes sent to the customer after the payment is confirmed.
//
// Format (UTF-8 text):  Z26|<reservation id>|<variable symbol>|<seat count>|<name>|<seat,seat,...>|<signature>
// e.g.                  Z26|42|2600000002|2|Jana Dvořáková|ML-1-1,ML-1-2|Xb3...
// The signature (HMAC-SHA256 with TICKET_SECRET) lets the scanner detect
// forged or altered codes. The scanner looks the reservation up by its id and
// shows its current state from the database (the rest of the code is only a
// fallback when offline). Older tickets without the id (6 parts) are still
// accepted. Keep the format in sync with src/scanner/ticket.js.
declare(strict_types=1);

use chillerlan\QRCode\Common\EccLevel;
use chillerlan\QRCode\Output\QROutputInterface;
use chillerlan\QRCode\QRCode;
use chillerlan\QRCode\QROptions;

const TICKET_PREFIX = 'Z26';

function ticket_secret(): string
{
    $secret = (string) config('TICKET_SECRET');
    if (strlen($secret) < 16) {
        throw new RuntimeException('TICKET_SECRET is not configured (min. 16 characters).');
    }
    return $secret;
}

function ticket_signature(string $payload): string
{
    $mac = hash_hmac('sha256', $payload, ticket_secret(), true);
    return substr(rtrim(strtr(base64_encode($mac), '+/', '-_'), '='), 0, 22);
}

function ticket_name(array $r): string
{
    $name = trim($r['first_name'] . ' ' . $r['last_name']);
    return trim(preg_replace('/[|\p{Cc}]+/u', ' ', $name));
}

function ticket_code(array $r): string
{
    $payload = implode('|', [
        TICKET_PREFIX,
        (string) (int) $r['id'],
        $r['variable_symbol'],
        (string) (int) $r['seat_count'],
        ticket_name($r),
        $r['seats'],
    ]);
    return $payload . '|' . ticket_signature($payload);
}

/** Returns the decoded ticket, or null when the code is malformed or the signature is wrong. */
function parse_ticket_code(string $code): ?array
{
    $parts = explode('|', trim($code));
    if (count($parts) === 6) {
        array_splice($parts, 1, 0, ['']); // older ticket without reservation id
    }
    if (count($parts) !== 7 || $parts[0] !== TICKET_PREFIX) {
        return null;
    }
    [, $id, $vs, $count, $name, $seats, $sig] = $parts;
    $signed = array_values(array_filter(array_slice($parts, 0, 6), static fn ($p, $i) => $i !== 1 || $id !== '', ARRAY_FILTER_USE_BOTH));
    if (!hash_equals(ticket_signature(implode('|', $signed)), $sig)) {
        return null;
    }
    return [
        'id' => $id === '' ? null : (int) $id,
        'variableSymbol' => $vs,
        'count' => (int) $count,
        'name' => $name,
        'seats' => $seats === '' ? [] : explode(',', $seats),
    ];
}

function ticket_png(string $code): string
{
    return qr_png($code);
}

/** PNG (binary) of a QR code for any text, e.g. tickets and scanner invite links. */
function qr_png(string $text, int $scale = 8): string
{
    require_once __DIR__ . '/../vendor/autoload.php';
    $options = new QROptions([
        'outputType' => QROutputInterface::GDIMAGE_PNG,
        'outputBase64' => false,
        'eccLevel' => EccLevel::M,
        'scale' => $scale,
        'quietzoneSize' => 3,
    ]);
    return (new QRCode($options))->render($text);
}

/**
 * E-mails the ticket QR code (inline PNG) to the customer. Returns true when
 * the message was handed over to the mail system. $intro replaces the default
 * "payment received" text (used for a new ticket after seats were cancelled).
 */
function send_ticket_email(array $r, array $intro = []): bool
{
    if ($r['email'] === '') {
        return false;
    }
    $code = ticket_code($r);
    $png = ticket_png($code);
    $h = static fn ($v) => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
    $seats = seat_labels($r['seats']);
    $count = (int) $r['seat_count'];

    $introLines = $intro ?: ['děkujeme, platba byla přijata. Vaše rezervace je potvrzena.'];
    $text = implode("\n", [
        "Dobrý den, {$r['first_name']} {$r['last_name']},",
        '',
        ...$introLines,
        '',
        run_line($r),
        "Počet míst: {$count}",
        'Místa: ' . implode('; ', $seats),
        'Variabilní symbol: ' . $r['variable_symbol'],
        '',
        'Při vstupu prosím ukažte QR kód z tohoto e-mailu.',
    ]);

    $html = '<!doctype html><html><body style="margin:0;background:#f5f1ea;font-family:Arial,sans-serif;color:#2b2620">'
        . '<div style="max-width:480px;margin:0 auto;padding:24px 16px">'
        . '<div style="background:#fffdf9;border-radius:18px;padding:24px;text-align:center">'
        . '<h1 style="margin:0 0 4px;font-family:Georgia,serif;font-size:24px">Moje židle <span style="color:#8a5a2b">2026</span></h1>'
        . '<p style="margin:0 0 20px;color:#6b6256">Vstupenka · VS ' . $h($r['variable_symbol']) . '</p>'
        . ($intro ? '<p style="margin:0 0 20px;text-align:left;line-height:1.5">' . implode('<br>', array_map($h, $intro)) . '</p>' : '')
        . '<img src="cid:ticket-qr" alt="QR kód vstupenky" width="260" height="260" style="display:block;margin:0 auto 20px;width:260px;height:260px">'
        . '<p style="margin:0 0 16px;font-size:20px;font-weight:bold;color:#8a5a2b">' . $h(preg_replace('/^Termín: /', '', run_line($r))) . '</p>'
        . '<p style="margin:0 0 4px;font-size:18px;font-weight:bold">' . $h($r['first_name'] . ' ' . $r['last_name']) . '</p>'
        . '<p style="margin:0 0 16px;color:#6b6256">' . $count . ' ' . ($count === 1 ? 'místo' : ($count < 5 ? 'místa' : 'míst')) . '</p>'
        . '<p style="margin:0;line-height:1.6">' . implode('<br>', array_map($h, $seats)) . '</p>'
        . '</div>'
        . '<p style="text-align:center;color:#6b6256;font-size:13px">Při vstupu prosím ukažte tento QR kód.</p>'
        . '</div></body></html>';

    $subject = ($intro ? 'Nová vstupenka' : 'Vstupenka') . ' – Moje židle 2026';
    $ok = deliver_mail($r['email'], $subject, $text, $html, [
        ['cid' => 'ticket-qr', 'data' => $png, 'name' => 'vstupenka.png', 'type' => 'image/png'],
    ]);
    if (!$ok) {
        error_log('[zidle] Failed to send ticket e-mail for reservation ' . $r['id']);
    }
    return $ok;
}

/** "ML-1-1,ML-1-2,BC-2-5" → ["Levá hlavní: ř. 1 m. 1, 2", "Balkon střed: ř. 2 m. 5"] */
function seat_labels(string $seats): array
{
    $byRow = [];
    foreach (array_filter(explode(',', $seats)) as $id) {
        [$section, $row, $seat] = explode('-', $id);
        $byRow[$section][(int) $row][] = (int) $seat;
    }
    $lines = [];
    foreach (SECTIONS as $section => $def) {
        if (empty($byRow[$section])) {
            continue;
        }
        ksort($byRow[$section]);
        $rows = [];
        foreach ($byRow[$section] as $row => $list) {
            sort($list);
            $rows[] = "ř. {$row} m. " . implode(', ', $list);
        }
        $lines[] = $def['name'] . ': ' . implode('; ', $rows);
    }
    return $lines;
}
