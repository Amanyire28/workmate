<?php

namespace App\Services;

use App\Models\JobFinalPrice;
use App\Models\JobPaymentEvent;
use App\Models\JobPriceAgreement;
use App\Models\ServiceRequest;
use App\Models\User;

/**
 * Phase B3: the final price of a job is a SNAPSHOT of the price the customer accepted in the B2 agreement
 * workflow. It is never typed in, so nobody can raise it alone. It is locked when the job is finished or the first
 * payment is reported, and when a later amendment is accepted a new version follows it and the old one is superseded
 * (the agreed amount of every version stays on record). A job with no agreement has no final price: none is invented.
 *
 * Callers hold the request row locked and check that pricing options are on.
 */
class FinalPrices
{
    /** @var AuditLogger */
    private $audit;

    public function __construct(AuditLogger $audit)
    {
        $this->audit = $audit;
    }

    /**
     * Locks the agreed price as the final price (once; again only when the agreed price changed). Returns the
     * current final price, or null when nothing has been agreed.
     */
    public function lockIfAgreed(ServiceRequest $request, ?User $actor = null): ?JobFinalPrice
    {
        $agreed = JobPriceAgreement::where('service_request_id', $request->id)->where('agreed_slot', 1)->first();
        $current = JobFinalPrice::where('service_request_id', $request->id)->where('current_slot', 1)->lockForUpdate()->first();
        if (! $agreed) {
            return $current;
        }
        if ($current && (int) $current->agreement_id === (int) $agreed->id) {
            return $current;
        }

        if ($current) {
            $current->forceFill(['status' => JobFinalPrice::SUPERSEDED, 'current_slot' => null, 'superseded_at' => now()])->save();
            $this->record($request, 'final_price_superseded', $actor, $current->amount_ugx, 'Replaced by the amended price');
        }
        $price = new JobFinalPrice();
        $price->forceFill([
            'service_request_id' => $request->id,
            'customer_id' => $request->customer_id,
            'provider_profile_id' => $agreed->provider_profile_id,
            'agreement_id' => $agreed->id,
            'revision_no' => (int) JobFinalPrice::where('service_request_id', $request->id)->max('revision_no') + 1,
            'amount_ugx' => $agreed->amount_ugx,
            'source' => JobFinalPrice::SOURCE_AGREEMENT,
            'status' => JobFinalPrice::CURRENT,
            'current_slot' => 1,
            'created_by_user_id' => $actor ? $actor->getKey() : null,
        ])->save();
        $this->record($request, 'final_price_set', $actor, $price->amount_ugx, 'Version '.$agreed->revision_no.' of the agreed price');
        $this->audit->record('marketplace.final_price_set', $request, $current ? ['amount_ugx' => $current->amount_ugx] : [], ['amount_ugx' => $price->amount_ugx, 'agreement_id' => $agreed->id, 'revision' => $price->revision_no], null, $actor);

        return $price;
    }

    /** A new agreed price was accepted: a final price already locked moves to it as a new version */
    public function followAgreement(ServiceRequest $request, ?User $actor = null): void
    {
        if (JobFinalPrice::where('service_request_id', $request->id)->where('current_slot', 1)->exists()) {
            $this->lockIfAgreed($request, $actor);
        }
    }

    public function current(ServiceRequest $request): ?JobFinalPrice
    {
        return JobFinalPrice::where('service_request_id', $request->id)->where('current_slot', 1)->first();
    }

    public static function present(?JobFinalPrice $price): ?array
    {
        if (! $price) {
            return null;
        }

        return ['amount' => $price->amount_ugx, 'revision_no' => $price->revision_no, 'source' => $price->source, 'agreement_id' => $price->agreement_id, 'locked_at' => $price->created_at->toIso8601String()];
    }

    private function record(ServiceRequest $request, string $event, ?User $actor, ?int $amount, ?string $note): void
    {
        $row = new JobPaymentEvent();
        $row->forceFill([
            'service_request_id' => $request->id, 'payment_id' => null, 'event' => $event, 'actor_id' => $actor ? $actor->getKey() : null,
            'actor_role' => $actor ? ($actor->getKey() === (int) $request->customer_id ? 'customer' : 'provider') : 'system',
            'amount_ugx' => $amount, 'note' => $note, 'created_at' => now(),
        ])->save();
    }
}
