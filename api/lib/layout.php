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
//                'reserve' => true marks reserve rows: their seats are "Záložní" (not for
//                sale) in a new run; the admin can switch any seat of a run (admin → Plánek);
//   label      – [x, y, align] where the section's name is shown on the map (y = top);
//   row_label  – 'end': row numbers after the last seat of each row (default: before seat 1).
const LEVELS = [
    'main' => 'Hlavní sál',
    'balcony' => 'Balkon',
];

const PLAN = [
    // the stage spans both main sections; the band's top is level with the first rows
    'main' => ['height' => 49.5, 'items' => [
        ['kind' => 'stage', 'label' => 'Pódium', 'x' => 24.1, 'y' => 0, 'w' => 51.8, 'h' => 8],
        ['kind' => 'band', 'label' => 'Kapela', 'x' => 6.5, 'y' => 15.6, 'w' => 14, 'h' => 12],
    ]],
    // the top 8 units hold the labels of the side parts
    'balcony' => ['height' => 66, 'items' => [
        ['kind' => 'void', 'label' => '↑ pódium', 'x' => 33, 'y' => 0, 'w' => 34, 'h' => 41],
    ]],
];

const SECTIONS = [
    'LK' => ['name' => 'Levé křídlo', 'short' => 'L. křídlo', 'level' => 'main', 'label' => [13.6, 9.5, 'center'], 'row_label' => 'end', 'rows' => [
        [5, 8.2, 30.5, 19, 30.5], [5, 8.2, 33.7, 19, 33.7], [5, 8.2, 36.9, 19, 36.9], [5, 8.2, 40.1, 19, 40.1],
    ]],
    'LH' => ['name' => 'Levá hlavní', 'short' => 'Levá hlavní', 'level' => 'main', 'label' => [35.45, 9.5, 'center'], 'row_label' => 'end', 'rows' => [
        [8, 26, 17.5, 44.9, 17.5], [8, 26, 21.1, 44.9, 21.1], [8, 26, 24.7, 44.9, 24.7], [8, 26, 28.3, 44.9, 28.3],
        [8, 26, 31.9, 44.9, 31.9], [8, 26, 35.5, 44.9, 35.5], [8, 26, 39.1, 44.9, 39.1], [8, 26, 42.7, 44.9, 42.7],
        [8, 26, 46.3, 44.9, 46.3],
    ]],
    'PH' => ['name' => 'Pravá hlavní', 'short' => 'Pravá hlavní', 'level' => 'main', 'label' => [64.55, 9.5, 'center'], 'rows' => [
        [8, 55.1, 17.5, 74, 17.5], [8, 55.1, 21.1, 74, 21.1], [8, 55.1, 24.7, 74, 24.7], [8, 55.1, 28.3, 74, 28.3],
        [8, 55.1, 31.9, 74, 31.9], [8, 55.1, 35.5, 74, 35.5], [8, 55.1, 39.1, 74, 39.1], [8, 55.1, 42.7, 74, 42.7],
        [8, 55.1, 46.3, 74, 46.3],
    ]],
    'PK' => ['name' => 'Pravé křídlo', 'short' => 'P. křídlo', 'level' => 'main', 'label' => [86.4, 9.5, 'center'], 'rows' => [
        [4, 82.35, 17.5, 90.45, 17.5], [4, 82.35, 21.1, 90.45, 21.1], [4, 82.35, 24.7, 90.45, 24.7],
        [5, 81, 28.3, 91.8, 28.3], [5, 81, 31.9, 91.8, 31.9], [5, 81, 35.5, 91.8, 35.5],
        [4, 82.35, 41, 90.45, 41],
    ]],
    'BL' => ['name' => 'Balkon vlevo', 'short' => 'Balkon vlevo', 'level' => 'balcony', 'label' => [31.5, 0.5, 'right'], 'rows' => [
        ['parts' => [[5, 30, 10.5, 30, 21.3], [7, 30, 25.5, 30, 41.7]]], // bench along the railing: seats 1–5 | 6–12
        // reserve rows behind it: rows 2 and 3 seats 1–5 | 6–11, row 4 only beside the back part
        ['reserve' => true, 'parts' => [[5, 25.5, 10.5, 25.5, 21.3], [6, 25.5, 25.5, 25.5, 39]]],
        ['reserve' => true, 'parts' => [[5, 22, 10.5, 22, 21.3], [6, 22, 25.5, 22, 39]]],
        ['reserve' => true, 'parts' => [[6, 18.5, 25.5, 18.5, 39]]],
    ]],
    'BS' => ['name' => 'Balkon střed', 'short' => 'Balkon střed', 'level' => 'balcony', 'label' => [50, 60.5, 'center'], 'rows' => [
        [7, 32.45, 45, 48.65, 45], [14, 32.45, 48.2, 67.55, 48.2], [14, 32.45, 51.4, 67.55, 51.4],
        [7, 45.95, 54.6, 62.15, 54.6], [7, 45.95, 57.8, 62.15, 57.8],
    ]],
    'BP' => ['name' => 'Balkon vpravo', 'short' => 'Balkon vpravo', 'level' => 'balcony', 'label' => [68.5, 0.5, 'left'], 'rows' => [
        // like the left part (mirrored): the bench along the railing (seats 1–6 | 7–11), reserve rows behind it
        ['parts' => [[6, 70, 10.5, 70, 24], [5, 70, 29.5, 70, 40.3]]],
        ['reserve' => true, 'parts' => [[3, 74.5, 27.5, 74.5, 32.9]]], ['reserve' => true, 'parts' => [[3, 78, 27.5, 78, 32.9]]],
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

/** Seat ids of the section in layout order. */
function section_seat_ids(string $id, array $section): array
{
    $ids = [];
    foreach (section_row_sizes($section) as $r => $count) {
        for ($n = 1; $n <= $count; $n++) {
            $ids[] = $id . '-' . ($r + 1) . '-' . $n;
        }
    }
    return $ids;
}

/** Seats that are "Záložní" (not for sale) in a run until the admin changes it: the rows marked 'reserve'. */
function default_reserve_seats(): array
{
    $ids = [];
    foreach (SECTIONS as $id => $section) {
        foreach (section_rows($section) as $r => $row) {
            for ($n = 1; $row['reserve'] && $n <= $row['seats']; $n++) {
                $ids[] = $id . '-' . ($r + 1) . '-' . $n;
            }
        }
    }
    return $ids;
}

/**
 * Layout for the apps: {levels, plan: {main|balcony: {height, items}}, sections: [{id, name, short,
 * level, label: {x, y, align}, rowLabel: start|end, rows: [{seats, reserve, parts: [{seats, x1, y1, x2, y2}]}]}]}.
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
            'rowLabel' => $s['row_label'] ?? 'start',
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

/** Only the exact form "LH-1-2" (no leading zeros, nothing after it), so one chair has one id. */
function is_valid_seat_id(string $id): bool
{
    if (!preg_match('/^([A-Z]{2})-([1-9]\d?)-([1-9]\d?)\z/', $id, $m)) {
        return false;
    }
    $sizes = isset(SECTIONS[$m[1]]) ? section_row_sizes(SECTIONS[$m[1]]) : [];
    $row = (int) $m[2];
    $seat = (int) $m[3];
    return $row >= 1 && $row <= count($sizes) && $seat >= 1 && $seat <= $sizes[$row - 1];
}

/** Full name of a section ("LH" → "Levá hlavní"), the id itself when unknown. */
function section_name(string $id): string
{
    foreach (SECTIONS as $sid => $section) {
        if ($sid === $id) {
            return $section['name'];
        }
    }
    return $id;
}

/** Section id of a seat id ("LH-1-2" → "LH"). */
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
