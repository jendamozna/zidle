<?php
// Received bank transfers recorded by the accountant, amounts still due,
// overpayments and refunds of money that arrived when it could no longer be used.
//
// Money columns on a reservation: paid_amount = everything received so far,
// refund_amount = everything that has to go back, refunded_amount = already returned.
declare(strict_types=1);

/** CZK still to pay for the reservation (its price minus what already arrived and is kept). */
function amount_due(array $r): int
{
    $kept = (int) $r['paid_amount'] - (int) $r['refund_amount'];
    return max(0, (int) $r['amount'] - $kept);
}

/**
 * Inside an open transaction: a pending reservation whose received money covers
 * its price becomes paid; a surplus is due for refund. Returns true when it did.
 */
function complete_if_covered(PDO $pdo, array $r): bool
{
    $received = (int) $r['paid_amount'];
    if ($r['status'] !== 'pending' || $received <= 0 || $received - (int) $r['refunded_amount'] < (int) $r['amount']) {
        return false;
    }
    $surplus = $received - (int) $r['amount'];
    $pdo->prepare(
        "UPDATE reservations SET status = 'paid', paid_at = ?, refund_amount = ? WHERE id = ?"
    )->execute([db_time(now_utc()), $surplus > 0 ? $surplus : null, $r['id']]);
    return true;
}

/**
 * Records a received bank transfer of $received CZK (whatever the reservation's status):
 *  - pending:   added to what arrived; once the whole price is there the reservation is paid
 *               (ticket e-mail, a surplus is due for refund); otherwise it stays pending and the
 *               customer is e-mailed how much is still missing.
 *  - expired:   restored as paid when the run has not started, the money received (minus anything
 *               already returned) covers the price and all its seats are still free; otherwise
 *               this payment is due for refund (earlier partial payments already are, see expiry).
 *  - cancelled: due for refund (the reservation was cancelled before the money arrived).
 *  - paid:      an extra payment (e.g. sent twice), due for refund.
 * Refunds go back to the paying account; the customer is e-mailed. Returns a message for the admin.
 */
function record_payment(int $id, int $received): string
{
    if ($received < 1 || $received > 1000000) {
        return 'Zadejte přijatou částku v Kč.';
    }
    $pdo = db();
    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare('SELECT * FROM reservations WHERE id = ? FOR UPDATE');
        $stmt->execute([$id]);
        $r = $stmt->fetch();
        if (!$r) {
            $pdo->rollBack();
            return 'Rezervace nenalezena.';
        }
        $status = $r['status'];
        $pdo->prepare('UPDATE reservations SET paid_amount = COALESCE(paid_amount, 0) + ? WHERE id = ?')
            ->execute([$received, $id]);
        $r['paid_amount'] = (int) $r['paid_amount'] + $received;

        $reason = null;
        if ($status === 'pending') {
            $outcome = complete_if_covered($pdo, $r) ? 'paid' : 'partial';
        } elseif ($status === 'expired') {
            $reason = late_payment_problem($pdo, $r);
            if ($reason === null) {
                $seats = explode(',', $r['seats']);
                $insert = $pdo->prepare('INSERT INTO reservation_seats (run_id, seat_id, reservation_id) VALUES (?, ?, ?)');
                foreach ($seats as $seat) {
                    $insert->execute([$r['run_id'], $seat, $id]);
                }
                // Money returned for an earlier partial payment stays returned.
                $pdo->prepare("UPDATE reservations SET status = 'pending', cancelled_at = NULL, refund_amount = NULLIF(refunded_amount, 0) WHERE id = ?")
                    ->execute([$id]);
                $r['status'] = 'pending';
                complete_if_covered($pdo, $r);
                $outcome = 'restored';
            } else {
                $outcome = 'refund';
            }
        } else {
            $reason = $status === 'paid' ? 'extra' : 'cancelled';
            $outcome = 'refund';
        }
        if ($outcome === 'refund') {
            $pdo->prepare('UPDATE reservations SET refund_amount = COALESCE(refund_amount, 0) + ? WHERE id = ?')
                ->execute([$received, $id]);
        }
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }

    $stmt = db()->prepare('SELECT * FROM reservations WHERE id = ?');
    $stmt->execute([$id]);
    $r = $stmt->fetch();
    $refundDue = (int) $r['refund_amount'] - (int) $r['refunded_amount'];
    $kc = format_czk($received);

    if ($r['status'] === 'paid' && $outcome !== 'refund') {
        $surplus = (int) $r['paid_amount'] - (int) $r['amount'];
        $intro = ['děkujeme, platba byla přijata. Vaše rezervace je potvrzena.'];
        if ($surplus > 0) {
            $intro[] = 'Přišlo o ' . format_czk($surplus) . ' více, než bylo potřeba. Přeplatek Vám do '
                . (int) config('REFUND_DAYS') . ' dnů pošleme zpět na účet, ze kterého platba přišla.';
        }
        return "Přijato {$kc}" . ($outcome === 'restored' ? ', rezervace obnovena' : '') . ' – zaplaceno.'
            . ($surplus > 0 ? ' Přeplatek ' . format_czk($surplus) . ' k vrácení.' : '')
            . send_ticket_for($id, $intro, 'Vstupenka');
    }
    if ($r['status'] === 'pending') {
        $mailed = send_partial_payment_email($r, $received);
        return "Přijato {$kc}, zbývá doplatit " . format_czk(amount_due($r)) . '.'
            . ($mailed ? ' Zákazník dostal e-mail.' : '');
    }
    $mailed = send_payment_refund_email($r, $received, $reason);
    return "Přijato {$kc} – " . REFUND_REASONS[$reason] . '. K vrácení na účet plátce: ' . format_czk($refundDue) . '.'
        . ($mailed ? ' Zákazník dostal e-mail.' : '');
}

