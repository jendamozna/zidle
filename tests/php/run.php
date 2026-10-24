<?php
// Integration tests of the money, seat, discount code and ticket logic against a real MariaDB.
//
//   DB_NAME=zidle_test MAIL_ENABLED=0 TICKET_SECRET=test-secret-0123456789-abcdefghijklmn BANK_ACCOUNT=19-2000145399/0800 php tests/php/run.php
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
    $id = reservation(['LK-1-1', 'LK-1-2']);
    contains('zbývá doplatit 300 Kč', record_payment($id, 300), 'message');
    same(['pending', 300, null, 300], money($id), 'after 300');
    contains('Přeplatek 100 Kč', record_payment($id, 400), 'message');
    same(['paid', 700, 100, 0], money($id), 'after 700');
});

test('payment code (SPD) asks only for the missing amount, with VS 3211 and the reservation\'s SS', function (): void {
    $id = reservation(['LK-1-3', 'LK-1-4']);
    record_payment($id, 200);
    contains('*AM:400.00*', spd_string(row($id)), 'SPD');
    $ss = row($id)['specific_symbol'];
    contains('*X-VS:3211*X-SS:' . $ss . '*', spd_string(row($id)), 'fixed VS, the reservation\'s own SS');
    contains("Variabilní symbol: 3211\nSpecifický symbol: {$ss}", implode("\n", payment_lines(row($id))), 'e-mail payment details');
    same(['3211', $ss], [reservation_payload(row($id))['payment']['variableSymbol'], reservation_payload(row($id))['payment']['specificSymbol']], 'payload symbols');
    same(400, reservation_payload(row($id))['payment']['amount'], 'payload amount');
    same(200, reservation_payload(row($id))['payment']['received'], 'payload received');
});

test('the IBAN in the payment code is derived from the Czech account number', function (): void {
    same('CZ6508000000192000145399', czech_iban('19-2000145399/0800'), 'with prefix');
    same('CZ6508000000192000145399', czech_iban(' 19 - 2000145399 / 0800 '), 'spaces ignored');
    same('CZ7801000000000000000123', czech_iban('123/0100'), 'without prefix');
    same(['', '', ''], [czech_iban('19-2000145398/0800'), czech_iban('2000145399'), czech_iban('')], 'typo, no bank code, empty');
    contains('*ACC:CZ6508000000192000145399*', spd_string(row(reservation(['PK-9-1']))), 'SPD account');
});

test('the SS is a random 5-digit number, unique and reassigned without clashing', function (): void {
    $id = reservation(['LK-2-9']);
    same(1, preg_match('/^[1-9]\d{4}$/', row($id)['specific_symbol']), '5 digits, no leading zero');
    for ($i = 0; $i < 30; $i++) {
        assign_specific_symbol(db(), $id);
    }
    same(1, preg_match('/^[1-9]\d{4}$/', row($id)['specific_symbol']), 'still 5 digits');
    same(0, (int) db_query('SELECT COUNT(*) FROM (SELECT specific_symbol FROM reservations GROUP BY specific_symbol HAVING COUNT(*) > 1) d')->fetchColumn(), 'no SS twice');
});

test('late payment restores an expired reservation when the seats are free', function (): void {
    $id = reservation(['LK-1-5']);
    expire($id);
    same('expired', row($id)['status'], 'expired');
    same([], array_keys(array_filter(held(1), fn ($h) => $h === "r{$id}")), 'seats freed');
    contains('rezervace obnovena', record_payment($id, 300), 'message');
    same(['paid', 300, null, 0], money($id), 'restored');
    same("r{$id}", held(1)['LK-1-5'] ?? null, 'seat held again');
});

test('late payment is refunded when someone else took the seats', function (): void {
    $id = reservation(['LK-1-6']);
    expire($id);
    reservation(['LK-1-6']);
    contains('místa mezitím obsadil někdo jiný', record_payment($id, 300), 'message');
    same(['expired', 300, 300, 300], money($id), 'refund');
});

test('late payment after the run is refunded', function (): void {
    $id = reservation(['LK-2-1'], 'pending', 2);
    expire($id);
    contains('platba dorazila až po představení', record_payment($id, 300), 'message');
    same(['expired', 300, 300, 300], money($id), 'refund');
});

test('late payment that does not cover the price is refunded', function (): void {
    $id = reservation(['LK-2-2']);
    expire($id);
    contains('nepokrývá celou částku', record_payment($id, 100), 'message');
    same(['expired', 100, 100, 300], money($id), 'refund');
});

test('expiry refunds a partial payment; paying the rest later restores the reservation', function (): void {
    $id = reservation(['LK-2-3', 'LK-2-4']);
    record_payment($id, 200);
    expire($id);
    same(['expired', 200, 200, 600], money($id), 'expired with refund');
    record_payment($id, 400);
    same(['paid', 600, null, 0], money($id), 'restored, refund cancelled');
});

test('payment for a cancelled reservation is refunded', function (): void {
    $id = reservation(['LK-2-5']);
    cancel_seats($id, null);
    contains('rezervace už byla zrušená', record_payment($id, 300), 'message');
    same(['cancelled', 300, 300, 300], money($id), 'refund');
});

test('a second payment for a paid reservation is refunded', function (): void {
    $id = reservation(['LK-2-6'], 'paid');
    contains('platbu navíc', record_payment($id, 300), 'message');
    same(['paid', 600, 300, 0], money($id), 'refund');
});

test('the same payment submitted twice (double click) is recorded once', function (): void {
    $id = reservation(['PK-7-3']);
    contains('zaplaceno', record_payment($id, 300, 0), 'first submit');
    same('Platba u této rezervace už byla mezitím zapsána – nic dalšího se nezapsalo. Zkontrolujte stav rezervace.',
        record_payment($id, 300, 0), 'second submit');
    same(['paid', 300, null, 0], money($id), 'no refund for money that never arrived');
});

test('invalid amounts are rejected', function (): void {
    $id = reservation(['LK-3-1']);
    same('Zadejte přijatou částku v Kč.', record_payment($id, 0), 'zero');
    same(['pending', 0, null, 300], money($id), 'unchanged');
});

