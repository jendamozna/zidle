<?php
// GET api/seats.php – seats held by pending or paid reservations.
declare(strict_types=1);
require __DIR__ . '/lib/bootstrap.php';

run_api(function (): void {
    if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
        json_error('Metoda není povolena.', 405);
    }
    expire_reservations();
    $taken = db()->query('SELECT seat_id FROM reservation_seats')->fetchAll(PDO::FETCH_COLUMN);
    json_response([
        'taken' => $taken,
        'price' => (int) config('SEAT_PRICE'),
        'deadlineHours' => (int) config('PAYMENT_DEADLINE_HOURS'),
    ]);
});
