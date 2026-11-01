<?php

namespace App\Services;

use App\Exceptions\BusinessRuleException;
use App\Models\Review;
use App\Models\ServiceRequest;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Customers rating providers they worked with. Only the customer who sent a
 * request, once the provider has marked it done, can rate it, once. The
 * provider's rating is always worked out from published reviews.
 */
class Reviews
{
    /** @var AuditLogger */
    private $audit;

    public function __construct(AuditLogger $audit)
    {
        $this->audit = $audit;
    }

    /** Finished requests the customer hasn't been asked about yet, for the pop-up */
    public function waiting(User $customer): Collection
    {
        return $this->finished($customer)
            ->whereNull('review_prompted_at')
            ->whereDoesntHave('review')
            ->with(['category', 'provider.owner'])
            ->oldest('responded_at')
            ->limit(10)
            ->get();
    }

    /** @param array{rating: int, comment: ?string} $data */
    public function rate(User $customer, int $requestId, array $data): Review
    {
        return DB::transaction(function () use ($customer, $requestId, $data) {
            $request = $this->finished($customer)->whereKey($requestId)->lockForUpdate()->first();

            if (! $request) {
                throw new BusinessRuleException('not_reviewable', 'You can rate a provider once they’ve finished work you asked them for.', 404);
            }
            if ($request->review()->exists()) {
                throw new BusinessRuleException('already_reviewed', 'You’ve already rated this job.', 409);
            }

            $review = new Review();
            $review->forceFill([
                'provider_profile_id' => $request->provider_profile_id,
                'reviewer_id' => $customer->getKey(),
                'service_request_id' => $request->id,
                'reviewer_name' => $this->shortName($customer->name),
                'job_title' => $request->title,
                'area_name' => $request->area_name,
                'rating' => $data['rating'],
                'comment' => isset($data['comment']) && trim($data['comment']) !== '' ? trim($data['comment']) : null,
                'status' => Review::STATUS_PUBLISHED,
                'reviewed_at' => now(),
            ])->save();
            $request->forceFill(['review_prompted_at' => $request->review_prompted_at ?: now()])->save();

            $this->audit->record('marketplace.review_posted', $review, [], ['rating' => $review->rating, 'service_request_id' => $request->id], null, $customer);

            return $review;
        });
    }

    /** "Not now": the pop-up doesn't come back for this job, but they can still rate it later */
    public function later(User $customer, int $requestId): void
    {
        $request = $this->finished($customer)->whereKey($requestId)->first();

        if (! $request) {
            throw new BusinessRuleException('not_found', 'The requested item was not found.', 404);
        }

        $request->forceFill(['review_prompted_at' => $request->review_prompted_at ?: now()])->save();
    }

    private function finished(User $customer)
    {
        return ServiceRequest::where('customer_id', $customer->getKey())
            ->whereNotNull('provider_profile_id')
            ->where('status', ServiceRequest::STATUS_COMPLETED);
    }

    /** "Aisha Nakato" → "Aisha N." */
    private function shortName(string $name): string
    {
        $parts = preg_split('/\s+/', trim($name)) ?: [];
        $first = $parts[0] ?? 'Customer';
        $last = count($parts) > 1 ? mb_substr(end($parts), 0, 1).'.' : '';

        return mb_substr(trim($first.' '.$last), 0, 60);
    }
}
