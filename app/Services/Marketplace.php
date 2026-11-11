<?php

namespace App\Services;

use App\Exceptions\BusinessRuleException;
use App\Models\Area;
use App\Models\Category;
use App\Models\ProviderProfile;
use App\Models\Quotation;
use App\Models\ServiceRequest;
use App\Models\ServiceRequestPhoto;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;

/**
 * Posting jobs, sending requests to providers and applying for jobs.
 * Prices and statuses are always set here, never taken from the client.
 * A new request may carry photos of the job, and a client token so that a
 * retry over a bad connection returns the first request instead of a copy.
 */
class Marketplace
{
    /** @var AuditLogger */
    private $audit;

    /** @var EventNotifications */
    private $notify;

    /** @var PhotoVariants */
    private $photos;

    /** @var JobAlerts */
    private $alerts;

    /** @var Scheduling */
    private $scheduling;

    /** @var Messaging */
    private $messaging;

    public function __construct(AuditLogger $audit, EventNotifications $notify, PhotoVariants $photos, JobAlerts $alerts, Scheduling $scheduling, Messaging $messaging)
    {
        $this->audit = $audit;
        $this->notify = $notify;
        $this->photos = $photos;
        $this->alerts = $alerts;
        $this->scheduling = $scheduling;
        $this->messaging = $messaging;
    }

    /**
     * A job any provider can apply to.
     *
     * @param  array{category: string, title: string, description: ?string, area: string, preferred_time: ?string, workers_needed: int, budget: ?int, budget_basis: ?string}  $data
     */
    /** @param UploadedFile[] $photos */
    public function postJob(User $customer, array $data, array $photos = []): ServiceRequest
    {
        $this->guardOpenForBusiness();
        $data = $this->withTiming(null, $data);

        $job = $this->createRequest($customer, null, $data, $photos);
        // Providers nearby who could take it hear about it; a retried post doesn't tell them twice
        if ($job->wasRecentlyCreated) {
            $this->alerts->jobPosted($job);
        }

        return $job;
    }

    /**
     * A request sent directly to one provider.
     *
     * @param  array{category: string, description: string, area: string, preferred_time: string, budget: ?int}  $data
     */
    public function requestProvider(User $customer, ProviderProfile $provider, array $data, array $photos = []): ServiceRequest
    {
        $this->guardOpenForBusiness();

        if ($provider->hasMember($customer)) {
            throw new BusinessRuleException('own_provider', 'You can’t send a request to your own provider profile.', 422);
        }

        if (! $provider->professions()->whereHas('category', function ($q) use ($data) {
            $q->where('slug', $data['category']);
        })->exists()) {
            throw new BusinessRuleException('service_not_offered', 'This provider doesn’t offer that service.', 422);
        }

        $category = Category::where('slug', $data['category'])->firstOrFail();
        $data['title'] = $category->name.' request';

        // A service from their list: its name becomes the title; the customer's description stays theirs
        if (! empty($data['service'])) {
            $service = $provider->services()->active()->whereKey((int) $data['service'])->first();
            if (! $service) {
                throw new BusinessRuleException('service_unavailable', 'That service isn’t on this provider’s list any more. Choose another, or describe what you need.', 422);
            }
            $data['title'] = $service->title;
            $data['provider_service_id'] = $service->id;
            // Always one of the provider's own categories
            if ($service->category_id) {
                $category = Category::whereKey($service->category_id)->firstOrFail();
                $data['category'] = $category->slug;
            }
        }

        $this->guardTakingRequests($provider, $data['timing'] ?? null);
        $data = $this->withTiming($provider, $data);

        $request = $this->createRequest($customer, $provider, $data, $photos);
        // A retried send returns the first request; the provider was told about it then
        if ($request->wasRecentlyCreated) {
            $this->notify->requestReceived($request);
        }

        return $request;
    }

