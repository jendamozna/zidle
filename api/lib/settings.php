<?php
// Admin-editable settings stored in the `settings` table (JSON values).
//   event_at     – "YYYY-MM-DD HH:MM" Europe/Prague; cancellation closes then, personal data is deleted after it
//   storno_rules – [{"from": "YYYY-MM-DD HH:MM", "percent": 50}, ...] sorted by date
declare(strict_types=1);

const PRAGUE = 'Europe/Prague';

function setting(string $name, $default = null)
{
    static $cache = null;
    if ($cache === null) {
        $cache = [];
        foreach (db()->query('SELECT name, value FROM settings')->fetchAll() as $row) {
            $cache[$row['name']] = json_decode($row['value'], true);
        }
    }
    return $cache[$name] ?? $default;
}

function save_setting(string $name, $value): void
{
    db()->prepare('INSERT INTO settings (name, value) VALUES (?, ?) ON DUPLICATE KEY UPDATE value = VALUES(value)')
        ->execute([$name, json_encode($value, JSON_UNESCAPED_UNICODE)]);
}

function prague_time(string $local): ?DateTimeImmutable
{
    $local = trim($local);
    if ($local === '') {
        return null;
    }
    try {
        return new DateTimeImmutable($local, new DateTimeZone(PRAGUE));
    } catch (Exception) {
        return null;
    }
}

function event_at(): ?DateTimeImmutable
{
    return prague_time((string) setting('event_at', ''));
}

/** @return array<int, array{from: string, percent: int}> sorted by date */
function storno_rules(): array
{
    $rules = array_values(array_filter(
        (array) setting('storno_rules', []),
        static fn ($r) => prague_time((string) ($r['from'] ?? '')) !== null
    ));
    usort($rules, static fn ($a, $b) => strcmp($a['from'], $b['from']));
    return $rules;
}

/** Storno percentage that applies at the given moment (0 when no rule has started yet). */
function storno_percent(DateTimeImmutable $at): int
{
    $percent = 0;
    foreach (storno_rules() as $rule) {
        if (prague_time($rule['from']) <= $at) {
            $percent = max($percent, (int) $rule['percent']);
        }
    }
    return $percent;
}

/** Public form of the rules: ISO dates for the frontend. */
function storno_rules_public(): array
{
    return array_map(static fn ($r) => [
        'from' => prague_time($r['from'])->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z'),
        'percent' => (int) $r['percent'],
    ], storno_rules());
}

function event_at_public(): ?string
{
    $event = event_at();
    return $event?->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z');
}
