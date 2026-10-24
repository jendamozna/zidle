<?php
// Minimal read-only IMAP client (no ext-imap needed): log in, open a folder read-only
// (EXAMINE), search UIDs, fetch whole messages without marking them read.
declare(strict_types=1);

final class ImapClient
{
    /** @var resource */
    private $stream;
    private int $tag = 0;
    /** @var list<string> Last lines of the conversation for the error log ("C:" sent, "S:" received). */
    private array $transcript = [];

    /**
     * Port 993 = implicit TLS; other ports switch to TLS by STARTTLS when the server offers it.
     * $ipv4 connects to the host's IPv4 address (e.g. when the server's IPv6 range is refused);
     * the TLS certificate is still checked against $host.
     */
    public function __construct(string $host, int $port, int $timeout = 20, bool $ipv4 = false)
    {
        $implicit = $port === 993;
        $address = $host;
        if ($ipv4) {
            $address = gethostbyname($host);
            if (filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false) {
                throw new RuntimeException("Nelze zjistit IPv4 adresu serveru schránky {$host}.");
            }
        }
        $this->note("connecting to {$address}:{$port}" . ($ipv4 ? " (IPv4 of {$host})" : ''));
        $context = stream_context_create(['ssl' => ['verify_peer' => true, 'verify_peer_name' => true, 'peer_name' => $host]]);
        $stream = @stream_socket_client(
            ($implicit ? 'ssl://' : 'tcp://') . $address . ':' . $port,
            $errno,
            $error,
            $timeout,
            STREAM_CLIENT_CONNECT,
            $context
        );
        if ($stream === false) {
            throw new RuntimeException("Nelze se připojit ke schránce {$host}:{$port} ({$error}).");
        }
        stream_set_timeout($stream, $timeout);
        $this->stream = $stream;
        if (!str_starts_with($this->line(), '* OK')) {
            throw new RuntimeException('Server schránky neodpověděl jako IMAP.');
        }
        // Capabilities (e.g. AUTH methods) end up in the transcript for the error log.
        if (str_contains(implode(' ', $this->command('CAPABILITY')['lines']), 'STARTTLS') && !$implicit) {
            $this->command('STARTTLS');
            if (!stream_socket_enable_crypto($stream, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
                throw new RuntimeException('Nepodařilo se zapnout šifrování (STARTTLS).');
            }
        }
    }

    public function login(string $user, #[\SensitiveParameter] string $password): void
    {
        $this->command('LOGIN ' . self::quote($user) . ' ' . self::quote($password));
    }

    /** Opens the folder read-only; returns its UIDVALIDITY. */
    public function examine(string $folder): int
    {
        $response = $this->command('EXAMINE ' . self::quote($folder));
        foreach ($response['lines'] as $line) {
            if (preg_match('/\[UIDVALIDITY (\d+)\]/i', $line, $m)) {
                return (int) $m[1];
            }
        }
        return 0;
    }

    /** UIDs of messages in the open folder that arrived on or after $since (ascending). */
    public function searchSince(DateTimeImmutable $since): array
    {
        $uids = [];
        foreach ($this->command('UID SEARCH SINCE ' . $since->format('j-M-Y'))['lines'] as $line) {
            if (preg_match('/^\* SEARCH\b(.*)$/i', $line, $m)) {
                foreach (preg_split('/\s+/', trim($m[1])) ?: [] as $uid) {
                    if (ctype_digit($uid)) {
                        $uids[] = (int) $uid;
                    }
                }
            }
        }
        sort($uids);
        return $uids;
    }

    /** The whole message (headers and body) as sent; does not set the \Seen flag. */
    public function fetch(int $uid): ?string
    {
        return $this->command('UID FETCH ' . $uid . ' (BODY.PEEK[])')['literals'][0] ?? null;
    }

    public function logout(): void
    {
        try {
            $this->command('LOGOUT');
        } catch (RuntimeException) {
            // the connection is closed anyway
        }
        fclose($this->stream);
    }

    /** The conversation so far (at most the last 40 lines, password masked, no message contents). */
    public function transcript(): string
    {
        return implode("\n", $this->transcript);
    }

    private function note(string $line): void
    {
        $this->transcript[] = mb_strimwidth($line, 0, 300, '…');
        $this->transcript = array_slice($this->transcript, -40);
    }

    /**
     * Sends a command and reads up to its tagged answer: untagged lines (a literal {n} is
     * replaced by "{}" and its content kept in 'literals'). Throws when the answer is not OK.
     */
    private function command(#[\SensitiveParameter] string $command): array
    {
        $tag = 'A' . ++$this->tag;
        $this->note('C: ' . $tag . ' ' . (str_starts_with($command, 'LOGIN ') ? (string) preg_replace('/ "(?:[^"\\\\]|\\\\.)*"$/', ' ***', $command) : $command));
        $this->write("{$tag} {$command}\r\n");
        $lines = [];
        $literals = [];
        while (true) {
            $line = $this->line();
            while (preg_match('/\{(\d+)\}$/', $line, $m)) {
                $literals[] = $this->read((int) $m[1]);
                $this->note('S: (' . $m[1] . ' bytes)');
                $line = substr($line, 0, -strlen($m[0])) . '{}' . $this->line();
            }
            if (str_starts_with($line, $tag . ' ')) {
                $status = substr($line, strlen($tag) + 1);
                if (!str_starts_with(strtoupper($status), 'OK')) {
                    // Only the command name: LOGIN carries the password.
                    throw new RuntimeException('Schránka odmítla příkaz ' . strtok($command, ' ') . ': ' . $status);
                }
                return ['lines' => $lines, 'literals' => $literals];
            }
            $lines[] = $line;
        }
    }

    private function write(string $data): void
    {
        if (@fwrite($this->stream, $data) !== strlen($data)) {
            throw new RuntimeException('Spojení se schránkou bylo přerušeno.');
        }
    }

    /** One line without CRLF. */
    private function line(): string
    {
        $line = fgets($this->stream);
        if ($line === false) {
            $this->note('S: (no answer – ' . (stream_get_meta_data($this->stream)['timed_out'] ? 'timeout' : 'connection closed') . ')');
            throw new RuntimeException('Spojení se schránkou bylo přerušeno nebo vypršel čas.');
        }
        $line = rtrim($line, "\r\n");
        $this->note('S: ' . $line);
        return $line;
    }

    private function read(int $bytes): string
    {
        $data = '';
        while (strlen($data) < $bytes) {
            $chunk = fread($this->stream, max(1, min(65536, $bytes - strlen($data))));
            if ($chunk === false || ($chunk === '' && feof($this->stream))) {
                throw new RuntimeException('Spojení se schránkou bylo přerušeno.');
            }
            if ($chunk === '' && (stream_get_meta_data($this->stream)['timed_out'])) {
                throw new RuntimeException('Vypršel čas při čtení e-mailu ze schránky.');
            }
            $data .= $chunk;
        }
        return $data;
    }

    private static function quote(#[\SensitiveParameter] string $value): string
    {
        if (preg_match('/[\r\n\0]/', $value)) {
            throw new InvalidArgumentException('Neplatný znak v přihlašovacích údajích schránky.');
        }
        return '"' . addcslashes($value, '"\\') . '"';
    }
}
