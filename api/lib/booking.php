<?php
// Creating a reservation (after the request was validated in reservations.php):
// prices, discount codes, seat locking, variable symbol and the first e-mail.
declare(strict_types=1);

/**
 * Books $seats (valid, sorted) of the run. The last $children seats are child seats,
 * each discount code (normalized) frees the most expensive seat; a reservation that
 * costs nothing is paid at once and gets its tickets, otherwise the payment e-mail.
 * $soldBy: on-site sale by this organizer (onsite.php) – no e-mail, no limit of unpaid
 * reservations, due at once.
 * Returns ['reservation' => row] or ['error' => message, 'status' => HTTP status, 'extra' => array].
 */
function book_reservation(array $run, string $firstName, string $lastName, string $email, array $seats, int $children, array $codes, ?string $soldBy = null): array
{
    $childSeats = assign_child_seats($seats, $children);
    $freeSeats = assign_free_seats($seats, $childSeats, count($codes), adult_price(), child_price());
    $amount = seats_value([
        'child_seats' => implode(',', $childSeats), 'free_seats' => implode(',', $freeSeats),
        'adult_price' => adult_price(), 'child_price' => child_price(),
    ], $seats);
    $free = $amount === 0; // everything paid by discount codes: confirmed at once

    // Counted per run, so one person can book several runs at once.
    $pending = db()->prepare("SELECT COUNT(*) FROM reservations WHERE email = ? AND run_id = ? AND status = 'pending'");
    $pending->execute([$email, $run['id']]);
    if ($soldBy === null && !$free && (int) $pending->fetchColumn() >= (int) config('PENDING_RESERVATIONS_PER_EMAIL')) {
        return ['error' => 'Na tento e-mail už na toto představení čekají nezaplacené rezervace. Nejdříve je prosím uhraďte.', 'status' => 429, 'extra' => []];
    }

    $pdo = db();
    $now = now_utc();
    // Due date: hours by the deadline rule in effect now (payment_deadline_hours()), never after the start of the run.
    $expires = $soldBy !== null ? $now : min($now->modify('+' . payment_deadline_hours($now) . ' hours'), run_starts($run));
    $token = bin2hex(random_bytes(16));

    $pdo->beginTransaction();
    try {
        $placeholders = implode(',', array_fill(0, count($seats), '?'));
        $stmt = $pdo->prepare("SELECT seat_id FROM reservation_seats WHERE run_id = ? AND seat_id IN ($placeholders) FOR UPDATE");
        $stmt->execute([$run['id'], ...$seats]);
        $conflict = $stmt->fetchAll(PDO::FETCH_COLUMN);
        if ($conflict) {
            $pdo->rollBack();
            return ['error' => 'Některá místa už mezitím někdo rezervoval.', 'status' => 409, 'extra' => ['conflict' => $conflict]];
        }
        $codeIds = lock_discount_codes($pdo, $codes);
        if (is_string($codeIds)) {
            $pdo->rollBack();
            $message = "Slevový kód {$codeIds} neplatí nebo už byl použit.";
            return ['error' => $message, 'status' => 422, 'extra' => ['fields' => ['codes' => $message]]];
        }

        $pdo->prepare(
            'INSERT INTO reservations
               (token, run_id, first_name, last_name, email, seats, child_seats, free_seats, seat_count,
                adult_price, child_price, amount, status, created_at, expires_at, paid_at, sold_by)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        )->execute([
            $token, $run['id'], $firstName, $lastName, $email, implode(',', $seats), implode(',', $childSeats),
            implode(',', $freeSeats), count($seats), adult_price(), child_price(), $amount,
            $free ? 'paid' : 'pending', db_time($now), db_time($expires), $free ? db_time($now) : null, $soldBy,
        ]);
        $id = (int) $pdo->lastInsertId();
        $pdo->prepare('UPDATE reservations SET variable_symbol = ? WHERE id = ?')
            ->execute([generate_variable_symbol($pdo), $id]);
        $useCode = $pdo->prepare('UPDATE discount_codes SET used_at = ?, reservation_id = ?, seat_id = ? WHERE id = ?');
        foreach ($codeIds as $i => $codeId) {
            $useCode->execute([db_time($now), $id, $freeSeats[$i], $codeId]);
        }

        $insertSeat = $pdo->prepare('INSERT INTO reservation_seats (run_id, seat_id, reservation_id) VALUES (?, ?, ?)');
        foreach ($seats as $seatId) {
            $insertSeat->execute([$run['id'], $seatId, $id]);
        }
        $pdo->commit();
    } catch (PDOException $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        // Duplicate key: a concurrent request took one of the seats first.
        if ($e->getCode() === '23000') {
            return ['error' => 'Některá místa už mezitím někdo rezervoval.', 'status' => 409, 'extra' => ['conflict' => []]];
        }
        throw $e;
    }

    if ($free) {
        send_ticket_for($id, ['děkujeme za rezervaci. Všechna místa jsou hrazená slevovými kódy, rezervace je potvrzena.'], 'Vstupenky');
    }
    $r = find_reservation_by_token($token);
    if ($r === null) {
        throw new RuntimeException('Reservation just created was not found.');
    }
    if (!$free) {
        send_payment_email($r);
    }
    return ['reservation' => $r];
}

/**
 * Random 10-digit variable symbol that is not used by any reservation yet.
 * Random instead of sequential so it does not reveal the number of
 * reservations and cannot be guessed; uq_vs guarantees uniqueness.
 */
function generate_variable_symbol(PDO $pdo): string
{
    $check = $pdo->prepare('SELECT 1 FROM reservations WHERE variable_symbol = ?');
    for ($i = 0; $i < 20; $i++) {
        $vs = (string) random_int(1_000_000_000, 9_999_999_999);
        $check->execute([$vs]);
        if (!$check->fetchColumn()) {
            return $vs;
        }
    }
    throw new RuntimeException('Could not generate a unique variable symbol.');
}
