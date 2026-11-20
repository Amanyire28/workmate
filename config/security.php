<?php

/*
|--------------------------------------------------------------------------
| Security settings
|--------------------------------------------------------------------------
|
| Everything here is off or permissive until APP_PUBLIC_URL is set, so a
| deploy changes nothing by itself. On the live server set:
|
|   APP_PUBLIC_URL=https://wm.resnetsystems.com
|
| Then: email links (password reset, email confirmation) are always built
| from it, never from the request's Host header; requests for any other host
| are refused (TrustHosts); cross-site API calls are only allowed from it
| (CORS); and HSTS is sent.
|
*/

$publicUrl = rtrim((string) env('APP_PUBLIC_URL', ''), '/');
$publicOrigin = $publicUrl !== '' ? (parse_url($publicUrl, PHP_URL_SCHEME).'://'.parse_url($publicUrl, PHP_URL_HOST).(parse_url($publicUrl, PHP_URL_PORT) ? ':'.parse_url($publicUrl, PHP_URL_PORT) : '')) : '';

return [
    // The site's own address, e.g. https://wm.resnetsystems.com ('' = not set yet)
    'public_url' => $publicUrl,

    // Other host names this app answers to (comma separated), e.g. a www. alias
    'extra_hosts' => array_values(array_filter(array_map('trim', explode(',', (string) env('TRUSTED_HOSTS', ''))))),

    // Origins allowed to call the API from another site; the site itself needs none (same origin)
    'cors_origins' => array_values(array_filter(array_merge(
        [$publicOrigin],
        array_map('trim', explode(',', (string) env('CORS_ALLOWED_ORIGINS', '')))
    ))),

    'headers' => [
        // Strict-Transport-Security max-age in seconds (180 days); 0 turns it off.
        // Only sent once APP_PUBLIC_URL is an https:// address.
        'hsts_seconds' => (int) env('HSTS_SECONDS', 15552000),

        // Content Security Policy: "report-only" (default: violations are reported,
        // nothing is blocked), "enforce", or "off"
        'csp' => env('CSP_MODE', 'report-only'),
    ],
];
