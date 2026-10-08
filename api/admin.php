<?php
// Admin page: list reservations, confirm received payments, cancel reservations,
// manage VIP guests (free entry, checked by name at the entrance).
declare(strict_types=1);
require __DIR__ . '/lib/bootstrap.php';

session_set_cookie_params(['httponly' => true, 'samesite' => 'Strict', 'secure' => !empty($_SERVER['HTTPS'])]);
session_start();
header('Content-Type: text/html; charset=utf-8');
header('X-Frame-Options: DENY');

$password = (string) config('ADMIN_PASSWORD');
$h = static fn ($v) => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');

if ($password === '') {
    http_response_code(503);
    exit('Nastavte ADMIN_PASSWORD v config.local.php.');
}

if (empty($_SESSION['csrf'])) {
    $_SESSION['csrf'] = bin2hex(random_bytes(16));
}
$csrfOk = hash_equals($_SESSION['csrf'], (string) ($_POST['csrf'] ?? ''));
$action = $_POST['action'] ?? null;

if ($action === 'login' && $csrfOk) {
    if (!login_allowed('admin')) {
        $loginError = 'Příliš mnoho pokusů. Zkuste to za 15 minut.';
    } elseif (hash_equals($password, (string) ($_POST['password'] ?? ''))) {
        session_regenerate_id(true);
        $_SESSION['admin'] = true;
    } else {
        sleep(1);
        $loginError = 'Nesprávné heslo.';
    }
}
if ($action === 'logout' && $csrfOk) {
    $_SESSION = [];
    session_destroy();
    header('Location: admin.php');
    exit;
}

$flash = null;
$view = in_array($_GET['view'] ?? '', ['vip', 'settings'], true) ? $_GET['view'] : 'reservations';

if (!empty($_SESSION['admin']) && $csrfOk && $action === 'settings') {
    $event = trim((string) ($_POST['event_at'] ?? ''));
    $rules = [];
    $errors = [];
    if ($event !== '' && prague_time($event) === null) {
        $errors[] = 'Neplatné datum akce.';
    }
    foreach ((array) ($_POST['rule_from'] ?? []) as $i => $from) {
        $from = trim((string) $from);
        $percent = trim((string) ($_POST['rule_percent'][$i] ?? ''));
        if ($from === '' && $percent === '') {
            continue;
        }
        if (prague_time($from) === null || !ctype_digit($percent) || (int) $percent > 100) {
            $errors[] = 'Storno pravidlo ' . ($i + 1) . ': zadejte datum a procento 0–100.';
            continue;
        }
        $rules[] = ['from' => str_replace('T', ' ', $from), 'percent' => (int) $percent];
    }
    if ($errors) {
        $_SESSION['flash'] = implode(' ', $errors);
    } else {
        usort($rules, static fn ($a, $b) => strcmp($a['from'], $b['from']));
        save_setting('event_at', str_replace('T', ' ', $event));
        save_setting('storno_rules', $rules);
        $_SESSION['flash'] = 'Nastavení uloženo.';
    }
    header('Location: admin.php?view=settings');
    exit;
}

if (!empty($_SESSION['admin']) && $csrfOk && in_array($action, ['vip-add', 'vip-delete', 'vip-reset'], true)) {
    $id = (int) ($_POST['id'] ?? 0);
    if ($action === 'vip-add') {
        $name = trim(preg_replace('/\s+/u', ' ', (string) ($_POST['name'] ?? '')));
        $section = (string) ($_POST['section'] ?? '');
        $persons = max(1, min(50, (int) ($_POST['persons'] ?? 1)));
        $note = trim((string) ($_POST['note'] ?? ''));
        if ($name === '' || mb_strlen($name) > 200 || !isset(SECTIONS[$section]) || mb_strlen($note) > 255) {
            $flash = 'Vyplňte jméno a sekci.';
        } else {
            db()->prepare('INSERT INTO vip_guests (name, section, persons, note, created_at) VALUES (?, ?, ?, ?, ?)')
                ->execute([$name, $section, $persons, $note, db_time(now_utc())]);
            $flash = "VIP host {$name} přidán.";
        }
    } elseif ($action === 'vip-delete') {
        db()->prepare('DELETE FROM vip_guests WHERE id = ?')->execute([$id]);
        $flash = 'VIP host odstraněn.';
    } else {
        db()->prepare('UPDATE vip_guests SET checked_in_at = NULL WHERE id = ?')->execute([$id]);
        $flash = 'Příchod zrušen.';
    }
    $_SESSION['flash'] = $flash;
    header('Location: admin.php?view=vip');
    exit;
}

