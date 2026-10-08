<?php
// Scheduled jobs – run every 10 minutes from cron:
//   */10 * * * * php /path/to/api/cron.php
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

$expired = expire_reservations();
$sent = send_due_notifications();
$purged = purge_personal_data();
cleanup_rate_limits();

printf(
    "%s expired %d, reminders %d, expiry notices %d, anonymized %d\n",
    date('c'), $expired, $sent['reminders'], $sent['expiry'], $purged
);
