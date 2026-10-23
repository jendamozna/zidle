<?php
// Prints a fresh invitation link for the account given as the argument (what the e-mail would carry).
declare(strict_types=1);

require __DIR__ . '/../php/fixtures.php';

$user = admin_user_by_email((string) ($argv[1] ?? ''));
if ($user === null) {
    fwrite(STDERR, "No such account.\n");
    exit(1);
}
echo admin_invite_link(admin_new_invite((int) $user['id']));
