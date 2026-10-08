<?php
// VIP guests: free entry with specific seats held in reservation_seats.
declare(strict_types=1);

/**
 * Adds a VIP guest holding the given seats of the run (free of charge). The seats
 * show as taken on the public map; fails when one of them is no longer free.
 */
function add_vip_guest(int $runId, string $name, array $seats, string $note): string
{
    if ($name === '' || mb_strlen($name) > 200 || mb_strlen($note) > 255 || !run_by_id($runId)) {
        return 'Vyplňte termín a jméno.';
    }
    if (!$seats || count($seats) > 50 || array_filter($seats, static fn ($s) => !is_valid_seat_id($s))) {
        return 'Vyberte na plánku 1 až 50 míst.';
    }
    usort($seats, 'compare_seat_ids');
    $pdo = db();
    $pdo->beginTransaction();
    try {
        $placeholders = implode(',', array_fill(0, count($seats), '?'));
        $taken = $pdo->prepare("SELECT seat_id FROM reservation_seats WHERE run_id = ? AND seat_id IN ($placeholders) FOR UPDATE");
        $taken->execute([$runId, ...$seats]);
        if ($conflict = $taken->fetchAll(PDO::FETCH_COLUMN)) {
            $pdo->rollBack();
            return 'Místa ' . implode(', ', $conflict) . ' už jsou obsazená. Vyberte jiná.';
        }
        $pdo->prepare('INSERT INTO vip_guests (run_id, name, section, seats, persons, note, created_at) VALUES (?, ?, ?, ?, ?, ?, ?)')
            ->execute([$runId, $name, explode('-', $seats[0])[0], implode(',', $seats), count($seats), $note, db_time(now_utc())]);
        $vipId = (int) $pdo->lastInsertId();
        $insert = $pdo->prepare('INSERT INTO reservation_seats (run_id, seat_id, vip_guest_id) VALUES (?, ?, ?)');
        foreach ($seats as $seat) {
            $insert->execute([$runId, $seat, $vipId]);
        }
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
    return "VIP host {$name} přidán (" . count($seats) . ' ' . (count($seats) === 1 ? 'místo' : (count($seats) < 5 ? 'místa' : 'míst')) . ').';
}

/** VIP guests of a run for the scanner. */
function vip_list(int $runId): array
{
    $stmt = db()->prepare('SELECT id, name, section, seats, persons, note, checked_in_at, checked_in_by FROM vip_guests WHERE run_id = ? ORDER BY name');
    $stmt->execute([$runId]);
    return array_map(static fn ($v) => [
        'id' => (int) $v['id'],
        'name' => $v['name'],
        'section' => $v['section'],
        'persons' => (int) $v['persons'],
        'seats' => seat_labels($v['seats']),
        'note' => $v['note'],
        'checkedInAt' => iso_time($v['checked_in_at']),
        'checkedInBy' => $v['checked_in_by'],
    ], $stmt->fetchAll());
}
