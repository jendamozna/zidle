<?php
// Integration tests of the money, seat and VIP logic against a real MariaDB.
//
//   DB_NAME=zidle_test MAIL_ENABLED=0 TICKET_SECRET=test-secret-0123456789 php tests/php/run.php
//
// The database is wiped and recreated from db/schema.sql, so DB_NAME must end with "_test".
declare(strict_types=1);

require __DIR__ . '/../../api/lib/bootstrap.php';

require __DIR__ . '/fixtures.php';

final class TestFailure extends Exception
{
}

/** @var array<string, callable(): void> $tests */
$tests = [];

function test(string $name, callable $fn): void
{
    $GLOBALS['tests'][$name] = $fn;
}

function same(mixed $expected, mixed $actual, string $what): void
{
    if ($expected !== $actual) {
        throw new TestFailure($what . ': expected ' . var_export($expected, true) . ', got ' . var_export($actual, true));
    }
}

function contains(string $needle, string $haystack, string $what): void
{
    if (!str_contains($haystack, $needle)) {
        throw new TestFailure("{$what}: '{$needle}' not found in '{$haystack}'");
    }
}

function row(int $id): array
{
    $stmt = db()->prepare('SELECT * FROM reservations WHERE id = ?');
    $stmt->execute([$id]);
    $r = $stmt->fetch();
    if (!$r) {
        throw new TestFailure("reservation {$id} not found");
    }
    return $r;
}

/** Lets the reservation's due date + grace pass and runs the expiry. */
function expire(int $id): void
{
    db()->prepare('UPDATE reservations SET expires_at = ? WHERE id = ?')
        ->execute([db_time(now_utc()->modify('-' . ((int) config('PAYMENT_GRACE_HOURS') + 1) . ' hours')), $id]);
    expire_reservations();
}

/** Seat ids held in the run, with the holder ("r<id>" or "v<id>"). */
function held(int $run): array
{
    $stmt = db()->prepare('SELECT seat_id, COALESCE(CONCAT("r", reservation_id), CONCAT("v", vip_guest_id)) FROM reservation_seats WHERE run_id = ?');
    $stmt->execute([$run]);
    return $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
}

function money(int $id): array
{
    $r = row($id);
    return [$r['status'], (int) $r['paid_amount'], $r['refund_amount'] === null ? null : (int) $r['refund_amount'], amount_due($r)];
}

// ---------------------------------------------------------------- payments

test('partial payment stays pending, the rest completes it and a surplus is refunded', function (): void {
    $id = reservation(['WL-1-1', 'WL-1-2']);
    contains('zbývá doplatit 300 Kč', record_payment($id, 300), 'message');
    same(['pending', 300, null, 300], money($id), 'after 300');
    contains('Přeplatek 100 Kč', record_payment($id, 400), 'message');
    same(['paid', 700, 100, 0], money($id), 'after 700');
});

test('payment code (SPD) asks only for the missing amount', function (): void {
    $id = reservation(['WL-1-3', 'WL-1-4']);
    record_payment($id, 200);
    contains('*AM:400.00*', spd_string(row($id)), 'SPD');
    same(400, reservation_payload(row($id))['payment']['amount'], 'payload amount');
    same(200, reservation_payload(row($id))['payment']['received'], 'payload received');
});

test('late payment restores an expired reservation when the seats are free', function (): void {
    $id = reservation(['WL-1-5']);
    expire($id);
    same('expired', row($id)['status'], 'expired');
    same([], array_keys(array_filter(held(1), fn ($h) => $h === "r{$id}")), 'seats freed');
    contains('rezervace obnovena', record_payment($id, 300), 'message');
    same(['paid', 300, null, 0], money($id), 'restored');
    same("r{$id}", held(1)['WL-1-5'] ?? null, 'seat held again');
});

test('late payment is refunded when someone else took the seats', function (): void {
    $id = reservation(['WL-1-6']);
    expire($id);
    reservation(['WL-1-6']);
    contains('místa mezitím obsadil někdo jiný', record_payment($id, 300), 'message');
    same(['expired', 300, 300, 300], money($id), 'refund');
});

test('late payment after the run is refunded', function (): void {
    $id = reservation(['WL-2-1'], 'pending', 2);
    expire($id);
    contains('platba dorazila až po představení', record_payment($id, 300), 'message');
    same(['expired', 300, 300, 300], money($id), 'refund');
});

test('late payment that does not cover the price is refunded', function (): void {
    $id = reservation(['WL-2-2']);
    expire($id);
    contains('nepokrývá celou částku', record_payment($id, 100), 'message');
    same(['expired', 100, 100, 300], money($id), 'refund');
});

