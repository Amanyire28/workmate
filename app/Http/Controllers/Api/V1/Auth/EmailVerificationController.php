<?php

namespace App\Http\Controllers\Api\V1\Auth;

use App\Exceptions\BusinessRuleException;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Auth\Events\Verified;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Throwable;

class EmailVerificationController extends Controller
{
    public function send(Request $request): JsonResponse
    {
        $user = $request->user();

        if ($user->hasVerifiedEmail()) {
            return response()->json(['data' => ['message' => 'Your email address is already confirmed.']]);
        }

        try {
            $user->sendEmailVerificationNotification();
        } catch (Throwable $e) {
            report($e);

            throw new BusinessRuleException('email_unavailable', 'We could not send emails just now. Please try again later.', 503);
        }

        return response()->json(['data' => ['message' => 'We have sent a new confirmation link to '.$user->email.'.']]);
    }

    /**
     * Opened from the link in the email, so it answers with a redirect to the
     * Account page, which shows the outcome. The signed link is the proof;
     * the person does not need to be signed in on this device.
     */
    public function verify(Request $request, AuditLogger $audit, string $id, string $hash): RedirectResponse
    {
        if (! $request->hasValidSignature()) {
            return redirect('/account?email_verification=expired');
        }

        $user = User::find($id);

        if (! $user || ! hash_equals(sha1($user->getEmailForVerification()), $hash)) {
            return redirect('/account?email_verification=invalid');
        }

        if (! $user->hasVerifiedEmail()) {
            $user->markEmailAsVerified();
            event(new Verified($user));
            $audit->record('account.email_verified', $user, [], ['email' => $user->email], null, $user);
        }

        return redirect('/account?email_verification=success');
    }
}
