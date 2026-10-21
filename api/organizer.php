<?php
// Organizer API used by the ticket scanner (scanner.html). Access: invite link
// from admin (only the invited runs) or the optional master password (all runs).
//   GET  organizer.php                                      → {loggedIn, name, runs, passwordLogin, layout}
//   POST organizer.php {action: "invite", token}            → signs the device in with an invite
//   POST organizer.php {action: "login", password}          → master password (if enabled)
//   POST organizer.php {action: "logout"}
//   POST organizer.php {action: "verify", code, runId, confirmOutside?}
//                                                           → ticket check for the run being checked in
//   POST organizer.php {action: "vip-list", runId}          → {vips: [...]}
//   POST organizer.php {action: "vip-checkin", id, runId, confirmOutside?}
//   POST organizer.php {action: "vip-undo", id, runId}
//   POST organizer.php {action: "snapshot", runId}          → list for checking tickets offline (scanner_snapshot())
//   POST organizer.php {action: "sync", runId, events}      → check-ins made offline (apply_offline_scans())
//   POST organizer.php {action: "sale-seats", runId}        → {taken, price, childPrice, maxSeats} for selling on site
//   POST organizer.php {action: "sale-create", runId, seats, children, infants} → {sale} with the QR payment (onsite.php)
//   POST organizer.php {action: "sale-paid", runId, id}     → paid and checked in
//   POST organizer.php {action: "sale-cancel", runId, id}   → seats free again
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
            'layout' => layout_public(),
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
            json_response(verify_ticket((string) ($body['code'] ?? ''), $run, $access, !empty($body['confirmOutside'])));
            // no break
        case 'vip-list':
            $access = require_organizer();
            $run = require_run($access, (int) ($body['runId'] ?? 0));
            json_response(['vips' => vip_list($run['id'])]);
            // no break
        case 'snapshot':
            $access = require_organizer();
            $run = require_run($access, (int) ($body['runId'] ?? 0));
            json_response(scanner_snapshot($run));
            // no break
        case 'sync':
            $access = require_organizer();
            $run = require_run($access, (int) ($body['runId'] ?? 0));
            json_response(apply_offline_scans($access, $run, is_array($body['events'] ?? null) ? $body['events'] : []));
            // no break
        case 'sale-seats':
            $access = require_organizer();
            $run = require_run($access, (int) ($body['runId'] ?? 0));
            expire_reservations();
            json_response([
                'taken' => run_taken_seats($run['id']),
                'price' => adult_price(),
                'childPrice' => child_price(),
                'maxSeats' => (int) config('MAX_SEATS_PER_RESERVATION'),
            ]);
            // no break
        case 'sale-create':
            $access = require_organizer();
            $run = require_run($access, (int) ($body['runId'] ?? 0));
            $sold = onsite_sale_create($access, $run, $body['seats'] ?? null, $body['children'] ?? 0, $body['infants'] ?? 0);
            if (isset($sold['error'])) {
                json_error($sold['error'], $sold['status'], $sold['extra']);
            }
            json_response($sold, 201);
            // no break
        case 'sale-paid':
            $access = require_organizer();
            $run = require_run($access, (int) ($body['runId'] ?? 0));
            $message = onsite_sale_paid($access, $run, (int) ($body['id'] ?? 0));
            if ($message === null) {
                json_error('Prodej už není otevřený.', 409);
            }
            json_response(['message' => $message]);
            // no break
        case 'sale-cancel':
            $access = require_organizer();
            $run = require_run($access, (int) ($body['runId'] ?? 0));
            if (!onsite_sale_cancel($run, (int) ($body['id'] ?? 0))) {
                json_error('Prodej už není otevřený.', 409);
            }
            json_response(['message' => 'Prodej zrušen, místa jsou volná.']);
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
    $run = run_by_id($runId);
    if ($run === null || !scanner_can_use_run($access, $runId)) {
        json_error('Na tento termín nemáte přístup.', 403);
    }
    return $run;
}

function scanner_run_public(array $run): array
{
    [$from, $to] = run_scan_window($run);
    return run_public($run) + ['scanFrom' => iso_utc($from), 'scanTo' => iso_utc($to)];
}
