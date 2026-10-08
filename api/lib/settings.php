<?php
// Runs (dates) of the event, managed in admin → Nastavení.
//   starts_at          – UTC; bookings and customer cancellations close then
//   booking_closes_at  – optional earlier end of bookings (UTC)
//   storno_rules       – [{"from": "YYYY-MM-DD HH:MM" (Europe/Prague), "percent": 50}, ...]
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
        foreach (db()->query('SELECT * FROM runs ORDER BY starts_at, id')->fetchAll() as $run) {
            $run['id'] = (int) $run['id'];
            $run['storno_rules'] = normalize_storno_rules((array) json_decode($run['storno_rules'], true));
            $cache[$run['id']] = $run;
        }
    }
    return $cache;
}

function run_by_id(int $id): ?array
{
    return runs()[$id] ?? null;
}

/** @return array<int, array{from: string, percent: int}> valid rules sorted by date */
function normalize_storno_rules(array $rules): array
{
    $rules = array_values(array_filter(
        $rules,
        static fn ($r) => prague_time((string) ($r['from'] ?? '')) !== null
    ));
    $rules = array_map(static fn ($r) => ['from' => (string) $r['from'], 'percent' => (int) $r['percent']], $rules);
    usort($rules, static fn ($a, $b) => strcmp($a['from'], $b['from']));
    return $rules;
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
        if (prague_time($rule['from']) <= $at) {
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
        'stornoRules' => array_map(static fn ($r) => [
            'from' => iso_utc(prague_time($r['from'])),
            'percent' => $r['percent'],
        ], $run['storno_rules']),
    ];
}

/** Start of the last run, or null when there are no runs. */
function last_run_start(): ?DateTimeImmutable
{
    $runs = runs();
    return $runs ? run_starts(end($runs)) : null;
}
