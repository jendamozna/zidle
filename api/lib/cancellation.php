<?php
// Cancellation by the organizers (admin; customers cannot cancel themselves): paid seats are
// refunded in full, reminder / expiry e-mails and deletion of personal data after the event.
declare(strict_types=1);

/**
 * Refund in CZK for cancelling $seats. Paid: their price (a seat paid by a discount code is
 * worth 0 – nothing comes back for it). Unpaid: cancelling all seats returns any part of the
 * price that already arrived.
 */
function cancellation_refund(array $r, array $seats): int
{
    if ($r['status'] === 'paid') {
        return seats_value($r, $seats);
    }
    $kept = (int) $r['paid_amount'] - (int) $r['refund_amount'];
    return count($seats) >= (int) $r['seat_count'] ? max(0, $kept) : 0;
}

/**
 * Cancels the given seats (null = the whole reservation) of a pending or paid reservation
 * (admin, or an on-site sale cancelled by the organizer). Cancelling all remaining seats
 * cancels the reservation. Refunds go back to the account the payment came from. Sends the
 * e-mails. Returns the updated reservation row; throws InvalidArgumentException with a
 * message for the user.
 */
function cancel_seats(int $id, ?array $seatIds): array
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

        $current = explode(',', $r['seats']);
        $cancel = $seatIds === null ? $current : array_values(array_unique(array_map('strval', $seatIds)));
        if (!$cancel || array_diff($cancel, $current)) {
            throw new InvalidArgumentException('Vyberte místa z této rezervace.');
        }
        $remaining = array_values(array_diff($current, $cancel));
        $whole = $remaining === [];

        $refund = cancellation_refund($r, $cancel);
        $remainingAmount = seats_value($r, $remaining);
        $paid = $r['status'] === 'paid';
        $totalRefund = $paid || $refund > 0 ? (int) $r['refund_amount'] + $refund : $r['refund_amount'];

        if ($whole) {
            $pdo->prepare(
                "UPDATE reservations SET status = 'cancelled', cancelled_at = ?, refund_amount = ? WHERE id = ?"
            )->execute([db_time(now_utc()), $totalRefund, $id]);
            $pdo->prepare('DELETE FROM reservation_seats WHERE reservation_id = ?')->execute([$id]);
        } else {
            $cancelledSeats = implode(',', array_filter([$r['cancelled_seats'], implode(',', $cancel)]));
            $pdo->prepare(
                'UPDATE reservations SET seats = ?, seat_count = ?, amount = ?, cancelled_seats = ?, refund_amount = ? WHERE id = ?'
            )->execute([implode(',', $remaining), count($remaining), $remainingAmount, $cancelledSeats, $totalRefund, $id]);
            $placeholders = implode(',', array_fill(0, count($cancel), '?'));
            $pdo->prepare("DELETE FROM reservation_seats WHERE reservation_id = ? AND seat_id IN ($placeholders)")
                ->execute([$id, ...$cancel]);
            if (!$paid) {
                // What already arrived may now cover the lower price.
                complete_if_covered($pdo, array_merge($r, ['amount' => $remainingAmount]));
            }
        }
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }

    $stmt = db()->prepare('SELECT * FROM reservations WHERE id = ?');
    $stmt->execute([$id]);
    $updated = $stmt->fetch();
    send_cancellation_notice($updated, $cancel, $whole, $refund, $paid);
    return $updated;
}

/**
 * E-mails after a cancellation:
 *  - paid, part of the seats: new ticket (the old one lists cancelled seats) + refund
 *  - paid, everything:        cancellation with the refund and its deadline
 *  - unpaid, part of the seats: new amount to pay, or the ticket when what already
 *                             arrived covers the remaining seats (a surplus is refunded)
 *  - unpaid, everything:      confirmation to the customer; money that already arrived and
 *                             a payment still on its way will be returned
 */
function send_cancellation_notice(array $r, array $cancelled, bool $whole, int $refund, bool $paid): void
{
    if (!mail_enabled() || $r['email'] === '') {
        return;
    }
    $refundLines = refund_lines($refund);
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
    if (!$paid && !$whole && $r['status'] === 'paid') {
        $surplus = (int) $r['paid_amount'] - (int) $r['amount'];
        try {
            send_ticket_email($r, array_merge(
                ['Zrušená místa: ' . implode('; ', seat_labels(implode(',', $cancelled))) . '.',
                 'Platba za zbývající místa je kompletní, rezervace je potvrzena.'],
                $surplus > 0 ? refund_lines($surplus) : []
            ), 'Platba přijata – Vaše vstupenky');
            db()->prepare('UPDATE reservations SET ticket_sent_at = ? WHERE id = ?')->execute([db_time(now_utc()), $r['id']]);
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
        ['Vaše rezervace (SS ' . $r['specific_symbol'] . ') byla zrušena a místa uvolněna:',
         implode('; ', seat_labels($r['seats'])) . '.',
         run_line($r)],
        $refundLines,
        !$paid
            ? ['', 'Pokud jste platbu už odeslali, pošleme Vám ji po připsání zpět na účet, ze kterého přišla.']
            : []
    ));
}

function refund_lines(int $refund): array
{
    $lines = [];
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
 * (payment records – SS, amount, seats – stay for accounting), delete the
 * offline scan conflicts and empty the e-mails of discount codes. Reservations with money still to return keep their
 * contact until the refund is marked as done (the next run then removes it).
 * Returns the number of anonymized reservations.
 */
function purge_personal_data(): int
{
    $deleteAt = data_deletion_at();
    if ($deleteAt === null || now_utc() < $deleteAt) {
        return 0;
    }
    $stmt = db()->prepare(
        "UPDATE reservations SET first_name = '', last_name = '', email = ''
         WHERE (email <> '' OR first_name <> '') AND COALESCE(refund_amount, 0) <= refunded_amount"
    );
    $stmt->execute();
    db()->exec('DELETE FROM scan_conflicts');
    db()->exec("UPDATE discount_codes SET email = '' WHERE email <> ''");
    return $stmt->rowCount();
}
