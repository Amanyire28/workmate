<?php

namespace App\Services;

use App\Models\AppInstallEvent;
use App\Models\AccountWarning;
use App\Models\Area;
use App\Models\AuditLog;
use App\Models\Category;
use App\Models\DocumentVerification;
use App\Models\PlatformSetting;
use App\Models\Profession;
use App\Models\ProfessionSuggestion;
use App\Models\ProviderApplication;
use App\Models\ProviderProfile;
use App\Models\ProviderReport;
use App\Models\ProviderWorkItem;
use App\Models\Report;
use App\Models\PushAnnouncement;
use App\Models\PushSubscription;
use App\Models\Quotation;
use App\Models\CommunityComment;
use App\Models\CommunityPost;
use App\Models\RecognitionMilestone;
use App\Models\Referral;
use App\Models\Review;
use App\Models\ServiceRequest;
use App\Models\User;
use App\Models\UserBadge;
use App\Models\VerificationDocument;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * The admin area's view of the marketplace, built from the database in the
 * record shapes the admin screens use. Each section is included only for
 * administrators whose permissions cover it; contact details are masked.
 *
 * Sample records (is_sample) are listed, marked "(sample)", but left out of
 * every dashboard figure so the numbers describe real activity only.
 *
 * Finance, bookings, disputes, videos, notifications, usage tracking and
 * document verification have no tables yet, so they come back empty.
 */
class AdminSnapshot
{
    public const HISTORY_DAYS = 180;

    /** Daily figures that are actually recorded; every other series key is not tracked yet */
    public const TRACKED_SERIES = ['customersNew', 'providersNew', 'jobsCreated', 'enquiries', 'jobsCompleted', 'activeUsers',
        'searches', 'searchNearby', 'searchWider', 'searchNone', 'profileViews',
        'messagesSent', 'communityPosts', 'communityComments', 'follows', 'reportsOpened', 'notificationsSent'];

    private const SERIES_KEYS = ['customersNew', 'providersNew', 'activeUsers', 'searches', 'searchNearby', 'searchWider', 'searchNone',
        'gpsDenied', 'gpsTimeout', 'profileViews', 'enquiries', 'jobsCreated', 'jobsCompleted', 'bookingsCreated', 'bookingsCompleted',
        'bookingsCancelled', 'gtv', 'commission', 'fees', 'refunds', 'disputesOpened', 'disputesResolved',
        'messagesSent', 'communityPosts', 'communityComments', 'follows', 'reportsOpened', 'notificationsSent'];

    // Anonymous daily counters (app_daily_counts) and the series they feed
    private const COUNTER_SERIES = ['search' => 'searches', 'search_nearby' => 'searchNearby', 'search_wider' => 'searchWider',
        'search_none' => 'searchNone', 'profile_view' => 'profileViews'];

    private const PROVIDER_TYPES = ['individual' => 'individual', 'group' => 'team', 'company' => 'business'];

    private const REPORT_TYPES = [
        'scam' => 'Suspected fraud', 'offline' => 'Off-platform payment request', 'no_show' => 'Didn’t show up or didn’t do the work',
        'unsafe' => 'Unsafe behaviour', 'fake' => 'Fake profile or request', 'quality' => 'Poor or damaging work',
        'abusive' => 'Rude or abusive', 'not_paid' => 'Didn’t pay', 'payment' => 'Payment problem', 'safety' => 'Safety concern',
        'app_problem' => 'Problem with the app', 'other' => 'Other',
        'spam' => 'Spam or advertising', 'inappropriate' => 'Inappropriate content', 'misleading' => 'Misleading or false',
    ];

    private const REPORT_OUTCOMES = [
        'no_violation' => 'No violation found', 'warned' => 'Warning sent', 'provider_suspended' => 'Provider suspended',
        'account_suspended' => 'Account suspended', 'resolved' => 'Resolved',
    ];

    /** @var User */
    private $viewer;

    public function build(User $viewer): array
    {
        $this->viewer = $viewer;
        $can = function (string ...$permissions) use ($viewer) {
            foreach ($permissions as $permission) {
                if ($viewer->hasPermission($permission)) {
                    return true;
                }
            }

            return false;
        };

        $marketplace = $can('marketplace.view');
        $categories = Category::orderBy('sort_order')->get();
        // The quick-pick places and any place in use, not every place in Uganda
        $areas = Area::active()
            ->where(function ($query) {
                $query->where('is_featured', true)
                    ->orWhereIn('id', ProviderProfile::whereNotNull('base_area_id')->select('base_area_id'))
                    ->orWhereIn('id', DB::table('provider_service_areas')->select('area_id'))
                    ->orWhereIn('id', ServiceRequest::withTrashed()->whereNotNull('area_id')->select('area_id'));
            })
            ->orderBy('sort_order')->orderBy('name')
            ->get();

        $customers = $marketplace ? $this->customers() : collect();
        $providers = $marketplace ? $this->providers() : collect();
        $jobs = $marketplace ? $this->jobs() : collect();

        return [
            'generatedAt' => now()->toIso8601String(),
            'trackedSeries' => self::TRACKED_SERIES,
            'categories' => $categories->map(function ($c) {
                return ['id' => $c->slug, 'label' => $c->name, 'description' => $c->description, 'visible' => $c->is_active, 'icon' => $c->icon, 'sortOrder' => $c->sort_order];
            })->values(),
            // Every profession, hidden ones too, for managing the catalogue
            'catalogueProfessions' => $can('marketplace.view', 'catalogue.manage') ? $this->catalogueProfessions() : [],
            'professions' => Profession::active()->with('category')->orderBy('name')->get()->map(function ($p) {
                return ['id' => $p->slug, 'label' => $p->name, 'category' => $p->category->slug];
            })->values(),
            'areas' => $areas->map(function ($a) {
                return ['slug' => $a->slug, 'name' => $a->name, 'town' => $a->town, 'district' => $a->district];
            })->values(),
            'daily' => $can('dashboard.view', 'analytics.view') ? $this->daily() : [],
            'presenceNow' => $can('dashboard.view', 'usage.view', 'activity.users') ? $this->presenceNow() : null,
            'security' => $can('security.view') ? $this->signInSecurity() : null,
            'installs' => $can('usage.view') ? $this->installs() : null,
            'push' => $can('comms.view') ? $this->pushStatus() : null,
            'core' => [
                'customers' => $customers->values(),
                'providers' => $providers->values(),
                'applicants' => $can('providers.review') ? $this->applicants()->values() : [],
                'jobs' => $jobs->values(),
                'quotations' => $marketplace ? $this->quotations()->values() : [],
                'reviews' => $can('marketplace.view', 'content.view') ? $this->reviews()->values() : [],
                // Not built yet: no bookings, payments, refunds, payouts or disputes exist
                'bookings' => [], 'transactions' => [], 'refunds' => [], 'payouts' => [], 'disputes' => [],
            ],
            'ops' => [
                'reports' => $can('trust.view') ? $this->reports()->values() : [],
                'areas' => $can('location.view') ? $this->areaRows($areas, $providers, $jobs)->values() : [],
                'coverage' => $can('location.view') ? $this->coverage($areas, $categories, $providers, $jobs)->values() : [],
                'audit' => $can('security.view') ? $this->audit()->values() : [],
                'adminUsers' => $can('admin.manage') ? $this->adminUsers()->values() : [],
                'events' => $can('activity.view') ? $this->events($can('security.view'))->values() : [],
                'pushAnnouncements' => $can('comms.view') ? $this->pushAnnouncements()->values() : [],
                'professionSuggestions' => $can('providers.review') ? $this->professionSuggestions()->values() : [],
                'verifications' => $can('documents.review') ? $this->verifications()->values() : [],
                // Invite Friends: who invited whom, the badges and who holds them
                'referrals' => $can('referrals.view') ? $this->referrals()->values() : [],
                'milestones' => $can('referrals.view') ? $this->milestones()->values() : [],
                'badgeAwards' => $can('referrals.view') ? $this->badgeAwards()->values() : [],
            ],
        ];
    }