test('bank statement CSV (KB, Windows-1250): matched by SS, VS or message, recorded once', function (): void {
    $full = reservation(['PH-9-1', 'PH-9-2']);
    $part = reservation(['PH-9-3']);
    $byMessage = reservation(['PH-9-4']);
    $paid = reservation(['PH-9-5'], 'paid');
    $ss = static fn (int $id) => row($id)['specific_symbol'];
    $line = static fn (string $name, string $amount, string $vs, string $ss, string $id, string $message = '') => '"' . implode('";"', [
        '19.10.2026', '', '19-123457/0100', $name, $amount, '', '', '', $vs, '0000', $ss, $id, 'Příchozí úhrada', '', '', $message, '', '', '',
    ]) . "\"\r\n";
    $csv = "\"Číslo účtu\";\"123/0100\"\r\n\"Období\";\"01.10.2026 - 31.10.2026\"\r\n\r\n"
        . '"Datum splatnosti";"Datum odepsání z jiné banky";"Protiúčet a kód banky";"Název protiúčtu";"Částka";"Originální částka";'
        . '"Originální měna";"Kurz";"VS";"KS";"SS";"Identifikace transakce";"Systémový popis";"Popis příkazce";"Popis pro příjemce";'
        . "\"AV pole 1\";\"AV pole 2\";\"AV pole 3\";\"AV pole 4\"\r\n"
        . $line('Jan Novák', '+600,00', '3211', $ss($full), 'T1')
        . $line('Eva Částečná', '+100,00', '0000003211', '00' . $ss($part), 'T2')
        . $line('Petr Zpráva', '+300,00', '321', '0', 'T3', 'za vstupenky ' . $ss($byMessage))
        . $line('Dvakrát', '+300,00', '3211', $ss($paid), 'T4')
        . $line('Neznámý', '+400,00', '321', '0', 'T5', 'Marie')
        . $line('Haléře', '+300,50', '3211', $ss($byMessage), 'T6')
        . $line('Odchozí', '-250,00', '', '', 'T7');
    $parsed = parse_bank_csv((string) iconv('UTF-8', 'WINDOWS-1250', $csv));
    if (is_string($parsed)) {
        throw new TestFailure($parsed);
    }
    $rows = $parsed['rows'];
    same([6, 1], [count($rows), $parsed['outgoing']], 'incoming rows, skipped outgoing');
    same(['T1', '2026-10-19', 'Jan Novák', 60000, '3211', $ss($full)], [$rows[0]['id'], $rows[0]['date'], $rows[0]['name'], $rows[0]['cents'], $rows[0]['vs'], $rows[0]['ss']], 'first row');
    same(['3211', $ss($part)], [$rows[1]['vs'], $rows[1]['ss']], 'leading zeros dropped');

    $matches = array_map('bank_match', $rows);
    same(['match', 'match', 'match', 'match', 'none', 'none'], array_column($matches, 'state'), 'states');
    same([true, true, true, false, false, false], array_column($matches, 'select'), 'pre-checked');
    same([$full, $part, $byMessage, $paid], array_map(static fn ($m) => (int) $m['reservation']['id'], array_slice($matches, 0, 4)), 'reservations');
    contains('Celá částka → Zaplaceno', $matches[0]['note'], 'full');
    contains('zbude doplatit 200 Kč', $matches[1]['note'], 'partial');
    contains('Nalezeno podle zprávy pro příjemce. VS 321 (má být 3211).', $matches[2]['note'], 'found in the message, wrong VS');
    contains('už je zaplacená', $matches[3]['note'], 'paid already');
    contains('Platba nemá SS', $matches[4]['note'], 'unmatched');
    contains('haléři', $matches[5]['note'], 'cents');

    foreach ([0, 1, 2] as $i) {
        same(true, bank_record($rows[$i], 'ucetni@example.cz')['ok'], "record row {$i}");
    }
    same(['paid', 600, null, 0], money($full), 'full payment');
    same(['pending', 100, null, 200], money($part), 'partial payment');
    same('paid', row($byMessage)['status'], 'matched by the message');
    same(['ok' => false, 'message' => 'Už zapsáno ' . utc_time((string) db_query("SELECT imported_at FROM bank_payments WHERE transaction_id = 'T1'")->fetchColumn())
        ->setTimezone(new DateTimeZone(PRAGUE))->format('j. n. Y H:i') . ' (ucetni@example.cz).'], bank_record($rows[0], 'jiny@example.cz'), 'second import');
    same('done', bank_match($rows[0])['state'], 'shown as recorded');
    same(['paid', 600, null, 0], money($full), 'not recorded twice');
    same(false, bank_record($rows[4], 'ucetni@example.cz')['ok'], 'unmatched not recorded');
    same(false, bank_match(['id' => 'T2b'] + $rows[1])['select'], 'another payment of a partly paid reservation is not pre-checked');

    same('Soubor je prázdný.', parse_bank_csv(''), 'empty file');
    $noHeader = parse_bank_csv("a;b\n1;2");
    contains('záhlavím', is_string($noHeader) ? $noHeader : '', 'no header');
});

// ------------------------------------------------------------ cancellation

test('admin cancellation of single seats refunds them in full and frees them', function (): void {
    $id = reservation(['LK-3-2', 'LK-3-3'], 'paid');
    cancel_seats($id, ['LK-3-3']);
    $r = row($id);
    same(['paid', ['LK-3-2'], ['LK-3-3'], 300, 300], [$r['status'], current_seat_ids($r), cancelled_seat_ids($r), (int) $r['amount'], (int) $r['refund_amount']], 'after one seat');
    same(false, isset(held(1)['LK-3-3']), 'seat freed');
    same('Vyberte místa z této rezervace.', (function () use ($id) {
        try {
            cancel_seats($id, ['LH-1-1']);
            return '';
        } catch (InvalidArgumentException $e) {
            return $e->getMessage();
        }
    })(), 'foreign seat');
});

test('admin cancellation refunds a paid reservation in full', function (): void {
    $id = reservation(['LK-3-4'], 'paid');
    cancel_seats($id, null);
    $r = row($id);
    same(['cancelled', 300], [$r['status'], (int) $r['refund_amount']], 'refund');
});

test('cancelling a partly paid pending reservation refunds what arrived', function (): void {
    $id = reservation(['LK-3-5', 'LK-3-6']);
    record_payment($id, 200);
    cancel_seats($id, null);
    same(['cancelled', 200, 200, 600], money($id), 'refund');
});

test('cancelling seats of a pending reservation completes it when the payment now covers it', function (): void {
    $id = reservation(['LK-4-1', 'LK-4-2', 'LK-4-3']);
    record_payment($id, 600);
    cancel_seats($id, ['LK-4-3']);
    same(['paid', 600, null, 0], money($id), 'completed');
});

test('a seat cannot be held twice in the same run', function (): void {
    reservation(['LK-4-4']);
    try {
        reservation(['LK-4-4']);
    } catch (PDOException $e) {
        same('23000', $e->getCode(), 'duplicate key');
        return;
    }
    throw new TestFailure('second reservation of the same seat was accepted');
});

test('the schema refuses inconsistent rows', function (): void {
    $id = reservation(['LK-4-5']);
    $refused = static function (string $sql, array $params, string $label): void {
        try {
            db()->prepare($sql)->execute($params);
        } catch (PDOException $e) {
            same('23000', $e->getCode(), $label);
            return;
        }
        throw new TestFailure($label . ': accepted');
    };
    $refused('INSERT INTO reservation_seats (run_id, seat_id, reservation_id) VALUES (2, ?, ?)', ['LK-4-6', $id], 'seat held in another run than the reservation');
    $refused('INSERT INTO reservations (token, run_id, first_name, last_name, email, amount, status, created_at, expires_at) VALUES (?, 99, ?, ?, ?, 0, ?, UTC_TIMESTAMP(), UTC_TIMESTAMP())', [bin2hex(random_bytes(16)), 'A', 'B', 'a@example.com', 'pending'], 'unknown run');
    $refused("UPDATE reservations SET status = 'paid' WHERE id = ?", [$id], 'paid without paid_at');
    $refused("UPDATE reservations SET status = 'cancelled' WHERE id = ?", [$id], 'cancelled without cancelled_at');
    $refused('UPDATE reservations SET refunded_amount = 100 WHERE id = ?', [$id], 'refunded more than due');
    $refused('UPDATE reservation_items SET free = 1 WHERE reservation_id = ?', [$id], 'free seat with a price');
    $code = new_code();
    db()->prepare('UPDATE discount_codes SET used_at = UTC_TIMESTAMP(), reservation_id = ?, seat_id = ? WHERE code = ?')->execute([$id, 'LK-4-5', $code]);
    $refused('UPDATE discount_codes SET used_at = UTC_TIMESTAMP(), reservation_id = ?, seat_id = ? WHERE code = ?', [$id, 'LK-4-5', new_code()], 'two codes for one seat');
    $refused('DELETE FROM reservations WHERE id = ?', [$id], 'reservation with a used code');
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
    $seats = ['LH-1-3', 'LH-1-1', 'LH-1-2'];
    $children = assign_child_seats($seats, 1);
    same(['LH-1-3'], $children, 'last seat is the child seat');
    same(['LH-1-1', 'LH-1-2'], assign_free_seats($seats, $children, 2, 300, 150), 'adult seats first');
    same(['LH-1-1', 'LH-1-2', 'LH-1-3'], assign_free_seats($seats, $children, 3, 300, 150), 'then the child seat');
    same(['LH-1-1'], assign_free_seats($seats, assign_child_seats($seats, 2), 1, 300, 150), 'the one adult seat');
    same([], assign_free_seats($seats, $children, 0, 300, 150), 'no code');
});

