<?php
// Ticket QR codes sent to the customer after the payment is confirmed – one code per seat
// (one QR = one chair), so a printed sheet can be cut and the tickets handed out.
//
// Format (UTF-8 text):  Z26M|<reservation id>|<specific symbol>|<seat>|<kind>|<name>|<signature>
// e.g.                  Z26M|42|2600000002|ML-1-2|C|Jana Dvořáková|Xb3...
// kind: A = adult, C = child (4–17 years). The signature (HMAC-SHA256 with TICKET_SECRET)
// lets the scanner detect forged or altered codes. The scanner looks the reservation up by
// its id and shows its current state from the database (the rest of the code is only a
// fallback when offline). Keep the format in sync with src/scanner/ticket.js.
declare(strict_types=1);

use chillerlan\QRCode\Common\EccLevel;
use chillerlan\QRCode\Output\QROutputInterface;
use chillerlan\QRCode\QRCode;
use chillerlan\QRCode\QROptions;

const TICKET_PREFIX = 'Z26M';
const TICKET_KINDS = ['adult' => 'A', 'child' => 'C'];

const TICKET_SECRET_MIN = 32;

/** TICKET_SECRET; refuses a short one or the text from config.local.example.php (contains spaces). */
function ticket_secret(): string
{
    $secret = (string) config('TICKET_SECRET');
    if (strlen($secret) < TICKET_SECRET_MIN || preg_match('/\s/', $secret)) {
        throw new RuntimeException('TICKET_SECRET is not configured (a random string of min. ' . TICKET_SECRET_MIN . ' characters, no spaces).');
    }
    return $secret;
}

function ticket_signature(string $payload): string
{
    $mac = hash_hmac('sha256', $payload, ticket_secret(), true);
    return substr(rtrim(strtr(base64_encode($mac), '+/', '-_'), '='), 0, 22);
}

/** Name in the ticket code of the seat (the name on the ticket, without "|", control and invisible format characters). */
function ticket_name(array $r, string $seat): string
{
    return trim(preg_replace('/[|\p{Cc}\p{Cf}]+/u', ' ', ticket_holder($r, $seat)) ?? '');
}

/** Ticket code of one seat of the reservation. */
function ticket_code(array $r, string $seat): string
{
    $payload = implode('|', [
        TICKET_PREFIX,
        (string) (int) $r['id'],
        $r['specific_symbol'],
        $seat,
        TICKET_KINDS[seat_kind($r, $seat)],
        ticket_name($r, $seat),
    ]);
    return $payload . '|' . ticket_signature($payload);
}