    /** What an applicant told customers about their work, for the combined review */
    private function serviceDetails(ProviderProfile $profile): array
    {
        $application = $profile->latestApplication;

        return [
            'status' => $profile->status,
            'type' => $profile->type,
            'registrationNumber' => $profile->business_registration_number,
            'bio' => $profile->bio,
            'skills' => $profile->professions->sortByDesc(function ($p) {
                return (int) $p->pivot->is_primary;
            })->map(function ($p) {
                return ['name' => $p->name, 'main' => (bool) $p->pivot->is_primary, 'years' => $p->pivot->years_experience];
            })->values(),
            // Typed under "Other": settled in Typed Professions before approving
            'typedSkills' => $profile->professionSuggestions->where('status', 'pending')->pluck('name')->values(),
            'places' => $profile->serviceAreas->pluck('name')->values(),
            'priceFrom' => $profile->price_from, 'priceBasis' => $profile->price_basis,
            'applicationStatus' => $application ? $application->status : null,
            'applicationReason' => $application ? $application->decision_reason : null,
            'sentAt' => $application && $application->submitted_at ? $application->submitted_at->toIso8601String() : null,
        ];
    }

    private function customers(): Collection
    {
        return User::withTrashed()->orderBy('id')->get()->map(function (User $u) {
            return [
                'id' => 'C-'.$u->id, 'userId' => $u->id, 'name' => $this->label($u->name, $u->is_sample),
                'area' => null, 'phoneMasked' => $this->maskPhone($u->phone), 'emailMasked' => $this->maskEmail($u->email),
                'channel' => 'Website', 'language' => $u->locale === 'lg' ? 'Luganda' : 'English',
                'registeredAt' => $u->created_at->toIso8601String(),
                'lastActiveAt' => optional($u->last_seen_at ?: $u->updated_at)->toIso8601String(),
                'presence' => $this->presence($u->last_seen_at), 'lastSeenAt' => optional($u->last_seen_at)->toIso8601String(),
                'status' => $u->trashed() ? 'deactivated' : $u->status,
                'emailVerified' => $u->email_verified_at !== null,
                'notifications' => ['sms' => null, 'email' => true, 'push' => null],
                'bookings' => 0, 'spend' => 0, 'complaints' => 0,
                'isSample' => $u->is_sample, 'history' => [],
            ];
        });
    }

    private function providers(): Collection
    {
        return ProviderProfile::query()
            ->whereIn('status', [ProviderProfile::STATUS_APPROVED, ProviderProfile::STATUS_SUSPENDED])
            ->with(['owner', 'professions.category', 'baseArea', 'serviceAreas'])
            ->withCount(['reviews as published_reviews_count' => function ($q) {
                $q->published();
            }])
            ->withAvg(['reviews as published_reviews_avg_rating' => function ($q) {
                $q->published();
            }], 'rating')
            ->withCount('reviews')
            ->orderBy('id')
            ->get()
            ->map(function (ProviderProfile $p) {
                $reviewCount = (int) $p->published_reviews_count;
                $complaints = ProviderReport::where('provider_profile_id', $p->id)->count();

                return [
                    'id' => 'P-'.$p->id, 'publicId' => $p->id, 'profileId' => $p->id,
                    'name' => $this->label($p->display_name, $p->is_sample), 'contactName' => $p->owner->name,
                    'type' => self::PROVIDER_TYPES[$p->type] ?? $p->type,
                    'services' => $p->professions->pluck('category.slug')->unique()->values(),
                    'area' => optional($p->baseArea)->name, 'hasCoordinates' => $p->latitude !== null,
                    'serviceAreas' => $p->serviceAreas->count(),
                    'status' => $p->status === ProviderProfile::STATUS_SUSPENDED ? 'suspended' : ($p->owner->isActive() ? 'active' : $p->owner->status),
                    'registeredAt' => optional($p->approved_at ?: $p->created_at)->toIso8601String(),
                    'lastActiveAt' => optional($p->owner->last_seen_at ?: $p->updated_at)->toIso8601String(),
                    'presence' => $this->presence($p->owner->last_seen_at), 'lastSeenAt' => optional($p->owner->last_seen_at)->toIso8601String(),
                    'phoneMasked' => $this->maskPhone($p->owner->phone),
                    'checks' => ['identity' => 'not_checked', 'phone' => 'not_checked', 'tradeCertificate' => 'not_provided', 'references' => 'not_checked'],
                    'publicListing' => $p->status === ProviderProfile::STATUS_APPROVED,
                    'profileCompleteness' => $this->completeness($p),
                    'completedJobs' => 0, 'reviewCount' => $reviewCount,
                    'rating' => $reviewCount ? round((float) $p->published_reviews_avg_rating, 2) : null,
                    'complaints' => $complaints, 'earned' => 0, 'pendingPayout' => 0,
                    'verification' => $p->document_verified_at ? 'verified' : 'none',
                    'blueTick' => $p->blue_tick_awarded_at ? 'awarded' : 'not_eligible',
                    'suspensionReason' => $p->suspension_reason,
                    'isSample' => $p->is_sample, 'history' => [],
                ];
            });
    }

    private function applicants(): Collection
    {
        $statuses = [
            ProviderApplication::STATUS_PENDING_REVIEW => 'pending_review',
            ProviderApplication::STATUS_MORE_INFO_REQUIRED => 'info_requested',
            ProviderApplication::STATUS_APPROVED => 'approved',
            ProviderApplication::STATUS_REJECTED => 'rejected',
        ];

        $categoryOf = Profession::with('category')->get()->mapWithKeys(function ($p) {
            return [$p->slug => $p->category->slug];
        });

        return ProviderApplication::with(['profile.owner', 'decider'])->latest('submitted_at')->get()->map(function (ProviderApplication $a) use ($statuses, $categoryOf) {
            $snapshot = $a->snapshot;
            $owner = $a->profile->owner;

            return [
                'id' => 'A-'.$a->id, 'applicationId' => $a->id, 'profileId' => $a->provider_profile_id,
                'name' => $snapshot['display_name'] ?? $a->profile->display_name, 'contactName' => $owner->name,
                'type' => self::PROVIDER_TYPES[$snapshot['type'] ?? 'individual'] ?? 'individual',
                'services' => collect($snapshot['professions'] ?? [])->filter(function ($p) {
                    return ! empty($p['profession']);
                })->map(function ($p) use ($categoryOf) {
                    return $categoryOf[$p['profession']] ?? $p['profession'];
                })->unique()->values(),
                'professions' => collect($snapshot['professions'] ?? [])->map(function ($p) {
                    return empty($p['profession']) ? $p['name'].' (typed, not in the list yet)' : $p['name'];
                })->implode(', '),
                'area' => null, 'status' => $statuses[$a->status] ?? $a->status,
                'submittedAt' => $a->submitted_at->toIso8601String(), 'decidedAt' => optional($a->decided_at)->toIso8601String(),
                'decidedBy' => optional($a->decider)->name, 'decisionReason' => $a->decision_reason, 'internalNote' => $a->internal_note,
                'applicantNote' => $a->applicant_note, 'bio' => $snapshot['bio'] ?? null,
                'emailVerified' => $owner->email_verified_at !== null,
                'phoneMasked' => $this->maskPhone($owner->phone),
                'checks' => ['identity' => 'not_checked', 'phone' => 'not_checked', 'tradeCertificate' => 'not_provided', 'references' => 'not_checked'],
                'documents' => 'Not collected yet (document verification comes later)',
                'history' => $this->historyFor(ProviderApplication::class, $a->id),
            ];
        });
    }

