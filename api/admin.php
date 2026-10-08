<?php
// Admin page: list reservations, confirm received payments, cancel reservations,
// manage VIP guests (free entry, checked by name at the entrance), scanner
// invites, runs and admin accounts (accountants invited by e-mail).
// Sign-in: e-mail + password of an account, or the master ADMIN_PASSWORD with an empty e-mail.
declare(strict_types=1);
require __DIR__ . '/lib/bootstrap.php';

session_set_cookie_params(['httponly' => true, 'samesite' => 'Strict', 'secure' => !empty($_SERVER['HTTPS'])]);
session_start();
header('Content-Type: text/html; charset=utf-8');
header('X-Frame-Options: DENY');

$h = static fn ($v) => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');

if (admin_login_impossible()) {
    http_response_code(503);
    exit('Nastavte ADMIN_PASSWORD v config.local.php.');
}

if (empty($_SESSION['csrf'])) {
    $_SESSION['csrf'] = bin2hex(random_bytes(16));
}
$csrfOk = hash_equals($_SESSION['csrf'], (string) ($_POST['csrf'] ?? ''));
$action = $_POST['action'] ?? null;

if ($action === 'login' && $csrfOk) {
    $loginEmail = trim((string) ($_POST['email'] ?? ''));
    if (!login_allowed('admin')) {
        $loginError = 'Příliš mnoho pokusů. Zkuste to za 15 minut.';
    } elseif ($signedIn = admin_login($loginEmail, (string) ($_POST['password'] ?? ''))) {
        session_regenerate_id(true);
        $_SESSION['admin_id'] = $signedIn['id'] ?? 0;
    } else {
        sleep(1);
        $loginError = 'Nesprávný e-mail nebo heslo.';
    }
}

// Invitation link admin.php?pozvanka=<token>: the invitee sets a password and is signed in.
$inviteToken = (string) ($_GET['pozvanka'] ?? '');
if ($inviteToken !== '') {
    $inviteError = null;
    if ($action === 'accept-invite' && $csrfOk) {
        $accepted = login_allowed('admin-invite')
            ? admin_accept_invite($inviteToken, (string) ($_POST['password'] ?? ''), (string) ($_POST['password_again'] ?? ''))
            : 'Příliš mnoho pokusů. Zkuste to za 15 minut.';
        if (is_array($accepted)) {
            session_regenerate_id(true);
            $_SESSION['admin_id'] = $accepted['id'];
            $_SESSION['flash'] = "Vítejte, {$accepted['name']}. Heslo je nastavené, příště se přihlásíte e-mailem {$accepted['email']}.";
            header('Location: admin.php');
            exit;
        }
        $inviteError = $accepted;
    }
    $invited = admin_invited_user($inviteToken);
}

$me = admin_from_session($_SESSION['admin_id'] ?? null);
if ($action === 'logout' && $csrfOk) {
    $_SESSION = [];
    session_destroy();
    header('Location: admin.php');
    exit;
}

$flash = null;
$view = in_array($_GET['view'] ?? '', ['vip', 'scanners', 'users', 'settings'], true) ? $_GET['view'] : 'reservations';

if ($me !== null && $csrfOk && in_array($action, ['user-invite', 'user-link', 'user-disable', 'user-enable'], true)) {
    $id = (int) ($_POST['id'] ?? 0);
    if ($action === 'user-invite' || $action === 'user-link') {
        if ($action === 'user-invite') {
            $result = admin_invite((string) ($_POST['email'] ?? ''), (string) ($_POST['name'] ?? ''), $me['name']);
        } else {
            $user = admin_user($id);
            $result = $user === null ? 'Účet nenalezen.' : ($user['disabled_at'] !== null ? 'Účet je vypnutý.' : ['token' => admin_new_invite($id), 'user' => $user]);
        }
        if (is_string($result)) {
            $_SESSION['flash'] = $result;
        } else {
            $user = $result['user'] ?? [];
            $mailed = send_admin_invite_email($user, $result['token'], $me['name']);
            $_SESSION['flash'] = ($action === 'user-invite' ? "Pozvánka pro {$user['email']} vytvořena." : "Nový odkaz pro {$user['email']} vytvořen, původní přestal platit.")
                . ($mailed ? ' E-mail odeslán.' : ' E-mail se neodeslal – předejte odkaz sami.');
            $_SESSION['user_link'] = ['email' => $user['email'], 'link' => admin_invite_link($result['token'])];
        }
    } else {
        $_SESSION['flash'] = admin_set_disabled($id, $action === 'user-disable', $me['id']);
    }
    header('Location: admin.php?view=users');
    exit;
}

if ($me !== null && $csrfOk && in_array($action, ['invite-save', 'invite-revoke'], true)) {
    $id = (int) ($_POST['id'] ?? 0);
    if ($action === 'invite-revoke') {
        db()->prepare('UPDATE scanner_invites SET revoked_at = ? WHERE id = ? AND revoked_at IS NULL')->execute([db_time(now_utc()), $id]);
        $_SESSION['flash'] = 'Přístup zrušen – zařízení s touto pozvánkou už nemohou odbavovat.';
    } else {
        $name = trim((string) ($_POST['name'] ?? ''));
        $runIds = (array) ($_POST['runs'] ?? []);
        if ($name === '' || mb_strlen($name) > 100 || !$runIds) {
            $_SESSION['flash'] = 'Vyplňte jméno a vyberte alespoň jeden termín.';
        } else {
            $token = save_invite($id, $name, $runIds, !empty($_POST['new_link']));
            $_SESSION['flash'] = $id === 0 ? "Pozvánka pro {$name} vytvořena." : ($token ? 'Vytvořen nový odkaz, původní přestal platit.' : 'Pozvánka uložena.');
            if ($token) {
                $_SESSION['invite_link'] = ['name' => $name, 'link' => invite_link($token)];
            }
        }
    }
    header('Location: admin.php?view=scanners');
    exit;
}

