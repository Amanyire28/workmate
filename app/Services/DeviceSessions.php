<?php

namespace App\Services;

use App\Exceptions\BusinessRuleException;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * The devices an account is signed in on (database sessions), and signing them
 * out. Session ids are secrets, so the app only ever sees a hash of each.
 *
 * Signing devices out also changes the account's remember token, so a "Keep
 * me signed in" cookie on those devices can't sign them back in.
 */
class DeviceSessions
{
    /** @var AuditLogger */
    private $audit;

    public function __construct(AuditLogger $audit)
    {
        $this->audit = $audit;
    }

    /** Only the database driver knows which sessions belong to whom */
    public function available(): bool
    {
        return config('session.driver') === 'database';
    }

    public static function keyOf(string $sessionId): string
    {
        return substr(hash('sha256', $sessionId), 0, 32);
    }

    public function forUser(User $user, ?string $currentId): array
    {
        if (! $this->available()) {
            return [];
        }

        $since = now()->subMinutes((int) config('session.lifetime'))->getTimestamp();

        return DB::table(config('session.table', 'sessions'))
            ->where('user_id', $user->id)
            ->where('last_activity', '>=', $since)
            ->orderByDesc('last_activity')
            ->get(['id', 'ip_address', 'user_agent', 'last_activity'])
            ->map(function ($row) use ($currentId) {
                $agent = self::describe((string) $row->user_agent);

                return [
                    'key' => self::keyOf($row->id),
                    'browser' => $agent['browser'],
                    'platform' => $agent['platform'],
                    'kind' => $agent['kind'],
                    'ip_address' => self::maskIp($row->ip_address),
                    'last_active_at' => Carbon::createFromTimestamp($row->last_activity)->toIso8601String(),
                    'current' => $currentId !== null && hash_equals($row->id, $currentId),
                ];
            })->values()->all();
    }

    /** Signs out one other device */
    public function signOut(User $user, string $key, Request $request): void
    {
        $currentId = $request->hasSession() ? $request->session()->getId() : null;
        $row = DB::table(config('session.table', 'sessions'))
            ->where('user_id', $user->id)
            ->get(['id', 'user_agent'])
            ->first(function ($row) use ($key) {
                return hash_equals(self::keyOf($row->id), $key);
            });

        if (! $row) {
            throw new BusinessRuleException('device_not_found', 'That device is already signed out.', 404);
        }
        if ($currentId !== null && hash_equals($row->id, $currentId)) {
            throw new BusinessRuleException('current_device', 'To sign out of this device, use Sign out.', 422);
        }

        $agent = self::describe((string) $row->user_agent);
        DB::transaction(function () use ($user, $row, $agent) {
            DB::table(config('session.table', 'sessions'))->where('id', $row->id)->delete();
            $this->audit->record('auth.device_signed_out', $user, [], ['device' => implode(' on ', array_filter([$agent['browser'], $agent['platform']]))], null, $user);
        });
        $this->forgetOtherRememberCookies($user, $request);
    }

    /** Signs out every device except this one */
    public function signOutOthers(User $user, Request $request): int
    {
        $currentId = $request->hasSession() ? $request->session()->getId() : null;
        $count = $this->endSessions($user, $currentId);
        $this->audit->record('auth.other_devices_signed_out', $user, [], ['devices' => $count], null, $user);
        $this->forgetOtherRememberCookies($user, $request);

        return $count;
    }

    /**
     * Ends the account's sessions (all of them, or all but $exceptId). Used
     * after a password change or reset and when an account is suspended.
     */
    public function endSessions(User $user, ?string $exceptId = null): int
    {
        if (! $this->available()) {
            return 0;
        }

        return DB::table(config('session.table', 'sessions'))
            ->where('user_id', $user->id)
            ->when($exceptId !== null, function ($q) use ($exceptId) {
                $q->where('id', '!=', $exceptId);
            })
            ->delete();
    }

    /**
     * A new remember token stops "Keep me signed in" cookies elsewhere. This
     * device keeps its own: it is signed in again with a fresh cookie.
     */
    public function forgetOtherRememberCookies(User $user, Request $request): void
    {
        $guard = Auth::guard('web');
        $remembered = $request->cookies->has($guard->getRecallerName());

        $user->forceFill(['remember_token' => Str::random(60)])->save();

        if ($remembered && $request->hasSession() && $guard->check() && $guard->id() === $user->id) {
            $guard->setRememberDuration((int) config('session.remember_lifetime'));
            $guard->login($user, true);
        }
    }

    /** "Chrome", "Android", "phone" from a user agent; plain words only */
    public static function describe(string $agent): array
    {
        $browser = 'Browser';
        foreach (['Edg/' => 'Edge', 'OPR/' => 'Opera', 'SamsungBrowser' => 'Samsung Internet', 'Firefox' => 'Firefox', 'CriOS' => 'Chrome', 'Chrome' => 'Chrome', 'Safari' => 'Safari'] as $needle => $name) {
            if (stripos($agent, $needle) !== false) {
                $browser = $name;
                break;
            }
        }

        $platform = null;
        foreach (['Android' => 'Android', 'iPhone' => 'iPhone', 'iPad' => 'iPad', 'Windows' => 'Windows', 'Mac OS X' => 'Mac', 'CrOS' => 'Chromebook', 'Linux' => 'Linux'] as $needle => $name) {
            if (stripos($agent, $needle) !== false) {
                $platform = $name;
                break;
            }
        }

        $kind = in_array($platform, ['Android', 'iPhone'], true) && stripos($agent, 'Mobile') !== false || $platform === 'iPhone'
            ? 'phone'
            : ($platform === 'iPad' || $platform === 'Android' ? 'tablet' : 'computer');

        return ['browser' => $browser, 'platform' => $platform, 'kind' => $kind];
    }

    /** 41.210.155.23 → 41.210.155.x; IPv6 keeps the first four groups */
    public static function maskIp(?string $ip): ?string
    {
        if (! $ip) {
            return null;
        }
        if (strpos($ip, ':') !== false) {
            return implode(':', array_slice(explode(':', $ip), 0, 4)).':…';
        }

        return preg_replace('/\.\d+$/', '.x', $ip);
    }
}
