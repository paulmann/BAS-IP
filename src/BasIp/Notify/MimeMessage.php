<?php

declare(strict_types=1);

namespace BasIp\Notify;

/**
 * RFC 822 / MIME message builder (plain text + optional HTML alternative).
 */
final class MimeMessage
{
    private string $subject = '';
    private string $text = '';
    private ?string $html = null;
    private string $from = '';
    private ?string $replyTo = null;
    /** @var array<int, string> */
    private array $to = [];
    /** @var array<int, string> */
    private array $cc = [];
    /** @var array<int, string> */
    private array $bcc = [];
    /** @var array<string, string> */
    private array $headers = [];

    public static function make(): self
    {
        return new self();
    }

    public function subject(string $subject): self { $this->subject = $subject; return $this; }
    public function text(string $text): self       { $this->text = $text; return $this; }
    public function html(?string $html): self      { $this->html = $html; return $this; }
    public function from(string $from): self       { $this->from = $from; return $this; }
    public function replyTo(?string $to): self     { $this->replyTo = $to; return $this; }
    /** @param array<int, string> $to */
    public function to(array $to): self            { $this->to = array_values($to); return $this; }
    /** @param array<int, string> $cc */
    public function cc(array $cc): self            { $this->cc = array_values($cc); return $this; }
    /** @param array<int, string> $bcc */
    public function bcc(array $bcc): self          { $this->bcc = array_values($bcc); return $this; }
    public function header(string $name, string $value): self { $this->headers[$name] = $value; return $this; }

    /** @return array<int, string> */
    public function recipients(): array
    {
        return array_values(array_unique(array_merge($this->to, $this->cc, $this->bcc)));
    }

    public function raw(): string
    {
        $boundary = 'basip-' . bin2hex(random_bytes(12));
        $headers  = [];

        $headers['Date']         = date('r');
        $headers['From']         = $this->encodeAddress($this->from);
        if ($this->replyTo !== null && $this->replyTo !== '') {
            $headers['Reply-To'] = $this->encodeAddress($this->replyTo);
        }
        if ($this->to !== []) {
            $headers['To'] = implode(', ', array_map([$this, 'encodeAddress'], $this->to));
        }
        if ($this->cc !== []) {
            $headers['Cc'] = implode(', ', array_map([$this, 'encodeAddress'], $this->cc));
        }
        $headers['Subject']      = $this->encodeHeader($this->subject);
        $headers['Message-ID']   = '<' . bin2hex(random_bytes(16)) . '@' . $this->domain() . '>';
        $headers['MIME-Version'] = '1.0';
        $headers['X-Mailer']     = 'BAS-IP Gate Manager';
        $headers['Auto-Submitted'] = 'auto-generated';

        foreach ($this->headers as $name => $value) {
            $headers[$name] = $value;
        }

        if ($this->html === null) {
            $headers['Content-Type'] = 'text/plain; charset=UTF-8; format=flowed';
            $headers['Content-Transfer-Encoding'] = 'base64';
            $body = chunk_split(base64_encode($this->normalize($this->text)), 76, "\r\n");
        } else {
            $headers['Content-Type'] = 'multipart/alternative; boundary="' . $boundary . '"';
            $body = "--$boundary\r\n"
                . "Content-Type: text/plain; charset=UTF-8\r\n"
                . "Content-Transfer-Encoding: base64\r\n\r\n"
                . chunk_split(base64_encode($this->normalize($this->text)), 76, "\r\n")
                . "--$boundary\r\n"
                . "Content-Type: text/html; charset=UTF-8\r\n"
                . "Content-Transfer-Encoding: base64\r\n\r\n"
                . chunk_split(base64_encode($this->normalize($this->html)), 76, "\r\n")
                . "--$boundary--\r\n";
        }

        $headerLines = [];
        foreach ($headers as $name => $value) {
            $headerLines[] = $this->fold($name . ': ' . $value);
        }

        return implode("\r\n", $headerLines) . "\r\n\r\n" . rtrim($body, "\r\n");
    }

    private function normalize(string $text): string
    {
        $text = str_replace(["\r\n", "\r"], "\n", $text);

        return str_replace("\n", "\r\n", $text);
    }

    private function fold(string $line): string
    {
        if (mb_strlen($line) <= 78) {
            return $line;
        }

        return wordwrap($line, 76, "\r\n ");
    }

    /** RFC 2047 encode when the value is not pure ASCII. */
    public function encodeHeader(string $value): string
    {
        if (preg_match('//u', $value) && !mb_check_encoding($value, 'ASCII')) {
            return '=?UTF-8?B?' . base64_encode($value) . '?=';
        }

        return $value;
    }

    private function encodeAddress(string $address): string
    {
        if (preg_match('/^(.*?)<([^>]+)>$/', trim($address), $m)) {
            $name = trim($m[1], " \t\"'");

            return $name === '' ? '<' . $m[2] . '>' : $this->encodeHeader($name) . ' <' . $m[2] . '>';
        }

        return $address;
    }

    private function domain(): string
    {
        if (preg_match('/@([a-z0-9.\-]+)/i', $this->from, $m)) {
            return $m[1];
        }

        return (string) (gethostname() ?: 'localhost');
    }
}
