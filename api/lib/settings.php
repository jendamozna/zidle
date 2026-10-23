<?php
// Runs (dates) of the event, managed in admin → Nastavení.
// All times are stored in UTC; Europe/Prague is used only for input and display.
//   starts_at          – UTC; bookings close then
//   booking_closes_at  – optional earlier end of bookings (UTC)
//   reserve_seats      – seat ids that are "Záložní" (not for sale) in this run; NULL = default_reserve_seats()
declare(strict_types=1);

const PRAGUE = 'Europe/Prague';
const CZECH_WEEKDAYS = ['ne', 'po', 'út', 'st', 'čt', 'pá', 'so'];

function prague_time(string $local): ?DateTimeImmutable
{
    $local = trim(str_replace('T', ' ', $local));
    if ($local === '' || !preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}$/', $local)) {
        return null;
    }
    try {
        return new DateTimeImmutable($local, new DateTimeZone(PRAGUE));
    } catch (Exception) {
        return null;
    }
}

function utc_time(string $dbTime): DateTimeImmutable
{
    return new DateTimeImmutable($dbTime, new DateTimeZone('UTC'));
}

/** All runs ordered by start, with decoded reserve seats. */
function runs(bool $fresh = false): array
{
    static $cache = null;
    if ($cache === null || $fresh) {
        $cache = [];
        foreach (db_query('SELECT * FROM runs ORDER BY starts_at, id')->fetchAll() as $run) {
            $run['id'] = (int) $run['id'];
            $run['is_test'] = (bool) ($run['is_test'] ?? false);
            $run['reserve_seats'] = ($run['reserve_seats'] ?? null) === null
                ? default_reserve_seats()
                : array_values(array_filter((array) json_decode((string) $run['reserve_seats'], true), static fn ($id) => is_string($id) && is_valid_seat_id($id)));
            $cache[$run['id']] = $run;
        }
    }
    return $cache;
}

/** Rule date (payment deadlines) stored as UTC ISO "…Z", or null. */
function rule_from(string $value): ?DateTimeImmutable
{
    return preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/', $value) ? new DateTimeImmutable($value) : null;
}

/** Prague "YYYY-MM-DDTHH:MM" for <input type="datetime-local">. */
function prague_input(?DateTimeImmutable $t): string
{
    return $t ? $t->setTimezone(new DateTimeZone(PRAGUE))->format('Y-m-d\TH:i') : '';
}

function run_by_id(int $id): ?array
{
    return runs()[$id] ?? null;
}

function run_starts(array $run): DateTimeImmutable
{
    return utc_time($run['starts_at']);
}

function run_booking_closes(array $run): DateTimeImmutable
{
    return utc_time($run['booking_closes_at'] ?? $run['starts_at']);
}

function run_booking_open(array $run): bool
{
    return now_utc() < run_booking_closes($run);
}

function run_started(array $run): bool
{
    return now_utc() >= run_starts($run);
}

/** "so 19. 12. 2026 18:00" (+ " · Premiéra", + " · TEST" for a test run) */
function run_label(array $run): string
{
    $start = run_starts($run)->setTimezone(new DateTimeZone(PRAGUE));
    $text = CZECH_WEEKDAYS[(int) $start->format('w')] . ' ' . $start->format('j. n. Y H:i');
    $text = $run['label'] !== '' ? $text . ' · ' . $run['label'] : $text;
    return !empty($run['is_test']) ? $text . ' · TEST' : $text;
}

/** True when the reservation belongs to a test run. */
function reservation_is_test(array $r): bool
{
    return (bool) (run_by_id((int) $r['run_id'])['is_test'] ?? false);
}

/** SQL condition: the reservation (column run_id) is not on a test run – for totals. */
const NOT_TEST_RUN_SQL = 'run_id NOT IN (SELECT id FROM runs WHERE is_test = 1)';

function iso_utc(DateTimeImmutable $t): string
{
    return $t->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z');
}

/** True when the seat is "Záložní" (not for sale) in the run. */
function seat_closed_in_run(array $run, string $seat): bool
{
    return in_array($seat, $run['reserve_seats'], true);
}

/** Seats for sale in the run ("Záložní" seats left out). */
function run_capacity(array $run): int
{
    return total_capacity() - count($run['reserve_seats']);
}

/**
 * Admin → Plánek: the run's "Záložní" seats are exactly $seats (valid ids; a seat held by
 * a reservation stays for sale). $seats = null returns to default_reserve_seats().
 * Returns the seats that could not become "Záložní" because they are taken.
 */