    /** Submitted document verifications, without file paths */
    private function verifications(): Collection
    {
        $rules = app(DocumentRequirements::class);

        return DocumentVerification::with(['profile.owner', 'profile.professions.category', 'profile.baseArea', 'profile.serviceAreas', 'profile.professionSuggestions', 'profile.latestApplication', 'reviewer', 'documents.reviewer', 'requests.requester', 'requirementVersion.category'])
            ->whereNotNull('submitted_at')
            ->latest('submitted_at')
            ->get()
            ->map(function (DocumentVerification $v) use ($rules) {
                $profile = $v->profile;
                $version = $rules->versionFor($v);
                $specs = collect($version ? $version->documents : [])->keyBy('id');
                $main = $profile->professions->first(function ($p) {
                    return (bool) $p->pivot->is_primary;
                }) ?: $profile->professions->first();
                $documents = $v->documents->whereNotIn('status', [VerificationDocument::STATUS_REPLACED, VerificationDocument::STATUS_REMOVED]);

                return [
                    'id' => 'VA-'.$v->id, 'verificationId' => $v->id, 'providerId' => 'P-'.$profile->id,
                    'providerName' => $this->label($profile->display_name, $profile->is_sample),
                    'profession' => $main ? $main->category->slug : null, 'professionName' => $main ? $main->name : null,
                    'services' => $profile->professions->pluck('category.slug')->unique()->values(),
                    'area' => optional($profile->baseArea)->name ?: '—',
                    'phoneMasked' => $this->maskPhone($profile->owner->phone),
                    'status' => $v->status, 'reviewer' => optional($v->reviewer)->name,
                    'submittedAt' => $v->submitted_at->toIso8601String(), 'lastActionAt' => $v->updated_at->toIso8601String(),
                    'decisionReason' => $v->decision_reason,
                    // The service details sent with the documents, decided together
                    'service' => $this->serviceDetails($profile),
                    'documents' => $documents->sortBy(function ($d) {
                        return [$d->kind === VerificationDocument::KIND_NATIONAL_ID ? 0 : 1, $d->id];
                    })->map(function (VerificationDocument $d) use ($specs) {
                        $spec = $d->kind === VerificationDocument::KIND_PROFESSION ? $specs->get($d->requirement_key) : null;
                        $optional = $d->kind === VerificationDocument::KIND_SUPPORTING || ($spec && $spec['requirement'] === 'optional');

                        return [
                            'reqId' => $d->uuid, 'kind' => $d->kind, 'name' => $d->label,
                            'requirement' => $optional ? 'optional' : 'required',
                            'instructions' => $d->kind === VerificationDocument::KIND_NATIONAL_ID ? 'Front and back of the National ID'
                                : ($d->kind === VerificationDocument::KIND_REQUESTED ? 'Requested by a reviewer'
                                : ($spec ? ($spec['instructions'] ?: 'Needed for this profession') : 'Added by the provider')),
                            'expiryRequired' => $d->expires_on !== null || ($spec && $spec['expiry']['required']), 'expiresAt' => optional($d->expires_on)->toDateString(),
                            'files' => array_map(function ($f) {
                                return ['index' => $f['index'], 'side' => $f['side'], 'name' => $f['name'], 'type' => $f['type'], 'sizeKb' => $f['size_kb']];
                            }, $d->publicFiles()),
                            'status' => $d->status, 'note' => $d->review_note,
                            'reviewedBy' => optional($d->reviewer)->name, 'reviewedAt' => optional($d->reviewed_at)->toIso8601String(),
                        ];
                    })->values(),
                    // The profession rules it was sent under, and what they still need
                    'rules' => $version ? [
                        'profession' => optional($version->category)->name, 'version' => $version->version,
                        'missing' => $rules->missing($v, $documents, $version),
                    ] : null,
                    'requests' => $v->requests->map(function ($r) {
                        return ['id' => $r->id, 'label' => $r->label, 'instructions' => $r->instructions, 'status' => $r->status,
                            'requestedBy' => optional($r->requester)->name, 'requestedAt' => $r->created_at->toIso8601String(), 'fulfilledAt' => optional($r->fulfilled_at)->toIso8601String()];
                    })->values(),
                    // The application's own events and those of every document in it, in order
                    'history' => collect($this->historyFor(DocumentVerification::class, $v->id))
                        ->concat($v->documents->flatMap(function ($d) {
                            return $this->historyFor(VerificationDocument::class, $d->id);
                        }))
                        ->sortBy('at')->values()->all(),
                ];
            });
    }

    private function catalogueProfessions(): Collection
    {
        // Approved providers offering each profession
        $providers = DB::table('provider_professions')
            ->join('provider_profiles', 'provider_profiles.id', '=', 'provider_professions.provider_profile_id')
            ->where('provider_profiles.status', ProviderProfile::STATUS_APPROVED)->where('provider_profiles.is_sample', false)
            ->select('profession_id', DB::raw('count(*) as total'))->groupBy('profession_id')->pluck('total', 'profession_id');

        return Profession::with('category')->orderBy('name')->get()->map(function (Profession $p) use ($providers) {
            return [
                'id' => $p->slug, 'name' => $p->name, 'pluralName' => $p->plural_name, 'category' => $p->category->slug,
                'visible' => (bool) $p->is_active, 'categoryVisible' => (bool) $p->category->is_active,
                'providers' => (int) ($providers[$p->id] ?? 0), 'history' => $this->historyFor(Profession::class, $p->id),
            ];
        })->values();
    }

    /** Professions applicants typed under "Other" */
    private function professionSuggestions(): Collection
    {
        return ProfessionSuggestion::with(['profile.owner', 'category', 'profession', 'decider'])->latest()->get()->map(function (ProfessionSuggestion $s) {
            return [
                'id' => 'PS-'.$s->id, 'suggestionId' => $s->id, 'name' => $s->name,
                'suggestedCategory' => optional($s->category)->slug, 'main' => $s->position === 0,
                'providerId' => 'P-'.$s->provider_profile_id, 'providerName' => $this->label($s->profile->display_name, $s->profile->is_sample),
                'providerStatus' => $s->profile->status, 'yearsExperience' => $s->years_experience,
                'status' => $s->status, 'profession' => optional($s->profession)->name,
                'decisionReason' => $s->decision_reason, 'decidedBy' => optional($s->decider)->name,
                'createdAt' => $s->created_at->toIso8601String(), 'decidedAt' => optional($s->decided_at)->toIso8601String(),
                'history' => $this->historyFor(ProfessionSuggestion::class, $s->id),
            ];
        });
    }

