<?php
// Admin accounts (accountants). An admin invites a colleague by e-mail; the
// invitation link lets them set a password, the e-mail is their login.
// ADMIN_PASSWORD (optional once accounts exist) signs in as the master admin
// with an empty e-mail. A new link for an existing account lets its owner set
// a new password (forgotten password).
declare(strict_types=1);

const ADMIN_INVITE_DAYS = 7;
const ADMIN_PASSWORD_MIN = 10;
const ADMIN_MASTER_NAME = 'hlavní heslo';
/** bcrypt hash of a throw-away password, verified for unknown e-mails. */
const ADMIN_DUMMY_HASH = '$2y$10$HGCUErDu2BaaEz6fNk5guOKYJzs4WKAtSEvvQkg8FjNoszGDbyPpW';

function admin_master_enabled(): bool
{
    return (string) config('ADMIN_PASSWORD') !== '';
}

/** True when nobody could sign in: no master password and no account with a password. */
function admin_login_impossible(): bool
{
    return !admin_master_enabled()
        && (int) db_query('SELECT COUNT(*) FROM admin_users WHERE password_hash IS NOT NULL AND disabled_at IS NULL')->fetchColumn() === 0;
}

function admin_user(int $id): ?array
{
    $stmt = db()->prepare('SELECT * FROM admin_users WHERE id = ?');
    $stmt->execute([$id]);
    return $stmt->fetch() ?: null;
}

function admin_user_by_email(string $email): ?array
{
    $stmt = db()->prepare('SELECT * FROM admin_users WHERE email = ?');
    $stmt->execute([mb_strtolower(trim($email))]);
    return $stmt->fetch() ?: null;
}

/**
 * Checks a login. Empty e-mail = master password. Returns the signed-in admin
 * ['id' => ?int (null = master), 'name' => string, 'email' => ?string] or null.
 */
function admin_login(string $email, string $password): ?array
{
    $email = trim($email);
    if ($email === '') {
        return admin_master_enabled() && hash_equals((string) config('ADMIN_PASSWORD'), $password)
            ? ['id' => null, 'name' => ADMIN_MASTER_NAME, 'email' => null]
            : null;
    }
    $user = admin_user_by_email($email);
    $hash = $user['password_hash'] ?? null;
    // Same work for unknown e-mails, so they can't be told apart by timing.
    $ok = password_verify($password, $hash ?? ADMIN_DUMMY_HASH);
    if ($user === null || $hash === null || !$ok || $user['disabled_at'] !== null) {
        return null;
    }
    $update = password_needs_rehash($hash, PASSWORD_DEFAULT)
        ? db()->prepare('UPDATE admin_users SET last_login_at = ?, password_hash = ? WHERE id = ?')
        : db()->prepare('UPDATE admin_users SET last_login_at = ? WHERE id = ?');
    $update->execute(password_needs_rehash($hash, PASSWORD_DEFAULT)
        ? [db_time(now_utc()), password_hash($password, PASSWORD_DEFAULT), $user['id']]
        : [db_time(now_utc()), $user['id']]);
    return ['id' => (int) $user['id'], 'name' => $user['name'], 'email' => $user['email']];
}

/** The admin of this session (re-checked on every request), or null. Session value: user id, 0 = master. */
function admin_from_session(mixed $value): ?array
{
    if ($value === 0) {
        return admin_master_enabled() ? ['id' => null, 'name' => ADMIN_MASTER_NAME, 'email' => null] : null;
    }
    if (!is_int($value)) {
        return null;
    }
    $user = admin_user($value);
    if ($user === null || $user['password_hash'] === null || $user['disabled_at'] !== null) {
        return null;
    }
    return ['id' => (int) $user['id'], 'name' => $user['name'], 'email' => $user['email']];
}

function admin_invite_link(string $token): string
{
    return app_base_url() . '/api/admin.php?pozvanka=' . $token;
}

/** Issues a new invitation token for the account (valid ADMIN_INVITE_DAYS); returns it. */
function admin_new_invite(int $id): string
{
    $token = bin2hex(random_bytes(24));
    db()->prepare('UPDATE admin_users SET invite_hash = ?, invite_expires_at = ? WHERE id = ?')
        ->execute([token_hash($token), db_time(now_utc()->modify('+' . ADMIN_INVITE_DAYS . ' days')), $id]);
    return $token;
}

/**
 * Invites a new accountant. Returns ['token' => string, 'user' => array] or an
 * error message for the admin.
 */
