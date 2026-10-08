<?php
// Cancels unpaid reservations past their deadline and frees their seats.
// The API does this lazily on every request too; run it from cron so seats
// are freed even when nobody visits the site, e.g.:
//   */10 * * * * php /path/to/api/cron-expire.php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit;
}
require __DIR__ . '/lib/bootstrap.php';

$count = expire_reservations();
cleanup_rate_limits();
echo date('c') . " expired {$count} reservation(s)\n";