    private function jobs(): Collection
    {
        return ServiceRequest::withTrashed()->with(['category', 'provider', 'photos'])->withCount('quotations')->latest()->get()->map(function (ServiceRequest $j) {
            return [
                'id' => 'J-'.$j->id, 'requestId' => $j->id, 'title' => $this->label($j->title, $j->is_sample), 'category' => $j->category->slug,
                'area' => $j->area_name, 'customerId' => 'C-'.$j->customer_id, 'createdAt' => $j->created_at->toIso8601String(),
                'budget' => $j->budget, 'bookingId' => null,
                'providerId' => $j->provider_profile_id ? 'P-'.$j->provider_profile_id : null,
                'kind' => $j->isPostedJob() ? 'Posted job' : 'Request to a provider',
                'matching' => $j->status === ServiceRequest::STATUS_OPEN ? ($j->quotations_count ? 'quoted' : 'searching') : 'closed',
                'status' => $j->trashed() ? 'cancelled' : $j->status,
                'failureReason' => null, 'quotes' => (int) $j->quotations_count, 'isSample' => $j->is_sample, 'history' => [],
                // Links only: the photo route checks marketplace.view again
                'photos' => $j->photos->map(function ($p) {
                    return $p->url();
                })->values()->all(),
            ];
        });
    }

    private function quotations(): Collection
    {
        return Quotation::with('request.category')->latest()->get()->map(function (Quotation $q) {
            return [
                'id' => 'Q-'.$q->id, 'jobId' => 'J-'.$q->service_request_id, 'customerId' => 'C-'.$q->request->customer_id,
                'providerId' => 'P-'.$q->provider_profile_id, 'service' => $q->request->category->slug, 'amount' => $q->amount,
                'submittedAt' => $q->created_at->toIso8601String(), 'expiresAt' => null,
                'status' => $q->status === Quotation::STATUS_PENDING ? 'submitted' : $q->status, 'bookingId' => null,
            ];
        });
    }

    private function reviews(): Collection
    {
        return Review::with('provider.professions.category')->latest('reviewed_at')->get()->map(function (Review $r) {
            return [
                'id' => 'RV-'.$r->id, 'reviewId' => $r->id, 'bookingId' => null, 'providerId' => 'P-'.$r->provider_profile_id,
                'customerId' => $r->reviewer_id ? 'C-'.$r->reviewer_id : null, 'reviewerName' => $r->reviewer_name,
                'service' => optional($r->provider->professions->first())->category->slug ?? null,
                'rating' => $r->rating, 'comment' => $r->comment, 'status' => $r->status, 'reports' => 0,
                'createdAt' => $r->reviewed_at->toIso8601String(), 'isSample' => $r->is_sample, 'history' => [],
            ];
        });
    }

    /** Reports and complaints, for the safety team: who reported, about whom, and the case so far */
    private function reports(): Collection
    {
        $statuses = [Report::STATUS_OPEN => 'new', Report::STATUS_UNDER_REVIEW => 'under_review', Report::STATUS_INFO_REQUESTED => 'info_requested',
            Report::STATUS_RESOLVED => 'resolved', Report::STATUS_CLOSED => 'closed'];

        return Report::with(['reporter', 'provider', 'subjectUser', 'serviceRequest', 'assignee', 'communityPost.media', 'communityComment', 'workItem.photos'])->latest()->get()->map(function (Report $r) use ($statuses) {
            $community = in_array($r->subject_type, Report::COMMUNITY_SUBJECTS, true);
            $about = in_array($r->subject_type, [Report::SUBJECT_PROVIDER, Report::SUBJECT_WORK], true)
                ? ['P-'.$r->provider_profile_id, optional($r->provider)->display_name ?: 'Provider']
                : ($r->subject_type === Report::SUBJECT_CUSTOMER || $community ? ['C-'.$r->subject_user_id, optional($r->subjectUser)->name ?: 'Member'] : [null, 'Something else']);

            return [
                'id' => 'RP-'.$r->id, 'reportId' => $r->id, 'reason' => $r->reason, 'type' => self::REPORT_TYPES[$r->reason] ?? $r->reason,
                'target' => $r->subject_type, 'subjectId' => $about[0], 'subjectName' => $about[1],
                // The safety team sees who reported; the reported person never does
                'reporterId' => $r->reporter_id ? 'C-'.$r->reporter_id : null, 'reporterName' => optional($r->reporter)->name ?: 'Deleted account',
                'reporterType' => $r->reporter_role === 'provider' ? 'Provider' : 'Customer',
                'jobTitle' => optional($r->serviceRequest)->title, 'jobId' => $r->service_request_id ? 'J-'.$r->service_request_id : null,
                'severity' => $r->severity, 'status' => $statuses[$r->status] ?? $r->status,
                'submittedAt' => $r->created_at->toIso8601String(), 'assignedTo' => optional($r->assignee)->name,
                'details' => $r->details, 'photos' => $r->publicAttachments(), 'evidence' => count($r->attachments ?: []),
                'question' => $r->info_request, 'reporterReply' => $r->reporter_reply,
                'outcome' => $r->outcome, 'resolution' => $r->outcome ? (self::REPORT_OUTCOMES[$r->outcome] ?? $r->outcome) : null,
                'outcomeMessage' => $r->outcome_message, 'resolvedAt' => optional($r->resolved_at)->toIso8601String(),
                'canWarn' => $r->subject_user_id !== null, 'canSuspendProvider' => $r->provider_profile_id !== null,
                // What was reported in the community, as it stands now
                'community' => $community ? $this->reportedContent($r) : null,
                // Reported work from a provider's profile, as it stands now
                'work' => $r->subject_type === Report::SUBJECT_WORK ? $this->reportedWork($r) : null,
                'notes' => [], 'history' => $this->historyFor(Report::class, $r->id),
            ];
        });
    }

    private function reportedContent(Report $r): ?array
    {
        $post = $r->communityPost;
        $comment = $r->communityComment;
        if (! $post) {
            return null;
        }
        $item = $comment ?: $post;

        return [
            'kind' => $comment ? 'comment' : 'post', 'postId' => $post->uuid, 'commentId' => $comment ? $comment->id : null,
            'text' => $item->body, 'photos' => $comment ? [] : $post->media->map(function ($m) {
                return $m->url(true);
            })->values()->all(),
            // published | removed | deleted (by its author)
            'status' => $item->trashed() ? 'deleted' : $item->status, 'removedReason' => $item->removed_reason,
        ];
    }

    private function referrals(): Collection
    {
        $flags = ['same_network_as_inviter' => 'Same network as the inviter', 'many_sign_ups_today' => 'Many sign-ups through this link today',
            'inviter_joined_today' => 'The inviter joined today'];

        return Referral::with(['referrer:id,name,status', 'referred:id,name,status,email_verified_at,created_at', 'decider:id,name'])
            ->latest()->limit(2000)->get()->map(function (Referral $r) use ($flags) {
                return [
                    'id' => 'RF-'.$r->id, 'referralId' => $r->id, 'status' => $r->status,
                    'inviterId' => 'C-'.$r->referrer_id, 'inviterName' => optional($r->referrer)->name ?: 'Deleted account',
                    'memberId' => 'C-'.$r->referred_id, 'memberName' => optional($r->referred)->name ?: 'Deleted account',
                    'emailConfirmed' => (bool) optional($r->referred)->email_verified_at,
                    'memberActive' => optional($r->referred)->status === User::STATUS_ACTIVE,
                    'flags' => collect($r->risk_flags ?: [])->map(function ($f) use ($flags) {
                        return $flags[$f] ?? $f;
                    })->values(),
                    'flagged' => ! empty($r->risk_flags),
                    'linkOpenedAt' => optional($r->link_opened_at)->toIso8601String(), 'joinedAt' => $r->created_at->toIso8601String(),
                    'qualifiedAt' => optional($r->qualified_at)->toIso8601String(), 'decidedAt' => optional($r->decided_at)->toIso8601String(),
                    'decidedBy' => optional($r->decider)->name, 'reason' => $r->decision_reason,
                    'history' => $this->historyFor(Referral::class, $r->id),
                ];
            });
    }