test('expiry refunds a partial payment; paying the rest later restores the reservation', function (): void {
    $id = reservation(['WL-2-3', 'WL-2-4']);
    record_payment($id, 200);
    expire($id);
    same(['expired', 200, 200, 600], money($id), 'expired with refund');
    record_payment($id, 400);
    same(['paid', 600, null, 0], money($id), 'restored, refund cancelled');
});

test('payment for a cancelled reservation is refunded', function (): void {
    $id = reservation(['WL-2-5']);
    cancel_seats($id, null, 'customer');
    contains('rezervace už byla zrušená', record_payment($id, 300), 'message');
    same(['cancelled', 300, 300, 300], money($id), 'refund');
});

test('a second payment for a paid reservation is refunded', function (): void {
    $id = reservation(['WL-2-6'], 'paid');
    contains('platbu navíc', record_payment($id, 300), 'message');
    same(['paid', 600, 300, 0], money($id), 'refund');
});

test('invalid amounts are rejected', function (): void {
    $id = reservation(['WL-3-1']);
    same('Zadejte přijatou částku v Kč.', record_payment($id, 0), 'zero');
    same(['pending', 0, null, 300], money($id), 'unchanged');
});

// ------------------------------------------------------------ cancellation

test('customer storno fee applies to a paid reservation', function (): void {
    $id = reservation(['WL-3-2', 'WL-3-3'], 'paid');
    $terms = cancellation_terms(row($id));
    same([true, 50, 300, 300], [$terms['allowed'], $terms['percent'], $terms['fee'], $terms['refund']], 'terms');
    cancel_seats($id, ['WL-3-3'], 'customer');
    $r = row($id);
    same(['paid', 'WL-3-2', 300, 150, 150], [$r['status'], $r['seats'], (int) $r['amount'], (int) $r['cancel_fee'], (int) $r['refund_amount']], 'after one seat');
    same(false, isset(held(1)['WL-3-3']), 'seat freed');
});

test('admin cancellation refunds a paid reservation in full', function (): void {
    $id = reservation(['WL-3-4'], 'paid');
    cancel_seats($id, null, 'admin');
    $r = row($id);
    same(['cancelled', 0, 300], [$r['status'], (int) $r['cancel_fee'], (int) $r['refund_amount']], 'refund');
});

test('cancelling a partly paid pending reservation refunds what arrived', function (): void {
    $id = reservation(['WL-3-5', 'WL-3-6']);
    record_payment($id, 200);
    same(200, cancellation_terms(row($id))['refund'], 'terms refund');
    cancel_seats($id, null, 'customer');
    same(['cancelled', 200, 200, 600], money($id), 'refund');
});

test('cancelling seats of a pending reservation completes it when the payment now covers it', function (): void {
    $id = reservation(['WL-4-1', 'WL-4-2', 'WL-4-3']);
    record_payment($id, 600);
    cancel_seats($id, ['WL-4-3'], 'customer');
    same(['paid', 600, null, 0], money($id), 'completed');
});

test('a seat cannot be held twice in the same run', function (): void {
    reservation(['WL-4-4']);
    try {
        reservation(['WL-4-4']);
    } catch (PDOException $e) {
        same('23000', $e->getCode(), 'duplicate key');
        return;
    }
    throw new TestFailure('second reservation of the same seat was accepted');
});

// --------------------------------------------------------------------- VIP

test('VIP guests hold their seats; taken seats are refused; removing frees them', function (): void {
    reservation(['ML-1-1']);
    contains('už jsou obsazená', add_vip_guest(1, 'Host', ['ML-1-1', 'ML-1-2'], ''), 'conflict');
    same(false, isset(held(1)['ML-1-2']), 'nothing held after a conflict');
    contains('přidán (2 místa)', add_vip_guest(1, 'Host', ['ML-1-3', 'ML-1-2'], 'pozn.'), 'added');
    $vip = db_query("SELECT * FROM vip_guests WHERE name = 'Host'")->fetch();
    same(['ML-1-2,ML-1-3', 'ML', 2], [$vip['seats'], $vip['section'], (int) $vip['persons']], 'stored sorted');
    same('v' . $vip['id'], held(1)['ML-1-2'] ?? null, 'held by VIP');
    same('Vyberte na plánku 1 až 50 míst.', add_vip_guest(1, 'Jiný', ['XX-1-1'], ''), 'invalid seat');
    db()->prepare('DELETE FROM vip_guests WHERE id = ?')->execute([$vip['id']]);
    same(false, isset(held(1)['ML-1-2']), 'freed on delete');
});

