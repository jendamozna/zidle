<?php
// Selling tickets at the entrance (scanner tab "Prodej"): while seats are free the organizer
// picks them, the customer pays by scanning the QR payment (SPD) shown on the organizer's
// phone in their banking app; after seeing the payment confirmed the organizer taps
// "Zaplaceno – vpustit": the reservation becomes paid and all its seats are checked in.
// The money is not verified at that moment: the accountant confirms in admin that it
// arrived (payment_checked_at). No name or e-mail is needed.
declare(strict_types=1);

const ONSITE_NAME = 'Prodej na místě';

/** Seats of the run that are held (reservations and VIP guests). */
function run_taken_seats(int $runId): array
{
    $stmt = db()->prepare('SELECT seat_id FROM reservation_seats WHERE run_id = ?');
    $stmt->execute([$runId]);
    return $stmt->fetchAll(PDO::FETCH_COLUMN);
}

/**
 * Creates the on-site sale (pending reservation sold by the organizer).
 * Returns ['sale' => onsite_sale_payload()] or ['error' => message, 'status' => HTTP status, 'extra' => array].
 */
function onsite_sale_create(array $access, array $run, mixed $seats, mixed $children, mixed $infants): array
{
    $max = (int) config('MAX_SEATS_PER_RESERVATION');
    $seats = is_array($seats) ? array_values(array_unique(array_map('strval', $seats))) : [];
    if (!$seats || count($seats) > $max || array_filter($seats, static fn ($id) => !is_valid_seat_id($id))) {
        return ['error' => "Vyberte 1 až {$max} volných míst.", 'status' => 422, 'extra' => []];
    }
    if (!is_int($children) || $children < 0 || $children > count($seats) || !is_int($infants) || $infants < 0 || $infants > count($seats)) {
        return ['error' => 'Dětí může být nejvýše tolik, kolik je míst.', 'status' => 422, 'extra' => []];
    }
    if (trim((string) config('BANK_IBAN')) === '') {
        return ['error' => 'Platby nejsou nastaveny (BANK_IBAN).', 'status' => 503, 'extra' => []];
    }
    usort($seats, 'compare_seat_ids');
    expire_reservations();
    $booked = book_reservation($run, '', ONSITE_NAME, '', $seats, $children, $infants, [], $access['name']);
    if (isset($booked['error'])) {
        return $booked;
    }
    return ['sale' => onsite_sale_payload($booked['reservation'])];
}

function onsite_sale_payload(array $r): array
{
    return [
        'id' => (int) $r['id'],
        'status' => $r['status'],
        'variableSymbol' => $r['variable_symbol'],
        'amount' => (int) $r['amount'],
        'seats' => seat_list((string) $r['seats']),
        'tickets' => reservation_seat_items($r),
        'infants' => (int) $r['infants'],
        'spd' => spd_string($r),
        'account' => (string) config('BANK_ACCOUNT_DISPLAY'),
        'iban' => (string) config('BANK_IBAN'),
    ];
}

/** The on-site sale of this run (pending), or null. */
function onsite_sale(array $run, int $id): ?array
{
    $stmt = db()->prepare("SELECT * FROM reservations WHERE id = ? AND run_id = ? AND sold_by IS NOT NULL AND status = 'pending'");
    $stmt->execute([$id, $run['id']]);
    return $stmt->fetch() ?: null;
}

/**
 * The customer showed the payment as sent: paid (the accountant verifies the transfer later),
 * all seats checked in by the organizer. Returns a message or null when the sale is not open.
 */
function onsite_sale_paid(array $access, array $run, int $id): ?string
{
    $pdo = db();
    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare("SELECT * FROM reservations WHERE id = ? AND run_id = ? AND sold_by IS NOT NULL AND status = 'pending' FOR UPDATE");
        $stmt->execute([$id, $run['id']]);
        $r = $stmt->fetch();
        if (!$r) {
            $pdo->rollBack();
            return null;
        }
        $now = db_time(now_utc());
        $pdo->prepare("UPDATE reservations SET status = 'paid', paid_at = ?, paid_amount = amount, checked_in_at = ?, checked_in_by = ? WHERE id = ?")
            ->execute([$now, $now, $access['name'], $id]);
        $checkin = $pdo->prepare('INSERT IGNORE INTO ticket_checkins (reservation_id, seat_id, checked_in_at, checked_in_by) VALUES (?, ?, ?, ?)');
        foreach (seat_list((string) $r['seats']) as $seat) {
            $checkin->execute([$id, $seat, $now, $access['name']]);
        }
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
    $n = (int) $r['seat_count'];
    return 'Zaplaceno ' . format_czk((int) $r['amount']) . ' – ' . $n . ' ' . ($n === 1 ? 'místo odbaveno' : ($n < 5 ? 'místa odbavena' : 'míst odbaveno')) . '.';
}

/** The customer did not pay: the sale is cancelled, the seats are free again. */
function onsite_sale_cancel(array $run, int $id): bool
{
    if (onsite_sale($run, $id) === null) {
        return false;
    }
    cancel_seats($id, null, 'admin');
    return true;
}