    /** @param array{message: string, start_time: ?string, amount: ?int} $data */
    public function apply(User $user, ServiceRequest $job, array $data): Quotation
    {
        $this->guardOpenForBusiness();

        $provider = $user->providerProfile;

        if (! $user->isProvider() || ! $provider) {
            throw new BusinessRuleException('provider_required', 'Only approved providers can apply for jobs. Apply to offer your services from your Account page.', 403);
        }

        return DB::transaction(function () use ($user, $job, $data, $provider) {
            $job = ServiceRequest::whereKey($job->getKey())->lockForUpdate()->firstOrFail();

            if (! $job->isPostedJob() || $job->status !== ServiceRequest::STATUS_OPEN) {
                throw new BusinessRuleException('job_closed', 'This job is no longer taking applications.', 409);
            }

            if ($job->customer_id === (int) $user->getKey()) {
                throw new BusinessRuleException('own_job', 'You can’t apply for a job you posted.', 422);
            }

            if ($job->quotations()->where('provider_profile_id', $provider->getKey())->exists()) {
                throw new BusinessRuleException('already_applied', 'You have already applied for this job.', 409);
            }

            $quotation = new Quotation($data);
            $quotation->forceFill([
                'service_request_id' => $job->getKey(),
                'provider_profile_id' => $provider->getKey(),
                'status' => Quotation::STATUS_PENDING,
            ])->save();

            $this->audit->record('marketplace.applied', $quotation, [], ['service_request_id' => $job->getKey(), 'amount' => $quotation->amount], null, $user);
            $this->notify->applicationReceived($quotation);

            return $quotation;
        });
    }

    /**
     * The customer hires one of the providers who applied to their posted
     * job. The job is then theirs: it shows in their received requests, they
     * mark it done there, and the customer can rate them. Everyone else who
     * applied is told someone else was chosen.
     */
    public function hire(User $customer, int $jobId, int $quotationId): ServiceRequest
    {
        $this->guardOpenForBusiness();

        return DB::transaction(function () use ($customer, $jobId, $quotationId) {
            $job = ServiceRequest::where('customer_id', $customer->getKey())->where('is_posted_job', true)
                ->whereKey($jobId)->lockForUpdate()->first();
            if (! $job) {
                throw new BusinessRuleException('not_found', 'The requested item was not found.', 404);
            }
            if ($job->status !== ServiceRequest::STATUS_OPEN || $job->provider_profile_id) {
                throw new BusinessRuleException('job_closed', 'You’ve already hired someone for this job.', 409);
            }

            $chosen = $job->quotations()->whereKey($quotationId)->lockForUpdate()->first();
            if (! $chosen || $chosen->status !== Quotation::STATUS_PENDING) {
                throw new BusinessRuleException('application_unavailable', 'This application can’t be accepted.', 409);
            }
            if (optional($chosen->provider)->status !== ProviderProfile::STATUS_APPROVED) {
                throw new BusinessRuleException('provider_unavailable', 'This provider can’t take work on WorkMate right now. Choose someone else.', 409);
            }

            // A job with a time becomes a booking: the provider must be free then
            $booking = $this->bookingFor($job, $chosen->provider_profile_id, null, false);

            $others = $job->quotations()->whereKeyNot($chosen->id)->where('status', Quotation::STATUS_PENDING)->get();
            $chosen->forceFill(['status' => Quotation::STATUS_ACCEPTED])->save();
            $job->quotations()->whereKeyNot($chosen->id)->where('status', Quotation::STATUS_PENDING)
                ->update(['status' => Quotation::STATUS_DECLINED, 'updated_at' => now()]);
            $job->forceFill([
                'provider_profile_id' => $chosen->provider_profile_id,
                'status' => $booking ? ServiceRequest::STATUS_SCHEDULED : ServiceRequest::STATUS_IN_PROGRESS,
                'responded_at' => now(),
            ] + ($booking ?: []))->save();

            $this->audit->record('marketplace.hired', $job, ['status' => ServiceRequest::STATUS_OPEN], [
                'status' => $job->status, 'provider_profile_id' => $chosen->provider_profile_id, 'quotation_id' => $chosen->id,
            ], null, $customer);
            // A job made with the booking system now shows in their chat; older jobs get no card
            if (! $job->isLegacy()) {
                $this->messaging->postRequestCard($job);
            }

            $this->notify->hired($job, $chosen);
            foreach ($others as $other) {
                $this->notify->notChosen($job, $other);
            }

            return $job;
        });
    }

