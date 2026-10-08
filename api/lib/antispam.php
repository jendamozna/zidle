<?php
// Spam and bot protection without third-party services:
//  - signed form token issued by seats.php, must be FORM_MIN_SECONDS old (bots posting directly or instantly)
//  - honeypot field "hp" that humans never see (not named like a real field, so browsers don't autofill it)
//  - rate limits per IP / e-mail stored in the rate_limits table
declare(strict_types=1);

const FORM_TOKEN_MAX_AGE = 12 * 3600;

function client_ip(): string
{
    return (string) ($_SERVER['REMOTE_ADDR'] ?? 'unknown');
}

function antispam_secret(): string
{
    return hash('sha256', 'form-token|' . ticket_secret());
}

function form_token(): string
{
    $time = (string) time();
    return $time . '.' . substr(hash_hmac('sha256', $time, antispam_secret()), 0, 32);
}

function form_token_valid(string $token): bool
{
    if (!preg_match('/^(\d{10})\.([a-f0-9]{32})$/', $token, $m)) {
        return false;
    }
    $expected = substr(hash_hmac('sha256', $m[1], antispam_secret()), 0, 32);
    $age = time() - (int) $m[1];
    return hash_equals($expected, $m[2])
        && $age >= (int) config('FORM_MIN_SECONDS')
        && $age <= FORM_TOKEN_MAX_AGE;
}

/**
 * Counts a hit for $key and returns false when more than $max hits happened
 * in the current window of $windowSeconds.
 */
function rate_limit(string $key, int $max, int $windowSeconds): bool
{
    $pdo = db();
    $bucket = hash('sha256', $key);
    $pdo->prepare(
        'INSERT INTO rate_limits (bucket, hits, window_start) VALUES (?, 1, UTC_TIMESTAMP())
         ON DUPLICATE KEY UPDATE
           hits = IF(window_start < UTC_TIMESTAMP() - INTERVAL ? SECOND, 1, hits + 1),
           window_start = IF(window_start < UTC_TIMESTAMP() - INTERVAL ? SECOND, UTC_TIMESTAMP(), window_start)'
    )->execute([$bucket, $windowSeconds, $windowSeconds]);
    $stmt = $pdo->prepare('SELECT hits FROM rate_limits WHERE bucket = ?');
    $stmt->execute([$bucket]);
    return (int) $stmt->fetchColumn() <= $max;
}

function login_allowed(string $area): bool
{
    return rate_limit("login|{$area}|" . client_ip(), (int) config('LOGIN_ATTEMPTS_PER_15_MIN'), 900);
}

function cleanup_rate_limits(): void
{
    db()->exec('DELETE FROM rate_limits WHERE window_start < UTC_TIMESTAMP() - INTERVAL 1 DAY');
}
