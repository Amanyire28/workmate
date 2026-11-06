<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Foundation\Support\Providers\RouteServiceProvider as ServiceProvider;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;

class RouteServiceProvider extends ServiceProvider
{
    /**
     * The path to the "home" route for your application.
     *
     * This is used by Laravel authentication to redirect users after login.
     *
     * @var string
     */
    public const HOME = '/home';

    /**
     * The controller namespace for the application.
     *
     * When present, controller route declarations will automatically be prefixed with this namespace.
     *
     * @var string|null
     */
    // protected $namespace = 'App\\Http\\Controllers';

    /**
     * Define your route model bindings, pattern filters, etc.
     *
     * @return void
     */
    public function boot()
    {
        $this->configureRateLimiting();

        $this->routes(function () {
            Route::prefix('api')
                ->middleware('api')
                ->namespace($this->namespace)
                ->group(base_path('routes/api.php'));

            Route::middleware('web')
                ->namespace($this->namespace)
                ->group(base_path('routes/web.php'));
        });
    }

    /**
     * Configure the rate limiters for the application.
     *
     * @return void
     */
    protected function configureRateLimiting()
    {
        RateLimiter::for('api', function (Request $request) {
            return Limit::perMinute(60)->by(optional($request->user())->id ?: $request->ip());
        });

        // Sign-in attempts: per email address and per device, so one person
        // can't lock out another and one device can't try many accounts
        RateLimiter::for('login', function (Request $request) {
            return [
                Limit::perMinute(5)->by(strtolower((string) $request->input('email')).'|'.$request->ip()),
                Limit::perMinute(20)->by($request->ip()),
            ];
        });

        // Creating jobs, requests, applications and messages
        RateLimiter::for('marketplace-write', function (Request $request) {
            return Limit::perMinute(30)->by(optional($request->user())->id ?: $request->ip());
        });

        // Anonymous install events from the web app
        RateLimiter::for('app-events', function (Request $request) {
            return Limit::perMinute(10)->by($request->ip());
        });

        // Typing in a location box asks the server as the letters come in
        RateLimiter::for('place-search', function (Request $request) {
            return Limit::perMinute(120)->by($request->ip());
        });

        // The open app checks in about once a minute
        RateLimiter::for('heartbeat', function (Request $request) {
            return Limit::perMinute(10)->by(optional($request->user())->id ?: $request->ip());
        });

        // Registration and anything that sends an email
        RateLimiter::for('account-email', function (Request $request) {
            return Limit::perMinute(6)->by(optional($request->user())->id ?: $request->ip());
        });

        // Anonymous search and profile-view counts; generous, as many phones share one mobile network address
        RateLimiter::for('app-counts', function (Request $request) {
            return Limit::perMinute(120)->by($request->ip());
        });

        // Community posts, comments, and likes, saves and edits
        RateLimiter::for('community-post', function (Request $request) {
            $key = optional($request->user())->id ?: $request->ip();

            return [Limit::perMinute(3)->by('m'.$key), Limit::perDay(40)->by('d'.$key)];
        });
        RateLimiter::for('community-comment', function (Request $request) {
            $key = optional($request->user())->id ?: $request->ip();

            return [Limit::perMinute(10)->by('m'.$key), Limit::perHour(150)->by('h'.$key)];
        });
        RateLimiter::for('community-react', function (Request $request) {
            return Limit::perMinute(90)->by(optional($request->user())->id ?: $request->ip());
        });
    }
}