    /**
     * The provider takes on a request sent to them. A request with a time
     * (or "as soon as possible") becomes a confirmed booking, only if they're
     * free then; older requests without one start straight away as before.
     * $minutes: how long they expect it to take, if they say.
     */
    public function acceptRequest(User $user, ServiceRequest $request, ?int $minutes = null): ServiceRequest
    {
        // Older requests keep their original workflow, exactly as before
        if ($request->isLegacy()) {
            return $this->reply($user, $request, ServiceRequest::STATUS_OPEN, ServiceRequest::STATUS_IN_PROGRESS, null, 'marketplace.request_accepted');
        }

        return DB::transaction(function () use ($user, $request, $minutes) {
            // The provider first, then the request: two acceptances for overlapping times queue here
            $this->scheduling->lockProvider($request->provider_profile_id);
            $request = $this->lockReceived($user, $request);
            if ($request->status !== ServiceRequest::STATUS_OPEN) {
                throw new BusinessRuleException('request_status_conflict', 'This request has already been answered or cancelled.', 409);
            }

            $booking = $this->bookingFor($request, $request->provider_profile_id, $minutes, true);
            $request->forceFill($booking + [
                'status' => ServiceRequest::STATUS_SCHEDULED,
                'responded_at' => $request->responded_at ?: now(),
                'proposed_start_at' => null,
                'proposed_duration_minutes' => null,
            ])->save();
            $this->audit->record('marketplace.request_accepted', $request, ['status' => ServiceRequest::STATUS_OPEN], [
                'status' => ServiceRequest::STATUS_SCHEDULED, 'start' => optional($request->scheduled_start_at)->toIso8601String(), 'minutes' => $request->duration_minutes,
            ], null, $user);
            $this->notify->requestAccepted($request);

            return $request;
        });
    }

    /** The provider arrived and started a confirmed booking */
    public function startRequest(User $user, ServiceRequest $request): ServiceRequest
    {
        return $this->reply($user, $request, ServiceRequest::STATUS_SCHEDULED, ServiceRequest::STATUS_IN_PROGRESS, null, 'marketplace.request_started', ['started_at' => now()]);
    }

    /**
     * The provider suggests another time for a request (or a booking). The
     * current answer stands until the customer accepts it.
     */
    public function proposeTime(User $user, ServiceRequest $request, string $startLocal, ?int $minutes = null): ServiceRequest
    {
        $this->guardNotLegacy($request);
        $start = $this->scheduling->parseLocal($startLocal);
        $this->scheduling->guardBookable($start);

        return DB::transaction(function () use ($user, $request, $start, $minutes) {
            $this->scheduling->lockProvider($request->provider_profile_id);
            $request = $this->lockReceived($user, $request);
            if (! in_array($request->status, [ServiceRequest::STATUS_OPEN, ServiceRequest::STATUS_SCHEDULED], true)) {
                throw new BusinessRuleException('request_status_conflict', 'You can only suggest a time for a request that’s waiting or booked.', 409);
            }
            $minutes = $minutes ?: $request->duration_minutes;
            $this->scheduling->guardFree($request->provider_profile_id, $start, $this->scheduling->endOf($start, $minutes), $request->id, true);

            $request->forceFill(['proposed_start_at' => $start, 'proposed_duration_minutes' => $minutes])->save();
            $this->audit->record('marketplace.time_proposed', $request, [], ['proposed_start' => $start->toIso8601String(), 'minutes' => $minutes], null, $user);
            $this->notify->timeProposed($request);

            return $request;
        });
    }

    /** The provider can't keep a confirmed booking; the customer sees why */
    public function cancelBooking(User $user, ServiceRequest $request, string $reason): ServiceRequest
    {
        return $this->reply($user, $request, ServiceRequest::STATUS_SCHEDULED, ServiceRequest::STATUS_CANCELLED, $reason, 'marketplace.booking_cancelled', ['cancelled_by' => 'provider']);
    }

