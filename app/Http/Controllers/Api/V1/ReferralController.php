<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\Referrals;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cookie;

/** Invite Friends: opening an invitation link, and your own invitations */
class ReferralController extends Controller
{
    /** @var Referrals */
    private $referrals;

    public function __construct(Referrals $referrals)
    {
        $this->referrals = $referrals;
    }

    /**
     * Someone opened an invitation link. Remembers it on this device for the
     * set number of days: the first valid link wins, and signed-in accounts
     * can't be claimed.
     */
    public function visit(Request $request): JsonResponse
    {
        $data = $request->validate(['code' => ['required', 'string', 'max:20']]);

        $existing = $this->referrals->fromCookie($request->cookie(Referrals::COOKIE));
        $inviter = $existing ? $this->referrals->inviterFor($existing['code']) : null;

        if (! $inviter) {
            $inviter = $this->referrals->recordVisit($data['code']);
            if ($inviter && ! $request->user('sanctum')) {
                Cookie::queue(Cookie::make(Referrals::COOKIE, $this->referrals->cookieValue($inviter->referral_code),
                    $this->referrals->attributionDays() * 24 * 60, null, null, null, true, false, 'lax'));
            }
        }

        return response()->json(['data' => [
            'valid' => (bool) $inviter,
            // "Invited by Robert S." on the sign-up page
            'inviter' => $inviter && ! $request->user('sanctum') ? Referrals::shortName($inviter->name) : null,
        ]]);
    }

    public function mine(Request $request): JsonResponse
    {
        return response()->json(['data' => $this->referrals->summary($request->user())]);
    }
}