/** Returns the decoded ticket, or null when the code is malformed or the signature is wrong. */
function parse_ticket_code(string $code): ?array
{
    $parts = explode('|', trim($code));
    if (count($parts) !== 7 || $parts[0] !== TICKET_PREFIX) {
        return null;
    }
    [, $id, $ss, $seat, $kind, $name, $sig] = $parts;
    if (!hash_equals(ticket_signature(implode('|', array_slice($parts, 0, 6))), $sig)) {
        return null;
    }
    return [
        'id' => (int) $id,
        'specificSymbol' => $ss,
        'seat' => $seat,
        'kind' => array_search($kind, TICKET_KINDS, true) ?: 'adult',
        'name' => $name,
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
    return new QRCode($options)->render($text);
}

/**
 * E-mails the tickets to the customer: one QR code per seat inline in the message and
 * the printable vstupenky.pdf (ticket_pdf()) plus the calendar event. Returns true when
 * the message was handed over to the mail system.
 * $intro replaces the default first paragraph; $subject (without the "TZG 2026: " prefix) defaults to
 * "Nové vstupenky" with an intro (replacement tickets) and "Vaše vstupenky" without.
 */
function send_ticket_email(array $r, array $intro = [], ?string $subject = null): bool
{
    if ($r['email'] === '') {
        return false;
    }
    $h = static fn ($v) => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
    $items = reservation_seat_items($r);
    $count = count($items);
    $printNote = 'V příloze je soubor vstupenky.pdf k vytištění – vstupenky rozstříhejte, každá platí pro jedno místo.'
        . ' Při vstupu můžete QR kódy ukázat i na mobilu.';

    $introLines = $intro ?: ['děkujeme, platba byla přijata. Vaše rezervace je potvrzena.'];
    $text = implode("\n", [
        "Dobrý den, {$r['first_name']} {$r['last_name']},",
        '',
        ...$introLines,
        '',
        run_line($r),
        'Vstupenky: ' . seat_kinds_summary($r),
        ...array_map(static fn ($i) => '- ' . seat_label($i['seat']) . ' – ' . SEAT_KIND_LABELS[$i['kind']]
            . ($i['holder'] !== null ? ' – ' . $i['holder'] : ''), $items),
        'Specifický symbol: ' . $r['specific_symbol'],
        '',
        'Každé místo má vlastní QR kód (1 QR kód = 1 židle). ' . $printNote,
        ...(contact_line() !== '' ? ['', contact_line()] : []),
    ]);

    $tickets = '';
    $inline = [];
    foreach ($items as $n => $item) {
        $cid = 'ticket-qr-' . ($n + 1);
        $inline[] = ['cid' => $cid, 'data' => ticket_png(ticket_code($r, $item['seat'])), 'name' => "vstupenka-{$item['seat']}.png", 'type' => 'image/png'];
        $tickets .= '<div style="border-top:1px dashed #d9cfbf;padding:20px 0 16px">'
            . '<img src="cid:' . $cid . '" alt="QR kód vstupenky ' . $h($item['seat']) . '" width="220" height="220" style="display:block;margin:0 auto 12px;width:220px;height:220px">'
            . ($item['holder'] !== null ? '<p style="margin:0 0 4px;font-size:16px">' . $h($item['holder']) . '</p>' : '')
            . '<p style="margin:0 0 4px;font-size:18px;font-weight:bold">' . $h(seat_label($item['seat'])) . '</p>'
            . '<p style="margin:0;color:#6b6256">' . $h(SEAT_KIND_LABELS[$item['kind']]) . ' · vstupenka ' . ($n + 1) . ' z ' . $count . '</p>'
            . '</div>';
    }

    $html = '<!doctype html><html><body style="margin:0;background:#f5f1ea;font-family:Arial,sans-serif;color:#2b2620">'
        . '<div style="max-width:480px;margin:0 auto;padding:24px 16px">'
        . '<div style="background:#fffdf9;border-radius:18px;padding:24px;text-align:center">'
        . '<h1 style="margin:0 0 4px;font-family:Georgia,serif;font-size:24px">Koncert TZG <span style="color:#8a5a2b">2026</span></h1>'
        . '<p style="margin:0 0 20px;color:#6b6256">Vstupenky · SS ' . $h($r['specific_symbol']) . '</p>'
        . ($intro ? '<p style="margin:0 0 20px;text-align:left;line-height:1.5">' . implode('<br>', array_map($h, $intro)) . '</p>' : '')
        . '<p style="margin:0 0 8px;font-size:20px;font-weight:bold;color:#8a5a2b">' . $h(preg_replace('/^Termín: /', '', run_line($r))) . '</p>'
        . '<p style="margin:0 0 4px;font-size:18px;font-weight:bold">' . $h($r['first_name'] . ' ' . $r['last_name']) . '</p>'
        . '<p style="margin:0 0 16px;color:#6b6256">' . $h(seat_kinds_summary($r)) . '</p>'
        . '<p style="margin:0 0 16px;line-height:1.5">Každé místo má vlastní QR kód – <strong>1 QR kód = 1 židle</strong>.</p>'
        . $tickets
        . '</div>'
        . '<p style="text-align:center;color:#6b6256;font-size:13px;line-height:1.5">' . $h($printNote)
        . (contact_line() !== '' ? '<br>' . $h(contact_line()) : '') . '</p>'
        . '</div></body></html>';

    $subject = mail_subject($subject ?? ($intro ? 'Nové vstupenky' : 'Vaše vstupenky'));
    $attachments = [['data' => ticket_pdf($r), 'name' => 'vstupenky.pdf', 'type' => 'application/pdf']];
    $ics = ticket_ics($r);
    if ($ics !== null) {
        $attachments[] = ['data' => $ics, 'name' => 'udalost.ics', 'type' => 'text/calendar; charset=UTF-8; method=REQUEST'];
    }
    $ok = deliver_mail($r['email'], $subject, $text, $html, $inline, $attachments);
    if (!$ok) {
        app_log('Failed to send ticket e-mail for reservation ' . $r['id']);
    }
    return $ok;
}

/**
 * Calendar invitation (iCalendar, METHOD:REQUEST) for the reservation's run, attached
 * to the ticket e-mail as udalost.ics so Gmail/Outlook show the event card.
 * UID is stable per reservation and SEQUENCE grows, so a new ticket updates the event.
 * Null when the run no longer exists.
 */
function ticket_ics(array $r, ?DateTimeImmutable $now = null): ?string
{
    $run = run_by_id((int) $r['run_id']);
    if ($run === null) {
        return null;
    }
    $now ??= now_utc();
    $utc = static fn (DateTimeImmutable $t) => $t->setTimezone(new DateTimeZone('UTC'))->format('Ymd\THis\Z');
    $start = run_starts($run);
    $end = $start->modify('+' . max(1, (int) config('EVENT_DURATION_MINUTES')) . ' minutes');
    $host = parse_url((string) config('PUBLIC_URL'), PHP_URL_HOST) ?: (explode('@', mail_sender())[1] ?? '') ?: 'koncert-tzg';
    $name = trim($r['first_name'] . ' ' . $r['last_name']);
    $seats = seat_labels($r['seats']);
    $link = reservation_link($r);
    $description = implode("\n", [
        'Místa: ' . implode('; ', $seats),
        'Specifický symbol: ' . $r['specific_symbol'],
        'Při vstupu prosím ukažte QR kódy vstupenek z e-mailu (1 QR kód = 1 místo).',
        ...($link ? ['Rezervace: ' . $link] : []),
        ...(contact_line() !== '' ? [contact_line()] : []),
    ]);
    $location = trim((string) config('EVENT_LOCATION'));
    $lines = [
        'BEGIN:VCALENDAR',
        'VERSION:2.0',
        'PRODID:-//Koncert TZG 2026//Rezervace//CS',
        'CALSCALE:GREGORIAN',
        'METHOD:REQUEST',
        'BEGIN:VEVENT',
        'UID:zidle-' . (int) $r['id'] . '@' . $host,
        'SEQUENCE:' . $now->getTimestamp(),
        'DTSTAMP:' . $utc($now),
        'DTSTART:' . $utc($start),
        'DTEND:' . $utc($end),
        'SUMMARY:' . ics_text('TZG 2026' . ($run['label'] !== '' ? ' · ' . $run['label'] : '') . ' – ' . implode('; ', $seats)),
        'DESCRIPTION:' . ics_text($description),
        ...($location !== '' ? ['LOCATION:' . ics_text($location)] : []),
        'ORGANIZER;CN=' . ics_param(MAIL_FROM_NAME) . ':mailto:' . mail_sender(),
        'ATTENDEE;CN=' . ics_param($name) . ';ROLE=REQ-PARTICIPANT;PARTSTAT=ACCEPTED;RSVP=FALSE:mailto:' . $r['email'],
        'STATUS:CONFIRMED',
        'TRANSP:OPAQUE',
        'END:VEVENT',
        'END:VCALENDAR',
    ];
    return implode("\r\n", array_map('ics_fold', $lines)) . "\r\n";
}

/** iCalendar TEXT value: escapes \\ ; , and newlines. */
function ics_text(string $value): string
{
    return str_replace(["\\", ';', ',', "\r\n", "\n"], ['\\\\', '\\;', '\\,', '\\n', '\\n'], $value);
}

/** iCalendar parameter value (CN=…): quoted, without characters it can't hold. */
function ics_param(string $value): string
{
    return '"' . str_replace(['"', "\r", "\n"], '', $value) . '"';
}

/** Folds a content line to at most 75 octets without splitting UTF-8 characters. */
function ics_fold(string $line): string
{
    $out = '';
    $current = '';
    foreach (mb_str_split($line) as $char) {
        if (strlen($current) + strlen($char) > ($out === '' ? 75 : 74)) {
            $out .= ($out === '' ? '' : "\r\n ") . $current;
            $current = '';
        }
        $current .= $char;
    }
    return $out === '' ? $current : $out . "\r\n " . $current;
}

/** "ML-1-2" → "Levá hlavní · řada 1 · místo 2" */
function seat_label(string $seat): string
{
    [$section, $row, $number] = array_pad(explode('-', $seat), 3, '');
    return section_name($section) . " · řada {$row} · místo {$number}";
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
