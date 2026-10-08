<?php
// Organizer API used by the ticket scanner (scanner.html).
//   GET  organizer.php                                  → {loggedIn, runs}
//   POST organizer.php {action: "login", password}      → {loggedIn}
//   POST organizer.php {action: "logout"}
//   POST organizer.php {action: "verify", code, runId}  → ticket check result for the run being checked in,
//                                                          records first check-in
//   POST organizer.php {action: "vip-list", runId}      → {vips: [...]} of the run
//   POST organizer.php {action: "vip-checkin", id}      → records VIP arrival
//   POST organizer.php {action: "vip-undo", id}         → clears VIP arrival (mistake)
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
        json_response([
            'loggedIn' => !empty($_SESSION['organizer']),
            'runs' => array_values(array_map(static fn ($run) => run_public($run), runs())),
        ]);
    }
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        json_error('Metoda není povolena.', 405);
    }

    $body = read_json_body();
    switch ($body['action'] ?? '') {
        case 'login':
            if (!login_allowed('organizer')) {
                json_error('Příliš mnoho pokusů. Zkuste to za 15 minut.', 429);
            }
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
            require_organizer();
            verify_ticket((string) ($body['code'] ?? ''), (int) ($body['runId'] ?? 0));
            // no break
        case 'vip-list':
            require_organizer();
            json_response(['vips' => vip_list((int) ($body['runId'] ?? 0))]);
            // no break
        case 'vip-checkin':
        case 'vip-undo':
            require_organizer();
            $id = (int) ($body['id'] ?? 0);
            if ($body['action'] === 'vip-checkin') {
                // COALESCE keeps the first arrival time when two organizers tap at once.
                db()->prepare('UPDATE vip_guests SET checked_in_at = COALESCE(checked_in_at, ?) WHERE id = ?')
                    ->execute([db_time(now_utc()), $id]);
            } else {
                db()->prepare('UPDATE vip_guests SET checked_in_at = NULL WHERE id = ?')->execute([$id]);
            }
            json_response(['vips' => vip_list((int) ($body['runId'] ?? 0))]);
            // no break
        default:
            json_error('Neznámá akce.', 400);
    }
});

function require_organizer(): void
{
    if (empty($_SESSION['organizer'])) {
        json_error('Přihlaste se.', 401);
    }
}

function vip_list(int $runId): array
{
    $stmt = db()->prepare('SELECT id, name, section, persons, note, checked_in_at FROM vip_guests WHERE run_id = ? ORDER BY name');
    $stmt->execute([$runId]);
    $rows = $stmt->fetchAll();
    return array_map(static fn ($v) => [
        'id' => (int) $v['id'],
        'name' => $v['name'],
        'section' => $v['section'],
        'persons' => (int) $v['persons'],
        'note' => $v['note'],
        'checkedInAt' => iso_time($v['checked_in_at']),
    ], $rows);
}

/**
 * result: valid       – paid, first scan (check-in recorded now)
 *         used        – paid, already scanned before
 *         unpaid      – reservation not paid yet
 *         cancelled   – reservation cancelled or expired
 *         invalid     – not our ticket, forged or altered
 *         wrong_run   – valid reservation, but for another run (not checked in)
 */
function verify_ticket(string $code, int $runId): void
{
    $ticket = parse_ticket_code($code);
    if ($ticket === null) {
        json_response(['result' => 'invalid']);
    }

    $pdo = db();
    $pdo->beginTransaction();
    // Current state is always loaded from the database: by reservation id
    // (older tickets: by variable symbol); the VS must match as well.
    $stmt = $ticket['id'] !== null
        ? $pdo->prepare('SELECT * FROM reservations WHERE id = ? AND variable_symbol = ? FOR UPDATE')
        : $pdo->prepare('SELECT * FROM reservations WHERE variable_symbol = ? FOR UPDATE');
    $stmt->execute($ticket['id'] !== null ? [$ticket['id'], $ticket['variableSymbol']] : [$ticket['variableSymbol']]);
    $r = $stmt->fetch();

    if (!$r) {
        $pdo->rollBack();
        json_response(['result' => 'invalid', 'ticket' => $ticket]);
    }
    // Seats may have been cancelled individually since the ticket was issued:
    // the database is authoritative, the scanner shows the current seats.
    $changed = $r['seats'] !== implode(',', $ticket['seats']);

    $wrongRun = (int) $r['run_id'] !== $runId;
    $firstScan = $r['checked_in_at'] === null;
    if ($r['status'] === 'paid' && $firstScan && !$wrongRun) {
        $now = db_time(now_utc());
        $pdo->prepare('UPDATE reservations SET checked_in_at = ? WHERE id = ?')->execute([$now, $r['id']]);
        $r['checked_in_at'] = $now;
    }
    $pdo->commit();

    $result = match (true) {
        $wrongRun && in_array($r['status'], ['pending', 'paid'], true) => 'wrong_run',
        $r['status'] === 'paid' => $firstScan ? 'valid' : 'used',
        $r['status'] === 'pending' => 'unpaid',
        default => 'cancelled',
    };
    $run = run_by_id((int) $r['run_id']);
    json_response([
        'result' => $result,
        'changed' => $changed,
        'ticket' => [
            'id' => (int) $r['id'],
            'variableSymbol' => $r['variable_symbol'],
            'count' => (int) $r['seat_count'],
            'name' => trim($r['first_name'] . ' ' . $r['last_name']) ?: $ticket['name'],
            'seats' => explode(',', $r['seats']),
        ],
        'checkedInAt' => iso_time($r['checked_in_at']),
        'status' => $r['status'],
        'run' => $run ? ['id' => $run['id'], 'label' => run_label($run)] : null,
        'cancelledSeats' => $r['cancelled_seats'] === '' ? [] : explode(',', $r['cancelled_seats']),
        'email' => $r['email'],
    ]);
}