    private function milestones(): Collection
    {
        $holders = UserBadge::held()->selectRaw('recognition_milestone_id, COUNT(*) AS n')->groupBy('recognition_milestone_id')->pluck('n', 'recognition_milestone_id');

        return RecognitionMilestone::orderBy('threshold')->get()->map(function (RecognitionMilestone $m) use ($holders) {
            return ['id' => 'BM-'.$m->id, 'milestoneId' => $m->id, 'key' => $m->key, 'name' => $m->name, 'description' => $m->description,
                'threshold' => $m->threshold, 'icon' => $m->icon, 'status' => $m->is_active ? 'active' : 'inactive', 'holders' => (int) ($holders[$m->id] ?? 0),
                'history' => $this->historyFor(RecognitionMilestone::class, $m->id)];
        });
    }

    private function badgeAwards(): Collection
    {
        return UserBadge::with(['milestone', 'user:id,name'])->latest('awarded_at')->limit(2000)->get()->map(function (UserBadge $b) {
            return ['id' => 'BA-'.$b->id, 'memberId' => 'C-'.$b->user_id, 'memberName' => optional($b->user)->name ?: 'Deleted account',
                'badge' => optional($b->milestone)->name, 'status' => $b->revoked_at ? 'revoked' : 'held',
                'awardedAt' => $b->awarded_at->toIso8601String(), 'revokedAt' => optional($b->revoked_at)->toIso8601String(), 'reason' => $b->revoked_reason];
        });
    }

    private function reportedWork(Report $r): ?array
    {
        $item = $r->workItem;
        if (! $item) {
            return null;
        }

        return [
            'id' => $item->uuid, 'providerId' => $item->provider_profile_id, 'title' => $item->title, 'text' => $item->description,
            'photos' => $item->photos->map(function ($p) {
                return $p->url(true);
            })->values()->all(),
            // published | removed | deleted (by the provider)
            'status' => $item->trashed() ? 'deleted' : $item->status, 'removedReason' => $item->removed_reason,
        ];
    }

    private function areaRows(Collection $areas, Collection $providers, Collection $jobs): Collection
    {
        $since = now()->subDays(30);

        return $areas->where('is_whole_town', false)->map(function (Area $a) use ($providers, $jobs, $since) {
            $here = $providers->where('area', $a->name)->where('status', 'active');

            return [
                'id' => $a->name, 'name' => $a->name, 'town' => $a->town, 'providers' => $here->count(),
                'missingCoordinates' => $here->where('hasCoordinates', false)->count(), 'customers' => 0,
                'jobs30' => $jobs->where('area', $a->name)->filter(function ($j) use ($since) {
                    return Carbon::parse($j['createdAt'])->gte($since);
                })->count(),
                // Search tracking isn't connected yet
                'searches30' => 0, 'noMatchRate' => 0, 'expandedRate' => 0, 'nearbyMatchRate' => 0, 'history' => [],
            ];
        });
    }

    private function coverage(Collection $areas, Collection $categories, Collection $providers, Collection $jobs): Collection
    {
        $since = now()->subDays(30);
        $rows = collect();

        foreach ($areas->where('is_whole_town', false) as $a) {
            foreach ($categories as $c) {
                $supply = $providers->filter(function ($p) use ($a, $c) {
                    return $p['status'] === 'active' && $p['area'] === $a->name && collect($p['services'])->contains($c->slug);
                })->count();
                $demand = $jobs->filter(function ($j) use ($a, $c, $since) {
                    return $j['area'] === $a->name && $j['category'] === $c->slug && Carbon::parse($j['createdAt'])->gte($since);
                })->count();
                $ratio = $supply ? $demand / $supply : null;

                $rows->push([
                    'id' => $a->name.'|'.$c->slug, 'area' => $a->name, 'town' => $a->town, 'category' => $c->slug,
                    'supply' => $supply, 'demand30' => $demand,
                    'status' => $supply === 0 && $demand > 0 ? 'gap' : ($ratio !== null && $ratio > 6 ? 'thin' : ($supply === 0 ? 'no_demand' : 'covered')),
                    'history' => [],
                ]);
            }
        }

        return $rows;
    }

    private function adminUsers(): Collection
    {
        return User::whereHas('roles')->with('roles')->orderBy('name')->get()->map(function (User $u) {
            return [
                'id' => 'U-'.$u->id, 'userId' => $u->id, 'name' => $u->name, 'emailMasked' => $this->maskEmail($u->email),
                'role' => optional($u->roles->first())->key, 'roles' => $u->roles->pluck('key')->values(),
                'status' => $u->isActive() ? 'active' : 'deactivated', 'twoFactor' => false,
                'lastSignInAt' => optional($u->last_seen_at)->toIso8601String(), 'createdAt' => $u->created_at->toIso8601String(),
                'isSelf' => $u->is($this->viewer), 'history' => [],
            ];
        });
    }

    private function audit(): Collection
    {
        return AuditLog::with(['actor:id,name', 'subject'])->latest('id')->limit(500)->get()->map(function (AuditLog $log) {
            return [
                'id' => 'AUD-'.$log->id, 'action' => $log->action, 'summary' => $this->summary($log),
                'actor' => $log->actor ? $log->actor->name : 'System or command line',
                'target' => $this->entity($log)['id'] ?? '—', 'reason' => $log->reason ?: '—',
                'at' => $log->created_at->toIso8601String(), 'ip' => $this->maskIp($log->ip_address),
                'before' => $log->before, 'after' => $log->after, 'source' => 'database', 'history' => [],
            ];
        });
    }

    private function events(bool $includeSecurity): Collection
    {
        return AuditLog::with(['actor:id,name', 'subject'])->latest('id')->limit(500)->get()
            ->map(function (AuditLog $log) {
                $category = $this->eventCategory($log->action);

                return [
                    'id' => 'EVT-'.$log->id, 'timestamp' => $log->created_at->toIso8601String(),
                    'outcome' => Str::contains($log->action, ['sign_in_failed', 'sign_in_blocked']) ? 'failure' : 'success',
                    'severity' => Str::contains($log->action, ['suspended', 'rejected', 'report_received', 'revoked', 'sign_in_failed', 'sign_in_blocked', 'removed']) ? 'warning' : 'info',
                    'correlationId' => null, 'type' => $log->action, 'category' => $category,
                    'actor' => $this->actorKind($log), 'actorName' => $log->actor ? $log->actor->name : 'System',
                    'entity' => $this->entity($log), 'summary' => $this->summary($log),
                    'details' => array_filter(['Reason' => $log->reason]),
                ];
            })
            ->filter(function ($e) use ($includeSecurity) {
                return $includeSecurity || $e['category'] !== 'security';
            });
    }

    /** Whether push is set up, and how many devices turned it on (no addresses) */
    private function pushStatus(): array
    {
        return [
            'configured' => PushNotifications::configured(),
            'subscribers' => PushSubscription::count(),
            'byPlatform' => PushSubscription::selectRaw("COALESCE(platform, 'other') as platform, COUNT(*) as total")
                ->groupBy('platform')->pluck('total', 'platform')->map(function ($n) {
                    return (int) $n;
                }),
        ];
    }

