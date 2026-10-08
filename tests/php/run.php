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
