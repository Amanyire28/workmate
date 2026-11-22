<?php

namespace App\Http\Controllers\Api\V1;

use App\Exceptions\BusinessRuleException;
use App\Http\Controllers\Controller;
use App\Models\Conversation;
use App\Models\ProviderProfile;
use App\Models\ServiceRequest;
use App\Services\Marketplace;
use App\Services\PriceAgreements;
use App\Services\Pricing;
use App\Services\RequestDetails;
use App\Services\Messaging;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Requests customers sent to the signed-in provider's business. Only
 * members of that business see them; anyone else gets "not found".
 */
class ReceivedRequestController extends Controller
{
    /** @var Marketplace */
    private $marketplace;

    /** @var array<int, bool> whether the signed-in user may speak for each provider business on money matters */
    private $canManage = [];

    public function __construct(Marketplace $marketplace)
    {
        $this->marketplace = $marketplace;
    }

    public function index(Request $request): JsonResponse
    {
        $requests = ServiceRequest::receivedBy($request->user())
            ->with(['category', 'area', 'customer:id,name,avatar_path', 'photos', 'agreedAgreement', 'openAgreement', 'latestAgreement'])
            ->latest()
            ->limit(200)
            ->get();

        // Existing chats with these customers, to open them straight away
        $chats = Conversation::whereIn('provider_profile_id', $requests->pluck('provider_profile_id')->unique())
            ->whereIn('customer_id', $requests->pluck('customer_id')->unique())
            ->get(['id', 'customer_id', 'provider_profile_id'])
            ->keyBy(function ($c) {
                return $c->provider_profile_id.'-'.$c->customer_id;
            });

        return response()->json(['data' => $requests->map(function (ServiceRequest $r) use ($chats) {
            return $this->present($r, optional($chats->get($r->provider_profile_id.'-'.$r->customer_id))->id);
        })->values()]);
    }

    /** Accept; for a booking, optionally say how long it will take */
    public function accept(Request $request, int $id): JsonResponse
    {
        $data = $request->validate(['duration_minutes' => ['nullable', 'integer', 'in:30,60,90,120,180,240,300,360,480,600,720']]);

        return $this->done($this->marketplace->acceptRequest($request->user(), $this->find($id), $data['duration_minutes'] ?? null));
    }

    /**
     * "I need to see the job before I can price it" (or take that back). The business owner or a manager
     * only; it records a flag and a note and prices nothing. Doing the same again changes nothing.
     */
    public function inspection(Request $request, Pricing $pricing, int $id): JsonResponse
    {
        $data = $request->validate([
            'required' => ['required', 'boolean'],
            'note' => ['nullable', 'string', 'max:300'],
        ], ['required.required' => 'Say whether you need to inspect the job first.']);

        return $this->done($pricing->setInspection($request->user(), $this->find($id), (bool) $data['required'], $data['note'] ?? null));
    }

    /**
     * Proposes a price: the business owner or a manager. A new version replaces one still waiting; the customer
     * decides. While a price is agreed, this is an amendment that only counts once the customer accepts it.
     */
    public function proposePrice(Request $request, PriceAgreements $agreements, int $id): JsonResponse
    {
        $data = $request->validate([
            'amount' => ['required', 'integer', 'min:'.Pricing::MIN_AMOUNT, 'max:'.Pricing::MAX_AMOUNT],
            'scope' => ['required', 'string', 'min:'.PriceAgreements::MIN_SCOPE, 'max:'.PriceAgreements::MAX_SCOPE],
            'exclusions' => ['nullable', 'string', 'max:'.PriceAgreements::MAX_EXCLUSIONS],
            // One per try, so a retry after a dropped connection can't create a copy
            'client_token' => ['nullable', 'uuid'],
        ], [
            'amount.required' => 'Enter the price in UGX.',
            'amount.integer' => 'Enter the price in whole UGX.',
            'amount.min' => 'Enter a price of at least UGX '.number_format(Pricing::MIN_AMOUNT).'.',
            'amount.max' => 'That price is too high. Check the amount.',
            'scope.required' => 'Say what the price includes.',
            'scope.min' => 'Say a little more about what the price includes.',
        ]);
        $version = $agreements->propose($request->user(), $id, $data);

        return $this->done($this->find($id), $version->wasRecentlyCreated ? 201 : 200);
    }

    /** Takes a proposal back while the customer hasn't accepted it */
    public function withdrawPrice(Request $request, PriceAgreements $agreements, int $id, int $agreement): JsonResponse
    {
        $agreements->withdraw($request->user(), $id, $agreement);

        return $this->done($this->find($id));
    }