test('a reservation with a child seat and a code costs adult + child − the adult seat', function (): void {
    $code = new_code();
    $booked = book(['PH-1-1', 'PH-1-2', 'PH-1-3'], 1, [$code]);
    $r = $booked['reservation'] ?? [];
    same(['pending', 450], [$r['status'], (int) $r['amount']], 'reservation');
    $used = db()->prepare('SELECT used_at IS NOT NULL, reservation_id, seat_id FROM discount_codes WHERE code = ?');
    $used->execute([$code]);
    same([1, (int) $r['id'], 'PH-1-1'], array_map(fn ($v) => is_numeric($v) ? (int) $v : $v, $used->fetch(PDO::FETCH_NUM) ?: []), 'code used for the seat');
    same([['PH-1-1', 'adult', 0, true], ['PH-1-2', 'adult', 300, false], ['PH-1-3', 'child', 150, false]],
        array_map(fn ($t) => [$t['seat'], $t['kind'], $t['price'], $t['free']], reservation_payload($r)['tickets']), 'tickets in the payload');
    same('2 dospělí, 1 dítě 4–17 let', seat_kinds_summary($r), 'summary');
});

test('a discount code works only once and not after it was revoked', function (): void {
    $code = new_code();
    same(true, discount_code_usable($code), 'form check before use');
    same(true, isset(book(['PH-2-1'], 0, [$code])['reservation']), 'first use');
    same(false, discount_code_usable($code), 'form check after use');
    same('Slevový kód ' . format_discount_code($code) . ' neplatí nebo už byl použit.', book(['PH-2-2'], 0, [$code])['error'] ?? null, 'second use');
    same(false, isset(held(1)['PH-2-2']), 'nothing booked on the failed attempt');
    $revoked = new_code();
    contains('Kód zrušen', revoke_discount_code((int) db_query('SELECT MAX(id) FROM discount_codes')->fetchColumn()), 'revoke');
    same(false, discount_code_usable($revoked), 'form check of a revoked code');
    same(422, book(['PH-2-3'], 0, [$revoked])['status'] ?? null, 'revoked code');
    same(false, discount_code_usable('XXXXXXXX'), 'form check of an unknown code');
    same(422, book(['PH-2-4'], 0, ['XXXXXXXX'])['status'] ?? null, 'unknown code');
});

test('a reservation paid fully by codes is confirmed at once', function (): void {
    $booked = book(['PH-3-1', 'PH-3-2'], 1, [new_code(), new_code()]);
    $r = $booked['reservation'] ?? [];
    same(['paid', 0, [true, true]], [$r['status'], (int) $r['amount'], array_column(reservation_seat_items($r), 'free')], 'paid without money');
    same(0, amount_due($r), 'nothing to pay');
});

test('a test run: hidden label TEST, bookings paid at once, codes refused, deleted with its reservations', function (): void {
    $lastBefore = last_run_start()?->format('c');
    db()->prepare('INSERT INTO runs (label, starts_at, is_test) VALUES (?, ?, 1)')->execute(['Zkouška', db_time(now_utc()->modify('+60 days'))]);
    $runId = (int) db()->lastInsertId();
    runs(true);
    $run = run_by_id($runId) ?? [];
    contains(' · Zkouška · TEST', run_label($run), 'label');
    same([true, $lastBefore], [run_public($run)['test'], last_run_start()?->format('c')], 'flagged; not the last run for data deletion');
    $code = new_code();
    $refused = book_reservation($run, 'Test', 'Odbavení', 'test@example.com', ['LK-1-1'], 0, [$code]);
    same([422, 'Na testovacím termínu nelze použít slevové kódy.'], [$refused['status'] ?? null, $refused['error'] ?? null], 'code refused');
    same(0, (int) db_query("SELECT COUNT(*) FROM discount_codes WHERE used_at IS NOT NULL AND code = '{$code}'")->fetchColumn(), 'code not used up');
    $r = book_reservation($run, 'Test', 'Odbavení', 'test@example.com', ['LK-1-1', 'LK-1-2'], 1, [])['reservation'] ?? [];
    same(['paid', 450, 450, 0], [$r['status'], (int) $r['amount'], (int) $r['paid_amount'], amount_due($r)], 'paid at once');
    same(['[TEST] Vstupenky - Těšín zpívá gospel', 'Rezervace míst - Těšín zpívá gospel'], [mail_subject('Vstupenky', $r), mail_subject('Rezervace míst', row(reservation(['PK-8-1'])))], 'subjects');
    same(1, preg_match('/^(neděle|pondělí|úterý|středa|čtvrtek|pátek|sobota) \d/u', run_label($run, true)), 'full weekday (PDF)');
    contains('Termín: ' . run_label($run, true), run_line($r), 'full weekday (e-mail)');
    $sale = onsite_sale_create(device('Vchod A'), $run, ['LK-1-3'], 0)['sale'] ?? [];
    same(['pending', 300], [row((int) ($sale['id'] ?? 0))['status'], $sale['amount'] ?? null], 'on-site sale keeps the normal flow');
    same('valid', verify_ticket(ticket_code($r, 'LK-1-1'), $run, device('Vchod A'), true)['result'], 'checked in');
    same('Termín má rezervace, nelze ho smazat.', delete_run(1), 'a real run with reservations stays');
    same('Termín smazán.', delete_run($runId), 'test run deleted');
    same([null, [], 0, 0], [run_by_id($runId), held($runId), (int) db_query("SELECT COUNT(*) FROM reservations WHERE run_id = {$runId}")->fetchColumn(),
        (int) db_query('SELECT COUNT(*) FROM reservation_items WHERE reservation_id = ' . (int) $r['id'])->fetchColumn()], 'with its reservations, seats and check-ins');
});

test('codes are created per listed e-mail; an invalid address creates nothing', function (): void {
    $before = (int) db_query('SELECT COUNT(*) FROM discount_codes')->fetchColumn();
    contains('Neplatné e-maily: spatny', create_discount_codes("a@example.com\nspatny", 'test'), 'invalid');
    same($before, (int) db_query('SELECT COUNT(*) FROM discount_codes')->fetchColumn(), 'nothing created');
    contains('Vytvořeno 3 kódy pro 2 adresy, odeslané e-maily: 0 z 2.', create_discount_codes("a@example.com, b@example.com;\nA@example.com", 'test'), 'created');
    same(2, (int) db_query("SELECT COUNT(*) FROM discount_codes WHERE email = 'a@example.com'")->fetchColumn(), 'two codes for a listed twice');
    $codes = db_query('SELECT code FROM discount_codes')->fetchAll(PDO::FETCH_COLUMN);
    same([], array_values(preg_grep('/^(?:[BCDFGHJKLMNPQRSTVWX][AEU]|[2-9]{2}){4}$/', $codes, PREG_GREP_INVERT) ?: []), 'four pairs: syllable or two digits');
    same('ABCDEFGH', normalize_discount_code(' abcd-efgh '), 'normalized input');
});