    private function pushAnnouncements(): Collection
    {
        return PushAnnouncement::with('sender:id,name')->latest('id')->limit(200)->get()->map(function (PushAnnouncement $a) {
            return [
                'id' => 'PA-'.$a->id, 'title' => $a->title, 'body' => $a->body, 'url' => $a->url, 'status' => $a->status,
                'recipients' => $a->recipients, 'delivered' => $a->delivered, 'failed' => $a->failed, 'expired' => $a->expired,
                'sentBy' => $a->sender ? $a->sender->name : 'Former administrator',
                'sentAt' => $a->created_at->toIso8601String(),
                'completedAt' => optional($a->completed_at)->toIso8601String(),
                'history' => $this->historyFor(PushAnnouncement::class, $a->id),
            ];
        });
    }

    /**
     * Web app install figures per day. A device "has the app" from the first
     * day it confirmed an install or opened WorkMate from its home-screen icon,
     * which also counts iPhones (they never confirm installs) and devices that
     * installed before tracking began.
     */
    private function installs(): array
    {
        $start = now()->startOfDay()->subDays(self::HISTORY_DAYS - 1);
        $events = AppInstallEvent::where('occurred_on', '>=', $start->toDateString())
            ->get(['device_id', 'event', 'platform', 'source', 'occurred_on']);

        $firstSeen = AppInstallEvent::whereIn('event', AppInstallEvent::ONCE_PER_DEVICE)
            ->selectRaw('device_id, MIN(occurred_on) as first_on, MIN(platform) as platform')
            ->groupBy('device_id')
            ->get();

        $days = [];
        for ($i = 0; $i < self::HISTORY_DAYS; $i++) {
            $date = $start->copy()->addDays($i)->toDateString();
            $days[$date] = [
                'date' => $date, 'installPrompts' => 0, 'installs' => 0, 'firstLaunches' => 0,
                'installsButton' => 0, 'installsBrowser' => 0, 'appAndroid' => 0, 'appIos' => 0, 'appDesktop' => 0,
            ];
        }

        $keys = ['prompt_shown' => 'installPrompts', 'installed' => 'installs', 'first_launch' => 'firstLaunches'];
        foreach ($events as $e) {
            $date = $e->occurred_on->toDateString();
            if (! isset($days[$date])) {
                continue;
            }
            $days[$date][$keys[$e->event]]++;
            if ($e->event === 'installed') {
                $days[$date][$e->source === 'button' ? 'installsButton' : 'installsBrowser']++;
            }
        }

        $platformKeys = ['android' => 'appAndroid', 'ios' => 'appIos', 'desktop' => 'appDesktop'];
        foreach ($firstSeen as $device) {
            $date = Carbon::parse($device->first_on)->toDateString();
            if (isset($days[$date], $platformKeys[$device->platform])) {
                $days[$date][$platformKeys[$device->platform]]++;
            }
        }

        $since = AppInstallEvent::min('occurred_on');

        return [
            'trackingSince' => $since ? Carbon::parse($since)->toDateString() : null,
            'devicesWithApp' => $firstSeen->count(),
            'daily' => array_values($days),
        ];
    }

    /** Per-day counts for the dashboard (samples excluded); untracked keys stay 0 */
    private function daily(): array
    {
        $start = now()->startOfDay()->subDays(self::HISTORY_DAYS - 1);
        $count = function ($query, string $column) use ($start) {
            $field = Str::afterLast($column, '.');

            return $query->where($column, '>=', $start)->get([$column])->groupBy(function ($row) use ($field) {
                return Carbon::parse($row->{$field})->toDateString();
            })->map->count();
        };

        $customers = $count(User::withTrashed()->where('is_sample', false), 'created_at');
        $providers = $count(ProviderProfile::where('is_sample', false)->whereNotNull('approved_at'), 'approved_at');
        $jobs = $count(ServiceRequest::withTrashed()->where('is_sample', false)->where('is_posted_job', true), 'created_at');
        $enquiries = $count(ServiceRequest::withTrashed()->where('is_sample', false)->where('is_posted_job', false), 'created_at');
        // Completed requests have no date of their own; the audit log records each one
        $completed = $count(AuditLog::where('action', 'marketplace.request_completed'), 'created_at');
        // Counted only: no message text is read. Sample accounts' messages are left out.
        $messages = $count(DB::table('messages')->join('users', 'users.id', '=', 'messages.sender_id')
            ->where('users.is_sample', false), 'messages.created_at');
        $posts = $count(CommunityPost::withTrashed(), 'created_at');
        $comments = $count(CommunityComment::withTrashed(), 'created_at');
        $follows = $count(DB::table('follows'), 'created_at');
        $reports = $count(Report::query(), 'created_at');
        $notified = $count(DB::table('user_notifications'), 'created_at');
        $active = DB::table('user_activity_days')->where('active_on', '>=', $start->toDateString())
            ->select('active_on', DB::raw('count(*) as total'))->groupBy('active_on')->pluck('total', 'active_on');
        $counters = DB::table('app_daily_counts')->where('counted_on', '>=', $start->toDateString())->get()
            ->groupBy('counted_on');

        $days = [];
        for ($i = 0; $i < self::HISTORY_DAYS; $i++) {
            $date = $start->copy()->addDays($i)->toDateString();
            $day = array_fill_keys(self::SERIES_KEYS, 0);
            $day['date'] = $date;
            $day['customersNew'] = $customers[$date] ?? 0;
            $day['providersNew'] = $providers[$date] ?? 0;
            $day['jobsCreated'] = $jobs[$date] ?? 0;
            $day['enquiries'] = $enquiries[$date] ?? 0;
            $day['jobsCompleted'] = $completed[$date] ?? 0;
            $day['messagesSent'] = $messages[$date] ?? 0;
            $day['communityPosts'] = $posts[$date] ?? 0;
            $day['communityComments'] = $comments[$date] ?? 0;
            $day['follows'] = $follows[$date] ?? 0;
            $day['reportsOpened'] = $reports[$date] ?? 0;
            $day['notificationsSent'] = $notified[$date] ?? 0;
            $day['activeUsers'] = (int) ($active[$date] ?? 0);
            foreach ($counters->get($date, collect()) as $c) {
                if (isset(self::COUNTER_SERIES[$c->metric])) {
                    $day[self::COUNTER_SERIES[$c->metric]] = (int) $c->total;
                }
            }
            $days[] = $day;
        }

        return $days;
    }

    private function eventCategory(string $action): string
    {
        if (Str::startsWith($action, ['account.password', 'roles.', 'document.viewed', 'auth.'])) {
            return 'security';
        }

        $map = ['account' => 'accounts', 'provider' => 'providers', 'marketplace' => 'marketplace', 'trust' => 'trust', 'settings' => 'admin', 'comms' => 'notifications',
            'verification' => 'providers', 'profession' => 'providers', 'community' => 'community', 'admin' => 'admin', 'catalogue' => 'admin'];

        return $map[Str::before($action, '.')] ?? 'system';
    }

    private function actorKind(AuditLog $log): string
    {
        if (! $log->actor) {
            return 'System';
        }
        if (Str::startsWith($log->action, ['provider.application_', 'provider.suspended', 'provider.reinstated', 'provider.work_removed', 'provider.work_restored', 'referral.rejected', 'referral.revoked', 'badge.milestone_', 'settings.', 'roles.', 'admin.', 'profession.', 'document.viewed',
            'verification.assigned', 'verification.document_accepted', 'verification.document_sent_back', 'verification.document_requested', 'verification.info_requested',
            'verification.approved', 'verification.rejected', 'verification.reopened', 'verification.note_added',
            'trust.report_assigned', 'trust.report_info_requested', 'trust.report_note', 'trust.report_resolved', 'trust.report_photo_viewed', 'trust.warning_sent'])) {
            return 'Admin';
        }

        return Str::startsWith($log->action, ['provider.', 'marketplace.applied', 'marketplace.request_accepted', 'marketplace.request_declined', 'marketplace.request_completed', 'verification.']) ? 'Provider' : 'Customer';
    }

