<?php
// Checking tickets at the entrance (online scanner): one QR code = one seat.
declare(strict_types=1);

/**
 * Checks a scanned ticket for the run being checked in (organizer.php "verify").
 * One ticket = one seat. result:
 *         valid          – paid, the seat's first scan (check-in recorded now)
 *         used           – paid, this seat already scanned before
 *         seat_cancelled – the seat was cancelled from the reservation (the rest is valid)
 *         unpaid         – reservation not paid yet
 *         cancelled      – reservation cancelled or expired
 *         invalid        – not our ticket, forged or altered
 *         wrong_run      – valid reservation, but for another run (not checked in)
 *         outside_window – would be valid, but the run is outside its check-in window
 *                          and the organizer did not confirm (not checked in)
 */
function verify_ticket(string $code, array $run, array $access, bool $confirmOutside): array
{
    $ticket = parse_ticket_code($code);
    if ($ticket === null) {
        return ['result' => 'invalid'];
    }

    $pdo = db();
    $pdo->beginTransaction();
    // Current state is always loaded from the database by reservation id; the SS must match as well.
    $stmt = $pdo->prepare('SELECT * FROM reservations WHERE id = ? AND specific_symbol = ? FOR UPDATE');
    $stmt->execute([$ticket['id'], $ticket['specificSymbol']]);
    $r = $stmt->fetch();

    if (!$r) {
        $pdo->rollBack();
        return ['result' => 'invalid', 'ticket' => $ticket];
    }
    $seat = $ticket['seat'];
    $r['items'] = reservation_items($r);
    $item = $r['items'][$seat] ?? null;
    $active = $item !== null && !$item['cancelled'];
    $wrongRun = (int) $r['run_id'] !== $run['id'];
    $firstScan = $item === null || $item['checkedInAt'] === null;
    $outside = !run_in_scan_window($run) && !$confirmOutside;
    if ($r['status'] === 'paid' && $active && $firstScan && !$wrongRun && !$outside) {
        $now = db_time(now_utc());
        $pdo->prepare('UPDATE reservation_items SET checked_in_at = ?, checked_in_by = ? WHERE reservation_id = ? AND seat_id = ?')
            ->execute([$now, $access['name'], $r['id'], $seat]);
        // The reservation remembers its first check-in (it can no longer be cancelled by the customer).
        $pdo->prepare('UPDATE reservations SET checked_in_at = COALESCE(checked_in_at, ?), checked_in_by = COALESCE(checked_in_by, ?) WHERE id = ?')
            ->execute([$now, $access['name'], $r['id']]);
        $r['items'][$seat]['checkedInAt'] = $now;
        $r['items'][$seat]['checkedInBy'] = $access['name'];
    }
    $pdo->commit();
    $checkins = reservation_checkins($r);

    $result = match (true) {
        $wrongRun && in_array($r['status'], ['pending', 'paid'], true) => 'wrong_run',
        $r['status'] === 'paid' && !$active => 'seat_cancelled',
        $r['status'] === 'paid' && !$firstScan => 'used',
        $r['status'] === 'paid' && $outside => 'outside_window',
        $r['status'] === 'paid' => 'valid',
        $r['status'] === 'pending' => 'unpaid',
        default => 'cancelled',
    };
    $ticketRun = run_by_id((int) $r['run_id']);
    $seats = current_seat_ids($r);
    return [
        'result' => $result,
        'ticket' => [
            'id' => (int) $r['id'],
            'specificSymbol' => $r['specific_symbol'],
            'seat' => $seat,
            'kind' => $item !== null ? $item['kind'] : $ticket['kind'],
            'name' => ticket_holder($r, $seat) ?: $ticket['name'],
            'reservedBy' => trim($r['first_name'] . ' ' . $r['last_name']),
            'seats' => $seats,
            'count' => count($seats),
            'checkedIn' => count(array_intersect(array_keys($checkins), $seats)),
        ],
        'checkedInAt' => $checkins[$seat]['at'] ?? null,
        'checkedInBy' => $checkins[$seat]['by'] ?? null,
        'status' => $r['status'],
        'run' => $ticketRun ? ['id' => $ticketRun['id'], 'label' => run_label($ticketRun)] : null,
    ];
}
