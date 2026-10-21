<?php
// One-time setup: creates the first Správce (admin account).
//   POST deploy.php {token, email, password, name?}   (JSON or form fields)
//   → 201 {message}; 403 wrong/disabled token; 409 a Správce already exists; 422 invalid e-mail / short password
// token = DEPLOY_TOKEN from config.local.php (≥ 32 characters, '' = disabled). Works only while no
// account has the Správce role; everybody else is invited from the admin (tab Uživatelé).
declare(strict_types=1);
require __DIR__ . '/lib/bootstrap.php';

run_api(function (): void {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        json_error('Metoda není povolena.', 405);
    }
    if (!rate_limit('deploy|' . client_ip(), 10, 3600)) {
        json_error('Příliš mnoho pokusů. Zkuste to později.', 429);
    }
    $body = str_starts_with((string) ($_SERVER['CONTENT_TYPE'] ?? ''), 'application/json') ? read_json_body() : $_POST;
    $field = static fn (string $key): string => is_string($body[$key] ?? null) ? $body[$key] : '';
    if (!deploy_token_ok($field('token'))) {
        sleep(1);
        json_error('Neplatný token (DEPLOY_TOKEN v config.local.php, alespoň ' . DEPLOY_TOKEN_MIN . ' znaků).', 403);
    }
    if (admin_exists()) {
        json_error('Správce už existuje – deploy lze použít jen jednou. Další uživatele pozve správce.', 409);
    }
    $created = create_first_admin($field('email'), $field('name'), $field('password'));
    if (is_string($created)) {
        json_error($created, str_starts_with($created, 'Správce už') ? 409 : 422);
    }
    json_response([
        'message' => "Správce {$created['email']} vytvořen. Přihlaste se na " . app_base_url() . '/api/admin.php',
    ], 201);
});