// -------------------------------------------------------------------- GDPR

test('personal data is purged after the event except while money is still to return', function (): void {
    $refund = reservation(['BL-1-1'], 'paid');
    record_payment($refund, 300); // paid twice → 300 to return
    $done = reservation(['BL-1-2'], 'paid');
    db()->exec('UPDATE runs SET starts_at = starts_at - INTERVAL 400 DAY');
    runs(true);
    try {
        purge_personal_data();
        same('test' . substr(row($refund)['last_name'], 1) . '@example.com', row($refund)['email'], 'kept while refund due');
        same('', row($done)['email'], 'purged');
        db()->prepare('UPDATE reservations SET refunded_amount = refund_amount WHERE id = ?')->execute([$refund]);
        purge_personal_data();
        same('', row($refund)['email'], 'purged after refund');
    } finally {
        db()->exec('UPDATE runs SET starts_at = starts_at + INTERVAL 400 DAY');
        runs(true);
    }
});

// ------------------------------------------------------------------ tickets

test('ticket code round-trips and rejects tampering', function (): void {
    $r = row(reservation(['BC-1-1', 'BC-1-2'], 'paid'));
    $code = ticket_code($r);
    $parsed = parse_ticket_code($code);
    same(true, $parsed !== null, 'parsed');
    same(null, parse_ticket_code(str_replace('BC-1-2', 'BC-1-3', $code)), 'tampered');
});

// ------------------------------------------------------------------- layout

test('the layout for the apps lists all sections in order with their shape', function (): void {
    $layout = layout_public();
    same(array_keys(SECTIONS), array_column($layout['sections'], 'id'), 'order');
    same(total_capacity(), array_sum(array_map(fn ($s) => $s['rows'] * $s['seatsPerRow'], $layout['sections'])), 'capacity');
    same(['left', 'left', 'right', 'right', 'balcony', 'balcony', 'balcony'], array_column($layout['sections'], 'group'), 'groups');
    same(array_keys(LEVELS), array_values(array_unique(array_column($layout['sections'], 'level'))), 'levels used');
    foreach ($layout['sections'] as $section) {
        same(true, is_valid_seat_id("{$section['id']}-{$section['rows']}-{$section['seatsPerRow']}"), "last seat of {$section['id']}");
    }
});

// ------------------------------------------------------------------ offline

/** Scanner access as scanner_access() returns it. */
function device(string $name): array
{
    return ['type' => 'invite', 'id' => 1, 'name' => $name, 'runIds' => null];
}

function conflicts(): array
{
    return db_query('SELECT reason, label, scanned_by, other_by FROM scan_conflicts ORDER BY id')->fetchAll();
}

test('snapshot lists the run\'s tickets without e-mails and with VIP guests', function (): void {
    $paid = reservation(['BR-1-1', 'BR-1-2'], 'paid', 1, 'Seznamová');
    add_vip_guest(1, 'VIP Seznam', ['BR-2-1'], '');
    $snapshot = scanner_snapshot(run_by_id(1) ?? []);
    $ticket = array_values(array_filter($snapshot['tickets'], fn ($t) => $t['id'] === $paid))[0] ?? null;
    same(['Test Seznamová', ['BR-1-1', 'BR-1-2'], 'paid', null], [$ticket['name'] ?? null, $ticket['seats'] ?? null, $ticket['status'] ?? null, $ticket['checkedInAt'] ?? null], 'ticket');
    same(false, array_key_exists('email', $ticket ?? []), 'no e-mail');
    same(true, in_array('VIP Seznam', array_column($snapshot['vips'], 'name'), true), 'VIP included');
});