test('e-mails share one HTML layout; the reservation e-mail embeds the payment QR', function (): void {
    $html = mail_layout(mail_text_html("Dobrý den,\n\n<b>Kód</b>:\n    ABCD-EFGH\n\nOdkaz: https://example.com/?r=1&x=2."), 'Rezervace míst');
    contains('Těšín zpívá gospel', $html, 'header');
    contains('Rezervace míst</p>', $html, 'subtitle');
    contains(nl2br(htmlspecialchars(mail_signature()), false) . '</p></div></body>', $html, 'one footer for every e-mail');
    contains('&lt;b&gt;Kód&lt;/b&gt;:<br><strong', $html, 'escaped, line break');
    contains('>ABCD-EFGH</strong>', $html, 'code line');
    contains('<a href="https://example.com/?r=1&amp;x=2" style="color:#c42aae">https://example.com/?r=1&amp;x=2</a>.', $html, 'link without the full stop');
    $r = row(reservation(['PK-7-4']));
    $qr = payment_qr_image($r) ?? [];
    same(['payment-qr', 'QR_platba.png', 'image/png', "\x89PNG"], [$qr['cid'] ?? null, $qr['name'] ?? null, $qr['type'] ?? null, substr($qr['data'] ?? '', 0, 4)], 'QR image');
    $body = payment_email_html($r, true);
    contains('<img src="cid:payment-qr"', $body, 'QR inline');
    contains('font-weight:bold;font-size:17px">' . $r['specific_symbol'] . '</td>', $body, 'SS highlighted');
    contains('font-weight:bold;font-size:17px">' . seat_label('PK-7-4') . ' – Dospělý</td>', $body, 'seat in long format');
    same(false, str_contains(payment_email_html($r, false), 'cid:'), 'no QR without IBAN');
});

test('codes of one address go in one e-mail', function (): void {
    $one = discount_codes_mail_text(['ABCDEFGH']);
    contains('slevový kód na jedno místo zdarma', $one, 'one code');
    contains('    ABCD-EFGH', $one, 'formatted');
    contains('Na velikosti písmen nezáleží', $one, 'case-insensitive note');
    $two = discount_codes_mail_text(['ABCDEFGH', 'JKLMNPQR']);
    contains('posíláme Vám 2 slevové kódy – každý na jedno místo zdarma', $two, 'two codes');
    contains("    ABCD-EFGH\n    JKLM-NPQR", $two, 'both codes');
    contains('jeden na místo', $two, 'more codes in one reservation');
    contains('5 slevových kódů', discount_codes_mail_text(array_fill(0, 5, 'ABCDEFGH')), 'five codes');
});

test('cancelling a seat paid by a code returns nothing; a child seat returns its price', function (): void {
    $id = reservation(['PH-4-1', 'PH-4-2', 'PH-4-3'], 'paid', 1, '', ['PH-4-3'], ['PH-4-1']);
    same(450, (int) row($id)['amount'], 'price');
    cancel_seats($id, ['PH-4-1']);
    same([450, 0], [(int) row($id)['amount'], (int) row($id)['refund_amount']], 'free seat: nothing back');
    cancel_seats($id, ['PH-4-3']);
    $r = row($id);
    same([['PH-4-2'], 300, 150], [current_seat_ids($r), (int) $r['amount'], (int) $r['refund_amount']], 'child seat');
    cancel_seats($id, null);
    same(['cancelled', 450], [row($id)['status'], (int) row($id)['refund_amount']], 'the adult seat in full');
});

test('cancelling the paid seats of a pending reservation leaves the free seats confirmed', function (): void {
    $id = reservation(['PH-5-1', 'PH-5-2'], 'pending', 1, '', [], ['PH-5-1']);
    cancel_seats($id, ['PH-5-2']);
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
        $r = book(['PH-8-1'])['reservation'] ?? [];
        $hours = (utc_time($r['expires_at'])->getTimestamp() - utc_time($r['created_at'])->getTimestamp()) / 3600;
        same(24, (int) round($hours), 'due date of a new reservation');
    } finally {
        save_payment_deadline_rules([]);
    }
});

// ----------------------------------------------------------- check-in

test('each seat ticket is checked in once; cancelled seats and other runs are refused', function (): void {
    $id = reservation(['PH-6-1', 'PH-6-2', 'PH-6-3'], 'paid', 2, 'Vstupní', ['PH-6-2']);
    cancel_seats($id, ['PH-6-3']);
    $r = row($id);
    $run = run_by_id(2) ?? [];
    // Run 2 started an hour ago – checked in with the organizer's confirmation outside the window.
    same('outside_window', verify_ticket(ticket_code($r, 'PH-6-1'), $run, device('Vchod A'), false)['result'], 'outside window');
    $first = verify_ticket(ticket_code($r, 'PH-6-1'), $run, device('Vchod A'), true);
    same(['valid', 'PH-6-1', 'adult', 1, 2], [$first['result'], $first['ticket']['seat'], $first['ticket']['kind'], $first['ticket']['checkedIn'], $first['ticket']['count']], 'first seat');
    same('used', verify_ticket(ticket_code($r, 'PH-6-1'), $run, device('Vchod B'), true)['result'], 'same seat again');
    $child = verify_ticket(ticket_code($r, 'PH-6-2'), $run, device('Vchod B'), true);
    same(['valid', 'child', 2], [$child['result'], $child['ticket']['kind'], $child['ticket']['checkedIn']], 'second seat');
    same('seat_cancelled', verify_ticket(ticket_code($r, 'PH-6-3'), $run, device('Vchod A'), true)['result'], 'cancelled seat');
    same('wrong_run', verify_ticket(ticket_code($r, 'PH-6-2'), run_by_id(1) ?? [], device('Vchod A'), false)['result'], 'other run');
    same('invalid', verify_ticket('Z26|1|2|3|x|y|z', $run, device('Vchod A'), false)['result'], 'not a ticket code');
    same('Vchod A', row($id)['checked_in_by'], 'reservation checked in');
});

test('offline: seats of one reservation are checked in separately', function (): void {
    db()->exec('DELETE FROM scan_conflicts');
    $id = reservation(['PH-7-1', 'PH-7-2'], 'paid');
    $ss = row($id)['specific_symbol'];
    $at = iso_utc(now_utc()->modify('-3 minutes'));
    $res = apply_offline_scans(device('Vchod A'), run_by_id(1) ?? [], [
        ['type' => 'ticket', 'id' => $id, 'specificSymbol' => $ss, 'seat' => 'PH-7-1', 'at' => $at],
        ['type' => 'ticket', 'id' => $id, 'specificSymbol' => $ss, 'seat' => 'PH-7-2', 'at' => $at],
        ['type' => 'ticket', 'id' => $id, 'specificSymbol' => $ss, 'seat' => 'PH-7-3', 'at' => $at],
    ]);
    same(['ok', 'ok', 'conflict'], array_column($res['results'], 'status'), 'two seats in, unknown seat refused');
    same(['PH-7-1', 'PH-7-2'], array_keys(reservation_checkins(row($id))), 'both seats checked in');
    contains('řada 7 · místo 3', conflicts()[0]['label'] ?? '', 'conflict names the seat');
});

