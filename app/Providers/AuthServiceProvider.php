<?php

namespace App\Providers;

use App\Models\Permission;
use App\Models\User;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Foundation\Support\Providers\AuthServiceProvider as ServiceProvider;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\URL;

class AuthServiceProvider extends ServiceProvider
{
    /**
     * The policy mappings for the application.
     *
     * @var array<class-string, class-string>
     */
    protected $policies = [
        // 'App\Models\Model' => 'App\Policies\ModelPolicy',
    ];

    /**
     * Register any authentication / authorization services.
     *
     * @return void
     */
    public function boot()
    {
        $this->registerPolicies();

        // Each permission key in config/permissions.php is a Gate ability,
        // answered from the user's roles. Other abilities fall through to policies.
        Gate::before(function (User $user, string $ability) {
            if (Permission::isDefined($ability)) {
                return $user->hasPermission($ability);
            }

            return null;
        });

        // Any administrator: an active account holding at least one permission
        Gate::define('admin-access', function (User $user) {
            return count($user->permissionKeys()) > 0;
        });

        // Approved provider with an active account. Customer abilities need no check.
        Gate::define('act-as-provider', function (User $user) {
            return $user->isProvider();
        });

        // Email links open pages in the Vue app, which call the API
        VerifyEmail::createUrlUsing(function ($user) {
            return URL::temporarySignedRoute('api.v1.auth.verify-email', now()->addHour(), [
                'id' => $user->getKey(),
                'hash' => sha1($user->getEmailForVerification()),
            ]);
        });

        ResetPassword::createUrlUsing(function ($user, string $token) {
            return url('/reset-password?'.http_build_query([
                'token' => $token,
                'email' => $user->getEmailForPasswordReset(),
            ]));
        });
    }
}