function save_run_reserve_seats(int $runId, ?array $seats): array
{
    $held = [];
    if ($seats !== null) {
        $seats = array_values(array_unique(array_filter(array_map('strval', $seats), 'is_valid_seat_id')));
        $stmt = db()->prepare('SELECT seat_id FROM reservation_seats WHERE run_id = ?');
        $stmt->execute([$runId]);
        $held = array_values(array_intersect($seats, $stmt->fetchAll(PDO::FETCH_COLUMN)));
        $seats = array_values(array_diff($seats, $held));
        usort($seats, 'compare_seat_ids');
    }
    db()->prepare('UPDATE runs SET reserve_seats = ? WHERE id = ?')
        ->execute([$seats === null ? null : json_encode($seats), $runId]);
    runs(true);
    return $held;
}

/** Public form of a run for the frontend. */
function run_public(array $run, ?int $free = null): array
{
    return [
        'id' => $run['id'],
        'label' => $run['label'],
        'startsAt' => iso_utc(run_starts($run)),
        'bookingClosesAt' => iso_utc(run_booking_closes($run)),
        'bookingOpen' => run_booking_open($run),
        'free' => $free,
        'capacity' => run_capacity($run),
        'reserveSeats' => $run['reserve_seats'],
        'test' => $run['is_test'],
    ];
}

/** [from, to] of the check-in window around the start of the run. */
function run_scan_window(array $run): array
{
    $start = run_starts($run);
    return [
        $start->modify('-' . (int) config('SCAN_WINDOW_BEFORE_MINUTES') . ' minutes'),
        $start->modify('+' . (int) config('SCAN_WINDOW_AFTER_MINUTES') . ' minutes'),
    ];
}

function run_in_scan_window(array $run): bool
{
    [$from, $to] = run_scan_window($run);
    $now = now_utc();
    return $now >= $from && $now <= $to;
}

/** True once any reservation (in any status) exists for the run. */
function run_has_reservations(int $runId): bool
{
    $stmt = db()->prepare('SELECT 1 FROM reservations WHERE run_id = ? LIMIT 1');
    $stmt->execute([$runId]);
    return (bool) $stmt->fetchColumn();
}

/**
 * Deletes the run (admin → Nastavení); returns the flash message. A real run only without
 * reservations (seats, items and check-ins cascade);
 * scan conflicts and user run assignments cascade.
 */
function delete_run(int $id): string
{
    $run = run_by_id($id);
    if ($run !== null && !$run['is_test'] && run_has_reservations($id)) {
        return 'Termín má rezervace, nelze ho smazat.';
    }
    $pdo = db();
    $pdo->beginTransaction();
    try {
        $pdo->prepare('DELETE FROM reservations WHERE run_id = ?')->execute([$id]);
        $pdo->prepare('DELETE FROM runs WHERE id = ?')->execute([$id]);
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
    runs(true);
    return 'Termín smazán.';
}

/** Start of the last real (not test) run, or null when there are none. */
function last_run_start(): ?DateTimeImmutable
{
    $runs = array_filter(runs(), static fn ($run) => !$run['is_test']);
    return $runs ? run_starts(end($runs)) : null;
}

/**
 * Payment deadline rules (admin → Nastavení, stored in settings "payment_deadlines"):
 * [{"from": "YYYY-MM-DDTHH:MM:SSZ" (UTC), "hours": 24}, ...] sorted by from.
 * A reservation made at or after a rule's "from" gets that many hours to pay;
 * before the first rule PAYMENT_DEADLINE_HOURS applies.
 */
function payment_deadline_rules(): array
{
    $stmt = db()->prepare('SELECT value FROM settings WHERE name = ?');
    $stmt->execute(['payment_deadlines']);
    $rules = [];
    foreach ((array) json_decode((string) $stmt->fetchColumn(), true) as $r) {
        $from = is_array($r) ? rule_from((string) ($r['from'] ?? '')) : null;
        if ($from !== null && (int) ($r['hours'] ?? 0) > 0) {
            $rules[] = ['from' => iso_utc($from), 'hours' => (int) $r['hours']];
        }
    }
    usort($rules, static fn ($a, $b) => strcmp($a['from'], $b['from']));
    return $rules;
}

function save_payment_deadline_rules(array $rules): void
{
    usort($rules, static fn ($a, $b) => strcmp($a['from'], $b['from']));
    db()->prepare('INSERT INTO settings (name, value) VALUES (?, ?) ON DUPLICATE KEY UPDATE value = VALUES(value)')
        ->execute(['payment_deadlines', json_encode($rules)]);
}

/** Hours to pay for a reservation made at $at (the latest started rule, else PAYMENT_DEADLINE_HOURS). */
function payment_deadline_hours(DateTimeImmutable $at): int
{
    $hours = (int) config('PAYMENT_DEADLINE_HOURS');
    foreach (payment_deadline_rules() as $rule) {
        if (new DateTimeImmutable($rule['from']) <= $at) {
            $hours = $rule['hours'];
        }
    }
    return $hours;
}
