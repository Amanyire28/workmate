<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Conversation;
use App\Models\ServiceRequest;
use App\Services\Marketplace;
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

    public function __construct(Marketplace $marketplace)
    {
        $this->marketplace = $marketplace;
    }

    public function index(Request $request): JsonResponse
    {
        $requests = ServiceRequest::receivedBy($request->user())
            ->with(['category', 'area', 'customer:id,name,avatar_path', 'photos'])
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

    public function accept(Request $request, int $id): JsonResponse
    {
        return $this->done($this->marketplace->acceptRequest($request->user(), $this->find($id)));
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

    private function find(int $id): ServiceRequest
    {
        // Checked again, with a lock, by the service
        return ServiceRequest::receivedBy(request()->user())->findOrFail($id);
    }

    private function done(ServiceRequest $serviceRequest): JsonResponse
    {
        return response()->json(['data' => $this->present($serviceRequest->fresh(['category', 'area', 'customer', 'photos']), null)]);
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
            'budget' => $r->budget,
            'budget_basis' => $r->budget_basis,
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
