<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

/**
 * Someone who signs in with "Keep me signed in on this device" unticked gets
 * the wm_shared_device cookie. Their session then ends after two hours idle
 * or when the browser closes, instead of lasting 90 days.
 *
 * Runs before the session starts, so the cookie is left unencrypted (it only
 * ever holds "1"; removing it just gives the normal length, which the server
 * still limits).
 */
class ShortSessionOnSharedDevice
{
    public const COOKIE = 'wm_shared_device';

    public function handle(Request $request, Closure $next)
    {
        if ($request->cookies->get(self::COOKIE) === '1') {
            config([
                'session.lifetime' => config('session.shared_device_lifetime'),
                'session.expire_on_close' => true,
            ]);
        }

        return $next($request);
    }
}
