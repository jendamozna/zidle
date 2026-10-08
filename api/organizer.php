<?php
// Organizer API used by the ticket scanner (scanner.html).
//   GET  organizer.php                                  → {loggedIn}
//   POST organizer.php {action: "login", password}      → {loggedIn}
//   POST organizer.php {action: "logout"}
//   POST organizer.php {action: "verify", code}         → ticket check result, records first check-in
declare(strict_types=1);
require __DIR__ . '/lib/bootstrap.php';

session_set_cookie_params([
    'httponly' => true,
    'samesite' => 'Strict',
    'secure' => !empty($_SERVER['HTTPS']),
    'lifetime' => 60 * 60 * 24,
]);
session_name('zidle_org');

run_api(function (): void {
    $password = (string) config('ORGANIZER_PASSWORD');
    if ($password === '') {
        json_error('Nastavte ORGANIZER_PASSWORD.', 503);
    }
    session_start();

    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        json_response(['loggedIn' => !empty($_SESSION['organizer'])]);
    }
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        json_error('Metoda není povolena.', 405);
    }

    $body = read_json_body();
    switch ($body['action'] ?? '') {
        case 'login':
            if (!hash_equals($password, (string) ($body['password'] ?? ''))) {
                sleep(1);
                json_error('Nesprávné heslo.', 401);
            }
            session_regenerate_id(true);
            $_SESSION['organizer'] = true;
            json_response(['loggedIn' => true]);
            // no break – json_response exits
        case 'logout':
            $_SESSION = [];
            session_destroy();
            json_response(['loggedIn' => false]);
            // no break
        case 'verify':
            if (empty($_SESSION['organizer'])) {
                json_error('Přihlaste se.', 401);
            }
            verify_ticket((string) ($body['code'] ?? ''));
            // no break
        default:
            json_error('Neznámá akce.', 400);
    }
});

/**
 * result: valid       – paid, first scan (check-in recorded now)
 *         used        – paid, already scanned before
 *         unpaid      – reservation not paid yet
 *         cancelled   – reservation cancelled or expired
 *         invalid     – not our ticket, forged or altered
 */
function verify_ticket(string $code): void
{
    $ticket = parse_ticket_code($code);
    if ($ticket === null) {
        json_response(['result' => 'invalid']);
    }

    $pdo = db();
    $pdo->beginTransaction();
    $stmt = $pdo->prepare('SELECT * FROM reservations WHERE variable_symbol = ? FOR UPDATE');
    $stmt->execute([$ticket['variableSymbol']]);
    $r = $stmt->fetch();

    if (!$r || $r['seats'] !== implode(',', $ticket['seats'])) {
        $pdo->rollBack();
        json_response(['result' => 'invalid', 'ticket' => $ticket]);
    }

    $firstScan = $r['checked_in_at'] === null;
    if ($r['status'] === 'paid' && $firstScan) {
        $now = db_time(now_utc());
        $pdo->prepare('UPDATE reservations SET checked_in_at = ? WHERE id = ?')->execute([$now, $r['id']]);
        $r['checked_in_at'] = $now;
    }
    $pdo->commit();

    $result = match ($r['status']) {
        'paid' => $firstScan ? 'valid' : 'used',
        'pending' => 'unpaid',
        default => 'cancelled',
    };
    json_response([
        'result' => $result,
        'ticket' => $ticket,
        'checkedInAt' => iso_time($r['checked_in_at']),
        'email' => $r['email'],
    ]);
}
