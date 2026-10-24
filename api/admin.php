<?php
// Admin page (roles in admin_users.php): Správce – everything (reservations, plan, discount
// codes, users, settings, scanner); Účetní – reservations and payments; Odbavovatel – only the
// scanner (signed in here, sent on to scanner.html). Sign-in: e-mail + password; invitation
// link ?pozvanka=, forgotten password ?zapomenute → e-mailed link ?heslo=.
declare(strict_types=1);
require __DIR__ . '/lib/bootstrap.php';

session_set_cookie_params(['httponly' => true, 'samesite' => 'Strict', 'secure' => !empty($_SERVER['HTTPS'])]);
session_start();
header('Content-Type: text/html; charset=utf-8');
header('X-Frame-Options: DENY');

$h = static fn ($v) => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');

if (!admin_exists()) {
    http_response_code(503);
    exit('Aplikace zatím nemá správce. Vytvořte ho jednorázově voláním POST api/deploy.php s tokenem DEPLOY_TOKEN z config.local.php (viz docs/DEVELOPER.md).');
}

if (empty($_SESSION['csrf'])) {
    $_SESSION['csrf'] = bin2hex(random_bytes(16));
}
$csrfOk = hash_equals($_SESSION['csrf'], (string) ($_POST['csrf'] ?? ''));
$action = $_POST['action'] ?? null;

/** Starts the session of a signed-in user and goes on (Odbavovatel only → the scanner). */
$signIn = static function (array $user, ?string $flash = null): never {
    session_regenerate_id(true);
    $_SESSION['admin_id'] = $user['id'];
    $_SESSION['admin_pw'] = $user['pw'];
    if ($flash !== null) {
        $_SESSION['flash'] = $flash;
    }
    header('Location: admin.php');
    exit;
};

if ($action === 'login' && $csrfOk) {
    $loginEmail = trim((string) ($_POST['email'] ?? ''));
    if (!login_allowed('admin')) {
        $loginError = 'Příliš mnoho pokusů. Zkuste to za 15 minut.';
    } elseif ($signedIn = admin_login($loginEmail, (string) ($_POST['password'] ?? ''))) {
        $signIn($signedIn);
    } else {
        sleep(1);
        $loginError = 'Nesprávný e-mail nebo heslo.';
    }
}

// Invitation link admin.php?pozvanka=<token>: the invitee sets a password, the account exists from now on.
$inviteToken = (string) ($_GET['pozvanka'] ?? '');
if ($inviteToken !== '') {
    $inviteError = null;
    if ($action === 'accept-invite' && $csrfOk) {
        $accepted = login_allowed('admin-invite')
            ? admin_accept_invite($inviteToken, (string) ($_POST['password'] ?? ''), (string) ($_POST['password_again'] ?? ''))
            : 'Příliš mnoho pokusů. Zkuste to za 15 minut.';
        if (is_array($accepted)) {
            $signIn($accepted, "Vítejte. Heslo je nastavené, příště se přihlásíte e-mailem {$accepted['email']}.");
        }
        $inviteError = $accepted;
    }
    $invited = admin_invited_user($inviteToken);
}

// Forgotten password: admin.php?zapomenute → e-mail; the e-mailed link admin.php?heslo=<token> sets a new one.
$forgot = isset($_GET['zapomenute']);
$forgotDone = false;
if ($forgot && $action === 'forgot' && $csrfOk) {
    if (login_allowed('reset')) {
        request_password_reset((string) ($_POST['email'] ?? ''));
    }
    $forgotDone = true; // the same answer whether the e-mail exists or not
}
$resetToken = (string) ($_GET['heslo'] ?? '');
if ($resetToken !== '') {
    $resetError = null;
    if ($action === 'reset-password' && $csrfOk) {
        $done = login_allowed('reset')
            ? reset_password($resetToken, (string) ($_POST['password'] ?? ''), (string) ($_POST['password_again'] ?? ''))
            : 'Příliš mnoho pokusů. Zkuste to za 15 minut.';
        if (is_array($done)) {
            $signIn($done, 'Heslo změněno.');
        }
        $resetError = $done;
    }
    $resetUser = password_reset_user($resetToken);
}

$me = admin_from_session($_SESSION['admin_id'] ?? null, $_SESSION['admin_pw'] ?? null);
if ($action === 'logout' && $csrfOk) {
    $_SESSION = [];
    session_destroy();
    header('Location: admin.php');
    exit;
}
$canManage = $me !== null && user_can($me, 'manage');
$canReservations = $me !== null && user_can($me, 'reservations');
$canScan = $me !== null && user_can($me, 'scan');
$scannerUrl = app_base_url() . '/scanner.html';
// Odbavovatel only: nothing to do here, the scanner is their app.
if ($me !== null && !$canReservations && $inviteToken === '' && $resetToken === '' && !$forgot) {
    header('Location: ' . $scannerUrl);
    exit;
}

$flash = null;
// Tabs the user may open (Účetní: reservations and the bank statement).
$view = (string) ($_GET['view'] ?? 'reservations');
if (!in_array($view, $canManage ? ['plan', 'codes', 'users', 'settings', 'bank'] : ['bank'], true) || !$canReservations) {
    $view = 'reservations';
}

// Plánek: which seats of a run are "Záložní" (not for sale).
if ($canManage && $csrfOk && in_array($action, ['plan-save', 'plan-default'], true)) {
    $runId = (int) ($_POST['run_id'] ?? 0);
    if (run_by_id($runId) === null) {
        $_SESSION['flash'] = 'Vyberte termín.';
    } else {
        $held = save_run_reserve_seats($runId, $action === 'plan-default' ? null : (array) ($_POST['reserve'] ?? []));
        $run = runs()[$runId];
        $_SESSION['flash'] = ($action === 'plan-default' ? 'Obnoveno výchozí rozložení.' : 'Plánek uložen.')
            . ' V prodeji ' . run_capacity($run) . ' míst, záložních ' . count($run['reserve_seats']) . '.'
            . ($held ? ' Obsazená místa zůstala v prodeji: ' . implode(', ', $held) . '.' : '');
    }
    header('Location: admin.php?' . http_build_query(['view' => 'plan', 'run' => $runId]));
    exit;
}

if ($canManage && $csrfOk && in_array($action, ['code-create', 'code-resend', 'code-revoke'], true)) {
    $id = (int) ($_POST['id'] ?? 0);
    if ($action === 'code-create') {
        $_SESSION['flash'] = create_discount_codes((string) ($_POST['emails'] ?? ''), $me['email']);
    } elseif ($action === 'code-resend') {
        $code = discount_code_row($id);
        $_SESSION['flash'] = $code === null || $code['used_at'] !== null || $code['revoked_at'] !== null
            ? 'Kód už nelze poslat (byl použit nebo zrušen).'
            : (send_discount_codes_email([$id]) ? "Kód odeslán na {$code['email']}." : 'E-mail se nepodařilo odeslat.');
    } else {
        $_SESSION['flash'] = revoke_discount_code($id);
    }
    header('Location: admin.php?' . http_build_query(['view' => 'codes', 'q' => $_GET['q'] ?? '']));
    exit;
}

if ($canManage && $csrfOk && in_array($action, ['user-invite', 'user-link', 'user-roles', 'user-disable', 'user-enable'], true)) {
    $id = (int) ($_POST['id'] ?? 0);
    $roles = array_map('strval', (array) ($_POST['roles'] ?? []));
    if ($action === 'user-invite' || $action === 'user-link') {
        if ($action === 'user-invite') {
            $result = admin_invite((string) ($_POST['email'] ?? ''), $roles, $me['email'], (array) ($_POST['runs'] ?? []));
        } else {
            $user = admin_user($id);
            $result = match (true) {
                $user === null => 'Účet nenalezen.',
                $user['disabled_at'] !== null => 'Účet je vypnutý.',
                $user['password_hash'] !== null => 'Pozvánka už byla přijata.',
                default => ['token' => admin_new_invite($id), 'user' => $user],
            };
        }
        if (is_string($result)) {
            $_SESSION['flash'] = $result;
        } else {
            $user = $result['user'] ?? [];
            $mailed = send_admin_invite_email($user, $result['token'], $me['email']);
            $_SESSION['flash'] = ($action === 'user-invite' ? "Pozvánka pro {$user['email']} vytvořena." : "Nová pozvánka pro {$user['email']} vytvořena, původní odkaz přestal platit.")
                . ($mailed ? ' E-mail odeslán.' : (mail_enabled() ? ' E-mail se neodeslal (důvod je v logu) – zkuste „Poslat znovu“.' : ' E-maily jsou vypnuté (MAIL_ENABLED) – pozvánka nebyla dodaná.'));
        }
    } elseif ($action === 'user-roles') {
        $_SESSION['flash'] = admin_set_roles($id, $roles, $me['id']);
        if (str_starts_with($_SESSION['flash'], 'Role a termíny')) {
            save_user_runs($id, (array) ($_POST['runs'] ?? []));
        }
    } else {
        $_SESSION['flash'] = admin_set_disabled($id, $action === 'user-disable', $me['id']);
    }
    header('Location: admin.php?view=users');
    exit;
}

if ($canManage && $csrfOk && $action === 'deadline-save') {
    // Payment deadline by the time of booking: from <date> new reservations get <hours> to pay.
    $rules = [];
    $errors = [];
    foreach ((array) ($_POST['deadline_from'] ?? []) as $i => $from) {
        $from = trim((string) $from);
        $hours = trim((string) ($_POST['deadline_hours'][$i] ?? ''));
        if ($from === '' && $hours === '') {
            continue;
        }
        if (prague_time($from) === null || !ctype_digit($hours) || (int) $hours < 1 || (int) $hours > 720) {
            $errors[] = 'Splatnost ' . ($i + 1) . ': zadejte datum a počet hodin 1–720.';
            continue;
        }
        $rules[] = ['from' => iso_utc(prague_time($from)), 'hours' => (int) $hours]; // stored in UTC
    }
    if ($errors) {
        $_SESSION['flash'] = implode(' ', $errors);
    } else {
        save_payment_deadline_rules($rules);
        $_SESSION['flash'] = 'Splatnost uložena. Platí pro nové rezervace, už vytvořené si splatnost ponechají.';
    }
    header('Location: admin.php?view=settings');
    exit;
}