    /** Every version and step of this request's price, for the provider business (any member) */
    public function pricing(Request $request, PriceAgreements $agreements, Pricing $pricing, int $id): JsonResponse
    {
        if (! $pricing->enabled()) {
            throw new BusinessRuleException('pricing_unavailable', 'This isn’t available right now.', 409);
        }

        return response()->json(['data' => $agreements->history($this->find($id))]);
    }

    /** Arrived and started a confirmed booking */
    public function start(Request $request, int $id): JsonResponse
    {
        return $this->done($this->marketplace->startRequest($request->user(), $this->find($id)));
    }

    /** Suggest another time (Uganda time, "2026-11-20 10:00"); the customer accepts it or not */
    public function propose(Request $request, int $id): JsonResponse
    {
        $data = $request->validate([
            'start' => ['required', 'date_format:Y-m-d H:i'],
            'duration_minutes' => ['nullable', 'integer', 'in:30,60,90,120,180,240,300,360,480,600,720'],
        ], ['start.required' => 'Choose a date and time.', 'start.date_format' => 'Choose a date and time.']);

        return $this->done($this->marketplace->proposeTime($request->user(), $this->find($id), $data['start'], $data['duration_minutes'] ?? null));
    }

    /** Can't keep a confirmed booking: the customer sees why */
    public function cancel(Request $request, int $id): JsonResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'min:3', 'max:500']], ['reason.required' => 'Tell the customer why.']);

        return $this->done($this->marketplace->cancelBooking($request->user(), $this->find($id), trim($data['reason'])));
    }

    public function decline(Request $request, int $id): JsonResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'min:3', 'max:500']]);

        return $this->done($this->marketplace->declineRequest($request->user(), $this->find($id), $data['reason']));
    }

    public function complete(Request $request, int $id): JsonResponse
    {
        return $this->done($this->marketplace->completeRequest($request->user(), $this->find($id)));
    }

    /** Opens (or starts) the chat with the customer who sent the request */
    public function message(Request $request, Messaging $messaging, int $id): JsonResponse
    {
        $serviceRequest = ServiceRequest::receivedBy($request->user())->with(['customer', 'provider'])->findOrFail($id);
        $conversation = $messaging->conversationWith($serviceRequest->customer, $serviceRequest->provider);

        return response()->json(['data' => ['conversation_id' => $conversation->id]]);
    }

    private function canManagePricing(int $providerId): bool
    {
        if (! array_key_exists($providerId, $this->canManage)) {
            $provider = ProviderProfile::find($providerId);
            $this->canManage[$providerId] = $provider !== null && $provider->canManageFinances(request()->user());
        }

        return $this->canManage[$providerId];
    }

    private function find(int $id): ServiceRequest
    {
        // Checked again, with a lock, by the service
        return ServiceRequest::receivedBy(request()->user())->findOrFail($id);
    }

    private function done(ServiceRequest $serviceRequest, int $status = 200): JsonResponse
    {
        return response()->json(['data' => $this->present($serviceRequest->fresh(['category', 'area', 'customer', 'photos', 'agreedAgreement', 'openAgreement', 'latestAgreement']), null)], $status);
    }

    /** No email or phone number: the provider replies through WorkMate */
    private function present(ServiceRequest $r, ?int $conversationId): array
    {
        return [
            'id' => $r->id,
            'title' => $r->title,
            'category' => $r->category->slug,
            'description' => $r->description,
            // The provider business received it, so its members see the photos
            'photos' => $r->photos->map->present()->values(),
            'area' => optional($r->area)->slug,
            'area_name' => $r->area_name,
            'preferred_time' => $r->preferred_time,
            'title' => $r->title,
            'service_id' => $r->provider_service_id,
            // Made before bookings existed: the original steps only
            'is_legacy' => $r->isLegacy(),
            // When, and where (the exact point and directions only once accepted)
            'schedule' => RequestDetails::schedule($r),
            'location' => RequestDetails::forProvider($r)['location'],
            'budget' => $r->budget,
            'budget_basis' => $r->budget_basis,
        ] + Pricing::budgetFields($r) + Pricing::inspectionFields($r) + [
            // Only the business owner or a manager marks a job as needing inspection: the app shows the control to them
            'can_manage_pricing' => $this->canManagePricing((int) $r->provider_profile_id),
            // The proposed and the agreed price (null while pricing options are off)
            'pricing' => PriceAgreements::summary($r),
            'status' => $r->status,
            'received_at' => $r->created_at->toIso8601String(),
            'responded_at' => optional($r->responded_at)->toIso8601String(),
            'provider_note' => $r->provider_note,
            'customer' => [
                'name' => $r->customer->name,
                'avatar_url' => $r->customer->avatarUrl(),
            ],
            'conversation_id' => $conversationId,
            'is_sample' => $r->is_sample,
        ];
    }
}
