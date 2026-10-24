<?php
// Automatic payments: the bank (Komerční banka) e-mails a notification about every movement
// on the account to the mailbox PAYMENT_IMAP_*. payment_sync() (cron every 5 minutes, or
// "Synchronizovat nyní" in the admin) reads new e-mails over IMAP (read-only), recognises the
// incoming transfer and records it like a bank statement row (bank_match() / bank_record()).
// Every e-mail is processed once (table payment_mails); what can't be recorded safely
// automatically waits in the admin (Výpis z banky) for a person.
declare(strict_types=1);

/** E-mails of the last days are looked at on every sync (older ones are never processed). */
const PAYMENT_MAIL_DAYS = 7;
/** At most this many new e-mails per sync (the rest comes next time). */
const PAYMENT_MAIL_MAX_PER_SYNC = 50;
/** The admin warns when the last automatic sync is older than this. */
const PAYMENT_SYNC_STALE_MINUTES = 15;
/** Who recorded a payment found in an e-mail (bank_payments.imported_by). */
const PAYMENT_MAIL_BY = 'e-mail z banky';
const PAYMENT_MAIL_EXCERPT_CHARS = 3000;

/** Statuses of a processed e-mail (admin labels). */
const PAYMENT_MAIL_STATUSES = [
    'recorded' => 'Zapsáno',
    'review' => 'Ke kontrole',
    'unrecognized' => 'Nerozpoznáno',
    'rejected' => 'Neověřený odesílatel',
    'ignored' => 'Přeskočeno',
];
/** Statuses that wait for a person until marked resolved. */
const PAYMENT_MAIL_ATTENTION = ['review', 'unrecognized', 'rejected'];

function payment_sync_enabled(): bool
{
    return trim((string) config('PAYMENT_IMAP_HOST')) !== '';
}

/**
 * Reads new e-mails from the payment mailbox and processes them. $by = 'cron' for the
 * automatic run, else the e-mail of the admin who started it. Returns (and remembers in
 * settings.payment_sync) ['at', 'by', 'ok', 'error', 'mails', 'recorded', 'review'].
 * $mailbox replaces the PAYMENT_IMAP_* settings (tests).
 */
