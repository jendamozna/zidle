<?php
// GET api/seats.php?run=<id> – runs with free seat counts (test runs only when they are
// the requested one), the seats of the given run held by pending or paid reservations, the seating layout.
declare(strict_types=1);
require __DIR__ . '/lib/bootstrap.php';

run_api(function (): void {
    if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
        json_error('Metoda není povolena.', 405);
    }
    expire_reservations();
    if (random_int(1, 100) === 1) {
        cleanup_rate_limits();
    }

    $run = run_by_id((int) ($_GET['run'] ?? 0));
    $held = db_query('SELECT run_id, COUNT(*) FROM reservation_seats GROUP BY run_id')->fetchAll(PDO::FETCH_KEY_PAIR);
    // Test runs are not listed publicly – only when requested by their link (?termin=<id>).
    $runs = array_values(array_map(
        static fn ($r) => run_public($r, run_capacity($r) - (int) ($held[$r['id']] ?? 0)),
        array_filter(runs(), static fn ($r) => !$r['is_test'] || $r['id'] === ($run['id'] ?? null))
    ));

    $taken = [];
    if ($run !== null) {
        $stmt = db()->prepare('SELECT seat_id FROM reservation_seats WHERE run_id = ?');
        $stmt->execute([$run['id']]);
        $taken = $stmt->fetchAll(PDO::FETCH_COLUMN);
    }

    json_response([
        'runs' => $runs,
        'runId' => $run['id'] ?? null,
        'taken' => $taken,
        'price' => adult_price(),
        'childPrice' => child_price(),
        'deadlineHours' => payment_deadline_hours(now_utc()),
        'maxSeats' => (int) config('MAX_SEATS_PER_RESERVATION'),
        'bookingOpen' => $run !== null && run_booking_open($run),
        'formToken' => form_token(),
        'contact' => contact_public(),
        'dataRetentionDays' => (int) config('DATA_RETENTION_DAYS'),
        'layout' => layout_public(),
    ]);
});