    /* ---------------- The customer's side of a booking ---------------- */

    /** The customer accepts or turns down the time the provider suggested */
    public function answerProposal(User $customer, int $requestId, bool $accept): ServiceRequest
    {
        $found = $this->ownRequest($customer, $requestId);
        $this->guardNotLegacy($found);

        return DB::transaction(function () use ($customer, $found, $accept) {
            if ($accept) {
                $this->scheduling->lockProvider($found->provider_profile_id);
            }
            $request = ServiceRequest::whereKey($found->id)->lockForUpdate()->first();
            if (! $request->proposed_start_at || ! in_array($request->status, [ServiceRequest::STATUS_OPEN, ServiceRequest::STATUS_SCHEDULED], true)) {
                throw new BusinessRuleException('no_proposal', 'There’s no suggested time waiting for you.', 409);
            }

            if (! $accept) {
                $request->forceFill(['proposed_start_at' => null, 'proposed_duration_minutes' => null])->save();
                $this->audit->record('marketplace.proposal_declined', $request, [], [], null, $customer);
                $this->notify->proposalAnswered($request, false);

                return $request;
            }

            $start = $request->proposed_start_at;
            if ($start->lt(now())) {
                throw new BusinessRuleException('time_passed', 'That suggested time has passed. Ask the provider for another.', 409);
            }
            $minutes = $request->proposed_duration_minutes ?: $request->duration_minutes;
            $end = $this->scheduling->endOf($start, $minutes);
            $this->scheduling->guardFree($request->provider_profile_id, $start, $end, $request->id, false);

            $before = $request->status;
            $request->forceFill([
                'status' => ServiceRequest::STATUS_SCHEDULED,
                'timing' => ServiceRequest::TIMING_SCHEDULED,
                'scheduled_start_at' => $start,
                'duration_minutes' => $minutes,
                'scheduled_end_at' => $end,
                'preferred_time' => $this->scheduling->label($start),
                'responded_at' => $request->responded_at ?: now(),
                'proposed_start_at' => null,
                'proposed_duration_minutes' => null,
            ])->save();
            $this->audit->record('marketplace.proposal_accepted', $request, ['status' => $before], ['status' => ServiceRequest::STATUS_SCHEDULED, 'start' => $start->toIso8601String()], null, $customer);
            $this->notify->proposalAnswered($request, true);

            return $request;
        });
    }

    /** The customer cancels a request still waiting, or a confirmed booking; the time is free again */
    public function cancelByCustomer(User $customer, int $requestId, ?string $reason = null): ServiceRequest
    {
        $found = $this->ownRequest($customer, $requestId);
        $this->guardNotLegacy($found);

        return DB::transaction(function () use ($customer, $found, $reason) {
            $request = ServiceRequest::whereKey($found->id)->lockForUpdate()->first();
            $cancellable = $request->status === ServiceRequest::STATUS_SCHEDULED
                || ($request->status === ServiceRequest::STATUS_OPEN && $request->provider_profile_id);
            if (! $cancellable) {
                throw new BusinessRuleException('request_status_conflict', 'This request can’t be cancelled now.', 409);
            }
            $before = $request->status;
            $request->forceFill(['status' => ServiceRequest::STATUS_CANCELLED, 'cancelled_by' => 'customer', 'proposed_start_at' => null, 'proposed_duration_minutes' => null])->save();
            $this->audit->record('marketplace.request_cancelled', $request, ['status' => $before], ['status' => ServiceRequest::STATUS_CANCELLED], $this->text($reason, 300), $customer);
            $this->notify->requestCancelled($request, 'customer');

            return $request;
        });
    }

    /** The provider can't do it; the customer sees why */
    public function declineRequest(User $user, ServiceRequest $request, string $reason): ServiceRequest
    {
        return $this->reply($user, $request, ServiceRequest::STATUS_OPEN, ServiceRequest::STATUS_CANCELLED, $reason, 'marketplace.request_declined');
    }

