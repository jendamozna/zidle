<?php
// Customer cancellation with storno fees, reminder / expiry e-mails and
// deletion of personal data after the event.
declare(strict_types=1);

/** Price of one seat in this reservation (prices are stored per reservation). */
function seat_price(array $r): int
{
    return (int) $r['seat_count'] > 0 ? intdiv((int) $r['amount'], (int) $r['seat_count']) : 0;
}

/**
 * What cancelling by the customer would mean right now.
 * allowed: false with a reason when the reservation can no longer be cancelled.
 * fee/refund are for the whole reservation; for single seats the frontend
 * uses seatPrice and percent (the server recalculates on cancellation).
 */
function cancellation_terms(array $r): array
{
    $run = run_by_id((int) $r['run_id']);

    if (!in_array($r['status'], ['pending', 'paid'], true)) {
        return ['allowed' => false, 'reason' => 'inactive'];
    }
    if ($r['checked_in_at'] !== null) {
        return ['allowed' => false, 'reason' => 'checked_in'];
    }
    if ($run === null || run_started($run)) {
        return ['allowed' => false, 'reason' => 'event_started'];
    }
    $percent = $r['status'] === 'paid' ? storno_percent($run, now_utc()) : 0;
    [$fee, $refund] = cancellation_money($r, (int) $r['seat_count'], 'customer');
    return [
        'allowed' => true,
        'percent' => $percent,
        'seatPrice' => seat_price($r),
        'fee' => $fee,
        'refund' => $refund,
    ];
}

/**
 * [fee, refund] in CZK for cancelling $count seats. Unpaid: nothing is paid
 * or returned. Paid by customer: storno fee in effect. Paid by admin: full refund.
 */
function cancellation_money(array $r, int $count, string $by): array
{
    if ($r['status'] !== 'paid') {
        return [0, 0];
    }
    $value = seat_price($r) * $count;
    $run = run_by_id((int) $r['run_id']);
    $percent = $by === 'customer' && $run !== null ? storno_percent($run, now_utc()) : 0;
    $fee = (int) round($value * $percent / 100);
    return [$fee, $value - $fee];
}

/**
 * Cancels the given seats (null = the whole reservation).
 *   $by = 'customer': only while cancellation_terms() allows it, storno fee applies.
 *   $by = 'admin':    any pending/paid reservation, paid seats are refunded in full.
 * Cancelling all remaining seats cancels the reservation. Refunds go back to the
 * account the payment came from. Sends the e-mails.
 * Returns the updated reservation row; throws InvalidArgumentException with a
 * message for the user.
 */
function cancel_seats(int $id, ?array $seatIds, string $by): array
{
    $pdo = db();
    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare('SELECT * FROM reservations WHERE id = ? FOR UPDATE');
        $stmt->execute([$id]);
        $r = $stmt->fetch();
        if (!$r || !in_array($r['status'], ['pending', 'paid'], true)) {
            throw new InvalidArgumentException('Rezervaci už nelze zrušit.');
        }
        if ($by === 'customer' && !cancellation_terms($r)['allowed']) {
            throw new InvalidArgumentException('Rezervaci už nelze zrušit.');
        }

        $current = explode(',', $r['seats']);
        $cancel = $seatIds === null ? $current : array_values(array_unique(array_map('strval', $seatIds)));
        if (!$cancel || array_diff($cancel, $current)) {
            throw new InvalidArgumentException('Vyberte místa z této rezervace.');
        }
        $remaining = array_values(array_diff($current, $cancel));
        $whole = $remaining === [];

        [$fee, $refund] = cancellation_money($r, count($cancel), $by);
        $paid = $r['status'] === 'paid';
        $totalFee = $paid ? (int) $r['cancel_fee'] + $fee : null;
        $totalRefund = $paid ? (int) $r['refund_amount'] + $refund : null;

        if ($whole) {
            $pdo->prepare(
                "UPDATE reservations SET status = 'cancelled', cancelled_at = ?, cancelled_by = ?,
                   cancel_fee = ?, refund_amount = ? WHERE id = ?"
            )->execute([db_time(now_utc()), $by, $totalFee, $totalRefund, $id]);
            $pdo->prepare('DELETE FROM reservation_seats WHERE reservation_id = ?')->execute([$id]);
        } else {
            $cancelledSeats = implode(',', array_filter([$r['cancelled_seats'], implode(',', $cancel)]));
            $pdo->prepare(
                'UPDATE reservations SET seats = ?, seat_count = ?, amount = ?, cancelled_seats = ?,
                   cancel_fee = ?, refund_amount = ? WHERE id = ?'
            )->execute([
                implode(',', $remaining), count($remaining), seat_price($r) * count($remaining),
                $cancelledSeats, $totalFee, $totalRefund, $id,
            ]);
            $placeholders = implode(',', array_fill(0, count($cancel), '?'));
            $pdo->prepare("DELETE FROM reservation_seats WHERE reservation_id = ? AND seat_id IN ($placeholders)")
                ->execute([$id, ...$cancel]);
        }
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }

    $stmt = db()->prepare('SELECT * FROM reservations WHERE id = ?');
    $stmt->execute([$id]);
    $updated = $stmt->fetch();
    send_cancellation_notice($updated, $cancel, $whole, $fee, $refund, $by, $paid);
    return $updated;
}

