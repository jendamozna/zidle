<?php
// User accounts with roles: Správce (admin – everything), Účetní (accountant – reservations
// and payments), Odbavovatel (scanner – checking tickets in); one account may have several.
// The first Správce is created once by api/deploy.php (DEPLOY_TOKEN, only while no Správce
// exists). A Správce invites everybody else by e-mail; the invitation link lets them set a
// password; the e-mail is the account and its login (no separate name). "Zapomněl jsem heslo"
// e-mails a short-lived link.
declare(strict_types=1);

const USER_ROLES = ['admin' => 'Správce', 'accountant' => 'Účetní', 'scanner' => 'Odbavovatel'];
const ADMIN_INVITE_DAYS = 7;
const PASSWORD_RESET_MINUTES = 60;
const ADMIN_PASSWORD_MIN = 10;
const DEPLOY_TOKEN_MIN = 32;
/** bcrypt hash of a throw-away password, verified for unknown e-mails. */
const ADMIN_DUMMY_HASH = '$2y$10$HGCUErDu2BaaEz6fNk5guOKYJzs4WKAtSEvvQkg8FjNoszGDbyPpW';

function token_hash(string $token): string
{
    return hash('sha256', $token);
}

/** Roles of an account row as a list (known roles only, in USER_ROLES order). */
function user_roles(array $user): array
{
    $roles = explode(',', (string) ($user['roles'] ?? ''));
    return array_values(array_filter(array_keys(USER_ROLES), static fn ($role) => in_array($role, $roles, true)));
}

/** Checked form values → "admin,scanner" (unknown roles dropped). */
function roles_csv(array $roles): string
{
    return implode(',', array_values(array_filter(array_keys(USER_ROLES), static fn ($role) => in_array($role, $roles, true))));
}

/**
 * What the signed-in user may do: 'manage' (plan, codes, users, settings – Správce),
 * 'reservations' (reservations and payments – Správce, Účetní), 'scan' (scanner – Správce, Odbavovatel).
 */
function user_can(array $me, string $what): bool
{
    $roles = $me['roles'];
    return in_array('admin', $roles, true) || match ($what) {
        'reservations' => in_array('accountant', $roles, true),
        'scan' => in_array('scanner', $roles, true),
        default => false,
    };
}