    /** @return array{type: string, id: string, label: string} */
    private function entity(AuditLog $log): array
    {
        $s = $log->subject;
        $id = $log->subject_id;

        switch ($log->subject_type) {
            case User::class:
                return ['type' => 'customer', 'id' => 'C-'.$id, 'label' => $s ? $s->name : 'Deleted account'];
            case ProviderProfile::class:
                return ['type' => 'provider', 'id' => 'P-'.$id, 'label' => $s ? $s->display_name : 'Provider'];
            case ProviderApplication::class:
                return ['type' => 'applicant', 'id' => 'A-'.$id, 'label' => $s ? ($s->snapshot['display_name'] ?? 'Application') : 'Application'];
            case ServiceRequest::class:
                return ['type' => 'job', 'id' => 'J-'.$id, 'label' => $s ? $s->title : 'Request'];
            case CommunityPost::class:
                return ['type' => 'post', 'id' => 'Post '.($s ? Str::limit($s->uuid, 8, '') : $id), 'label' => $s ? $this->excerpt($s->body, 'A post with photos') : 'A post',
                    'href' => $s && ! $s->trashed() && $s->status === CommunityPost::STATUS_PUBLISHED ? '/community/post/'.$s->uuid : null];
            case CommunityComment::class:
                $post = $s ? CommunityPost::withTrashed()->find($s->community_post_id) : null;

                return ['type' => 'comment', 'id' => 'Comment '.$id, 'label' => $s ? $this->excerpt($s->body, 'A comment') : 'A comment',
                    'href' => $post && $post->isVisible() ? '/community/post/'.$post->uuid.'#comments' : null];
            case ProviderWorkItem::class:
                return ['type' => 'work', 'id' => 'Work '.($s ? Str::limit($s->uuid, 8, '') : $id), 'label' => $s ? '“'.$s->title.'”' : 'Some work',
                    'href' => $s && $s->isVisible() ? '/provider/'.$s->provider_profile_id.'#work' : null];
            case Referral::class:
                return ['type' => 'referral', 'id' => 'RF-'.$id, 'label' => 'an invitation'];
            case UserBadge::class:
                return ['type' => 'badge', 'id' => 'BA-'.$id, 'label' => $s && $s->milestone ? 'the '.$s->milestone->name.' badge' : 'a badge'];
            case RecognitionMilestone::class:
                return ['type' => 'badge', 'id' => 'BM-'.$id, 'label' => $s ? '“'.$s->name.'”' : 'a badge'];
            case Quotation::class:
                return ['type' => 'quotation', 'id' => 'Q-'.$id, 'label' => 'Application for a job'];
            case ProviderReport::class:
                return ['type' => 'report', 'id' => 'RP-'.$id, 'label' => 'Report about a provider'];
            case Report::class:
                return ['type' => 'report', 'id' => 'RP-'.$id, 'label' => 'a report'];
            case AccountWarning::class:
                return ['type' => 'warning', 'id' => '—', 'label' => 'a warning'];
            case PushAnnouncement::class:
                return ['type' => 'announcement', 'id' => 'PA-'.$id, 'label' => $s ? '“'.$s->title.'”' : 'Announcement'];
            case DocumentVerification::class:
                return ['type' => 'verification', 'id' => 'VA-'.$id, 'label' => 'Document verification'];
            case VerificationDocument::class:
                return ['type' => 'verification', 'id' => $s ? 'VA-'.$s->document_verification_id : '—', 'label' => $s ? '“'.$s->label.'”' : 'A document'];
            case ProfessionSuggestion::class:
                return ['type' => 'suggestion', 'id' => 'PS-'.$id, 'label' => $s ? '“'.$s->name.'”' : 'A typed profession'];
            case Category::class:
                return ['type' => 'category', 'id' => $s ? $s->slug : '—', 'label' => $s ? '“'.$s->name.'”' : 'A category'];
            case Profession::class:
                return ['type' => 'profession', 'id' => $s ? $s->slug : '—', 'label' => $s ? '“'.$s->name.'”' : 'A profession'];
            case Review::class:
                return ['type' => 'review', 'id' => 'R-'.$id, 'label' => $s ? $s->rating.'-star review' : 'A review'];
            case PlatformSetting::class:
                return ['type' => 'setting', 'id' => 'setting:'.($s ? $s->key : $id), 'label' => $s ? $s->key : 'Setting'];
            default:
                return ['type' => 'system', 'id' => '—', 'label' => '—'];
        }
    }