    /** The provider has finished the work they accepted (started or not) */
    public function completeRequest(User $user, ServiceRequest $request): ServiceRequest
    {
        return $this->reply($user, $request, [ServiceRequest::STATUS_IN_PROGRESS, ServiceRequest::STATUS_SCHEDULED], ServiceRequest::STATUS_COMPLETED, null, 'marketplace.request_completed');
    }

    /** @param string|string[] $from the status(es) the request must be in */
    private function reply(User $user, ServiceRequest $request, $from, string $to, ?string $note, string $action, array $extra = []): ServiceRequest
    {
        $from = (array) $from;

        return DB::transaction(function () use ($user, $request, $from, $to, $note, $action, $extra) {
            $request = $this->lockReceived($user, $request);
            if (! in_array($request->status, $from, true)) {
                throw new BusinessRuleException('request_status_conflict', in_array(ServiceRequest::STATUS_OPEN, $from, true)
                    ? 'This request has already been answered or cancelled.'
                    : ($to === ServiceRequest::STATUS_COMPLETED ? 'Only a request you accepted can be marked as done.' : 'This booking has already changed. Reload to see it.'), 409);
            }

            $before = $request->status;
            $request->forceFill([
                'status' => $to,
                'responded_at' => $request->responded_at ?: now(),
                'provider_note' => $note ?? $request->provider_note,
            ] + $extra)->save();

            $this->audit->record($action, $request, ['status' => $before], ['status' => $to], $note, $user);

            $notices = [
                'marketplace.request_accepted' => 'requestAccepted',
                'marketplace.request_declined' => 'requestDeclined',
                'marketplace.request_completed' => 'requestCompleted',
                'marketplace.request_started' => 'requestStarted',
            ];
            if ($action === 'marketplace.booking_cancelled') {
                $this->notify->requestCancelled($request, 'provider');
            } else {
                $this->notify->{$notices[$action]}($request);
            }

            return $request;
        });
    }

    /** A request this provider business received, locked; someone else's is "not found" */
    private function lockReceived(User $user, ServiceRequest $request): ServiceRequest
    {
        $request = ServiceRequest::receivedBy($user)->whereKey($request->getKey())->lockForUpdate()->first();
        if (! $request) {
            throw new BusinessRuleException('not_found', 'The requested item was not found.', 404);
        }
        if ($request->provider->status !== ProviderProfile::STATUS_APPROVED) {
            throw new BusinessRuleException('provider_not_active', 'Your provider account isn’t active, so you can’t answer requests.', 403);
        }

        return $request;
    }

    /** The booking system's actions are for requests made with it; older ones keep their original steps */
    private function guardNotLegacy(ServiceRequest $request): void
    {
        if ($request->isLegacy()) {
            throw new BusinessRuleException('legacy_request', 'This request was made before bookings existed, so it keeps its original steps: accept or decline it, then mark it as done.', 409);
        }
    }

    /** The customer's own request that went to a provider (sent directly, or a posted job they hired for) */
    private function ownRequest(User $customer, int $id): ServiceRequest
    {
        $request = ServiceRequest::where('customer_id', $customer->getKey())->whereNotNull('provider_profile_id')->whereKey($id)->first();
        if (! $request) {
            throw new BusinessRuleException('not_found', 'The requested item was not found.', 404);
        }

        return $request;
    }

    /* ---------------- Times ---------------- */

    /**
     * The "when" of a new request: a chosen start (Uganda time), how long,
     * and the end; or "as soon as possible". A chosen time that's already
     * booked is refused politely, without saying by whom.
     */
    private function withTiming(?ProviderProfile $provider, array $data): array
    {
        $timing = $data['timing'] ?? null;
        $minutes = isset($data['duration_minutes']) ? (int) $data['duration_minutes'] : null;
        if ($timing === ServiceRequest::TIMING_SCHEDULED) {
            $start = $this->scheduling->parseLocal((string) ($data['scheduled_start'] ?? ''));
            $this->scheduling->guardBookable($start);
            $end = $this->scheduling->endOf($start, $minutes);
            if ($provider) {
                $this->scheduling->guardFree($provider->id, $start, $end, null, false, false);
            }
            $data['scheduled_start_at'] = $start;
            $data['scheduled_end_at'] = $end;
            $data['duration_minutes'] = $minutes;
            $data['preferred_time'] = $this->scheduling->label($start);
        } elseif ($timing === ServiceRequest::TIMING_ASAP) {
            $data['duration_minutes'] = $minutes;
            $data['preferred_time'] = 'As soon as possible';
        }

        return $data;
    }

