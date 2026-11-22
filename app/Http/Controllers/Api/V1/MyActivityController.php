<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\JobResource;
use App\Models\Quotation;
use App\Models\ServiceRequest;
use App\Exceptions\BusinessRuleException;
use App\Services\Marketplace;
use App\Services\PriceAgreements;
use App\Services\Pricing;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** The signed-in account's own requests, posted jobs and applications. */
class MyActivityController extends Controller
{
    public function requests(Request $request)
    {
        $requests = ServiceRequest::where('customer_id', $request->user()->getKey())
            ->with(['category', 'area', 'provider', 'review', 'photos', 'agreedAgreement', 'openAgreement', 'latestAgreement'])
            ->withCount('quotations')
            ->latest()
            ->limit(200)
            ->get();

        return JobResource::collection($requests);
    }

    /** Accept or turn down the time the provider suggested */
    public function answerProposal(Request $request, Marketplace $marketplace, int $id)
    {
        $data = $request->validate(['accept' => ['required', 'boolean']]);
        $updated = $marketplace->answerProposal($request->user(), $id, (bool) $data['accept']);

        return new JobResource($updated->fresh(['category', 'area', 'provider', 'review', 'photos']));
    }

    /**
     * "Accept price": the customer accepts the version they are looking at. They send its amount, and it has to
     * be the one still open; if it changed meanwhile they are asked to look again (409), never agreed silently.
     */
    public function acceptPrice(Request $request, PriceAgreements $agreements, int $id, int $agreement)
    {
        $data = $request->validate(['amount' => ['required', 'integer']], ['amount.required' => 'The price you are accepting is missing. Please look at it again.']);
        $agreements->accept($request->user(), $id, $agreement, $data['amount']);

        return $this->priceDone($id, $request);
    }

    /** "No thanks": turns the price down; nothing is agreed */
    public function rejectPrice(Request $request, PriceAgreements $agreements, int $id, int $agreement)
    {
        $data = $request->validate(['note' => ['nullable', 'string', 'max:'.PriceAgreements::MAX_NOTE]]);
        $agreements->reject($request->user(), $id, $agreement, $data['note'] ?? null);

        return $this->priceDone($id, $request);
    }

    /** "Request a change": asks for another price; an amount, if given, is only what the customer would like */
    public function requestPriceChange(Request $request, PriceAgreements $agreements, int $id, int $agreement)
    {
        $data = $request->validate([
            'note' => ['nullable', 'string', 'max:'.PriceAgreements::MAX_NOTE],
            'suggested_amount' => ['nullable', 'integer', 'min:'.Pricing::MIN_AMOUNT, 'max:'.Pricing::MAX_AMOUNT],
        ], ['suggested_amount.min' => 'Enter an amount of at least UGX '.number_format(Pricing::MIN_AMOUNT).', or leave it empty.']);
        $agreements->requestChange($request->user(), $id, $agreement, $data['note'] ?? null, $data['suggested_amount'] ?? null);

        return $this->priceDone($id, $request);
    }

    /** Every version and step of the customer's own request's price */
    public function pricing(Request $request, PriceAgreements $agreements, Pricing $pricing, int $id): JsonResponse
    {
        if (! $pricing->enabled()) {
            throw new BusinessRuleException('pricing_unavailable', 'This is not available right now.', 409);
        }
        $found = ServiceRequest::where('customer_id', $request->user()->getKey())->findOrFail($id);

        return response()->json(['data' => $agreements->history($found)]);
    }

    private function priceDone(int $id, Request $request): JobResource
    {
        $found = ServiceRequest::where('customer_id', $request->user()->getKey())->with(['category', 'area', 'provider', 'review', 'photos', 'agreedAgreement', 'openAgreement', 'latestAgreement'])->findOrFail($id);

        return new JobResource($found);
    }

    /** Cancel a request still waiting, or a confirmed booking */
    public function cancel(Request $request, Marketplace $marketplace, int $id)
    {
        $data = $request->validate(['reason' => ['nullable', 'string', 'max:300']]);
        $updated = $marketplace->cancelByCustomer($request->user(), $id, $data['reason'] ?? null);

        return new JobResource($updated->fresh(['category', 'area', 'provider', 'review', 'photos']));
    }

    public function applications(Request $request): JsonResponse
    {
        $profile = $request->user()->providerProfile;

        if (! $profile) {
            return response()->json(['data' => []]);
        }

        $applications = Quotation::where('provider_profile_id', $profile->getKey())
            ->with('request.category')
            ->latest()
            ->limit(200)
            ->get()
            ->map(function (Quotation $quotation) {
                $job = $quotation->request;

                return [
                    'id' => $quotation->id,
                    'status' => $quotation->status,
                    'message' => $quotation->message,
                    'start_time' => $quotation->start_time,
                    'amount' => $quotation->amount,
                    'amount_max' => $quotation->amount_max,
                    'estimate_type' => $quotation->estimate_type,
                    'applied_at' => $quotation->created_at->toIso8601String(),
                    'job' => [
                        'id' => $job->id,
                        'title' => $job->title,
                        'category' => $job->category->slug,
                        'area_name' => $job->area_name,
                        'budget' => $job->budget,
                        'budget_basis' => $job->budget_basis,
                        'budget_type' => $job->effectiveBudgetType(),
                        'budget_min' => $job->budget_min,
                        'budget_max' => $job->budget_max,
                        'status' => $job->trashed() ? 'cancelled' : $job->status,
                    ],
                ];
            });

        return response()->json(['data' => $applications]);
    }
}
