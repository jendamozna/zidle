<?php
// GET api/altcha.php – new invisible ALTCHA challenge for the reservation form.
declare(strict_types=1);
require __DIR__ . '/lib/bootstrap.php';

run_api(function (): void {
    if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
        json_error('Metoda není povolena.', 405);
    }
    if (!altcha_enabled()) {
        json_response(['enabled' => false]);
    }
    if (!rate_limit('altcha-challenge|' . client_ip(), (int) config('ALTCHA_CHALLENGES_PER_IP_PER_HOUR'), 3600)) {
        json_error('Příliš mnoho pokusů. Zkuste to prosím později.', 429);
    }
    json_response(['enabled' => true, 'challenge' => altcha_challenge()]);
});
