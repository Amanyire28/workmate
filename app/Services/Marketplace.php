<?php

namespace App\Services;

use App\Exceptions\BusinessRuleException;
use App\Models\Area;
use App\Models\Category;
use App\Models\ProviderProfile;
use App\Models\Quotation;
use App\Models\ServiceRequest;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Posting jobs, sending requests to providers and applying for jobs.
 * Prices and statuses are always set here, never taken from the client.
 */
class Marketplace
{
    /** @var AuditLogger */
    private $audit;

    /** @var EventNotifications */
    private $notify;

    public function __construct(AuditLogger $audit, EventNotifications $notify)
    {
        $this->audit = $audit;
        $this->notify = $notify;
    }

    /**
     * A job any provider can apply to.
     *
     * @param  array{category: string, title: string, description: ?string, area: string, preferred_time: ?string, workers_needed: int, budget: ?int, budget_basis: ?string}  $data
     */
    public function postJob(User $customer, array $data): ServiceRequest
    {
        $this->guardOpenForBusiness();

        return $this->createRequest($customer, null, $data);
    }

    /**
     * A request sent directly to one provider.
     *
     * @param  array{category: string, description: string, area: string, preferred_time: string, budget: ?int}  $data
     */
    public function requestProvider(User $customer, ProviderProfile $provider, array $data): ServiceRequest
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

        $request = $this->createRequest($customer, $provider, $data);
        $this->notify->requestReceived($request);

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

            $others = $job->quotations()->whereKeyNot($chosen->id)->where('status', Quotation::STATUS_PENDING)->get();
            $chosen->forceFill(['status' => Quotation::STATUS_ACCEPTED])->save();
            $job->quotations()->whereKeyNot($chosen->id)->where('status', Quotation::STATUS_PENDING)
                ->update(['status' => Quotation::STATUS_DECLINED, 'updated_at' => now()]);
            $job->forceFill([
                'provider_profile_id' => $chosen->provider_profile_id,
                'status' => ServiceRequest::STATUS_IN_PROGRESS,
                'responded_at' => now(),
            ])->save();

            $this->audit->record('marketplace.hired', $job, ['status' => ServiceRequest::STATUS_OPEN], [
                'status' => ServiceRequest::STATUS_IN_PROGRESS, 'provider_profile_id' => $chosen->provider_profile_id, 'quotation_id' => $chosen->id,
            ], null, $customer);

            $this->notify->hired($job, $chosen);
            foreach ($others as $other) {
                $this->notify->notChosen($job, $other);
            }

            return $job;
        });
    }

    /** The provider takes on a request sent to them */
    public function acceptRequest(User $user, ServiceRequest $request): ServiceRequest
    {
        return $this->reply($user, $request, ServiceRequest::STATUS_OPEN, ServiceRequest::STATUS_IN_PROGRESS, null, 'marketplace.request_accepted');
    }

    /** The provider can't do it; the customer sees why */
    public function declineRequest(User $user, ServiceRequest $request, string $reason): ServiceRequest
    {
        return $this->reply($user, $request, ServiceRequest::STATUS_OPEN, ServiceRequest::STATUS_CANCELLED, $reason, 'marketplace.request_declined');
    }

    /** The provider has finished the work they accepted */
    public function completeRequest(User $user, ServiceRequest $request): ServiceRequest
    {
        return $this->reply($user, $request, ServiceRequest::STATUS_IN_PROGRESS, ServiceRequest::STATUS_COMPLETED, null, 'marketplace.request_completed');
    }

    private function reply(User $user, ServiceRequest $request, string $from, string $to, ?string $note, string $action): ServiceRequest
    {
        return DB::transaction(function () use ($user, $request, $from, $to, $note, $action) {
            $request = ServiceRequest::receivedBy($user)->whereKey($request->getKey())->lockForUpdate()->first();

            // Someone else's request is reported as not found
            if (! $request) {
                throw new BusinessRuleException('not_found', 'The requested item was not found.', 404);
            }
            if ($request->provider->status !== ProviderProfile::STATUS_APPROVED) {
                throw new BusinessRuleException('provider_not_active', 'Your provider account isn’t active, so you can’t answer requests.', 403);
            }
            if ($request->status !== $from) {
                throw new BusinessRuleException('request_status_conflict', $from === ServiceRequest::STATUS_OPEN
                    ? 'This request has already been answered or cancelled.'
                    : 'Only a request you accepted can be marked as done.', 409);
            }

            $request->forceFill([
                'status' => $to,
                'responded_at' => $request->responded_at ?: now(),
                'provider_note' => $note ?? $request->provider_note,
            ])->save();

            $this->audit->record($action, $request, ['status' => $from], ['status' => $to], $note, $user);

            $notices = [
                ServiceRequest::STATUS_IN_PROGRESS => 'requestAccepted',
                ServiceRequest::STATUS_CANCELLED => 'requestDeclined',
                ServiceRequest::STATUS_COMPLETED => 'requestCompleted',
            ];
            $this->notify->{$notices[$to]}($request);

            return $request;
        });
    }

    /** Maintenance mode (Admin → Settings) pauses new work; messages and replies continue */
    private function guardOpenForBusiness(): void
    {
        if (app(PlatformSettings::class)->get('platform.maintenance_mode')) {
            throw new BusinessRuleException('maintenance', 'WorkMate is having maintenance, so new jobs, requests and applications are paused. Please try again later.', 503);
        }
    }

    private function createRequest(User $customer, ?ProviderProfile $provider, array $data): ServiceRequest
    {
        $area = Area::active()->where('slug', $data['area'])->firstOrFail();
        $category = Category::active()->where('slug', $data['category'])->firstOrFail();

        return DB::transaction(function () use ($customer, $provider, $data, $area, $category) {
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
                'provider_profile_id' => $provider ? $provider->getKey() : null,
                'is_posted_job' => $provider === null,
                'status' => ServiceRequest::STATUS_OPEN,
            ])->save();

            $this->audit->record(
                $provider ? 'marketplace.request_sent' : 'marketplace.job_posted',
                $request, [], ['category' => $category->slug, 'area' => $area->slug, 'budget' => $request->budget], null, $customer
            );

            return $request;
        });
    }
}
