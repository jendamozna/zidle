<?php
// Fresh test database for the Playwright tests; prints the data the tests need as JSON.
//   run 1 "Premiéra" in a month – bookable, one pending reservation for the admin test,
//   three unused discount codes for the customer tests
//   run 2 "Dnes" starting in 20 minutes – inside the scan window: two paid reservations
//   (one for the online and one for the offline scanner test)
//   accounts: Správce spravce@example.cz (created like deploy.php does), Odbavovatel vchod@example.cz (both runs)
declare(strict_types=1);

require __DIR__ . '/../php/fixtures.php';

reset_database();
$pdo = db();
$pdo->prepare('UPDATE runs SET label = ?, starts_at = ? WHERE id = 1')->execute(['Premiéra', db_time(now_utc()->modify('+30 days'))]);
$pdo->prepare('UPDATE runs SET label = ?, starts_at = ? WHERE id = 2')->execute(['Dnes', db_time(now_utc()->modify('+20 minutes'))]);
runs(true);

$pending = reservation(['MR-5-1', 'MR-5-2'], 'pending', 1, 'Platící');
$paid = reservation(['BC-1-1', 'BC-1-2'], 'paid', 2, 'Vstupenka', ['BC-1-2']);
$offline = reservation(['BC-2-1', 'BC-2-2'], 'paid', 2, 'Offline');
create_discount_codes("zakaznik@example.com\nzakaznik@example.com\nzakaznik@example.com", 'seed');
create_first_admin('spravce@example.cz', 'e2e-spravce-heslo');
$invite = admin_invite('vchod@example.cz', ['scanner'], 'seed', [1, 2]); // checks in both runs
admin_accept_invite(is_array($invite) ? $invite['token'] : '', 'e2e-vchod-heslo', 'e2e-vchod-heslo');

$row = static function (int $id): array {
    $stmt = db()->prepare('SELECT * FROM reservations WHERE id = ?');
    $stmt->execute([$id]);
    return $stmt->fetch();
};
echo json_encode([
    'pendingVs' => $row($pending)['specific_symbol'],
    'ticket' => ticket_code($row($paid), 'BC-1-1'),
    'offlineTicket' => ticket_code($row($offline), 'BC-2-1'),
    'codes' => array_map('format_discount_code', db_query('SELECT code FROM discount_codes ORDER BY id')->fetchAll(PDO::FETCH_COLUMN)),
], JSON_UNESCAPED_UNICODE);
