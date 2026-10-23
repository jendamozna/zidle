<?php
// Bank statement import (admin → Výpis z banky): incoming transfers from a CSV export of
// internet banking are matched to reservations by the specific symbol and recorded with
// record_payment(). Every bank transaction is recorded only once (table bank_payments).
declare(strict_types=1);

const BANK_CSV_MAX_BYTES = 2_000_000;

/** Statement columns (header names, the first one present wins; KB MojeBanka first, then Fio). */
const BANK_COLUMNS = [
    'amount' => ['Částka', 'Objem'],
    'vs' => ['VS', 'Variabilní symbol'],
    'ss' => ['SS', 'Specifický symbol'],
    'id' => ['Identifikace transakce', 'ID pohybu', 'ID transakce'],
    'date' => ['Datum splatnosti', 'Datum zaúčtování', 'Datum'],
    'account' => ['Protiúčet a kód banky', 'Protiúčet'],
    'name' => ['Název protiúčtu'],
];

/** Columns joined into the message for the payee. */
const BANK_MESSAGE_COLUMNS = ['Popis pro příjemce', 'Zpráva pro příjemce', 'AV pole 1', 'AV pole 2', 'AV pole 3', 'AV pole 4'];

/**
 * Parses a bank statement CSV (UTF-8 or Windows-1250, separator ";" or ",", any lines before
 * the header row). Returns ['rows' => incoming transfers, 'outgoing' => number of skipped
 * outgoing ones] or an error message in Czech. A row: id, date (Y-m-d or null), account,
 * name, cents (amount in haléře, > 0), vs, ss (digits without leading zeros), message.
 */
function parse_bank_csv(string $raw): array|string
{
    if (trim($raw) === '') {
        return 'Soubor je prázdný.';
    }
    if (strlen($raw) > BANK_CSV_MAX_BYTES) {
        return 'Soubor je příliš velký (nejvýš 2 MB).';
    }
    $raw = (string) preg_replace('/^\xEF\xBB\xBF/', '', $raw);
    if (!mb_check_encoding($raw, 'UTF-8')) {
        $converted = @iconv('WINDOWS-1250', 'UTF-8//IGNORE', $raw);
        if ($converted === false) {
            return 'Soubor se nepodařilo přečíst (neznámé kódování).';
        }
        $raw = $converted;
    }

    $map = null;
    $rows = [];
    $outgoing = 0;
    foreach (preg_split('/\r\n|\r|\n/', $raw) ?: [] as $line) {
        if (trim($line) === '') {
            continue;
        }
        if ($map === null) {
            foreach ([';', ','] as $separator) {
                $map = bank_csv_header(bank_csv_cells($line, $separator));
                if ($map !== null) {
                    $map['separator'] = $separator;
                    break;
                }
            }
            continue;
        }
        $cells = bank_csv_cells($line, $map['separator']);
        $cell = static fn (string $key) => isset($map[$key]) ? (string) ($cells[$map[$key]] ?? '') : '';
        $amount = str_replace([' ', "\u{a0}", ','], ['', '', '.'], $cell('amount'));
        if (!preg_match('/^[+-]?\d+(\.\d{1,2})?$/', $amount)) {
            continue; // e.g. a summary line after the transactions
        }
        $cents = (int) round((float) $amount * 100);
        if ($cents <= 0) {
            $outgoing++;
            continue;
        }
        $date = DateTimeImmutable::createFromFormat('!j.n.Y', $cell('date'))
            ?: DateTimeImmutable::createFromFormat('!Y-m-d', $cell('date'));
        $message = implode(' ', array_filter(array_map(
            static fn ($i) => (string) ($cells[$i] ?? ''),
            $map['message']
        ), static fn ($v) => $v !== ''));
        $symbol = static fn (string $v) => ltrim((string) preg_replace('/\D/', '', $v), '0');
        $row = [
            'id' => '',
            'date' => $date ? $date->format('Y-m-d') : null,
            'account' => $cell('account'),
            'name' => $cell('name'),
            'cents' => $cents,
            'vs' => $symbol($cell('vs')),
            'ss' => $symbol($cell('ss')),
            'message' => $message,
        ];
        $id = $cell('id');
        $row['id'] = $id !== '' ? mb_substr($id, 0, 100) : 'h:' . sha1(implode('|', $row));
        $rows[] = $row;
    }
    if ($map === null) {
        return 'Ve souboru chybí řádek se záhlavím (sloupce Částka a SS). Nahrajte výpis z internetového bankovnictví ve formátu CSV.';
    }
    return ['rows' => $rows, 'outgoing' => $outgoing];
}

/** Trimmed cells of one CSV line. */
function bank_csv_cells(string $line, string $separator): array
{
    return array_map(static fn ($v) => trim((string) $v), str_getcsv($line, $separator, '"', ''));
}

/** Column indexes of a header row, or null when the line is not the header. */
function bank_csv_header(array $cells): ?array
{
    $map = [];
    foreach (BANK_COLUMNS as $key => $names) {
        foreach ($names as $name) {
            $i = array_search($name, $cells, true);
            if ($i !== false) {
                $map[$key] = $i;
                break;
            }
        }
    }
    if (!isset($map['amount'], $map['ss'])) {
        return null;
    }
    $map['message'] = array_values(array_filter(array_map(
        static fn ($name) => array_search($name, $cells, true),
        BANK_MESSAGE_COLUMNS
    ), static fn ($i) => $i !== false));
    return $map;
}

