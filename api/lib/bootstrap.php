<?php
declare(strict_types=1);

require_once __DIR__ . '/layout.php';
require_once __DIR__ . '/ticket.php';
require_once __DIR__ . '/antispam.php';
require_once __DIR__ . '/settings.php';
require_once __DIR__ . '/mail.php';
require_once __DIR__ . '/cancellation.php';
require_once __DIR__ . '/scanner_access.php';
require_once __DIR__ . '/altcha.php';

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
        // expires_at is the due date shown to the customer; the reservation is
        // only cancelled PAYMENT_GRACE_HOURS later, so a transfer sent on the
        // last day still arrives before the seats are released.
        $stmt = $pdo->prepare(
            "UPDATE reservations SET status = 'expired', cancelled_at = :now
             WHERE status = 'pending' AND expires_at <= :cutoff"
        );
        $now = now_utc();
        $cutoff = $now->modify('-' . (int) config('PAYMENT_GRACE_HOURS') . ' hours');
        $stmt->execute(['now' => db_time($now), 'cutoff' => db_time($cutoff)]);
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

/** Specific symbol from config, digits only (max 10), '' when not set. */
function specific_symbol(): string
{
    return substr(preg_replace('/\D/', '', (string) config('PAYMENT_SPECIFIC_SYMBOL')), 0, 10);
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
    $ss = specific_symbol();
    if ($ss !== '') {
        $parts[] = 'X-SS:' . $ss;
    }
    $recipient = $clean((string) config('PAYMENT_RECIPIENT'), 35);
    if ($recipient !== '') {
        $parts[] = 'RN:' . $recipient;
    }
    return implode('*', $parts);
}

function reservation_payload(array $r): array
{
    $ticket = $r['status'] === 'paid' && strlen((string) config('TICKET_SECRET')) >= 16 ? ticket_code($r) : null;
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
        'ticket' => $ticket,
        'checkedInAt' => iso_time($r['checked_in_at']),
        'cancellation' => cancellation_terms($r),
        'cancelledBy' => $r['cancelled_by'],
        'cancelFee' => $r['cancel_fee'] === null ? null : (int) $r['cancel_fee'],
        'refundAmount' => $r['refund_amount'] === null ? null : (int) $r['refund_amount'],
        'refundedAmount' => (int) $r['refunded_amount'],
        'cancelledSeats' => $r['cancelled_seats'] === '' ? [] : explode(',', $r['cancelled_seats']),
        'refundedAt' => iso_time($r['refunded_at']),
        'run' => ($run = run_by_id((int) $r['run_id'])) ? run_public($run) : null,
        'payment' => [
            'iban' => (string) config('BANK_IBAN'),
            'account' => (string) config('BANK_ACCOUNT_DISPLAY'),
            'recipient' => (string) config('PAYMENT_RECIPIENT'),
            'variableSymbol' => $r['variable_symbol'],
            'specificSymbol' => specific_symbol() ?: null,
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
