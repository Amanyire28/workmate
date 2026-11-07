<?php

namespace App\Http\Controllers\Api\V1\Auth;

use App\Exceptions\BusinessRuleException;
use App\Http\Controllers\Controller;
use App\Services\Accounts;
use App\Services\DeviceSessions;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Password;
use Illuminate\Validation\Rules\Password as PasswordRule;
use Illuminate\Validation\ValidationException;
use Throwable;

class PasswordController extends Controller
{
    /**
     * Always answers the same way, so the form can't be used to find out
     * which email addresses have accounts.
     */
    public function forgot(Request $request): JsonResponse
    {
        $data = $request->validate(['email' => ['required', 'string', 'email']]);

        try {
            Password::sendResetLink(['email' => strtolower(trim($data['email']))]);
        } catch (Throwable $e) {
            report($e);

            throw new BusinessRuleException('email_unavailable', 'We could not send emails just now. Please try again later.', 503);
        }

        return response()->json(['data' => [
            'message' => 'If an account uses that email address, we have sent it a link to reset the password.',
        ]]);
    }

    public function reset(Request $request, Accounts $accounts): JsonResponse
    {
        $data = $request->validate([
            'token' => ['required', 'string'],
            'email' => ['required', 'string', 'email'],
            'password' => ['required', 'confirmed', PasswordRule::min(8)->letters()->numbers()],
        ]);

        $status = Password::reset(
            ['email' => strtolower(trim($data['email'])), 'token' => $data['token'], 'password' => $data['password']],
            function ($user, $password) use ($accounts) {
                $accounts->setPassword($user, $password, 'account.password_reset');
                event(new PasswordReset($user));
            }
        );

        if ($status !== Password::PASSWORD_RESET) {
            throw ValidationException::withMessages([
                'email' => 'This reset link is invalid or has expired. Ask for a new one.',
            ]);
        }

        return response()->json(['data' => ['message' => 'Your password has been changed. You can now sign in.']]);
    }

    public function update(Request $request, Accounts $accounts, DeviceSessions $devices): JsonResponse
    {
        $data = $request->validate([
            'current_password' => ['required', 'string', 'current_password'],
            'password' => ['required', 'confirmed', 'different:current_password', PasswordRule::min(8)->letters()->numbers()],
        ], [
            'current_password.current_password' => 'Your current password is incorrect.',
            'password.different' => 'Choose a password you are not already using.',
        ]);

        $user = $request->user();
        $accounts->setPassword($user, $data['password'], 'account.password_changed', $request->hasSession() ? $request->session()->getId() : null);

        if ($request->hasSession()) {
            // This device stays signed in, with a new "Keep me signed in" cookie if it had one
            $devices->forgetOtherRememberCookies($user, $request);
            $request->session()->regenerate();
        }

        return response()->json(['data' => ['message' => 'Your password has been changed.']]);
    }
}
