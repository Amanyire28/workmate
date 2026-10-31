<?php

use App\Http\Controllers\Api\V1\Admin\AdminActionController;
use App\Http\Controllers\Api\V1\Admin\AdminDataController;
use App\Http\Controllers\Api\V1\Admin\AuditLogController;
use App\Http\Controllers\Api\V1\Admin\ProviderApplicationController as AdminProviderApplicationController;
use App\Http\Controllers\Api\V1\Admin\ProviderStandingController;
use App\Http\Controllers\Api\V1\Admin\RoleController;
use App\Http\Controllers\Api\V1\Admin\SettingController;
use App\Http\Controllers\Api\V1\Admin\UserRoleController;
use App\Http\Controllers\Api\V1\Auth\EmailVerificationController;
use App\Http\Controllers\Api\V1\Auth\PasswordController;
use App\Http\Controllers\Api\V1\Auth\SessionController;
use App\Http\Controllers\Api\V1\CategoryController;
use App\Http\Controllers\Api\V1\ConversationController;
use App\Http\Controllers\Api\V1\DirectoryController;
use App\Http\Controllers\Api\V1\JobController;
use App\Http\Controllers\Api\V1\MyActivityController;
use App\Http\Controllers\Api\V1\HealthController;
use App\Http\Controllers\Api\V1\MeController;
use App\Http\Controllers\Api\V1\ProviderApplicationController;
use App\Http\Controllers\Api\V1\ProfilePhotoController;
use App\Http\Controllers\Api\V1\ProviderReportController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
|
| Here is where you can register API routes for your application. These
| routes are loaded by the RouteServiceProvider within a group which
| is assigned the "api" middleware group. Enjoy building your API!
|
*/

Route::middleware('auth:sanctum')->get('/user', function (Request $request) {
    return $request->user();
});

