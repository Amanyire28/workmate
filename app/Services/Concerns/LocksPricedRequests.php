<?php

namespace App\Services\Concerns;

use App\Exceptions\BusinessRuleException;
use App\Models\ProviderProfile;
use App\Models\ServiceRequest;
use App\Models\User;

/**
 * What every price and payment action starts with: pricing options switched on, and the request locked for the
 * person acting: the owner or a manager of the provider business that received it, or the customer who sent it.
 * Anyone else gets "not found". Uses the class's own $this->pricing (App\Services\Pricing).
 */
trait LocksPricedRequests
{
    private function guardEnabled(): void
    {
        if (! $this->pricing->enabled()) {
            throw new BusinessRuleException('pricing_unavailable', 'This isn’t available right now.', 409);
        }
    }

    /** A request this provider business received, locked, for someone who may speak for it on money matters */
    private function lockForProvider(User $user, int $requestId): ServiceRequest
    {
        $request = ServiceRequest::receivedBy($user)->whereKey($requestId)->lockForUpdate()->first();
        if (! $request) {
            throw new BusinessRuleException('not_found', 'The requested item was not found.', 404);
        }
        $provider = $request->provider;
        if (! $provider || $provider->status !== ProviderProfile::STATUS_APPROVED) {
            throw new BusinessRuleException('provider_not_active', 'Your provider account isn’t active, so you can’t answer requests.', 403);
        }
        if (! $provider->canManageFinances($user)) {
            throw new BusinessRuleException('not_authorised', 'Only the business owner or a manager can do this.', 403);
        }
        $this->guardNotLegacy($request);

        return $request;
    }

    /** The customer's own request, locked; anyone else's is "not found" */
    private function lockForCustomer(User $customer, int $requestId): ServiceRequest
    {
        $request = ServiceRequest::where('customer_id', $customer->getKey())->whereKey($requestId)->lockForUpdate()->first();
        if (! $request) {
            throw new BusinessRuleException('not_found', 'The requested item was not found.', 404);
        }
        $this->guardNotLegacy($request);

        return $request;
    }

    private function guardNotLegacy(ServiceRequest $request): void
    {
        if ($request->isLegacy()) {
            throw new BusinessRuleException('legacy_request', 'This request was made before bookings existed, so it keeps its original steps.', 409);
        }
    }

    /** Trimmed, with runs of spaces collapsed and blank lines limited; line breaks are kept */
    private function text($value, int $max): string
    {
        $value = str_replace(["\r\n", "\r"], "\n", (string) $value);
        $value = preg_replace('/[ \t]+/u', ' ', $value);
        $value = preg_replace("/\n{3,}/", "\n\n", $value);

        return mb_substr(trim($value), 0, $max);
    }

    /** A short optional note (the class's MAX_NOTE), or null when empty */
    private function note(?string $note): ?string
    {
        $note = $this->text($note ?? '', self::MAX_NOTE);

        return $note === '' ? null : $note;
    }
}
