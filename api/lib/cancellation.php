<?php
// Customer cancellation with storno fees, reminder / expiry e-mails and
// deletion of personal data after the event.
declare(strict_types=1);

/**
 * What cancelling would mean right now.
 * allowed: false with a reason when the reservation can no longer be cancelled.
 */
function cancellation_terms(array $r): array
{
    $now = now_utc();
    $event = event_at();
    $amount = (int) $r['amount'];

    if (!in_array($r['status'], ['pending', 'paid'], true)) {
        return ['allowed' => false, 'reason' => 'inactive'];
    }
    if ($r['checked_in_at'] !== null) {
        return ['allowed' => false, 'reason' => 'checked_in'];
    }
    if ($event !== null && $now >= $event) {
        return ['allowed' => false, 'reason' => 'event_started'];
    }
    if ($r['status'] === 'pending') {
        return ['allowed' => true, 'percent' => 0, 'fee' => 0, 'refund' => 0];
    }
    $percent = storno_percent($now);
    $fee = (int) round($amount * $percent / 100);
    return ['allowed' => true, 'percent' => $percent, 'fee' => $fee, 'refund' => $amount - $fee];
}

/** Czech account "(prefix-)number/bank" or an IBAN; returns the normalized value or null. */
function normalize_refund_account(string $account): ?string
{
    $account = strtoupper(preg_replace('/\s+/', '', $account));
    if (preg_match('/^(\d{1,6}-)?\d{2,10}\/\d{4}$/', $account) || preg_match('/^[A-Z]{2}\d{2}[A-Z0-9]{10,30}$/', $account)) {
        return $account;
    }
    return null;
}

/**
 * Cancels the reservation for the customer. Returns the updated row or
 * throws InvalidArgumentException with a message for the customer.
 */
function cancel_by_customer(string $token, string $refundAccount): array
{
    $pdo = db();
    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare('SELECT * FROM reservations WHERE token = ? FOR UPDATE');
        $stmt->execute([$token]);
        $r = $stmt->fetch();
        if (!$r) {
            throw new InvalidArgumentException('Rezervace nenalezena.');
        }
        $terms = cancellation_terms($r);
        if (!$terms['allowed']) {
            throw new InvalidArgumentException('Rezervaci už nelze zrušit.');
        }
        $account = null;
        if ($terms['refund'] > 0) {
            $account = normalize_refund_account($refundAccount);
            if ($account === null) {
                throw new InvalidArgumentException('Zadejte platné číslo účtu pro vrácení peněz.');
            }
        }
        $pdo->prepare(
            "UPDATE reservations SET status = 'cancelled', cancelled_at = ?, cancelled_by = 'customer',
               cancel_fee = ?, refund_amount = ?, refund_account = ? WHERE id = ?"
        )->execute([
            db_time(now_utc()),
            $r['status'] === 'paid' ? $terms['fee'] : null,
            $terms['refund'] ?: null,
            $account,
            $r['id'],
        ]);
        $pdo->prepare('DELETE FROM reservation_seats WHERE reservation_id = ?')->execute([$r['id']]);
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
    $r = find_reservation_by_token($token);
    send_cancellation_email($r);
    return $r;
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

/** Date after which personal data is deleted, or null when the event date is not set. */
function data_deletion_at(): ?DateTimeImmutable
{
    $event = event_at();
    return $event?->modify('+' . (int) config('DATA_RETENTION_DAYS') . ' days');
}

/**
 * GDPR: after the event + DATA_RETENTION_DAYS remove names, e-mails and
 * refund accounts (payment records – VS, amount, seats – stay for accounting)
 * and delete the VIP list. Returns the number of anonymized reservations.
 */
function purge_personal_data(): int
{
    $deleteAt = data_deletion_at();
    if ($deleteAt === null || now_utc() < $deleteAt) {
        return 0;
    }
    $stmt = db()->prepare(
        "UPDATE reservations SET first_name = '', last_name = '', email = '', refund_account = NULL
         WHERE email <> '' OR first_name <> '' OR refund_account IS NOT NULL"
    );
    $stmt->execute();
    db()->exec('DELETE FROM vip_guests');
    return $stmt->rowCount();
}