function payment_sync(string $by, #[\SensitiveParameter] ?array $mailbox = null): array
{
    $result = ['at' => iso_utc(now_utc()), 'by' => $by, 'ok' => false, 'error' => null, 'mails' => 0, 'recorded' => 0, 'review' => 0];
    $mailbox ??= payment_mailbox();
    if (trim($mailbox['host']) === '') {
        return ['error' => 'Automatické načítání plateb není nastavené (PAYMENT_IMAP_HOST).'] + $result;
    }
    if ((int) db_query("SELECT GET_LOCK('zidle_payment_sync', 0)")->fetchColumn() !== 1) {
        return ['error' => 'Synchronizace právě běží, zkuste to za chvíli.'] + $result;
    }
    $imap = null;
    try {
        $imap = new ImapClient($mailbox['host'], $mailbox['port'], 20, $mailbox['ipv4'] ?? false);
        try {
            $imap->login($mailbox['user'], $mailbox['password']);
            $folder = $mailbox['folder'];
            $prefix = 'imap:' . $folder . ':' . $imap->examine($folder) . ':';
            $stmt = db()->prepare('SELECT mail_key FROM payment_mails WHERE LEFT(mail_key, ?) = ?');
            $stmt->execute([mb_strlen($prefix), $prefix]);
            $known = array_flip($stmt->fetchAll(PDO::FETCH_COLUMN));
            $new = array_filter(
                $imap->searchSince(now_utc()->modify('-' . PAYMENT_MAIL_DAYS . ' days')),
                static fn (int $uid) => !isset($known[$prefix . $uid])
            );
            foreach (array_slice($new, 0, PAYMENT_MAIL_MAX_PER_SYNC) as $uid) {
                $raw = $imap->fetch($uid);
                if ($raw === null) {
                    continue;
                }
                $row = process_payment_mail($prefix . $uid, $raw);
                $result['mails']++;
                $result['recorded'] += (int) ($row['status'] === 'recorded');
                $result['review'] += (int) in_array($row['status'], PAYMENT_MAIL_ATTENTION, true);
            }
        } finally {
            $imap->logout();
        }
        $result['ok'] = true;
    } catch (Throwable $e) {
        $result['error'] = $e->getMessage();
        app_log("Payment sync failed ({$by}): " . payment_imap_diagnostics($mailbox)
            . ($imap !== null ? "\nIMAP conversation:\n" . $imap->transcript() : '') . "\n" . $e);
    } finally {
        db_query("SELECT RELEASE_LOCK('zidle_payment_sync')");
    }
    $state = payment_sync_state();
    $state['last'] = $result;
    if ($by === 'cron') {
        $state['auto'] = $result;
    }
    db()->prepare('INSERT INTO settings (name, value) VALUES (?, ?) ON DUPLICATE KEY UPDATE value = VALUES(value)')
        ->execute(['payment_sync', json_encode($state, JSON_UNESCAPED_UNICODE)]);
    return $result;
}

/** The PAYMENT_IMAP_* settings: host, port, user, password, folder, ipv4. */
function payment_mailbox(): array
{
    return [
        'host' => (string) config('PAYMENT_IMAP_HOST'),
        'port' => (int) config('PAYMENT_IMAP_PORT'),
        'user' => (string) config('PAYMENT_IMAP_USER'),
        'password' => (string) config('PAYMENT_IMAP_PASSWORD'),
        'folder' => (string) config('PAYMENT_IMAP_FOLDER'),
        'ipv4' => (bool) config('PAYMENT_IMAP_IPV4'),
    ];
}

/**
 * Mailbox settings for the error log, without the password itself: its length and the start
 * of its SHA-256 (to compare with another machine), whitespace around values, versions.
 */
function payment_imap_diagnostics(#[\SensitiveParameter] array $mailbox): string
{
    ['user' => $user, 'password' => $password] = $mailbox;
    $padded = static fn (string $v) => $v !== trim($v) ? ' WITH WHITESPACE AROUND' : '';
    return sprintf(
        'host %s, port %d%s, folder %s, user %s (%d chars%s), password %d chars%s sha256 %s…, PHP %s, %s, server %s',
        var_export($mailbox['host'], true),
        $mailbox['port'],
        ($mailbox['ipv4'] ?? false) ? ' (IPv4 only)' : '',
        var_export($mailbox['folder'], true),
        var_export($user, true),
        strlen($user),
        $padded($user),
        strlen($password),
        $padded($password) . ($password !== '' && preg_match('/[^\x20-\x7e]/', $password) ? ' NON-ASCII,' : ','),
        substr(hash('sha256', $password), 0, 8),
        PHP_VERSION,
        defined('OPENSSL_VERSION_TEXT') ? OPENSSL_VERSION_TEXT : 'no OpenSSL',
        gethostname() ?: '?'
    );
}

/** ['last' => last sync result or null, 'auto' => last automatic (cron) one or null]. */
function payment_sync_state(): array
{
    $value = db()->prepare('SELECT value FROM settings WHERE name = ?');
    $value->execute(['payment_sync']);
    $state = (array) json_decode((string) $value->fetchColumn(), true);
    return ['last' => $state['last'] ?? null, 'auto' => $state['auto'] ?? null];
}

/** Czech one-line summary of a sync result. */
function payment_sync_summary(array $result): string
{
    if (!$result['ok']) {
        return 'chyba: ' . $result['error'];
    }
    return $result['mails'] === 0
        ? 'žádné nové e-maily'
        : "nových e-mailů {$result['mails']}, zapsáno plateb {$result['recorded']}, ke kontrole {$result['review']}";
}

/**
 * Processes one e-mail of the mailbox ($key identifies it there) and stores the outcome in
 * payment_mails. A recognised incoming transfer is recorded when bank_match() would pre-check
 * it (pending reservation without earlier payments, expired reservation); other ones wait
 * for review. Returns the stored row.
 */
function process_payment_mail(string $key, string $raw): array
{
    $mail = parse_mime_message($raw);
    $header = static fn (string $name) => $mail['headers'][$name][0] ?? '';
    $from = mail_address($header('from'));
    try {
        $receivedAt = new DateTimeImmutable($header('date'))->setTimezone(new DateTimeZone('UTC'));
    } catch (Exception) {
        $receivedAt = now_utc();
    }
    $row = [
        'mail_key' => $key,
        'received_at' => db_time($receivedAt),
        'sender' => mb_substr($from, 0, 190),
        'subject' => mb_substr($header('subject'), 0, 250),
        'status' => 'ignored',
        'note' => '',
        'tx' => null,
        'excerpt' => mb_substr($mail['text'], 0, PAYMENT_MAIL_EXCERPT_CHARS),
        'reservation_id' => null,
    ];

    if (!payment_mail_sender_allowed($from)) {
        $row['note'] = 'Odesílatel ' . ($from ?: 'neznámý') . ' není banka (PAYMENT_MAIL_SENDERS).';
        $row['excerpt'] = null;
    } elseif (!payment_mail_authentic($mail['headers'], $from)) {
        $row['status'] = 'rejected';
        $row['note'] = 'E-mail neprošel ověřením odesílatele (DKIM/DMARC) a mohl být podvržený – nic se nezapsalo. Platbu ověřte v internetovém bankovnictví a případně zapište ručně.';
    } else {
        $parsed = parse_payment_notification($mail['text']);
        if (is_string($parsed)) {
            $row['status'] = 'unrecognized';
            $row['note'] = $parsed . ' Platbu ověřte v internetovém bankovnictví a případně zapište ručně.';
        } elseif ($parsed['direction'] === 'out') {
            $row['note'] = 'Odchozí platba.';
        } else {
            $tx = $parsed['tx'];
            if ($tx['id'] === '') {
                $messageId = $header('message-id');
                $tx['id'] = 'mail:' . sha1($messageId !== '' ? $messageId : $key);
            }
            $row['tx'] = json_encode($tx, JSON_UNESCAPED_UNICODE);
            $match = bank_match($tx);
            $row['reservation_id'] = $match['reservation']['id'] ?? null;
            $row['note'] = $match['note'];
            if ($match['state'] === 'none') {
                $row['status'] = 'review';
            } elseif ($match['state'] === 'match' && $match['select']) {
                try {
                    $done = bank_record($tx, PAYMENT_MAIL_BY);
                } catch (Throwable $e) {
                    app_log('Payment from e-mail not recorded: ' . $e);
                    $done = ['ok' => false, 'message' => 'Zápis selhal – zapište ručně.'];
                }
                $row['status'] = $done['ok'] ? 'recorded' : 'review';
                $row['note'] = $done['message'];
            } elseif ($match['state'] === 'match') {
                $row['status'] = 'review';
            }
        }
    }

    db()->prepare(
        'INSERT INTO payment_mails (mail_key, received_at, sender, subject, status, note, tx, excerpt, reservation_id, processed_at)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
    )->execute([...array_values($row), db_time(now_utc())]);
    return ['id' => (int) db()->lastInsertId()] + $row;
}

/** Admin: records the transfer of an e-mail waiting for review. Returns ['ok', 'message']. */
function payment_mail_record(int $id, string $by): array
{
    $mail = payment_mail($id);
    if ($mail === null || $mail['status'] !== 'review' || $mail['tx'] === null) {
        return ['ok' => false, 'message' => 'Platbu z tohoto e-mailu nelze zapsat.'];
    }
    $done = bank_record((array) json_decode($mail['tx'], true), $by);
    if ($done['ok']) {
        db()->prepare("UPDATE payment_mails SET status = 'recorded', note = ?, resolved_at = ?, resolved_by = ? WHERE id = ?")
            ->execute([$done['message'], db_time(now_utc()), $by, $id]);
    }
    return $done;
}

/** Admin: an e-mail waiting for a person is dealt with (e.g. the payment was recorded by hand). */
function payment_mail_resolve(int $id, string $by): bool
{
    $stmt = db()->prepare('UPDATE payment_mails SET resolved_at = ?, resolved_by = ? WHERE id = ? AND resolved_at IS NULL');
    $stmt->execute([db_time(now_utc()), $by, $id]);
    return $stmt->rowCount() === 1;
}

function payment_mail(int $id): ?array
{
    $stmt = db()->prepare('SELECT * FROM payment_mails WHERE id = ?');
    $stmt->execute([$id]);
    return $stmt->fetch() ?: null;
}

/** Number of e-mails waiting for a person (badge of the admin tab). */
function payment_mails_attention(): int
{
    return (int) db_query(
        "SELECT COUNT(*) FROM payment_mails WHERE status IN ('" . implode("','", PAYMENT_MAIL_ATTENTION) . "') AND resolved_at IS NULL"
    )->fetchColumn();
}

/** The address of a From header, lower case ('' when there is none). */
function mail_address(string $from): string
{
    if (preg_match('/<([^<>@\s]+@[^<>\s]+)>/', $from, $m) || preg_match('/([^\s<>"]+@[^\s<>"]+)/', $from, $m)) {
        return strtolower($m[1]);
    }
    return '';
}

/** PAYMENT_MAIL_SENDERS: comma-separated addresses (exact) and domains (also their subdomains). */
function payment_mail_sender_allowed(string $address): bool
{
    $domain = strtolower(substr(strrchr($address, '@') ?: '', 1));
    foreach (explode(',', strtolower((string) config('PAYMENT_MAIL_SENDERS'))) as $allowed) {
        $allowed = trim($allowed);
        if ($allowed === '' || $domain === '') {
            continue;
        }
        if (str_contains($allowed, '@') ? $address === $allowed : ($domain === $allowed || str_ends_with($domain, '.' . $allowed))) {
            return true;
        }
    }
    return false;
}

/**
 * Whether the receiving server verified the sender: the first (topmost, added by the mailbox's
 * own server) Authentication-Results header has dmarc=pass, or dkim=pass for the sender's
 * domain. Always true with PAYMENT_MAIL_VERIFY off.
 */
function payment_mail_authentic(array $headers, string $from): bool
{
    if (!config('PAYMENT_MAIL_VERIFY')) {
        return true;
    }
    $results = strtolower($headers['authentication-results'][0] ?? '');
    $domain = preg_quote(strtolower(substr(strrchr($from, '@') ?: '', 1)), '/');
    return $domain !== '' && (
        preg_match('/\bdmarc=pass\b[^;]*header\.from=' . $domain . '(?![a-z0-9.-])/', $results)
        || preg_match('/\bdkim=pass\b[^;]*header\.[di]=@?(?:[a-z0-9.-]+\.)?' . $domain . '(?![a-z0-9.-])/', $results)
    );
}

/**
 * Headers (lower-case name => values in order, decoded to UTF-8) and the readable text of an
 * e-mail: the text/plain parts, or the text/html ones converted to text when there is no plain
 * text. Handles multipart, base64, quoted-printable and any charset iconv knows.
 */
function parse_mime_message(string $raw): array
{
    [$head, $body] = mime_split(str_replace(["\r\n", "\r"], "\n", $raw));
    $headers = mime_headers($head);
    $parts = ['plain' => [], 'html' => []];
    mime_collect_text($headers, $body, $parts, 0);
    $text = $parts['plain'] ? implode("\n", $parts['plain']) : implode("\n", array_map('html_to_text', $parts['html']));
    return ['headers' => $headers, 'text' => trim($text)];
}

function mime_split(string $part): array
{
    $pos = strpos($part, "\n\n");
    return $pos === false ? [$part, ''] : [substr($part, 0, $pos), substr($part, $pos + 2)];
}

function mime_headers(string $head): array
{
    $headers = [];
    foreach (explode("\n", (string) preg_replace('/\n[ \t]+/', ' ', $head)) as $line) {
        if (preg_match('/^([!-9;-~]+):\s*(.*)$/', $line, $m)) {
            $value = $m[2];
            if (!mb_check_encoding($value, 'UTF-8')) {
                $value = (string) @iconv('WINDOWS-1250', 'UTF-8//IGNORE', $value); // raw 8-bit header
            }
            if (str_contains($value, '=?')) {
                $decoded = @iconv_mime_decode($value, ICONV_MIME_DECODE_CONTINUE_ON_ERROR, 'UTF-8');
                $value = $decoded === false ? $value : $decoded;
            }
            $headers[strtolower($m[1])][] = trim($value);
        }
    }
    return $headers;
}

/** A parameter of a header value, e.g. boundary or charset ('' when missing). */
function mime_param(string $value, string $name): string
{
    return preg_match('/;\s*' . preg_quote($name, '/') . '\s*=\s*(?:"([^"]*)"|([^;\s]+))/i', $value, $m) ? ($m[2] ?? '') . $m[1] : '';
}

function mime_collect_text(array $headers, string $body, array &$parts, int $depth): void
{
    $type = $headers['content-type'][0] ?? 'text/plain';
    $mime = strtolower(trim(strtok($type, ';') ?: 'text/plain'));
    if (str_starts_with($mime, 'multipart/')) {
        $boundary = mime_param($type, 'boundary');
        if ($boundary === '' || $depth > 5) {
            return;
        }
        $sections = explode("\n--" . $boundary, "\n" . $body);
        array_shift($sections); // preamble
        foreach ($sections as $section) {
            if (str_starts_with($section, '--')) {
                break; // closing boundary
            }
            [$head, $partBody] = mime_split((string) preg_replace('/^[ \t]*\n/', '', $section));
            mime_collect_text(mime_headers($head), $partBody, $parts, $depth + 1);
        }
        return;
    }
    if (($mime !== 'text/plain' && $mime !== 'text/html')
        || str_starts_with(strtolower($headers['content-disposition'][0] ?? ''), 'attachment')) {
        return;
    }
    $body = match (strtolower(trim($headers['content-transfer-encoding'][0] ?? ''))) {
        'base64' => (string) base64_decode((string) preg_replace('/\s+/', '', $body)),
        'quoted-printable' => quoted_printable_decode($body),
        default => $body,
    };
    $charset = strtolower(mime_param($type, 'charset'));
    if ($charset !== '' && $charset !== 'utf-8' && $charset !== 'us-ascii') {
        $converted = @iconv($charset, 'UTF-8//IGNORE', $body);
        $body = $converted === false ? $body : $converted;
    }
    if (!mb_check_encoding($body, 'UTF-8')) {
        $body = (string) @iconv('WINDOWS-1250', 'UTF-8//IGNORE', $body);
    }
    $parts[$mime === 'text/html' ? 'html' : 'plain'][] = str_replace("\r", '', $body);
}

/** Text of an HTML e-mail: table cells separated by a tab, rows and blocks by a new line. */
function html_to_text(string $html): string
{
    $html = (string) preg_replace('#<(style|script|head)\b.*?</\1>#is', '', $html);
    $html = (string) preg_replace('#<br\s*/?>|</(p|div|tr|li|h\d|table)>#i', "\n", $html);
    $html = (string) preg_replace('#</t[dh]>#i', "\t", $html);
    $text = html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $lines = array_map(static fn ($l) => trim((string) preg_replace('/[ \x{a0}]+/u', ' ', $l), " \t"), explode("\n", $text));
    $lines = array_map(static fn ($l) => (string) preg_replace('/[ ]*\t[ \t]*/', "\t", $l), $lines);
    return implode("\n", array_filter($lines, static fn ($l) => $l !== ''));
}

/**
 * Reads one account movement from the text of a bank notification. The exact KB format is not
 * fixed here: labels are matched with or without diacritics, values may follow on the same
 * line (after ":" or a table cell) or inline ("VS 3211, SS 12345"). Returns ['direction' =>
 * 'in'|'out', 'tx' => a bank statement row (bank.php; id '' when the e-mail has none)] or why
 * it could not be read (Czech).
 */
function parse_payment_notification(string $text): array|string
{
    $text = str_replace(["\u{a0}", "\u{202f}", "\u{2007}", "\u{2212}"], [' ', ' ', ' ', '-'], $text);
    $number = '([+-]?)(\d{1,3}(?:[ .]\d{3})+|\d+)(?:[,.](\d{1,2}))?(?!\d)[ ]*(czk|kč|kc|eur|usd|gbp)?';

    preg_match_all('/(?<!\p{L})' . cz_pattern('(?:castka|objem)') . '(?!\p{L})[^\d+\-\n]{0,30}?' . $number . '/iu', $text, $found, PREG_SET_ORDER);
    if (!$found) {
        // No labelled amount: amounts with a currency on lines that are not a balance.
        foreach (explode("\n", $text) as $line) {
            if (!preg_match('/' . cz_pattern('zustatek|disponibilni') . '/iu', $line) && preg_match_all('/' . $number . '/iu', $line, $all, PREG_SET_ORDER)) {
                array_push($found, ...array_filter($all, static fn ($m) => ($m[4] ?? '') !== ''));
            }
        }
    }
    $amounts = array_unique(array_map(static fn ($m) => $m[1] . preg_replace('/\D/', '', $m[2]) . ',' . ($m[3] ?? ''), $found));
    if (!$amounts) {
        return 'V e-mailu není částka platby.';
    }
    if (count($amounts) > 1) {
        return 'E-mail obsahuje více částek (více pohybů?) – nelze zapsat automaticky.';
    }
    [, $sign, $whole] = $found[0];
    $currency = mb_strtolower($found[0][4] ?? '');
    if (!in_array($currency, ['', 'czk', 'kč', 'kc'], true)) {
        return 'Platba není v korunách (' . strtoupper($currency) . ').';
    }
    $cents = (int) preg_replace('/\D/', '', $whole) * 100 + (int) str_pad($found[0][3] ?? '', 2, '0');
    if ($cents === 0) {
        return 'Částka platby je nulová.';
    }

    $incoming = (bool) preg_match('/' . cz_pattern('prichozi|pripsan|kredit|prijata\s+platba') . '/iu', $text);
    $outgoing = (bool) preg_match('/' . cz_pattern('odchozi|odepsan|debet') . '/iu', $text);
    $direction = match (true) {
        $sign === '+' => 'in',
        $sign === '-' => 'out',
        $incoming && !$outgoing => 'in',
        $outgoing && !$incoming => 'out',
        default => null,
    };
    if ($direction === null) {
        return 'Z e-mailu nejde poznat, jestli jde o příchozí platbu.';
    }

    $value = static function (string $labels) use ($text): string {
        $pattern = '/(?<!\p{L})' . cz_pattern('(?:' . $labels . ')') . '(?!\p{L})[ \t]*(?::|\t)[ \t]*([^\n\t;]*)/iu';
        return preg_match($pattern, $text, $m) ? trim($m[1], " \t,.") : '';
    };
    $symbol = static function (string $labels) use ($text): string {
        $pattern = '/(?<!\p{L})' . cz_pattern('(?:' . $labels . ')') . '(?!\p{L})\s*[:.]?\s*(\d{1,10})(?!\d)/iu';
        return preg_match($pattern, $text, $m) ? ltrim($m[1], '0') : '';
    };
    preg_match('/(?<!\d)(\d{1,2})\.\s?(\d{1,2})\.\s?(\d{4})(?!\d)/', $text, $d);
    $date = $d && checkdate((int) $d[2], (int) $d[1], (int) $d[3]) ? sprintf('%04d-%02d-%02d', $d[3], $d[2], $d[1]) : null;
    preg_match('/' . cz_pattern('(?:protiucet|z\s+uctu|ucet\s+platce|ucet\s+protistrany|odesilatel)') . '[^0-9\n]{0,40}((?:\d{1,6}-)?\d{2,10}\/\d{4})/iu', $text, $account);
    preg_match('/' . cz_pattern('(?:identifikace|id|cislo|reference)\s+transakce') . '\s*:?\s*([a-z0-9][a-z0-9\-\/.]{3,60})/iu', $text, $id);

    return [
        'direction' => $direction,
        'tx' => [
            'id' => isset($id[1]) ? rtrim($id[1], '.') : '',
            'date' => $date,
            'account' => $account[1] ?? '',
            'name' => mb_substr($value('nazev\s+protiuctu|nazev\s+(?:uctu\s+)?protistrany|jmeno\s+protistrany|platce'), 0, 100),
            'cents' => $cents,
            'vs' => $symbol('vs|variabilni\s+symbol'),
            'ss' => $symbol('ss|specificky\s+symbol'),
            'message' => mb_substr($value('zprava\s+pro\s+prijemce|popis\s+pro\s+prijemce|zprava|poznamka'), 0, 140),
        ],
    ];
}

/** A regex written without diacritics that also matches the Czech letters with them ("castka" → Částka). */
function cz_pattern(string $ascii): string
{
    $letters = ['a' => '[aá]', 'c' => '[cč]', 'd' => '[dď]', 'e' => '[eéě]', 'i' => '[ií]', 'n' => '[nň]', 'o' => '[oó]',
        'r' => '[rř]', 's' => '[sš]', 't' => '[tť]', 'u' => '[uúů]', 'y' => '[yý]', 'z' => '[zž]'];
    return (string) preg_replace_callback('/(?<!\\\\)[acdeinorstuyz]/', static fn ($m) => $letters[$m[0]], $ascii);
}
