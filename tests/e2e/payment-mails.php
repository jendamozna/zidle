<?php
// Two bank notification e-mails as the payment mailbox sync would process them: a second
// payment for a new paid reservation in run 2 (waits for review) and an unreadable one.
// Prints the reservation's SS.
declare(strict_types=1);

require __DIR__ . '/../php/fixtures.php';

$id = reservation(['BP-1-1'], 'paid', 2, 'Emailová');
$ss = (string) db()->query("SELECT specific_symbol FROM reservations WHERE id = {$id}")->fetchColumn();
$mail = static fn (string $text) => "From: KB <info@kb.cz>\r\nSubject: Pohyb na uctu\r\nDate: " . date(DATE_RFC2822) . "\r\n"
    . "Message-ID: <" . bin2hex(random_bytes(6)) . "@kb.cz>\r\n"
    . "Authentication-Results: mx.google.com; dkim=pass header.i=@kb.cz; dmarc=pass header.from=kb.cz\r\n"
    . "Content-Type: text/plain; charset=utf-8\r\n\r\n{$text}";
process_payment_mail('e2e:' . $ss . ':1', $mail("Příchozí úhrada\nČástka: 300,00 CZK\nNázev protiúčtu: Emil Emailový\nVS: 3211\nSS: {$ss}"));
process_payment_mail('e2e:' . $ss . ':2', $mail('Výpis z účtu za říjen je připraven.'));
echo $ss;
