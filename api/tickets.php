<?php
// GET api/tickets.php?token=<reservation token> – printable tickets (PDF, one per seat) of a paid reservation.
declare(strict_types=1);
require __DIR__ . '/lib/bootstrap.php';

run_api(function (): void {
    if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
        json_error('Metoda není povolena.', 405);
    }
    $token = (string) ($_GET['token'] ?? '');
    $r = preg_match('/^[a-f0-9]{32}$/', $token) ? find_reservation_by_token($token) : null;
    if ($r === null || $r['status'] !== 'paid') {
        json_error('Vstupenky nejsou k dispozici – rezervace není zaplacená.', 404);
    }
    $pdf = ticket_pdf($r);
    header('Content-Type: application/pdf');
    header('Content-Disposition: attachment; filename="' . TICKET_PDF_NAME . '"');
    header('Cache-Control: no-store');
    header('Content-Length: ' . strlen($pdf));
    echo $pdf;
});
