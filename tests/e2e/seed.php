<?php
// Fresh test database for the Playwright tests; prints the data the tests need as JSON.
//   run 1 "Premiéra" in a month – bookable, one pending reservation for the admin test
//   run 2 "Dnes" starting in 20 minutes – inside the scan window: a paid ticket and a VIP guest
declare(strict_types=1);

require __DIR__ . '/../php/fixtures.php';

reset_database();
$pdo = db();
$pdo->prepare('UPDATE runs SET label = ?, starts_at = ? WHERE id = 1')->execute(['Premiéra', db_time(now_utc()->modify('+30 days'))]);
$pdo->prepare('UPDATE runs SET label = ?, starts_at = ? WHERE id = 2')->execute(['Dnes', db_time(now_utc()->modify('+20 minutes'))]);
runs(true);

$pending = reservation(['MR-5-1', 'MR-5-2'], 'pending', 1, 'Platící');
$paid = reservation(['BC-1-1', 'BC-1-2'], 'paid', 2, 'Vstupenka');
add_vip_guest(2, 'Mons. Testovací', ['ML-1-1', 'ML-1-2'], '');

$row = static function (int $id): array {
    $stmt = db()->prepare('SELECT * FROM reservations WHERE id = ?');
    $stmt->execute([$id]);
    return $stmt->fetch();
};
echo json_encode([
    'pendingVs' => $row($pending)['variable_symbol'],
    'ticket' => ticket_code($row($paid)),
], JSON_UNESCAPED_UNICODE);
