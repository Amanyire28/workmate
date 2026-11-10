<?php

namespace App\Providers;

use App\Listeners\QualifyReferral;
use App\Listeners\SendVerificationEmail;
use Illuminate\Auth\Events\Registered;
use Illuminate\Auth\Events\Verified;
use Illuminate\Foundation\Support\Providers\EventServiceProvider as ServiceProvider;
use Illuminate\Support\Facades\Event;

class EventServiceProvider extends ServiceProvider
{
    /**
     * The event listener mappings for the application.
     *
     * @var array<class-string, array<int, class-string>>
     */
    protected $listen = [
        Registered::class => [
            // Logs mail failures instead of failing the registration
            SendVerificationEmail::class,
        ],
        Verified::class => [
            // An invitation counts once the new member confirms their email
            QualifyReferral::class,
        ],
    ];

    /**
     * Register any events for your application.
     *
     * @return void
     */
    public function boot()
    {
        //
    }
}
