<?php
// Must match src/data/layout.js.
const SECTIONS = [
    'WL' => ['name' => 'Levé křídlo', 'rows' => 4, 'seats' => 6],
    'ML' => ['name' => 'Levá hlavní', 'rows' => 10, 'seats' => 8],
    'MR' => ['name' => 'Pravá hlavní', 'rows' => 10, 'seats' => 8],
    'WR' => ['name' => 'Pravé křídlo', 'rows' => 6, 'seats' => 6],
    'BL' => ['name' => 'Balkon vlevo', 'rows' => 4, 'seats' => 12],
    'BC' => ['name' => 'Balkon střed', 'rows' => 4, 'seats' => 12],
    'BR' => ['name' => 'Balkon vpravo', 'rows' => 2, 'seats' => 10],
];

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
