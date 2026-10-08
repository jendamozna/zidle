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
 * run 1 in a month (50 % storno from yesterday), run 2 started an hour ago.
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
    $rules = json_encode([['from' => iso_utc(now_utc()->modify('-1 day')), 'percent' => 50]]);
    $pdo->prepare('INSERT INTO runs (id, label, starts_at, storno_rules) VALUES (1, ?, ?, ?), (2, ?, ?, ?)')->execute([
        'Budoucí', db_time(now_utc()->modify('+30 days')), $rules,
        'Proběhlé', db_time(now_utc()->modify('-1 hour')), '[]',
    ]);
    runs(true);
}

/** Pending (or paid) reservation of 300 Kč seats held in the run; returns its id. */
function reservation(array $seats, string $status = 'pending', int $run = 1, string $lastName = ''): int
{
    static $n = 0;
    $n++;
    $pdo = db();
    $amount = 300 * count($seats);
    $pdo->prepare(
        "INSERT INTO reservations (token, run_id, first_name, last_name, email, seats, seat_count, amount,
           variable_symbol, status, created_at, expires_at, paid_at, paid_amount)
         VALUES (?, ?, 'Test', ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
    )->execute([
        bin2hex(random_bytes(16)), $run, $lastName ?: "T{$n}", "test{$n}@example.com", implode(',', $seats), count($seats), $amount,
        (string) (1000000000 + $n), $status, db_time(now_utc()), db_time(now_utc()->modify('+3 days')),
        $status === 'paid' ? db_time(now_utc()) : null, $status === 'paid' ? $amount : null,
    ]);
    $id = (int) $pdo->lastInsertId();
    $insert = $pdo->prepare('INSERT INTO reservation_seats (run_id, seat_id, reservation_id) VALUES (?, ?, ?)');
    foreach ($seats as $seat) {
        $insert->execute([$run, $seat, $id]);
    }
    return $id;
}