/**
 * What recording an incoming transfer would do: ['reservation' => row or null, 'state' =>
 * 'done' (already recorded) | 'none' (nothing to record automatically) | 'match', 'note' =>
 * text for the admin, 'select' => pre-checked in the preview]. The reservation is found by
 * SS; when that fails, by the VS (unless it is the common VS) or a 10-digit number in the message.
 */
function bank_match(array $tx): array
{
    $stmt = db()->prepare('SELECT * FROM bank_payments WHERE transaction_id = ?');
    $stmt->execute([$tx['id']]);
    $done = $stmt->fetch();
    if ($done) {
        return [
            'reservation' => $done['reservation_id'] !== null ? bank_reservation('id', (string) $done['reservation_id']) : null,
            'state' => 'done',
            'note' => 'Už zapsáno ' . utc_time($done['imported_at'])->setTimezone(new DateTimeZone(PRAGUE))->format('j. n. Y H:i') . ' (' . $done['imported_by'] . ').',
            'select' => false,
        ];
    }

    $commonVs = ltrim(payment_variable_symbol(), '0');
    $candidates = [[$tx['ss'], '']];
    if ($tx['vs'] !== $commonVs) {
        $candidates[] = [$tx['vs'], 'Nalezeno podle VS. '];
    }
    preg_match_all('/(?<!\d)\d{10}(?!\d)/', $tx['message'], $found);
    foreach ($found[0] as $number) {
        $candidates[] = [$number, 'Nalezeno podle zprávy pro příjemce. '];
    }
    $r = null;
    $how = '';
    foreach ($candidates as [$symbol, $via]) {
        if ($symbol !== '' && ($r = bank_reservation('specific_symbol', $symbol))) {
            $how = $via;
            break;
        }
    }
    if ($r === null) {
        return [
            'reservation' => null,
            'state' => 'none',
            'note' => ($tx['ss'] === '' ? 'Platba nemá SS' : 'Rezervace se SS ' . $tx['ss'] . ' neexistuje') . ' – zapište ručně, pokud k rezervaci patří.',
            'select' => false,
        ];
    }

    $none = static fn (string $note) => ['reservation' => $r, 'state' => 'none', 'note' => $how . $note, 'select' => false];
    if ($tx['cents'] % 100 !== 0) {
        return $none('Částka s haléři – zapište ručně.');
    }
    if ($tx['cents'] > 100_000_000) {
        return $none('Částka je příliš vysoká – zapište ručně.');
    }
    $kc = intdiv($tx['cents'], 100);
    $note = $how . ($commonVs !== '' && $tx['vs'] !== $commonVs ? 'VS ' . ($tx['vs'] ?: 'chybí') . ' (má být ' . $commonVs . '). ' : '');
    $select = true;
    if ($r['status'] === 'pending') {
        $due = amount_due($r);
        $note .= match (true) {
            $kc === $due => 'Celá částka → Zaplaceno, vstupenka e-mailem.',
            $kc < $due => 'Částečná platba, zbude doplatit ' . format_czk($due - $kc) . '.',
            default => 'Zaplaceno, přeplatek ' . format_czk($kc - $due) . ' k vrácení.',
        };
        if ((int) $r['paid_amount'] > 0) {
            $note .= ' Už evidováno ' . format_czk((int) $r['paid_amount']) . ' – pokud jste tuto platbu zapsali ručně, nezapisujte ji znovu.';
            $select = false;
        }
    } elseif ($r['status'] === 'expired') {
        $note .= 'Rezervace propadla – obnoví se jako zaplacená, pokud to jde, jinak půjde platba k vrácení.';
    } else {
        $note .= ($r['status'] === 'paid' ? 'Rezervace už je zaplacená' : 'Rezervace je zrušená')
            . ' – platba půjde k vrácení. Pokud jste ji už zapsali ručně, nezapisujte ji znovu.';
        $select = false;
    }
    return ['reservation' => $r, 'state' => 'match', 'note' => $note, 'select' => $select];
}

function bank_reservation(string $column, string $value): ?array
{
    $stmt = db()->prepare("SELECT * FROM reservations WHERE {$column} = ?");
    $stmt->execute([$value]);
    return $stmt->fetch() ?: null;
}

/**
 * Records one incoming transfer to its matched reservation (see bank_match) and remembers the
 * transaction. Returns ['ok' => bool, 'message' => text for the admin].
 */
function bank_record(array $tx, string $by): array
{
    $match = bank_match($tx);
    if ($match['state'] !== 'match') {
        return ['ok' => false, 'message' => $match['note']];
    }
    $id = (int) $match['reservation']['id'];
    $insert = db()->prepare(
        'INSERT IGNORE INTO bank_payments (transaction_id, reservation_id, amount, booked_on, imported_at, imported_by) VALUES (?, ?, ?, ?, ?, ?)'
    );
    $insert->execute([$tx['id'], $id, intdiv($tx['cents'], 100), $tx['date'], db_time(now_utc()), $by]);
    if ($insert->rowCount() === 0) {
        return ['ok' => false, 'message' => 'Už zapsáno.'];
    }
    $forget = static fn () => db()->prepare('DELETE FROM bank_payments WHERE transaction_id = ?')->execute([$tx['id']]);
    try {
        $message = record_payment($id, intdiv($tx['cents'], 100));
    } catch (Throwable $e) {
        $forget();
        throw $e;
    }
    if (!str_starts_with($message, 'Přijato')) {
        $forget();
        return ['ok' => false, 'message' => $message];
    }
    return ['ok' => true, 'message' => $message];
}
