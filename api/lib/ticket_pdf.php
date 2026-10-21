<?php
// Printable tickets (vstupenky.pdf): A4, the reservation's seat tickets one below
// another (four per page) with dashed cut lines, so they can be printed, cut apart and
// handed out – each ticket is one seat with its own QR code (ticket_code()).
declare(strict_types=1);

use Dompdf\Dompdf;
use Dompdf\Options;

const TICKETS_PER_PAGE = 4;

/** PDF (binary) with one ticket per current seat of the reservation. */
function ticket_pdf(array $r): string
{
    require_once __DIR__ . '/../vendor/autoload.php';
    $h = static fn ($v) => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
    $items = reservation_seat_items($r);
    $count = count($items);
    $run = run_by_id((int) $r['run_id']);
    $runText = $run ? run_label($run) : '–';

    $tickets = '';
    foreach ($items as $n => $item) {
        $qr = base64_encode(qr_png(ticket_code($r, $item['seat']), 6));
        [$section, $row, $seat] = array_pad(explode('-', $item['seat']), 3, '');
        $breakBefore = $n > 0 && $n % TICKETS_PER_PAGE === 0;
        $tickets .= '<table class="ticket' . ($breakBefore ? ' page' : '') . '"><tr>'
            . '<td class="qr"><img src="data:image/png;base64,' . $qr . '" width="170" height="170"></td>'
            . '<td class="info">'
            . '<div class="brand">Koncert TZG <span>2026</span></div>'
            . '<div class="run">' . $h($runText) . '</div>'
            . '<div class="seat">' . $h(section_name($section)) . '</div>'
            . '<div class="place">řada <b>' . $h($row) . '</b> &nbsp; místo <b>' . $h($seat) . '</b></div>'
            . '<div class="kind">' . $h(SEAT_KIND_LABELS[$item['kind']]) . '</div>'
            . '<div class="holder">' . $h(ticket_holder($r, $item['seat'])) . '</div>'
            . '<div class="meta">VS ' . $h($r['variable_symbol']) . ' · vstupenka ' . ($n + 1) . ' z ' . $count . '</div>'
            . '</td></tr></table>';
    }

    $html = '<!doctype html><html><head><meta charset="utf-8"><style>'
        . '@page { margin: 12mm 14mm; }'
        . 'body { font-family: "DejaVu Sans", sans-serif; color: #2b2620; font-size: 10pt; }'
        . '.note { margin: 0 0 4mm; color: #6b6256; font-size: 8.5pt; }'
        . '.ticket { width: 100%; height: 60mm; border-collapse: collapse; border-bottom: 0.4mm dashed #9b8f7f; }'
        . '.ticket.page { page-break-before: always; }'
        . '.ticket td { vertical-align: middle; padding: 3mm 0; }'
        . '.qr { width: 64mm; }'
        . '.brand { font-family: "DejaVu Serif", serif; font-size: 13pt; font-weight: bold; }'
        . '.brand span { color: #8a5a2b; }'
        . '.run { margin-top: 1mm; font-size: 11pt; color: #8a5a2b; font-weight: bold; }'
        . '.seat { margin-top: 4mm; font-size: 15pt; font-weight: bold; }'
        . '.place { font-size: 15pt; }'
        . '.kind { margin-top: 2mm; font-size: 11pt; }'
        . '.holder { margin-top: 2mm; font-size: 11pt; font-weight: bold; }'
        . '.meta { margin-top: 2mm; font-size: 8.5pt; color: #6b6256; }'
        . '</style></head><body>'
        . '<p class="note">Rozstříhejte podle čárkované čáry. Každá vstupenka platí pro jedno místo – při vstupu se načte její QR kód.</p>'
        . $tickets
        . '</body></html>';

    $options = new Options();
    $options->setIsRemoteEnabled(false);
    $options->setDefaultFont('DejaVu Sans');
    $dompdf = new Dompdf($options);
    $dompdf->setPaper('A4');
    $dompdf->loadHtml($html, 'UTF-8');
    $dompdf->render();
    return (string) $dompdf->output();
}
