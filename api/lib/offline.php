<?php
// Scanning without a connection: the scanner downloads a list of the run's
// tickets in advance (scanner_snapshot()), checks tickets against
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
 * entrance needs: no e-mails, no money.
 */
function scanner_snapshot(array $run): array
{
    $stmt = db()->prepare(
        'SELECT id, specific_symbol, first_name, last_name, status FROM reservations WHERE run_id = ? ORDER BY id'
    );
    $stmt->execute([$run['id']]);
    $rows = $stmt->fetchAll();
    $items = load_reservation_items(array_column($rows, 'id'));
    $tickets = array_map(static function ($r) use ($items) {
        $r['items'] = $items[(int) $r['id']] ?? [];
        $current = current_items($r);
        return [
            'id' => (int) $r['id'],
            'specificSymbol' => (string) $r['specific_symbol'],
            'name' => reservation_name($r),
            'seats' => current_seat_ids($r),
            'childSeats' => array_map('strval', array_keys(array_filter($current, static fn ($i) => $i['kind'] === 'child'))),
            'status' => $r['status'],
            'checkedIn' => (object) reservation_checkins($r),
        ];
    }, $rows);

    return [
        'runId' => $run['id'],
        'at' => iso_utc(now_utc()),
        'tickets' => $tickets,
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
 *   {type: "ticket", id, specificSymbol, seat, at} – seat ticket let in
 * A seat already checked in by somebody else, an unpaid, cancelled or unknown ticket
 * is recorded in scan_conflicts. Sending the same event again is harmless.
 * Returns ['results' => [['status' => 'ok'|'conflict', 'reason' => ?string]], 'conflicts' => int].
 */
function apply_offline_scans(array $access, array $run, array $events): array
{
    $by = mb_substr($access['name'], 0, 200 - strlen(OFFLINE_SUFFIX)) . OFFLINE_SUFFIX;
    $results = [];
    $conflicts = 0;
    foreach (array_slice($events, 0, 1000) as $event) {
        $event = is_array($event) ? $event : [];
        $result = match ($event['type'] ?? '') {
            'ticket' => offline_ticket($run, $event, $by),
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
    $ss = (string) ($event['specificSymbol'] ?? '');
    $seat = (string) ($event['seat'] ?? '');
    $pdo = db();
    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare('SELECT * FROM reservations WHERE id = ? AND specific_symbol = ? AND run_id = ? FOR UPDATE');
        $stmt->execute([(int) ($event['id'] ?? 0), $ss, $run['id']]);
        $r = $stmt->fetch();
        $item = $r ? (reservation_items($r)[$seat] ?? null) : null;
        if (!$r) {
            $result = record_conflict($run, null, 'SS ' . $ss . ($seat !== '' ? ' · ' . $seat : ''), 'unknown', $at, $by);
        } elseif ($r['status'] !== 'paid' || $item === null || $item['cancelled']) {
            $result = record_conflict($run, (int) $r['id'], reservation_label($r, $seat), 'not_paid', $at, $by);
        } else {
            if ($item['checkedInAt'] === null) {
                $pdo->prepare('UPDATE reservation_items SET checked_in_at = ?, checked_in_by = ? WHERE reservation_id = ? AND seat_id = ?')
                    ->execute([db_time($at), $by, $r['id'], $seat]);
                // The reservation keeps its earliest check-in.
                $pdo->prepare('UPDATE reservations SET checked_in_by = IF(checked_in_at IS NULL OR checked_in_at > ?, ?, checked_in_by),
                     checked_in_at = IF(checked_in_at IS NULL OR checked_in_at > ?, ?, checked_in_at) WHERE id = ?')
                    ->execute([db_time($at), $by, db_time($at), db_time($at), $r['id']]);
                $result = ['status' => 'ok'];
            } elseif ($item['checkedInAt'] === db_time($at) && $item['checkedInBy'] === $by) {
                $result = ['status' => 'ok']; // the same event sent again
            } else {
                $result = record_conflict($run, (int) $r['id'], reservation_label($r, $seat), 'already_checked_in', $at, $by,
                    $item['checkedInAt'], $item['checkedInBy']);
            }
        }
        $pdo->commit();
        return $result;
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
}

/** "SS 123 Jana Nováková · Levá hlavní · řada 1 · místo 2" */
function reservation_label(array $r, string $seat): string
{
    return mb_substr(trim('SS ' . $r['specific_symbol'] . ' ' . $r['first_name'] . ' ' . $r['last_name']) . ' · ' . seat_label($seat), 0, 250);
}

function record_conflict(
    array $run,
    ?int $reservationId,
    string $label,
    string $reason,
    DateTimeImmutable $at,
    string $by,
    ?string $otherAt = null,
    ?string $otherBy = null,
): array {
    // Sending the same event again must not add the conflict twice.
    $exists = db()->prepare(
        'SELECT 1 FROM scan_conflicts WHERE run_id = ? AND reservation_id <=> ? AND label = ?
           AND scanned_at = ? AND scanned_by = ?'
    );
    $exists->execute([$run['id'], $reservationId, mb_substr($label, 0, 250), db_time($at), $by]);
    if ($exists->fetchColumn() === false) {
        db()->prepare(
            'INSERT INTO scan_conflicts (run_id, reservation_id, label, reason, scanned_at, scanned_by, other_at, other_by, created_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
        )->execute([$run['id'], $reservationId, mb_substr($label, 0, 250), $reason, db_time($at), $by, $otherAt, $otherBy, db_time(now_utc())]);
    }
    return ['status' => 'conflict', 'reason' => $reason, 'recorded' => true];
}

/** Conflict reasons for the admin. */
const CONFLICT_REASONS = [
    'already_checked_in' => 'už odbaveno jinde',
    'not_paid' => 'vstupenka neplatila (nezaplaceno / zrušeno / místo zrušeno)',
    'unknown' => 'neznámá vstupenka',
];