test('offline check-ins are applied with the device time; the first one wins', function (): void {
    db()->exec('DELETE FROM scan_conflicts');
    $id = reservation(['BR-1-3'], 'paid');
    $vs = row($id)['variable_symbol'];
    $at = now_utc()->modify('-10 minutes');
    $event = ['type' => 'ticket', 'id' => $id, 'variableSymbol' => $vs, 'at' => iso_utc($at)];
    $res = apply_offline_scans(device('Vchod A'), run_by_id(1) ?? [], [$event]);
    same([['status' => 'ok']], $res['results'], 'applied');
    same([db_time($at), 'Vchod A (offline)'], [row($id)['checked_in_at'], row($id)['checked_in_by']], 'stored');
    // The same event sent again (response lost) is not a conflict.
    same(0, apply_offline_scans(device('Vchod A'), run_by_id(1) ?? [], [$event])['conflicts'], 'resend');
    // Another device let the same ticket in offline too.
    $other = ['type' => 'ticket', 'id' => $id, 'variableSymbol' => $vs, 'at' => iso_utc($at->modify('+2 minutes'))];
    $res = apply_offline_scans(device('Vchod B'), run_by_id(1) ?? [], [$other]);
    same([1, 'already_checked_in'], [$res['conflicts'], $res['results'][0]['reason'] ?? null], 'conflict');
    same(db_time($at), row($id)['checked_in_at'], 'first check-in kept');
    $c = conflicts();
    same(['already_checked_in', 'Vchod B (offline)', 'Vchod A (offline)'], [$c[0]['reason'], $c[0]['scanned_by'], $c[0]['other_by']], 'conflict row');
    apply_offline_scans(device('Vchod B'), run_by_id(1) ?? [], [$other]);
    same(1, count(conflicts()), 'conflict recorded once');
});

test('offline scans of unpaid, unknown or other-run tickets become conflicts', function (): void {
    db()->exec('DELETE FROM scan_conflicts');
    $pending = reservation(['BR-1-4']);
    $otherRun = reservation(['BR-1-5'], 'paid', 2);
    $events = [
        ['type' => 'ticket', 'id' => $pending, 'variableSymbol' => row($pending)['variable_symbol'], 'at' => iso_utc(now_utc())],
        ['type' => 'ticket', 'id' => $otherRun, 'variableSymbol' => row($otherRun)['variable_symbol'], 'at' => iso_utc(now_utc())],
        ['type' => 'ticket', 'id' => 999999, 'variableSymbol' => '1', 'at' => iso_utc(now_utc())],
    ];
    $res = apply_offline_scans(device('Vchod A'), run_by_id(1) ?? [], $events);
    same(['not_paid', 'unknown', 'unknown'], array_column($res['results'], 'reason'), 'reasons');
    same(null, row($otherRun)['checked_in_at'], 'other run untouched');
});

test('device time is limited to the last 48 hours and never in the future', function (): void {
    $future = reservation(['BR-2-2'], 'paid');
    $old = reservation(['BR-2-3'], 'paid');
    apply_offline_scans(device('Vchod A'), run_by_id(1) ?? [], [
        ['type' => 'ticket', 'id' => $future, 'variableSymbol' => row($future)['variable_symbol'], 'at' => iso_utc(now_utc()->modify('+3 hours'))],
        ['type' => 'ticket', 'id' => $old, 'variableSymbol' => row($old)['variable_symbol'], 'at' => '2001-01-01T00:00:00Z'],
    ]);
    same(true, row($future)['checked_in_at'] <= db_time(now_utc()), 'not in the future');
    same(true, row($old)['checked_in_at'] >= db_time(now_utc()->modify('-49 hours')), 'not older than 48 h');
});

test('offline VIP arrivals: first wins, undo takes an arrival back', function (): void {
    db()->exec('DELETE FROM scan_conflicts');
    add_vip_guest(1, 'VIP Offline', ['BR-2-4'], '');
    $vip = (int) db_query("SELECT id FROM vip_guests WHERE name = 'VIP Offline'")->fetchColumn();
    $at = iso_utc(now_utc()->modify('-5 minutes'));
    same('ok', apply_offline_scans(device('Vchod A'), run_by_id(1) ?? [], [['type' => 'vip-checkin', 'id' => $vip, 'at' => $at]])['results'][0]['status'], 'arrival');
    $res = apply_offline_scans(device('Vchod B'), run_by_id(1) ?? [], [['type' => 'vip-checkin', 'id' => $vip, 'at' => iso_utc(now_utc())]]);
    same('already_checked_in', $res['results'][0]['reason'] ?? null, 'second device');
    apply_offline_scans(device('Vchod A'), run_by_id(1) ?? [], [['type' => 'vip-undo', 'id' => $vip]]);
    same(null, db_query("SELECT checked_in_at FROM vip_guests WHERE id = {$vip}")->fetchColumn(), 'undone');
});

// ---------------------------------------------------------------------- run

reset_database();
$failed = 0;
foreach ($tests as $name => $fn) {
    try {
        $fn();
        echo "✓ {$name}\n";
    } catch (Throwable $e) {
        $failed++;
        echo "✗ {$name}\n    " . ($e instanceof TestFailure ? $e->getMessage() : get_class($e) . ': ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine()) . "\n";
    }
}
echo "\n" . (count($tests) - $failed) . ' / ' . count($tests) . " passed\n";
exit($failed > 0 ? 1 : 0);
