<?php

use App\Http\Controllers\Api\V1\Admin\AdminActionController;
use App\Http\Controllers\Api\V1\Admin\AdminDataController;
use App\Http\Controllers\Api\V1\Admin\AuditLogController;
use App\Http\Controllers\Api\V1\Admin\CommunityModerationController;
use App\Http\Controllers\Api\V1\Admin\DocumentRequirementController;
use App\Http\Controllers\Api\V1\Admin\DocumentVerificationController;
use App\Http\Controllers\Api\V1\Admin\ProfessionSuggestionController;
use App\Http\Controllers\Api\V1\Admin\ReportController as AdminReportController;
use App\Http\Controllers\Api\V1\Admin\ProviderApplicationController as AdminProviderApplicationController;
use App\Http\Controllers\Api\V1\Admin\ProviderStandingController;
use App\Http\Controllers\Api\V1\Admin\PushAnnouncementController;
use App\Http\Controllers\Api\V1\Admin\RoleController;
use App\Http\Controllers\Api\V1\Admin\SettingController;
use App\Http\Controllers\Api\V1\Admin\UserRoleController;
use App\Http\Controllers\Api\V1\AppEventController;
use App\Http\Controllers\Api\V1\Auth\EmailVerificationController;
use App\Http\Controllers\Api\V1\Auth\PasswordController;
use App\Http\Controllers\Api\V1\Auth\SessionController;
use App\Http\Controllers\Api\V1\CategoryController;
use App\Http\Controllers\Api\V1\CommunityController;
use App\Http\Controllers\Api\V1\ConversationController;
use App\Http\Controllers\Api\V1\DirectoryController;
use App\Http\Controllers\Api\V1\JobApplicantController;
use App\Http\Controllers\Api\V1\JobController;
use App\Http\Controllers\Api\V1\MyActivityController;
use App\Http\Controllers\Api\V1\HealthController;
use App\Http\Controllers\Api\V1\MeController;
use App\Http\Controllers\Api\V1\PresenceController;
use App\Http\Controllers\Api\V1\ProviderApplicationController;
use App\Http\Controllers\Api\V1\ReceivedRequestController;
use App\Http\Controllers\Api\V1\ReportController;
use App\Http\Controllers\Api\V1\ReviewController;
use App\Http\Controllers\Api\V1\VerificationController;
use App\Http\Controllers\Api\V1\ProfilePhotoController;
use App\Http\Controllers\Api\V1\ProviderReportController;
use App\Http\Controllers\Api\V1\PushSubscriptionController;
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
    Route::get('settings', [DirectoryController::class, 'settings'])->name('settings.public');
    Route::middleware('throttle:place-search')->group(function () {
        Route::get('areas/search', [DirectoryController::class, 'searchAreas'])->name('areas.search');
        Route::get('areas/nearest', [DirectoryController::class, 'nearestArea'])->name('areas.nearest');
    });
    Route::get('providers', [DirectoryController::class, 'providers'])->name('providers.index');
    Route::get('providers/{id}', [DirectoryController::class, 'provider'])->whereNumber('id')->name('providers.show');
    Route::get('reviews/recent', [DirectoryController::class, 'recentReviews'])->name('reviews.recent');
    Route::get('jobs', [JobController::class, 'index'])->name('jobs.index');
    Route::get('jobs/{id}', [JobController::class, 'show'])->whereNumber('id')->name('jobs.show');
    Route::post('app-events', [AppEventController::class, 'store'])->middleware('throttle:app-events')->name('app-events.store');
    Route::get('push/config', [PushSubscriptionController::class, 'config'])->name('push.config');

    // The community: anyone can read
    Route::get('community/posts', [CommunityController::class, 'index'])->name('community.index');
    Route::get('community/posts/{post}', [CommunityController::class, 'show'])->whereUuid('post')->name('community.show');
    Route::get('community/posts/{post}/comments', [CommunityController::class, 'comments'])->whereUuid('post')->name('community.comments');
    Route::get('community/media/{file}', [CommunityController::class, 'media'])
        ->where('file', '[0-9a-f-]{36}(-t)?\.jpg')
        ->name('community.media');

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
            Route::post('reports', [ReportController::class, 'store'])->name('reports.store');
            Route::post('me/reports/{id}/reply', [ReportController::class, 'reply'])->whereNumber('id')->name('me.reports.reply');
        });
        Route::middleware('throttle:marketplace-write')->group(function () {
            Route::post('me/push-subscriptions', [PushSubscriptionController::class, 'store'])->name('me.push.store');
            Route::delete('me/push-subscriptions', [PushSubscriptionController::class, 'destroy'])->name('me.push.destroy');
        });
        Route::get('me/requests', [MyActivityController::class, 'requests'])->name('me.requests');
        Route::get('me/applications', [MyActivityController::class, 'applications'])->name('me.applications');

        // Providers who applied to the customer's own posted job
        Route::get('me/jobs/{id}/applications', [JobApplicantController::class, 'index'])->whereNumber('id')->name('me.jobs.applications');
        Route::post('me/jobs/{id}/applications/{application}/hire', [JobApplicantController::class, 'hire'])
            ->whereNumber('id')->whereNumber('application')->middleware('throttle:marketplace-write')->name('me.jobs.hire');

        // The open app checks in so administrators can see who is online
        Route::post('me/heartbeat', [PresenceController::class, 'heartbeat'])->middleware('throttle:heartbeat')->name('me.heartbeat');

        // The account's own reports and complaints, and warnings sent to it
        Route::get('me/reports', [ReportController::class, 'index'])->name('me.reports');
        Route::get('me/reports/{id}/files/{index}', [ReportController::class, 'file'])->whereNumber('id')->whereNumber('index')->name('me.reports.file');
        Route::get('me/warnings', [ReportController::class, 'warnings'])->name('me.warnings');
        Route::post('me/warnings/{id}/read', [ReportController::class, 'readWarning'])->whereNumber('id')->name('me.warnings.read');

        // Rating providers who finished work for the customer
        Route::get('me/reviews/waiting', [ReviewController::class, 'waiting'])->name('me.reviews.waiting');
        Route::middleware('throttle:marketplace-write')->group(function () {
            Route::post('me/requests/{id}/review', [ReviewController::class, 'store'])->whereNumber('id')->name('me.requests.review');
            Route::post('me/requests/{id}/review/later', [ReviewController::class, 'later'])->whereNumber('id')->name('me.requests.review-later');
        });

        // Requests customers sent to the provider business the account belongs to
        Route::get('me/received-requests', [ReceivedRequestController::class, 'index'])->name('me.received-requests');
        Route::middleware('throttle:marketplace-write')->group(function () {
            Route::post('me/received-requests/{id}/accept', [ReceivedRequestController::class, 'accept'])->whereNumber('id')->name('me.received-requests.accept');
            Route::post('me/received-requests/{id}/decline', [ReceivedRequestController::class, 'decline'])->whereNumber('id')->name('me.received-requests.decline');
            Route::post('me/received-requests/{id}/complete', [ReceivedRequestController::class, 'complete'])->whereNumber('id')->name('me.received-requests.complete');
            Route::post('me/received-requests/{id}/message', [ReceivedRequestController::class, 'message'])->whereNumber('id')->name('me.received-requests.message');
        });

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

        // The provider's own verification documents (private files)
        Route::get('me/verification', [VerificationController::class, 'show'])->name('me.verification');
        Route::get('me/verification/documents/{document}/files/{index}', [VerificationController::class, 'file'])->whereNumber('index')->name('me.verification.file');
        Route::middleware('throttle:marketplace-write')->group(function () {
            Route::post('me/verification/documents', [VerificationController::class, 'store'])->name('me.verification.store');
            Route::post('me/verification/documents/{document}/replace', [VerificationController::class, 'replace'])->name('me.verification.replace');
            Route::delete('me/verification/documents/{document}', [VerificationController::class, 'destroy'])->name('me.verification.destroy');
            Route::post('me/verification/submit', [VerificationController::class, 'submit'])->name('me.verification.submit');
            Route::put('me/verification/answers', [VerificationController::class, 'answers'])->name('me.verification.answers');
        });

        // Taking part in the community
        Route::get('community/saved', [CommunityController::class, 'saved'])->name('community.saved');
        Route::middleware('throttle:community-post')->group(function () {
            Route::post('community/posts', [CommunityController::class, 'store'])->name('community.store');
        });
        Route::middleware('throttle:community-comment')->group(function () {
            Route::post('community/posts/{post}/comments', [CommunityController::class, 'comment'])->whereUuid('post')->name('community.comment');
        });
        Route::middleware('throttle:community-react')->group(function () {
            Route::patch('community/posts/{post}', [CommunityController::class, 'update'])->whereUuid('post')->name('community.update');
            Route::delete('community/posts/{post}', [CommunityController::class, 'destroy'])->whereUuid('post')->name('community.destroy');
            Route::post('community/posts/{post}/like', [CommunityController::class, 'like'])->whereUuid('post')->name('community.like');
            Route::delete('community/posts/{post}/like', [CommunityController::class, 'like'])->whereUuid('post')->name('community.unlike');
            Route::post('community/posts/{post}/save', [CommunityController::class, 'save'])->whereUuid('post')->name('community.save');
            Route::delete('community/posts/{post}/save', [CommunityController::class, 'save'])->whereUuid('post')->name('community.unsave');
            Route::delete('community/comments/{comment}', [CommunityController::class, 'destroyComment'])->whereNumber('comment')->name('community.comment.destroy');
        });

        Route::prefix('admin')->name('admin.')->group(function () {
            // Any administrator; each section inside is limited by permission
            Route::get('data', AdminDataController::class)->middleware('can:admin-access')->name('data');

            Route::middleware('can:trust.view')->group(function () {
                Route::post('users/{user}/suspend', [AdminActionController::class, 'suspendAccount'])->name('users.suspend');
                Route::post('users/{user}/reactivate', [AdminActionController::class, 'reactivateAccount'])->name('users.reactivate');
                // Working a report: take it, ask the reporter, note, resolve with an action
                Route::post('reports/{report}/assign', [AdminReportController::class, 'assign'])->name('reports.assign');
                Route::post('reports/{report}/request-information', [AdminReportController::class, 'requestInformation'])->name('reports.request-information');
                Route::post('reports/{report}/note', [AdminReportController::class, 'note'])->name('reports.note');
                Route::post('reports/{report}/resolve', [AdminReportController::class, 'resolve'])->name('reports.resolve');
                Route::get('reports/{report}/files/{index}', [AdminReportController::class, 'file'])->whereNumber('index')->name('reports.file');
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

                // Professions typed under "Other"
                Route::post('profession-suggestions/{suggestion}/add', [ProfessionSuggestionController::class, 'add'])->name('profession-suggestions.add');
                Route::post('profession-suggestions/{suggestion}/match', [ProfessionSuggestionController::class, 'match'])->name('profession-suggestions.match');
                Route::post('profession-suggestions/{suggestion}/decline', [ProfessionSuggestionController::class, 'decline'])->name('profession-suggestions.decline');
            });

            // Community moderation
            Route::middleware('can:content.view')->prefix('community')->name('community.')->group(function () {
                Route::post('posts/{post}/remove', [CommunityModerationController::class, 'removePost'])->whereUuid('post')->name('posts.remove');
                Route::post('posts/{post}/restore', [CommunityModerationController::class, 'restorePost'])->whereUuid('post')->name('posts.restore');
                Route::post('comments/{comment}/remove', [CommunityModerationController::class, 'removeComment'])->whereNumber('comment')->name('comments.remove');
            });

            // Documents each profession needs; publishing adds a version
            Route::get('document-requirements', [DocumentRequirementController::class, 'index'])
                ->middleware('can:documents.review')->name('document-requirements.index');
            Route::post('document-requirements/{category}', [DocumentRequirementController::class, 'publish'])
                ->middleware('can:verification.configure')->where('category', '[a-z0-9_-]+')->name('document-requirements.publish');

            // Document verification (the grey tick)
            Route::middleware('can:documents.review')->prefix('verifications/{verification}')->name('verifications.')->group(function () {
                Route::post('assign', [DocumentVerificationController::class, 'assign'])->name('assign');
                Route::post('documents/{document}/accept', [DocumentVerificationController::class, 'acceptDocument'])->name('documents.accept');
                Route::post('documents/{document}/send-back', [DocumentVerificationController::class, 'sendBackDocument'])->name('documents.send-back');
                Route::post('request-document', [DocumentVerificationController::class, 'requestDocument'])->name('request-document');
                Route::post('request-information', [DocumentVerificationController::class, 'requestInformation'])->name('request-information');
                Route::post('approve', [DocumentVerificationController::class, 'approve'])->name('approve');
                Route::post('reject', [DocumentVerificationController::class, 'reject'])->name('reject');
                Route::post('note', [DocumentVerificationController::class, 'note'])->name('note');
                Route::post('reopen', [DocumentVerificationController::class, 'reopen'])->middleware('can:verification.configure')->name('reopen');
            });
            Route::get('verifications/{verification}/documents/{document}/files/{index}', [DocumentVerificationController::class, 'file'])
                ->middleware('can:documents.view')->whereNumber('index')->name('verifications.file');

            // Suspending a provider is a trust and safety action
            Route::middleware('can:trust.view')->group(function () {
                Route::post('providers/{provider}/suspend', [ProviderStandingController::class, 'suspend'])->name('providers.suspend');
                Route::post('providers/{provider}/reinstate', [ProviderStandingController::class, 'reinstate'])->name('providers.reinstate');
            });

            Route::post('push-announcements', [PushAnnouncementController::class, 'store'])
                ->middleware(['can:comms.send', 'throttle:account-email'])
                ->name('push-announcements.store');

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
