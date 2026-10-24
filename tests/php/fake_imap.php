<?php
// Fake IMAP server for the payment mailbox test (no TLS, one client at a time):
//   php fake_imap.php <messages.json>   prints the port, then serves until killed.
// messages.json: {"user": "...", "password": "...", "uidvalidity": 7, "messages": {"<uid>": "<raw e-mail>"}}
declare(strict_types=1);

$data = json_decode((string) file_get_contents($argv[1]), true);
$server = stream_socket_server('tcp://127.0.0.1:0', $errno, $error);
if ($server === false) {
    fwrite(STDERR, (string) $error);
    exit(1);
}
echo explode(':', (string) stream_socket_get_name($server, false))[1], "\n";
flush();

while ($client = @stream_socket_accept($server, -1)) {
    fwrite($client, "* OK fake IMAP ready\r\n");
    while (($line = fgets($client)) !== false) {
        [$tag, $command, $args] = array_pad(explode(' ', rtrim($line, "\r\n"), 3), 3, '');
        $command = strtoupper($command);
        if ($command === 'CAPABILITY') {
            fwrite($client, "* CAPABILITY IMAP4rev1\r\n{$tag} OK done\r\n");
        } elseif ($command === 'LOGIN') {
            $quote = static fn (string $v) => '"' . addcslashes($v, '"\\') . '"';
            $ok = $args === $quote($data['user']) . ' ' . $quote($data['password']);
            fwrite($client, $ok ? "{$tag} OK logged in\r\n" : "{$tag} NO [AUTHENTICATIONFAILED] Invalid credentials\r\n");
        } elseif ($command === 'EXAMINE') {
            fwrite($client, '* ' . count($data['messages']) . " EXISTS\r\n* OK [UIDVALIDITY {$data['uidvalidity']}] UIDs valid\r\n{$tag} OK [READ-ONLY] done\r\n");
        } elseif ($command === 'UID' && str_starts_with(strtoupper($args), 'SEARCH SINCE ')) {
            fwrite($client, '* SEARCH ' . implode(' ', array_keys($data['messages'])) . "\r\n{$tag} OK done\r\n");
        } elseif ($command === 'UID' && preg_match('/^FETCH (\d+) \(BODY\.PEEK\[\]\)$/i', $args, $m)) {
            $raw = $data['messages'][$m[1]] ?? null;
            if ($raw !== null) {
                fwrite($client, "* 1 FETCH (UID {$m[1]} BODY[] {" . strlen($raw) . "}\r\n{$raw})\r\n");
            }
            fwrite($client, "{$tag} OK done\r\n");
        } elseif ($command === 'LOGOUT') {
            fwrite($client, "* BYE\r\n{$tag} OK bye\r\n");
            break;
        } else {
            fwrite($client, "{$tag} BAD unknown\r\n");
        }
    }
    fclose($client);
}
