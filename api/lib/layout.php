<?php
// Must match src/data/layout.js.
const SECTIONS = [
    'WL' => ['rows' => 4, 'seats' => 6],
    'ML' => ['rows' => 10, 'seats' => 8],
    'MR' => ['rows' => 10, 'seats' => 8],
    'WR' => ['rows' => 6, 'seats' => 6],
    'BL' => ['rows' => 4, 'seats' => 12],
    'BC' => ['rows' => 4, 'seats' => 12],
    'BR' => ['rows' => 2, 'seats' => 10],
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
