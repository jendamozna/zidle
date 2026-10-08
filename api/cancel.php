<?php
// POST api/cancel.php {token, refundAccount?} – customer cancels their reservation.
declare(strict_types=1);
require __DIR__ . '/lib/bootstrap.php';

run_api(function (): void {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        json_error('Metoda není povolena.', 405);
    }
    $body = read_json_body();
    $token = (string) ($body['token'] ?? '');
    if (!preg_match('/^[a-f0-9]{32}$/', $token)) {
        json_error('Rezervace nenalezena.', 404);
    }
    if (!rate_limit('cancel|' . client_ip(), 20, 3600)) {
        json_error('Příliš mnoho pokusů. Zkuste to prosím později.', 429);
    }
    expire_reservations();
    try {
        $r = cancel_by_customer($token, (string) ($body['refundAccount'] ?? ''));
    } catch (InvalidArgumentException $e) {
        json_error($e->getMessage(), 422);
    }
    json_response(reservation_payload($r));
});
