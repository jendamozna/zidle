<?php
// POST api/reservations.php        – create a reservation
//      body: {firstName, lastName, email, seats: ["ML-1-1", ...]}
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
            $errors['seats'] = "Najednou lze rezervovat nejvýše {$max} míst.";
        } elseif (array_filter($seats, fn ($id) => !is_valid_seat_id($id))) {
            $errors['seats'] = 'Neplatné místo.';
        }
    }
    if ($errors) {
        json_error('Zkontrolujte zadané údaje.', 422, ['fields' => $errors]);
    }

    expire_reservations();

    $pdo = db();
    $now = now_utc();
    $expires = $now->modify('+' . (int) config('PAYMENT_DEADLINE_HOURS') . ' hours');
    $amount = count($seats) * (int) config('SEAT_PRICE');
    $token = bin2hex(random_bytes(16));

    $pdo->beginTransaction();
    try {
        $placeholders = implode(',', array_fill(0, count($seats), '?'));
        $stmt = $pdo->prepare("SELECT seat_id FROM reservation_seats WHERE seat_id IN ($placeholders) FOR UPDATE");
        $stmt->execute($seats);
        $conflict = $stmt->fetchAll(PDO::FETCH_COLUMN);
        if ($conflict) {
            $pdo->rollBack();
            json_error('Některá místa už mezitím někdo rezervoval.', 409, ['conflict' => $conflict]);
        }

        $pdo->prepare(
            'INSERT INTO reservations
               (token, first_name, last_name, email, seats, seat_count, amount, created_at, expires_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
        )->execute([
            $token, $firstName, $lastName, $email, implode(',', $seats), count($seats), $amount,
            db_time($now), db_time($expires),
        ]);
        $id = (int) $pdo->lastInsertId();
        $vs = $now->format('y') . str_pad((string) $id, 8, '0', STR_PAD_LEFT);
        $pdo->prepare('UPDATE reservations SET variable_symbol = ? WHERE id = ?')->execute([$vs, $id]);

        $insertSeat = $pdo->prepare('INSERT INTO reservation_seats (seat_id, reservation_id) VALUES (?, ?)');
        foreach ($seats as $seatId) {
            $insertSeat->execute([$seatId, $id]);
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
