<?php
// POST api/reservations.php        – create a reservation
//      body: {runId, firstName, lastName, email, seats: ["ML-1-1", ...], children, codes: ["ABCD-EFGH"],
//             formToken, hp, altcha}
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
    // Invisible ALTCHA: the browser solved a proof-of-work challenge in the background.
    if (altcha_enabled() && !altcha_verify((string) ($body['altcha'] ?? ''))) {
        error_log('[zidle] Rejected reservation: ALTCHA failed from ' . client_ip());
        json_error('Ověření proti robotům se nezdařilo. Zkuste to prosím znovu.', 400, ['code' => 'altcha']);
    }

    $firstName = clean_name($body['firstName'] ?? '');
    $lastName = clean_name($body['lastName'] ?? '');
    $email = trim((string) ($body['email'] ?? ''));
    $seats = $body['seats'] ?? null;
    $children = $body['children'] ?? 0;
    $codes = $body['codes'] ?? [];

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
        $seats = [];
    } else {
        $seats = array_values(array_unique(array_map('strval', $seats)));
        $max = (int) config('MAX_SEATS_PER_RESERVATION');
        if (count($seats) > $max) {
            $errors['seats'] = 'Najednou lze rezervovat nejvýše ' . $max . ' ' . ($max === 1 ? 'místo' : ($max < 5 ? 'místa' : 'míst')) . '.';
        } elseif (array_filter($seats, fn ($id) => !is_valid_seat_id($id))) {
            $errors['seats'] = 'Neplatné místo.';
        } else {
            usort($seats, 'compare_seat_ids');
        }
    }
    if (!is_int($children) || $children < 0 || $children > count($seats)) {
        $errors['children'] = 'Dětských vstupenek může být nejvýše tolik, kolik je míst.';
    }
    $codes = is_array($codes) ? array_values(array_filter(array_map(static fn ($c) => normalize_discount_code((string) $c), $codes))) : null;
    if ($codes === null) {
        $errors['codes'] = 'Neplatné slevové kódy.';
    } elseif (count($codes) > count($seats)) {
        $errors['codes'] = 'Slevových kódů může být nejvýše tolik, kolik je míst.';
    } elseif (count($codes) !== count(array_unique($codes))) {
        $errors['codes'] = 'Každý slevový kód lze zadat jen jednou.';
    } elseif (($bad = array_find($codes, static fn ($code) => !preg_match('/^[' . DISCOUNT_ALPHABET . ']{8}$/', $code))) !== null) {
        $errors['codes'] = 'Slevový kód ' . $bad . ' neplatí nebo už byl použit.';
    }
    if ($errors) {
        json_error($errors['seats'] ?? $errors['codes'] ?? $errors['children'] ?? 'Zkontrolujte zadané údaje.', 422, ['fields' => $errors]);
    }
    // Seats per IP, not reservations: one script can't hold a whole run with many small ones.
    // Counted only for well-formed requests, so a visitor's own typos don't use up the limit.
    if (!seat_quota_take(count($seats))) {
        json_error('Z tohoto zařízení už bylo rezervováno příliš mnoho míst. Zkuste to prosím později.', 429);
    }

    expire_reservations();

    $booked = book_reservation($run, $firstName, $lastName, $email, $seats, (int) $children, (array) $codes);
    if (isset($booked['error'])) {
        seat_quota_release(count($seats));
        json_error($booked['error'], $booked['status'], $booked['extra']);
    }
    json_response(reservation_payload($booked['reservation']), 201);
}