/**
 * Why money for an expired reservation can't restore it as paid, or null when it can
 * ($r['paid_amount'] already includes the new payment).
 */
function late_payment_problem(PDO $pdo, array $r): ?string
{
    $run = run_by_id((int) $r['run_id']);
    if ($run === null || run_started($run)) {
        return 'after_run';
    }
    if ((int) $r['paid_amount'] - (int) $r['refunded_amount'] < (int) $r['amount']) {
        return 'short';
    }
    $seats = explode(',', $r['seats']);
    $placeholders = implode(',', array_fill(0, count($seats), '?'));
    $taken = $pdo->prepare("SELECT seat_id FROM reservation_seats WHERE run_id = ? AND seat_id IN ($placeholders) FOR UPDATE");
    $taken->execute([$r['run_id'], ...$seats]);
    return $taken->fetchColumn() === false ? null : 'taken';
}

/** Reason texts for money that is returned (admin message; the e-mail uses the same wording). */
const REFUND_REASONS = [
    'after_run' => 'platba dorazila až po představení',
    'taken' => 'místa mezitím obsadil někdo jiný',
    'short' => 'rezervace už propadla a platba nepokrývá celou částku',
    'cancelled' => 'rezervace už byla zrušená',
    'extra' => 'rezervace už byla zaplacená, jde o platbu navíc',
];

/** Sends the ticket e-mail for a paid reservation; returns a message for the admin. */
function send_ticket_for(int $id, array $intro = [], ?string $subject = null): string
{
    $stmt = db()->prepare("SELECT * FROM reservations WHERE id = ? AND status = 'paid'");
    $stmt->execute([$id]);
    $r = $stmt->fetch();
    if (!$r) {
        return '';
    }
    if (!mail_enabled()) {
        return ' E-maily jsou vypnuté (MAIL_ENABLED), vstupenka nebyla odeslána.';
    }
    try {
        $sent = send_ticket_email($r, $intro, $subject);
    } catch (Throwable $e) {
        error_log('[zidle] ' . $e);
        $sent = false;
    }
    if (!$sent) {
        return ' Vstupenku se nepodařilo odeslat.';
    }
    db()->prepare('UPDATE reservations SET ticket_sent_at = ? WHERE id = ?')->execute([db_time(now_utc()), $id]);
    return ' Vstupenka odeslána na ' . $r['email'] . '.';
}