test('on-site sale: QR payment, then paid and every seat checked in; the accountant verifies it', function (): void {
    $run = run_by_id(2) ?? [];
    same(422, onsite_sale_create(device('Vchod A'), $run, ['XX-1-1'], 0)['status'] ?? null, 'invalid seat');
    $sold = onsite_sale_create(device('Vchod A'), $run, ['PK-5-2', 'PK-5-1'], 1);
    $sale = $sold['sale'] ?? [];
    same([450, ['PK-5-1', 'PK-5-2']], [$sale['amount'], $sale['seats']], 'sale');
    contains('*AM:450.00*', $sale['spd'], 'QR payment');
    same(409, onsite_sale_create(device('Vchod B'), $run, ['PK-5-1'], 0)['status'] ?? null, 'seat held by the open sale');
    contains('2 místa odbavena', (string) onsite_sale_paid(device('Vchod A'), $run, (int) $sale['id']), 'paid');
    $r = row((int) $sale['id']);
    same(['paid', 450, 'Vchod A', null], [$r['status'], (int) $r['paid_amount'], $r['sold_by'], $r['payment_checked_at']], 'reservation');
    same(['PK-5-1', 'PK-5-2'], array_keys(reservation_checkins($r)), 'checked in');
    same(null, onsite_sale_paid(device('Vchod A'), $run, (int) $sale['id']), 'only once');

    $other = onsite_sale_create(device('Vchod A'), $run, ['PK-5-3'], 0)['sale'] ?? [];
    same(true, onsite_sale_cancel($run, (int) $other['id']), 'cancelled');
    same([false, 'cancelled'], [isset(held(2)['PK-5-3']), row((int) $other['id'])['status']], 'seat free again');
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
        same(['', ''], [row($done)['email'], row($done)['first_name']], 'purged');
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
    $r = row(reservation(['BS-1-1', 'BS-1-2'], 'paid', 1, '', ['BS-1-2']));
    $code = ticket_code($r, 'BS-1-2');
    same(['id' => (int) $r['id'], 'specificSymbol' => $r['specific_symbol'], 'seat' => 'BS-1-2', 'kind' => 'child', 'name' => 'Test ' . $r['last_name']],
        parse_ticket_code($code), 'parsed');
    same('adult', parse_ticket_code(ticket_code($r, 'BS-1-1'))['kind'] ?? null, 'adult seat');
    same(null, parse_ticket_code(str_replace('BS-1-2', 'BS-1-3', $code)), 'tampered');
    $payload = reservation_payload($r);
    same(['BS-1-1', 'BS-1-2'], array_column($payload['tickets'], 'seat'), 'one ticket per seat');
    same(true, count(array_unique(array_column($payload['tickets'], 'code'))) === 2, 'codes differ');
});

test('every ticket of a reservation carries the reservation name', function (): void {
    $r = row(reservation(['BS-3-1', 'BS-3-2'], 'paid', 1, 'Jménová'));
    same(['Test Jménová', 'Test Jménová'], array_map(static fn ($s) => parse_ticket_code(ticket_code($r, $s))['name'] ?? null, ['BS-3-1', 'BS-3-2']), 'name in the QR codes');
    same(false, array_key_exists('holder', reservation_payload($r)['tickets'][0]), 'no per-seat name in the payload');
});

test('the printable PDF holds the tickets of the current seats', function (): void {
    $r = row(reservation(['BS-1-3', 'BS-1-4', 'BS-1-5', 'BS-1-6', 'BS-1-7'], 'paid', 1, 'Tisková', ['BS-1-7']));
    $pdf = ticket_pdf($r);
    same('%PDF-', substr($pdf, 0, 5), 'PDF');
    same(2, preg_match_all('#/Type\s*/Page[^s]#', $pdf), 'five tickets on two pages (four per page)');
});

test('the ticket calendar event covers the run with folded, escaped lines', function (): void {
    $r = row(reservation(['BS-2-1', 'BS-2-2'], 'paid', 1, 'Nováková, Jana'));
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
    contains('SUMMARY:Těšín zpívá gospel · Budoucí – ' . str_replace(',', '\,', implode('; ', seat_labels(current_seat_ids($r)))), $unfolded, 'summary');
    contains('QR kódy vstupenek z PDF v e-mailu', $unfolded, 'description');
    same(false, str_contains($unfolded, $r['specific_symbol']), 'no SS');
    contains('ORGANIZER;CN="' . config('MAIL_FROM_NAME') . '":mailto:', $unfolded, 'organizer = MAIL_FROM_NAME');
    same(true, str_ends_with($ics, "END:VCALENDAR\r\n"), 'CRLF');
    foreach (explode("\r\n", $ics) as $line) {
        same(true, strlen($line) <= 75 && mb_check_encoding($line, 'UTF-8'), "line length: {$line}");
    }
    same(null, ticket_ics(['run_id' => 999] + $r), 'missing run');
});

// ------------------------------------------------------------------- layout

test('a seat has exactly one id: leading zeros or a trailing new line are refused', function (): void {
    same([true, false, false, false, false, false], array_map('is_valid_seat_id', ['LH-1-1', 'LH-01-1', 'LH-1-01', "LH-1-1\n", 'LH-0-1', 'BL-02-1']), 'forms');
    $booking = fn ($seat) => book_reservation(run_by_id(1) ?? [], 'Jan', 'Židle', 'zidle@example.com', [$seat], 0, []);
    same(true, isset($booking('PK-7-2')['reservation']), 'booked');
    same([422, 409], [$booking('PK-07-2')['status'] ?? null, $booking('PK-7-2')['status'] ?? null], 'the same chair written differently / again');
});

test('two customers booking the same seat at the same moment: one gets it, the other a 409, nothing half-saved', function (): void {
    $bootstrap = var_export(dirname(__DIR__, 2) . '/api/lib/bootstrap.php', true);
    for ($round = 1; $round <= 4; $round++) {
        $shared = "LH-9-{$round}";
        $own = ['a' => 'LH-8-' . (2 * $round - 1), 'b' => 'LH-8-' . (2 * $round)];
        // Both processes connect first, then start booking at the same instant.
        $start = microtime(true) + 0.8;
        $procs = $pipes = [];
        foreach ($own as $who => $seat) {
            $code = "require {$bootstrap}; db(); @time_sleep_until({$start});"
                . ' $r = book_reservation(run_by_id(1) ?? [], "Souběh", ' . var_export($who, true) . ', '
                . var_export("soubeh-{$who}{$round}@example.com", true) . ', ' . var_export([$seat, $shared], true) . ', 0, []);'
                . ' echo json_encode(["status" => $r["status"] ?? 200, "id" => $r["reservation"]["id"] ?? null]);';
            $procs[$who] = proc_open([PHP_BINARY, '-r', $code], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes[$who])
                ?: throw new TestFailure('proc_open failed');
        }
        $out = [];
        foreach ($procs as $who => $proc) {
            $stdout = (string) stream_get_contents($pipes[$who][1]);
            $stderr = (string) stream_get_contents($pipes[$who][2]);
            proc_close($proc);
            $out[$who] = json_decode($stdout, true) ?? throw new TestFailure("round {$round}, {$who}: {$stdout}{$stderr}");
        }
        $statuses = array_column($out, 'status', null);
        sort($statuses);
        same([200, 409], $statuses, "round {$round}: statuses");
        $winner = $out['a']['status'] === 200 ? 'a' : 'b';
        $loser = $winner === 'a' ? 'b' : 'a';
        $holds = held(1);
        $holder = 'r' . $out[$winner]['id'];
        same([$holder, $holder, null], [$holds[$shared] ?? null, $holds[$own[$winner]] ?? null, $holds[$own[$loser]] ?? null], "round {$round}: seats held");
        $stmt = db()->prepare('SELECT COUNT(*) FROM reservations WHERE email IN (?, ?)');
        $stmt->execute(["soubeh-a{$round}@example.com", "soubeh-b{$round}@example.com"]);
        same(1, (int) $stmt->fetchColumn(), "round {$round}: reservations");
    }
});

