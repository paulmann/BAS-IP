<?php

declare(strict_types=1);

namespace BasIp\Core;

use BasIp\Support\Fs;

/**
 * HTTP response builder (fluent, immutable-ish).
 */
final class Response
{
    /** @var array<string, string> */
    private array $headers = [];

    /** @var array<int, array{name: string, value: string, options: array<string, mixed>}> */
    private array $cookies = [];

    private string $body = '';
    private int $status = 200;
    private ?string $file = null;
    private ?string $fileDisposition = null;
    private ?string $fileMime = null;

    public function __construct(string $body = '', int $status = 200, array $headers = [])
    {
        $this->body   = $body;
        $this->status = $status;
        foreach ($headers as $name => $value) {
            $this->headers[(string) $name] = (string) $value;
        }
    }

    public static function html(string $body, int $status = 200): self
    {
        return new self($body, $status, ['Content-Type' => 'text/html; charset=UTF-8']);
    }

    public static function json(mixed $data, int $status = 200, int $flags = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES): self
    {
        $encoded = json_encode($data, $flags | JSON_INVALID_UTF8_SUBSTITUTE);

        return new self($encoded === false ? '{"error":"invalid utf-8"}' : $encoded, $status, [
            'Content-Type'  => 'application/json; charset=UTF-8',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    public static function text(string $body, int $status = 200): self
    {
        return new self($body, $status, ['Content-Type' => 'text/plain; charset=UTF-8']);
    }

    public static function csv(string $body, string $filename = 'export.csv'): self
    {
        return (new self($body, 200, ['Content-Type' => 'text/csv; charset=UTF-8']))
            ->download($filename);
    }

    public static function redirect(string $to, int $status = 302): self
    {
        return new self('', $status, ['Location' => $to, 'Cache-Control' => 'no-store']);
    }

    public static function back(Request $request, string $fallback = '/'): self
    {
        $referer = $request->referer();
        $target  = $referer !== '' ? $referer : $request->url($fallback);

        return self::redirect($target);
    }

    public static function noContent(int $status = 204): self
    {
        return new self('', $status);
    }

    public static function file(string $path, ?string $name = null, bool $inline = false, ?string $mime = null): self
    {
        $response = new self('', 200);
        $response->file = $path;
        $response->fileMime = $mime;

        $filename = $name ?? basename($path);
        $type     = $inline ? 'inline' : 'attachment';
        // RFC 6266 — ASCII fallback plus the UTF-8 extended form for Cyrillic names.
        $ascii = preg_replace('/[^\x20-\x7E]/', '_', $filename) ?? 'download';
        $ascii = str_replace(['"', '\\'], ['_', '_'], $ascii);
        $response->fileDisposition = sprintf(
            "%s; filename=\"%s\"; filename*=UTF-8''%s",
            $type,
            $ascii,
            rawurlencode($filename)
        );

        return $response;
    }

    /** Current HTTP status code — getter, since {@see status()} is the fluent setter. */
    public function statusCode(): int
    {
        return $this->status;
    }

    public function status(int $status): self
    {
        $this->status = $status;

        return $this;
    }

    public function getStatus(): int
    {
        return $this->status;
    }

    public function header(string $name, string $value, bool $replace = true): self
    {
        if ($replace || !isset($this->headers[$name])) {
            $this->headers[$name] = $value;
        } else {
            $this->headers[$name] .= ', ' . $value;
        }

        return $this;
    }

    /** @param array<string, string> $headers */
    public function withHeaders(array $headers): self
    {
        foreach ($headers as $name => $value) {
            $this->header((string) $name, (string) $value);
        }

        return $this;
    }

    /** @return array<string, string> */
    public function headers(): array
    {
        return $this->headers;
    }

    public function body(string $body): self
    {
        $this->body = $body;

        return $this;
    }

    public function getBody(): string
    {
        return $this->body;
    }

    public function cookie(string $name, string $value, int $expires = 0, array $options = []): self
    {
        $this->cookies[] = ['name' => $name, 'value' => $value, 'options' => $options + [
            'expires'  => $expires,
            'path'     => '/',
            'secure'   => false,
            'httponly' => true,
            'samesite' => 'Lax',
        ]];

        return $this;
    }

    public function cache(int $seconds, bool $public = false): self
    {
        return $this
            ->header('Cache-Control', ($public ? 'public' : 'private') . ', max-age=' . $seconds)
            ->header('Expires', gmdate('D, d M Y H:i:s', time() + $seconds) . ' GMT');
    }

    public function noCache(): self
    {
        return $this
            ->header('Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0')
            ->header('Pragma', 'no-cache')
            ->header('Expires', '0');
    }

    public function etag(string $value, Request $request): self
    {
        $etag = '"' . $value . '"';
        $this->header('ETag', $etag);
        if ($request->header('if-none-match') === $etag) {
            $this->status = 304;
            $this->body   = '';
        }

        return $this;
    }

    public function withCspNonce(string $nonce): self
    {
        return $this->header('X-Csp-Nonce', $nonce, false);
    }

    /** Emit the response and terminate. */
    public function send(): never
    {
        $this->emit();
        exit;
    }

    public function emit(): void
    {
        if (!headers_sent()) {
            http_response_code($this->status);
            foreach ($this->headers as $name => $value) {
                header($name . ': ' . $value, true);
            }
            foreach ($this->cookies as $cookie) {
                setcookie($cookie['name'], $cookie['value'], $cookie['options']);
            }
        }

        if ($this->file !== null) {
            $this->streamFile();

            return;
        }

        if ($this->status !== 304) {
            echo $this->body;
        }
        if (function_exists('fastcgi_finish_request')) {
            @fastcgi_finish_request();
        }
    }

    private function streamFile(): void
    {
        $path = (string) $this->file;
        if (!is_file($path) || !is_readable($path)) {
            http_response_code(404);
            echo 'File not found';

            return;
        }
        $mime = $this->fileMime ?? (self::guessMime($path) ?: 'application/octet-stream');
        if (!headers_sent()) {
            header('Content-Type: ' . $mime);
            header('Content-Length: ' . (string) Fs::size($path));
            if ($this->fileDisposition !== null) {
                header('Content-Disposition: ' . $this->fileDisposition);
            }
            header('X-Content-Type-Options: nosniff');
        }
        readfile($path);
    }

    public static function guessMime(string $path): ?string
    {
        $map = [
            'css' => 'text/css', 'js' => 'text/javascript', 'mjs' => 'text/javascript',
            'json' => 'application/json', 'svg' => 'image/svg+xml', 'html' => 'text/html',
            'txt' => 'text/plain', 'csv' => 'text/csv', 'xml' => 'application/xml',
            'png' => 'image/png', 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg',
            'gif' => 'image/gif', 'webp' => 'image/webp', 'ico' => 'image/x-icon',
            'woff' => 'font/woff', 'woff2' => 'font/woff2', 'ttf' => 'font/ttf',
            'pdf' => 'application/pdf', 'zip' => 'application/zip',
            'sql' => 'text/plain', 'log' => 'text/plain', 'map' => 'application/json',
        ];
        $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));

        return $map[$ext] ?? null;
    }
}