/*
| WorkMate API v1. Success: { "data": ... } (paginated lists add "links" and
| "meta"). Errors: { "message", "code", "errors"? } — see App\Exceptions\Handler.
| Admin routes check a permission key from config/permissions.php with can:.
*/
Route::prefix('v1')->name('api.v1.')->group(function () {
    Route::get('health', HealthController::class)->name('health');
    Route::get('categories', [CategoryController::class, 'index'])->name('categories.index');
    Route::get('catalog', [DirectoryController::class, 'catalog'])->name('catalog');
    Route::get('avatars/{file}', [ProfilePhotoController::class, 'show'])
        ->where('file', '[0-9a-f-]{36}\.jpg')
        ->name('avatars.show');
    Route::get('areas', [DirectoryController::class, 'areas'])->name('areas.index');
    Route::get('providers', [DirectoryController::class, 'providers'])->name('providers.index');
    Route::get('providers/{id}', [DirectoryController::class, 'provider'])->whereNumber('id')->name('providers.show');
    Route::get('reviews/recent', [DirectoryController::class, 'recentReviews'])->name('reviews.recent');
    Route::get('jobs', [JobController::class, 'index'])->name('jobs.index');
    Route::get('jobs/{id}', [JobController::class, 'show'])->whereNumber('id')->name('jobs.show');

    // Signing in and out (cookie sessions; call GET /sanctum/csrf-cookie first)
    Route::prefix('auth')->name('auth.')->group(function () {
        Route::post('register', [SessionController::class, 'register'])->middleware('throttle:account-email')->name('register');
        Route::post('login', [SessionController::class, 'login'])->middleware('throttle:login')->name('login');
        Route::post('logout', [SessionController::class, 'logout'])->name('logout');
        Route::post('forgot-password', [PasswordController::class, 'forgot'])->middleware('throttle:account-email')->name('forgot-password');
        Route::post('reset-password', [PasswordController::class, 'reset'])->middleware('throttle:login')->name('reset-password');
        // Opened from the email; the signature is checked in the controller
        Route::get('verify-email/{id}/{hash}', [EmailVerificationController::class, 'verify'])->name('verify-email');
    });

    Route::middleware(['auth:sanctum', 'active'])->group(function () {
        Route::get('me', [MeController::class, 'show'])->name('me');
        Route::patch('me', [MeController::class, 'update'])->name('me.update');
        Route::put('me/password', [PasswordController::class, 'update'])->middleware('throttle:login')->name('me.password');
        Route::post('me/photo', [ProfilePhotoController::class, 'store'])->middleware('throttle:account-email')->name('me.photo');
        Route::delete('me/photo', [ProfilePhotoController::class, 'destroy'])->name('me.photo.destroy');
        Route::post('me/email/verification-notification', [EmailVerificationController::class, 'send'])
            ->middleware('throttle:account-email')
            ->name('me.verification-notification');

        // Posting jobs, requesting providers and applying
        Route::middleware('throttle:marketplace-write')->group(function () {
            Route::post('jobs', [JobController::class, 'store'])->name('jobs.store');
            Route::post('jobs/{id}/applications', [JobController::class, 'apply'])->whereNumber('id')->name('jobs.apply');
            Route::post('providers/{id}/requests', [JobController::class, 'requestProvider'])->whereNumber('id')->name('providers.request');
            Route::post('providers/{id}/reports', [ProviderReportController::class, 'store'])->whereNumber('id')->name('providers.report');
        });
        Route::get('me/requests', [MyActivityController::class, 'requests'])->name('me.requests');
        Route::get('me/applications', [MyActivityController::class, 'applications'])->name('me.applications');

        // Messages (only the two sides of a chat can see it)
        Route::get('me/conversations', [ConversationController::class, 'index'])->name('me.conversations');
        Route::get('me/conversations/unread-count', [ConversationController::class, 'unreadCount'])->name('me.conversations.unread');
        Route::post('me/conversations/provider/{id}', [ConversationController::class, 'withProvider'])->whereNumber('id')->name('me.conversations.provider');
        Route::get('me/conversations/{conversation}', [ConversationController::class, 'show'])->name('me.conversations.show');
        Route::post('me/conversations/{conversation}/messages', [ConversationController::class, 'send'])
            ->middleware('throttle:marketplace-write')
            ->name('me.conversations.send');

        // The account's own application to offer services
        Route::get('me/provider', [ProviderApplicationController::class, 'show'])->name('me.provider');
        Route::put('me/provider', [ProviderApplicationController::class, 'save'])->name('me.provider.save');
        Route::post('me/provider/submit', [ProviderApplicationController::class, 'submit'])->name('me.provider.submit');
        Route::post('me/provider/reopen', [ProviderApplicationController::class, 'reopen'])->name('me.provider.reopen');

        Route::prefix('admin')->name('admin.')->group(function () {
            // Any administrator; each section inside is limited by permission
            Route::get('data', AdminDataController::class)->middleware('can:admin-access')->name('data');

            Route::middleware('can:trust.view')->group(function () {
                Route::post('users/{user}/suspend', [AdminActionController::class, 'suspendAccount'])->name('users.suspend');
                Route::post('users/{user}/reactivate', [AdminActionController::class, 'reactivateAccount'])->name('users.reactivate');
                Route::patch('reports/{report}', [AdminActionController::class, 'updateReport'])->name('reports.update');
            });
            Route::patch('reviews/{review}', [AdminActionController::class, 'updateReview'])
                ->middleware('can:content.view')
                ->name('reviews.update');

            Route::middleware('can:admin.manage')->group(function () {
                Route::get('permissions', [RoleController::class, 'permissions'])->name('permissions.index');
                Route::get('roles', [RoleController::class, 'index'])->name('roles.index');
                Route::put('users/{user}/roles/{role}', [UserRoleController::class, 'store'])->name('users.roles.store');
                Route::delete('users/{user}/roles/{role}', [UserRoleController::class, 'destroy'])->name('users.roles.destroy');
            });

            Route::middleware('can:providers.review')->group(function () {
                Route::get('provider-applications', [AdminProviderApplicationController::class, 'index'])->name('provider-applications.index');
                Route::get('provider-applications/{application}', [AdminProviderApplicationController::class, 'show'])->name('provider-applications.show');
                Route::post('provider-applications/{application}/approve', [AdminProviderApplicationController::class, 'approve'])->name('provider-applications.approve');
                Route::post('provider-applications/{application}/reject', [AdminProviderApplicationController::class, 'reject'])->name('provider-applications.reject');
                Route::post('provider-applications/{application}/request-information', [AdminProviderApplicationController::class, 'requestInformation'])->name('provider-applications.request-information');
            });

            // Suspending a provider is a trust and safety action
            Route::middleware('can:trust.view')->group(function () {
                Route::post('providers/{provider}/suspend', [ProviderStandingController::class, 'suspend'])->name('providers.suspend');
                Route::post('providers/{provider}/reinstate', [ProviderStandingController::class, 'reinstate'])->name('providers.reinstate');
            });

            Route::get('audit-logs', [AuditLogController::class, 'index'])
                ->middleware('can:security.view')
                ->name('audit-logs.index');

            // Each setting checks its own view and change permissions
            Route::get('settings', [SettingController::class, 'index'])->name('settings.index');
            Route::patch('settings/{key}', [SettingController::class, 'update'])
                ->where('key', '[a-z0-9_.]+')
                ->name('settings.update');
        });
    });
});
