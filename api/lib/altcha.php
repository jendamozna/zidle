<?php
// Invisible ALTCHA (proof-of-work) for the reservation form.
// api/altcha.php issues a signed challenge (PBKDF2/SHA-256); the browser solves
// it in the background while the visitor fills the form and sends the base64
// payload as "altcha" with the reservation. Each solved challenge is accepted
// only once (replay protection via rate_limits). Keep in sync with src/altcha.js.
declare(strict_types=1);

use AltchaOrg\Altcha\Algorithm\Pbkdf2;
use AltchaOrg\Altcha\Altcha;
use AltchaOrg\Altcha\CreateChallengeOptions;
use AltchaOrg\Altcha\VerifySolutionOptions;

const ALTCHA_TTL_SECONDS = 900;

function altcha_enabled(): bool
{
    return filter_var(config('ALTCHA_ENABLED'), FILTER_VALIDATE_BOOLEAN);
}

function altcha(): Altcha
{
    require_once __DIR__ . '/../vendor/autoload.php';
    // Secrets derived from TICKET_SECRET, separate from the other HMAC uses.
    return new Altcha(
        hmacSignatureSecret: hash('sha256', 'altcha-signature|' . ticket_secret()),
        hmacKeySignatureSecret: hash('sha256', 'altcha-key|' . ticket_secret()),
    );
}

/** New challenge as an array for the widget/solver. */
function altcha_challenge(): array
{
    $max = max(10, (int) config('ALTCHA_COUNTER_MAX'));
    return altcha()->createChallenge(new CreateChallengeOptions(
        algorithm: new Pbkdf2(),
        cost: max(1, (int) config('ALTCHA_COST')),
        counter: random_int(intdiv($max, 3), $max),
        expiresAt: time() + ALTCHA_TTL_SECONDS,
    ))->toArray();
}

/** True when the base64 payload solves a valid, unexpired challenge that was not used before. */
function altcha_verify(string $payload): bool
{
    if ($payload === '' || strlen($payload) > 10000) {
        return false;
    }
    try {
        $result = altcha()->verifySolution(new VerifySolutionOptions(algorithm: new Pbkdf2(), payload: $payload));
    } catch (Throwable) {
        return false;
    }
    if (!$result->verified) {
        return false;
    }
    $data = json_decode((string) base64_decode($payload, true), true);
    $signature = (string) ($data['challenge']['signature'] ?? '');
    return $signature !== '' && rate_limit('altcha|' . $signature, 1, ALTCHA_TTL_SECONDS + 60);
}
