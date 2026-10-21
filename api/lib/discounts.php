<?php
// Discount codes: the admin enters e-mail addresses, each gets one code by e-mail
// (an address listed twice gets two codes). A code works for any run, once, and makes
// the most expensive seat of the reservation free (assign_free_seats()). A used code
// stays used even when the reservation is cancelled or expires.
declare(strict_types=1);

/** Letters and digits that can't be confused when typed (no 0/O, 1/I). */
const DISCOUNT_ALPHABET = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
const DISCOUNT_MAX_PER_BATCH = 200;

/** "abcd efgh" / "ABCD-EFGH" → "ABCDEFGH" (no validity check). */
function normalize_discount_code(string $input): string
{
    return strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $input) ?? '');
}

/** "ABCDEFGH" → "ABCD-EFGH" */
function format_discount_code(string $code): string
{
    return substr($code, 0, 4) . '-' . substr($code, 4);
}

function generate_discount_code(): string
{
    $check = db()->prepare('SELECT 1 FROM discount_codes WHERE code = ?');
    for ($i = 0; $i < 20; $i++) {
        $code = '';
        for ($j = 0; $j < 8; $j++) {
            $code .= DISCOUNT_ALPHABET[random_int(0, strlen(DISCOUNT_ALPHABET) - 1)];
        }
        $check->execute([$code]);
        if (!$check->fetchColumn()) {
            return $code;
        }
    }
    throw new RuntimeException('Could not generate a unique discount code.');
}

/**
 * Creates one code per e-mail address in $text (separated by new lines, commas,
 * semicolons or spaces) and e-mails each. Nothing is created when an address is invalid.
 * Returns a message for the admin.
 */
function create_discount_codes(string $text, string $createdBy): string
{
    $emails = array_values(array_filter(array_map('trim', preg_split('/[\s,;]+/', $text) ?: [])));
    if (!$emails) {
        return 'Zadejte alespoň jeden e-mail.';
    }
    if (count($emails) > DISCOUNT_MAX_PER_BATCH) {
        return 'Najednou lze poslat nejvýše ' . DISCOUNT_MAX_PER_BATCH . ' kódů.';
    }
    $invalid = array_filter($emails, static fn ($e) => mb_strlen($e) > 190 || !filter_var($e, FILTER_VALIDATE_EMAIL));
    if ($invalid) {
        return 'Neplatné e-maily: ' . implode(', ', $invalid) . '. Nic nebylo vytvořeno.';
    }
    $insert = db()->prepare('INSERT INTO discount_codes (code, email, created_at, created_by) VALUES (?, ?, ?, ?)');
    $mailed = 0;
    foreach ($emails as $email) {
        $insert->execute([generate_discount_code(), $email, db_time(now_utc()), $createdBy]);
        if (send_discount_code_email((int) db()->lastInsertId())) {
            $mailed++;
        }
    }
    $n = count($emails);
    return 'Vytvořeno ' . $n . ' ' . ($n === 1 ? 'kód' : ($n < 5 ? 'kódy' : 'kódů')) . ', e-mailem odesláno ' . $mailed . '.'
        . ($mailed < $n ? (mail_enabled() ? ' Některé e-maily se neodeslaly – zkuste „Poslat znovu“.' : ' E-maily jsou vypnuté (MAIL_ENABLED).') : '');
}

function discount_code_row(int $id): ?array
{
    $stmt = db()->prepare('SELECT * FROM discount_codes WHERE id = ?');
    $stmt->execute([$id]);
    return $stmt->fetch() ?: null;
}

/** E-mail with the code; records emailed_at. False when not sent. */
function send_discount_code_email(int $id): bool
{
    $c = discount_code_row($id);
    if ($c === null || !mail_enabled() || $c['email'] === '') {
        return false;
    }
    $base = rtrim((string) config('PUBLIC_URL'), '/');
    $body = implode("\n", [
        'Dobrý den,',
        '',
        'posíláme Vám slevový kód na jedno místo zdarma na představení Moje židle 2026:',
        '',
        '    ' . format_discount_code($c['code']),
        '',
        'Kód zadejte při rezervaci' . ($base !== '' ? " na {$base}/" : '') . ' do pole „Slevové kódy“.',
        'Platí na libovolný termín a lze ho použít jednou. Z rezervace se odečte cena jednoho místa'
            . ' (nejdražšího – nejdřív dospělého, pak dětského).',
        '',
        'Moje židle 2026',
        ...(contact_line() !== '' ? [contact_line()] : []),
    ]);
    $ok = deliver_mail($c['email'], 'Slevový kód – Moje židle 2026', $body);
    if ($ok) {
        db()->prepare('UPDATE discount_codes SET emailed_at = ? WHERE id = ?')->execute([db_time(now_utc()), $id]);
    } else {
        error_log("[zidle] Failed to send discount code {$id}");
    }
    return $ok;
}

/** Admin: an unused code stops working. */
function revoke_discount_code(int $id): string
{
    $stmt = db()->prepare('UPDATE discount_codes SET revoked_at = ? WHERE id = ? AND used_at IS NULL AND revoked_at IS NULL');
    $stmt->execute([db_time(now_utc()), $id]);
    return $stmt->rowCount() ? 'Kód zrušen – už ho nelze použít.' : 'Kód nelze zrušit (už byl použit nebo zrušen).';
}

/**
 * Inside the reservation transaction: locks the codes and returns their ids in the
 * given order, or the first code that can't be used (formatted) as a string.
 */
function lock_discount_codes(PDO $pdo, array $codes): array|string
{
    $ids = [];
    $stmt = $pdo->prepare('SELECT id, used_at, revoked_at FROM discount_codes WHERE code = ? FOR UPDATE');
    foreach ($codes as $code) {
        $stmt->execute([$code]);
        $row = $stmt->fetch();
        if (!$row || $row['used_at'] !== null || $row['revoked_at'] !== null) {
            return format_discount_code($code);
        }
        $ids[] = (int) $row['id'];
    }
    return $ids;
}