if ($me !== null && $csrfOk && in_array($action, ['run-save', 'run-delete'], true)) {
    $id = (int) ($_POST['id'] ?? 0);
    if ($action === 'run-delete') {
        $used = db()->prepare('SELECT (SELECT COUNT(*) FROM reservations WHERE run_id = ?) + (SELECT COUNT(*) FROM vip_guests WHERE run_id = ?)');
        $used->execute([$id, $id]);
        if ((int) $used->fetchColumn() > 0) {
            $_SESSION['flash'] = 'Termín má rezervace nebo VIP hosty, nelze ho smazat.';
        } else {
            db()->prepare('DELETE FROM runs WHERE id = ?')->execute([$id]);
            db()->prepare('DELETE FROM scanner_invite_runs WHERE run_id = ?')->execute([$id]);
            db()->prepare('DELETE FROM scan_conflicts WHERE run_id = ?')->execute([$id]);
            $_SESSION['flash'] = 'Termín smazán.';
        }
    } else {
        // Once a run has reservations, its start and storno rules are fixed:
        // customers booked under them. Only the label and the booking cut-off change.
        $existing = $id > 0 ? run_by_id($id) : null;
        $locked = $existing !== null && run_has_reservations($id);
        $label = trim((string) ($_POST['label'] ?? ''));
        $starts = $locked ? run_starts($existing) : prague_time((string) ($_POST['starts_at'] ?? ''));
        $closesRaw = trim((string) ($_POST['booking_closes_at'] ?? ''));
        $closes = $closesRaw === '' ? null : prague_time($closesRaw);
        $rules = [];
        $errors = [];
        if ($starts === null) {
            $errors[] = 'Zadejte datum a čas začátku.';
        }
        if ($closesRaw !== '' && ($closes === null || ($starts && $closes > $starts))) {
            $errors[] = 'Konec rezervací musí být platné datum před začátkem.';
        }
        if (mb_strlen($label) > 100) {
            $errors[] = 'Název je příliš dlouhý.';
        }
        if ($locked && (isset($_POST['starts_at']) || isset($_POST['rule_from']))) {
            $errors[] = 'Představení už má rezervace – začátek a storno podmínky nelze měnit.';
        }
        foreach ($locked ? [] : (array) ($_POST['rule_from'] ?? []) as $i => $from) {
            $from = trim((string) $from);
            $percent = trim((string) ($_POST['rule_percent'][$i] ?? ''));
            if ($from === '' && $percent === '') {
                continue;
            }
            if (prague_time($from) === null || !ctype_digit($percent) || (int) $percent > 100) {
                $errors[] = 'Storno pravidlo ' . ($i + 1) . ': zadejte datum a procento 0–100.';
                continue;
            }
            $rules[] = ['from' => iso_utc(prague_time($from)), 'percent' => (int) $percent]; // stored in UTC
        }
        if ($errors || $starts === null) {
            $_SESSION['flash'] = implode(' ', $errors);
        } else {
            $rulesJson = $locked ? json_encode($existing['storno_rules']) : json_encode(normalize_storno_rules($rules), JSON_UNESCAPED_UNICODE);
            $values = [$label, db_time($starts), $closes ? db_time($closes) : null, $rulesJson];
            if ($id > 0) {
                db()->prepare('UPDATE runs SET label = ?, starts_at = ?, booking_closes_at = ?, storno_rules = ? WHERE id = ?')
                    ->execute([...$values, $id]);
            } else {
                db()->prepare('INSERT INTO runs (label, starts_at, booking_closes_at, storno_rules) VALUES (?, ?, ?, ?)')
                    ->execute($values);
            }
            $_SESSION['flash'] = $id > 0 ? 'Termín uložen.' : 'Termín přidán.';
        }
    }
    header('Location: admin.php?view=settings');
    exit;
}

if ($me !== null && $csrfOk && in_array($action, ['vip-add', 'vip-delete', 'vip-reset'], true)) {
    $id = (int) ($_POST['id'] ?? 0);
    if ($action === 'vip-add') {
        $flash = add_vip_guest(
            (int) ($_POST['run_id'] ?? 0),
            trim(preg_replace('/\s+/u', ' ', (string) ($_POST['name'] ?? '')) ?? ''),
            array_values(array_unique(array_map('strval', (array) ($_POST['seats'] ?? [])))),
            trim((string) ($_POST['note'] ?? ''))
        );
    } elseif ($action === 'vip-delete') {
        db()->prepare('DELETE FROM vip_guests WHERE id = ?')->execute([$id]);
        $flash = 'VIP host odstraněn, jeho místa jsou volná.';
    } else {
        db()->prepare('UPDATE vip_guests SET checked_in_at = NULL WHERE id = ?')->execute([$id]);
        $flash = 'Příchod zrušen.';
    }
    $_SESSION['flash'] = $flash;
    header('Location: admin.php?' . http_build_query(['view' => 'vip', 'run' => $_GET['run'] ?? '']));
    exit;
}

if ($me !== null && $csrfOk && in_array($action, ['payment', 'cancel', 'cancel-seats', 'ticket', 'email', 'refunded'], true)) {
    $id = (int) ($_POST['id'] ?? 0);
    $now = db_time(now_utc());
    if ($action === 'payment') {
        $flash = record_payment($id, (int) ($_POST['received'] ?? 0));
    } elseif ($action === 'email') {
        $email = trim((string) ($_POST['email'] ?? ''));
        if (mb_strlen($email) > 190 || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $flash = 'Neplatný e-mail.';
        } else {
            db()->prepare('UPDATE reservations SET email = ? WHERE id = ?')->execute([$email, $id]);
            $flash = "E-mail změněn na {$email}.";
        }
    } elseif ($action === 'refunded') {
        $stmt = db()->prepare('UPDATE reservations SET refunded_at = ?, refunded_amount = refund_amount WHERE id = ? AND refund_amount > refunded_amount');
        $stmt->execute([$now, $id]);
        $flash = $stmt->rowCount() ? 'Vrácení peněz zaznamenáno.' : 'Nelze označit jako vráceno.';
    } elseif ($action === 'ticket') {
        $flash = trim(send_ticket_for($id)) ?: 'Vstupenku nelze odeslat.';
    } else {
        // Cancelled by the organizers: paid seats are refunded in full, the customer is e-mailed.
        $seats = $action === 'cancel-seats' ? (array) ($_POST['seats'] ?? []) : null;
        try {
            $r = cancel_seats($id, $seats, 'admin');
            $flash = $r['status'] === 'cancelled' ? 'Rezervace zrušena, místa uvolněna.' : 'Vybraná místa zrušena a uvolněna.';
            if ($r['refund_amount'] > $r['refunded_amount']) {
                $flash .= ' K vrácení: ' . format_czk((int) $r['refund_amount'] - (int) $r['refunded_amount']) . '.';
            }
        } catch (InvalidArgumentException $e) {
            $flash = $e->getMessage();
        }
    }
    $_SESSION['flash'] = $flash;
    header('Location: admin.php?' . http_build_query(['status' => $_GET['status'] ?? '', 'q' => $_GET['q'] ?? '', 'run' => $_GET['run'] ?? '']));
    exit;
}
$flash = $_SESSION['flash'] ?? null;

/** True once any reservation (in any status) exists for the run. */
function run_has_reservations(int $runId): bool
{
    $stmt = db()->prepare('SELECT 1 FROM reservations WHERE run_id = ? LIMIT 1');
    $stmt->execute([$runId]);
    return (bool) $stmt->fetchColumn();
}

unset($_SESSION['flash']);

