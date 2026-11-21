<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

/**
 * Browser security headers on every page and API response (config/security.php).
 *
 * The Content Security Policy starts in report-only mode: browsers report
 * anything that would have been blocked (to /api/v1/csp-reports, logged)
 * without blocking it, so it can be checked against the live site before
 * it's enforced. It lists exactly what WorkMate loads: its own scripts, the
 * Inter font (Google Fonts), Bootstrap Icons (jsDelivr), and videos from the
 * Cloudflare R2 bucket.
 */
class SecurityHeaders
{
    public function handle(Request $request, Closure $next)
    {
        $response = $next($request);
        $headers = $response->headers;

        $headers->set('X-Content-Type-Options', 'nosniff', false);
        $headers->set('Referrer-Policy', 'strict-origin-when-cross-origin', false);
        // Pages can only be framed by WorkMate itself (clickjacking)
        $headers->set('X-Frame-Options', 'SAMEORIGIN', false);
        // Location is used (nearby providers); camera and microphone are used only
        // through the file picker, which needs no permission; nothing else
        $headers->set('Permissions-Policy', 'geolocation=(self), camera=(), microphone=(), payment=(), usb=()', false);

        $public = (string) config('security.public_url');
        $hsts = (int) config('security.headers.hsts_seconds');
        if ($hsts > 0 && strpos($public, 'https://') === 0) {
            $headers->set('Strict-Transport-Security', 'max-age='.$hsts, false);
        }

        $mode = config('security.headers.csp');
        if ($mode === 'enforce' || $mode === 'report-only') {
            $name = $mode === 'enforce' ? 'Content-Security-Policy' : 'Content-Security-Policy-Report-Only';
            $headers->set($name, $this->policy(), false);
        }

        return $response;
    }

    public function policy(): string
    {
        $r2 = $this->r2Origin();
        $dev = app()->environment('local') ? $this->devServer() : '';

        $directives = [
            "default-src 'self'",
            "script-src 'self'".$dev,
            // Vue sets style="" attributes (sizes, progress bars); fonts' stylesheets
            "style-src 'self' 'unsafe-inline' https://fonts.googleapis.com https://cdn.jsdelivr.net".$dev,
            "font-src 'self' data: https://fonts.gstatic.com https://cdn.jsdelivr.net",
            // Photo and video previews made on the phone are blob: and data: images
            "img-src 'self' data: blob:",
            "media-src 'self' blob:".$r2,
            // Direct video uploads go to R2; the trimming tool reads local files only
            "connect-src 'self'".$r2.$dev.($dev ? ' ws:'.substr($dev, 6) : ''),
            "worker-src 'self' blob:",
            "manifest-src 'self'",
            "frame-ancestors 'self'",
            "base-uri 'self'",
            "form-action 'self'",
            "object-src 'none'",
            'report-uri /api/v1/csp-reports',
        ];

        return implode('; ', $directives);
    }

    /** The R2 endpoint origin videos play from and upload to (' https://…' or '') */
    private function r2Origin(): string
    {
        $endpoint = config('videos.r2.endpoint') ?: (config('videos.r2.account_id') ? 'https://'.config('videos.r2.account_id').'.r2.cloudflarestorage.com' : null);
        if (! $endpoint || ! ($host = parse_url($endpoint, PHP_URL_HOST))) {
            return '';
        }
        $scheme = parse_url($endpoint, PHP_URL_SCHEME) ?: 'https';
        $port = parse_url($endpoint, PHP_URL_PORT);

        return ' '.$scheme.'://'.$host.($port ? ':'.$port : '');
    }

    /** The Vite dev server while developing (public/hot), e.g. ' http://127.0.0.1:5173' */
    private function devServer(): string
    {
        $hot = public_path('hot');
        if (! is_file($hot)) {
            return '';
        }
        $url = trim((string) @file_get_contents($hot));

        return preg_match('#^https?://[^/\s]+$#', $url) ? ' '.$url : '';
    }
}