    private function summary(AuditLog $log): string
    {
        $who = $log->actor ? $log->actor->name : 'The system';
        $what = $this->entity($log)['label'];
        $phrases = [
            'account.registered' => "{$what} registered",
            'account.profile_updated' => "{$who} updated their profile",
            'account.photo_changed' => "{$who} changed their profile photo",
            'account.photo_removed' => "{$who} removed their profile photo",
            'account.email_verified' => "{$what} confirmed their email address",
            'account.password_changed' => "{$who} changed their password",
            'account.password_reset' => "{$who} reset their password",
            'provider.started' => "{$who} started a provider application",
            'provider.details_updated' => "{$who} updated their provider details",
            'provider.submitted' => "{$what} sent a provider application",
            'provider.resubmitted' => "{$what} resent their provider application",
            'provider.reopened' => "{$who} started a new provider application",
            'provider.application_approved' => "{$who} approved {$what}",
            'provider.application_rejected' => "{$who} rejected {$what}",
            'provider.application_more_info_required' => "{$who} asked {$what} for more information",
            'provider.suspended' => "{$who} suspended {$what}",
            'provider.reinstated' => "{$who} reinstated {$what}",
            'marketplace.job_posted' => "Job posted: {$what}",
            'marketplace.request_sent' => "{$who} sent a request to a provider",
            'marketplace.applied' => "{$who} applied for a job",
            'marketplace.request_accepted' => "{$who} accepted a request: {$what}",
            'marketplace.request_declined' => "{$who} declined a request: {$what}",
            'marketplace.request_completed' => "{$who} marked a request as done: {$what}",
            'marketplace.review_posted' => "{$who} rated a provider",
            'marketplace.hired' => "{$who} hired a provider for {$what}",
            'trust.report_received' => 'A report was received',
            'trust.report_reply' => "{$who} answered the safety team’s question",
            'trust.report_assigned' => "{$who} took the report",
            'trust.report_info_requested' => "{$who} asked the reporter for more information",
            'trust.report_note' => "{$who} added a note",
            'trust.report_resolved' => "{$who} resolved the report",
            'trust.report_photo_viewed' => "{$who} opened an evidence photo",
            'trust.warning_sent' => "{$who} sent {$what} a warning",
            'roles.granted' => "{$who} gave {$what} an admin role",
            'roles.revoked' => "{$who} removed an admin role from {$what}",
            'settings.updated' => "{$who} changed {$what}",
            'admin.account_suspended' => "{$who} suspended {$what}",
            'admin.account_reactivated' => "{$who} reactivated {$what}",
            'admin.review_status_changed' => "{$who} changed a review’s visibility",
            'admin.report_status_changed' => "{$who} updated a report",
            'comms.push_announcement_sent' => "{$who} sent a push announcement: {$what}",
            'verification.document_added' => "{$who} added a document: {$what}",
            'verification.document_replaced' => "{$who} replaced a document: {$what}",
            'verification.document_removed' => "{$who} removed a document: {$what}",
            'verification.submitted' => "{$who} sent their documents for verification",
            'verification.resubmitted' => "{$who} resent their documents for verification",
            'verification.assigned' => "{$who} took the verification for review",
            'verification.document_accepted' => "{$who} accepted {$what}",
            'verification.document_sent_back' => "{$who} sent back {$what} for a replacement",
            'verification.document_requested' => "{$who} asked for another document",
            'verification.info_requested' => "{$who} asked for more information",
            'verification.approved' => "{$who} approved the documents",
            'verification.rejected' => "{$who} rejected the documents",
            'verification.reopened' => "{$who} reopened the verification",
            'verification.note_added' => "{$who} added a reviewer note",
            'document.viewed' => "{$who} opened {$what}",
            'profession.created' => "{$who} added the profession {$what}",
            'profession.suggestion_added' => "{$who} added a typed profession to the list",
            'profession.suggestion_matched' => "{$who} matched a typed profession to the list",
            'profession.suggestion_declined' => "{$who} declined a typed profession",
            'verification.answers_changed' => "{$who} answered the questions about their documents",
            'verification.requirements_published' => "{$who} published new document requirements",
            'community.post_published' => "{$who} posted in the community: {$what}",
            'community.post_edited' => "{$who} edited their post {$what}",
            'community.post_deleted' => "{$who} deleted their post {$what}",
            'community.post_removed' => "{$who} removed a post from the community: {$what}",
            'community.post_restored' => "{$who} restored a post: {$what}",
            'community.comment_deleted' => "{$who} deleted a comment: {$what}",
            'community.comment_removed' => "{$who} removed a comment: {$what}",
            'referral.registered' => "{$who} joined through an invitation",
            'referral.held_for_review' => 'An invitation was held for a review',
            'referral.qualified' => "{$who} counted {$what}",
            'referral.rejected' => "{$who} rejected {$what}",
            'referral.revoked' => "{$who} revoked {$what}",
            'badge.awarded' => "{$who} earned {$what}",
            'badge.revoked' => "{$what} was taken back",
            'badge.milestone_created' => "{$who} added the badge {$what}",
            'badge.milestone_updated' => "{$who} changed the badge {$what}",
            'provider.work_added' => "{$who} added work to their profile: {$what}",
            'provider.work_edited' => "{$who} edited their work {$what}",
            'provider.work_deleted' => "{$who} deleted their work {$what}",
            'provider.work_removed' => "{$who} removed work from a profile: {$what}",
            'provider.work_restored' => "{$who} restored work: {$what}",
            'provider.cover_changed' => "{$who} changed their cover photo",
            'provider.cover_removed' => "{$who} removed their cover photo",
            'catalogue.category_created' => "{$who} added the category {$what} (hidden until shown)",
            'catalogue.category_updated' => "{$who} edited the category {$what}",
            'catalogue.category_shown' => "{$who} showed the category {$what} to customers",
            'catalogue.category_hidden' => "{$who} hid the category {$what} from customers",
            'catalogue.category_moved' => "{$who} moved the category {$what}",
            'catalogue.profession_updated' => "{$who} edited the profession {$what}",
            'catalogue.profession_shown' => "{$who} showed the profession {$what} to customers",
            'catalogue.profession_hidden' => "{$who} hid the profession {$what} from customers",
            'auth.signed_in' => "{$who} signed in",
            'auth.sign_in_failed' => 'A sign-in failed'.($log->subject_id ? " for {$what}" : ' (no such account)'),
            'auth.sign_in_blocked' => "{$who} tried to sign in to an account that isn’t active",
        ];

        return $phrases[$log->action] ?? Str::headline(str_replace('.', ' ', $log->action));
    }

    private function historyFor(string $type, int $id): array
    {
        return AuditLog::with('actor:id,name')
            ->where('subject_type', $type)->where('subject_id', $id)
            ->orderBy('id')->get()
            ->map(function (AuditLog $log) {
                return [
                    'at' => $log->created_at->toIso8601String(), 'actor' => $log->actor ? $log->actor->name : 'System',
                    'label' => $this->summary($log), 'reason' => $log->reason, 'detail' => null,
                ];
            })->all();
    }

    private function completeness(ProviderProfile $p): int
    {
        $checks = [$p->bio, $p->base_area_id, $p->price_from, $p->skills, $p->availability_status, $p->serviceAreas->isNotEmpty() ?: null, $p->owner->avatar_path];
        $filled = count(array_filter($checks, function ($v) {
            return ! empty($v);
        }));

        return (int) round(30 + 70 * $filled / count($checks));
    }

    /**
     * online | recently_active | offline | unknown, from the last check-in and the
     * presence windows in Admin → Settings. Online isn't the same as
     * available for work.
     */
    private function presence($lastSeen): string
    {
        if (! $lastSeen) {
            return 'unknown';
        }
        $settings = app(PlatformSettings::class);
        $minutes = $lastSeen->diffInSeconds(now()) / 60;
        if ($minutes <= $settings->get('presence.online_minutes')) {
            return 'online';
        }

        return $minutes <= $settings->get('presence.recent_minutes') ? 'recently_active' : 'offline';
    }

    private function excerpt(?string $text, string $empty): string
    {
        $text = trim((string) $text);

        return $text === '' ? $empty : '“'.Str::limit(preg_replace('/\s+/', ' ', $text), 60).'”';
    }

    /** Who is on WorkMate right now, and how many people used it today */
    private function presenceNow(): array
    {
        $since = now()->subMinutes((int) app(PlatformSettings::class)->get('presence.online_minutes'));
        $online = User::where('is_sample', false)->where('last_seen_at', '>=', $since);

        return [
            'onlineNow' => (clone $online)->count(),
            'onlineProviders' => (clone $online)->whereHas('providerProfile', function ($p) {
                $p->where('status', ProviderProfile::STATUS_APPROVED);
            })->count(),
            'activeToday' => DB::table('user_activity_days')->where('active_on', now()->toDateString())->count(),
        ];
    }

    /** Sign-ins in the last 24 hours, and how many failed */
    private function signInSecurity(): array
    {
        $since = now()->subDay();
        $failed = AuditLog::where('action', 'auth.sign_in_failed')->where('created_at', '>=', $since);

        return [
            'signIns24h' => AuditLog::where('action', 'auth.signed_in')->where('created_at', '>=', $since)->count(),
            'failedSignIns24h' => (clone $failed)->count(),
            // Several failures against one existing account suggest someone guessing its password
            'accountsTargeted24h' => (clone $failed)->whereNotNull('subject_id')->select('subject_id')
                ->groupBy('subject_id')->havingRaw('count(*) >= 5')->get()->count(),
        ];
    }

    private function label(string $name, bool $isSample): string
    {
        return $isSample ? "{$name} (sample)" : $name;
    }

    private function maskPhone(?string $phone): string
    {
        return $phone ? substr($phone, 0, 5).' ••• '.substr($phone, -3) : 'Not given';
    }

    private function maskEmail(string $email): string
    {
        [$local, $domain] = array_pad(explode('@', $email, 2), 2, '');

        return mb_substr($local, 0, 1).'•••@'.$domain;
    }

    private function maskIp(?string $ip): string
    {
        if (! $ip) {
            return '—';
        }
        $parts = explode('.', $ip);

        return count($parts) === 4 ? "{$parts[0]}.{$parts[1]}.{$parts[2]}.•••" : 'IPv6 •••';
    }
}