test('seats per IP are limited per hour and per day; a refused or failed booking counts nothing', function (): void {
    db()->exec('DELETE FROM rate_limits');
    $hits = fn ($window) => (int) db_query("SELECT hits FROM rate_limits WHERE bucket = '" . hash('sha256', "seats|{$window}|" . client_ip()) . "'")->fetchColumn();
    same(true, seat_quota_take(30), '30 seats');
    same(false, seat_quota_take(15), 'over 40 an hour');
    same([30, 30], [$hits('hour'), $hits('day')], 'refused request not counted');
    same(true, seat_quota_take(10), 'up to 40');
    seat_quota_release(10);
    same([30, 30], [$hits('hour'), $hits('day')], 'released');
    db()->exec("UPDATE rate_limits SET window_start = UTC_TIMESTAMP() - INTERVAL 2 HOUR WHERE bucket = '" . hash('sha256', 'seats|hour|' . client_ip()) . "'");
    same(true, seat_quota_take(40), 'a new hour');
    same(false, seat_quota_take(20), 'over 80 a day');
    db()->exec('DELETE FROM rate_limits');
});

test('names lose line breaks, control and invisible characters', function (): void {
    same('Jan POZOR změna účtu x y', clean_name("Jan\r\n\nPOZOR\tzměna  účtu\u{202E}x|y "), 'cleaned');
    same('', clean_name(['pole']), 'not a string');
});

