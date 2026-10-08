<?php
// Seating layout of the church – the only definition. The customer app and the
// scanner load it from the server (layout_public() in seats.php / organizer.php).
//   name/short – full and short Czech name; level – key of LEVELS;
//   group      – place in the floor plan: left / right (main floor, stage on top),
//                balcony (U-shaped, in list order);
//   rows/seats – rows and chairs per row (row 1 nearest the stage or railing);
//   rotated    – drawn turned by 90° (rows run vertically), rowSide = side of row 1.
const LEVELS = [
    'main' => 'Hlavní loď',
    'balcony' => 'Balkon',
];

const SECTIONS = [
    'WL' => ['name' => 'Levé křídlo', 'short' => 'L. křídlo', 'level' => 'main', 'group' => 'left', 'rows' => 4, 'seats' => 6],
    'ML' => ['name' => 'Levá hlavní', 'short' => 'L. hlavní', 'level' => 'main', 'group' => 'left', 'rows' => 10, 'seats' => 8],
    'MR' => ['name' => 'Pravá hlavní', 'short' => 'P. hlavní', 'level' => 'main', 'group' => 'right', 'rows' => 10, 'seats' => 8],
    'WR' => ['name' => 'Pravé křídlo', 'short' => 'P. křídlo', 'level' => 'main', 'group' => 'right', 'rows' => 6, 'seats' => 6],
    'BL' => ['name' => 'Balkon vlevo', 'short' => 'Balkon L', 'level' => 'balcony', 'group' => 'balcony', 'rows' => 4, 'seats' => 12, 'rotated' => true, 'rowSide' => 'right'],
    'BC' => ['name' => 'Balkon střed', 'short' => 'Balkon S', 'level' => 'balcony', 'group' => 'balcony', 'rows' => 4, 'seats' => 12],
    'BR' => ['name' => 'Balkon vpravo', 'short' => 'Balkon P', 'level' => 'balcony', 'group' => 'balcony', 'rows' => 2, 'seats' => 10, 'rotated' => true, 'rowSide' => 'left'],
];

/** Layout for the apps: {levels, sections: [{id, name, short, level, group, rows, seatsPerRow, rotated, rowSide}]}. */
function layout_public(): array
{
    $sections = [];
    foreach (SECTIONS as $id => $s) {
        $sections[] = [
            'id' => $id,
            'name' => $s['name'],
            'short' => $s['short'],
            'level' => $s['level'],
            'group' => $s['group'],
            'rows' => $s['rows'],
            'seatsPerRow' => $s['seats'],
            'rotated' => $s['rotated'] ?? false,
            'rowSide' => $s['rowSide'] ?? null,
        ];
    }
    return ['levels' => LEVELS, 'sections' => $sections];
}

function total_capacity(): int
{
    return array_sum(array_map(static fn ($s) => $s['rows'] * $s['seats'], SECTIONS));
}

function is_valid_seat_id(string $id): bool
{
    if (!preg_match('/^([A-Z]{2})-(\d{1,2})-(\d{1,2})$/', $id, $m)) {
        return false;
    }
    $section = SECTIONS[$m[1]] ?? null;
    $row = (int) $m[2];
    $seat = (int) $m[3];
    return $section !== null
        && $row >= 1 && $row <= $section['rows']
        && $seat >= 1 && $seat <= $section['seats'];
}

/** Sort order of seat ids: section (layout order), row, seat. */
function compare_seat_ids(string $a, string $b): int
{
    $order = array_flip(array_keys(SECTIONS));
    [$sa, $ra, $na] = explode('-', $a);
    [$sb, $rb, $nb] = explode('-', $b);
    return [$order[$sa] ?? 99, (int) $ra, (int) $na] <=> [$order[$sb] ?? 99, (int) $rb, (int) $nb];
}
