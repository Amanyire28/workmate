<?php

namespace App\Providers;

use App\Models\Permission;
use App\Models\User;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Foundation\Support\Providers\AuthServiceProvider as ServiceProvider;
use Illuminate\Notifications\Messages\MailMessage;
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

        // Email links open pages in the Vue app, which call the API. They're built from the
        // site's own address (APP_PUBLIC_URL), never from the Host header of the request
        // that asked for them, so a forged header can't send the link somewhere else.
        VerifyEmail::createUrlUsing(function ($user) {
            return self::fromPublicUrl(function () use ($user) {
                return URL::temporarySignedRoute('api.v1.auth.verify-email', now()->addMinutes(config('auth.verification.expire')), [
                    'id' => $user->getKey(),
                    'hash' => sha1($user->getEmailForVerification()),
                ]);
            });
        });

        VerifyEmail::toMailUsing(function ($user, string $url) {
            $firstName = trim(strtok((string) $user->name, ' ')) ?: 'there';

            return (new MailMessage)
                ->subject('Confirm your WorkMate email address')
                ->greeting("Hi {$firstName},")
                ->line('Welcome to WorkMate! Please confirm that this is your email address.')
                ->action('Confirm email address', $url)
                ->line('This link works for 24 hours. If it expires, you can ask for a new one from your Account page.')
                ->line('If you didn’t create a WorkMate account, you can ignore this email.')
                ->salutation('The WorkMate team');
        });

        ResetPassword::createUrlUsing(function ($user, string $token) {
            return self::fromPublicUrl(function () use ($user, $token) {
                return url('/reset-password?'.http_build_query([
                    'token' => $token,
                    'email' => $user->getEmailForPasswordReset(),
                ]));
            });
        });
    }

    /** Builds a link from APP_PUBLIC_URL when it's set (config/security.php) */
    private static function fromPublicUrl(callable $build): string
    {
        $root = (string) config('security.public_url');
        if ($root === '') {
            return $build();
        }
        URL::forceRootUrl($root);
        try {
            return $build();
        } finally {
            URL::forceRootUrl(null);
        }
    }
}
