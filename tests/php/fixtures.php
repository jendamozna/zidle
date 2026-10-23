<?php
// Test database helpers shared by tests/php/run.php and tests/e2e/seed.php.
declare(strict_types=1);

require_once __DIR__ . '/../../api/lib/bootstrap.php';

if (!str_ends_with((string) config('DB_NAME'), '_test')) {
    fwrite(STDERR, "Refusing to run: DB_NAME must end with _test (the database is wiped).\n");
    exit(2);
}

/**
 * Recreates all tables from db/schema.sql with two runs:
 * run 1 in a month, run 2 started an hour ago.
 */
function reset_database(): void
{
    $pdo = db();
    $pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
    foreach (db_query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN) as $table) {
        $pdo->exec("DROP TABLE `{$table}`");
    }
    $pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
    $schema = (string) file_get_contents(__DIR__ . '/../../db/schema.sql');
    foreach (array_filter(array_map('trim', explode(";\n", $schema))) as $statement) {
        $pdo->exec($statement);
    }
    $pdo->prepare('INSERT INTO runs (id, label, starts_at) VALUES (1, ?, ?), (2, ?, ?)')->execute([
        'Budoucí', db_time(now_utc()->modify('+30 days')),
        'Proběhlé', db_time(now_utc()->modify('-1 hour')),
    ]);
    runs(true);
}

/**
 * Pending (or paid) reservation held in the run; adult seats 300 Kč, $children seats 150 Kč,
 * $free seats paid by a discount code (0 Kč). Returns its id.
 */
function reservation(array $seats, string $status = 'pending', int $run = 1, string $lastName = '', array $children = [], array $free = []): int
{
    static $n = 0;
    $n++;
    $pdo = db();
    $items = new_reservation_items($seats, $children, $free, 300, 150);
    $amount = array_sum(array_column($items, 'price'));
    $pdo->prepare(
        "INSERT INTO reservations (token, run_id, first_name, last_name, email, amount, specific_symbol, status, created_at, expires_at, paid_at, paid_amount)
         VALUES (?, ?, 'Test', ?, ?, ?, ?, ?, ?, ?, ?, ?)"
    )->execute([
        bin2hex(random_bytes(16)), $run, $lastName ?: "T{$n}", "test{$n}@example.com", $amount,
        (string) (10000 + $n), $status, db_time(now_utc()), db_time(now_utc()->modify('+3 days')),
        $status === 'paid' ? db_time(now_utc()) : null, $status === 'paid' && $amount > 0 ? $amount : null,
    ]);
    $id = (int) $pdo->lastInsertId();
    insert_reservation_items($pdo, $id, $items);
    $insert = $pdo->prepare('INSERT INTO reservation_seats (run_id, seat_id, reservation_id) VALUES (?, ?, ?)');
    foreach ($seats as $seat) {
        $insert->execute([$run, $seat, $id]);
    }
    return $id;
}
