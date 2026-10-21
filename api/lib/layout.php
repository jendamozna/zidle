<?php
// Seating layout of the church – the only definition. The customer app and the
// scanner load it from the server (layout_public() in seats.php / organizer.php).
//
// PLAN: one map per level (main floor with the stage on top, U-shaped balcony),
//   width 100 units, `height` units tall; `items` are drawn decorations:
//   stage / band / void (the open middle of the balcony) with x, y, w, h and a label.
// SECTIONS (in this order everywhere):
//   name/short – full and short Czech name; level – key of LEVELS;
//   rows       – one entry per row (row 1 first: nearest the stage or the balcony railing):
//                [number of seats, x1, y1, x2, y2] = centres of the first and the last seat
//                in plan units, the seats lie evenly on that line; a row split by a gap is
//                ['parts' => [[seats, x1, y1, x2, y2], …]] (numbering goes on across the parts);
//                'reserve' => true marks reserve seats ("Záložní"): not for sale until the
//                admin opens the section's reserve seats for a run;
//   label      – [x, y, align] where the section's name is shown on the map (y = top).
const LEVELS = [
    'main' => 'Hlavní loď',
    'balcony' => 'Balkon',
];

const PLAN = [
    'main' => ['height' => 54, 'items' => [
        ['kind' => 'stage', 'label' => 'Pódium', 'x' => 32, 'y' => 0, 'w' => 36, 'h' => 8],
        ['kind' => 'band', 'label' => 'Kapela', 'x' => 1.5, 'y' => 23, 'w' => 14, 'h' => 12],
    ]],
    'balcony' => ['height' => 58, 'items' => [
        ['kind' => 'void', 'label' => '↑ k pódiu', 'x' => 33, 'y' => 0, 'w' => 34, 'h' => 33],
    ]],
];

const SECTIONS = [
    'WL' => ['name' => 'Levé křídlo', 'short' => 'L. křídlo', 'level' => 'main', 'label' => [8.6, 52.5, 'center'], 'rows' => [
        [5, 3.2, 38.5, 14, 38.5], [5, 3.2, 41.7, 14, 41.7], [5, 3.2, 44.9, 14, 44.9], [5, 3.2, 48.1, 14, 48.1],
    ]],
    'ML' => ['name' => 'Levá hlavní', 'short' => 'L. hlavní', 'level' => 'main', 'label' => [30.45, 9.5, 'center'], 'rows' => [
        [8, 21, 17.5, 39.9, 17.5], [8, 21, 21.1, 39.9, 21.1], [8, 21, 24.7, 39.9, 24.7], [8, 21, 28.3, 39.9, 28.3],
        [8, 21, 31.9, 39.9, 31.9], [8, 21, 35.5, 39.9, 35.5], [8, 21, 39.1, 39.9, 39.1], [8, 21, 42.7, 39.9, 42.7],
        [8, 21, 46.3, 39.9, 46.3],
    ]],
    'MR' => ['name' => 'Pravá hlavní', 'short' => 'P. hlavní', 'level' => 'main', 'label' => [69.55, 9.5, 'center'], 'rows' => [
        [8, 60.1, 17.5, 79, 17.5], [8, 60.1, 21.1, 79, 21.1], [8, 60.1, 24.7, 79, 24.7], [8, 60.1, 28.3, 79, 28.3],
        [8, 60.1, 31.9, 79, 31.9], [8, 60.1, 35.5, 79, 35.5], [8, 60.1, 39.1, 79, 39.1], [8, 60.1, 42.7, 79, 42.7],
        [8, 60.1, 46.3, 79, 46.3],
    ]],
    'WR' => ['name' => 'Pravé křídlo', 'short' => 'P. křídlo', 'level' => 'main', 'label' => [90.4, 12.5, 'center'], 'rows' => [
        [4, 86.35, 21.5, 94.45, 21.5], [4, 86.35, 25.1, 94.45, 25.1], [4, 86.35, 28.7, 94.45, 28.7],
        [5, 85, 32.3, 95.8, 32.3], [5, 85, 35.9, 95.8, 35.9], [5, 85, 39.5, 95.8, 39.5],
        [4, 86.35, 45, 94.45, 45],
    ]],
    'BL' => ['name' => 'Balkon vlevo', 'short' => 'Balkon L', 'level' => 'balcony', 'label' => [34, 1.5, 'left'], 'rows' => [
        ['parts' => [[5, 30, 2.5, 30, 13.3], [7, 30, 17.5, 30, 33.7]]], // bench along the railing: seats 1–5 | 6–12
        ['reserve' => true, 'parts' => [[5, 25.5, 2.5, 25.5, 13.3]]], ['reserve' => true, 'parts' => [[5, 22, 2.5, 22, 13.3]]],
        ['reserve' => true, 'parts' => [[6, 25.5, 17.5, 25.5, 31]]], ['reserve' => true, 'parts' => [[6, 22, 17.5, 22, 31]]],
        ['reserve' => true, 'parts' => [[6, 18.5, 17.5, 18.5, 31]]],
    ]],
    'BC' => ['name' => 'Balkon střed', 'short' => 'Balkon S', 'level' => 'balcony', 'label' => [50, 52.5, 'center'], 'rows' => [
        [7, 32.45, 37, 48.65, 37], [14, 32.45, 40.2, 67.55, 40.2], [14, 32.45, 43.4, 67.55, 43.4],
        [7, 45.95, 46.6, 62.15, 46.6], [7, 45.95, 49.8, 62.15, 49.8],
    ]],
    'BR' => ['name' => 'Balkon vpravo', 'short' => 'Balkon P', 'level' => 'balcony', 'label' => [66, 1.5, 'right'], 'rows' => [
        // like the left part (mirrored): benches along the railing, reserve rows behind them
        [6, 70, 2.5, 70, 16], [5, 70, 21.5, 70, 32.3],
        ['reserve' => true, 'parts' => [[3, 74.5, 19.5, 74.5, 24.9]]], ['reserve' => true, 'parts' => [[3, 78, 19.5, 78, 24.9]]],
    ]],
];

