<?php
// Access of organizers to the ticket scanner (scanner.html).
//  - Invitation: admin creates an invite for chosen runs; its link
//    (scanner.html?pozvanka=<token>) signs the device in for those runs only.
//    Revoking the invite locks the device out on its next request.
//  - Master password ORGANIZER_PASSWORD (optional): all runs.
// The device keeps a cookie (no PHP session, so it survives idle hours):
//   "i:<invite token>"  or  "m:<expiry>:<hmac>"
declare(strict_types=1);

const SCANNER_COOKIE = 'zidle_scanner';
const SCANNER_COOKIE_DAYS = 30;
const MASTER_NAME = 'hlavní heslo';

function scanner_cookie_path(): string
{
    return rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/api/x.php')), '/') . '/';
}

function scanner_set_cookie(string $value): void
{
    setcookie(SCANNER_COOKIE, $value, [
        'expires' => time() + SCANNER_COOKIE_DAYS * 86400,
        'path' => scanner_cookie_path(),
        'secure' => !empty($_SERVER['HTTPS']),
        'httponly' => true,
        'samesite' => 'Strict',
    ]);
}

function scanner_clear_cookie(): void
{
    setcookie(SCANNER_COOKIE, '', ['expires' => 1, 'path' => scanner_cookie_path(), 'httponly' => true, 'samesite' => 'Strict']);
}

function master_cookie(): string
{
    $expiry = (string) (time() + SCANNER_COOKIE_DAYS * 86400);
    return 'm:' . $expiry . ':' . hash_hmac('sha256', 'master|' . $expiry, antispam_secret() . (string) config('ORGANIZER_PASSWORD'));
}

function token_hash(string $token): string
{
    return hash('sha256', $token);
}

/**
 * Current organizer, or null when not signed in / revoked.
 * ['type' => 'invite'|'master', 'id' => ?int, 'name' => string, 'runIds' => int[]|null (null = all)]
 */
function scanner_access(): ?array
{
    static $access = false;
    if ($access !== false) {
        return $access;
    }
    $access = null;
    $cookie = (string) ($_COOKIE[SCANNER_COOKIE] ?? '');

    if (preg_match('/^i:([a-f0-9]{48})$/', $cookie, $m)) {
        $stmt = db()->prepare('SELECT * FROM scanner_invites WHERE token_hash = ? AND revoked_at IS NULL');
        $stmt->execute([token_hash($m[1])]);
        $invite = $stmt->fetch();
        if ($invite) {
            db()->prepare('UPDATE scanner_invites SET last_used_at = ? WHERE id = ?')->execute([db_time(now_utc()), $invite['id']]);
            $access = [
                'type' => 'invite',
                'id' => (int) $invite['id'],
                'name' => $invite['name'],
                'runIds' => invite_run_ids((int) $invite['id']),
            ];
        }
    } elseif (preg_match('/^m:(\d{10}):([a-f0-9]{64})$/', $cookie, $m) && (string) config('ORGANIZER_PASSWORD') !== '') {
        $expected = hash_hmac('sha256', 'master|' . $m[1], antispam_secret() . (string) config('ORGANIZER_PASSWORD'));
        if ((int) $m[1] > time() && hash_equals($expected, $m[2])) {
            $access = ['type' => 'master', 'id' => null, 'name' => MASTER_NAME, 'runIds' => null];
        }
    }
    return $access;
}

function scanner_can_use_run(array $access, int $runId): bool
{
    return run_by_id($runId) !== null && ($access['runIds'] === null || in_array($runId, $access['runIds'], true));
}

/** Runs the organizer may check in, in start order. */
function scanner_runs(array $access): array
{
    return array_values(array_filter(runs(), static fn ($run) => scanner_can_use_run($access, $run['id'])));
}

function invite_run_ids(int $inviteId): array
{
    $stmt = db()->prepare('SELECT run_id FROM scanner_invite_runs WHERE invite_id = ?');
    $stmt->execute([$inviteId]);
    return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
}

/** Accepts an invite token: stores the device cookie. Returns false for unknown / revoked invites. */
function accept_invite(string $token): bool
{
    if (!preg_match('/^[a-f0-9]{48}$/', $token)) {
        return false;
    }
    $stmt = db()->prepare('SELECT id FROM scanner_invites WHERE token_hash = ? AND revoked_at IS NULL');
    $stmt->execute([token_hash($token)]);
    if (!$stmt->fetchColumn()) {
        return false;
    }
    scanner_set_cookie('i:' . $token);
    return true;
}

/** Creates (id = 0) or updates an invite; returns the new token when one was issued. */
function save_invite(int $id, string $name, array $runIds, bool $newToken): ?string
{
    $pdo = db();
    $runIds = array_values(array_unique(array_filter(array_map('intval', $runIds), static fn ($r) => run_by_id($r) !== null)));
    $token = null;
    $pdo->beginTransaction();
    if ($id === 0) {
        $token = bin2hex(random_bytes(24));
        $pdo->prepare('INSERT INTO scanner_invites (name, token_hash, created_at) VALUES (?, ?, ?)')
            ->execute([$name, token_hash($token), db_time(now_utc())]);
        $id = (int) $pdo->lastInsertId();
    } else {
        $pdo->prepare('UPDATE scanner_invites SET name = ? WHERE id = ?')->execute([$name, $id]);
        if ($newToken) {
            $token = bin2hex(random_bytes(24));
            // A new link replaces the old one: devices signed in with the old link are signed out.
            $pdo->prepare('UPDATE scanner_invites SET token_hash = ?, revoked_at = NULL WHERE id = ?')->execute([token_hash($token), $id]);
        }
    }
    $pdo->prepare('DELETE FROM scanner_invite_runs WHERE invite_id = ?')->execute([$id]);
    $insert = $pdo->prepare('INSERT INTO scanner_invite_runs (invite_id, run_id) VALUES (?, ?)');
    foreach ($runIds as $runId) {
        $insert->execute([$id, $runId]);
    }
    $pdo->commit();
    return $token;
}

/** Base URL of the app (PUBLIC_URL, or derived from the current request). */
function app_base_url(): string
{
    $configured = rtrim((string) config('PUBLIC_URL'), '/');
    if ($configured !== '') {
        return $configured;
    }
    $scheme = !empty($_SERVER['HTTPS']) ? 'https' : 'http';
    $dir = rtrim(dirname(rtrim(scanner_cookie_path(), '/')), '/');
    return $scheme . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost') . $dir;
}

function invite_link(string $token): string
{
    return app_base_url() . '/scanner.html?pozvanka=' . $token;
}
