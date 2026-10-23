<?php
// Seat prices: adult (from 18 years) and child (4–17 years) seats; children up to
// 3 years may sit on an adult's lap for free (not recorded). A discount code makes one seat
// free – the most expensive one (adult seats first, then child seats).
//
// A reservation's seats are rows of reservation_items: kind, the price the seat was booked
// for (0 = paid by a discount code), the name on the ticket, cancellation and check-in.
declare(strict_types=1);

const SEAT_KIND_LABELS = ['adult' => 'Dospělý', 'child' => 'Dítě 4–17 let'];

function adult_price(): int
{
    return (int) config('SEAT_PRICE');
}

function child_price(): int
{
    return (int) config('CHILD_SEAT_PRICE');
}

/**
 * Every seat ever in the reservation, in seat order: [seat => [seat, kind (adult/child), price, free,
 * holder (name on the ticket or null), cancelled, checkedInAt (DB time or null), checkedInBy]].
 * Uses $r['items'] when the caller loaded them (with_items()).
 */
function reservation_items(array $r): array
{
    return $r['items'] ?? load_reservation_items([(int) $r['id']])[(int) $r['id']] ?? [];
}

/** reservation_items() of several reservations in one query: [reservation id => items]. */
function load_reservation_items(array $ids): array
{
    if (!$ids) {
        return [];
    }
    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $stmt = db()->prepare("SELECT * FROM reservation_items WHERE reservation_id IN ($placeholders)");
    $stmt->execute(array_values($ids));
    $out = [];
    foreach ($stmt->fetchAll() as $i) {
        $out[(int) $i['reservation_id']][(string) $i['seat_id']] = [
            'seat' => (string) $i['seat_id'],
            'kind' => (string) $i['kind'],
            'price' => (int) $i['price'],
            'free' => (bool) $i['free'],
            'holder' => $i['holder_name'],
            'cancelled' => $i['cancelled_at'] !== null,
            'checkedInAt' => $i['checked_in_at'],
            'checkedInBy' => $i['checked_in_by'],
        ];
    }
    return array_map(static function (array $items): array {
        uksort($items, static fn ($a, $b) => compare_seat_ids((string) $a, (string) $b));
        return $items;
    }, $out);
}

/** The row with its items loaded once (for code that reads them per seat). */
function with_items(array $r): array
{
    return $r + ['items' => reservation_items($r)];
}

/** Items of the seats currently in the reservation (not cancelled one by one). */
function current_items(array $r): array
{
    return array_filter(reservation_items($r), static fn ($i) => !$i['cancelled']);
}

/** Seat ids currently in the reservation, in seat order. */
function current_seat_ids(array $r): array
{
    return array_map('strval', array_keys(current_items($r)));
}

/** Seat ids cancelled from the reservation one by one, in seat order. */
function cancelled_seat_ids(array $r): array
{
    return array_map('strval', array_keys(array_filter(reservation_items($r), static fn ($i) => $i['cancelled'])));
}

/** 'adult' or 'child'. */
function seat_kind(array $r, string $seat): string
{
    return reservation_items($r)[$seat]['kind'] ?? 'adult';
}

/** Price of the given seats of the reservation in CZK (a seat paid by a discount code is 0). */
function seats_value(array $r, array $seats): int
{
    $items = reservation_items($r);
    return array_sum(array_map(static fn ($seat) => $items[(string) $seat]['price'] ?? 0, $seats));
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

/** Items of a new reservation: [seat => [kind, price, free]]; a seat freed by a discount code costs 0. */
function new_reservation_items(array $seats, array $childSeats, array $freeSeats, int $adultPrice, int $childPrice): array
{
    $items = [];
    foreach ($seats as $seat) {
        $kind = in_array($seat, $childSeats, true) ? 'child' : 'adult';
        $free = in_array($seat, $freeSeats, true);
        $items[(string) $seat] = ['kind' => $kind, 'price' => $free ? 0 : ($kind === 'child' ? $childPrice : $adultPrice), 'free' => $free];
    }
    return $items;
}

/** Inserts new_reservation_items() of the reservation. */
function insert_reservation_items(PDO $pdo, int $id, array $items): void
{
    $insert = $pdo->prepare('INSERT INTO reservation_items (reservation_id, seat_id, kind, price, free) VALUES (?, ?, ?, ?, ?)');
    foreach ($items as $seat => $item) {
        $insert->execute([$id, $seat, $item['kind'], $item['price'], (int) $item['free']]);
    }
}

/** Per-seat tickets of the reservation's current seats: [{seat, kind, price, free, holder}] in seat order. */
function reservation_seat_items(array $r): array
{
    return array_values(array_map(static fn ($i) => [
        'seat' => $i['seat'],
        'kind' => $i['kind'],
        'price' => $i['price'],
        'free' => $i['free'],
        'holder' => $i['holder'],
    ], current_items($r)));
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
    return array_filter(array_map(static fn ($i) => $i['holder'], reservation_items($r)), static fn ($n) => $n !== null && $n !== '');
}

/** Name shown on the ticket of the seat: the customer's name for it, else the reservation name. */
function ticket_holder(array $r, string $seat): string
{
    return (string) (reservation_items($r)[$seat]['holder'] ?? '') ?: trim($r['first_name'] . ' ' . $r['last_name']);
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
    $seats = current_seat_ids($r);
    $updates = [];
    foreach ($names as $seat => $name) {
        $name = clean_name($name);
        if (!in_array((string) $seat, $seats, true)) {
            return 'Vyberte místa z této rezervace.';
        }
        if (mb_strlen($name) > 100) {
            return 'Jméno na vstupence může mít nejvýše 100 znaků.';
        }
        $updates[(string) $seat] = $name === '' ? null : $name;
    }
    $update = db()->prepare('UPDATE reservation_items SET holder_name = ? WHERE reservation_id = ? AND seat_id = ?');
    foreach ($updates as $seat => $name) {
        $update->execute([$name, $r['id'], $seat]);
    }
    return null;
}

/** "2 dospělí, 1 dítě 4–17 let" for e-mails. */
function seat_kinds_summary(array $r): string
{
    $items = current_items($r);
    $children = count(array_filter($items, static fn ($i) => $i['kind'] === 'child'));
    $adults = count($items) - $children;
    $parts = [];
    if ($adults > 0) {
        $parts[] = $adults . ' ' . ($adults === 1 ? 'dospělý' : 'dospělí');
    }
    if ($children > 0) {
        $parts[] = $children . ' ' . ($children === 1 ? 'dítě' : ($children < 5 ? 'děti' : 'dětí')) . ' 4–17 let';
    }
    return implode(', ', $parts);
}