/**
 * E-mails after a cancellation:
 *  - paid, part of the seats: new ticket (the old one lists cancelled seats) + fee/refund
 *  - paid, everything:        cancellation with fee/refund and refund deadline
 *  - unpaid, part of the seats: new amount to pay
 *  - unpaid, everything:      confirmation to the customer (by admin: also that a payment
 *                             already sent will be returned)
 */
function send_cancellation_notice(array $r, array $cancelled, bool $whole, int $fee, int $refund, string $by, bool $paid): void
{
    if (!mail_enabled() || $r['email'] === '') {
        return;
    }
    $refundLines = refund_lines($r, $fee, $refund, $by);
    if ($paid && !$whole) {
        try {
            send_ticket_email($r, array_merge(
                ['Zrušená místa: ' . implode('; ', seat_labels(implode(',', $cancelled))) . '.'],
                $refundLines,
                ['Posíláme novou vstupenku na zbývající místa. Původní vstupenka už neplatí.']
            ));
        } catch (Throwable $e) {
            error_log('[zidle] ' . $e);
        }
        return;
    }
    if (!$paid && !$whole) {
        send_customer_email($r, 'Změna rezervace', array_merge(
            ['zrušili jsme místa: ' . implode('; ', seat_labels(implode(',', $cancelled))) . '.', run_line($r),
             'Zbývající místa: ' . implode('; ', seat_labels($r['seats'])) . '.', '', 'Nové platební údaje:'],
            payment_lines($r)
        ));
        return;
    }
    send_customer_email($r, 'Rezervace zrušena', array_merge(
        ['Vaše rezervace (VS ' . $r['variable_symbol'] . ') byla zrušena a místa uvolněna:',
         implode('; ', seat_labels($r['seats'])) . '.',
         run_line($r)],
        $refundLines,
        !$paid && $by === 'admin'
            ? ['', 'Pokud jste platbu už odeslali, pošleme Vám ji zpět na účet, ze kterého přišla.']
            : []
    ));
}

function refund_lines(array $r, int $fee, int $refund, string $by): array
{
    $lines = [];
    if ($fee > 0) {
        $lines[] = 'Storno poplatek: ' . format_czk($fee) . '.';
    }
    if ($refund > 0) {
        $days = (int) config('REFUND_DAYS');
        $lines[] = 'Částku ' . format_czk($refund) . " Vám do {$days} dnů pošleme zpět na účet, ze kterého platba přišla.";
    }
    return $lines;
}

/** Reminder 24 h before the due date and notice after expiry. Returns counts. */
function send_due_notifications(): array
{
    $pdo = db();
    $now = now_utc();
    $sent = ['reminders' => 0, 'expiry' => 0];
    if (!mail_enabled()) {
        return $sent;
    }

    // Only reservations whose due date is more than 24 h after creation get a reminder.
    $stmt = $pdo->prepare(
        "SELECT * FROM reservations
         WHERE status = 'pending' AND reminder_sent_at IS NULL
           AND expires_at > ? AND expires_at <= ?
           AND created_at <= expires_at - INTERVAL 24 HOUR"
    );
    $stmt->execute([db_time($now), db_time($now->modify('+24 hours'))]);
    $mark = $pdo->prepare('UPDATE reservations SET reminder_sent_at = ? WHERE id = ?');
    foreach ($stmt->fetchAll() as $r) {
        if (send_reminder_email($r)) {
            $mark->execute([db_time($now), $r['id']]);
            $sent['reminders']++;
        }
    }

    // Recently expired only, so enabling e-mails later doesn't mail old reservations.
    $stmt = $pdo->prepare(
        "SELECT * FROM reservations
         WHERE status = 'expired' AND expiry_notice_sent_at IS NULL AND cancelled_at >= ?"
    );
    $stmt->execute([db_time($now->modify('-3 days'))]);
    $mark = $pdo->prepare('UPDATE reservations SET expiry_notice_sent_at = ? WHERE id = ?');
    foreach ($stmt->fetchAll() as $r) {
        if (send_expiry_email($r)) {
            $mark->execute([db_time($now), $r['id']]);
            $sent['expiry']++;
        }
    }
    return $sent;
}

/** Personal data is deleted DATA_RETENTION_DAYS after the start of the last run (null without runs). */
function data_deletion_at(): ?DateTimeImmutable
{
    return last_run_start()?->modify('+' . (int) config('DATA_RETENTION_DAYS') . ' days');
}

/**
 * GDPR: DATA_RETENTION_DAYS after the last run remove names and e-mails
 * (payment records – VS, amount, seats – stay for accounting)
 * and delete the VIP list. Returns the number of anonymized reservations.
 */
function purge_personal_data(): int
{
    $deleteAt = data_deletion_at();
    if ($deleteAt === null || now_utc() < $deleteAt) {
        return 0;
    }
    $stmt = db()->prepare(
        "UPDATE reservations SET first_name = '', last_name = '', email = ''
         WHERE email <> '' OR first_name <> ''"
    );
    $stmt->execute();
    db()->exec('DELETE FROM vip_guests');
    return $stmt->rowCount();
}
