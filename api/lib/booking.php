<?php
// Creating a reservation (after the request was validated in reservations.php):
// prices, discount codes, seat locking, specific symbol (SS) and the first e-mail.
declare(strict_types=1);

/**
 * Books $seats (sorted; ids in the exact form, checked again here) of the run. The last $children seats are child seats,
 * each discount code (normalized) frees the most expensive seat; a reservation that
 * costs nothing is paid at once and gets its tickets, otherwise the payment e-mail.
 * $soldBy: on-site sale by this organizer (onsite.php) – no e-mail, no limit of unpaid
 * reservations, due at once.
 * Test run ($run['is_test']): discount codes are refused (real codes must not be used up) and
 * an online booking is paid at once (paid_amount = amount) and gets its tickets; on-site
 * sales keep the normal flow so the organizers can practise it.
 * Returns ['reservation' => row] or ['error' => message, 'status' => HTTP status, 'extra' => array].
 */
function book_reservation(array $run, string $firstName, string $lastName, string $email, array $seats, int $children, array $codes, ?string $soldBy = null): array
{
    if (!$seats || array_filter($seats, static fn ($seat) => !is_string($seat) || !is_valid_seat_id($seat))) {
        return ['error' => 'Neplatné místo.', 'status' => 422, 'extra' => []];
    }
    if ($codes && !empty($run['is_test'])) {
        $message = 'Na testovacím termínu nelze použít slevové kódy.';
        return ['error' => $message, 'status' => 422, 'extra' => ['fields' => ['codes' => $message]]];
    }
    $closed = array_values(array_filter($seats, static fn ($seat) => seat_closed_in_run($run, $seat)));
    if ($closed) {
        return ['error' => 'Některá místa zatím nejsou v prodeji.', 'status' => 409, 'extra' => ['conflict' => $closed]];
    }
    $childSeats = assign_child_seats($seats, $children);
    $freeSeats = assign_free_seats($seats, $childSeats, count($codes), adult_price(), child_price());
    $items = new_reservation_items($seats, $childSeats, $freeSeats, adult_price(), child_price());
    $amount = array_sum(array_column($items, 'price'));
    $free = $amount === 0; // everything paid by discount codes: confirmed at once
    $test = !empty($run['is_test']) && $soldBy === null; // test run: paid at once
    $paid = $free || $test;

    // Counted per run, so one person can book several runs at once.
    $pending = db()->prepare("SELECT COUNT(*) FROM reservations WHERE email = ? AND run_id = ? AND status = 'pending'");
    $pending->execute([$email, $run['id']]);
    if ($soldBy === null && !$paid && (int) $pending->fetchColumn() >= (int) config('PENDING_RESERVATIONS_PER_EMAIL')) {
        return ['error' => 'Na tento e-mail už na toto představení čekají nezaplacené rezervace. Nejdříve je prosím uhraďte.', 'status' => 429, 'extra' => []];
    }

    $pdo = db();
    $now = now_utc();
    // Due date: hours by the deadline rule in effect now (payment_deadline_hours()), never after the start of the run.
    $expires = $soldBy !== null ? $now : min($now->modify('+' . payment_deadline_hours($now) . ' hours'), run_starts($run));
    $token = bin2hex(random_bytes(16));

    // Simultaneous bookings of nearby seats can deadlock on InnoDB gap locks; the retry then sees the winner's seats.
    for ($attempt = 1; ; $attempt++) {
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
                   (token, run_id, first_name, last_name, email, amount, paid_amount, status, created_at, expires_at, paid_at, sold_by)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
            )->execute([
                $token, $run['id'], $firstName, $lastName, $email, $amount, $test ? $amount : 0,
                $paid ? 'paid' : 'pending', db_time($now), db_time($expires), $paid ? db_time($now) : null, $soldBy,
            ]);
            $id = (int) $pdo->lastInsertId();
            $pdo->prepare('UPDATE reservations SET specific_symbol = ? WHERE id = ?')
                ->execute([generate_specific_symbol($pdo), $id]);
            insert_reservation_items($pdo, $id, $items);
            $useCode = $pdo->prepare('UPDATE discount_codes SET used_at = ?, reservation_id = ?, seat_id = ? WHERE id = ?');
            foreach ($codeIds as $i => $codeId) {
                $useCode->execute([db_time($now), $id, $freeSeats[$i], $codeId]);
            }

            $insertSeat = $pdo->prepare('INSERT INTO reservation_seats (run_id, seat_id, reservation_id) VALUES (?, ?, ?)');
            foreach ($seats as $seatId) {
                $insertSeat->execute([$run['id'], $seatId, $id]);
            }
            $pdo->commit();
            break;
        } catch (PDOException $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            // Duplicate key: a concurrent request took one of the seats first.
            if (($e->errorInfo[1] ?? null) === 1062) {
                return ['error' => 'Některá místa už mezitím někdo rezervoval.', 'status' => 409, 'extra' => ['conflict' => []]];
            }
            if ($e->getCode() === '40001' && $attempt < 3) {
                continue;
            }
            throw $e;
        }
    }

    if ($test) {
        send_ticket_for($id, ['děkujeme za rezervaci. Jde o testovací termín – rezervace je automaticky zaplacená.'], 'Rezervace potvrzena – Vaše vstupenky');
    } elseif ($free) {
        send_ticket_for($id, ['děkujeme za rezervaci. Všechna místa jsou hrazená slevovými kódy, rezervace je potvrzena.'], 'Rezervace potvrzena – Vaše vstupenky');
    }
    $r = find_reservation_by_token($token);
    if ($r === null) {
        throw new RuntimeException('Reservation just created was not found.');
    }
    if (!$paid) {
        send_payment_email($r);
    }
    return ['reservation' => $r];
}

/**
 * Random 10-digit specific symbol (SS) that is not used by any reservation yet.
 * Random instead of sequential so it does not reveal the number of
 * reservations and cannot be guessed; uq_ss guarantees uniqueness.
 */
function generate_specific_symbol(PDO $pdo): string
{
    $check = $pdo->prepare('SELECT 1 FROM reservations WHERE specific_symbol = ?');
    for ($i = 0; $i < 20; $i++) {
        $ss = (string) random_int(1_000_000_000, 9_999_999_999);
        $check->execute([$ss]);
        if (!$check->fetchColumn()) {
            return $ss;
        }
    }
    throw new RuntimeException('Could not generate a unique specific symbol.');
}