/**
 * Rows of the section in one shape: [['seats' => n, 'reserve' => bool, 'parts' => [[n, x1, y1, x2, y2], …]], …].
 */
function section_rows(array $section): array
{
    $rows = [];
    foreach ($section['rows'] as $row) {
        $parts = isset($row['parts']) ? $row['parts'] : [$row];
        $rows[] = [
            'seats' => (int) array_sum(array_column($parts, 0)),
            'reserve' => !empty($row['reserve']),
            'parts' => array_values($parts),
        ];
    }
    return $rows;
}

/** Number of seats in each row of the section. */
function section_row_sizes(array $section): array
{
    return array_column(section_rows($section), 'seats');
}

function section_capacity(array $section): int
{
    return array_sum(section_row_sizes($section));
}

/** Reserve seats of the section (rows marked 'reserve'). */
function section_reserve_capacity(array $section): int
{
    return array_sum(array_column(array_filter(section_rows($section), static fn ($r) => $r['reserve']), 'seats'));
}

/** Ids of the sections that have reserve seats. */
function reserve_section_ids(): array
{
    return array_keys(array_filter(SECTIONS, static fn ($s) => section_reserve_capacity($s) > 0));
}

/** True for a seat in a reserve row (e.g. "BL-2-1"). */
function seat_is_reserve(string $id): bool
{
    [$section, $row] = array_pad(explode('-', $id), 2, '');
    foreach (SECTIONS as $sid => $def) {
        if ($sid === $section) {
            return (section_rows($def)[(int) $row - 1]['reserve'] ?? false) === true;
        }
    }
    return false;
}

/**
 * Layout for the apps: {levels, plan: {main|balcony: {height, items}}, sections: [{id, name, short,
 * level, label: {x, y, align}, rows: [{seats, reserve, parts: [{seats, x1, y1, x2, y2}]}]}]}.
 */
function layout_public(): array
{
    $sections = [];
    foreach (SECTIONS as $id => $s) {
        $sections[] = [
            'id' => $id,
            'name' => $s['name'],
            'short' => $s['short'],
            'level' => $s['level'],
            'label' => ['x' => $s['label'][0], 'y' => $s['label'][1], 'align' => $s['label'][2]],
            'rows' => array_map(static fn ($r) => [
                'seats' => $r['seats'],
                'reserve' => $r['reserve'],
                'parts' => array_map(static fn ($p) => ['seats' => $p[0], 'x1' => $p[1], 'y1' => $p[2], 'x2' => $p[3], 'y2' => $p[4]], $r['parts']),
            ], section_rows($s)),
        ];
    }
    return ['levels' => LEVELS, 'plan' => PLAN, 'sections' => $sections];
}

/** All seats of the layout, reserve seats included. */
function total_capacity(): int
{
    return array_sum(array_map('section_capacity', SECTIONS));
}

function is_valid_seat_id(string $id): bool
{
    if (!preg_match('/^([A-Z]{2})-(\d{1,2})-(\d{1,2})$/', $id, $m)) {
        return false;
    }
    $sizes = isset(SECTIONS[$m[1]]) ? section_row_sizes(SECTIONS[$m[1]]) : [];
    $row = (int) $m[2];
    $seat = (int) $m[3];
    return $row >= 1 && $row <= count($sizes) && $seat >= 1 && $seat <= $sizes[$row - 1];
}

/** Full name of a section ("ML" → "Levá hlavní"), the id itself when unknown. */
function section_name(string $id): string
{
    foreach (SECTIONS as $sid => $section) {
        if ($sid === $id) {
            return $section['name'];
        }
    }
    return $id;
}

/** Section id of a seat id ("ML-1-2" → "ML"). */
function seat_section(string $id): string
{
    return explode('-', $id)[0];
}

/** Sort order of seat ids: section (layout order), row, seat. */
function compare_seat_ids(string $a, string $b): int
{
    $order = array_flip(array_keys(SECTIONS));
    [$sa, $ra, $na] = explode('-', $a);
    [$sb, $rb, $nb] = explode('-', $b);
    return [$order[$sa] ?? 99, (int) $ra, (int) $na] <=> [$order[$sb] ?? 99, (int) $rb, (int) $nb];
}
