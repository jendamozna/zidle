<?php
// Seat prices: adult (from 18 years) and child (4–17 years) seats, children up to
// 3 years sit on a lap for free (no seat, no ticket). A discount code makes one seat
// free – the most expensive one (adult seats first, then child seats).
//
// A reservation keeps the prices it was made with (adult_price, child_price) and the
// seat lists child_seats / free_seats; every seat's value follows from them.
declare(strict_types=1);

const SEAT_KIND_LABELS = ['adult' => 'Dospělý', 'child' => 'Dítě 4–17 let'];

/** "A,B" → ["A", "B"], '' → []. */
function seat_list(string $csv): array
{
    return $csv === '' ? [] : explode(',', $csv);
}

function adult_price(): int
{
    return (int) config('SEAT_PRICE');
}

function child_price(): int
{
    return (int) config('CHILD_SEAT_PRICE');
}

/** 'adult' or 'child'. */
function seat_kind(array $r, string $seat): string
{
    return in_array($seat, seat_list((string) $r['child_seats']), true) ? 'child' : 'adult';
}

function seat_is_free(array $r, string $seat): bool
{
    return in_array($seat, seat_list((string) $r['free_seats']), true);
}

/** Price of one seat of the reservation in CZK (0 for a seat paid by a discount code). */
function seat_value(array $r, string $seat): int
{
    if (seat_is_free($r, $seat)) {
        return 0;
    }
    return (int) (seat_kind($r, $seat) === 'child' ? $r['child_price'] : $r['adult_price']);
}

function seats_value(array $r, array $seats): int
{
    return array_sum(array_map(static fn ($seat) => seat_value($r, (string) $seat), $seats));
}

/** The last $children seats (in seat order) get child tickets. */
function assign_child_seats(array $seats, int $children): array
{
    usort($seats, 'compare_seat_ids');
    return $children > 0 ? array_slice($seats, -$children) : [];
}

/**
 * Seats made free by $codes discount codes: the most expensive first
 * (adult seats, then child seats), in seat order within the same price.
 */
function assign_free_seats(array $seats, array $childSeats, int $codes, int $adultPrice, int $childPrice): array
{
    usort($seats, 'compare_seat_ids');
    $price = static fn ($seat) => in_array($seat, $childSeats, true) ? $childPrice : $adultPrice;
    $order = array_keys($seats);
    usort($order, static fn ($a, $b) => [$price($seats[$b]), $a] <=> [$price($seats[$a]), $b]);
    return array_map(static fn ($i) => $seats[$i], array_slice($order, 0, max(0, $codes)));
}

/** Per-seat tickets of the reservation's current seats: [{seat, kind, price, free}] in seat order. */
function reservation_seat_items(array $r): array
{
    $seats = seat_list((string) $r['seats']);
    usort($seats, 'compare_seat_ids');
    return array_map(static fn ($seat) => [
        'seat' => $seat,
        'kind' => seat_kind($r, $seat),
        'price' => seat_value($r, $seat),
        'free' => seat_is_free($r, $seat),
    ], $seats);
}

/** "2 dospělí, 1 dítě 4–17 let, 1 dítě do 3 let na klíně" for e-mails and admin. */
function seat_kinds_summary(array $r): string
{
    $seats = seat_list((string) $r['seats']);
    $children = count(array_filter($seats, static fn ($s) => seat_kind($r, $s) === 'child'));
    $adults = count($seats) - $children;
    $infants = (int) $r['infants'];
    $parts = [];
    if ($adults > 0) {
        $parts[] = $adults . ' ' . ($adults === 1 ? 'dospělý' : 'dospělí');
    }
    if ($children > 0) {
        $parts[] = $children . ' ' . ($children === 1 ? 'dítě' : ($children < 5 ? 'děti' : 'dětí')) . ' 4–17 let';
    }
    if ($infants > 0) {
        $parts[] = infants_label($infants);
    }
    return implode(', ', $parts);
}

/** "1 dítě do 3 let na klíně" */
function infants_label(int $infants): string
{
    return $infants . ' ' . ($infants === 1 ? 'dítě' : ($infants < 5 ? 'děti' : 'dětí')) . ' do 3 let na klíně';
}
