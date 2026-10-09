<?php

declare(strict_types=1);

namespace BasIp\Core;

/**
 * CSRF protection (synchroniser-token pattern).
 *
 * Token lives in the session, is rendered into every form via `{csrf}` and can
 * also be sent as the `X-CSRF-Token` header by the JS layer.
 */
final class Csrf
{
    public const FIELD  = '_token';
    public const HEADER = 'X-CSRF-Token';

    public function __construct(private readonly Session $session, private readonly Config $config)
    {
    }

    public function token(): string
    {
        if (!$this->config->bool('security.csrf.enabled', true)) {
            return '';
        }
        $token = $this->session->get('_csrf');
        if (!is_string($token) || $token === '') {
            $token = bin2hex(random_bytes(32));
            $this->session->set('_csrf', $token);
            $this->session->set('_csrf_at', time());
        }

        return $token;
    }

    public function rotate(): string
    {
        $this->session->forget('_csrf');
        $this->session->forget('_csrf_at');

        return $this->token();
    }

    public function field(): string
    {
        return sprintf('<input type="hidden" name="%s" value="%s">', self::FIELD, htmlspecialchars($this->token(), ENT_QUOTES, 'UTF-8'));
    }

    public function meta(): string
    {
        return sprintf('<meta name="csrf-token" content="%s">', htmlspecialchars($this->token(), ENT_QUOTES, 'UTF-8'));
    }

    public function check(?string $token): bool
    {
        if (!$this->config->bool('security.csrf.enabled', true)) {
            return true;
        }
        if (!is_string($token) || $token === '') {
            return false;
        }
        $expected = (string) $this->session->get('_csrf', '');
        if ($expected === '' || !hash_equals($expected, $token)) {
            return false;
        }
        $ttl = $this->config->int('security.csrf.token_ttl', 7200);
        $at  = (int) $this->session->get('_csrf_at', time());
        if ($ttl > 0 && time() - $at > $ttl) {
            return false;
        }

        return true;
    }

    public function validate(Request $request): bool
    {
        if (!$this->config->bool('security.csrf.enabled', true)) {
            return true;
        }
        // Safe methods never need a token.
        if (in_array($request->method, ['GET', 'HEAD', 'OPTIONS'], true)) {
            return true;
        }
        $token = $request->str(self::FIELD);
        if ($token === '') {
            $token = (string) ($request->header(self::HEADER) ?? '');
        }
        if ($token === '') {
            $token = (string) ($request->header('X-XSRF-TOKEN') ?? '');
        }

        return $this->check($token);
    }
}
