<?php
declare(strict_types=1);

require_once __DIR__ . '/layout.php';

function config(string $key)
{
    static $config = null;
    if ($config === null) {
        $config = require __DIR__ . '/../config.php';
    }
    return $config[$key] ?? null;
}

function db(): PDO
{
    static $pdo = null;
    if ($pdo === null) {
        $dsn = sprintf(
            'mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4',
            config('DB_HOST'),
            config('DB_PORT'),
            config('DB_NAME')
        );
        $pdo = new PDO($dsn, (string) config('DB_USER'), (string) config('DB_PASS'), [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);
        $pdo->exec("SET time_zone = '+00:00'");
    }
    return $pdo;
}

function now_utc(): DateTimeImmutable
{
    return new DateTimeImmutable('now', new DateTimeZone('UTC'));
}

function db_time(DateTimeImmutable $t): string
{
    return $t->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
}

function iso_time(?string $dbTime): ?string
{
    if ($dbTime === null) {
        return null;
    }
    return (new DateTimeImmutable($dbTime, new DateTimeZone('UTC')))->format('Y-m-d\TH:i:s\Z');
}

// ---------------------------------------------------------------- HTTP

function send_cors(): void
{
    $origin = (string) config('CORS_ORIGIN');
    if ($origin !== '') {
        header('Access-Control-Allow-Origin: ' . $origin);
        header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
        header('Access-Control-Allow-Headers: Content-Type');
        header('Vary: Origin');
    }
    if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') {
        http_response_code(204);
        exit;
    }
}

function json_response($data, int $status = 200): void
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function json_error(string $message, int $status, array $extra = []): void
{
    json_response(['error' => $message] + $extra, $status);
}

function read_json_body(): array
{
    $raw = file_get_contents('php://input');
    $data = json_decode($raw === false ? '' : $raw, true);
    if (!is_array($data)) {
        json_error('Neplatný požadavek.', 400);
    }
    return $data;
}

function run_api(callable $handler): void
{
    send_cors();
    try {
        $handler();
    } catch (Throwable $e) {
        error_log('[zidle] ' . $e);
        json_error('Chyba serveru. Zkuste to prosím znovu.', 500);
    }
}

// ---------------------------------------------------------------- Domain

/**
 * Marks unpaid reservations past their deadline as expired and frees their
 * seats. Called on every API request and by cron-expire.php.
 */
function expire_reservations(): int
{
    $pdo = db();
    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare(
            "UPDATE reservations SET status = 'expired', cancelled_at = :now
             WHERE status = 'pending' AND expires_at <= :now2"
        );
        $now = db_time(now_utc());
        $stmt->execute(['now' => $now, 'now2' => $now]);
        $count = $stmt->rowCount();
        $pdo->exec(
            "DELETE rs FROM reservation_seats rs
             JOIN reservations r ON r.id = rs.reservation_id
             WHERE r.status IN ('expired', 'cancelled')"
        );
        $pdo->commit();
        return $count;
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
}

function strip_diacritics(string $text): string
{
    $converted = class_exists('Transliterator')
        ? Transliterator::create('Any-Latin; Latin-ASCII')->transliterate($text)
        : iconv('UTF-8', 'ASCII//TRANSLIT', $text);
    return (string) $converted;
}

/** Czech QR payment string (Short Payment Descriptor, "QR Platba"). */
function spd_string(array $r): string
{
    $clean = static fn (string $v, int $max) =>
        substr(trim(preg_replace('/[^A-Za-z0-9 .,\/+\-:]/', '', strip_diacritics($v))), 0, $max);

    $acc = strtoupper(str_replace(' ', '', (string) config('BANK_IBAN')));
    $bic = trim((string) config('BANK_BIC'));
    $parts = [
        'SPD',
        '1.0',
        'ACC:' . $acc . ($bic !== '' ? '+' . $bic : ''),
        'AM:' . number_format((float) $r['amount'], 2, '.', ''),
        'CC:CZK',
        'X-VS:' . $r['variable_symbol'],
        'DT:' . (new DateTimeImmutable($r['expires_at'], new DateTimeZone('UTC')))
            ->setTimezone(new DateTimeZone('Europe/Prague'))->format('Ymd'),
        'MSG:' . $clean(config('PAYMENT_MESSAGE') . ' ' . $r['last_name'], 60),
    ];
    $recipient = $clean((string) config('PAYMENT_RECIPIENT'), 35);
    if ($recipient !== '') {
        $parts[] = 'RN:' . $recipient;
    }
    return implode('*', $parts);
}

function reservation_payload(array $r): array
{
    return [
        'token' => $r['token'],
        'status' => $r['status'],
        'firstName' => $r['first_name'],
        'lastName' => $r['last_name'],
        'email' => $r['email'],
        'seats' => $r['seats'] === '' ? [] : explode(',', $r['seats']),
        'amount' => (int) $r['amount'],
        'createdAt' => iso_time($r['created_at']),
        'expiresAt' => iso_time($r['expires_at']),
        'paidAt' => iso_time($r['paid_at']),
        'payment' => [
            'iban' => (string) config('BANK_IBAN'),
            'account' => (string) config('BANK_ACCOUNT_DISPLAY'),
            'recipient' => (string) config('PAYMENT_RECIPIENT'),
            'variableSymbol' => $r['variable_symbol'],
            'amount' => (int) $r['amount'],
            'currency' => 'CZK',
            'spd' => spd_string($r),
        ],
    ];
}

function find_reservation_by_token(string $token): ?array
{
    $stmt = db()->prepare('SELECT * FROM reservations WHERE token = ?');
    $stmt->execute([$token]);
    $row = $stmt->fetch();
    return $row ?: null;
}

function send_payment_email(array $r): void
{
    if (!filter_var(config('MAIL_ENABLED'), FILTER_VALIDATE_BOOLEAN)) {
        return;
    }
    $deadline = (new DateTimeImmutable($r['expires_at'], new DateTimeZone('UTC')))
        ->setTimezone(new DateTimeZone('Europe/Prague'))->format('j. n. Y H:i');
    $link = rtrim((string) config('PUBLIC_URL'), '/');
    $lines = [
        "Dobrý den, {$r['first_name']} {$r['last_name']},",
        '',
        'děkujeme za rezervaci míst: ' . str_replace(',', ', ', $r['seats']) . '.',
        '',
        'Platební údaje:',
        'Částka: ' . number_format((int) $r['amount'], 0, ',', ' ') . ' Kč',
        'Účet: ' . config('BANK_ACCOUNT_DISPLAY') . ' (IBAN ' . config('BANK_IBAN') . ')',
        'Variabilní symbol: ' . $r['variable_symbol'],
        "Splatnost: {$deadline}",
        '',
        'Pokud platba nedorazí do data splatnosti, rezervace bude zrušena a místa uvolněna.',
    ];
    if ($link !== '') {
        $lines[] = '';
        $lines[] = 'QR kód pro platbu: ' . $link . '/?r=' . $r['token'];
    }
    $headers = [
        'From: ' . config('MAIL_FROM'),
        'Content-Type: text/plain; charset=UTF-8',
        'Content-Transfer-Encoding: 8bit',
    ];
    $subject = '=?UTF-8?B?' . base64_encode('Rezervace míst – Moje židle 2026') . '?=';
    if (!@mail($r['email'], $subject, implode("\r\n", $lines), implode("\r\n", $headers))) {
        error_log('[zidle] Failed to send e-mail for reservation ' . $r['id']);
    }
}
