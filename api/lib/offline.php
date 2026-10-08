<?php
// Scanning without a connection: the scanner downloads a list of the run's
// tickets and VIP guests in advance (scanner_snapshot()), checks tickets against
// it while offline and sends the queued check-ins later (apply_offline_scans()).
// The first check-in wins; anything that can no longer be applied is stored in
// scan_conflicts for the admin.
declare(strict_types=1);

/** Suffix of checked_in_by for check-ins made without a connection. */
const OFFLINE_SUFFIX = ' (offline)';

/** Offline scans older than this are recorded at this age (device clock gone wrong). */
const OFFLINE_MAX_AGE_HOURS = 48;

/**
 * Everything the scanner needs to check the run's tickets offline. Only what the
 * entrance needs: no e-mails, no money. VIP guests as in vip_list().
 */
function scanner_snapshot(array $run): array
{
    $stmt = db()->prepare(
        'SELECT id, variable_symbol, first_name, last_name, seats, status, checked_in_at, checked_in_by
         FROM reservations WHERE run_id = ? ORDER BY id'
    );
    $stmt->execute([$run['id']]);
    $tickets = array_map(static fn ($r) => [
        'id' => (int) $r['id'],
        'variableSymbol' => (string) $r['variable_symbol'],
        'name' => trim($r['first_name'] . ' ' . $r['last_name']),
        'seats' => $r['seats'] === '' ? [] : explode(',', $r['seats']),
        'status' => $r['status'],
        'checkedInAt' => iso_time($r['checked_in_at']),
        'checkedInBy' => $r['checked_in_by'],
    ], $stmt->fetchAll());

    return [
        'runId' => $run['id'],
        'at' => iso_utc(now_utc()),
        'tickets' => $tickets,
        'vips' => vip_list($run['id']),
    ];
}

/** Device time of an offline scan, limited to the last OFFLINE_MAX_AGE_HOURS (never in the future). */
function offline_scan_time(mixed $at): DateTimeImmutable
{
    $now = now_utc();
    try {
        $time = is_string($at) ? new DateTimeImmutable($at) : $now;
    } catch (Exception) {
        $time = $now;
    }
    $time = $time->setTimezone(new DateTimeZone('UTC'));
    $oldest = $now->modify('-' . OFFLINE_MAX_AGE_HOURS . ' hours');
    return $time > $now ? $now : ($time < $oldest ? $oldest : $time);
}

/**
 * Applies check-ins queued by a scanner while it was offline, in order. Events:
 *   {type: "ticket", id, variableSymbol, at}   – ticket let in
 *   {type: "vip-checkin", id, at}              – VIP guest let in
 *   {type: "vip-undo", id}                     – VIP arrival taken back
 * A ticket/VIP already checked in by somebody else, an unpaid or unknown ticket
 * is recorded in scan_conflicts. Sending the same event again is harmless.
 * Returns ['results' => [['status' => 'ok'|'conflict', 'reason' => ?string]], 'conflicts' => int].
 */
function apply_offline_scans(array $access, array $run, array $events): array
{
    $by = mb_substr($access['name'], 0, 100 - strlen(OFFLINE_SUFFIX)) . OFFLINE_SUFFIX;
    $results = [];
    $conflicts = 0;
    foreach (array_slice($events, 0, 1000) as $event) {
        $event = is_array($event) ? $event : [];
        $result = match ($event['type'] ?? '') {
            'ticket' => offline_ticket($run, $event, $by),
            'vip-checkin' => offline_vip_checkin($run, $event, $by),
            'vip-undo' => offline_vip_undo($run, $event),
            default => ['status' => 'conflict', 'reason' => 'invalid'],
        };
        $conflicts += $result['status'] === 'conflict' && ($result['recorded'] ?? false) ? 1 : 0;
        unset($result['recorded']);
        $results[] = $result;
    }
    return ['results' => $results, 'conflicts' => $conflicts];
}