if (!empty($_SESSION['admin']) && $csrfOk && in_array($action, ['paid', 'paid-late', 'cancel', 'cancel-seats', 'ticket', 'email', 'refunded'], true)) {
    $id = (int) ($_POST['id'] ?? 0);
    $now = db_time(now_utc());
    if ($action === 'paid') {
        $stmt = db()->prepare("UPDATE reservations SET status = 'paid', paid_at = ?, paid_amount = amount WHERE id = ? AND status = 'pending'");
        $stmt->execute([$now, $id]);
        $flash = $stmt->rowCount() ? 'Platba potvrzena.' . send_ticket_for($id) : 'Rezervaci nelze označit jako zaplacenou.';
    } elseif ($action === 'paid-late') {
        $flash = accept_late_payment($id);
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
    header('Location: admin.php?' . http_build_query(['status' => $_GET['status'] ?? '', 'q' => $_GET['q'] ?? '']));
    exit;
}
$flash = $_SESSION['flash'] ?? null;

/**
 * Payment arrived after the reservation expired: restore it as paid if all
 * its seats are still free, otherwise report which seats were taken meanwhile.
 */
function accept_late_payment(int $id): string
{
    $pdo = db();
    $pdo->beginTransaction();
    $stmt = $pdo->prepare("SELECT * FROM reservations WHERE id = ? AND status = 'expired' FOR UPDATE");
    $stmt->execute([$id]);
    $r = $stmt->fetch();
    if (!$r) {
        $pdo->rollBack();
        return 'Rezervaci nelze obnovit.';
    }
    $seats = explode(',', $r['seats']);
    $placeholders = implode(',', array_fill(0, count($seats), '?'));
    $taken = $pdo->prepare("SELECT seat_id FROM reservation_seats WHERE seat_id IN ($placeholders) FOR UPDATE");
    $taken->execute($seats);
    $conflict = $taken->fetchAll(PDO::FETCH_COLUMN);
    if ($conflict) {
        $pdo->rollBack();
        return 'Místa ' . implode(', ', $conflict) . ' už mezitím obsadil někdo jiný. Platbu je nutné vrátit nebo domluvit jiná místa.';
    }
    $insert = $pdo->prepare('INSERT INTO reservation_seats (seat_id, reservation_id) VALUES (?, ?)');
    foreach ($seats as $seat) {
        $insert->execute([$seat, $id]);
    }
    $pdo->prepare("UPDATE reservations SET status = 'paid', paid_at = ?, paid_amount = amount, cancelled_at = NULL WHERE id = ?")
        ->execute([db_time(now_utc()), $id]);
    $pdo->commit();
    return 'Pozdní platba přijata, rezervace obnovena.' . send_ticket_for($id);
}

/** Sends the ticket e-mail for a paid reservation; returns a message for the flash. */
function send_ticket_for(int $id): string
{
    $stmt = db()->prepare("SELECT * FROM reservations WHERE id = ? AND status = 'paid'");
    $stmt->execute([$id]);
    $r = $stmt->fetch();
    if (!$r) {
        return '';
    }
    if (!filter_var(config('MAIL_ENABLED'), FILTER_VALIDATE_BOOLEAN)) {
        return ' E-maily jsou vypnuté (MAIL_ENABLED), vstupenka nebyla odeslána.';
    }
    try {
        $sent = send_ticket_email($r);
    } catch (Throwable $e) {
        error_log('[zidle] ' . $e);
        $sent = false;
    }
    if (!$sent) {
        return ' Vstupenku se nepodařilo odeslat.';
    }
    db()->prepare('UPDATE reservations SET ticket_sent_at = ? WHERE id = ?')->execute([db_time(now_utc()), $id]);
    return ' Vstupenka odeslána na ' . $r['email'] . '.';
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
  .error { color:#8f2a20; }
  .tabs { display:flex; gap:4px; padding:4px; border-radius:999px; background:#efe9df; margin-right:auto; }
  .tabs a { padding:6px 16px; border-radius:999px; color:var(--ink-2); text-decoration:none; font-weight:600; }
  .tabs a.active { background:var(--surface); color:var(--ink); box-shadow:0 1px 4px rgba(60,45,25,.12); }
  .vip-form { display:flex; gap:10px; flex-wrap:wrap; align-items:flex-end; margin-bottom:16px; }
  .vip-form label { display:flex; flex-direction:column; gap:4px; font-size:.8rem; font-weight:600; color:var(--ink-2); }
  .vip-form label.grow { flex:1 1 200px; }
  .vip-form input[name=persons] { width:80px; }
  .edit-email summary { cursor:pointer; font-size:.78rem; color:var(--ink-2); margin-top:2px; }
  .edit-email form { display:flex; gap:6px; margin-top:6px; }
  .edit-email input { width:200px; padding:4px 8px; }
  .edit-email button { padding:4px 10px; }
  .overdue { color:#8f2a20; font-weight:600; }
  .seat-cancel { display:flex; flex-wrap:wrap; gap:4px 10px; margin-top:6px; align-items:center; max-width:260px; }
  .seat-cancel label { font-size:.8rem; white-space:nowrap; }
  .seat-cancel button { padding:4px 10px; }
  .card.attention { background:#f6cdc6; color:#7d2117; text-decoration:none; }
  .settings { max-width:640px; display:flex; flex-direction:column; gap:12px; }
  .settings h2 { margin:8px 0 0; font:600 1.15rem Georgia, serif; }
  .settings label { display:flex; flex-direction:column; gap:4px; font-size:.8rem; font-weight:600; color:var(--ink-2); max-width:280px; }
  .settings .rules { display:flex; flex-direction:column; gap:8px; }
  .settings .rule { display:flex; gap:10px; flex-wrap:wrap; }
  .settings .rule input[type=number] { width:110px; }
  .settings button { align-self:flex-start; }
  .hint { margin:0; font-size:.85rem; color:var(--ink-2); }
  .login { max-width:340px; margin:15vh auto; display:flex; flex-direction:column; gap:12px; }
</style>
</head>
<body>
<main>
<?php if (empty($_SESSION['admin'])): ?>
  <form class="card login" method="post">
    <h1>Správa rezervací</h1>
    <input type="hidden" name="csrf" value="<?= $h($_SESSION['csrf']) ?>">
    <input type="hidden" name="action" value="login">
    <input type="password" name="password" placeholder="Heslo" autofocus required>
    <?php if (!empty($loginError)): ?><div class="error"><?= $h($loginError) ?></div><?php endif ?>
    <button>Přihlásit</button>
  </form>
<?php else:
    expire_reservations();
    $status = (string) ($_GET['status'] ?? '');
    $q = trim((string) ($_GET['q'] ?? ''));
    $where = [];
    $params = [];
    if (isset($statusLabels[$status])) {
        $where[] = 'status = ?';
        $params[] = $status;
    } elseif ($status === 'refund') {
        $where[] = 'refund_amount > refunded_amount';
    }
    if ($q !== '') {
        $where[] = '(variable_symbol LIKE ? OR email LIKE ? OR last_name LIKE ? OR first_name LIKE ? OR seats LIKE ?)';
        array_push($params, ...array_fill(0, 5, '%' . $q . '%'));
    }
    $sql = 'SELECT * FROM reservations' . ($where ? ' WHERE ' . implode(' AND ', $where) : '') . ' ORDER BY id DESC LIMIT 500';
    $stmt = db()->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll();
    $totals = db()->query("SELECT status, COUNT(*) n, SUM(seat_count) seats, SUM(amount) amount FROM reservations GROUP BY status")
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
      <a href="admin.php?view=settings" class="<?= $view === 'settings' ? 'active' : '' ?>">Nastavení</a>
    </nav>
    <form method="post">
      <input type="hidden" name="csrf" value="<?= $h($_SESSION['csrf']) ?>">
      <input type="hidden" name="action" value="logout">
      <button class="secondary">Odhlásit</button>
    </form>
  </header>

  <?php if ($flash): ?><div class="flash"><?= $h($flash) ?></div><?php endif ?>

  <?php if ($view === 'settings'):
      $eventValue = str_replace(' ', 'T', (string) setting('event_at', ''));
      $rules = storno_rules();
      $rows = array_pad($rules, max(4, count($rules) + 1), ['from' => '', 'percent' => '']);
      $deleteAt = data_deletion_at();
  ?>
  <form class="card settings" method="post">
    <input type="hidden" name="csrf" value="<?= $h($_SESSION['csrf']) ?>">
    <input type="hidden" name="action" value="settings">

    <h2>Akce</h2>
    <label>Začátek akce<input type="datetime-local" name="event_at" value="<?= $h($eventValue) ?>"></label>
    <p class="hint">Od začátku akce už nelze rezervace rušit.
      <?php if ($deleteAt): ?>Osobní údaje budou smazány <?= $h($deleteAt->format('j. n. Y')) ?> (<?= (int) config('DATA_RETENTION_DAYS') ?> dní po akci).<?php endif ?></p>

    <h2>Storno poplatky</h2>
    <p class="hint">Platí pro zaplacené rezervace zrušené zákazníkem. Nezaplacené lze zrušit vždy zdarma. Před prvním datem je storno zdarma.</p>
    <div class="rules">
      <?php foreach ($rows as $rule): ?>
        <div class="rule">
          <label>Od<input type="datetime-local" name="rule_from[]" value="<?= $h(str_replace(' ', 'T', (string) $rule['from'])) ?>"></label>
          <label>Poplatek %<input type="number" name="rule_percent[]" min="0" max="100" value="<?= $h($rule['percent']) ?>"></label>
        </div>
      <?php endforeach ?>
    </div>
    <p class="hint">Prázdné řádky se ignorují. Pro další pravidla uložte a objeví se nový prázdný řádek.</p>
    <button>Uložit nastavení</button>
  </form>

  <?php elseif ($view === 'vip'):
      $vips = db()->query('SELECT * FROM vip_guests ORDER BY section, name')->fetchAll();
      $vipPersons = array_sum(array_column($vips, 'persons'));
      $vipArrived = array_sum(array_map(fn ($v) => $v['checked_in_at'] ? (int) $v['persons'] : 0, $vips));
  ?>
  <div class="stats">
    <div class="card">VIP hosté<strong><?= count($vips) ?></strong><?= $vipPersons ?> osob</div>
    <div class="card">Přišlo<strong><?= $vipArrived ?> / <?= $vipPersons ?></strong>osob</div>
  </div>

  <form class="card vip-form" method="post">
    <input type="hidden" name="csrf" value="<?= $h($_SESSION['csrf']) ?>">
    <input type="hidden" name="action" value="vip-add">
    <label>Jméno<input name="name" required maxlength="200" placeholder="Jméno a příjmení"></label>
    <label>Sekce<select name="section" required>
      <?php foreach (SECTIONS as $id => $def): ?><option value="<?= $h($id) ?>"><?= $h($def['name']) ?></option><?php endforeach ?>
    </select></label>
    <label>Osob<input name="persons" type="number" min="1" max="50" value="1" required></label>
    <label class="grow">Poznámka<input name="note" maxlength="255" placeholder="nepovinné"></label>
    <button>Přidat VIP</button>
  </form>

  <div class="card table">
    <table>
      <thead><tr><th>Jméno</th><th>Sekce</th><th>Osob</th><th>Poznámka</th><th>Příchod</th><th></th></tr></thead>
      <tbody>
      <?php foreach ($vips as $v): ?>
        <tr>
          <td><strong><?= $h($v['name']) ?></strong></td>
          <td><?= $h(SECTIONS[$v['section']]['name'] ?? $v['section']) ?></td>
          <td><?= (int) $v['persons'] ?></td>
          <td><?= $h($v['note']) ?></td>
          <td><?= $v['checked_in_at'] ? '<span class="badge s-paid">' . $h($fmt($v['checked_in_at'])) . '</span>' : '' ?></td>
          <td>
            <div class="actions">
              <?php if ($v['checked_in_at']): ?>
                <form method="post">
                  <input type="hidden" name="csrf" value="<?= $h($_SESSION['csrf']) ?>">
                  <input type="hidden" name="id" value="<?= (int) $v['id'] ?>">
                  <button class="secondary" name="action" value="vip-reset">Zrušit příchod</button>
                </form>
              <?php endif ?>
              <form method="post" onsubmit="return confirm('Odstranit VIP hosta?')">
                <input type="hidden" name="csrf" value="<?= $h($_SESSION['csrf']) ?>">
                <input type="hidden" name="id" value="<?= (int) $v['id'] ?>">
                <button class="danger" name="action" value="vip-delete">Odstranit</button>
              </form>
            </div>
          </td>
        </tr>
      <?php endforeach ?>
      <?php if (!$vips): ?><tr><td colspan="6">Žádní VIP hosté.</td></tr><?php endif ?>
      </tbody>
    </table>
  </div>
  <?php else: ?>

  <div class="stats">
    <?php foreach (['pending', 'paid'] as $s): $t = $totals[$s] ?? ['n' => 0, 'seats' => 0, 'amount' => 0]; ?>
      <div class="card"><?= $h($statusLabels[$s]) ?><strong><?= (int) $t['seats'] ?> míst</strong><?= $kc($t['amount']) ?> · <?= (int) $t['n'] ?> rez.</div>
    <?php endforeach ?>
    <?php $refunds = db()->query('SELECT COUNT(*) n, COALESCE(SUM(refund_amount - refunded_amount), 0) amount FROM reservations WHERE refund_amount > refunded_amount')->fetch(); ?>
    <?php if ($refunds['n'] > 0): ?>
      <a class="card attention" href="admin.php?status=refund">K vrácení<strong><?= $kc($refunds['amount']) ?></strong><?= (int) $refunds['n'] ?> rez.</a>
    <?php endif ?>
  </div>

  <form class="filters" method="get">
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
      <thead><tr><th>VS</th><th>Jméno</th><th>Místa</th><th>Částka</th><th>Stav</th><th>Vytvořeno</th><th>Splatnost</th><th></th></tr></thead>
      <tbody>
      <?php foreach ($rows as $r): ?>
        <tr>
          <td><strong><?= $h($r['variable_symbol']) ?></strong></td>
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
            <?php if ($r['paid_amount'] !== null && (int) $r['paid_amount'] !== (int) $r['amount']): ?><br><small>přijato <?= $kc($r['paid_amount']) ?></small><?php endif ?></td>
          <td><span class="badge s-<?= $h($r['status']) ?>"><?= $h($statusLabels[$r['status']]) ?></span>
            <?php if ($r['paid_at']): ?><br><small>zaplaceno <?= $h($fmt($r['paid_at'])) ?></small><?php endif ?>
            <?php if ($r['ticket_sent_at']): ?><br><small>vstupenka <?= $h($fmt($r['ticket_sent_at'])) ?></small><?php endif ?>
            <?php if ($r['checked_in_at']): ?><br><small>odbaveno <?= $h($fmt($r['checked_in_at'])) ?></small><?php endif ?>
            <?php if ($r['status'] === 'cancelled'): ?><br><small><?= $r['cancelled_by'] === 'customer' ? 'zrušil zákazník' : 'zrušeno správcem' ?> <?= $h($fmt($r['cancelled_at'])) ?></small><?php endif ?>
            <?php if ($r['cancel_fee'] > 0): ?><br><small>storno <?= $kc($r['cancel_fee']) ?></small><?php endif ?>
            <?php if ($r['refunded_amount'] > 0): ?><br><small>vráceno <?= $kc($r['refunded_amount']) ?> (<?= $h($fmt($r['refunded_at'])) ?>)</small><?php endif ?>
            <?php if ($r['refund_amount'] > $r['refunded_amount']): ?>
              <br><small class="overdue">vrátit: <?= $kc($r['refund_amount'] - $r['refunded_amount']) ?>
                <?= $r['refund_account'] ? ' na ' . $h($r['refund_account']) : ' (účet zjistit e-mailem)' ?></small>
            <?php endif ?></td>
          <td><?= $h($fmt($r['created_at'])) ?></td>
          <td class="<?= $r['status'] === 'pending' && $r['expires_at'] < db_time(now_utc()) ? 'overdue' : '' ?>"><?= $h($fmt($r['expires_at'])) ?></td>
          <td>
            <div class="actions">
              <?php if ($r['status'] === 'pending'): ?>
                <form method="post">
                  <input type="hidden" name="csrf" value="<?= $h($_SESSION['csrf']) ?>">
                  <input type="hidden" name="id" value="<?= (int) $r['id'] ?>">
                  <button name="action" value="paid">Zaplaceno</button>
                </form>
              <?php endif ?>
              <?php if ($r['refund_amount'] > $r['refunded_amount']): ?>
                <form method="post" onsubmit="return confirm('Peníze byly vráceny?')">
                  <input type="hidden" name="csrf" value="<?= $h($_SESSION['csrf']) ?>">
                  <input type="hidden" name="id" value="<?= (int) $r['id'] ?>">
                  <button name="action" value="refunded">Vráceno</button>
                </form>
              <?php endif ?>
              <?php if ($r['status'] === 'expired'): ?>
                <form method="post" onsubmit="return confirm('Platba dorazila po splatnosti. Obnovit rezervaci jako zaplacenou?')">
                  <input type="hidden" name="csrf" value="<?= $h($_SESSION['csrf']) ?>">
                  <input type="hidden" name="id" value="<?= (int) $r['id'] ?>">
                  <button class="secondary" name="action" value="paid-late">Přijmout pozdní platbu</button>
                </form>
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
      <?php if (!$rows): ?><tr><td colspan="8">Žádné rezervace.</td></tr><?php endif ?>
      </tbody>
    </table>
  </div>
  <?php endif ?>
<?php endif ?>
</main>
</body>
</html>