$statusLabels = ['pending' => 'Čeká na platbu', 'paid' => 'Zaplaceno', 'expired' => 'Propadlo', 'cancelled' => 'Zrušeno'];
?>
<!doctype html>
<html lang="cs">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Správa rezervací – Moje židle 2026</title>
<style>
  /* Admin is desktop-first (accountant at a computer); narrow screens get only
     small adjustments at the end, wide tables scroll horizontally. */
  :root { --bg:#f5f1ea; --surface:#fffdf9; --ink:#2b2620; --ink-2:#6b6256; --wall:#d9cfbf; --accent:#8a5a2b; }
  * { box-sizing: border-box; }
  body { margin:0; background:var(--bg); color:var(--ink); font:15px/1.45 system-ui, sans-serif; }
  main { max-width:1200px; margin:0 auto; padding:24px 16px 64px; }
  header { display:flex; justify-content:space-between; align-items:center; gap:16px; flex-wrap:wrap; margin-bottom:20px; }
  h1 { margin:0; font:600 1.5rem Georgia, serif; }
  .card { background:var(--surface); border-radius:16px; box-shadow:0 6px 20px rgba(60,45,25,.07); padding:16px; }
  .stats { display:flex; gap:12px; flex-wrap:wrap; margin-bottom:16px; }
  .stats .card { padding:12px 16px; min-width:150px; }
  .stats strong { display:block; font:600 1.4rem Georgia, serif; }
  form.filters { display:flex; gap:8px; flex-wrap:wrap; margin-bottom:16px; }
  input, select, button { font:inherit; padding:8px 12px; border-radius:10px; border:1px solid var(--wall); background:#fff; }
  button { cursor:pointer; background:var(--accent); color:#fff; border:0; font-weight:600; }
  button.secondary { background:#fff; color:var(--ink); border:1px solid var(--wall); }
  button.danger { background:#fff; color:#8f2a20; border:1px solid #e49a8e; }
  .table { overflow-x:auto; }
  table { width:100%; border-collapse:collapse; }
  th, td { text-align:left; padding:10px 8px; border-bottom:1px solid #eee5d8; vertical-align:top; }
  th { font-size:.78rem; text-transform:uppercase; letter-spacing:.06em; color:var(--ink-2); }
  .badge { display:inline-block; padding:2px 10px; border-radius:999px; font-size:.8rem; font-weight:600; white-space:nowrap; }
  .s-pending { background:#f9e4b7; color:#6d4a04; } .s-paid { background:#dcefd9; color:#1f5a2c; }
  .s-expired, .s-cancelled { background:#eee5d8; color:var(--ink-2); }
  .seats { max-width:260px; font-size:.85rem; color:var(--ink-2); }
  .actions { display:flex; gap:6px; flex-wrap:wrap; } .actions form { margin:0; }
  .flash { margin-bottom:16px; padding:10px 14px; border-radius:10px; background:#dcefd9; color:#1f5a2c; }
  .flash.warn { background:#f9e4b7; color:#6d4a04; }
  .pay-form { display:flex; gap:6px; align-items:center; margin:0; }
  .pay-form input[type=number] { width:96px; padding:6px 8px; }
  details .pay-form { margin-top:6px; }
  .error { color:#8f2a20; }
  .tabs { display:flex; gap:4px; padding:4px; border-radius:999px; background:#efe9df; margin-right:auto; }
  .tabs a { padding:6px 16px; border-radius:999px; color:var(--ink-2); text-decoration:none; font-weight:600; }
  .tabs a.active { background:var(--surface); color:var(--ink); box-shadow:0 1px 4px rgba(60,45,25,.12); }
  .vip-form { display:flex; gap:10px; flex-wrap:wrap; align-items:flex-end; margin-bottom:16px; }
  .vip-form label { display:flex; flex-direction:column; gap:4px; font-size:.8rem; font-weight:600; color:var(--ink-2); }
  .vip-form label.grow { flex:1 1 200px; }
  .vip-add { margin-bottom:16px; }
  .vip-add .vip-form { margin-bottom:8px; }
  .vip-map { display:flex; flex-wrap:wrap; gap:12px; margin-top:12px; }
  .vip-section { border:1px solid var(--wall); border-radius:12px; padding:8px 10px; margin:0; }
  .vip-section legend { font-size:.8rem; font-weight:600; color:var(--ink-2); padding:0 4px; }
  .vip-row { display:flex; gap:3px; align-items:center; margin-bottom:3px; }
  .vip-row-label { width:18px; font-size:.7rem; color:var(--ink-2); text-align:right; margin-right:3px; }
  .vip-seat { position:relative; cursor:pointer; }
  .vip-seat input { position:absolute; opacity:0; pointer-events:none; }
  .vip-seat span { display:grid; place-items:center; width:24px; height:24px; border-radius:6px; border:1px solid var(--wall); background:#fff; font-size:.7rem; }
  .vip-seat input:checked + span { background:var(--accent); border-color:var(--accent); color:#fff; font-weight:700; }
  .vip-seat input:focus-visible + span { outline:2px solid var(--accent); outline-offset:1px; }
  .vip-seat.is-taken span { background:#e4dccf; color:#a59a8a; border-color:#e4dccf; cursor:not-allowed; }
  .vip-seat.is-vip span { background:#f1d98f; color:#7a5d0c; border-color:#f1d98f; cursor:not-allowed; }
  .edit-email summary { cursor:pointer; font-size:.78rem; color:var(--ink-2); margin-top:2px; }
  .edit-email form { display:flex; gap:6px; margin-top:6px; }
  .edit-email input { width:200px; padding:4px 8px; }
  .edit-email button { padding:4px 10px; }
  .overdue { color:#8f2a20; font-weight:600; }
  .seat-cancel { display:flex; flex-wrap:wrap; gap:4px 10px; margin-top:6px; align-items:center; max-width:260px; }
  .seat-cancel label { font-size:.8rem; white-space:nowrap; }
  .seat-cancel button { padding:4px 10px; }
  a.card { color:inherit; text-decoration:none; }
  .card.selected { outline:2px solid var(--accent); }
  .settings-intro { margin-bottom:12px; max-width:760px; }
  .settings + .settings { margin-top:12px; }
  .hint.locked { padding:8px 12px; border-radius:10px; background:#f9e4b7; color:#6d4a04; font-weight:600; }
  input:disabled { background:#efe9df; color:var(--ink-2); }
  .card.settings { margin-bottom:16px; }
  .tab-alert { display:inline-block; min-width:1.4em; padding:0 6px; margin-left:4px; border-radius:999px; background:#c0392b; color:#fff; font-size:.75rem; text-align:center; }
  .me { color:var(--ink-2); font-size:.9rem; }
  .login .hint { margin:-4px 0 0; }
  .section-title { margin:28px 0 6px; font:600 1.15rem Georgia, serif; }
  .checks { display:flex; flex-wrap:wrap; gap:6px 16px; }
  .check { display:inline-flex !important; flex-direction:row !important; align-items:center; gap:6px; font-weight:500 !important; color:var(--ink) !important; max-width:none !important; }
  .invite-new { display:flex; gap:20px; align-items:center; flex-wrap:wrap; margin-bottom:16px; border:2px solid var(--accent); }
  .invite-new h2 { margin:0 0 4px; font:600 1.15rem Georgia, serif; }
  .invite-new > div { display:flex; flex-direction:column; gap:8px; flex:1 1 320px; min-width:0; }
  .invite-link { width:100%; font-family:ui-monospace, monospace; font-size:.85rem; }
  .invite-edit { display:flex; flex-direction:column; gap:8px; margin-top:8px; min-width:280px; }
  .card.attention { background:#f6cdc6; color:#7d2117; text-decoration:none; }
  .settings { max-width:640px; display:flex; flex-direction:column; gap:12px; }
  .settings h2 { margin:8px 0 0; font:600 1.15rem Georgia, serif; }
  .settings label { display:flex; flex-direction:column; gap:4px; font-size:.8rem; font-weight:600; color:var(--ink-2); max-width:280px; }
  .settings .rules { display:flex; flex-direction:column; gap:8px; }
  .settings .rule { display:flex; gap:10px; flex-wrap:wrap; }
  .settings .rule input[type=number] { width:110px; }
  .settings button { align-self:flex-start; }
  .hint { margin:0; font-size:.85rem; color:var(--ink-2); }
  @media (max-width: 760px) {
    main { padding:16px 12px 48px; }
    .tabs { order:3; width:100%; overflow-x:auto; margin-right:0; }
    .stats .card { min-width:0; flex:1 1 140px; }
  }
  .login { max-width:340px; margin:15vh auto; display:flex; flex-direction:column; gap:12px; }
</style>
</head>
<body>
<main>
<?php if ($inviteToken !== ''): ?>
  <form class="card login" method="post">
    <h1>Správa rezervací</h1>
    <?php if ($invited): ?>
      <p>Dobrý den, <strong><?= $h($invited['name']) ?></strong>. Vytvořte si heslo do správy rezervací.
        Přihlašovací jméno je Váš e-mail <strong><?= $h($invited['email']) ?></strong>.</p>
      <input type="hidden" name="csrf" value="<?= $h($_SESSION['csrf']) ?>">
      <input type="hidden" name="action" value="accept-invite">
      <input type="email" value="<?= $h($invited['email']) ?>" autocomplete="username" readonly hidden>
      <input type="password" name="password" placeholder="Nové heslo (alespoň <?= ADMIN_PASSWORD_MIN ?> znaků)" minlength="<?= ADMIN_PASSWORD_MIN ?>" autocomplete="new-password" autofocus required>
      <input type="password" name="password_again" placeholder="Heslo znovu" autocomplete="new-password" required>
      <?php if ($inviteError): ?><div class="error"><?= $h($inviteError) ?></div><?php endif ?>
      <button>Uložit heslo a přihlásit</button>
    <?php else: ?>
      <p class="error">Pozvánka neplatí nebo vypršela. Požádejte o novou.</p>
      <a href="admin.php">Přejít na přihlášení</a>
    <?php endif ?>
  </form>
<?php elseif ($me === null): ?>
  <form class="card login" method="post">
    <h1>Správa rezervací</h1>
    <input type="hidden" name="csrf" value="<?= $h($_SESSION['csrf']) ?>">
    <input type="hidden" name="action" value="login">
    <input type="email" name="email" placeholder="E-mail" value="<?= $h($loginEmail ?? '') ?>" autocomplete="username" autofocus>
    <input type="password" name="password" placeholder="Heslo" autocomplete="current-password" required>
    <?php if (admin_master_enabled()): ?><p class="hint">Hlavní heslo: e-mail nechte prázdný.</p><?php endif ?>
    <?php if (!empty($loginError)): ?><div class="error"><?= $h($loginError) ?></div><?php endif ?>
    <button>Přihlásit</button>
  </form>
<?php else:
    expire_reservations();
    $status = (string) ($_GET['status'] ?? '');
    $runFilter = (int) ($_GET['run'] ?? 0);
    $q = trim((string) ($_GET['q'] ?? ''));
    $where = [];
    $params = [];
    if (isset($statusLabels[$status])) {
        $where[] = 'status = ?';
        $params[] = $status;
    } elseif ($status === 'refund') {
        $where[] = 'refund_amount > refunded_amount';
    }
    if ($runFilter) {
        $where[] = 'run_id = ?';
        $params[] = $runFilter;
    }
    if ($q !== '') {
        $where[] = '(variable_symbol LIKE ? OR email LIKE ? OR last_name LIKE ? OR first_name LIKE ? OR seats LIKE ?)';
        array_push($params, ...array_fill(0, 5, '%' . $q . '%'));
    }
    $sql = 'SELECT * FROM reservations' . ($where ? ' WHERE ' . implode(' AND ', $where) : '') . ' ORDER BY id DESC LIMIT 500';
    $stmt = db()->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll();
    $totals = db_query("SELECT status, COUNT(*) n, SUM(seat_count) seats, SUM(amount) amount FROM reservations GROUP BY status")
        ->fetchAll(PDO::FETCH_UNIQUE);
    $prague = new DateTimeZone('Europe/Prague');
    $fmt = static fn ($t) => $t ? (new DateTimeImmutable($t, new DateTimeZone('UTC')))->setTimezone($prague)->format('j. n. Y H:i') : '';
    $kc = static fn ($n) => number_format((int) $n, 0, ',', ' ') . ' Kč';
?>
  <header>
    <h1>Správa rezervací</h1>
    <nav class="tabs">
      <a href="admin.php" class="<?= $view === 'reservations' ? 'active' : '' ?>">Rezervace</a>
      <a href="admin.php?view=vip" class="<?= $view === 'vip' ? 'active' : '' ?>">VIP</a>
      <?php $conflictCount = (int) db_query('SELECT COUNT(*) FROM scan_conflicts')->fetchColumn(); ?>
      <a href="admin.php?view=scanners" class="<?= $view === 'scanners' ? 'active' : '' ?>">Pořadatelé<?= $conflictCount ? ' <span class="tab-alert" title="Konflikty z odbavení bez spojení">' . $conflictCount . '</span>' : '' ?></a>
      <a href="admin.php?view=users" class="<?= $view === 'users' ? 'active' : '' ?>">Účetní</a>
      <a href="admin.php?view=settings" class="<?= $view === 'settings' ? 'active' : '' ?>">Nastavení</a>
    </nav>
    <span class="me" title="<?= $h($me['email'] ?? '') ?>">Přihlášen: <strong><?= $h($me['name']) ?></strong></span>
    <form method="post">
      <input type="hidden" name="csrf" value="<?= $h($_SESSION['csrf']) ?>">
      <input type="hidden" name="action" value="logout">
      <button class="secondary">Odhlásit</button>
    </form>
  </header>

  <?php if ($flash): ?><div class="flash"><?= $h($flash) ?></div><?php endif ?>

  <?php if ($view === 'scanners'):
      $newInvite = $_SESSION['invite_link'] ?? null;
      unset($_SESSION['invite_link']);
      $invites = db_query('SELECT * FROM scanner_invites ORDER BY revoked_at IS NOT NULL, name')->fetchAll();
      $runChecks = static function (array $selected) use ($h): string {
          $out = '';
          foreach (runs() as $run) {
              $out .= '<label class="check"><input type="checkbox" name="runs[]" value="' . (int) $run['id'] . '"'
                  . (in_array($run['id'], $selected, true) ? ' checked' : '') . '> ' . $h(run_label($run)) . '</label>';
          }
          return $out;
      };
  ?>
  <p class="hint settings-intro">Pořadatel u vchodu dostane odkaz (nebo QR kód) na odbavení. Odkaz otevře scanner na jeho
    mobilu a povolí odbavení jen vybraných termínů. Odkaz se zobrazí jen jednou – při ztrátě vytvořte nový.
    Zrušením přístupu se zařízení okamžitě odhlásí.</p>

  <?php if ($newInvite): ?>
    <div class="card invite-new">
      <img src="data:image/png;base64,<?= base64_encode(qr_png($newInvite['link'], 6)) ?>" alt="QR kód pozvánky" width="180" height="180">
      <div>
        <h2>Pozvánka – <?= $h($newInvite['name']) ?></h2>
        <p class="hint">Pošlete odkaz pořadateli, nebo ať si QR kód naskenuje fotoaparátem mobilu.</p>
        <input class="invite-link" readonly value="<?= $h($newInvite['link']) ?>" onclick="this.select()">
        <button type="button" class="secondary" onclick="navigator.clipboard.writeText(this.previousElementSibling.value).then(() => this.textContent = 'Zkopírováno')">Kopírovat odkaz</button>
      </div>
    </div>
  <?php endif ?>

  <form class="card settings" method="post">
    <input type="hidden" name="csrf" value="<?= $h($_SESSION['csrf']) ?>">
    <input type="hidden" name="id" value="0">
    <h2>Nová pozvánka</h2>
    <label>Jméno pořadatele / vchodu<input name="name" required maxlength="100" placeholder="např. Vchod A – Petr"></label>
    <div class="checks"><?= $runChecks([]) ?></div>
    <button name="action" value="invite-save">Vytvořit pozvánku</button>
  </form>

  <div class="card table">
    <table>
      <thead><tr><th>Pořadatel</th><th>Termíny</th><th>Vytvořeno</th><th>Naposledy použito</th><th>Stav</th><th></th></tr></thead>
      <tbody>
      <?php foreach ($invites as $inv):
          $invRuns = invite_run_ids((int) $inv['id']);
          $revoked = $inv['revoked_at'] !== null;
      ?>
        <tr>
          <td><strong><?= $h($inv['name']) ?></strong></td>
          <td class="seats"><?= $h(implode(', ', array_map(static fn ($id) => ($r = run_by_id($id)) ? run_label($r) : '–', $invRuns))) ?></td>
          <td><?= $h($fmt($inv['created_at'])) ?></td>
          <td><?= $h($fmt($inv['last_used_at'])) ?: '–' ?></td>
          <td><span class="badge <?= $revoked ? 's-cancelled' : 's-paid' ?>"><?= $revoked ? 'Zrušeno' : 'Aktivní' ?></span></td>
          <td>
            <div class="actions">
              <details class="edit-email"><summary>upravit</summary>
                <form method="post" class="invite-edit">
                  <input type="hidden" name="csrf" value="<?= $h($_SESSION['csrf']) ?>">
                  <input type="hidden" name="id" value="<?= (int) $inv['id'] ?>">
                  <input name="name" required maxlength="100" value="<?= $h($inv['name']) ?>">
                  <div class="checks"><?= $runChecks($invRuns) ?></div>
                  <label class="check"><input type="checkbox" name="new_link" value="1" <?= $revoked ? 'checked' : '' ?>> vytvořit nový odkaz (původní přestane platit<?= $revoked ? ', obnoví přístup' : '' ?>)</label>
                  <button name="action" value="invite-save">Uložit</button>
                </form>
              </details>
              <?php if (!$revoked): ?>
                <form method="post" onsubmit="return confirm('Zrušit přístup? Zařízení se okamžitě odhlásí.')">
                  <input type="hidden" name="csrf" value="<?= $h($_SESSION['csrf']) ?>">
                  <input type="hidden" name="id" value="<?= (int) $inv['id'] ?>">
                  <button class="danger" name="action" value="invite-revoke">Zrušit přístup</button>
                </form>
              <?php endif ?>
            </div>
          </td>
        </tr>
      <?php endforeach ?>
      <?php if (!$invites): ?><tr><td colspan="6">Zatím žádné pozvánky.</td></tr><?php endif ?>
      </tbody>
    </table>
  </div>

  <?php $conflicts = db_query('SELECT * FROM scan_conflicts ORDER BY scanned_at DESC LIMIT 500')->fetchAll(); ?>
  <h2 class="section-title" id="konflikty">Odbavení bez spojení – konflikty<?= $conflicts ? ' (' . count($conflicts) . ')' : '' ?></h2>
  <p class="hint settings-intro">Scanner bez internetu ověřuje vstupenky podle seznamu staženého předem a odbavení odešle,
    jakmile se spojení vrátí. Platí první odbavení. Sem se zapíše, co se pak nedalo přijmout – typicky stejná vstupenka
    puštěná na dvou mobilech.</p>
  <div class="card table">
    <table>
      <thead><tr><th>Načteno</th><th>Termín</th><th>Vstupenka / host</th><th>Problém</th><th>Kdo</th><th>Dříve odbaveno</th></tr></thead>
      <tbody>
      <?php foreach ($conflicts as $c): ?>
        <tr>
          <td><?= $h($fmt($c['scanned_at'])) ?></td>
          <td class="seats"><?= ($cr = run_by_id((int) $c['run_id'])) ? $h(run_label($cr)) : '–' ?></td>
          <td><strong><?= $h($c['label']) ?></strong></td>
          <td><span class="badge <?= $c['reason'] === 'already_checked_in' ? 's-pending' : 's-expired' ?>"><?= $h(CONFLICT_REASONS[$c['reason']] ?? $c['reason']) ?></span></td>
          <td><?= $h($c['scanned_by']) ?></td>
          <td><?= $c['other_at'] ? $h($fmt($c['other_at'])) . ($c['other_by'] ? '<br><small>' . $h($c['other_by']) . '</small>' : '') : '' ?></td>
        </tr>
      <?php endforeach ?>
      <?php if (!$conflicts): ?><tr><td colspan="6">Žádné konflikty.</td></tr><?php endif ?>
      </tbody>
    </table>
  </div>

  <?php elseif ($view === 'users'):
      $newLink = $_SESSION['user_link'] ?? null;
      unset($_SESSION['user_link']);
      $users = db_query('SELECT * FROM admin_users ORDER BY disabled_at IS NOT NULL, name')->fetchAll();
      $nowDb = db_time(now_utc());
  ?>
  <p class="hint settings-intro">Účetní a další správci se přihlašují svým e-mailem a heslem. Pozvaný dostane e-mail
    s odkazem (platí <?= ADMIN_INVITE_DAYS ?> dní), na kterém si heslo vytvoří. Kdo heslo zapomene, pošlete mu nový odkaz.
    Všichni správci mají stejná práva.<?= admin_master_enabled() ? ' Hlavní heslo z konfigurace (ADMIN_PASSWORD) dál funguje s prázdným e-mailem – po pozvání účetních ho můžete v konfiguraci smazat.' : '' ?></p>

  <?php if ($newLink): ?>
    <div class="card invite-new">
      <div>
        <h2>Odkaz pro <?= $h($newLink['email']) ?></h2>
        <p class="hint">Zobrazí se jen teď. Pokud e-mail nedorazí, pošlete odkaz sami.</p>
        <input class="invite-link" readonly value="<?= $h($newLink['link']) ?>" onclick="this.select()">
        <button type="button" class="secondary" onclick="navigator.clipboard.writeText(this.previousElementSibling.value).then(() => this.textContent = 'Zkopírováno')">Kopírovat odkaz</button>
      </div>
    </div>
  <?php endif ?>

  <form class="card settings" method="post">
    <input type="hidden" name="csrf" value="<?= $h($_SESSION['csrf']) ?>">
    <h2>Pozvat účetní</h2>
    <label>Jméno<input name="name" required maxlength="100" placeholder="např. Jana Nováková"></label>
    <label>E-mail (bude přihlašovací jméno)<input type="email" name="email" required maxlength="190" placeholder="jana@example.cz"></label>
    <button name="action" value="user-invite">Poslat pozvánku</button>
  </form>

  <div class="card table">
    <table>
      <thead><tr><th>Jméno</th><th>E-mail (login)</th><th>Stav</th><th>Pozval(a)</th><th>Naposledy přihlášen(a)</th><th></th></tr></thead>
      <tbody>
      <?php foreach ($users as $u):
          $pending = $u['password_hash'] === null;
          $openLink = $u['invite_hash'] !== null && $u['invite_expires_at'] > $nowDb;
          [$badge, $label] = match (true) {
              $u['disabled_at'] !== null => ['s-cancelled', 'Vypnuto'],
              $pending && $openLink => ['s-pending', 'Čeká na heslo (do ' . $fmt($u['invite_expires_at']) . ')'],
              $pending => ['s-expired', 'Pozvánka vypršela'],
              default => ['s-paid', 'Aktivní'],
          };
      ?>
        <tr>
          <td><strong><?= $h($u['name']) ?></strong><?= (int) $u['id'] === $me['id'] ? ' <small>(vy)</small>' : '' ?></td>
          <td><?= $h($u['email']) ?></td>
          <td><span class="badge <?= $badge ?>"><?= $h($label) ?></span>
            <?php if (!$pending && $openLink): ?><br><small>odkaz na nové heslo platí do <?= $h($fmt($u['invite_expires_at'])) ?></small><?php endif ?></td>
          <td><?= $h($u['invited_by']) ?><br><small><?= $h($fmt($u['created_at'])) ?></small></td>
          <td><?= $h($fmt($u['last_login_at'])) ?: '–' ?></td>
          <td>
            <div class="actions">
              <?php if ($u['disabled_at'] === null): ?>
                <form method="post" onsubmit="return confirm('<?= $pending ? 'Poslat novou pozvánku? Původní odkaz přestane platit.' : 'Poslat odkaz na nové heslo? Dosavadní heslo platí, dokud si nové nenastaví.' ?>')">
                  <input type="hidden" name="csrf" value="<?= $h($_SESSION['csrf']) ?>">
                  <input type="hidden" name="id" value="<?= (int) $u['id'] ?>">
                  <button class="secondary" name="action" value="user-link"><?= $pending ? 'Poslat znovu' : 'Nové heslo' ?></button>
                </form>
                <?php if ((int) $u['id'] !== $me['id']): ?>
                  <form method="post" onsubmit="return confirm('Vypnout účet? Dotyčný se už nepřihlásí a je ihned odhlášen.')">
                    <input type="hidden" name="csrf" value="<?= $h($_SESSION['csrf']) ?>">
                    <input type="hidden" name="id" value="<?= (int) $u['id'] ?>">
                    <button class="danger" name="action" value="user-disable">Vypnout</button>
                  </form>
                <?php endif ?>
              <?php else: ?>
                <form method="post">
                  <input type="hidden" name="csrf" value="<?= $h($_SESSION['csrf']) ?>">
                  <input type="hidden" name="id" value="<?= (int) $u['id'] ?>">
                  <button class="secondary" name="action" value="user-enable">Zapnout</button>
                </form>
              <?php endif ?>
            </div>
          </td>
        </tr>
      <?php endforeach ?>
      <?php if (!$users): ?><tr><td colspan="6">Zatím žádní účetní – pozvěte je formulářem výše.</td></tr><?php endif ?>
      </tbody>
    </table>
  </div>

  <?php elseif ($view === 'settings'):
      $deleteAt = data_deletion_at();
      $local = static fn (?string $utc) => $utc ? utc_time($utc)->setTimezone(new DateTimeZone(PRAGUE))->format('Y-m-d\TH:i') : '';
      $runForms = array_values(runs());
      $runForms[] = ['id' => 0, 'label' => '', 'starts_at' => null, 'booking_closes_at' => null, 'storno_rules' => []];
  ?>
  <p class="hint settings-intro">Každý termín má vlastní plánek míst, rezervace, VIP hosty a storno podmínky.
    Rezervace a rušení zákazníkem končí začátkem termínu (nebo dříve, je-li vyplněn konec rezervací).
    <?php if ($deleteAt): ?>Osobní údaje budou smazány <?= $h($deleteAt->format('j. n. Y')) ?> (<?= (int) config('DATA_RETENTION_DAYS') ?> dní po posledním termínu).<?php endif ?></p>

  <?php foreach ($runForms as $run):
      $locked = $run['id'] && run_has_reservations($run['id']);
      $rows = $locked ? $run['storno_rules'] : array_pad($run['storno_rules'], max(3, count($run['storno_rules']) + 1), ['from' => '', 'percent' => '']);
      $dis = $locked ? ' disabled' : '';
  ?>
  <form class="card settings" method="post">
    <input type="hidden" name="csrf" value="<?= $h($_SESSION['csrf']) ?>">
    <input type="hidden" name="id" value="<?= (int) $run['id'] ?>">
    <h2><?= $run['id'] ? $h(run_label($run)) : 'Nový termín' ?></h2>
    <div class="rule">
      <label>Začátek<input type="datetime-local" name="starts_at" value="<?= $h($local($run['starts_at'])) ?>" required<?= $dis ?>></label>
      <label>Název (nepovinný)<input name="label" maxlength="100" value="<?= $h($run['label']) ?>" placeholder="např. Premiéra"></label>
      <label>Konec rezervací (nepovinný)<input type="datetime-local" name="booking_closes_at" value="<?= $h($local($run['booking_closes_at'])) ?>"></label>
    </div>
    <?php if ($locked): ?>
      <p class="hint locked">Představení už má rezervace – začátek a storno podmínky nelze měnit (zákazníci rezervovali za těchto podmínek).</p>
    <?php endif ?>
    <p class="hint">Storno poplatky zaplacených rezervací tohoto termínu. Před prvním datem je storno zdarma.<?= $locked && !$rows ? ' Storno podmínky nejsou nastavené – zrušení je zdarma.' : '' ?></p>
    <div class="rules">
      <?php foreach ($rows as $rule): ?>
        <div class="rule">
          <label>Od<input type="datetime-local" name="rule_from[]" value="<?= $h($rule['from'] === '' ? '' : prague_input(storno_from($rule['from']))) ?>"<?= $dis ?>></label>
          <label>Poplatek %<input type="number" name="rule_percent[]" min="0" max="100" value="<?= $h($rule['percent']) ?>"<?= $dis ?>></label>
        </div>
      <?php endforeach ?>
    </div>
    <div class="actions">
      <button name="action" value="run-save"><?= $run['id'] ? 'Uložit termín' : 'Přidat termín' ?></button>
      <?php if ($run['id']): ?>
        <button class="danger" name="action" value="run-delete" onclick="return confirm('Smazat termín? Lze jen u termínu bez rezervací a VIP.')">Smazat</button>
      <?php endif ?>
    </div>
  </form>
  <?php endforeach ?>

  <?php elseif ($view === 'vip'):
      $runFilter = (int) ($_GET['run'] ?? 0);
      $stmt = db()->prepare('SELECT * FROM vip_guests' . ($runFilter ? ' WHERE run_id = ?' : '') . ' ORDER BY run_id, section, name');
      $stmt->execute($runFilter ? [$runFilter] : []);
      $vips = $stmt->fetchAll();
      $vipPersons = array_sum(array_column($vips, 'persons'));
      $vipArrived = array_sum(array_map(fn ($v) => $v['checked_in_at'] ? (int) $v['persons'] : 0, $vips));
  ?>
  <div class="stats">
    <div class="card">VIP hosté<strong><?= count($vips) ?></strong><?= $vipPersons ?> osob</div>
    <div class="card">Přišlo<strong><?= $vipArrived ?> / <?= $vipPersons ?></strong>osob</div>
  </div>

  <form class="filters" method="get">
    <input type="hidden" name="view" value="vip">
    <select name="run" onchange="this.form.submit()">
      <option value="">Všechny termíny</option>
      <?php foreach (runs() as $run): ?><option value="<?= (int) $run['id'] ?>" <?= $runFilter === $run['id'] ? 'selected' : '' ?>><?= $h(run_label($run)) ?></option><?php endforeach ?>
    </select>
  </form>

  <?php if ($runFilter && ($vipRun = run_by_id($runFilter))):
      $heldStmt = db()->prepare('SELECT seat_id, vip_guest_id FROM reservation_seats WHERE run_id = ?');
      $heldStmt->execute([$runFilter]);
      $held = $heldStmt->fetchAll(PDO::FETCH_KEY_PAIR); ?>
  <form class="card vip-add" method="post">
    <input type="hidden" name="csrf" value="<?= $h($_SESSION['csrf']) ?>">
    <input type="hidden" name="action" value="vip-add">
    <input type="hidden" name="run_id" value="<?= (int) $runFilter ?>">
    <div class="vip-form">
      <label>Jméno<input name="name" required maxlength="200" placeholder="Jméno a příjmení"></label>
      <label class="grow">Poznámka<input name="note" maxlength="255" placeholder="nepovinné"></label>
      <span class="hint">Vybraná místa: <strong id="vip-count">0</strong></span>
      <button>Přidat VIP</button>
    </div>
    <p class="hint">Vyberte místa na plánku (<?= $h(run_label($vipRun)) ?>). Šedá jsou obsazená rezervacemi, zlatá jinými VIP hosty. Místa VIP se na webu zobrazí jako obsazená.</p>
    <div class="vip-map">
      <?php foreach (SECTIONS as $sid => $def): ?>
        <fieldset class="vip-section"><legend><?= $h($def['name']) ?></legend>
          <?php for ($row = 1; $row <= $def['rows']; $row++): ?>
            <div class="vip-row"><span class="vip-row-label"><?= $row ?></span>
              <?php for ($seat = 1; $seat <= $def['seats']; $seat++):
                  $seatId = "{$sid}-{$row}-{$seat}";
                  $isHeld = array_key_exists($seatId, $held);
                  $cls = $isHeld ? ($held[$seatId] !== null ? 'is-vip' : 'is-taken') : ''; ?>
                <label class="vip-seat <?= $cls ?>" title="<?= $h($seatId) ?>"><input type="checkbox" name="seats[]" value="<?= $h($seatId) ?>" <?= $isHeld ? 'disabled' : '' ?>><span><?= $seat ?></span></label>
              <?php endfor ?>
            </div>
          <?php endfor ?>
        </fieldset>
      <?php endforeach ?>
    </div>
  </form>
  <script>
    document.querySelector('.vip-map').addEventListener('change', () => {
      document.getElementById('vip-count').textContent = document.querySelectorAll('.vip-map input:checked').length;
    });
  </script>
  <?php else: ?>
  <p class="card hint">Pro přidání VIP hosta vyberte nahoře termín – zobrazí se plánek s volnými místy.</p>
  <?php endif ?>

  <div class="card table">
    <table>
      <thead><tr><th>Termín</th><th>Jméno</th><th>Místa</th><th>Osob</th><th>Poznámka</th><th>Příchod</th><th></th></tr></thead>
      <tbody>
      <?php foreach ($vips as $v): ?>
        <tr>
          <td><?= ($vr = run_by_id((int) $v['run_id'])) ? $h(run_label($vr)) : '–' ?></td>
          <td><strong><?= $h($v['name']) ?></strong></td>
          <td class="seats"><?= $v['seats'] !== ''
              ? implode('<br>', array_map($h, seat_labels($v['seats'])))
              : $h(SECTIONS[$v['section']]['name'] ?? $v['section']) . '<br><small class="overdue">bez přidělených míst – odstraňte a přidejte znovu</small>' ?></td>
          <td><?= (int) $v['persons'] ?></td>
          <td><?= $h($v['note']) ?></td>
          <td><?= $v['checked_in_at'] ? '<span class="badge s-paid">' . $h($fmt($v['checked_in_at'])) . '</span>' . ($v['checked_in_by'] ? '<br><small>' . $h($v['checked_in_by']) . '</small>' : '') : '' ?></td>
          <td>
            <div class="actions">
              <?php if ($v['checked_in_at']): ?>
                <form method="post">
                  <input type="hidden" name="csrf" value="<?= $h($_SESSION['csrf']) ?>">
                  <input type="hidden" name="id" value="<?= (int) $v['id'] ?>">
                  <button class="secondary" name="action" value="vip-reset">Zrušit příchod</button>
                </form>
              <?php endif ?>
              <form method="post" onsubmit="return confirm('Odstranit VIP hosta a uvolnit jeho místa?')">
                <input type="hidden" name="csrf" value="<?= $h($_SESSION['csrf']) ?>">
                <input type="hidden" name="id" value="<?= (int) $v['id'] ?>">
                <button class="danger" name="action" value="vip-delete">Odstranit</button>
              </form>
            </div>
          </td>
        </tr>
      <?php endforeach ?>
      <?php if (!$vips): ?><tr><td colspan="7">Žádní VIP hosté.</td></tr><?php endif ?>
      </tbody>
    </table>
  </div>
  <?php else: ?>

  <div class="stats">
    <?php foreach (['pending', 'paid'] as $s): $t = $totals[$s] ?? ['n' => 0, 'seats' => 0, 'amount' => 0]; ?>
      <div class="card"><?= $h($statusLabels[$s]) ?><strong><?= (int) $t['seats'] ?> míst</strong><?= $kc($t['amount']) ?> · <?= (int) $t['n'] ?> rez.</div>
    <?php endforeach ?>
    <?php $refunds = db_query('SELECT COUNT(*) n, COALESCE(SUM(refund_amount - refunded_amount), 0) amount FROM reservations WHERE refund_amount > refunded_amount')->fetch(); ?>
    <?php if ($refunds['n'] > 0): ?>
      <a class="card attention" href="admin.php?status=refund">K vrácení<strong><?= $kc($refunds['amount']) ?></strong><?= (int) $refunds['n'] ?> rez.</a>
    <?php endif ?>
  </div>

  <?php $deleteAt = data_deletion_at();
  if ($refunds['n'] > 0 && $deleteAt !== null && now_utc() > $deleteAt->modify('-14 days')): ?>
    <div class="flash warn">Osobní údaje se mažou <?= $h(format_prague(db_time($deleteAt))) ?>. U <?= (int) $refunds['n'] ?> rez. zbývá vrátit peníze – jejich jméno a e-mail se smažou až po označení „Vráceno“.</div>
  <?php endif ?>

  <?php $perRun = db_query("SELECT run_id, COUNT(*) FROM reservation_seats GROUP BY run_id")->fetchAll(PDO::FETCH_KEY_PAIR); ?>
  <div class="stats">
    <?php foreach (runs() as $run): $held = (int) ($perRun[$run['id']] ?? 0); ?>
      <a class="card <?= $runFilter === $run['id'] ? 'selected' : '' ?>" href="admin.php?run=<?= (int) $run['id'] ?>"><?= $h(run_label($run)) ?><strong><?= $held ?> / <?= total_capacity() ?></strong><?= total_capacity() - $held ?> volných</a>
    <?php endforeach ?>
  </div>

  <form class="filters" method="get">
    <select name="run">
      <option value="">Všechny termíny</option>
      <?php foreach (runs() as $run): ?><option value="<?= (int) $run['id'] ?>" <?= $runFilter === $run['id'] ? 'selected' : '' ?>><?= $h(run_label($run)) ?></option><?php endforeach ?>
    </select>
    <select name="status">
      <option value="">Všechny stavy</option>
      <?php foreach ($statusLabels as $k => $label): ?>
        <option value="<?= $h($k) ?>" <?= $status === $k ? 'selected' : '' ?>><?= $h($label) ?></option>
      <?php endforeach ?>
      <option value="refund" <?= $status === 'refund' ? 'selected' : '' ?>>K vrácení peněz</option>
    </select>
    <input type="search" name="q" value="<?= $h($q) ?>" placeholder="VS, jméno, e-mail, místo">
    <button class="secondary">Filtrovat</button>
  </form>

  <div class="card table">
    <table>
      <thead><tr><th>VS</th><th>Termín</th><th>Jméno</th><th>Místa</th><th>Částka</th><th>Stav</th><th>Vytvořeno</th><th>Splatnost</th><th></th></tr></thead>
      <tbody>
      <?php foreach ($rows as $r): ?>
        <tr>
          <td><strong><?= $h($r['variable_symbol']) ?></strong></td>
          <td class="seats"><?= ($rr = run_by_id((int) $r['run_id'])) ? $h(run_label($rr)) : '–' ?></td>
          <td><?= $h($r['first_name'] . ' ' . $r['last_name']) ?><br><a href="mailto:<?= $h($r['email']) ?>"><?= $h($r['email']) ?></a>
            <details class="edit-email"><summary>změnit e-mail</summary>
              <form method="post">
                <input type="hidden" name="csrf" value="<?= $h($_SESSION['csrf']) ?>">
                <input type="hidden" name="id" value="<?= (int) $r['id'] ?>">
                <input type="email" name="email" value="<?= $h($r['email']) ?>" required>
                <button name="action" value="email">Uložit</button>
              </form>
            </details></td>
          <td class="seats"><?= $h(str_replace(',', ', ', $r['seats'])) ?>
            <?php if ($r['cancelled_seats'] !== ''): ?><br><small>zrušená místa: <?= $h(str_replace(',', ', ', $r['cancelled_seats'])) ?></small><?php endif ?>
            <?php if (in_array($r['status'], ['pending', 'paid'], true) && (int) $r['seat_count'] > 1): ?>
              <details class="edit-email"><summary>zrušit jednotlivá místa</summary>
                <form method="post" class="seat-cancel" onsubmit="return confirm('Zrušit vybraná místa?<?= $r['status'] === 'paid' ? ' Zákazníkovi bude vrácena jejich plná cena a přijde mu e-mail s novou vstupenkou.' : '' ?>')">
                  <input type="hidden" name="csrf" value="<?= $h($_SESSION['csrf']) ?>">
                  <input type="hidden" name="id" value="<?= (int) $r['id'] ?>">
                  <?php foreach (explode(',', $r['seats']) as $seat): ?>
                    <label><input type="checkbox" name="seats[]" value="<?= $h($seat) ?>"> <?= $h($seat) ?></label>
                  <?php endforeach ?>
                  <button class="danger" name="action" value="cancel-seats">Zrušit vybraná</button>
                </form>
              </details>
            <?php endif ?></td>
          <td><?= $kc($r['amount']) ?>
            <?php if ($r['paid_amount'] !== null && (int) $r['paid_amount'] !== (int) $r['amount']): ?><br><small>přijato <?= $kc($r['paid_amount']) ?></small><?php endif ?>
            <?php if ($r['status'] === 'pending' && (int) $r['paid_amount'] > 0): ?><br><small class="overdue">zbývá doplatit <?= $kc(amount_due($r)) ?></small><?php endif ?></td>
          <td><span class="badge s-<?= $h($r['status']) ?>"><?= $h($statusLabels[$r['status']]) ?></span>
            <?php if ($r['paid_at']): ?><br><small>zaplaceno <?= $h($fmt($r['paid_at'])) ?></small><?php endif ?>
            <?php if ($r['ticket_sent_at']): ?><br><small>vstupenka <?= $h($fmt($r['ticket_sent_at'])) ?></small><?php endif ?>
            <?php if ($r['checked_in_at']): ?><br><small>odbaveno <?= $h($fmt($r['checked_in_at'])) ?><?= $r['checked_in_by'] ? ' (' . $h($r['checked_in_by']) . ')' : '' ?></small><?php endif ?>
            <?php if ($r['status'] === 'cancelled'): ?><br><small><?= $r['cancelled_by'] === 'customer' ? 'zrušil zákazník' : 'zrušeno správcem' ?> <?= $h($fmt($r['cancelled_at'])) ?></small><?php endif ?>
            <?php if ($r['cancel_fee'] > 0): ?><br><small>storno <?= $kc($r['cancel_fee']) ?></small><?php endif ?>
            <?php if ($r['refunded_amount'] > 0): ?><br><small>vráceno <?= $kc($r['refunded_amount']) ?> (<?= $h($fmt($r['refunded_at'])) ?>)</small><?php endif ?>
            <?php if ($r['refund_amount'] > $r['refunded_amount']): ?>
              <br><small class="overdue">vrátit: <?= $kc($r['refund_amount'] - $r['refunded_amount']) ?>
                na účet plátce</small>
            <?php endif ?></td>
          <td><?= $h($fmt($r['created_at'])) ?></td>
          <td class="<?= $r['status'] === 'pending' && $r['expires_at'] < db_time(now_utc()) ? 'overdue' : '' ?>"><?= $h($fmt($r['expires_at'])) ?></td>
          <td>
            <div class="actions">
              <?php if ($r['status'] === 'pending'): ?>
                <form method="post" class="pay-form" title="Zadejte částku, která skutečně přišla na účet">
                  <input type="hidden" name="csrf" value="<?= $h($_SESSION['csrf']) ?>">
                  <input type="hidden" name="id" value="<?= (int) $r['id'] ?>">
                  <input type="number" name="received" min="1" max="1000000" value="<?= amount_due($r) ?>" required aria-label="Přijatá částka v Kč"> Kč
                  <button name="action" value="payment">Zaplaceno</button>
                </form>
              <?php endif ?>
              <?php if ($r['refund_amount'] > $r['refunded_amount']): ?>
                <form method="post" onsubmit="return confirm('Peníze byly vráceny?')">
                  <input type="hidden" name="csrf" value="<?= $h($_SESSION['csrf']) ?>">
                  <input type="hidden" name="id" value="<?= (int) $r['id'] ?>">
                  <button name="action" value="refunded">Vráceno</button>
                </form>
              <?php endif ?>
              <?php if ($r['status'] !== 'pending'): ?>
                <details class="edit-email"><summary>přišla platba</summary>
                  <form method="post" class="pay-form" onsubmit="return confirm('<?= $r['status'] === 'expired'
                      ? 'Zapsat platbu? Pokud představení ještě nezačalo, částka stačí a místa jsou volná, rezervace se obnoví jako zaplacená. Jinak bude platba k vrácení a zákazník dostane e-mail.'
                      : 'Zapsat platbu? Rezervace je ' . ($r['status'] === 'paid' ? 'už zaplacená' : 'zrušená') . ' – platba bude k vrácení na účet plátce a zákazník dostane e-mail.' ?>')">
                    <input type="hidden" name="csrf" value="<?= $h($_SESSION['csrf']) ?>">
                    <input type="hidden" name="id" value="<?= (int) $r['id'] ?>">
                    <input type="number" name="received" min="1" max="1000000" value="<?= $r['status'] === 'expired' ? amount_due($r) : (int) $r['amount'] ?>" required aria-label="Přijatá částka v Kč"> Kč
                    <button class="secondary" name="action" value="payment">Zapsat platbu</button>
                  </form>
                </details>
              <?php endif ?>
              <?php if ($r['status'] === 'paid'): ?>
                <form method="post">
                  <input type="hidden" name="csrf" value="<?= $h($_SESSION['csrf']) ?>">
                  <input type="hidden" name="id" value="<?= (int) $r['id'] ?>">
                  <button class="secondary" name="action" value="ticket"><?= $r['ticket_sent_at'] ? 'Poslat znovu' : 'Poslat vstupenku' ?></button>
                </form>
              <?php endif ?>
              <?php if (in_array($r['status'], ['pending', 'paid'], true)): ?>
                <form method="post" onsubmit="return confirm('Zrušit rezervaci a uvolnit místa?<?= $r['status'] === 'paid' ? ' Zákazníkovi bude vrácena celá částka a přijde mu e-mail.' : '' ?>')">
                  <input type="hidden" name="csrf" value="<?= $h($_SESSION['csrf']) ?>">
                  <input type="hidden" name="id" value="<?= (int) $r['id'] ?>">
                  <button class="danger" name="action" value="cancel">Zrušit</button>
                </form>
              <?php endif ?>
            </div>
          </td>
        </tr>
      <?php endforeach ?>
      <?php if (!$rows): ?><tr><td colspan="9">Žádné rezervace.</td></tr><?php endif ?>
      </tbody>
    </table>
  </div>
  <?php endif ?>
<?php endif ?>
</main>
</body>
</html>
