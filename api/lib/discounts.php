<?php
// Discount codes: the admin enters e-mail addresses, each gets one code (an address
// listed N times gets N codes, all in one e-mail). A code works for any run, once, and makes
// the most expensive seat of the reservation free (assign_free_seats()). A used code
// stays used even when the reservation is cancelled or expires.
declare(strict_types=1);

/** Letters and digits that can't be confused when typed (no 0/O, 1/I, Y/Z). */
const DISCOUNT_ALPHABET = 'ABCDEFGHJKLMNPQRSTUVWX23456789';
/** New codes: 4 pairs, each a syllable (consonant + vowel) or two digits, in any order ("BA42-KU77", "4827-BAKU"). */
const DISCOUNT_CONSONANTS = 'BCDFGHJKLMNPQRSTVWX';
const DISCOUNT_VOWELS = 'AEU';
const DISCOUNT_DIGITS = '23456789';
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
    $pick = static fn (string $chars): string => $chars[random_int(0, strlen($chars) - 1)];
    for ($i = 0; $i < 20; $i++) {
        $code = '';
        for ($pair = 0; $pair < 4; $pair++) {
            // 57 syllables vs 64 digit pairs: about even odds
            $code .= random_int(0, 1) === 0
                ? $pick(DISCOUNT_CONSONANTS) . $pick(DISCOUNT_VOWELS)
                : $pick(DISCOUNT_DIGITS) . $pick(DISCOUNT_DIGITS);
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
 * semicolons or spaces) and e-mails each address its codes in one message. Nothing is
 * created when an address is invalid. Returns a message for the admin.
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
    $byEmail = [];
    foreach ($emails as $email) {
        $insert->execute([generate_discount_code(), $email, db_time(now_utc()), $createdBy]);
        $byEmail[mb_strtolower($email)][] = (int) db()->lastInsertId();
    }
    $mailed = count(array_filter($byEmail, 'send_discount_codes_email'));
    $n = count($emails);
    $addresses = count($byEmail);
    return 'Vytvořeno ' . $n . ' ' . ($n === 1 ? 'kód' : ($n < 5 ? 'kódy' : 'kódů'))
        . ' pro ' . $addresses . ' ' . ($addresses === 1 ? 'adresu' : ($addresses < 5 ? 'adresy' : 'adres'))
        . ', odeslané e-maily: ' . $mailed . ' z ' . $addresses . '.'
        . ($mailed < $addresses ? (mail_enabled() ? ' Některé e-maily se neodeslaly – zkuste „Poslat znovu“.' : ' E-maily jsou vypnuté (MAIL_ENABLED).') : '');
}

function discount_code_row(int $id): ?array
{
    $stmt = db()->prepare('SELECT * FROM discount_codes WHERE id = ?');
    $stmt->execute([$id]);
    return $stmt->fetch() ?: null;
}

/** Text of the e-mail with one or more codes ("ABCDEFGH") for one address. */
function discount_codes_mail_text(array $codes): string
{
    $n = count($codes);
    $base = rtrim((string) config('PUBLIC_URL'), '/');
    $where = 'při rezervaci' . ($base !== '' ? " na {$base}/" : '') . ' do pole „Slevové kódy“';
    return implode("\n", [
        'Dobrý den,',
        '',
        $n === 1
            ? 'posíláme Vám slevový kód na jedno místo zdarma na představení Těšín zpívá gospel 2026:'
            : "posíláme Vám {$n} " . ($n < 5 ? 'slevové kódy' : 'slevových kódů') . ' – každý na jedno místo zdarma na představení Těšín zpívá gospel 2026:',
        '',
        ...array_map(static fn ($c) => '    ' . format_discount_code($c), $codes),
        '',
        $n === 1 ? "Kód zadejte {$where}." : "Kódy zadejte {$where} – do jedné rezervace jich lze zadat víc, jeden na místo.",
        'Na velikosti písmen nezáleží a pomlčku psát nemusíte.',
        $n === 1 ? 'Platí na libovolný termín a lze ho použít jednou.' : 'Každý platí na libovolný termín a lze ho použít jednou.',
        '',
        'Těšín zpívá gospel',
        ...(contact_line() !== '' ? [contact_line()] : []),
    ]);
}

/** One e-mail with the given codes (all of one address); records emailed_at. False when not sent. */
function send_discount_codes_email(array $ids): bool
{
    $rows = array_values(array_filter(array_map('discount_code_row', $ids)));
    if (!$rows || !mail_enabled() || $rows[0]['email'] === '') {
        return false;
    }
    $codes = array_column($rows, 'code');
    $ok = deliver_mail($rows[0]['email'], mail_subject(count($codes) === 1 ? 'Slevový kód' : 'Slevové kódy'), discount_codes_mail_text($codes));
    $ids = array_map('intval', array_column($rows, 'id'));
    if ($ok) {
        db()->prepare('UPDATE discount_codes SET emailed_at = ? WHERE id IN (' . implode(',', $ids) . ')')->execute([db_time(now_utc())]);
    } else {
        app_log('Failed to send discount codes ' . implode(',', $ids));
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

/** Code (normalized) exists and is neither used nor revoked; booking checks it again. */
function discount_code_usable(string $code): bool
{
    $stmt = db()->prepare('SELECT 1 FROM discount_codes WHERE code = ? AND used_at IS NULL AND revoked_at IS NULL');
    $stmt->execute([$code]);
    return (bool) $stmt->fetchColumn();
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
