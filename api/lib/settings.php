<?php
// Runs (dates) of the event, managed in admin → Nastavení.
// All times are stored in UTC; Europe/Prague is used only for input and display.
//   starts_at          – UTC; bookings and customer cancellations close then
//   booking_closes_at  – optional earlier end of bookings (UTC)
//   storno_rules       – [{"from": "YYYY-MM-DDTHH:MM:SSZ" (UTC), "percent": 50}, ...]
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

/** All runs ordered by start, with decoded storno rules. */
function runs(bool $fresh = false): array
{
    static $cache = null;
    if ($cache === null || $fresh) {
        $cache = [];
        foreach (db_query('SELECT * FROM runs ORDER BY starts_at, id')->fetchAll() as $run) {
            $run['id'] = (int) $run['id'];
            $raw = $run['storno_rules'];
            $run['storno_rules'] = normalize_storno_rules((array) json_decode($raw, true));
            $run['reserve_seats'] = ($run['reserve_seats'] ?? null) === null
                ? default_reserve_seats()
                : array_values(array_filter((array) json_decode((string) $run['reserve_seats'], true), static fn ($id) => is_string($id) && is_valid_seat_id($id)));
            // Older rows stored rule dates in Prague local time: rewrite them in UTC once.
            $encoded = json_encode($run['storno_rules']);
            if ($encoded !== $raw) {
                db()->prepare('UPDATE runs SET storno_rules = ? WHERE id = ?')->execute([$encoded, $run['id']]);
            }
            $cache[$run['id']] = $run;
        }
    }
    return $cache;
}

/** Storno rule date: UTC ISO ("…Z"), or the older Prague local "YYYY-MM-DD HH:MM". */
function storno_from(string $value): ?DateTimeImmutable
{
    if (preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/', $value)) {
        return new DateTimeImmutable($value);
    }
    return prague_time($value);
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

/** @return array<int, array{from: string, percent: int}> valid rules, dates in UTC ISO, sorted */
function normalize_storno_rules(array $rules): array
{
    $out = [];
    foreach ($rules as $r) {
        $from = storno_from((string) ($r['from'] ?? ''));
        if ($from !== null) {
            $out[] = ['from' => iso_utc($from), 'percent' => (int) ($r['percent'] ?? 0)];
        }
    }
    usort($out, static fn ($a, $b) => strcmp($a['from'], $b['from']));
    return $out;
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

/** Storno percentage of the run at the given moment (0 before its first rule). */
function storno_percent(array $run, DateTimeImmutable $at): int
{
    $percent = 0;
    foreach ($run['storno_rules'] as $rule) {
        if (storno_from($rule['from']) <= $at) {
            $percent = max($percent, (int) $rule['percent']);
        }
    }
    return $percent;
}

/** "so 19. 12. 2026 18:00" (+ " · Premiéra") */
function run_label(array $run): string
{
    $start = run_starts($run)->setTimezone(new DateTimeZone(PRAGUE));
    $text = CZECH_WEEKDAYS[(int) $start->format('w')] . ' ' . $start->format('j. n. Y H:i');
    return $run['label'] !== '' ? $text . ' · ' . $run['label'] : $text;
}

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
        'stornoRules' => $run['storno_rules'],
        'reserveSeats' => $run['reserve_seats'],
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

/** Start of the last run, or null when there are no runs. */
function last_run_start(): ?DateTimeImmutable
{
    $runs = runs();
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
        $from = is_array($r) ? storno_from((string) ($r['from'] ?? '')) : null;
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