    /**
     * The confirmed time for a request with a timing, checked against the
     * provider's other bookings (the caller holds the provider lock). Null
     * for an older request without a time.
     */
    private function bookingFor(ServiceRequest $request, int $providerId, ?int $minutes, bool $forProvider): ?array
    {
        // Older requests (and posted jobs) keep their original workflow
        if ($request->isLegacy()) {
            return null;
        }
        $minutes = $minutes ?: $request->duration_minutes;
        // No time given (a flexible job, or an older app): confirmed with the time still to be
        // arranged. Nothing is reserved and no time is made up; a suggested time is checked when accepted.
        if (! $request->timing) {
            return ['scheduled_start_at' => null, 'scheduled_end_at' => null, 'duration_minutes' => $minutes];
        }
        // As soon as possible: from now; otherwise the time chosen
        if ($request->timing === ServiceRequest::TIMING_ASAP) {
            $start = now();
        } else {
            $start = $request->scheduled_start_at;
            if (! $start || $start->lt(now())) {
                throw new BusinessRuleException('time_passed', $forProvider
                    ? 'The requested time has passed. Suggest another time instead.'
                    : 'The time for this job has passed. Post it again with a new date.', 409);
            }
        }
        $end = $this->scheduling->endOf($start, $minutes);
        if (! $forProvider) {
            $this->scheduling->lockProvider($providerId);
        }
        $reason = $this->scheduling->conflictReason($providerId, $start, $end, $request->id);
        if ($reason === 'legacy') {
            throw new BusinessRuleException('earlier_job_in_progress', $forProvider
                ? 'You’re still marked as working on an earlier job. Mark it as done first, or suggest a time on a later day.'
                : 'This provider is finishing another job today. Choose someone else, or post the job again for a later day.', 409);
        }
        if ($reason === 'booked') {
            throw new BusinessRuleException('time_taken', $forProvider
                ? 'You already have an appointment (or time off) at that time. Suggest another time instead.'
                : 'This provider already has an appointment at that time. Choose someone else, or post the job again for another time.', 409);
        }

        return ['scheduled_start_at' => $start, 'scheduled_end_at' => $end, 'duration_minutes' => $minutes];
    }

    /**
     * Whether the provider takes this kind of request now: nothing while
     * paused; "as soon as possible" only when they take immediate work;
     * a booking only when they take bookings.
     */
    private function guardTakingRequests(ProviderProfile $provider, ?string $timing): void
    {
        if (! $provider->accepts_immediate && ! $provider->accepts_bookings) {
            throw new BusinessRuleException('provider_paused', 'This provider isn’t accepting new requests at the moment.', 422);
        }
        if ($timing === ServiceRequest::TIMING_ASAP && ! $provider->accepts_immediate) {
            throw new BusinessRuleException('provider_not_immediate', 'This provider can’t come right away, but you can book them for later. Choose a date and time.', 422);
        }
        if ($timing === ServiceRequest::TIMING_SCHEDULED && ! $provider->accepts_bookings) {
            throw new BusinessRuleException('provider_not_booking', 'This provider isn’t taking bookings for later right now. Choose “As soon as possible” or another provider.', 422);
        }
    }

    private function text(?string $value, int $max): ?string
    {
        $value = trim(preg_replace('/\s+/u', ' ', (string) $value));

        return $value === '' ? null : mb_substr($value, 0, $max);
    }

    /** Maintenance mode (Admin → Settings) pauses new work; messages and replies continue */
    private function guardOpenForBusiness(): void
    {
        if (app(PlatformSettings::class)->get('platform.maintenance_mode')) {
            throw new BusinessRuleException('maintenance', 'WorkMate is having maintenance, so new jobs, requests and applications are paused. Please try again later.', 503);
        }
    }

