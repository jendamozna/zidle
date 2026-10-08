<?php
// Organizer API used by the ticket scanner (scanner.html). Access: invite link
// from admin (only the invited runs) or the optional master password (all runs).
//   GET  organizer.php                                      → {loggedIn, name, runs, passwordLogin}
//   POST organizer.php {action: "invite", token}            → signs the device in with an invite
//   POST organizer.php {action: "login", password}          → master password (if enabled)
//   POST organizer.php {action: "logout"}
//   POST organizer.php {action: "verify", code, runId, confirmOutside?}
//                                                           → ticket check for the run being checked in
//   POST organizer.php {action: "vip-list", runId}          → {vips: [...]}
//   POST organizer.php {action: "vip-checkin", id, runId, confirmOutside?}
//   POST organizer.php {action: "vip-undo", id, runId}
// Outside the run's check-in window (SCAN_WINDOW_*), check-ins happen only with
// confirmOutside = true; otherwise the result is "outside_window".
declare(strict_types=1);
require __DIR__ . '/lib/bootstrap.php';

run_api(function (): void {
    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        $access = scanner_access();
        json_response([
            'loggedIn' => $access !== null,
            'name' => $access['name'] ?? null,
            'passwordLogin' => (string) config('ORGANIZER_PASSWORD') !== '',
            'runs' => $access ? array_map('scanner_run_public', scanner_runs($access)) : [],
        ]);
    }
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        json_error('Metoda není povolena.', 405);
    }

    $body = read_json_body();
    switch ($body['action'] ?? '') {
        case 'invite':
            if (!login_allowed('organizer')) {
                json_error('Příliš mnoho pokusů. Zkuste to za 15 minut.', 429);
            }
            if (!accept_invite((string) ($body['token'] ?? ''))) {
                json_error('Pozvánka neplatí – je zrušená nebo nahrazená novou. Požádejte správce o nový odkaz.', 401);
            }
            json_response(['loggedIn' => true]);
            // no break – json_response exits
        case 'login':
            $password = (string) config('ORGANIZER_PASSWORD');
            if ($password === '') {
                json_error('Přihlášení heslem je vypnuté. Použijte pozvánku od správce.', 403);
            }
            if (!login_allowed('organizer')) {
                json_error('Příliš mnoho pokusů. Zkuste to za 15 minut.', 429);
            }
            if (!hash_equals($password, (string) ($body['password'] ?? ''))) {
                sleep(1);
                json_error('Nesprávné heslo.', 401);
            }
            scanner_set_cookie(master_cookie());
            json_response(['loggedIn' => true]);
            // no break
        case 'logout':
            scanner_clear_cookie();
            json_response(['loggedIn' => false]);
            // no break
        case 'verify':
            $access = require_organizer();
            $run = require_run($access, (int) ($body['runId'] ?? 0));
            verify_ticket((string) ($body['code'] ?? ''), $run, $access, !empty($body['confirmOutside']));
            // no break
        case 'vip-list':
            $access = require_organizer();
            $run = require_run($access, (int) ($body['runId'] ?? 0));
            json_response(['vips' => vip_list($run['id'])]);
            // no break
        case 'vip-checkin':
        case 'vip-undo':
            $access = require_organizer();
            $run = require_run($access, (int) ($body['runId'] ?? 0));
            $id = (int) ($body['id'] ?? 0);
            if ($body['action'] === 'vip-checkin') {
                if (!run_in_scan_window($run) && empty($body['confirmOutside'])) {
                    json_error('Termín je mimo čas odbavení. Potvrďte odbavení mimo čas.', 409);
                }
                // The first arrival wins when two organizers tap at once.
                db()->prepare(
                    'UPDATE vip_guests SET checked_in_by = IF(checked_in_at IS NULL, ?, checked_in_by),
                       checked_in_at = COALESCE(checked_in_at, ?) WHERE id = ? AND run_id = ?'
                )->execute([$access['name'], db_time(now_utc()), $id, $run['id']]);
            } else {
                db()->prepare('UPDATE vip_guests SET checked_in_at = NULL, checked_in_by = NULL WHERE id = ? AND run_id = ?')
                    ->execute([$id, $run['id']]);
            }
            json_response(['vips' => vip_list($run['id'])]);
            // no break
        default:
            json_error('Neznámá akce.', 400);
    }
});

function require_organizer(): array
{
    $access = scanner_access();
    if ($access === null) {
        json_error('Přihlaste se pozvánkou od správce.', 401);
    }
    return $access;
}

function require_run(array $access, int $runId): array
{
    if (!scanner_can_use_run($access, $runId)) {
        json_error('Na tento termín nemáte přístup.', 403);
    }
    return run_by_id($runId);
}

function scanner_run_public(array $run): array
{
    [$from, $to] = run_scan_window($run);
    return run_public($run) + ['scanFrom' => iso_utc($from), 'scanTo' => iso_utc($to)];
}

function vip_list(int $runId): array
{
    $stmt = db()->prepare('SELECT id, name, section, persons, note, checked_in_at, checked_in_by FROM vip_guests WHERE run_id = ? ORDER BY name');
    $stmt->execute([$runId]);
    return array_map(static fn ($v) => [
        'id' => (int) $v['id'],
        'name' => $v['name'],
        'section' => $v['section'],
        'persons' => (int) $v['persons'],
        'note' => $v['note'],
        'checkedInAt' => iso_time($v['checked_in_at']),
        'checkedInBy' => $v['checked_in_by'],
    ], $stmt->fetchAll());
}

/**
 * result: valid          – paid, first scan (check-in recorded now)
 *         used           – paid, already scanned before
 *         unpaid         – reservation not paid yet
 *         cancelled      – reservation cancelled or expired
 *         invalid        – not our ticket, forged or altered
 *         wrong_run      – valid reservation, but for another run (not checked in)
 *         outside_window – would be valid, but the run is outside its check-in window
 *                          and the organizer did not confirm (not checked in)
 */
function verify_ticket(string $code, array $run, array $access, bool $confirmOutside): void
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

    $wrongRun = (int) $r['run_id'] !== $run['id'];
    $firstScan = $r['checked_in_at'] === null;
    $outside = !run_in_scan_window($run) && !$confirmOutside;
    if ($r['status'] === 'paid' && $firstScan && !$wrongRun && !$outside) {
        $now = db_time(now_utc());
        $pdo->prepare('UPDATE reservations SET checked_in_at = ?, checked_in_by = ? WHERE id = ?')
            ->execute([$now, $access['name'], $r['id']]);
        $r['checked_in_at'] = $now;
        $r['checked_in_by'] = $access['name'];
    }
    $pdo->commit();

    $result = match (true) {
        $wrongRun && in_array($r['status'], ['pending', 'paid'], true) => 'wrong_run',
        $r['status'] === 'paid' && !$firstScan => 'used',
        $r['status'] === 'paid' && $outside => 'outside_window',
        $r['status'] === 'paid' => 'valid',
        $r['status'] === 'pending' => 'unpaid',
        default => 'cancelled',
    };
    $ticketRun = run_by_id((int) $r['run_id']);
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
        'checkedInBy' => $r['checked_in_by'],
        'status' => $r['status'],
        'run' => $ticketRun ? ['id' => $ticketRun['id'], 'label' => run_label($ticketRun)] : null,
        'cancelledSeats' => $r['cancelled_seats'] === '' ? [] : explode(',', $r['cancelled_seats']),
        'email' => $r['email'],
    ]);
}
