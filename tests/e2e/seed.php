<?php
// Fresh test database for the Playwright tests; prints the data the tests need as JSON.
//   run 1 "Premiéra" in a month – bookable, one pending reservation for the admin test
//   run 2 "Dnes" starting in 20 minutes – inside the scan window: two paid tickets and two VIP guests
//   (one of each for the online and one for the offline scanner test)
declare(strict_types=1);

require __DIR__ . '/../php/fixtures.php';

reset_database();
$pdo = db();
$pdo->prepare('UPDATE runs SET label = ?, starts_at = ? WHERE id = 1')->execute(['Premiéra', db_time(now_utc()->modify('+30 days'))]);
$pdo->prepare('UPDATE runs SET label = ?, starts_at = ? WHERE id = 2')->execute(['Dnes', db_time(now_utc()->modify('+20 minutes'))]);
runs(true);

$pending = reservation(['MR-5-1', 'MR-5-2'], 'pending', 1, 'Platící');
$paid = reservation(['BC-1-1', 'BC-1-2'], 'paid', 2, 'Vstupenka');
$offline = reservation(['BC-2-1', 'BC-2-2'], 'paid', 2, 'Offline');
add_vip_guest(2, 'Mons. Testovací', ['ML-1-1', 'ML-1-2'], '');
add_vip_guest(2, 'Paní Offline', ['ML-2-1'], '');

$row = static function (int $id): array {
    $stmt = db()->prepare('SELECT * FROM reservations WHERE id = ?');
    $stmt->execute([$id]);
    return $stmt->fetch();
};
echo json_encode([
    'pendingVs' => $row($pending)['variable_symbol'],
    'ticket' => ticket_code($row($paid)),
    'offlineTicket' => ticket_code($row($offline)),
], JSON_UNESCAPED_UNICODE);
