<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * Ends the session of a suspended or deactivated account on its next request,
 * so a suspension takes effect immediately rather than at the next sign-in.
 */
class EnsureAccountIsActive
{
    public function handle(Request $request, Closure $next)
    {
        $user = $request->user();

        if ($user && ! $user->isActive()) {
            if ($request->hasSession()) {
                Auth::guard('web')->logout();
                $request->session()->invalidate();
                $request->session()->regenerateToken();
            }

            return new JsonResponse([
                'message' => 'This account is not active. Contact WorkMate support if you think this is a mistake.',
                'code' => 'account_inactive',
            ], 403);
        }

        return $next($request);
    }
}