if ($canManage && $csrfOk && in_array($action, ['run-save', 'run-delete'], true)) {
    $id = (int) ($_POST['id'] ?? 0);
    if ($action === 'run-delete') {
        $_SESSION['flash'] = delete_run($id);
    } else {
        // Once a run has reservations, its start is fixed: customers booked for that time.
        // Only the label and the booking cut-off change (the test flag is fixed too).
        $existing = $id > 0 ? run_by_id($id) : null;
        $locked = $existing !== null && run_has_reservations($id);
        $label = trim((string) ($_POST['label'] ?? ''));
        $isTest = $locked ? $existing['is_test'] : isset($_POST['is_test']);
        $starts = $locked ? run_starts($existing) : prague_time((string) ($_POST['starts_at'] ?? ''));
        $closesRaw = trim((string) ($_POST['booking_closes_at'] ?? ''));
        $closes = $closesRaw === '' ? null : prague_time($closesRaw);
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
        if ($locked && isset($_POST['starts_at'])) {
            $errors[] = 'Představení už má rezervace – začátek nelze měnit.';
        }
        if ($errors) {
            $_SESSION['flash'] = implode(' ', $errors);
        } else {
            $values = [$label, db_time($starts), $closes ? db_time($closes) : null, (int) $isTest];
            if ($id > 0) {
                db()->prepare('UPDATE runs SET label = ?, starts_at = ?, booking_closes_at = ?, is_test = ? WHERE id = ?')
                    ->execute([...$values, $id]);
            } else {
                db()->prepare('INSERT INTO runs (label, starts_at, booking_closes_at, is_test) VALUES (?, ?, ?, ?)')
                    ->execute($values);
            }
            $_SESSION['flash'] = $id > 0 ? 'Termín uložen.' : 'Termín přidán.';
        }
    }
    header('Location: admin.php?view=settings');
    exit;
}

