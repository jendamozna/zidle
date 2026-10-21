<?php
// Seat prices: adult (from 18 years) and child (4–17 years) seats; children up to
// 3 years may sit on an adult's lap for free (not recorded). A discount code makes one seat
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
    $names = ticket_names($r);
    return array_map(static fn ($seat) => [
        'seat' => $seat,
        'kind' => seat_kind($r, $seat),
        'price' => seat_value($r, $seat),
        'free' => seat_is_free($r, $seat),
        'holder' => $names[$seat] ?? null,
    ], $seats);
}

/**
 * A person's name as typed: line breaks, control and invisible format characters (bidi, zero-width)
 * and runs of spaces become one space, "|" (separator of the ticket code) too; non-strings give ''.
 */
function clean_name(mixed $name): string
{
    return is_string($name) ? trim(preg_replace('/[|\p{Cc}\p{Cf}\s]+/u', ' ', $name) ?? '') : '';
}

/** Names the customer put on the seat tickets: [seat => name]. */
function ticket_names(array $r): array
{
    $names = json_decode((string) ($r['ticket_names'] ?? '{}'), true);
    return is_array($names) ? array_filter($names, static fn ($n, $s) => is_string($n) && $n !== '' && is_string($s), ARRAY_FILTER_USE_BOTH) : [];
}

/** Name shown on the ticket of the seat: the customer's name for it, else the reservation name. */
function ticket_holder(array $r, string $seat): string
{
    return ticket_names($r)[$seat] ?? trim($r['first_name'] . ' ' . $r['last_name']);
}

/**
 * Customer sets names on the seat tickets ([seat => name], '' = back to the reservation name).
 * Only current seats, while the reservation is pending or paid and the run has not started.
 * Returns an error message or null.
 */
function save_ticket_names(array $r, array $names): ?string
{
    $run = run_by_id((int) $r['run_id']);
    if (!in_array($r['status'], ['pending', 'paid'], true) || $run === null || run_started($run)) {
        return 'Jména na vstupenkách už nelze měnit.';
    }
    $seats = seat_list((string) $r['seats']);
    $current = ticket_names($r);
    foreach ($names as $seat => $name) {
        $name = clean_name($name);
        if (!in_array((string) $seat, $seats, true)) {
            return 'Vyberte místa z této rezervace.';
        }
        if (mb_strlen($name) > 100) {
            return 'Jméno na vstupence může mít nejvýše 100 znaků.';
        }
        if ($name === '') {
            unset($current[(string) $seat]);
        } else {
            $current[(string) $seat] = $name;
        }
    }
    db()->prepare('UPDATE reservations SET ticket_names = ? WHERE id = ?')
        ->execute([json_encode((object) $current, JSON_UNESCAPED_UNICODE), $r['id']]);
    return null;
}

/** "2 dospělí, 1 dítě 4–17 let" for e-mails. */
function seat_kinds_summary(array $r): string
{
    $seats = seat_list((string) $r['seats']);
    $children = count(array_filter($seats, static fn ($s) => seat_kind($r, $s) === 'child'));
    $adults = count($seats) - $children;
    $parts = [];
    if ($adults > 0) {
        $parts[] = $adults . ' ' . ($adults === 1 ? 'dospělý' : 'dospělí');
    }
    if ($children > 0) {
        $parts[] = $children . ' ' . ($children === 1 ? 'dítě' : ($children < 5 ? 'děti' : 'dětí')) . ' 4–17 let';
    }
    return implode(', ', $parts);
}