test('the layout matches the plan: seats per row, capacities, reserve balcony seats', function (): void {
    $layout = layout_public();
    same(array_keys(SECTIONS), array_column($layout['sections'], 'id'), 'order');
    $sizes = array_combine(array_column($layout['sections'], 'id'), array_map(fn ($s) => array_column($s['rows'], 'seats'), $layout['sections']));
    same([5, 5, 5, 5], $sizes['LK'], 'left wing');
    same(array_fill(0, 9, 8), $sizes['LH'], 'left main');
    same([4, 4, 4, 5, 5, 5, 4], $sizes['PK'], 'right wing');
    same([7, 14, 14, 7, 7], $sizes['BS'], 'balcony centre');
    same([12, 11, 11, 6], $sizes['BL'], 'balcony left: bench + reserve rows');
    same([5, 7], array_column(section_rows(SECTIONS['BL'])[0]['parts'], 0), 'bench split 1–5 | 6–12');
    same([[5, 6], [5, 6]], [array_column(section_rows(SECTIONS['BL'])[1]['parts'], 0), array_column(section_rows(SECTIONS['BL'])[2]['parts'], 0)], 'reserve rows 2 and 3 split 1–5 | 6–11');
    same([11, 3, 3], $sizes['BP'], 'balcony right');
    same([6, 5], array_column(section_rows(SECTIONS['BP'])[0]['parts'], 0), 'right bench split 1–6 | 7–11');
    same([195, 106, 301], [
        array_sum(array_map('section_capacity', array_filter(SECTIONS, fn ($s) => $s['level'] === 'main'))),
        array_sum(array_map('section_capacity', array_filter(SECTIONS, fn ($s) => $s['level'] === 'balcony'))),
        total_capacity(),
    ], 'capacities');
    $default = default_reserve_seats();
    same([34, true, false, true, false], [count($default), in_array('BL-2-1', $default, true), in_array('BL-1-12', $default, true),
        in_array('BP-3-3', $default, true), in_array('BP-1-11', $default, true)], 'default reserve rows');
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

test('"Záložní" seats: the reserve rows by default, any seat switchable per run, taken seats stay for sale', function (): void {
    $run = run_by_id(1) ?? [];
    same([34, 267], [count($run['reserve_seats']), run_capacity($run)], 'default');
    same(409, book(['BL-2-1'])['status'] ?? null, 'reserve seat cannot be booked');
    same(true, isset(book(['BL-1-6'])['reservation']), 'the bench of the same section can');
    try {
        same(['BL-1-6'], save_run_reserve_seats(1, ['LH-1-1', 'BL-1-6', 'XX-1-1']), 'a taken seat cannot become reserve');
        $run = run_by_id(1) ?? [];
        same([['LH-1-1'], 300], [$run['reserve_seats'], run_capacity($run)], 'only LH-1-1 reserve');
        same(true, isset(book(['BL-2-1'])['reservation']), 'former reserve seat booked');
        same(409, book(['LH-1-1'])['status'] ?? null, 'new reserve seat refused');
        same(['LH-1-1'], run_public($run)['reserveSeats'], 'sent to the apps');
        same(34, count(run_by_id(2)['reserve_seats'] ?? []), 'other runs keep the default');
    } finally {
        save_run_reserve_seats(1, null);
    }
    same(34, count(run_by_id(1)['reserve_seats'] ?? []), 'back to the default');
});

// ----------------------------------------------------------- user accounts

/** Invites and accepts an account; returns the signed-in user. */
function account(string $email, array $roles, string $password = 'dlouhe-heslo-1'): array
{
    $invite = admin_invite($email, $roles, 'test');
    if (!is_array($invite)) {
        throw new TestFailure("invite {$email}: {$invite}");
    }
    $me = admin_accept_invite($invite['token'], $password, $password);
    if (!is_array($me)) {
        throw new TestFailure("accept {$email}: {$me}");
    }
    return $me;
}

test('deploy creates the first Správce once; it needs DEPLOY_TOKEN', function (): void {
    same(false, admin_exists(), 'no Správce yet');
    same(false, deploy_token_ok(''), 'empty token');
    $configured = (string) config('DEPLOY_TOKEN');
    if ($configured !== '') {
        same([true, false], [deploy_token_ok($configured), deploy_token_ok($configured . 'x')], 'configured token');
    }
    same('Heslo musí mít alespoň 10 znaků.', create_first_admin('spravce@example.cz', 'kratke'), 'short password');
    $admin = create_first_admin(' Spravce@Example.cz ', 'spravcovo-heslo');
    same(['spravce@example.cz', 'admin', 'deploy'], is_array($admin) ? [$admin['email'], $admin['roles'], $admin['invited_by']] : $admin, 'created');
    same('Správce už existuje – deploy lze použít jen jednou.', create_first_admin('druhy@example.cz', 'druhe-heslo-22'), 'only once');
    same(['admin'], admin_login('spravce@example.cz', 'spravcovo-heslo')['roles'] ?? null, 'signs in');
});

test('an invited user gets an account with the roles once they set a password', function (): void {
    $invite = admin_invite(' Jana@Example.cz ', ['accountant', 'neznámá'], 'spravce@example.cz');
    same(true, is_array($invite), 'invited');
    $token = is_array($invite) ? $invite['token'] : '';
    same(['jana@example.cz', 'accountant', 'spravce@example.cz', null], [$invite['user']['email'] ?? null, $invite['user']['roles'] ?? null, $invite['user']['invited_by'] ?? null, $invite['user']['password_hash'] ?? null], 'invitation (not accepted yet)');
    same(null, admin_login('jana@example.cz', 'cokoliv-dlouheho'), 'no login before accepting');
    same('Heslo musí mít alespoň 10 znaků.', admin_accept_invite($token, 'kratke', 'kratke'), 'too short');
    same('Hesla se neshodují.', admin_accept_invite($token, 'dlouhe-heslo-1', 'dlouhe-heslo-2'), 'mismatch');
    $me = admin_accept_invite($token, 'dlouhe-heslo-1', 'dlouhe-heslo-1');
    same(['jana@example.cz', ['accountant']], is_array($me) ? [$me['email'], $me['roles']] : $me, 'accepted');
    same(null, admin_invited_user($token), 'link used up');
    same('jana@example.cz', admin_login('JANA@example.cz', 'dlouhe-heslo-1')['email'] ?? null, 'login (e-mail case-insensitive)');
    same(null, admin_login('jana@example.cz', 'spatne-heslo'), 'wrong password');
    same('Účet s tímto e-mailem už existuje.', admin_invite('jana@example.cz', ['scanner'], 'x'), 'duplicate');
    same('Neplatný e-mail.', admin_invite('neni-email', ['scanner'], 'x'), 'invalid e-mail');
    same('Vyberte alespoň jednu roli.', admin_invite('bez@example.cz', [], 'x'), 'no role');
    $expired = admin_invite('pozde@example.cz', ['scanner'], 'x');
    db()->prepare('UPDATE admin_users SET invite_expires_at = ? WHERE email = ?')->execute([db_time(now_utc()->modify('-1 minute')), 'pozde@example.cz']);
    same('Pozvánka neplatí nebo vypršela. Požádejte o novou.', admin_accept_invite(is_array($expired) ? $expired['token'] : '', 'pozde-heslo-1', 'pozde-heslo-1'), 'expired');
});

test('roles: Správce everything, Účetní reservations, Odbavovatel the scanner; several roles add up', function (): void {
    $can = fn (array $roles) => array_map(fn ($what) => user_can(['roles' => $roles], $what), ['manage', 'reservations', 'scan']);
    same([true, true, true], $can(['admin']), 'Správce');
    same([false, true, false], $can(['accountant']), 'Účetní');
    same([false, false, true], $can(['scanner']), 'Odbavovatel');
    same([false, true, true], $can(['accountant', 'scanner']), 'Účetní + Odbavovatel');
    $me = account('role@example.cz', ['scanner']);
    same('Role a termíny účtu role@example.cz uloženy.', admin_set_roles($me['id'], ['accountant', 'scanner'], 999), 'changed');
    same(['accountant', 'scanner'], admin_login('role@example.cz', 'dlouhe-heslo-1')['roles'] ?? null, 'new roles');
    $admin = account('admin2@example.cz', ['admin']);
    same('Svou vlastní roli Správce nelze odebrat.', admin_set_roles($admin['id'], ['accountant'], $admin['id']), 'not your own Správce role');
});

test('forgotten password: an e-mailed link sets a new one and signs out the old sessions', function (): void {
    $me = account('zapomnel@example.cz', ['accountant'], 'stare-heslo-1');
    same($me['email'], admin_from_session($me['id'], $me['pw'])['email'] ?? null, 'session');
    same(null, request_password_reset('nikdo@example.cz'), 'unknown e-mail: nothing (same answer for the visitor)');
    $token = (string) request_password_reset('Zapomnel@example.cz');
    same($me['email'], password_reset_user($token)['email'] ?? null, 'link valid');
    same($me['email'], admin_login('zapomnel@example.cz', 'stare-heslo-1')['email'] ?? null, 'old password works until changed');
    same('Hesla se neshodují.', reset_password($token, 'nove-heslo-11', 'jine-heslo-11'), 'mismatch');
    $new = reset_password($token, 'nove-heslo-11', 'nove-heslo-11');
    same($me['email'], is_array($new) ? $new['email'] : $new, 'changed');
    same([null, $me['email']], [admin_login('zapomnel@example.cz', 'stare-heslo-1'), admin_login('zapomnel@example.cz', 'nove-heslo-11')['email'] ?? null], 'new password');
    same(null, admin_from_session($me['id'], $me['pw']), 'old session signed out');
    same('Odkaz neplatí nebo vypršel. Požádejte o nový.', reset_password($token, 'treti-heslo-11', 'treti-heslo-11'), 'link used up');
    $late = (string) request_password_reset('zapomnel@example.cz');
    db()->prepare('UPDATE admin_users SET reset_expires_at = ? WHERE id = ?')->execute([db_time(now_utc()->modify('-1 minute')), $me['id']]);
    same(null, password_reset_user($late), 'expired');
    request_password_reset('zapomnel@example.cz');
    same(null, request_password_reset('zapomnel@example.cz'), 'at most 3 e-mails an hour');
});

test('disabled accounts cannot sign in and lose their session; nobody disables themselves', function (): void {
    $me = account('eva@example.cz', ['accountant'], 'evino-heslo-1');
    same('Svůj vlastní účet nelze vypnout.', admin_set_disabled($me['id'], true, $me['id']), 'not yourself');
    admin_set_disabled($me['id'], true, 999);
    same([null, null], [admin_login('eva@example.cz', 'evino-heslo-1'), admin_from_session($me['id'], $me['pw'])], 'disabled');
    admin_set_disabled($me['id'], false, 999);
    same('eva@example.cz', admin_login('eva@example.cz', 'evino-heslo-1')['email'] ?? null, 'enabled again');
});

test('the scanner signs in Odbavovatel and Správce accounts; a new password, a removed role or a disabled account lock the device out', function (): void {
    $scanner = account('vchod@example.cz', ['scanner'], 'vchodove-heslo');
    account('ucetni-jen@example.cz', ['accountant'], 'ucetni-heslo-1');
    same('Nesprávný e-mail nebo heslo.', scanner_login('vchod@example.cz', 'spatne-heslo'), 'wrong password');
    same('Tento účet nemá roli Odbavovatel. Požádejte správce o přidání role.', scanner_login('ucetni-jen@example.cz', 'ucetni-heslo-1'), 'Účet without the role');
    same(['name' => 'vchod@example.cz', 'runIds' => []], array_intersect_key((array) scanner_login('vchod@example.cz', 'vchodove-heslo'), ['name' => 1, 'runIds' => 1]), 'signed in, all runs');
    same(true, is_array(scanner_login('spravce@example.cz', 'spravcovo-heslo')), 'Správce may scan');
    $cookie = scanner_cookie_for($scanner);
    same($scanner['email'], scanner_access_from_cookie($cookie)['name'] ?? null, 'cookie');
    same(null, scanner_access_from_cookie(substr($cookie, 0, -1) . (str_ends_with($cookie, 'a') ? 'b' : 'a')), 'forged');
    admin_set_roles($scanner['id'], ['accountant'], 999);
    same(null, scanner_access_from_cookie($cookie), 'role removed');
    admin_set_roles($scanner['id'], ['scanner'], 999);
    same($scanner['email'], scanner_access_from_cookie($cookie)['name'] ?? null, 'role back');
    $token = (string) request_password_reset('vchod@example.cz');
    reset_password($token, 'nove-vchodove-1', 'nove-vchodove-1');
    same(null, scanner_access_from_cookie($cookie), 'new password');
});

test('an Odbavovatel checks in only the assigned runs (none, one or several); a run can have several; a Správce checks in all', function (): void {
    $none = account('bez-terminu@example.cz', ['scanner']);
    same([[], []], [scanner_access_of($none)['runIds'], scanner_runs(scanner_access_of($none))], 'no run assigned');
    $invite = admin_invite('dva-terminy@example.cz', ['scanner'], 'test', [1, 2, 999]);
    $both = is_array($invite) ? (int) $invite['user']['id'] : 0;
    same([2, 1], user_run_ids($both), 'invited with two runs (in start order), unknown run ignored');
    save_user_runs($none['id'], [2]);
    $access = scanner_access_of(signed_in_user(admin_user($none['id']) ?? []));
    same([[2], false, true], [$access['runIds'], scanner_can_use_run($access, 1), scanner_can_use_run($access, 2)], 'one run');
    same(['bez-terminu@example.cz', 'dva-terminy@example.cz'], run_scanner_emails(2), 'run 2 has two Odbavovatelé');
    $admin = account('spravce-terminy@example.cz', ['admin', 'scanner']);
    same(null, scanner_access_of($admin)['runIds'], 'Správce: every run');
    db()->prepare('INSERT INTO runs (label, starts_at) VALUES (?, ?)')->execute(['Smazaný', db_time(now_utc()->modify('+40 days'))]);
    $runId = (int) db()->lastInsertId();
    runs(true);
    save_user_runs($both, [1, 2, $runId]);
    db()->prepare('DELETE FROM runs WHERE id = ?')->execute([$runId]);
    runs(true);
    same([2, 1], user_run_ids($both), 'a deleted run disappears from the assignment');
});

// ------------------------------------------------------------------ offline

/** Scanner access as scanner_access() returns it. */
function device(string $name): array
{
    return ['id' => 1, 'name' => $name, 'roles' => ['scanner'], 'runIds' => null];
}

function conflicts(): array
{
    return db_query('SELECT reason, label, scanned_by, other_by FROM scan_conflicts ORDER BY id')->fetchAll();
}

test('snapshot lists the run\'s tickets without e-mails', function (): void {
    $paid = reservation(['BP-1-1', 'BP-1-2'], 'paid', 1, 'Seznamová');
    $snapshot = scanner_snapshot(run_by_id(1) ?? []);
    $ticket = array_values(array_filter($snapshot['tickets'], fn ($t) => $t['id'] === $paid))[0] ?? null;
    same(['Test Seznamová', ['BP-1-1', 'BP-1-2'], 'paid', []], [$ticket['name'] ?? null, $ticket['seats'] ?? null, $ticket['status'] ?? null, (array) ($ticket['checkedIn'] ?? null)], 'ticket');
    same(false, array_key_exists('email', $ticket ?? []), 'no e-mail');
});

test('offline check-ins are applied with the device time; the first one wins', function (): void {
    db()->exec('DELETE FROM scan_conflicts');
    $id = reservation(['BP-1-3'], 'paid');
    $ss = row($id)['specific_symbol'];
    $at = now_utc()->modify('-10 minutes');
    $event = ['type' => 'ticket', 'id' => $id, 'specificSymbol' => $ss, 'seat' => 'BP-1-3', 'at' => iso_utc($at)];
    $res = apply_offline_scans(device('Vchod A'), run_by_id(1) ?? [], [$event]);
    same([['status' => 'ok']], $res['results'], 'applied');
    same([db_time($at), 'Vchod A (offline)'], [row($id)['checked_in_at'], row($id)['checked_in_by']], 'stored');
    // The same event sent again (response lost) is not a conflict.
    same(0, apply_offline_scans(device('Vchod A'), run_by_id(1) ?? [], [$event])['conflicts'], 'resend');
    // Another device let the same ticket in offline too.
    $other = ['type' => 'ticket', 'id' => $id, 'specificSymbol' => $ss, 'seat' => 'BP-1-3', 'at' => iso_utc($at->modify('+2 minutes'))];
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
    $pending = reservation(['BP-1-4']);
    $otherRun = reservation(['BP-1-5'], 'paid', 2);
    $events = [
        ['type' => 'ticket', 'id' => $pending, 'specificSymbol' => row($pending)['specific_symbol'], 'seat' => 'BP-1-4', 'at' => iso_utc(now_utc())],
        ['type' => 'ticket', 'id' => $otherRun, 'specificSymbol' => row($otherRun)['specific_symbol'], 'seat' => 'BP-1-5', 'at' => iso_utc(now_utc())],
        ['type' => 'ticket', 'id' => 999999, 'specificSymbol' => '1', 'seat' => 'BP-1-6', 'at' => iso_utc(now_utc())],
    ];
    $res = apply_offline_scans(device('Vchod A'), run_by_id(1) ?? [], $events);
    same(['not_paid', 'unknown', 'unknown'], array_column($res['results'], 'reason'), 'reasons');
    same(null, row($otherRun)['checked_in_at'], 'other run untouched');
});

test('device time is limited to the last 48 hours and never in the future', function (): void {
    $future = reservation(['BP-1-8'], 'paid');
    $old = reservation(['BP-1-9'], 'paid');
    apply_offline_scans(device('Vchod A'), run_by_id(1) ?? [], [
        ['type' => 'ticket', 'id' => $future, 'specificSymbol' => row($future)['specific_symbol'], 'seat' => 'BP-1-8', 'at' => iso_utc(now_utc()->modify('+3 hours'))],
        ['type' => 'ticket', 'id' => $old, 'specificSymbol' => row($old)['specific_symbol'], 'seat' => 'BP-1-9', 'at' => '2001-01-01T00:00:00Z'],
    ]);
    same(true, row($future)['checked_in_at'] <= db_time(now_utc()), 'not in the future');
    same(true, row($old)['checked_in_at'] >= db_time(now_utc()->modify('-49 hours')), 'not older than 48 h');
});

test('a mail that cannot be handed over returns false instead of a 500 (mail() disabled by the host)', function (): void {
    $code = 'require ' . var_export(dirname(__DIR__, 2) . '/api/lib/bootstrap.php', true) . ';'
        . " echo var_export(deliver_mail('test@example.com', 'Pozvánka', 'text'), true);";
    $out = (string) shell_exec('LOG_DIR= ' . escapeshellarg(PHP_BINARY) . ' -d disable_functions=mail -r ' . escapeshellarg($code) . ' 2>/dev/null');
    same('false', $out, 'deliver_mail() result');
});

test('errors are written to LOG_DIR/php-error-YYYY-MM.log by the app itself, closed to the web', function (): void {
    $dir = sys_get_temp_dir() . '/zidle-log-' . bin2hex(random_bytes(4));
    // PHP's own error_log points elsewhere, as when a host fixes it: the app still writes its file.
    $script = $dir . '.php'; // a script file: `php -r` skips exception handlers
    file_put_contents($script, '<?php require ' . var_export(dirname(__DIR__, 2) . '/api/lib/bootstrap.php', true) . ';'
        . " app_log('hello'); trigger_error('careful', E_USER_WARNING); throw new RuntimeException('boom');");
    shell_exec('LOG_DIR=' . escapeshellarg($dir) . ' ' . escapeshellarg(PHP_BINARY) . ' -d error_log=/dev/null ' . escapeshellarg($script) . ' 2>&1');
    unlink($script);
    $log = (string) @file_get_contents($dir . '/php-error-' . gmdate('Y-m') . '.log');
    same("Require all denied\n", @file_get_contents($dir . '/.htaccess'), 'deny-all .htaccess');
    contains('UTC] [zidle] hello', $log, 'app_log line');
    contains('PHP Warning: careful in ' . $script . ':1', $log, 'warning');
    contains('[zidle] Uncaught on CLI', $log, 'uncaught exception');
    contains('RuntimeException: boom', $log, 'exception text');
    array_map(static fn ($f) => @unlink($f), [$dir . '/.htaccess', $dir . '/php-error-' . gmdate('Y-m') . '.log']);
    @rmdir($dir);
});

// Runs last: checks every reservation the tests above left behind.
test('stored totals match the items: amount = current seat prices, first check-in = earliest seat check-in', function (): void {
    $bad = db_query(
        'SELECT r.id, r.amount, r.checked_in_at, r.checked_in_by,
                COALESCE(SUM(CASE WHEN i.cancelled_at IS NULL THEN i.price END), 0) AS items_amount,
                MIN(i.checked_in_at) AS first_at,
                (SELECT f.checked_in_by FROM reservation_items f WHERE f.reservation_id = r.id AND f.checked_in_at IS NOT NULL
                  ORDER BY f.checked_in_at, f.seat_id LIMIT 1) AS first_by
           FROM reservations r LEFT JOIN reservation_items i ON i.reservation_id = r.id
          GROUP BY r.id
         HAVING r.amount <> items_amount OR NOT (r.checked_in_at <=> first_at) OR NOT (r.checked_in_by <=> first_by)'
    )->fetchAll();
    same([], $bad, 'reservations whose stored totals differ from their items');
    same(true, (int) db_query('SELECT COUNT(*) FROM reservations WHERE checked_in_at IS NOT NULL')->fetchColumn() > 0, 'some check-ins were covered');
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
