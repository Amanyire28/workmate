<?php

namespace App\Http\Controllers\Api\V1\Auth;

use App\Exceptions\BusinessRuleException;
use App\Http\Controllers\Controller;
use App\Http\Requests\RegisterRequest;
use App\Http\Resources\UserResource;
use App\Models\User;
use App\Services\Accounts;
use App\Services\AuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;

/**
 * Cookie-based sign-in for the WorkMate website (Laravel Sanctum SPA mode).
 * The browser first calls GET /sanctum/csrf-cookie, then these endpoints.
 * Every sign-in, failed or not, goes into the audit log as a security event.
 */
class SessionController extends Controller
{
    public function register(RegisterRequest $request, Accounts $accounts): JsonResponse
    {
        $this->requireSession($request);

        $user = $accounts->register($request->validated());

        Auth::guard('web')->login($user);
        $request->session()->regenerate();

        return (new UserResource($user->load('roles', 'providerProfile')))
            ->response()
            ->setStatusCode(201);
    }

    public function login(Request $request, AuditLogger $audit): UserResource
    {
        $this->requireSession($request);

        $credentials = $request->validate([
            'email' => ['required', 'string', 'email'],
            'password' => ['required', 'string'],
            'remember' => ['sometimes', 'boolean'],
        ]);

        $attempt = ['email' => strtolower(trim($credentials['email'])), 'password' => $credentials['password']];

        if (! Auth::guard('web')->attempt($attempt, (bool) ($credentials['remember'] ?? false))) {
            // Linked to the account when one exists; the address itself is stored masked
            $audit->record('auth.sign_in_failed', User::where('email', $attempt['email'])->first(), [], ['email' => $this->maskEmail($attempt['email'])]);

            throw ValidationException::withMessages(['email' => 'The email or password is incorrect.']);
        }

        // Checked only after the password, so status isn't revealed to guessers
        $user = Auth::guard('web')->user();

        if (! $user->isActive()) {
            $audit->record('auth.sign_in_blocked', $user, [], ['status' => $user->status], null, $user);
            Auth::guard('web')->logout();
            $request->session()->invalidate();

            throw new BusinessRuleException(
                'account_inactive',
                'This account is not active. Contact WorkMate support if you think this is a mistake.',
                403
            );
        }

        $request->session()->regenerate();
        $audit->record('auth.signed_in', $user, [], [], null, $user);

        return new UserResource($user->load('roles', 'providerProfile'));
    }

    /** "aisha@gmail.com" → "a•••@gmail.com" */
    private function maskEmail(string $email): string
    {
        [$local, $domain] = array_pad(explode('@', $email, 2), 2, '');

        return mb_substr($local, 0, 1).'•••@'.mb_substr($domain, 0, 60);
    }

    public function logout(Request $request): JsonResponse
    {
        Auth::guard('web')->logout();

        if ($request->hasSession()) {
            $request->session()->invalidate();
            $request->session()->regenerateToken();
        }

        return new JsonResponse(null, 204);
    }

    /**
     * Sessions exist only for requests from the WorkMate website's own
     * domains (SANCTUM_STATEFUL_DOMAINS). A future mobile app will use tokens.
     */
    private function requireSession(Request $request): void
    {
        if (! $request->hasSession()) {
            throw new BusinessRuleException('session_required', 'Sign in from the WorkMate website.', 400);
        }
    }
}