if ($canReservations && $csrfOk && in_array($action, ['payment', 'cancel', 'cancel-seats', 'ticket', 'email', 'refunded', 'onsite-checked'], true)) {
    $id = (int) ($_POST['id'] ?? 0);
    $now = db_time(now_utc());
    if ($action === 'onsite-checked') {
        $stmt = db()->prepare('UPDATE reservations SET payment_checked_at = ? WHERE id = ? AND sold_by IS NOT NULL AND payment_checked_at IS NULL');
        $stmt->execute([$now, $id]);
        $flash = $stmt->rowCount() ? 'Platba za prodej na místě ověřena.' : 'Nelze označit.';
    } elseif ($action === 'payment') {
        $flash = record_payment($id, (int) ($_POST['received'] ?? 0), isset($_POST['paid_before']) ? (int) $_POST['paid_before'] : null);
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
            $r = cancel_seats($id, $seats);
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

// Výpis z banky: upload a CSV statement → preview kept in the session → record the checked transfers.
if ($canReservations && $csrfOk && in_array($action, ['bank-upload', 'bank-apply', 'bank-clear'], true)) {
    if ($action === 'bank-upload') {
        $file = $_FILES['statement'] ?? null;
        $parsed = !is_array($file) || ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || !is_uploaded_file((string) $file['tmp_name'])
            ? (($file['error'] ?? null) === UPLOAD_ERR_INI_SIZE ? 'Soubor je příliš velký (nejvýš 2 MB).' : 'Vyberte soubor CSV s výpisem.')
            : parse_bank_csv((string) file_get_contents((string) $file['tmp_name'], false, null, 0, BANK_CSV_MAX_BYTES + 1));
        unset($_SESSION['bank_results']);
        if (is_string($parsed)) {
            $_SESSION['flash'] = $parsed;
        } else {
            $_SESSION['bank_import'] = ['file' => mb_substr(basename((string) $file['name']), 0, 100)] + $parsed;
        }
    } elseif ($action === 'bank-apply') {
        $rows = $_SESSION['bank_import']['rows'] ?? [];
        $results = [];
        foreach (array_unique(array_map('intval', (array) ($_POST['tx'] ?? []))) as $i) {
            if (isset($rows[$i])) {
                $results[] = ['tx' => $rows[$i]] + bank_record($rows[$i], $me['email']);
            }
        }
        $recorded = count(array_filter($results, static fn ($r) => $r['ok']));
        $_SESSION['bank_results'] = $results;
        $_SESSION['flash'] = $results ? "Zapsáno plateb: {$recorded} z " . count($results) . '.' : 'Nevybrali jste žádnou platbu.';
    } else {
        unset($_SESSION['bank_import'], $_SESSION['bank_results']);
    }
    header('Location: admin.php?view=bank');
    exit;
}

// Platby z e-mailu: sync the payment mailbox now, record / resolve an e-mail waiting for a person.
if ($canReservations && $csrfOk && in_array($action, ['payment-sync', 'payment-mail-record', 'payment-mail-resolve'], true)) {
    $mailId = (int) ($_POST['id'] ?? 0);
    if ($action === 'payment-sync') {
        $sync = payment_sync($me['email']);
        $_SESSION['flash'] = $sync['ok'] ? 'Synchronizace hotová: ' . payment_sync_summary($sync) . '.' : 'Synchronizace selhala: ' . $sync['error'];
    } elseif ($action === 'payment-mail-record') {
        $_SESSION['flash'] = payment_mail_record($mailId, $me['email'])['message'];
    } else {
        $_SESSION['flash'] = payment_mail_resolve($mailId, $me['email']) ? 'E-mail označen jako vyřízený.' : 'E-mail už je vyřízený.';
    }
    header('Location: admin.php?view=bank');
    exit;
}
$flash = $_SESSION['flash'] ?? null;

unset($_SESSION['flash']);

$statusLabels = ['pending' => 'Čeká na platbu', 'paid' => 'Zaplaceno', 'expired' => 'Propadlo', 'cancelled' => 'Zrušeno'];
?>
<!doctype html>
<html lang="cs">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Správa rezervací – Těšín zpívá gospel</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Bricolage+Grotesque:opsz,wght@12..96,500..800&family=Instrument+Sans:wght@400;500;600&display=swap" rel="stylesheet">
<style>
  /* Admin is desktop-first (accountant at a computer). Below 760 px: tables with class "cards" become one card
     per row with labels from data-label, the plan scrolls sideways. Brand palette of the customer app (src/styles.css). */
  :root { --ink:#140a24; --paper:#fbf6f1; --magenta:#e03cc8; --magenta-dark:#c42aae; --pink:#f07ad9; --violet:#6b2cf5; --lilac:#b79bff;
    --teal:#3fd9d0; --plum:#8a1f78; --pad-x:clamp(20px, 5vw, 64px); --display:'Bricolage Grotesque', sans-serif;
    --bg:var(--paper); --surface:#fff; --surface-2:#f3ebf4; --line:#efe6f1; --ink-2:#5a4f6b; --ink-3:#8a8099; --wall:#e2d6ea;
    /* white text needs the darker magenta (contrast 4.9:1; --magenta is only 3.7:1) */
    --accent:var(--magenta-dark); --accent-dark:var(--plum);
    --font:'Instrument Sans', system-ui, sans-serif; --font-display:var(--display); }
  * { box-sizing: border-box; }
  body { margin:0; background:var(--bg); color:var(--ink); font:15px/1.45 var(--font); -webkit-font-smoothing:antialiased; }
  main { max-width:1328px; margin:0 auto; padding:24px var(--pad-x) 64px; }
  :focus-visible { outline:2px solid var(--violet); outline-offset:2px; }
  input:focus, select:focus, textarea:focus { border-color:var(--lilac); }
  header { display:flex; justify-content:space-between; align-items:center; gap:16px; flex-wrap:wrap; margin-bottom:20px; }
  h1 { margin:0; font:700 1.5rem var(--font-display); }
  .card { background:var(--surface); border-radius:16px; box-shadow:0 1px 2px rgba(20,10,36,.05), 0 6px 20px rgba(20,10,36,.07); padding:16px; }
  .stats { display:flex; gap:12px; margin-bottom:16px; }
  .stats .card { padding:12px 16px; flex:1 1 0; min-width:0; }
  .stats strong { display:block; font:700 1.4rem var(--font-display); }
  form.filters { display:flex; gap:8px; flex-wrap:wrap; margin-bottom:16px; }
  input, select, button { font:inherit; padding:8px 12px; border-radius:10px; border:1px solid var(--wall); background:#fff; }
  button { cursor:pointer; background:var(--accent); color:#fff; border:0; font-weight:600; }
  button:not(.secondary):not(.danger):not(.link):hover { background:var(--accent-dark); }
  button.secondary, a.button.secondary { background:#fff; color:var(--ink); border:1px solid var(--wall); }
  a.button { display:inline-block; padding:8px 12px; border-radius:10px; font-weight:600; text-decoration:none; }
  button.danger { background:#fff; color:#8f2a20; border:1px solid #e49a8e; }
  .table { overflow-x:auto; }
  table { width:100%; border-collapse:collapse; }
  th, td { text-align:left; padding:10px 8px; border-bottom:1px solid var(--line); vertical-align:top; }
  th { font-size:.78rem; text-transform:uppercase; letter-spacing:.06em; color:var(--ink-2); }
  th a.sort { color:inherit; text-decoration:none; white-space:nowrap; }
  th a.sort:hover, th[aria-sort] a.sort { color:var(--ink); }
  .badge { display:inline-block; padding:2px 10px; border-radius:999px; font-size:.8rem; font-weight:600; white-space:nowrap; }
  .s-pending { background:#f9e4b7; color:#6d4a04; } .s-paid { background:#dcefd9; color:#1f5a2c; }
  .s-expired, .s-cancelled { background:var(--surface-2); color:var(--ink-2); }
  .seats { max-width:260px; font-size:.85rem; color:var(--ink-2); }
  .actions { display:flex; gap:6px; flex-wrap:wrap; } .actions form { margin:0; }
  .flash { margin-bottom:16px; padding:10px 14px; border-radius:10px; background:#dcefd9; color:#1f5a2c; }
  .flash.warn { background:#f9e4b7; color:#6d4a04; }
  .pay-form { display:flex; gap:6px; align-items:center; margin:0; }
  .pay-form input[type=number] { width:96px; padding:6px 8px; }
  details .pay-form { margin-top:6px; }
  .error { color:#8f2a20; }
  .bank-none td, .bank-done td { color:var(--ink-2); } .bank-done { opacity:.6; }
  .bank-results { margin:0; padding-left:20px; } .bank-results li { margin:4px 0; }
  .bank-actions { margin-top:12px; } td.num { white-space:nowrap; text-align:right; }
  .sync-status { margin:0; } .sync-status.warn { padding:8px 12px; border-radius:10px; background:#f9e4b7; color:#6d4a04; }
  .card.mails { margin-bottom:16px; } .mails h2 { margin:0 0 8px; font:700 1.15rem var(--font-display); }
  .mails tr:not(.mail-open) td { color:var(--ink-2); }
  .m-recorded { background:#dcefd9; color:#1f5a2c; } .m-review, .m-unrecognized { background:#f9e4b7; color:#6d4a04; }
  .m-rejected { background:#f6d5d1; color:#8f2a20; } .m-ignored { background:var(--surface-2); color:var(--ink-2); }
  .nowrap { white-space:nowrap; }
  pre.excerpt { white-space:pre-wrap; max-height:240px; overflow:auto; font-size:.78rem; background:var(--surface-2); padding:8px; border-radius:8px; }
  .tabs { display:flex; flex-wrap:wrap; gap:4px; padding:4px; border-radius:22px; background:var(--surface-2); margin-right:auto; }
  .tabs a { padding:6px 14px; border-radius:999px; color:var(--ink-2); text-decoration:none; font-weight:600; }
  .tabs a.active { background:var(--surface); color:var(--magenta-dark); box-shadow:0 1px 4px rgba(20,10,36,.12); }
  .edit-email summary { cursor:pointer; font-size:.78rem; color:var(--ink-2); margin-top:2px; }
  .edit-email form { display:flex; gap:6px; margin-top:6px; }
  .edit-email input { width:200px; padding:4px 8px; }
  .edit-email button { padding:4px 10px; }
  .overdue { color:#8f2a20; font-weight:600; }
  .seat-cancel { display:flex; flex-wrap:wrap; gap:4px 10px; margin-top:6px; align-items:center; max-width:260px; }
  .seat-cancel label { font-size:.8rem; white-space:nowrap; }
  .seat-cancel button { padding:4px 10px; }
  a.card { color:inherit; text-decoration:none; }
  .card.selected { outline:2px solid var(--magenta); }
  .settings-intro { margin-bottom:12px; max-width:760px; }
  .settings + .settings { margin-top:12px; }
  .hint.locked { padding:8px 12px; border-radius:10px; background:#f9e4b7; color:#6d4a04; font-weight:600; }
  input:disabled { background:var(--surface-2); color:var(--ink-2); }
  .card.settings { margin-bottom:16px; }
  .tab-alert { display:inline-block; min-width:1.4em; padding:0 6px; margin-left:4px; border-radius:999px; background:#c0392b; color:#fff; font-size:.75rem; text-align:center; }
  .me { color:var(--ink-2); font-size:.9rem; font-weight:600; max-width:200px; overflow:hidden; text-overflow:ellipsis; white-space:nowrap; }
  .login .hint { margin:-4px 0 0; }
  .section-title { margin:28px 0 6px; font:700 1.15rem var(--font-display); }
  .checks { display:flex; flex-wrap:wrap; gap:6px 16px; }
  .checks-title { flex:1 1 100%; }
  .check { display:inline-flex !important; flex-direction:row !important; align-items:center; gap:6px; font-weight:500 !important; color:var(--ink) !important; max-width:none !important; }
  .roles-edit { display:flex; flex-direction:column; align-items:flex-start; gap:6px; margin-top:6px; }
  .roles-edit .checks { flex-direction:column; gap:4px; }
  .edit-email .check input, .settings .check input { width:auto; }
  .card.attention { background:#f6cdc6; color:#7d2117; text-decoration:none; }
  .card.test-run { background:#dde9f8; color:#1d3f6b; }
  tr.test-run td { background:#eef4fc; }
  .run-test { color:#1d3f6b; font-weight:600; }
  .settings { max-width:640px; display:flex; flex-direction:column; gap:12px; }
  .settings h2 { margin:8px 0 0; font:700 1.15rem var(--font-display); }
  .settings label { display:flex; flex-direction:column; gap:4px; font-size:.8rem; font-weight:600; color:var(--ink-2); max-width:280px; }
  .settings .rules { display:flex; flex-direction:column; gap:8px; }
  .settings .rule { display:flex; gap:10px; flex-wrap:wrap; }
  .settings .rule input[type=number] { width:110px; }
  .settings button { align-self:flex-start; }
  .hint { margin:0; font-size:.85rem; color:var(--ink-2); }
  .plan-bar { display:flex; gap:16px; align-items:center; flex-wrap:wrap; margin-bottom:16px; position:sticky; top:8px; z-index:2; }
  .plan-legend { display:inline-flex; align-items:center; gap:6px; font-size:.85rem; color:var(--ink-2); margin-right:auto; white-space:nowrap; }
  .plan-legend i { display:inline-block; flex:none; width:14px; height:14px; border-radius:4px; margin-left:8px; }
  .plan-level { margin-bottom:16px; }
  .plan-level .section-title { margin:0 0 8px; }
  .plan-map { position:relative; width:100%; container-type:inline-size; }
  .plan-item { position:absolute; display:grid; place-items:center; font-size:.75rem; font-weight:600; letter-spacing:.08em; text-transform:uppercase; color:var(--ink-2); }
  .plan-stage { background:var(--surface-2); border-radius:0 0 999px 999px / 0 0 60% 60%; }
  .plan-band { border:1.5px dashed var(--wall); border-radius:8px; }
  .plan-void { border:2px solid var(--wall); border-top:0; border-radius:0 0 50% 50% / 0 0 45% 45%; }
  .plan-name { position:absolute; display:flex; flex-direction:column; align-items:center; transform:translate(-50%, -30%); font-size:.8rem; line-height:1.2; white-space:nowrap; }
  .plan-name[data-align=left] { align-items:flex-start; transform:translate(0, -30%); }
  .plan-name[data-align=right] { align-items:flex-end; transform:translate(-100%, -30%); }
  .plan-name .link { background:none; border:0; padding:0; color:var(--violet); font-weight:500; font-size:.75rem; text-decoration:underline; }
  .ps { position:absolute; width:2.2%; aspect-ratio:1; transform:translate(-50%, -50%); cursor:pointer; }
  .ps input { position:absolute; opacity:0; pointer-events:none; }
  .ps span { display:grid; place-items:center; width:100%; height:100%; border-radius:5px; font-size:max(9px, .9cqi); font-weight:600; }
  .ps span, .ps-free { background:#d8f0d9; border:1px solid #a4d4a8; color:#24572f; }
  .ps input:checked + span, .ps-reserve { background:#d9d8dc; border:1px dashed #8e8b94; color:#5e5b64; }
  .ps-held span, i.ps-held { background:#f6d6ef; border:1px solid var(--pink); color:var(--plum); }
  .ps-held { cursor:not-allowed; }
  .ps input:focus-visible + span { outline:2px solid var(--violet); outline-offset:1px; }
  .settings label.wide { max-width:none; }
  textarea { font:inherit; padding:8px 12px; border-radius:10px; border:1px solid var(--wall); background:#fff; resize:vertical; }
  .code { font-family:ui-monospace, monospace; letter-spacing:.05em; white-space:nowrap; }
  .kind { display:inline-block; padding:0 6px; border-radius:6px; background:var(--surface-2); font-size:.72rem; color:var(--ink-2); }
  .kind.child { background:#dfe9f5; color:#1f3f6b; } .kind.free { background:#dcefd9; color:#1f5a2c; }
  @media (max-width: 760px) {
    main { padding-top:12px; padding-bottom:48px; }
    header { gap:8px 12px; margin-bottom:14px; }
    h1 { font-size:1.25rem; }
    .me { order:2; margin-left:auto; max-width:50vw; }
    header > form { order:2; }
    .tabs { order:3; width:100%; margin-right:0; border-radius:16px; }
    .tabs a { flex:1 1 auto; padding:8px 10px; text-align:center; white-space:nowrap; }
    .stats { flex-wrap:wrap; gap:8px; }
    .stats .card { flex:1 1 calc(50% - 4px); padding:10px 12px; font-size:.85rem; }
    .stats strong { font-size:1.2rem; }
    form.filters > select, form.filters > input[type=search] { flex:1 1 100%; }
    form.filters > button { flex:1 1 100%; }
    .card.table { padding:0; background:none; box-shadow:none; overflow:visible; }
    table.cards, table.cards tbody, table.cards tr, table.cards td { display:block; width:100%; }
    table.cards thead { display:none; }
    table.cards thead.sortable { display:block; margin-bottom:8px; }
    table.cards thead.sortable tr { display:flex; flex-wrap:wrap; gap:6px; }
    table.cards thead.sortable th { padding:4px 10px; border:0; border-radius:999px; background:var(--surface); font-size:.72rem; }
    table.cards thead.sortable th:empty { display:none; }
    table.cards tr { margin-bottom:10px; padding:8px 14px; border-radius:16px; background:var(--surface); box-shadow:0 1px 2px rgba(20,10,36,.05), 0 6px 20px rgba(20,10,36,.07); }
    table.cards tr.test-run, tr.test-run td { background:#eef4fc; }
    table.cards td { padding:6px 0; border-bottom:1px solid var(--line); max-width:none; }
    table.cards td:last-child { border-bottom:0; }
    table.cards td:empty { display:none; }
    table.cards td[data-label]::before { content:attr(data-label); display:inline-block; min-width:6.5em; margin-right:8px; font-size:.72rem; font-weight:600; text-transform:uppercase; letter-spacing:.06em; color:var(--ink-2); }
    table.cards td.num { text-align:left; }
    .seat-cancel { max-width:none; }
    .edit-email form { flex-wrap:wrap; }
    .edit-email input { flex:1 1 160px; width:auto; }
    .actions button, .actions a.button { padding:8px 14px; }
    .plan-bar { position:static; gap:8px 12px; }
    .plan-level { overflow-x:auto; }
    .plan-map { min-width:720px; }
    .settings label, .settings .rule label { max-width:none; flex:1 1 100%; }
    .settings .rule input[type=number] { width:100%; }
    .checks { flex-direction:column; gap:8px; }
    .check { flex:none !important; align-items:flex-start !important; }
    .check input { flex:none; margin-top:3px; }
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
      <p>Dobrý den, vytvořte si heslo – tím vznikne Váš účet
        (role: <?= $h(implode(', ', array_map(static fn ($r) => USER_ROLES[$r], user_roles($invited)))) ?>).
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
<?php elseif ($resetToken !== ''): ?>
  <form class="card login" method="post">
    <h1>Nové heslo</h1>
    <?php if ($resetUser): ?>
      <p>Účet <strong><?= $h($resetUser['email']) ?></strong>. Zadejte nové heslo.</p>
      <input type="hidden" name="csrf" value="<?= $h($_SESSION['csrf']) ?>">
      <input type="hidden" name="action" value="reset-password">
      <input type="email" value="<?= $h($resetUser['email']) ?>" autocomplete="username" readonly hidden>
      <input type="password" name="password" placeholder="Nové heslo (alespoň <?= ADMIN_PASSWORD_MIN ?> znaků)" minlength="<?= ADMIN_PASSWORD_MIN ?>" autocomplete="new-password" autofocus required>
      <input type="password" name="password_again" placeholder="Heslo znovu" autocomplete="new-password" required>
      <?php if ($resetError): ?><div class="error"><?= $h($resetError) ?></div><?php endif ?>
      <button>Uložit heslo a přihlásit</button>
    <?php else: ?>
      <p class="error">Odkaz neplatí nebo vypršel. Požádejte o nový.</p>
      <a href="admin.php?zapomenute">Zapomněl jsem heslo</a>
    <?php endif ?>
  </form>
<?php elseif ($forgot && $me === null): ?>
  <form class="card login" method="post">
    <h1>Zapomenuté heslo</h1>
    <?php if ($forgotDone): ?>
      <p>Pokud e-mail u nás má účet, poslali jsme na něj odkaz na nové heslo. Platí <?= PASSWORD_RESET_MINUTES ?> minut.</p>
    <?php else: ?>
      <p class="hint">Zadejte e-mail, kterým se přihlašujete. Pošleme na něj odkaz na nové heslo.</p>
      <input type="hidden" name="csrf" value="<?= $h($_SESSION['csrf']) ?>">
      <input type="hidden" name="action" value="forgot">
      <input type="email" name="email" placeholder="E-mail" autocomplete="username" autofocus required>
      <button>Poslat odkaz</button>
    <?php endif ?>
    <a href="admin.php">Zpět na přihlášení</a>
  </form>
<?php elseif ($me === null): ?>
  <form class="card login" method="post">
    <h1>Správa rezervací</h1>
    <input type="hidden" name="csrf" value="<?= $h($_SESSION['csrf']) ?>">
    <input type="hidden" name="action" value="login">
    <input type="email" name="email" placeholder="E-mail" value="<?= $h($loginEmail ?? '') ?>" autocomplete="username" autofocus required>
    <input type="password" name="password" placeholder="Heslo" autocomplete="current-password" required>
    <?php if (!empty($loginError)): ?><div class="error"><?= $h($loginError) ?></div><?php endif ?>
    <button>Přihlásit</button>
    <a href="admin.php?zapomenute">Zapomněl jsem heslo</a>
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
    } elseif ($status === 'onsite') {
        $where[] = "sold_by IS NOT NULL AND status = 'paid' AND payment_checked_at IS NULL";
    }
    if ($runFilter) {
        $where[] = 'run_id = ?';
        $params[] = $runFilter;
    }
    if ($q !== '') {
        // A seat id matches exactly (LH-1-1 not LH-1-10); a prefix like "LH-1" lists the whole row.
        $where[] = '(specific_symbol LIKE ? OR email LIKE ? OR last_name LIKE ? OR first_name LIKE ?
            OR EXISTS (SELECT 1 FROM reservation_items i WHERE i.reservation_id = reservations.id AND (i.seat_id = ? OR i.seat_id LIKE ?)))';
        array_push($params, ...array_fill(0, 4, '%' . $q . '%'));
        array_push($params, $q, $q . '-%');
    }
    $seatCountSql = '(SELECT COUNT(*) FROM reservation_items i WHERE i.reservation_id = reservations.id AND i.cancelled_at IS NULL)';
    $sortColumns = [
        'ss' => 'specific_symbol', 'run' => '(SELECT starts_at FROM runs WHERE runs.id = reservations.run_id)',
        'name' => 'last_name, first_name', 'seats' => $seatCountSql, 'amount' => 'amount', 'status' => 'status',
        'created' => 'created_at', 'expires' => 'expires_at',
    ];
    $sort = isset($sortColumns[$_GET['sort'] ?? '']) ? (string) $_GET['sort'] : '';
    $dir = ($_GET['dir'] ?? '') === 'desc' ? 'desc' : 'asc';
    $orderBy = $sort !== ''
        ? implode(', ', array_map(static fn ($c) => $c . ' ' . strtoupper($dir), explode(', ', $sortColumns[$sort]))) . ', id DESC'
        : 'id DESC';
    $sql = 'SELECT * FROM reservations' . ($where ? ' WHERE ' . implode(' AND ', $where) : '') . ' ORDER BY ' . $orderBy . ' LIMIT 500';
    $stmt = db()->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll();
    $rowItems = load_reservation_items(array_column($rows, 'id'));
    $rows = array_map(static fn ($r) => $r + ['items' => $rowItems[(int) $r['id']] ?? []], $rows);
    $totals = db_query(
        'SELECT status, COUNT(*) n, SUM(seats) seats, SUM(amount) amount
         FROM (SELECT status, amount, ' . $seatCountSql . ' seats FROM reservations WHERE ' . NOT_TEST_RUN_SQL . ') t GROUP BY status'
    )->fetchAll(PDO::FETCH_UNIQUE);
    $prague = new DateTimeZone('Europe/Prague');
    $fmt = static fn ($t) => $t ? new DateTimeImmutable($t, new DateTimeZone('UTC'))->setTimezone($prague)->format('j. n. Y H:i') : '';
    $kc = static fn ($n) => number_format((int) $n, 0, ',', ' ') . ' Kč';
    $reservationsCount = static fn ($n) => (int) $n . ((int) $n >= 1 && (int) $n <= 4 ? ' rezervace' : ' rezervací');
?>
  <header>
    <h1>Správa TZG</h1>
    <nav class="tabs">
      <a href="admin.php" class="<?= $view === 'reservations' ? 'active' : '' ?>">Rezervace</a>
      <?php $mailAttention = payment_mails_attention(); ?>
      <a href="admin.php?view=bank" class="<?= $view === 'bank' ? 'active' : '' ?>">Výpis z banky<?= $mailAttention ? ' <span class="tab-alert" title="E-maily z banky ke kontrole">' . $mailAttention . '</span>' : '' ?></a>
      <?php if ($canManage): ?>
        <a href="admin.php?view=plan" class="<?= $view === 'plan' ? 'active' : '' ?>">Plánek</a>
        <a href="admin.php?view=codes" class="<?= $view === 'codes' ? 'active' : '' ?>">Slevové kódy</a>
        <?php $conflictCount = (int) db_query('SELECT COUNT(*) FROM scan_conflicts')->fetchColumn(); ?>
        <a href="admin.php?view=users" class="<?= $view === 'users' ? 'active' : '' ?>">Uživatelé<?= $conflictCount ? ' <span class="tab-alert" title="Konflikty z odbavení bez spojení">' . $conflictCount . '</span>' : '' ?></a>
        <a href="admin.php?view=settings" class="<?= $view === 'settings' ? 'active' : '' ?>">Nastavení</a>
      <?php endif ?>
      <?php if ($canScan): ?><a href="<?= $h($scannerUrl) ?>">Odbavení</a><?php endif ?>
    </nav>
    <span class="me" title="<?= $h('Přihlášen: ' . $me['email'] . ' · ' . implode(', ', array_map(static fn ($r) => USER_ROLES[$r], $me['roles']))) ?>"><?= $h($me['email']) ?></span>
    <form method="post">
      <input type="hidden" name="csrf" value="<?= $h($_SESSION['csrf']) ?>">
      <input type="hidden" name="action" value="logout">
      <button class="secondary">Odhlásit</button>
    </form>
  </header>

  <?php if ($flash): ?><div class="flash"><?= $h($flash) ?></div><?php endif ?>

  <?php if ($view === 'plan'):
      $planRuns = array_values(runs());
      $planRun = run_by_id((int) ($_GET['run'] ?? 0)) ?? ($planRuns[0] ?? null);
  ?>
  <p class="hint settings-intro">Klikem přepnete místo mezi <strong>v prodeji</strong> a <strong>záložní</strong> (zákazníci ho vidí,
    ale nemohou ho koupit). Obsazená místa nelze přepnout. Nový termín začíná s výchozím
    rozložením (záložní řady na balkoně).</p>
  <?php if ($planRun === null): ?>
    <p class="card hint">Nejdřív přidejte termín v Nastavení.</p>
  <?php else:
      $heldStmt = db()->prepare('SELECT seat_id FROM reservation_seats WHERE run_id = ?');
      $heldStmt->execute([$planRun['id']]);
      $planHeld = array_flip($heldStmt->fetchAll(PDO::FETCH_COLUMN));
      $planReserve = array_flip($planRun['reserve_seats']);
  ?>
  <form class="filters" method="get">
    <input type="hidden" name="view" value="plan">
    <select name="run" onchange="this.form.submit()">
      <?php foreach ($planRuns as $run): ?><option value="<?= (int) $run['id'] ?>" <?= $planRun['id'] === $run['id'] ? 'selected' : '' ?>><?= $h(run_label($run)) ?></option><?php endforeach ?>
    </select>
  </form>
  <form method="post" class="plan-form">
    <input type="hidden" name="csrf" value="<?= $h($_SESSION['csrf']) ?>">
    <input type="hidden" name="run_id" value="<?= (int) $planRun['id'] ?>">
    <div class="plan-bar card">
      <span>V prodeji <strong id="plan-sale"><?= run_capacity($planRun) ?></strong> · záložních <strong id="plan-reserve"><?= count($planRun['reserve_seats']) ?></strong> · obsazených <?= count($planHeld) ?></span>
      <span class="plan-legend"><i class="ps-free"></i> v prodeji <i class="ps-reserve"></i> záložní <i class="ps-held"></i> obsazené</span>
      <button name="action" value="plan-save">Uložit plánek</button>
      <button class="secondary" name="action" value="plan-default" onclick="return confirm('Vrátit výchozí rozložení záložních míst?')">Výchozí rozložení</button>
    </div>
    <?php foreach (PLAN as $level => $plan): ?>
      <div class="card plan-level">
        <h2 class="section-title"><?= $h(LEVELS[$level]) ?></h2>
        <div class="plan-map" style="aspect-ratio: 100 / <?= $plan['height'] ?>">
          <?php foreach ($plan['items'] as $item): ?>
            <div class="plan-item plan-<?= $h($item['kind']) ?>" style="left:<?= $item['x'] ?>%;top:<?= $item['y'] / $plan['height'] * 100 ?>%;width:<?= $item['w'] ?>%;height:<?= $item['h'] / $plan['height'] * 100 ?>%"><?= $h($item['label']) ?></div>
          <?php endforeach ?>
          <?php foreach (SECTIONS as $sid => $def): if ($def['level'] !== $level) { continue; } ?>
            <div class="plan-name" style="left:<?= $def['label'][0] ?>%;top:<?= $def['label'][1] / $plan['height'] * 100 ?>%" data-align="<?= $h($def['label'][2]) ?>">
              <strong><?= $h($def['short']) ?></strong>
              <button type="button" class="link" data-section="<?= $h($sid) ?>" data-to="0">vše v prodeji</button>
              <button type="button" class="link" data-section="<?= $h($sid) ?>" data-to="1">vše záložní</button>
            </div>
            <?php foreach (section_rows($def) as $r => $row):
                $seat = 0;
                foreach ($row['parts'] as $part):
                    for ($i = 0; $i < $part[0]; $i++):
                        $seat++;
                        $t = $part[0] > 1 ? $i / ($part[0] - 1) : 0;
                        $x = $part[1] + ($part[3] - $part[1]) * $t;
                        $y = ($part[2] + ($part[4] - $part[2]) * $t) / $plan['height'] * 100;
                        $seatId = $sid . '-' . ($r + 1) . '-' . $seat;
                        $isHeld = isset($planHeld[$seatId]); ?>
              <label class="ps <?= $isHeld ? 'ps-held' : '' ?>" style="left:<?= $x ?>%;top:<?= $y ?>%" title="<?= $h(seat_label($seatId)) ?>">
                <input type="checkbox" name="reserve[]" value="<?= $h($seatId) ?>" data-section="<?= $h($sid) ?>" <?= isset($planReserve[$seatId]) ? 'checked' : '' ?> <?= $isHeld ? 'disabled' : '' ?>><span><?= $seat ?></span>
              </label>
            <?php endfor; endforeach; endforeach; endforeach ?>
        </div>
      </div>
    <?php endforeach ?>
  </form>
  <script>
    (() => {
      const form = document.querySelector('.plan-form');
      const boxes = [...form.querySelectorAll('input[name="reserve[]"]')];
      const count = () => {
        const reserve = boxes.filter((b) => b.checked).length;
        document.getElementById('plan-reserve').textContent = reserve;
        document.getElementById('plan-sale').textContent = boxes.length - reserve;
      };
      form.addEventListener('change', count);
      form.querySelectorAll('button[data-section]').forEach((button) => button.addEventListener('click', () => {
        boxes.filter((b) => b.dataset.section === button.dataset.section && !b.disabled).forEach((b) => { b.checked = button.dataset.to === '1'; });
        count();
      }));
    })();
  </script>
  <?php endif ?>

  <?php elseif ($view === 'bank'):
      $bankImport = $_SESSION['bank_import'] ?? null;
      $bankResults = $_SESSION['bank_results'] ?? [];
      $cents = static fn (int $c) => number_format($c / 100, $c % 100 ? 2 : 0, ',', ' ') . ' Kč';
      $txLabel = static fn (array $tx) => ($tx['date'] ? new DateTimeImmutable($tx['date'])->format('j. n. Y') . ' · ' : '')
          . $cents($tx['cents']) . ($tx['name'] !== '' ? ' · ' . $tx['name'] : '');
  ?>
  <?php
      $sync = payment_sync_state();
      $syncAt = static fn (array $r) => format_prague(db_time(new DateTimeImmutable($r['at'])));
      $syncStale = $sync['auto'] === null || new DateTimeImmutable($sync['auto']['at']) < now_utc()->modify('-' . PAYMENT_SYNC_STALE_MINUTES . ' minutes');
      $mails = db_query(
          "SELECT m.*, r.specific_symbol, r.first_name, r.last_name, r.status AS reservation_status FROM payment_mails m
           LEFT JOIN reservations r ON r.id = m.reservation_id
           ORDER BY (m.status IN ('" . implode("','", PAYMENT_MAIL_ATTENTION) . "') AND m.resolved_at IS NULL) DESC, m.received_at DESC, m.id DESC LIMIT 30"
      )->fetchAll();
  ?>
  <div class="card settings sync">
    <h2>Platby z e-mailu banky</h2>
    <?php if (!payment_sync_enabled()): ?>
      <p class="hint">Automatické načítání plateb je vypnuté – v konfiguraci chybí schránka s oznámeními banky (PAYMENT_IMAP_HOST).</p>
    <?php else: ?>
      <p class="hint">Banka posílá e-mail o každém pohybu na účtu. Každých 5 minut se nové e-maily načtou a příchozí platby se zapíší
        k rezervacím podle SS (jako u výpisu). Co nejde zapsat automaticky, čeká níže na kontrolu.</p>
      <?php if ($sync['auto'] === null): ?>
        <p class="sync-status warn">Automatická synchronizace ještě neproběhla – zkontrolujte, že cron spouští api/cron.php.</p>
      <?php else: ?>
        <p class="sync-status<?= !$sync['auto']['ok'] || $syncStale ? ' warn' : '' ?>">Poslední automatická synchronizace:
          <strong><?= $h($syncAt($sync['auto'])) ?></strong> – <?= $h(payment_sync_summary($sync['auto'])) ?>
          <?php if ($syncStale): ?><br>Proběhla před více než <?= PAYMENT_SYNC_STALE_MINUTES ?> minutami – zkontrolujte, že cron běží.<?php endif ?></p>
      <?php endif ?>
      <?php if ($sync['last'] !== null && $sync['last']['by'] !== 'cron'): ?>
        <p class="hint">Naposledy spuštěno ručně: <?= $h($syncAt($sync['last'])) ?> (<?= $h($sync['last']['by']) ?>) – <?= $h(payment_sync_summary($sync['last'])) ?></p>
      <?php endif ?>
      <form method="post">
        <input type="hidden" name="csrf" value="<?= $h($_SESSION['csrf']) ?>">
        <button name="action" value="payment-sync">Synchronizovat nyní</button>
      </form>
    <?php endif ?>
  </div>

  <?php if ($mails): ?>
  <div class="card table mails">
    <h2>E-maily z banky</h2>
    <table class="cards">
      <thead><tr><th>Přijato</th><th>Platba</th><th>Rezervace</th><th>Stav</th><th>Co se stalo</th><th></th></tr></thead>
      <tbody>
      <?php foreach ($mails as $m):
          $tx = $m['tx'] !== null ? (array) json_decode($m['tx'], true) : null;
          $open = in_array($m['status'], PAYMENT_MAIL_ATTENTION, true) && $m['resolved_at'] === null; ?>
        <tr class="mail-<?= $h($m['status']) ?><?= $open ? ' mail-open' : '' ?>">
          <td data-label="Přijato"><?= $h(format_prague($m['received_at'])) ?><br><small><?= $h($m['subject']) ?></small></td>
          <td data-label="Platba"><?php if ($tx): ?>
              <strong><?= $h($cents((int) $tx['cents'])) ?></strong>
              <br><span class="nowrap"><small>VS</small> <?= $h($tx['vs'] ?: '–') ?> · <small>SS</small> <?= $h($tx['ss'] ?: '–') ?></span>
              <?php if ($tx['name'] !== ''): ?><br><small><?= $h($tx['name']) ?></small><?php endif ?>
              <?php if ($tx['account'] !== ''): ?><br><small class="nowrap"><?= $h($tx['account']) ?></small><?php endif ?>
              <?php if ($tx['message'] !== ''): ?><br><small><?= $h($tx['message']) ?></small><?php endif ?>
            <?php else: ?><small><?= $h($m['sender']) ?></small><?php endif ?></td>
          <td data-label="Rezervace"><?php if ($m['specific_symbol'] !== null): ?>
              <a href="admin.php?q=<?= $h($m['specific_symbol']) ?>">SS <?= $h($m['specific_symbol']) ?></a>
              <br><?= $h($m['first_name'] . ' ' . $m['last_name']) ?>
              <br><span class="badge s-<?= $h($m['reservation_status']) ?>"><?= $h($statusLabels[$m['reservation_status']]) ?></span>
            <?php else: ?>–<?php endif ?></td>
          <td data-label="Stav"><span class="badge m-<?= $h($m['status']) ?>"><?= $h(PAYMENT_MAIL_STATUSES[$m['status']]) ?></span>
            <?php if ($m['resolved_at'] !== null && $m['status'] !== 'recorded'): ?><br><small>vyřízeno (<?= $h($m['resolved_by']) ?>)</small><?php endif ?></td>
          <td data-label="Co se stalo"><small><?= $h($m['note']) ?></small>
            <?php if ($m['excerpt'] !== null && $tx === null): ?>
              <details><summary>Text e-mailu</summary><pre class="excerpt"><?= $h($m['excerpt']) ?></pre></details>
            <?php endif ?></td>
          <td data-label=""><?php if ($open): ?>
            <form method="post" class="actions">
              <input type="hidden" name="csrf" value="<?= $h($_SESSION['csrf']) ?>">
              <input type="hidden" name="id" value="<?= (int) $m['id'] ?>">
              <?php if ($m['status'] === 'review' && $m['reservation_id'] !== null): ?>
                <button name="action" value="payment-mail-record" onclick="return confirm('Zapsat tuto platbu k rezervaci? Pokud jste ji už zapsali ručně, nezapisujte ji znovu.')">Zapsat platbu</button>
              <?php endif ?>
              <button class="secondary" name="action" value="payment-mail-resolve">Vyřízeno</button>
            </form>
          <?php endif ?></td>
        </tr>
      <?php endforeach ?>
      </tbody>
    </table>
  </div>
  <?php endif ?>

  <form class="card settings" method="post" enctype="multipart/form-data">
    <input type="hidden" name="csrf" value="<?= $h($_SESSION['csrf']) ?>">
    <h2>Nahrát výpis z banky</h2>
    <p class="hint">Výpis z internetového bankovnictví ve formátu CSV (KB MojeBanka: Historie transakcí → Export → CSV).
      Příchozí platby se spárují s rezervacemi podle specifického symbolu (SS); když nesedí, hledá se číslo rezervace ve VS
      a ve zprávě pro příjemce. Nejdřív uvidíte náhled, zapíší se jen zaškrtnuté platby. </p>
    <label class="wide">Soubor CSV <input type="file" name="statement" accept=".csv,text/csv" required></label>
    <button name="action" value="bank-upload">Načíst výpis</button>
  </form>

  <?php if ($bankResults): ?>
    <div class="card">
      <h2>Výsledek zápisu</h2>
      <ul class="bank-results">
        <?php foreach ($bankResults as $res): ?>
          <li class="<?= $res['ok'] ? '' : 'error' ?>"><strong><?= $h($txLabel($res['tx'])) ?>:</strong> <?= $h($res['message']) ?></li>
        <?php endforeach ?>
      </ul>
    </div>
  <?php endif ?>

  <?php if ($bankImport):
      $bankRows = array_map(static fn ($tx) => ['tx' => $tx] + bank_match($tx), $bankImport['rows']);
      $bankCount = static fn (string $state) => count(array_filter($bankRows, static fn ($b) => $b['state'] === $state));
  ?>
  <div class="stats">
    <div class="card">Příchozích plateb<strong><?= count($bankRows) ?></strong><?= $h($bankImport['file']) ?><?= $bankImport['outgoing'] ? ' · odchozí přeskočeny: ' . (int) $bankImport['outgoing'] : '' ?></div>
    <div class="card">Spárováno<strong><?= $bankCount('match') ?></strong>lze zapsat</div>
    <div class="card">Nespárováno<strong><?= $bankCount('none') ?></strong>zapište ručně</div>
    <div class="card">Už zapsáno<strong><?= $bankCount('done') ?></strong>přeskočí se</div>
  </div>
  <form class="card table" method="post">
    <input type="hidden" name="csrf" value="<?= $h($_SESSION['csrf']) ?>">
    <table class="cards">
      <thead><tr><th></th><th>Datum</th><th>Plátce</th><th>Částka</th><th>VS / SS</th><th>Zpráva</th><th>Rezervace</th><th>Co se stane</th></tr></thead>
      <tbody>
      <?php foreach ($bankRows as $i => $b): $tx = $b['tx']; $br = $b['reservation']; ?>
        <tr class="bank-<?= $h($b['state']) ?>">
          <td<?= $b['state'] === 'match' ? ' data-label="Zapsat"' : '' ?>><?php if ($b['state'] === 'match'): ?><input type="checkbox" name="tx[]" value="<?= (int) $i ?>" aria-label="Zapsat platbu" <?= $b['select'] ? 'checked' : '' ?>><?php endif ?></td>
          <td data-label="Datum"><?= $tx['date'] ? $h(new DateTimeImmutable($tx['date'])->format('j. n. Y')) : '–' ?></td>
          <td data-label="Plátce"><?= $h($tx['name']) ?><?= $tx['account'] !== '' ? '<br><small>' . $h($tx['account']) . '</small>' : '' ?></td>
          <td class="num" data-label="Částka"><?= $h($cents($tx['cents'])) ?></td>
          <td data-label="VS / SS"><small>VS</small> <?= $h($tx['vs'] ?: '–') ?><br><small>SS</small> <?= $h($tx['ss'] ?: '–') ?></td>
          <td data-label="Zpráva"><small><?= $h($tx['message']) ?></small></td>
          <td data-label="Rezervace"><?php if ($br): ?>
              <a href="admin.php?q=<?= $h($br['specific_symbol']) ?>">SS <?= $h($br['specific_symbol']) ?></a>
              <br><?= $h($br['first_name'] . ' ' . $br['last_name']) ?>
              <br><span class="badge s-<?= $h($br['status']) ?>"><?= $h($statusLabels[$br['status']]) ?></span>
              <small><?= $h($kc($br['amount'])) ?></small>
            <?php else: ?>–<?php endif ?></td>
          <td data-label="Co se stane"><small><?= $h($b['note']) ?></small></td>
        </tr>
      <?php endforeach ?>
      <?php if (!$bankRows): ?><tr><td colspan="8">Ve výpisu nejsou žádné příchozí platby.</td></tr><?php endif ?>
      </tbody>
    </table>
    <div class="actions bank-actions">
      <?php if ($bankCount('match')): ?><button name="action" value="bank-apply">Zapsat vybrané platby</button><?php endif ?>
      <button class="secondary" name="action" value="bank-clear">Zavřít výpis</button>
    </div>
  </form>
  <?php endif ?>

  <?php elseif ($view === 'codes'):
      $q = trim((string) ($_GET['q'] ?? ''));
      $codeSearch = normalize_discount_code($q);
      $stmt = db()->prepare(
          'SELECT c.*, r.specific_symbol, r.run_id FROM discount_codes c LEFT JOIN reservations r ON r.id = c.reservation_id'
          . ($q !== '' ? ' WHERE c.email LIKE ? OR c.code LIKE ? OR r.specific_symbol LIKE ?' : '')
          . ' ORDER BY c.id DESC LIMIT 1000'
      );
      $stmt->execute($q !== '' ? ['%' . $q . '%', '%' . ($codeSearch !== '' ? $codeSearch : $q) . '%', '%' . $q . '%'] : []);
      $codes = $stmt->fetchAll();
      $codeTotals = db_query('SELECT COUNT(*) n, COUNT(used_at) used, SUM(used_at IS NULL AND revoked_at IS NULL) open FROM discount_codes')->fetch();
  ?>
  <div class="stats">
    <div class="card">Kódů celkem<strong><?= (int) $codeTotals['n'] ?></strong>rozesláno</div>
    <div class="card">Použito<strong><?= (int) $codeTotals['used'] ?></strong>míst zdarma</div>
    <div class="card">Nepoužito<strong><?= (int) $codeTotals['open'] ?></strong>lze ještě uplatnit</div>
  </div>

  <form class="card settings" method="post">
    <input type="hidden" name="csrf" value="<?= $h($_SESSION['csrf']) ?>">
    <h2>Rozeslat slevové kódy</h2>
    <p class="hint">Každý zadaný e-mail dostane jeden kód. Kdo má dostat víc kódů, zadejte jeho e-mail vícekrát.
      Kód platí na libovolný termín, jednou, na jedno místo. Při stornu se za místo zdarma nic nevrací a kód zůstává použitý.</p>
    <label class="wide">E-maily (každý na nový řádek, nebo oddělené čárkou)
      <textarea name="emails" rows="6" required placeholder="jana@example.cz&#10;petr@example.cz&#10;petr@example.cz"></textarea></label>
    <button name="action" value="code-create">Vytvořit a poslat kódy</button>
  </form>

  <form class="filters" method="get">
    <input type="hidden" name="view" value="codes">
    <input type="search" name="q" value="<?= $h($q) ?>" placeholder="E-mail, kód nebo SS">
    <button class="secondary">Hledat</button>
  </form>

  <div class="card table">
    <table class="cards">
      <thead><tr><th>Kód</th><th>E-mail</th><th>Vytvořeno</th><th>Odesláno</th><th>Stav</th><th></th></tr></thead>
      <tbody>
      <?php foreach ($codes as $c): ?>
        <tr>
          <td data-label="Kód"><strong class="code"><?= $h(format_discount_code($c['code'])) ?></strong></td>
          <td data-label="E-mail"><?= $h($c['email']) ?: '<small>smazáno</small>' ?></td>
          <td data-label="Vytvořeno"><?= $h($fmt($c['created_at'])) ?><?= $c['created_by'] !== '' ? '<br><small>' . $h($c['created_by']) . '</small>' : '' ?></td>
          <td data-label="Odesláno"><?= $h($fmt($c['emailed_at'])) ?: '–' ?></td>
          <td data-label="Stav"><?php if ($c['used_at']): ?>
              <span class="badge s-paid">Použitý</span><br><small><?= $h($fmt($c['used_at'])) ?> ·
                <?php if ($c['specific_symbol'] !== null): ?><a href="admin.php?q=<?= $h($c['specific_symbol']) ?>">SS <?= $h($c['specific_symbol']) ?></a><?php endif ?>
                <?= ($cr = run_by_id((int) $c['run_id'])) ? '· ' . $h(run_label($cr)) : '' ?>
                <?= $c['seat_id'] ? '· ' . $h($c['seat_id']) : '' ?></small>
            <?php elseif ($c['revoked_at']): ?>
              <span class="badge s-cancelled">Zrušený</span><br><small><?= $h($fmt($c['revoked_at'])) ?></small>
            <?php else: ?>
              <span class="badge s-pending">Nepoužitý</span>
            <?php endif ?></td>
          <td>
            <?php if (!$c['used_at'] && !$c['revoked_at']): ?>
              <div class="actions">
                <form method="post">
                  <input type="hidden" name="csrf" value="<?= $h($_SESSION['csrf']) ?>">
                  <input type="hidden" name="id" value="<?= (int) $c['id'] ?>">
                  <button class="secondary" name="action" value="code-resend">Poslat znovu</button>
                </form>
                <form method="post" onsubmit="return confirm('Zrušit kód? Už ho nepůjde použít.')">
                  <input type="hidden" name="csrf" value="<?= $h($_SESSION['csrf']) ?>">
                  <input type="hidden" name="id" value="<?= (int) $c['id'] ?>">
                  <button class="danger" name="action" value="code-revoke">Zrušit</button>
                </form>
              </div>
            <?php endif ?>
          </td>
        </tr>
      <?php endforeach ?>
      <?php if (!$codes): ?><tr><td colspan="6">Žádné slevové kódy.</td></tr><?php endif ?>
      </tbody>
    </table>
  </div>

  <?php elseif ($view === 'users'):
      $users = db_query('SELECT * FROM admin_users ORDER BY disabled_at IS NOT NULL, email')->fetchAll();
      $nowDb = db_time(now_utc());
      $roleChecks = static function (array $selected) use ($h): string {
          $out = '';
          foreach (USER_ROLES as $role => $label) {
              $out .= '<label class="check"><input type="checkbox" name="roles[]" value="' . $h($role) . '"'
                  . (in_array($role, $selected, true) ? ' checked' : '') . '> ' . $h($label) . '</label>';
          }
          return $out;
      };
      // Runs an Odbavovatel checks in (a Správce checks in every run).
      $runChecks = static function (array $selected) use ($h): string {
          $out = '<span class="hint checks-title">Odbavuje termíny:</span>';
          foreach (runs() as $run) {
              $out .= '<label class="check"><input type="checkbox" name="runs[]" value="' . (int) $run['id'] . '"'
                  . (in_array($run['id'], $selected, true) ? ' checked' : '') . '> ' . $h(run_label($run)) . '</label>';
          }
          return $out;
      };
  ?>
  

  <form class="card settings" method="post">
    <input type="hidden" name="csrf" value="<?= $h($_SESSION['csrf']) ?>">
    <h2>Pozvat uživatele</h2>
    <p class="hint settings-intro">Pozvaný dostane e-mail s odkazem
    (platí <?= ADMIN_INVITE_DAYS ?> dní); účet vznikne, až si na něm vytvoří heslo. Přihlašovací jméno je e-mail.
    <strong>Správce</strong> může vše, <strong>Účetní</strong> vidí jen rezervace a platby, <strong>Odbavovatel</strong>
    jen odbavuje (scanner) – a to jen termíny, které mu tu přiřadíte.</p>
    <label>E-mail (bude přihlašovací jméno)<input type="email" name="email" required maxlength="190" placeholder="jana@example.cz"></label>
    <div class="checks"><?= $roleChecks([]) ?></div>
    <div class="checks"><?= $runChecks([]) ?></div>
    <button name="action" value="user-invite">Poslat pozvánku</button>
  </form>

  <div class="card table">
    <table class="cards">
      <thead><tr><th>E-mail (login)</th><th>Role</th><th>Pozvánka</th><th>Naposledy přihlášen(a)</th><th></th></tr></thead>
      <tbody>
      <?php foreach ($users as $u):
          $pending = $u['password_hash'] === null;
          $openLink = $u['invite_hash'] !== null && $u['invite_expires_at'] > $nowDb;
          [$badge, $label] = match (true) {
              $u['disabled_at'] !== null => ['s-cancelled', 'Účet vypnut'],
              $pending && $openLink => ['s-pending', 'Nepřijata (platí do ' . $fmt($u['invite_expires_at']) . ')'],
              $pending => ['s-expired', 'Nepřijata – vypršela'],
              default => ['s-paid', 'Přijata ' . $fmt($u['accepted_at'])],
          };
          $self = (int) $u['id'] === $me['id'];
      ?>
        <tr>
          <td data-label="E-mail"><strong><?= $h($u['email']) ?></strong><?= $self ? ' <small>(vy)</small>' : '' ?></td>
          <td data-label="Role">
            <?= $h(implode(', ', array_map(static fn ($r) => USER_ROLES[$r], user_roles($u)))) ?>
            <?php $userRuns = user_run_ids((int) $u['id']);
                if (in_array('scanner', user_roles($u), true) && !in_array('admin', user_roles($u), true)): ?>
              <br><small class="<?= $userRuns ? '' : 'overdue' ?>">odbavuje: <?= $userRuns ? $h(implode(', ', array_map(static fn ($r) => run_label(runs()[$r]), $userRuns))) : 'žádný termín' ?></small>
            <?php endif ?>
            <details class="edit-email"><summary>změnit</summary>
              <form method="post" class="roles-edit">
                <input type="hidden" name="csrf" value="<?= $h($_SESSION['csrf']) ?>">
                <input type="hidden" name="id" value="<?= (int) $u['id'] ?>">
                <div class="checks"><?= $roleChecks(user_roles($u)) ?></div>
                <div class="checks"><?= $runChecks($userRuns) ?></div>
                <button name="action" value="user-roles">Uložit</button>
              </form>
            </details>
          </td>
          <td data-label="Pozvánka"><span class="badge <?= $badge ?>"><?= $h($label) ?></span>
            <br><small><?= $u['invited_by'] === 'deploy' ? 'první správce (deploy)' : 'pozval(a) ' . $h($u['invited_by']) ?>, <?= $h($fmt($u['created_at'])) ?></small></td>
          <td data-label="Přihlášen(a)"><?= $h($fmt($u['last_login_at'])) ?: '–' ?></td>
          <td>
            <div class="actions">
              <?php if ($u['disabled_at'] === null): ?>
                <?php if ($pending): ?>
                  <form method="post" onsubmit="return confirm('Poslat novou pozvánku? Původní odkaz přestane platit.')">
                    <input type="hidden" name="csrf" value="<?= $h($_SESSION['csrf']) ?>">
                    <input type="hidden" name="id" value="<?= (int) $u['id'] ?>">
                    <button class="secondary" name="action" value="user-link">Poslat znovu</button>
                  </form>
                <?php endif ?>
                <?php if (!$self): ?>
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
      </tbody>
    </table>
  </div>

  <?php $conflicts = db_query('SELECT * FROM scan_conflicts ORDER BY scanned_at DESC LIMIT 500')->fetchAll(); ?>
  <h2 class="section-title" id="konflikty">Odbavení bez spojení – konflikty<?= $conflicts ? ' (' . count($conflicts) . ')' : '' ?></h2>
  <p class="hint settings-intro">Scanner bez internetu ověřuje vstupenky podle seznamu staženého předem a odbavení odešle,
    jakmile se spojení vrátí. Platí první odbavení. Sem se zapíše, co se pak nedalo přijmout – typicky stejná vstupenka
    puštěná na dvou mobilech.</p>
  <div class="card table">
    <table class="cards">
      <thead><tr><th>Načteno</th><th>Termín</th><th>Vstupenka / host</th><th>Problém</th><th>Kdo</th><th>Dříve odbaveno</th></tr></thead>
      <tbody>
      <?php foreach ($conflicts as $c): ?>
        <tr>
          <td data-label="Načteno"><?= $h($fmt($c['scanned_at'])) ?></td>
          <td class="seats" data-label="Termín"><?= ($cr = run_by_id((int) $c['run_id'])) ? $h(run_label($cr)) : '–' ?></td>
          <td data-label="Vstupenka"><strong><?= $h($c['label']) ?></strong></td>
          <td data-label="Problém"><span class="badge <?= $c['reason'] === 'already_checked_in' ? 's-pending' : 's-expired' ?>"><?= $h(CONFLICT_REASONS[$c['reason']] ?? $c['reason']) ?></span></td>
          <td data-label="Kdo"><?= $h($c['scanned_by']) ?></td>
          <td data-label="Dříve odbaveno"><?= $c['other_at'] ? $h($fmt($c['other_at'])) . ($c['other_by'] ? '<br><small>' . $h($c['other_by']) . '</small>' : '') : '' ?></td>
        </tr>
      <?php endforeach ?>
      <?php if (!$conflicts): ?><tr><td colspan="6">Žádné konflikty.</td></tr><?php endif ?>
      </tbody>
    </table>
  </div>

  <?php elseif ($view === 'settings'):
      $deleteAt = data_deletion_at();
      $local = static fn (?string $utc) => $utc ? utc_time($utc)->setTimezone(new DateTimeZone(PRAGUE))->format('Y-m-d\TH:i') : '';
      $runForms = array_values(runs());
      $runForms[] = ['id' => 0, 'label' => '', 'starts_at' => null, 'booking_closes_at' => null, 'is_test' => false];
  ?>
  
  <?php $deadlineRules = payment_deadline_rules();
      $deadlineRows = array_pad($deadlineRules, max(3, count($deadlineRules) + 1), ['from' => '', 'hours' => '']); ?>
  <form class="ca-rd settings" method="post">
    <input type="hidden" name="csrf" value="<?= $h($_SESSION['csrf']) ?>">
    <h2>Splatnost plateb</h2>
    <p class="hint">Kolik hodin má zákazník na zaplacení od okamžiku, kdy rezervaci vytvoří. Rezervace vytvořené před prvním
      datem mají <?= (int) config('PAYMENT_DEADLINE_HOURS') ?> hodin (PAYMENT_DEADLINE_HOURS). Splatnost nikdy nepřesáhne
      začátek představení. Změna platí jen pro nové rezervace. Nyní platí: <strong><?= payment_deadline_hours(now_utc()) ?> hodin</strong>.</p>
    <div class="rules">
      <?php foreach ($deadlineRows as $rule): ?>
        <div class="rule">
          <label>Rezervace od<input type="datetime-local" name="deadline_from[]" value="<?= $h($rule['from'] === '' ? '' : prague_input(rule_from($rule['from']))) ?>"></label>
          <label>Splatnost (hodin)<input type="number" name="deadline_hours[]" min="1" max="720" value="<?= $h($rule['hours']) ?>"></label>
        </div>
      <?php endforeach ?>
    </div>
    <button name="action" value="deadline-save">Uložit splatnost</button>
  </form>

  <?php foreach ($runForms as $run):
      $locked = $run['id'] && run_has_reservations($run['id']);
      $dis = $locked ? ' disabled' : '';
  ?>
  <form class="card settings<?= $run['is_test'] ? ' test-run' : '' ?>" method="post">
    <input type="hidden" name="csrf" value="<?= $h($_SESSION['csrf']) ?>">
    <input type="hidden" name="id" value="<?= (int) $run['id'] ?>">
    <h2><?= $run['id'] ? $h(run_label($run)) : 'Nový termín' ?></h2>
    <div class="rule">
      <label>Začátek<input type="datetime-local" name="starts_at" value="<?= $h($local($run['starts_at'])) ?>" required<?= $dis ?>></label>
      <label>Název (nepovinný)<input name="label" maxlength="100" value="<?= $h($run['label']) ?>" placeholder="např. Premiéra"></label>
      <label>Konec rezervací (nepovinný)<input type="datetime-local" name="booking_closes_at" value="<?= $h($local($run['booking_closes_at'])) ?>"></label>
    </div>
    <label class="check"><input type="checkbox" name="is_test" value="1"<?= $run['is_test'] ? ' checked' : '' ?><?= $dis ?>> Testovací termín</label>
    <p class="hint">Pro vyzkoušení odbavení: termín není veřejně vidět, rezervace jsou hned zaplacené,
      slevové kódy nejdou použít, nepočítá se do souhrnů a lze ho smazat i s rezervacemi.</p>
    <?php if ($run['id'] && $run['is_test'] && ($base = rtrim((string) config('PUBLIC_URL'), '/')) !== ''): ?>
      <p class="hint">Odkaz na testovací termín: <a href="<?= $h($base . '/?termin=' . (int) $run['id']) ?>"><?= $h($base . '/?termin=' . (int) $run['id']) ?></a></p>
    <?php endif ?>
    <?php if ($locked): ?>
      <p class="hint locked">Představení už má rezervace – začátek nelze měnit (zákazníci rezervovali na tento čas).</p>
    <?php endif ?>
    <?php if ($run['id']): $scanners = run_scanner_emails((int) $run['id']); ?>
      <p class="hint">Odbavují: <?= $scanners ? $h(implode(', ', $scanners)) : 'zatím nikdo' ?> (a všichni správci).</p>
    <?php endif ?>
    <div class="actions">
      <button name="action" value="run-save"><?= $run['id'] ? 'Uložit termín' : 'Přidat termín' ?></button>
      <?php if ($run['id']): ?>
        <button class="danger" name="action" value="run-delete" onclick="return confirm('<?= $run['is_test'] ? 'Smazat testovací termín i se všemi jeho rezervacemi a odbaveními?' : 'Smazat termín? Lze jen u termínu bez rezervací.' ?>')">Smazat</button>
      <?php endif ?>
    </div>
  </form>
  <?php endforeach ?>

  <?php else: ?>

  <?php
    // Cards toggle one filter and keep the others (a second click removes it).
    $toggle = static fn (string $key, string|int $value) => $h('admin.php?' . http_build_query(array_filter(
        ['run' => $runFilter ?: null, 'status' => $status ?: null, 'q' => $q ?: null, 'sort' => $sort ?: null, 'dir' => $sort !== '' ? $dir : null,
         $key => ($key === 'run' ? $runFilter : $status) === $value ? null : $value]
    )));
  ?>
  <div class="stats">
    <?php foreach (['pending', 'paid'] as $s): $t = $totals[$s] ?? ['n' => 0, 'seats' => 0, 'amount' => 0]; ?>
      <a class="card <?= $status === $s ? 'selected' : '' ?>" href="<?= $toggle('status', $s) ?>"><?= $h($statusLabels[$s]) ?><strong><?= (int) $t['seats'] ?> míst</strong><?= $kc($t['amount']) ?> · <?= $reservationsCount($t['n']) ?></a>
    <?php endforeach ?>
    <?php $refunds = db_query('SELECT COUNT(*) n, COALESCE(SUM(refund_amount - refunded_amount), 0) amount FROM reservations WHERE refund_amount > refunded_amount AND ' . NOT_TEST_RUN_SQL)->fetch(); ?>
    <?php if ($refunds['n'] > 0): ?>
      <a class="card attention <?= $status === 'refund' ? 'selected' : '' ?>" href="<?= $toggle('status', 'refund') ?>">K vrácení<strong><?= $kc($refunds['amount']) ?></strong><?= $reservationsCount($refunds['n']) ?></a>
    <?php endif ?>
    <?php $onsite = db_query("SELECT COUNT(*) n, COALESCE(SUM(amount), 0) amount FROM reservations WHERE sold_by IS NOT NULL AND status = 'paid' AND payment_checked_at IS NULL AND " . NOT_TEST_RUN_SQL)->fetch(); ?>
    <?php if ($onsite['n'] > 0): ?>
      <a class="card attention <?= $status === 'onsite' ? 'selected' : '' ?>" href="<?= $toggle('status', 'onsite') ?>">Na místě – ověřit platbu<strong><?= $kc($onsite['amount']) ?></strong><?= ($n = (int) $onsite['n']) ?> <?= $n === 1 ? 'prodej' : ($n < 5 ? 'prodeje' : 'prodejů') ?></a>
    <?php endif ?>
    <?php $perRun = db_query("SELECT run_id, COUNT(*) FROM reservation_seats GROUP BY run_id")->fetchAll(PDO::FETCH_KEY_PAIR); ?>
    <?php foreach (runs() as $run): $held = (int) ($perRun[$run['id']] ?? 0); ?>
      <a class="card <?= $run['is_test'] ? 'test-run' : '' ?> <?= $runFilter === $run['id'] ? 'selected' : '' ?>" href="<?= $toggle('run', (int) $run['id']) ?>"><?= $h(run_label($run)) ?><strong><?= $held ?> / <?= run_capacity($run) ?></strong><?= run_capacity($run) - $held ?> volných</a>
    <?php endforeach ?>
  </div>

  <?php $deleteAt = data_deletion_at();
  if ($refunds['n'] > 0 && $deleteAt !== null && now_utc() > $deleteAt->modify('-14 days')): ?>
    <div class="flash warn">Osobní údaje se mažou <?= $h(format_prague(db_time($deleteAt))) ?>. U <?= (int) $refunds['n'] ?> <?= (int) $refunds['n'] === 1 ? 'rezervace' : 'rezervací' ?> zbývá vrátit peníze – jejich jméno a e-mail se smažou až po označení „Vráceno“.</div>
  <?php endif ?>

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
      <option value="onsite" <?= $status === 'onsite' ? 'selected' : '' ?>>Na místě – ověřit platbu</option>
    </select>
    <input type="search" name="q" value="<?= $h($q) ?>" placeholder="SS, jméno, e-mail, místo">
    <?php if ($sort !== ''): ?><input type="hidden" name="sort" value="<?= $h($sort) ?>"><input type="hidden" name="dir" value="<?= $h($dir) ?>"><?php endif ?>
    <button class="secondary">Filtrovat</button>
  </form>

  <div class="card table">
    <table class="cards">
      <thead class="sortable"><tr>
        <?php foreach (['ss' => 'SS', 'run' => 'Termín', 'name' => 'Jméno', 'seats' => 'Místa', 'amount' => 'Částka', 'status' => 'Stav', 'created' => 'Vytvořeno', 'expires' => 'Splatnost'] as $key => $title):
            $active = $sort === $key;
            $link = 'admin.php?' . http_build_query(array_filter(['run' => $runFilter ?: null, 'status' => $status ?: null, 'q' => $q ?: null, 'sort' => $key, 'dir' => $active && $dir === 'asc' ? 'desc' : 'asc'])); ?>
          <th<?= $active ? ' aria-sort="' . ($dir === 'asc' ? 'ascending' : 'descending') . '"' : '' ?>><a class="sort" href="<?= $h($link) ?>"><?= $h($title) ?><?= $active ? ($dir === 'asc' ? ' ▲' : ' ▼') : '' ?></a></th>
        <?php endforeach ?>
        <th></th></tr></thead>
      <tbody>
      <?php foreach ($rows as $r): $rr = run_by_id((int) $r['run_id']); ?>
        <tr<?= $rr && $rr['is_test'] ? ' class="test-run"' : '' ?>>
          <td data-label="SS"><strong><?= $h($r['specific_symbol']) ?></strong></td>
          <td class="seats<?= $rr && $rr['is_test'] ? ' run-test' : '' ?>" data-label="Termín"><?= $rr ? $h(run_label($rr)) : '–' ?></td>
          <td data-label="Jméno"><?= $h($r['first_name'] . ' ' . $r['last_name']) ?><br><a href="mailto:<?= $h($r['email']) ?>"><?= $h($r['email']) ?></a>
            <?php if ($r['sold_by'] !== null): ?><small>prodal(a) <?= $h($r['sold_by']) ?></small><?php endif ?>
            <details class="edit-email"><summary>změnit e-mail</summary>
              <form method="post">
                <input type="hidden" name="csrf" value="<?= $h($_SESSION['csrf']) ?>">
                <input type="hidden" name="id" value="<?= (int) $r['id'] ?>">
                <input type="email" name="email" value="<?= $h($r['email']) ?>" required>
                <button name="action" value="email">Uložit</button>
              </form>
            </details></td>
          <td class="seats" data-label="Místa"><?php $checkedIn = reservation_checkins($r);
              $seatCount = count(current_items($r));
              foreach (current_items($r) as $seat => $item): ?>
              <?= $h($seat) ?><?= $item['kind'] === 'child' ? ' <span class="kind child">dítě</span>' : '' ?><?= $item['free'] ? ' <span class="kind free">kód</span>' : '' ?><?= isset($checkedIn[$seat]) ? ' ✓' : '' ?><br>
            <?php endforeach ?>
            <?php if ($cancelledSeats = cancelled_seat_ids($r)): ?><br><small>zrušená místa: <?= $h(implode(', ', $cancelledSeats)) ?></small><?php endif ?>
            <?php /*if (in_array($r['status'], ['pending', 'paid'], true) && $seatCount > 1): ?>
              <details class="edit-email"><summary>zrušit jednotlivá místa</summary>
                <form method="post" class="seat-cancel" onsubmit="return confirm('Zrušit vybraná místa?<?= $r['status'] === 'paid' ? ' Zákazníkovi bude vrácena jejich plná cena (místa zdarma se slevovým kódem se nevracejí) a přijde mu e-mail s novými vstupenkami.' : '' ?>')">
                  <input type="hidden" name="csrf" value="<?= $h($_SESSION['csrf']) ?>">
                  <input type="hidden" name="id" value="<?= (int) $r['id'] ?>">
                  <?php foreach (current_seat_ids($r) as $seat): ?>
                    <label><input type="checkbox" name="seats[]" value="<?= $h($seat) ?>"> <?= $h($seat) ?></label>
                  <?php endforeach ?>
 
                  <button class="danger" name="action" value="cancel-seats">Zrušit vybraná</button>
             
                </form>
              </details>
            <?php endif */ ?></td>
          <td data-label="Částka"><?= $kc($r['amount']) ?>
            <?php if ($r['paid_amount'] !== null && (int) $r['paid_amount'] !== (int) $r['amount']): ?><br><small>přijato <?= $kc($r['paid_amount']) ?></small><?php endif ?>
            <?php if ($r['status'] === 'pending' && (int) $r['paid_amount'] > 0): ?><br><small class="overdue">zbývá doplatit <?= $kc(amount_due($r)) ?></small><?php endif ?></td>
          <td data-label="Stav"><span class="badge s-<?= $h($r['status']) ?>"><?= $h($statusLabels[$r['status']]) ?></span>
            <?php if ($r['paid_at']): ?><br><small>zaplaceno <?= $h($fmt($r['paid_at'])) ?></small><?php endif ?>
            <?php if ($r['sold_by'] !== null && $r['status'] === 'paid'): ?><br><small class="<?= $r['payment_checked_at'] ? '' : 'overdue' ?>"><?= $r['payment_checked_at'] ? 'platba ověřena ' . $h($fmt($r['payment_checked_at'])) : 'na místě – ověřte, že platba dorazila' ?></small><?php endif ?>
            <?php if ($r['ticket_sent_at']): ?><br><small>vstupenka <?= $h($fmt($r['ticket_sent_at'])) ?></small><?php endif ?>
            <?php if ($r['checked_in_at']): ?><br><small>odbaveno <?= count($checkedIn) ?> z <?= $seatCount ?> míst, poprvé <?= $h($fmt($r['checked_in_at'])) ?><?= $r['checked_in_by'] ? ' (' . $h($r['checked_in_by']) . ')' : '' ?></small><?php endif ?>
            <?php if ($r['status'] === 'cancelled'): ?><br><small>zrušeno <?= $h($fmt($r['cancelled_at'])) ?></small><?php endif ?>
            <?php if ($r['refunded_amount'] > 0): ?><br><small>vráceno <?= $kc($r['refunded_amount']) ?> (<?= $h($fmt($r['refunded_at'])) ?>)</small><?php endif ?>
            <?php if ($r['refund_amount'] > $r['refunded_amount']): ?>
              <br><small class="overdue">vrátit: <?= $kc($r['refund_amount'] - $r['refunded_amount']) ?>
                na účet plátce</small>
            <?php endif ?></td>
          <td data-label="Vytvořeno"><?= $h($fmt($r['created_at'])) ?></td>
          <td data-label="Splatnost" class="<?= $r['status'] === 'pending' && $r['expires_at'] < db_time(now_utc()) ? 'overdue' : '' ?>"><?= $h($fmt($r['expires_at'])) ?></td>
          <td>
            <div class="actions">
              <?php if ($r['status'] === 'pending'): ?>
                <form method="post" class="pay-form" title="Zadejte částku, která skutečně přišla na účet" onsubmit="if (this.dataset.sent) return false; this.dataset.sent = 1">
                  <input type="hidden" name="csrf" value="<?= $h($_SESSION['csrf']) ?>">
                  <input type="hidden" name="id" value="<?= (int) $r['id'] ?>">
                  <input type="hidden" name="paid_before" value="<?= (int) $r['paid_amount'] ?>">
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
              <?php if ($r['sold_by'] !== null && $r['status'] === 'paid' && !$r['payment_checked_at']): ?>
                <form method="post" onsubmit="return confirm('Platba <?= $h(format_czk((int) $r['amount'])) ?> s SS <?= $h($r['specific_symbol']) ?> dorazila na účet?')">
                  <input type="hidden" name="csrf" value="<?= $h($_SESSION['csrf']) ?>">
                  <input type="hidden" name="id" value="<?= (int) $r['id'] ?>">
                  <button name="action" value="onsite-checked">Platba dorazila</button>
                </form>
              <?php elseif ($r['status'] !== 'pending'): ?>
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
                <?php if ($r['email'] !== ''): ?>
                <form method="post">
                  <input type="hidden" name="csrf" value="<?= $h($_SESSION['csrf']) ?>">
                  <input type="hidden" name="id" value="<?= (int) $r['id'] ?>">
                  <button class="secondary" name="action" value="ticket"><?= $r['ticket_sent_at'] ? 'Poslat znovu' : 'Poslat vstupenku' ?></button>
                </form>
                <?php endif ?>
                <a class="button secondary" href="tickets.php?token=<?= $h($r['token']) ?>">PDF vstupenky</a>
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