function offline_ticket(array $run, array $event, string $by): array
{
    $at = offline_scan_time($event['at'] ?? null);
    $vs = (string) ($event['variableSymbol'] ?? '');
    $pdo = db();
    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare('SELECT * FROM reservations WHERE id = ? AND variable_symbol = ? AND run_id = ? FOR UPDATE');
        $stmt->execute([(int) ($event['id'] ?? 0), $vs, $run['id']]);
        $r = $stmt->fetch();
        if (!$r) {
            $result = record_conflict($run, null, null, 'VS ' . $vs, 'unknown', $at, $by);
        } elseif ($r['status'] !== 'paid') {
            $result = record_conflict($run, (int) $r['id'], null, reservation_label($r), 'not_paid', $at, $by);
        } elseif ($r['checked_in_at'] === null) {
            $pdo->prepare('UPDATE reservations SET checked_in_at = ?, checked_in_by = ? WHERE id = ?')
                ->execute([db_time($at), $by, $r['id']]);
            $result = ['status' => 'ok'];
        } elseif ($r['checked_in_at'] === db_time($at) && $r['checked_in_by'] === $by) {
            $result = ['status' => 'ok']; // the same event sent again
        } else {
            $result = record_conflict($run, (int) $r['id'], null, reservation_label($r), 'already_checked_in', $at, $by,
                $r['checked_in_at'], $r['checked_in_by']);
        }
        $pdo->commit();
        return $result;
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
}

function offline_vip_checkin(array $run, array $event, string $by): array
{
    $at = offline_scan_time($event['at'] ?? null);
    $pdo = db();
    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare('SELECT * FROM vip_guests WHERE id = ? AND run_id = ? FOR UPDATE');
        $stmt->execute([(int) ($event['id'] ?? 0), $run['id']]);
        $v = $stmt->fetch();
        if (!$v) {
            $result = record_conflict($run, null, null, 'VIP č. ' . (int) ($event['id'] ?? 0), 'unknown', $at, $by);
        } elseif ($v['checked_in_at'] === null) {
            $pdo->prepare('UPDATE vip_guests SET checked_in_at = ?, checked_in_by = ? WHERE id = ?')
                ->execute([db_time($at), $by, $v['id']]);
            $result = ['status' => 'ok'];
        } elseif ($v['checked_in_at'] === db_time($at) && $v['checked_in_by'] === $by) {
            $result = ['status' => 'ok'];
        } else {
            $result = record_conflict($run, null, (int) $v['id'], 'VIP ' . $v['name'], 'already_checked_in', $at, $by,
                $v['checked_in_at'], $v['checked_in_by']);
        }
        $pdo->commit();
        return $result;
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
}

/** An arrival taken back offline (the guest was let in by mistake). */
function offline_vip_undo(array $run, array $event): array
{
    db()->prepare('UPDATE vip_guests SET checked_in_at = NULL, checked_in_by = NULL WHERE id = ? AND run_id = ?')
        ->execute([(int) ($event['id'] ?? 0), $run['id']]);
    return ['status' => 'ok'];
}

function reservation_label(array $r): string
{
    return mb_substr(trim('VS ' . $r['variable_symbol'] . ' ' . $r['first_name'] . ' ' . $r['last_name']), 0, 250);
}

function record_conflict(
    array $run,
    ?int $reservationId,
    ?int $vipId,
    string $label,
    string $reason,
    DateTimeImmutable $at,
    string $by,
    ?string $otherAt = null,
    ?string $otherBy = null,
): array {
    // Sending the same event again must not add the conflict twice.
    $exists = db()->prepare(
        'SELECT 1 FROM scan_conflicts WHERE run_id = ? AND reservation_id <=> ? AND vip_guest_id <=> ? AND label = ?
           AND scanned_at = ? AND scanned_by = ?'
    );
    $exists->execute([$run['id'], $reservationId, $vipId, mb_substr($label, 0, 250), db_time($at), $by]);
    if ($exists->fetchColumn() === false) {
        db()->prepare(
            'INSERT INTO scan_conflicts (run_id, reservation_id, vip_guest_id, label, reason, scanned_at, scanned_by, other_at, other_by, created_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        )->execute([$run['id'], $reservationId, $vipId, mb_substr($label, 0, 250), $reason, db_time($at), $by, $otherAt, $otherBy, db_time(now_utc())]);
    }
    return ['status' => 'conflict', 'reason' => $reason, 'recorded' => true];
}

/** Conflict reasons for the admin. */
const CONFLICT_REASONS = [
    'already_checked_in' => 'už odbaveno jinde',
    'not_paid' => 'vstupenka neplatila (nezaplaceno / zrušeno)',
    'unknown' => 'neznámá vstupenka',
];
