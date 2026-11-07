<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Auth\Events\Registered;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * Account lifecycle for every user. Each account is a customer from the
 * start; provider capabilities come from App\Services\ProviderApplications.
 */
class Accounts
{
    /** @var AuditLogger */
    private $audit;

    /** @var DeviceSessions */
    private $devices;

    public function __construct(AuditLogger $audit, DeviceSessions $devices)
    {
        $this->audit = $audit;
        $this->devices = $devices;
    }

    /** @param array{name: string, email: string, phone: ?string, locale: ?string, password: string} $data */
    public function register(array $data): User
    {
        $user = DB::transaction(function () use ($data) {
            $user = User::create([
                'name' => $data['name'],
                'email' => $data['email'],
                'phone' => $data['phone'] ?? null,
                'locale' => $data['locale'] ?? 'en',
                'password' => Hash::make($data['password']),
            ]);

            $this->audit->record('account.registered', $user, [], ['email' => $user->email], null, $user);

            return $user;
        });

        // Sends the verification email (failures are logged, not fatal)
        event(new Registered($user));

        return $user;
    }

    /** @param array{name?: string, phone?: ?string, locale?: string} $data */
    public function updateProfile(User $user, array $data): User
    {
        $before = $user->only(array_keys($data));
        $user->fill($data);

        if (! $user->isDirty()) {
            return $user;
        }

        DB::transaction(function () use ($user, $before) {
            $after = $user->getDirty();
            $user->save();
            $this->audit->record('account.profile_updated', $user, array_intersect_key($before, $after), $after, null, $user);
        });

        return $user;
    }

    /**
     * Sets a new password and signs the account out everywhere: API tokens,
     * "Keep me signed in" cookies and every browser session except
     * $keepSessionId (the device that changed it). The caller handles that one.
     */
    public function setPassword(User $user, string $password, string $action, ?string $keepSessionId = null): void
    {
        DB::transaction(function () use ($user, $password, $action, $keepSessionId) {
            $user->forceFill([
                'password' => Hash::make($password),
                'remember_token' => Str::random(60),
            ])->save();

            $user->tokens()->delete();
            $this->devices->endSessions($user, $keepSessionId);

            $this->audit->record($action, $user, [], [], null, $user);
        });
    }
}
