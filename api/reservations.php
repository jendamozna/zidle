<?php
// POST api/reservations.php        – create a reservation
//      body: {runId, firstName, lastName, email, seats: ["ML-1-1", ...], formToken, hp}
// GET  api/reservations.php?token= – reservation status and payment details
declare(strict_types=1);
require __DIR__ . '/lib/bootstrap.php';

run_api(function (): void {
    $method = $_SERVER['REQUEST_METHOD'];
    if ($method === 'GET') {
        show_reservation();
    } elseif ($method === 'POST') {
        create_reservation();
    } else {
        json_error('Metoda není povolena.', 405);
    }
});

function show_reservation(): void
{
    $token = (string) ($_GET['token'] ?? '');
    if (!preg_match('/^[a-f0-9]{32}$/', $token)) {
        json_error('Rezervace nenalezena.', 404);
    }
    expire_reservations();
    $r = find_reservation_by_token($token);
    if ($r === null) {
        json_error('Rezervace nenalezena.', 404);
    }
    json_response(reservation_payload($r));
}

function create_reservation(): void
{
    if (trim((string) config('BANK_IBAN')) === '') {
        json_error('Platby nejsou nastaveny (BANK_IBAN).', 503);
    }

    $body = read_json_body();

    $run = run_by_id((int) ($body['runId'] ?? 0));
    if ($run === null) {
        json_error('Vyberte termín.', 422);
    }
    if (!run_booking_open($run)) {
        json_error('Rezervace na tento termín jsou uzavřeny.', 403);
    }

    // Bot checks: honeypot must stay empty, form token must be issued by us and not too fresh.
    if (trim((string) ($body['hp'] ?? '')) !== '' || !form_token_valid((string) ($body['formToken'] ?? ''))) {
        error_log('[zidle] Rejected reservation as bot from ' . client_ip());
        json_error('Rezervaci se nepodařilo odeslat. Obnovte stránku a zkuste to znovu.', 400);
    }

    $firstName = trim((string) ($body['firstName'] ?? ''));
    $lastName = trim((string) ($body['lastName'] ?? ''));
    $email = trim((string) ($body['email'] ?? ''));
    $seats = $body['seats'] ?? null;

    $errors = [];
    if ($firstName === '' || mb_strlen($firstName) > 100) {
        $errors['firstName'] = 'Vyplňte jméno.';
    }
    if ($lastName === '' || mb_strlen($lastName) > 100) {
        $errors['lastName'] = 'Vyplňte příjmení.';
    }
    if (mb_strlen($email) > 190 || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errors['email'] = 'Zadejte platný e-mail.';
    }
    if (!is_array($seats) || $seats === []) {
        $errors['seats'] = 'Vyberte alespoň jedno místo.';
    } else {
        $seats = array_values(array_unique(array_map('strval', $seats)));
        $max = (int) config('MAX_SEATS_PER_RESERVATION');
        if (count($seats) > $max) {
            $errors['seats'] = 'Najednou lze rezervovat nejvýše ' . $max . ' ' . ($max === 1 ? 'místo' : ($max < 5 ? 'místa' : 'míst')) . '.';
        } elseif (array_filter($seats, fn ($id) => !is_valid_seat_id($id))) {
            $errors['seats'] = 'Neplatné místo.';
        }
    }
    if ($errors) {
        json_error($errors['seats'] ?? 'Zkontrolujte zadané údaje.', 422, ['fields' => $errors]);
    }
    // Counted only for well-formed requests, so a visitor's own typos don't use up the limit.
    if (!rate_limit('reserve|' . client_ip(), (int) config('RESERVATIONS_PER_IP_PER_HOUR'), 3600)) {
        json_error('Příliš mnoho rezervací z tohoto zařízení. Zkuste to prosím později.', 429);
    }

    expire_reservations();

    // Counted per run, so one person can book several runs at once.
    $pending = db()->prepare("SELECT COUNT(*) FROM reservations WHERE email = ? AND run_id = ? AND status = 'pending'");
    $pending->execute([$email, $run['id']]);
    if ((int) $pending->fetchColumn() >= (int) config('PENDING_RESERVATIONS_PER_EMAIL')) {
        json_error('Na tento e-mail už na toto představení čekají nezaplacené rezervace. Nejdříve je prosím uhraďte.', 429);
    }

    $pdo = db();
    $now = now_utc();
    // Due date: PAYMENT_DEADLINE_HOURS, but never after the start of the run.
    $expires = min($now->modify('+' . (int) config('PAYMENT_DEADLINE_HOURS') . ' hours'), run_starts($run));
    $amount = count($seats) * (int) config('SEAT_PRICE');
    $token = bin2hex(random_bytes(16));

    $pdo->beginTransaction();
    try {
        $placeholders = implode(',', array_fill(0, count($seats), '?'));
        $stmt = $pdo->prepare("SELECT seat_id FROM reservation_seats WHERE run_id = ? AND seat_id IN ($placeholders) FOR UPDATE");
        $stmt->execute([$run['id'], ...$seats]);
        $conflict = $stmt->fetchAll(PDO::FETCH_COLUMN);
        if ($conflict) {
            $pdo->rollBack();
            json_error('Některá místa už mezitím někdo rezervoval.', 409, ['conflict' => $conflict]);
        }

        $pdo->prepare(
            'INSERT INTO reservations
               (token, run_id, first_name, last_name, email, seats, seat_count, amount, created_at, expires_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        )->execute([
            $token, $run['id'], $firstName, $lastName, $email, implode(',', $seats), count($seats), $amount,
            db_time($now), db_time($expires),
        ]);
        $id = (int) $pdo->lastInsertId();
        $pdo->prepare('UPDATE reservations SET variable_symbol = ? WHERE id = ?')
            ->execute([generate_variable_symbol($pdo), $id]);

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
            json_error('Některá místa už mezitím někdo rezervoval.', 409, ['conflict' => []]);
        }
        throw $e;
    }

    $r = find_reservation_by_token($token);
    send_payment_email($r);
    json_response(reservation_payload($r), 201);
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
