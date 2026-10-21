<?php
// Integration tests of the money, seat, discount code and ticket logic against a real MariaDB.
//
//   DB_NAME=zidle_test MAIL_ENABLED=0 TICKET_SECRET=test-secret-0123456789 BANK_IBAN=CZ6508000000192000145399 php tests/php/run.php
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

/** Seat ids held in the run, with the holder ("r<id>"). */
function held(int $run): array
{
    $stmt = db()->prepare('SELECT seat_id, CONCAT("r", reservation_id) FROM reservation_seats WHERE run_id = ?');
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

// ------------------------------------------------- prices and discount codes

/** A fresh unused discount code (normalized), created as the admin would. */
function new_code(string $email = 'kod@example.com'): string
{
    create_discount_codes($email, 'test');
    return (string) db_query('SELECT code FROM discount_codes ORDER BY id DESC LIMIT 1')->fetchColumn();
}

function book(array $seats, int $children = 0, array $codes = []): array
{
    return book_reservation(run_by_id(1) ?? [], 'Jana', 'Kódová', 'kody@example.com', $seats, $children, $codes);
}

test('child seats are the last seats, codes free the most expensive seats first', function (): void {
    $seats = ['ML-1-3', 'ML-1-1', 'ML-1-2'];
    $children = assign_child_seats($seats, 1);
    same(['ML-1-3'], $children, 'last seat is the child seat');
    same(['ML-1-1', 'ML-1-2'], assign_free_seats($seats, $children, 2, 300, 150), 'adult seats first');
    same(['ML-1-1', 'ML-1-2', 'ML-1-3'], assign_free_seats($seats, $children, 3, 300, 150), 'then the child seat');
    same(['ML-1-1'], assign_free_seats($seats, assign_child_seats($seats, 2), 1, 300, 150), 'the one adult seat');
    same([], assign_free_seats($seats, $children, 0, 300, 150), 'no code');
});

test('a reservation with a child seat and a code costs adult + child − the adult seat', function (): void {
    $code = new_code();
    $booked = book(['MR-1-1', 'MR-1-2', 'MR-1-3'], 1, [$code]);
    $r = $booked['reservation'] ?? [];
    same(['pending', 'MR-1-3', 'MR-1-1', 450], [$r['status'], $r['child_seats'], $r['free_seats'], (int) $r['amount']], 'reservation');
    $used = db()->prepare('SELECT used_at IS NOT NULL, reservation_id, seat_id FROM discount_codes WHERE code = ?');
    $used->execute([$code]);
    same([1, (int) $r['id'], 'MR-1-1'], array_map(fn ($v) => is_numeric($v) ? (int) $v : $v, $used->fetch(PDO::FETCH_NUM) ?: []), 'code used for the seat');
    same([['MR-1-1', 'adult', 0, true], ['MR-1-2', 'adult', 300, false], ['MR-1-3', 'child', 150, false]],
        array_map(fn ($t) => [$t['seat'], $t['kind'], $t['price'], $t['free']], reservation_payload($r)['tickets']), 'tickets in the payload');
    same('2 dospělí, 1 dítě 4–17 let', seat_kinds_summary($r), 'summary');
});

test('a discount code works only once and not after it was revoked', function (): void {
    $code = new_code();
    same(true, isset(book(['MR-2-1'], 0, [$code])['reservation']), 'first use');
    same('Slevový kód ' . format_discount_code($code) . ' neplatí nebo už byl použit.', book(['MR-2-2'], 0, [$code])['error'] ?? null, 'second use');
    same(false, isset(held(1)['MR-2-2']), 'nothing booked on the failed attempt');
    $revoked = new_code();
    contains('Kód zrušen', revoke_discount_code((int) db_query('SELECT MAX(id) FROM discount_codes')->fetchColumn()), 'revoke');
    same(422, book(['MR-2-3'], 0, [$revoked])['status'] ?? null, 'revoked code');
    same(422, book(['MR-2-4'], 0, ['ZZZZZZZZ'])['status'] ?? null, 'unknown code');
});

test('a reservation paid fully by codes is confirmed at once', function (): void {
    $booked = book(['MR-3-1', 'MR-3-2'], 1, [new_code(), new_code()]);
    $r = $booked['reservation'] ?? [];
    same(['paid', 0, 'MR-3-1,MR-3-2'], [$r['status'], (int) $r['amount'], $r['free_seats']], 'paid without money');
    same(0, amount_due($r), 'nothing to pay');
});

test('codes are created per listed e-mail; an invalid address creates nothing', function (): void {
    $before = (int) db_query('SELECT COUNT(*) FROM discount_codes')->fetchColumn();
    contains('Neplatné e-maily: spatny', create_discount_codes("a@example.com\nspatny", 'test'), 'invalid');
    same($before, (int) db_query('SELECT COUNT(*) FROM discount_codes')->fetchColumn(), 'nothing created');
    contains('Vytvořeno 3 kódy', create_discount_codes("a@example.com, b@example.com;\na@example.com", 'test'), 'created');
    same(2, (int) db_query("SELECT COUNT(*) FROM discount_codes WHERE email = 'a@example.com'")->fetchColumn(), 'two codes for a listed twice');
    same('ABCDEFGH', normalize_discount_code(' abcd-efgh '), 'normalized input');
});

test('storno of a seat paid by a code returns nothing; a child seat returns its price minus the fee', function (): void {
    $id = reservation(['MR-4-1', 'MR-4-2', 'MR-4-3'], 'paid', 1, '', ['MR-4-3'], ['MR-4-1']);
    same(450, (int) row($id)['amount'], 'price');
    same(['MR-4-1' => 0, 'MR-4-2' => 300, 'MR-4-3' => 150], cancellation_terms(row($id))['seatPrices'], 'seat prices');
    cancel_seats($id, ['MR-4-1'], 'customer');
    same([450, 0, 0], [(int) row($id)['amount'], (int) row($id)['cancel_fee'], (int) row($id)['refund_amount']], 'free seat: nothing back');
    cancel_seats($id, ['MR-4-3'], 'customer'); // 50 % storno rule in run 1
    $r = row($id);
    same(['MR-4-2', 300, 75, 75], [$r['seats'], (int) $r['amount'], (int) $r['cancel_fee'], (int) $r['refund_amount']], 'child seat');
    cancel_seats($id, null, 'admin');
    same(['cancelled', 375], [row($id)['status'], (int) row($id)['refund_amount']], 'admin refunds the adult seat in full');
});

test('cancelling the paid seats of a pending reservation leaves the free seats confirmed', function (): void {
    $id = reservation(['MR-5-1', 'MR-5-2'], 'pending', 1, '', [], ['MR-5-1']);
    cancel_seats($id, ['MR-5-2'], 'customer');
    same(['paid', 0], [row($id)['status'], (int) row($id)['amount']], 'confirmed');
});

test('the payment deadline follows the rule in effect when the reservation is made', function (): void {
    $default = (int) config('PAYMENT_DEADLINE_HOURS');
    save_payment_deadline_rules([
        ['from' => iso_utc(now_utc()->modify('+5 days')), 'hours' => 12],
        ['from' => iso_utc(now_utc()->modify('-1 day')), 'hours' => 24],
    ]);
    try {
        same([$default, 24, 12], [
            payment_deadline_hours(now_utc()->modify('-2 days')),
            payment_deadline_hours(now_utc()),
            payment_deadline_hours(now_utc()->modify('+6 days')),
        ], 'hours by booking time');
        $r = book(['MR-8-1'])['reservation'] ?? [];
        $hours = (utc_time($r['expires_at'])->getTimestamp() - utc_time($r['created_at'])->getTimestamp()) / 3600;
        same(24, (int) round($hours), 'due date of a new reservation');
    } finally {
        save_payment_deadline_rules([]);
    }
});

// ----------------------------------------------------------- check-in

test('each seat ticket is checked in once; cancelled seats and other runs are refused', function (): void {
    $id = reservation(['MR-6-1', 'MR-6-2', 'MR-6-3'], 'paid', 2, 'Vstupní', ['MR-6-2']);
    cancel_seats($id, ['MR-6-3'], 'admin');
    $r = row($id);
    $run = run_by_id(2) ?? [];
    // Run 2 started an hour ago – checked in with the organizer's confirmation outside the window.
    same('outside_window', verify_ticket(ticket_code($r, 'MR-6-1'), $run, device('Vchod A'), false)['result'], 'outside window');
    $first = verify_ticket(ticket_code($r, 'MR-6-1'), $run, device('Vchod A'), true);
    same(['valid', 'MR-6-1', 'adult', 1, 2], [$first['result'], $first['ticket']['seat'], $first['ticket']['kind'], $first['ticket']['checkedIn'], $first['ticket']['count']], 'first seat');
    same('used', verify_ticket(ticket_code($r, 'MR-6-1'), $run, device('Vchod B'), true)['result'], 'same seat again');
    $child = verify_ticket(ticket_code($r, 'MR-6-2'), $run, device('Vchod B'), true);
    same(['valid', 'child', 2], [$child['result'], $child['ticket']['kind'], $child['ticket']['checkedIn']], 'second seat');
    same('seat_cancelled', verify_ticket(ticket_code($r, 'MR-6-3'), $run, device('Vchod A'), true)['result'], 'cancelled seat');
    same('wrong_run', verify_ticket(ticket_code($r, 'MR-6-2'), run_by_id(1) ?? [], device('Vchod A'), false)['result'], 'other run');
    same('invalid', verify_ticket('Z26|1|2|3|x|y|z', $run, device('Vchod A'), false)['result'], 'old format');
    same(['Vchod A', false], [row($id)['checked_in_by'], cancellation_terms(row($id))['allowed']], 'reservation checked in');
});

test('offline: seats of one reservation are checked in separately', function (): void {
    db()->exec('DELETE FROM scan_conflicts');
    $id = reservation(['MR-7-1', 'MR-7-2'], 'paid');
    $vs = row($id)['variable_symbol'];
    $at = iso_utc(now_utc()->modify('-3 minutes'));
    $res = apply_offline_scans(device('Vchod A'), run_by_id(1) ?? [], [
        ['type' => 'ticket', 'id' => $id, 'variableSymbol' => $vs, 'seat' => 'MR-7-1', 'at' => $at],
        ['type' => 'ticket', 'id' => $id, 'variableSymbol' => $vs, 'seat' => 'MR-7-2', 'at' => $at],
        ['type' => 'ticket', 'id' => $id, 'variableSymbol' => $vs, 'seat' => 'MR-7-3', 'at' => $at],
    ]);
    same(['ok', 'ok', 'conflict'], array_column($res['results'], 'status'), 'two seats in, unknown seat refused');
    same(['MR-7-1', 'MR-7-2'], array_keys(reservation_checkins($id)), 'both seats checked in');
    contains('řada 7 · místo 3', conflicts()[0]['label'] ?? '', 'conflict names the seat');
});

test('on-site sale: QR payment, then paid and every seat checked in; the accountant verifies it', function (): void {
    $run = run_by_id(2) ?? [];
    same(422, onsite_sale_create(device('Vchod A'), $run, ['XX-1-1'], 0)['status'] ?? null, 'invalid seat');
    $sold = onsite_sale_create(device('Vchod A'), $run, ['WR-5-2', 'WR-5-1'], 1);
    $sale = $sold['sale'] ?? [];
    same([450, ['WR-5-1', 'WR-5-2']], [$sale['amount'], $sale['seats']], 'sale');
    contains('*AM:450.00*', $sale['spd'], 'QR payment');
    same(409, onsite_sale_create(device('Vchod B'), $run, ['WR-5-1'], 0)['status'] ?? null, 'seat held by the open sale');
    contains('2 místa odbavena', (string) onsite_sale_paid(device('Vchod A'), $run, (int) $sale['id']), 'paid');
    $r = row((int) $sale['id']);
    same(['paid', 450, 'Vchod A', null], [$r['status'], (int) $r['paid_amount'], $r['sold_by'], $r['payment_checked_at']], 'reservation');
    same(['WR-5-1', 'WR-5-2'], array_keys(reservation_checkins((int) $r['id'])), 'checked in');
    same(null, onsite_sale_paid(device('Vchod A'), $run, (int) $sale['id']), 'only once');

    $other = onsite_sale_create(device('Vchod A'), $run, ['WR-5-3'], 0)['sale'] ?? [];
    same(true, onsite_sale_cancel($run, (int) $other['id']), 'cancelled');
    same([false, 'cancelled'], [isset(held(2)['WR-5-3']), row((int) $other['id'])['status']], 'seat free again');
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

test('one ticket code per seat round-trips with its kind and rejects tampering', function (): void {
    $r = row(reservation(['BC-1-1', 'BC-1-2'], 'paid', 1, '', ['BC-1-2']));
    $code = ticket_code($r, 'BC-1-2');
    same(['id' => (int) $r['id'], 'variableSymbol' => $r['variable_symbol'], 'seat' => 'BC-1-2', 'kind' => 'child', 'name' => 'Test ' . $r['last_name']],
        parse_ticket_code($code), 'parsed');
    same('adult', parse_ticket_code(ticket_code($r, 'BC-1-1'))['kind'] ?? null, 'adult seat');
    same(null, parse_ticket_code(str_replace('BC-1-2', 'BC-1-3', $code)), 'tampered');
    $payload = reservation_payload($r);
    same(['BC-1-1', 'BC-1-2'], array_column($payload['tickets'], 'seat'), 'one ticket per seat');
    same(true, count(array_unique(array_column($payload['tickets'], 'code'))) === 2, 'codes differ');
});

test('the printable PDF holds the tickets of the current seats', function (): void {
    $r = row(reservation(['BC-1-3', 'BC-1-4', 'BC-1-5', 'BC-1-6', 'BC-1-7'], 'paid', 1, 'Tisková', ['BC-1-7']));
    $pdf = ticket_pdf($r);
    same('%PDF-', substr($pdf, 0, 5), 'PDF');
    same(2, preg_match_all('#/Type\s*/Page[^s]#', $pdf), 'five tickets on two pages (four per page)');
});

test('the ticket calendar event covers the run with folded, escaped lines', function (): void {
    $r = row(reservation(['BC-2-1', 'BC-2-2'], 'paid', 1, 'Nováková, Jana'));
    $now = now_utc();
    $ics = (string) ticket_ics($r, $now);
    $start = run_starts(runs()[1]);
    $unfolded = str_replace("\r\n ", '', $ics);
    contains('METHOD:REQUEST', $ics, 'method');
    contains('DTSTART:' . $start->format('Ymd\THis\Z'), $ics, 'start');
    contains('DTEND:' . $start->modify('+' . (int) config('EVENT_DURATION_MINUTES') . ' minutes')->format('Ymd\THis\Z'), $ics, 'end');
    contains('UID:zidle-' . $r['id'] . '@', $ics, 'uid');
    contains('SEQUENCE:' . $now->getTimestamp(), $ics, 'sequence');
    contains('ATTENDEE;CN="Test Nováková, Jana";', $unfolded, 'attendee');
    contains('SUMMARY:TZG 2026 · Budoucí – ' . str_replace(',', '\,', implode('; ', seat_labels($r['seats']))), $unfolded, 'summary');
    contains('Variabilní symbol: ' . $r['variable_symbol'], $unfolded, 'description');
    same(true, str_ends_with($ics, "END:VCALENDAR\r\n"), 'CRLF');
    foreach (explode("\r\n", $ics) as $line) {
        same(true, strlen($line) <= 75 && mb_check_encoding($line, 'UTF-8'), "line length: {$line}");
    }
    same(null, ticket_ics(['run_id' => 999] + $r), 'missing run');
});

// ------------------------------------------------------------------- layout

test('the layout matches the plan: seats per row, capacities, reserve balcony seats', function (): void {
    $layout = layout_public();
    same(array_keys(SECTIONS), array_column($layout['sections'], 'id'), 'order');
    $sizes = array_combine(array_column($layout['sections'], 'id'), array_map(fn ($s) => array_column($s['rows'], 'seats'), $layout['sections']));
    same([5, 5, 5, 5], $sizes['WL'], 'left wing');
    same(array_fill(0, 9, 8), $sizes['ML'], 'left main');
    same([4, 4, 4, 5, 5, 5, 4], $sizes['WR'], 'right wing');
    same([7, 14, 14, 7, 7], $sizes['BC'], 'balcony centre');
    same([12, 5, 5, 6, 6, 6], $sizes['BL'], 'balcony left: bench + reserve rows');
    same([5, 7], array_column(section_rows(SECTIONS['BL'])[0]['parts'], 0), 'bench split 1–5 | 6–12');
    same([6, 5, 3, 3], $sizes['BR'], 'balcony right');
    same([195, 106, 301], [
        array_sum(array_map('section_capacity', array_filter(SECTIONS, fn ($s) => $s['level'] === 'main'))),
        array_sum(array_map('section_capacity', array_filter(SECTIONS, fn ($s) => $s['level'] === 'balcony'))),
        total_capacity(),
    ], 'capacities');
    same([['BL', 'BR'], 28, 6], [reserve_section_ids(), section_reserve_capacity(SECTIONS['BL']), section_reserve_capacity(SECTIONS['BR'])], 'reserve seats');
    same([true, false, true, false], [seat_is_reserve('BL-2-1'), seat_is_reserve('BL-1-12'), seat_is_reserve('BR-4-3'), seat_is_reserve('BR-2-5')], 'reserve rows');
    same(array_keys(LEVELS), array_keys($layout['plan']), 'a plan per level');
    foreach ($layout['sections'] as $section) {
        $rows = count($section['rows']);
        $last = $section['rows'][$rows - 1]['seats'];
        same([true, false, false], [
            is_valid_seat_id("{$section['id']}-{$rows}-{$last}"),
            is_valid_seat_id("{$section['id']}-{$rows}-" . ($last + 1)),
            is_valid_seat_id("{$section['id']}-" . ($rows + 1) . '-1'),
        ], "last seat of {$section['id']}");
    }
});

test('reserve balcony seats are not for sale until the admin opens them for the run', function (): void {
    $run = run_by_id(1) ?? [];
    same([['BL', 'BR'], 267], [run_closed_reserves($run), run_capacity($run)], 'closed by default');
    same(409, book(['BL-2-1'])['status'] ?? null, 'reserve seat cannot be booked');
    same(true, isset(book(['BL-1-6'])['reservation']), 'the bench of the same section can');
    db()->exec("UPDATE runs SET open_reserves = '[\"BL\"]' WHERE id = 1");
    runs(true);
    try {
        $run = run_by_id(1) ?? [];
        same([['BR'], 295], [run_closed_reserves($run), run_capacity($run)], 'BL reserve opened');
        same(true, isset(book(['BL-2-1'])['reservation']), 'booked');
    } finally {
        db()->exec("UPDATE runs SET open_reserves = '[]' WHERE id = 1");
        runs(true);
    }
});

// ----------------------------------------------------------- admin accounts

test('an invited accountant sets a password and signs in with the e-mail', function (): void {
    $invite = admin_invite(' Jana@Example.cz ', 'Jana  Nováková', 'hlavní heslo');
    same(true, is_array($invite), 'invited');
    $token = is_array($invite) ? $invite['token'] : '';
    same(['jana@example.cz', 'Jana Nováková', null], [$invite['user']['email'] ?? null, $invite['user']['name'] ?? null, $invite['user']['password_hash'] ?? null], 'account');
    same(null, admin_login('jana@example.cz', 'cokoliv-dlouheho'), 'no login before accepting');
    same('Heslo musí mít alespoň 10 znaků.', admin_accept_invite($token, 'kratke', 'kratke'), 'too short');
    same('Hesla se neshodují.', admin_accept_invite($token, 'dlouhe-heslo-1', 'dlouhe-heslo-2'), 'mismatch');
    $me = admin_accept_invite($token, 'dlouhe-heslo-1', 'dlouhe-heslo-1');
    same('Jana Nováková', is_array($me) ? $me['name'] : $me, 'accepted');
    same(null, admin_invited_user($token), 'link used up');
    same('Jana Nováková', admin_login('JANA@example.cz', 'dlouhe-heslo-1')['name'] ?? null, 'login (e-mail case-insensitive)');
    same(null, admin_login('jana@example.cz', 'spatne-heslo'), 'wrong password');
    same('Účet s tímto e-mailem už existuje. Pošlete mu nový odkaz v seznamu.', admin_invite('jana@example.cz', 'Jana', 'x'), 'duplicate');
    same('Neplatný e-mail.', admin_invite('neni-email', 'Jana', 'x'), 'invalid e-mail');
});

test('a new link lets the owner set a new password; expired links do not work', function (): void {
    $invite = admin_invite('petr@example.cz', 'Petr', 'x');
    $id = is_array($invite) ? (int) $invite['user']['id'] : 0;
    admin_accept_invite(is_array($invite) ? $invite['token'] : '', 'prvni-heslo-1', 'prvni-heslo-1');
    $token = admin_new_invite($id);
    same('Petr', admin_login('petr@example.cz', 'prvni-heslo-1')['name'] ?? null, 'old password works until changed');
    admin_accept_invite($token, 'druhe-heslo-2', 'druhe-heslo-2');
    same([null, 'Petr'], [admin_login('petr@example.cz', 'prvni-heslo-1'), admin_login('petr@example.cz', 'druhe-heslo-2')['name'] ?? null], 'new password');
    $expired = admin_new_invite($id);
    db()->prepare('UPDATE admin_users SET invite_expires_at = ? WHERE id = ?')->execute([db_time(now_utc()->modify('-1 minute')), $id]);
    same('Pozvánka neplatí nebo vypršela. Požádejte o novou.', admin_accept_invite($expired, 'treti-heslo-3', 'treti-heslo-3'), 'expired');
});

test('disabled accounts cannot sign in and lose their session; nobody disables themselves', function (): void {
    $invite = admin_invite('eva@example.cz', 'Eva', 'x');
    $id = is_array($invite) ? (int) $invite['user']['id'] : 0;
    admin_accept_invite(is_array($invite) ? $invite['token'] : '', 'evino-heslo-1', 'evino-heslo-1');
    same('Eva', admin_from_session($id)['name'] ?? null, 'session valid');
    same('Svůj vlastní účet nelze vypnout.', admin_set_disabled($id, true, $id), 'not yourself');
    admin_set_disabled($id, true, null);
    same([null, null], [admin_login('eva@example.cz', 'evino-heslo-1'), admin_from_session($id)], 'disabled');
    admin_set_disabled($id, false, null);
    same('Eva', admin_login('eva@example.cz', 'evino-heslo-1')['name'] ?? null, 'enabled again');
});

test('the master password signs in with an empty e-mail', function (): void {
    $master = (string) config('ADMIN_PASSWORD');
    if ($master === '') {
        same(null, admin_login('', 'cokoliv'), 'no master password configured');
        return;
    }
    same(['id' => null, 'name' => ADMIN_MASTER_NAME, 'email' => null], admin_login('', $master), 'master');
    same(null, admin_login('', $master . 'x'), 'wrong master password');
    same(ADMIN_MASTER_NAME, admin_from_session(0)['name'] ?? null, 'master session');
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

test('snapshot lists the run\'s tickets without e-mails', function (): void {
    $paid = reservation(['BR-1-1', 'BR-1-2'], 'paid', 1, 'Seznamová');
    $snapshot = scanner_snapshot(run_by_id(1) ?? []);
    $ticket = array_values(array_filter($snapshot['tickets'], fn ($t) => $t['id'] === $paid))[0] ?? null;
    same(['Test Seznamová', ['BR-1-1', 'BR-1-2'], 'paid', []], [$ticket['name'] ?? null, $ticket['seats'] ?? null, $ticket['status'] ?? null, (array) ($ticket['checkedIn'] ?? null)], 'ticket');
    same(false, array_key_exists('email', $ticket ?? []), 'no e-mail');
});

test('offline check-ins are applied with the device time; the first one wins', function (): void {
    db()->exec('DELETE FROM scan_conflicts');
    $id = reservation(['BR-1-3'], 'paid');
    $vs = row($id)['variable_symbol'];
    $at = now_utc()->modify('-10 minutes');
    $event = ['type' => 'ticket', 'id' => $id, 'variableSymbol' => $vs, 'seat' => 'BR-1-3', 'at' => iso_utc($at)];
    $res = apply_offline_scans(device('Vchod A'), run_by_id(1) ?? [], [$event]);
    same([['status' => 'ok']], $res['results'], 'applied');
    same([db_time($at), 'Vchod A (offline)'], [row($id)['checked_in_at'], row($id)['checked_in_by']], 'stored');
    // The same event sent again (response lost) is not a conflict.
    same(0, apply_offline_scans(device('Vchod A'), run_by_id(1) ?? [], [$event])['conflicts'], 'resend');
    // Another device let the same ticket in offline too.
    $other = ['type' => 'ticket', 'id' => $id, 'variableSymbol' => $vs, 'seat' => 'BR-1-3', 'at' => iso_utc($at->modify('+2 minutes'))];
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
        ['type' => 'ticket', 'id' => $pending, 'variableSymbol' => row($pending)['variable_symbol'], 'seat' => 'BR-1-4', 'at' => iso_utc(now_utc())],
        ['type' => 'ticket', 'id' => $otherRun, 'variableSymbol' => row($otherRun)['variable_symbol'], 'seat' => 'BR-1-5', 'at' => iso_utc(now_utc())],
        ['type' => 'ticket', 'id' => 999999, 'variableSymbol' => '1', 'seat' => 'BR-1-6', 'at' => iso_utc(now_utc())],
    ];
    $res = apply_offline_scans(device('Vchod A'), run_by_id(1) ?? [], $events);
    same(['not_paid', 'unknown', 'unknown'], array_column($res['results'], 'reason'), 'reasons');
    same(null, row($otherRun)['checked_in_at'], 'other run untouched');
});

test('device time is limited to the last 48 hours and never in the future', function (): void {
    $future = reservation(['BR-2-2'], 'paid');
    $old = reservation(['BR-2-3'], 'paid');
    apply_offline_scans(device('Vchod A'), run_by_id(1) ?? [], [
        ['type' => 'ticket', 'id' => $future, 'variableSymbol' => row($future)['variable_symbol'], 'seat' => 'BR-2-2', 'at' => iso_utc(now_utc()->modify('+3 hours'))],
        ['type' => 'ticket', 'id' => $old, 'variableSymbol' => row($old)['variable_symbol'], 'seat' => 'BR-2-3', 'at' => '2001-01-01T00:00:00Z'],
    ]);
    same(true, row($future)['checked_in_at'] <= db_time(now_utc()), 'not in the future');
    same(true, row($old)['checked_in_at'] >= db_time(now_utc()->modify('-49 hours')), 'not older than 48 h');
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
