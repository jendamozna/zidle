<?php
// POST api/ticket-names.php {token, names: {seat: name}} – customer puts names on the seat tickets
// ('' = back to the reservation name). Returns the reservation payload.
declare(strict_types=1);
require __DIR__ . '/lib/bootstrap.php';

run_api(function (): void {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        json_error('Metoda není povolena.', 405);
    }
    $body = read_json_body();
    $token = (string) ($body['token'] ?? '');
    $r = preg_match('/^[a-f0-9]{32}$/', $token) ? find_reservation_by_token($token) : null;
    if ($r === null) {
        json_error('Rezervace nenalezena.', 404);
    }
    if (!rate_limit('names|' . client_ip(), 60, 3600)) {
        json_error('Příliš mnoho pokusů. Zkuste to prosím později.', 429);
    }
    $error = save_ticket_names($r, is_array($body['names'] ?? null) ? $body['names'] : []);
    if ($error !== null) {
        json_error($error, 422);
    }
    $stmt = db()->prepare('SELECT * FROM reservations WHERE id = ?');
    $stmt->execute([$r['id']]);
    json_response(reservation_payload($stmt->fetch() ?: $r));
});