function admin_invite(string $email, string $name, string $invitedBy): array|string
{
    $email = mb_strtolower(trim($email));
    $name = trim(preg_replace('/\s+/u', ' ', $name) ?? '');
    if ($name === '' || mb_strlen($name) > 100) {
        return 'Vyplňte jméno.';
    }
    if (mb_strlen($email) > 190 || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        return 'Neplatný e-mail.';
    }
    if (admin_user_by_email($email) !== null) {
        return 'Účet s tímto e-mailem už existuje. Pošlete mu nový odkaz v seznamu.';
    }
    db()->prepare('INSERT INTO admin_users (email, name, invited_by, created_at) VALUES (?, ?, ?, ?)')
        ->execute([$email, $name, mb_substr($invitedBy, 0, 100), db_time(now_utc())]);
    $id = (int) db()->lastInsertId();
    $token = admin_new_invite($id);
    return ['token' => $token, 'user' => admin_user($id)];
}

/** Account of a valid (not expired, not disabled) invitation token, or null. */
function admin_invited_user(string $token): ?array
{
    if (!preg_match('/^[a-f0-9]{48}$/', $token)) {
        return null;
    }
    $stmt = db()->prepare('SELECT * FROM admin_users WHERE invite_hash = ? AND invite_expires_at > ? AND disabled_at IS NULL');
    $stmt->execute([token_hash($token), db_time(now_utc())]);
    return $stmt->fetch() ?: null;
}

/**
 * Sets the password from an invitation; the link stops working. Returns the
 * signed-in admin (as admin_login()) or an error message.
 */
function admin_accept_invite(string $token, string $password, string $again): array|string
{
    $user = admin_invited_user($token);
    if ($user === null) {
        return 'Pozvánka neplatí nebo vypršela. Požádejte o novou.';
    }
    if (mb_strlen($password) < ADMIN_PASSWORD_MIN) {
        return 'Heslo musí mít alespoň ' . ADMIN_PASSWORD_MIN . ' znaků.';
    }
    if ($password !== $again) {
        return 'Hesla se neshodují.';
    }
    $now = db_time(now_utc());
    db()->prepare(
        'UPDATE admin_users SET password_hash = ?, invite_hash = NULL, invite_expires_at = NULL,
           accepted_at = COALESCE(accepted_at, ?), last_login_at = ? WHERE id = ?'
    )->execute([password_hash($password, PASSWORD_DEFAULT), $now, $now, $user['id']]);
    return ['id' => (int) $user['id'], 'name' => $user['name'], 'email' => $user['email']];
}

/** Disables (true) or enables an account; nobody can disable themselves. Returns a message. */
function admin_set_disabled(int $id, bool $disabled, ?int $me): string
{
    $user = admin_user($id);
    if ($user === null) {
        return 'Účet nenalezen.';
    }
    if ($disabled && $id === $me) {
        return 'Svůj vlastní účet nelze vypnout.';
    }
    db()->prepare('UPDATE admin_users SET disabled_at = ?, invite_hash = IF(? IS NULL, invite_hash, NULL) WHERE id = ?')
        ->execute([$disabled ? db_time(now_utc()) : null, $disabled ? 1 : null, $id]);
    return $disabled ? "Účet {$user['email']} vypnut – už se nepřihlásí." : "Účet {$user['email']} znovu zapnut.";
}

/** Invitation e-mail; false when e-mails are off or sending failed. */
function send_admin_invite_email(array $user, string $token, string $invitedBy): bool
{
    if (!mail_enabled()) {
        return false;
    }
    $who = $invitedBy === ADMIN_MASTER_NAME ? 'Správce rezervací' : $invitedBy;
    $text = implode("\n", array_merge([
        "Dobrý den, {$user['name']},",
        '',
        "{$who} Vás zve ke správě rezervací Moje židle 2026 (potvrzování plateb, vracení peněz, VIP hosté).",
        '',
        'Heslo si vytvoříte na tomto odkazu (platí ' . ADMIN_INVITE_DAYS . ' dní):',
        admin_invite_link($token),
        '',
        "Přihlašovací jméno je Váš e-mail: {$user['email']}",
        '',
        'Moje židle 2026',
    ], contact_line() !== '' ? [contact_line()] : []));
    return deliver_mail($user['email'], 'Pozvánka do správy rezervací – Moje židle 2026', $text);
}