    /** @param UploadedFile[] $photos */
    private function createRequest(User $customer, ?ProviderProfile $provider, array $data, array $photos = []): ServiceRequest
    {
        $token = $data['client_token'] ?? null;
        if ($token && ($sent = $this->alreadySent($customer, $token))) {
            return $sent;
        }

        $area = Area::active()->where('slug', $data['area'])->firstOrFail();
        $category = Category::active()->where('slug', $data['category'])->firstOrFail();

        // Photos are re-drawn first; if anything after fails, they're removed again
        $stored = [];
        try {
            foreach (array_slice($photos, 0, ServiceRequestPhoto::MAX) as $photo) {
                $stored[] = $this->photos->store($photo, ServiceRequestPhoto::DIRECTORY);
            }

            return $this->saveRequest($customer, $provider, $data, $area, $category, $stored);
        } catch (\Throwable $e) {
            $this->photos->discard(array_merge(array_column($stored, 'path'), array_column($stored, 'thumb_path')));
            // Two copies of the same send arrived together: the other one was saved
            if ($token && $e instanceof QueryException && ($sent = $this->alreadySent($customer, $token))) {
                return $sent;
            }
            throw $e;
        }
    }

    private function alreadySent(User $customer, string $token): ?ServiceRequest
    {
        return ServiceRequest::withTrashed()->where('customer_id', $customer->getKey())->where('client_token', $token)->first();
    }

    private function saveRequest(User $customer, ?ProviderProfile $provider, array $data, Area $area, Category $category, array $stored): ServiceRequest
    {
        return DB::transaction(function () use ($customer, $provider, $data, $area, $category, $stored) {
            $request = new ServiceRequest([
                'category_id' => $category->id,
                'title' => $data['title'],
                'description' => $data['description'] ?? null,
                'area_id' => $area->id,
                'area_name' => $area->name,
                'preferred_time' => $data['preferred_time'] ?? null,
                'workers_needed' => $data['workers_needed'] ?? 1,
                'budget' => $data['budget'] ?? null,
                'budget_basis' => isset($data['budget']) ? ($data['budget_basis'] ?? 'fixed') : null,
            ]);
            $request->forceFill([
                'customer_id' => $customer->getKey(),
                'client_token' => $data['client_token'] ?? null,
                'provider_profile_id' => $provider ? $provider->getKey() : null,
                'provider_service_id' => $data['provider_service_id'] ?? null,
                // Made with the booking system (older requests stay 1, legacy)
                'booking_version' => ServiceRequest::BOOKING_V2,
                // Where: the confirmed point (a fixed destination, never tracking), landmark and directions
                'latitude' => isset($data['latitude']) ? round((float) $data['latitude'], 6) : null,
                'longitude' => isset($data['longitude']) ? round((float) $data['longitude'], 6) : null,
                'location_accuracy_m' => isset($data['latitude'], $data['location_accuracy']) ? (int) round($data['location_accuracy']) : null,
                'landmark' => $this->text($data['landmark'] ?? null, 150),
                'directions' => $this->text($data['directions'] ?? null, 300),
                // When
                'timing' => $data['timing'] ?? null,
                'scheduled_start_at' => $data['scheduled_start_at'] ?? null,
                'duration_minutes' => $data['duration_minutes'] ?? null,
                'scheduled_end_at' => $data['scheduled_end_at'] ?? null,
                'is_posted_job' => $provider === null,
                'status' => ServiceRequest::STATUS_OPEN,
            ])->save();

            foreach ($stored as $i => $file) {
                $photo = new ServiceRequestPhoto();
                $photo->forceFill($file + ['service_request_id' => $request->id, 'position' => $i])->save();
            }

            // A request to one provider also shows in their chat, saved together with it
            if ($provider) {
                $this->messaging->postRequestCard($request);
            }

            $this->audit->record(
                $provider ? 'marketplace.request_sent' : 'marketplace.job_posted',
                $request, [], ['category' => $category->slug, 'area' => $area->slug, 'budget' => $request->budget, 'photos' => count($stored)], null, $customer
            );

            return $request;
        });
    }
}