/** Any account (in any state) with the Správce role – then deploy.php is closed. */
function admin_exists(): bool
{
    return (int) db_query("SELECT COUNT(*) FROM admin_users WHERE FIND_IN_SET('admin', roles)")->fetchColumn() > 0;
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

/** Signed-in user as the app uses it: ['id', 'email', 'roles' => [...], 'pw' => password fingerprint]. */
function signed_in_user(array $user): array
{
    return [
        'id' => (int) $user['id'],
        'email' => $user['email'],
        'roles' => user_roles($user),
        'pw' => password_fingerprint((string) $user['password_hash']),
    ];
}

/** Changes with every new password, so sessions and scanner cookies of the old one stop working. */
function password_fingerprint(string $hash): string
{
    return substr(hash('sha256', 'pw|' . $hash), 0, 16);
}

/** Checks a login (e-mail + password of an active account). Returns signed_in_user() or null. */
function admin_login(string $email, string $password): ?array
{
    $user = admin_user_by_email($email);
    $hash = $user['password_hash'] ?? null;
    // Same work for unknown e-mails, so they can't be told apart by timing.
    $ok = password_verify($password, $hash ?? ADMIN_DUMMY_HASH);
    if ($user === null || $hash === null || !$ok || $user['disabled_at'] !== null) {
        return null;
    }
    if (password_needs_rehash($hash, PASSWORD_DEFAULT)) {
        $hash = password_hash($password, PASSWORD_DEFAULT);
        db()->prepare('UPDATE admin_users SET password_hash = ? WHERE id = ?')->execute([$hash, $user['id']]);
        $user['password_hash'] = $hash;
    }
    db()->prepare('UPDATE admin_users SET last_login_at = ? WHERE id = ?')->execute([db_time(now_utc()), $user['id']]);
    return signed_in_user($user);
}

/**
 * The user of this session (re-checked on every request: active, same password), or null.
 * Session: ['admin_id' => id, 'admin_pw' => password fingerprint].
 */
function admin_from_session(mixed $id, mixed $fingerprint): ?array
{
    if (!is_int($id) || !is_string($fingerprint)) {
        return null;
    }
    $user = admin_user($id);
    if ($user === null || $user['password_hash'] === null || $user['disabled_at'] !== null) {
        return null;
    }
    $me = signed_in_user($user);
    return hash_equals($me['pw'], $fingerprint) ? $me : null;
}

// ---------------------------------------------------------------- runs of an Odbavovatel

/** Runs the account may check in (user_runs), in start order; a Správce checks in every run regardless. */
function user_run_ids(int $id): array
{
    $stmt = db()->prepare('SELECT run_id FROM user_runs WHERE user_id = ?');
    $stmt->execute([$id]);
    $ids = array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
    return array_values(array_filter(array_keys(runs()), static fn ($runId) => in_array($runId, $ids, true)));
}

/** Sets the runs of the account (unknown runs ignored). */
function save_user_runs(int $id, array $runIds): void
{
    $runIds = array_values(array_unique(array_filter(array_map('intval', $runIds), static fn ($runId) => run_by_id($runId) !== null)));
    $pdo = db();
    $pdo->beginTransaction();
    $pdo->prepare('DELETE FROM user_runs WHERE user_id = ?')->execute([$id]);
    $insert = $pdo->prepare('INSERT INTO user_runs (user_id, run_id) VALUES (?, ?)');
    foreach ($runIds as $runId) {
        $insert->execute([$id, $runId]);
    }
    $pdo->commit();
}

/** E-mails of the accounts assigned to the run, sorted. */
function run_scanner_emails(int $runId): array
{
    $stmt = db()->prepare('SELECT u.email FROM user_runs ur JOIN admin_users u ON u.id = ur.user_id WHERE ur.run_id = ? AND u.disabled_at IS NULL ORDER BY u.email');
    $stmt->execute([$runId]);
    return $stmt->fetchAll(PDO::FETCH_COLUMN);
}

// ---------------------------------------------------------------- deploy

/** DEPLOY_TOKEN matches (and is long enough to be a real secret). */
function deploy_token_ok(string $given): bool
{
    $token = (string) config('DEPLOY_TOKEN');
    return strlen($token) >= DEPLOY_TOKEN_MIN && hash_equals($token, $given);
}

/**
 * Creates the first Správce (deploy.php). Only while no account has the Správce role.
 * Returns the new account row or an error message.
 */
function create_first_admin(string $email, string $password): array|string
{
    $email = mb_strtolower(trim($email));
    if (mb_strlen($email) > 190 || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        return 'Neplatný e-mail.';
    }
    if (mb_strlen($password) < ADMIN_PASSWORD_MIN) {
        return 'Heslo musí mít alespoň ' . ADMIN_PASSWORD_MIN . ' znaků.';
    }
    $pdo = db();
    // Serializes concurrent deploy calls.
    $pdo->query("SELECT GET_LOCK('zidle_deploy', 10)");
    try {
        if (admin_exists() || admin_user_by_email($email) !== null) {
            return 'Správce už existuje – deploy lze použít jen jednou.';
        }
        $now = db_time(now_utc());
        $pdo->prepare(
            "INSERT INTO admin_users (email, roles, password_hash, invited_by, created_at, accepted_at) VALUES (?, 'admin', ?, 'deploy', ?, ?)"
        )->execute([$email, password_hash($password, PASSWORD_DEFAULT), $now, $now]);
        $id = (int) $pdo->lastInsertId();
    } finally {
        $pdo->query("SELECT RELEASE_LOCK('zidle_deploy')");
    }
    return admin_user($id) ?? 'Účet se nepodařilo vytvořit.';
}

// ---------------------------------------------------------------- invitations

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
 * Invites a new user with the given roles and runs to check in (for an Odbavovatel).
 * Returns ['token' => string, 'user' => array] or an error message for the admin.
 */
function admin_invite(string $email, array $roles, string $invitedBy, array $runIds = []): array|string
{
    $email = mb_strtolower(trim($email));
    $roles = roles_csv($roles);
    if (mb_strlen($email) > 190 || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        return 'Neplatný e-mail.';
    }
    if ($roles === '') {
        return 'Vyberte alespoň jednu roli.';
    }
    if (admin_user_by_email($email) !== null) {
        return 'Účet s tímto e-mailem už existuje.';
    }
    db()->prepare('INSERT INTO admin_users (email, roles, invited_by, created_at) VALUES (?, ?, ?, ?)')
        ->execute([$email, $roles, mb_substr($invitedBy, 0, 200), db_time(now_utc())]);
    $id = (int) db()->lastInsertId();
    save_user_runs($id, $runIds);
    $token = admin_new_invite($id);
    return ['token' => $token, 'user' => admin_user($id)];
}

/** Account of a valid (not expired, not disabled) invitation token, or null. */
function admin_invited_user(string $token): ?array
{
    if (!preg_match('/^[a-f0-9]{48}\z/', $token)) {
        return null;
    }
    $stmt = db()->prepare('SELECT * FROM admin_users WHERE invite_hash = ? AND invite_expires_at > ? AND disabled_at IS NULL');
    $stmt->execute([token_hash($token), db_time(now_utc())]);
    return $stmt->fetch() ?: null;
}

/** Password rules for a new password; null when fine. */
function new_password_problem(string $password, string $again): ?string
{
    if (mb_strlen($password) < ADMIN_PASSWORD_MIN) {
        return 'Heslo musí mít alespoň ' . ADMIN_PASSWORD_MIN . ' znaků.';
    }
    return $password === $again ? null : 'Hesla se neshodují.';
}

/**
 * Sets the password from an invitation; the link stops working, the account exists from now on.
 * Returns the signed-in user (signed_in_user()) or an error message.
 */
function admin_accept_invite(string $token, string $password, string $again): array|string
{
    $user = admin_invited_user($token);
    if ($user === null) {
        return 'Pozvánka neplatí nebo vypršela. Požádejte o novou.';
    }
    if (($problem = new_password_problem($password, $again)) !== null) {
        return $problem;
    }
    $now = db_time(now_utc());
    $hash = password_hash($password, PASSWORD_DEFAULT);
    db()->prepare(
        'UPDATE admin_users SET password_hash = ?, invite_hash = NULL, invite_expires_at = NULL,
           accepted_at = COALESCE(accepted_at, ?), last_login_at = ? WHERE id = ?'
    )->execute([$hash, $now, $now, $user['id']]);
    return signed_in_user(['password_hash' => $hash] + $user);
}

/** Invitation e-mail; false when e-mails are off or sending failed. */
function send_admin_invite_email(array $user, string $token, string $invitedBy): bool
{
    if (!mail_enabled()) {
        return false;
    }
    $roles = implode(', ', array_map(static fn ($role) => USER_ROLES[$role], user_roles($user)));
    $text = implode("\n", array_merge([
        'Dobrý den,',
        '',
        "{$invitedBy} Vás zve do systému Těšín zpívá gospel – role: {$roles}.",
        '',
        'Účet vznikne, až si na tomto odkazu vytvoříte heslo (platí ' . ADMIN_INVITE_DAYS . ' dní):',
        admin_invite_link($token),
        '',
        "Přihlašovací jméno je Váš e-mail: {$user['email']}",
        '',
        'Těšín zpívá gospel',
    ], contact_line() !== '' ? [contact_line()] : []));
    return deliver_mail($user['email'], mail_subject('Pozvánka'), $text);
}

// ---------------------------------------------------------------- account changes

/** Správce changes the roles of an account; nobody removes their own Správce role. Returns a message. */
function admin_set_roles(int $id, array $roles, int $me): string
{
    $user = admin_user($id);
    $csv = roles_csv($roles);
    if ($user === null) {
        return 'Účet nenalezen.';
    }
    if ($csv === '') {
        return 'Vyberte alespoň jednu roli.';
    }
    if ($id === $me && !in_array('admin', explode(',', $csv), true)) {
        return 'Svou vlastní roli Správce nelze odebrat.';
    }
    db()->prepare('UPDATE admin_users SET roles = ? WHERE id = ?')->execute([$csv, $id]);
    return "Role a termíny účtu {$user['email']} uloženy.";
}

/** Disables (true) or enables an account; nobody can disable themselves. Returns a message. */
function admin_set_disabled(int $id, bool $disabled, int $me): string
{
    $user = admin_user($id);
    if ($user === null) {
        return 'Účet nenalezen.';
    }
    if ($disabled && $id === $me) {
        return 'Svůj vlastní účet nelze vypnout.';
    }
    db()->prepare('UPDATE admin_users SET disabled_at = ?, invite_hash = IF(? IS NULL, invite_hash, NULL), reset_hash = IF(? IS NULL, reset_hash, NULL) WHERE id = ?')
        ->execute([$disabled ? db_time(now_utc()) : null, $disabled ? 1 : null, $disabled ? 1 : null, $id]);
    return $disabled ? "Účet {$user['email']} vypnut – už se nepřihlásí." : "Účet {$user['email']} znovu zapnut.";
}

// ---------------------------------------------------------------- forgotten password

function password_reset_link(string $token): string
{
    return app_base_url() . '/api/admin.php?heslo=' . $token;
}

/**
 * "Zapomněl jsem heslo": for an active account with a password, e-mails a link to set a new
 * password (valid PASSWORD_RESET_MINUTES; the old password works until then). Says nothing
 * about whether the e-mail exists. Returns the token when one was issued (tests), else null.
 */
function request_password_reset(string $email): ?string
{
    $user = admin_user_by_email($email);
    if ($user === null || $user['password_hash'] === null || $user['disabled_at'] !== null
        || !rate_limit('reset|' . $user['id'], 3, 3600)) {
        return null;
    }
    $token = bin2hex(random_bytes(24));
    db()->prepare('UPDATE admin_users SET reset_hash = ?, reset_expires_at = ? WHERE id = ?')
        ->execute([token_hash($token), db_time(now_utc()->modify('+' . PASSWORD_RESET_MINUTES . ' minutes')), $user['id']]);
    if (mail_enabled()) {
        $text = implode("\n", array_merge([
            'Dobrý den,',
            '',
            'někdo (nejspíš Vy) požádal o nové heslo do systému Těšín zpívá gospel.',
            'Nové heslo si nastavíte na tomto odkazu (platí ' . PASSWORD_RESET_MINUTES . ' minut):',
            password_reset_link($token),
            '',
            'Pokud jste o nové heslo nežádali, e-mail ignorujte – dosavadní heslo platí dál.',
            '',
            'Těšín zpívá gospel',
        ], contact_line() !== '' ? [contact_line()] : []));
        deliver_mail($user['email'], mail_subject('Nové heslo'), $text);
    }
    return $token;
}

/** Account of a valid password reset token, or null. */
function password_reset_user(string $token): ?array
{
    if (!preg_match('/^[a-f0-9]{48}\z/', $token)) {
        return null;
    }
    $stmt = db()->prepare('SELECT * FROM admin_users WHERE reset_hash = ? AND reset_expires_at > ? AND disabled_at IS NULL');
    $stmt->execute([token_hash($token), db_time(now_utc())]);
    return $stmt->fetch() ?: null;
}

/** Sets the new password from a reset link (once). Returns the signed-in user or an error message. */
function reset_password(string $token, string $password, string $again): array|string
{
    $user = password_reset_user($token);
    if ($user === null) {
        return 'Odkaz neplatí nebo vypršel. Požádejte o nový.';
    }
    if (($problem = new_password_problem($password, $again)) !== null) {
        return $problem;
    }
    $hash = password_hash($password, PASSWORD_DEFAULT);
    db()->prepare('UPDATE admin_users SET password_hash = ?, reset_hash = NULL, reset_expires_at = NULL, last_login_at = ? WHERE id = ?')
        ->execute([$hash, db_time(now_utc()), $user['id']]);
    return signed_in_user(['password_hash' => $hash] + $user);
}
