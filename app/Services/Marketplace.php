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

    public function __construct(AuditLogger $audit)
    {
        $this->audit = $audit;
    }

    /**
     * A job any provider can apply to.
     *
     * @param  array{category: string, title: string, description: ?string, area: string, preferred_time: ?string, workers_needed: int, budget: ?int, budget_basis: ?string}  $data
     */
    public function postJob(User $customer, array $data): ServiceRequest
    {
        return $this->createRequest($customer, null, $data);
    }

    /**
     * A request sent directly to one provider.
     *
     * @param  array{category: string, description: string, area: string, preferred_time: string, budget: ?int}  $data
     */
    public function requestProvider(User $customer, ProviderProfile $provider, array $data): ServiceRequest
    {
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

        return $this->createRequest($customer, $provider, $data);
    }

    /** @param array{message: string, start_time: ?string, amount: ?int} $data */
    public function apply(User $user, ServiceRequest $job, array $data): Quotation
    {
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

            return $quotation;
        });
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
