<?php
// Scheduled jobs – run every 5 minutes from cron:
//   */5 * * * * php /path/to/api/cron.php
// - reads bank notification e-mails and records the payments (PAYMENT_IMAP_*, payment_mail.php)
// - cancels unpaid reservations after the due date + grace period, frees seats
// - e-mails payment reminders (24 h before the due date) and expiry notices
// - deletes personal data DATA_RETENTION_DAYS after the event
// - cleans up old rate-limit records
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit;
}
require __DIR__ . '/lib/bootstrap.php';

// Payments first, so a transfer that just arrived counts before unpaid reservations expire.
$sync = payment_sync_enabled() ? payment_sync('cron') : null;
$expired = expire_reservations();
$sent = send_due_notifications();
$purged = purge_personal_data();
cleanup_rate_limits();

printf(
    "%s expired %d, reminders %d, expiry notices %d, anonymized %d, payments: %s\n",
    date('c'), $expired, $sent['reminders'], $sent['expiry'], $purged,
    $sync === null ? 'off' : ($sync['ok'] ? "mails {$sync['mails']}, recorded {$sync['recorded']}, to review {$sync['review']}" : 'error ' . $sync['error'])
);
