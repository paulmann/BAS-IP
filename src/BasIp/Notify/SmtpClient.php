<?php

declare(strict_types=1);

namespace BasIp\Notify;

use RuntimeException;

/**
 * Minimal SMTP client (RFC 5321) built on stream sockets.
 *
 * Supports PLAIN/SSL (port 465) and STARTTLS (port 587), LOGIN and PLAIN auth,
 * pipelining-free command/response dialogue and a 7-bit-safe 8BITMIME body.
 * No Composer packages required — this is the whole reason it exists.
 */
final class SmtpClient
{
    /** @var resource|null */
    private $socket = null;

    /** @var array<int, string> */
    private array $transcript = [];

    private bool $tlsActive = false;

    public function __construct(
        private readonly string $host,
        private readonly int $port = 587,
        private readonly string $encryption = 'tls',   // tls | ssl | none
        private readonly ?string $username = null,
        private readonly ?string $password = null,
        private readonly int $timeout = 20,
        private readonly ?string $helo = null,
        private readonly ?string $caFile = null,
        private readonly bool $verifyPeer = true,
    ) {
    }

    /** @return array<int, string> */
    public function transcript(): array
    {
        return $this->transcript;
    }

    public function connect(): void
    {
        $prefix = $this->encryption === 'ssl' ? 'ssl://' : '';
        $errno = 0;
        $errstr = '';
        $context = stream_context_create([
            'ssl' => [
                'verify_peer'      => $this->verifyPeer,
                'verify_peer_name' => $this->verifyPeer,
                'allow_self_signed' => !$this->verifyPeer,
                'SNI_enabled'      => true,
                'peer_name'        => $this->host,
            ] + ($this->caFile !== null ? ['cafile' => $this->caFile] : []),
        ]);

        $socket = @stream_socket_client(
            $prefix . $this->host . ':' . $this->port,
            $errno,
            $errstr,
            $this->timeout,
            STREAM_CLIENT_CONNECT,
            $context
        );

        if ($socket === false) {
            throw new RuntimeException(sprintf('SMTP: не удалось подключиться к %s:%d (%s)', $this->host, $this->port, $errstr ?: 'errno ' . $errno));
        }

        $this->socket = $socket;
        stream_set_timeout($this->socket, $this->timeout);

        $this->expect(220);
        $this->command('EHLO ' . ($this->helo ?? $this->localHostname()), [250]);

        if ($this->encryption === 'tls') {
            $this->startTls();
        }

        if ($this->username !== null && $this->username !== '') {
            $this->authenticate();
        }
    }

    /**
     * @param array<int, string> $to
     */
    public function send(string $from, array $to, string $rawMessage): void
    {
        if ($this->socket === null) {
            $this->connect();
        }
        $this->command('MAIL FROM:<' . $this->addr($from) . '>', [250]);
        if ($to === []) {
            throw new RuntimeException('SMTP: пустой список получателей.');
        }
        foreach ($to as $recipient) {
            $this->command('RCPT TO:<' . $this->addr($recipient) . '>', [250, 251]);
        }
        $this->command('DATA', [354]);
        $this->write(preg_replace('/^\./m', '..', $rawMessage) . "\r\n.");
        $this->expect(250);
    }

    public function quit(): void
    {
        if ($this->socket === null) {
            return;
        }
        try {
            $this->command('QUIT', [221, 500, 503]);
        } catch (RuntimeException) {
            // ignore — we are closing anyway
        }
        @fclose($this->socket);
        $this->socket = null;
    }

    public function __destruct()
    {
        $this->quit();
    }

    private function startTls(): void
    {
        $this->command('STARTTLS', [220]);
        $options = [
            'verify_peer'      => $this->verifyPeer,
            'verify_peer_name' => $this->verifyPeer,
            'allow_self_signed' => !$this->verifyPeer,
            'SNI_enabled'      => true,
            'peer_name'        => $this->host,
        ];
        if ($this->caFile !== null) {
            $options['cafile'] = $this->caFile;
        }
        stream_context_set_option($this->socket, ['ssl' => $options]);

        $ok = @stream_socket_enable_crypto($this->socket, true, STREAM_CRYPTO_METHOD_TLS_CLIENT);
        if ($ok !== true) {
            throw new RuntimeException('SMTP: не удалось включить STARTTLS.');
        }
        $this->tlsActive = true;
        $this->command('EHLO ' . ($this->helo ?? $this->localHostname()), [250]);
    }

    private function authenticate(): void
    {
        $username = (string) $this->username;
        $password = (string) ($this->password ?? '');

        try {
            // AUTH LOGIN
            $this->command('AUTH LOGIN', [334]);
            $this->command(base64_encode($username), [334]);
            $this->command(base64_encode($password), [235]);

            return;
        } catch (RuntimeException) {
            // fall through to PLAIN
        }

        $this->command('AUTH PLAIN ' . base64_encode("\0" . $username . "\0" . $password), [235]);
    }

    /** @param array<int, int> $expected */
    private function command(string $line, array $expected): string
    {
        $this->write($line);
        $sensitive = str_starts_with($line, 'AUTH') || preg_match('/^[A-Za-z0-9+\/=]{8,}$/', $line) === 1;
        $this->transcript[] = ($sensitive ? 'C: ***' : 'C: ' . $line);

        return $this->expect(...$expected);
    }

    private function expect(int ...$codes): string
    {
        $response = '';
        while (($line = $this->readLine()) !== null) {
            $response .= $line;
            $this->transcript[] = 'S: ' . rtrim($line);
            // Multi-line responses use "250-…", the last line uses "250 …".
            if (isset($line[3]) && $line[3] === ' ') {
                break;
            }
            if (strlen($line) < 4) {
                break;
            }
        }

        $code = (int) substr($response, 0, 3);
        if ($codes !== [] && !in_array($code, $codes, true)) {
            throw new RuntimeException('SMTP: неожиданный ответ ' . trim($response));
        }

        return $response;
    }

    private function write(string $data): void
    {
        if ($this->socket === null) {
            throw new RuntimeException('SMTP: соединение не установлено.');
        }
        $written = @fwrite($this->socket, $data . "\r\n");
        if ($written === false) {
            throw new RuntimeException('SMTP: ошибка записи в сокет.');
        }
    }

    private function readLine(): ?string
    {
        if ($this->socket === null) {
            return null;
        }
        $line = @fgets($this->socket, 1024);
        if ($line === false) {
            $meta = stream_get_meta_data($this->socket);
            if (!empty($meta['timed_out'])) {
                throw new RuntimeException('SMTP: таймаут ожидания ответа сервера.');
            }

            return null;
        }

        return $line;
    }

    private function addr(string $address): string
    {
        // "Name <a@b.c>" → a@b.c
        if (preg_match('/<([^>]+)>/', $address, $m)) {
            return trim($m[1]);
        }

        return trim($address);
    }

    private function localHostname(): string
    {
        $host = gethostname();

        return is_string($host) && $host !== '' ? $host : 'localhost';
    }

    public function isTlsActive(): bool
    {
        return $this->tlsActive || $this->encryption === 'ssl';
    }
}
