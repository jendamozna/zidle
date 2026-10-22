<?php
// Access to the ticket scanner (scanner.html): accounts with the role Odbavovatel or Správce
// (admin_users.php) sign in with their e-mail and password – on the scanner's login screen, or
// in the admin (the scanner then takes over the admin session). An Odbavovatel checks in only
// the runs assigned to the account (user_runs, 0..n); a Správce every run.
// The device keeps a signed cookie (no PHP session, so it survives idle hours and offline use):
//   "u:<account id>:<expiry>:<hmac>" – the hmac covers the password fingerprint, so a new
//   password, a disabled account or a removed role locks the device out on its next request.
declare(strict_types=1);

const SCANNER_COOKIE = 'zidle_scanner';
const SCANNER_COOKIE_DAYS = 30;

function scanner_cookie_path(): string
{
    return rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/api/x.php')), '/') . '/';
}

function scanner_set_cookie(string $value): void
{
    if (headers_sent()) {
        return; // CLI tests
    }
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
    if (headers_sent()) {
        return;
    }
    setcookie(SCANNER_COOKIE, '', ['expires' => 1, 'path' => scanner_cookie_path(), 'httponly' => true, 'samesite' => 'Strict']);
}

function scanner_cookie_mac(int $id, int $expiry, string $fingerprint): string
{
    return hash_hmac('sha256', "scanner|{$id}|{$expiry}|{$fingerprint}", antispam_secret());
}

/** Cookie value for a signed-in user (signed_in_user()). */
function scanner_cookie_for(array $me): string
{
    $expiry = time() + SCANNER_COOKIE_DAYS * 86400;
    return "u:{$me['id']}:{$expiry}:" . scanner_cookie_mac($me['id'], $expiry, $me['pw']);
}

/**
 * Scanner access of a signed-in user: ['id', 'name' => e-mail (recorded with check-ins and sales), 'roles',
 * 'runIds' => the assigned runs (user_runs, may be none), null for a Správce (every run)].
 */
function scanner_access_of(array $me): array
{
    return [
        'id' => $me['id'],
        'name' => $me['email'],
        'roles' => $me['roles'],
        'runIds' => in_array('admin', $me['roles'], true) ? null : user_run_ids($me['id']),
    ];
}

/** Current scanner user (cookie of this request), or null. */
function scanner_access(): ?array
{
    static $access = false;
    if ($access === false) {
        $access = scanner_access_from_cookie((string) ($_COOKIE[SCANNER_COOKIE] ?? ''));
    }
    return $access;
}

/** Scanner user of a cookie value, or null when invalid, expired, disabled, without the role or with an old password. */
function scanner_access_from_cookie(string $cookie): ?array
{
    if (!preg_match('/^u:(\d{1,10}):(\d{10}):([a-f0-9]{64})\z/', $cookie, $m) || (int) $m[2] <= time()) {
        return null;
    }
    $user = admin_user((int) $m[1]);
    if ($user === null || $user['password_hash'] === null || $user['disabled_at'] !== null) {
        return null;
    }
    $me = signed_in_user($user);
    return user_can($me, 'scan') && hash_equals(scanner_cookie_mac($me['id'], (int) $m[2], $me['pw']), $m[3])
        ? scanner_access_of($me)
        : null;
}

/** Signs the device in with e-mail + password. Returns the access, or an error message. */
function scanner_login(string $email, string $password): array|string
{
    $me = admin_login($email, $password);
    if ($me === null) {
        return 'Nesprávný e-mail nebo heslo.';
    }
    if (!user_can($me, 'scan')) {
        return 'Tento účet nemá roli Odbavovatel. Požádejte správce o přidání role.';
    }
    scanner_set_cookie(scanner_cookie_for($me));
    return scanner_access_of($me);
}

/**
 * A user signed in to the admin opens the scanner: the admin session (read only) signs the
 * device in too, when the account may scan. Returns the access or null.
 */
function scanner_access_from_admin_session(): ?array
{
    if (!isset($_COOKIE[session_name()]) || session_status() !== PHP_SESSION_NONE) {
        return null;
    }
    session_start(['read_and_close' => true]);
    $me = admin_from_session($_SESSION['admin_id'] ?? null, $_SESSION['admin_pw'] ?? null);
    if ($me === null || !user_can($me, 'scan')) {
        return null;
    }
    scanner_set_cookie(scanner_cookie_for($me));
    return scanner_access_of($me);
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
