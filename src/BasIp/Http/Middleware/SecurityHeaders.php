<?php

declare(strict_types=1);

namespace BasIp\Http\Middleware;

use BasIp\Core\Request;
use BasIp\Core\Response;
use Closure;

/**
 * Security headers + a per-request CSP nonce.
 *
 * The CSP is built from local origins only — no CDN, no inline scripts without
 * a nonce, no `unsafe-eval`. Everything the UI needs ships from `/assets`.
 */
final class SecurityHeaders extends AbstractMiddleware
{
    public function handle(Request $request, Closure $next): Response
    {
        $config = $this->config();
        $nonce  = base64_encode(random_bytes(16));
        $this->container->set('cspNonce', $nonce);

        $response = $next($request);

        if (!$config->bool('security.headers.csp', true)) {
            return $response;
        }

        $assetOrigin = $request->scheme . '://' . ($request->header('host') ?? 'localhost');
        $directives = [
            'default-src'   => ["'self'"],
            'script-src'    => ["'self'", "'nonce-" . $nonce . "'"],
            'style-src'     => ["'self'", "'unsafe-inline'"],   // theme bootstrap writes inline CSS vars
            'img-src'       => ["'self'", 'data:', 'blob:'],
            'font-src'      => ["'self'", 'data:'],
            'connect-src'  => ["'self'"],
            'media-src'     => ["'self'", 'blob:'],
            'object-src'    => ["'none'"],
            'frame-src'     => ["'self'"],
            'base-uri'      => ["'self'"],
            'form-action'   => ["'self'"],
            'frame-ancestors' => [$config->string('security.headers.frame_ancestors', "'none'")],
            'manifest-src'  => ["'self'"],
            'worker-src'    => ["'self'", 'blob:'],
        ];

        // The camera proxy may point at the panel itself.
        $deviceHost = (string) parse_url($config->string('device.base_url', ''), PHP_URL_HOST);
        if ($deviceHost !== '' && $config->string('device.camera.mode') === 'mjpeg') {
            $directives['img-src'][] = $request->scheme . '://' . $deviceHost . ':*';
            $directives['media-src'][] = $request->scheme . '://' . $deviceHost . ':*';
        }
        foreach ((array) $config->get('security.headers.csp_extra', []) as $name => $extra) {
            $name = (string) $name;
            $directives[$name] = array_unique(array_merge($directives[$name] ?? [], (array) $extra));
        }

        $parts = [];
        foreach ($directives as $name => $values) {
            $values = array_values(array_filter((array) $values, static fn ($v) => $v !== '' && $v !== null));
            if ($values === []) {
                continue;
            }
            $parts[] = $name . ' ' . implode(' ', $values);
        }
        unset($assetOrigin);
        $parts[] = 'upgrade-insecure-requests';
        if (!$request->isSecure()) {
            $parts = array_values(array_filter($parts, static fn ($p) => $p !== 'upgrade-insecure-requests'));
        }

        $csp = implode('; ', $parts);
        $header = $config->bool('security.headers.csp_report_only') ? 'Content-Security-Policy-Report-Only' : 'Content-Security-Policy';
        $response->header($header, $csp);

        $response->header('X-Content-Type-Options', 'nosniff');
        $response->header('Referrer-Policy', $config->string('security.headers.referrer_policy', 'strict-origin-when-cross-origin'));
        $response->header('Permissions-Policy', $config->string('security.headers.permissions_policy', 'camera=(), microphone=(), geolocation=()'));
        $response->header('Cross-Origin-Opener-Policy', 'same-origin');
        $response->header('Cross-Origin-Resource-Policy', 'same-origin');

        if ($request->isSecure() && $config->bool('app.strict_transport_security', true)) {
            $maxAge = $config->int('security.headers.hsts_max_age', 31536000);
            if ($maxAge > 0) {
                $response->header('Strict-Transport-Security', 'max-age=' . $maxAge . '; includeSubDomains');
            }
        }
        foreach ((array) $config->get('security.headers.custom', []) as $name => $value) {
            $response->header((string) $name, (string) $value);
        }

        return $response;
    }
}
