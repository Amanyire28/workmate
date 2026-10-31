<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\JobResource;
use App\Models\ProviderProfile;
use App\Models\ServiceRequest;
use App\Services\Marketplace;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class JobController extends Controller
{
    /** @var Marketplace */
    private $marketplace;

    public function __construct(Marketplace $marketplace)
    {
        $this->marketplace = $marketplace;
    }

    /** Open jobs anyone can apply to, newest first */
    public function index()
    {
        $jobs = ServiceRequest::openJobs()
            ->with(['category', 'area'])
            ->withCount('quotations')
            ->latest()
            ->limit(200)
            ->get();

        return JobResource::collection($jobs);
    }

    /** An open job, or any request the signed-in customer made */
    public function show(Request $request, int $id): JobResource
    {
        $job = ServiceRequest::with(['category', 'area', 'provider'])->withCount('quotations')->findOrFail($id);
        $user = $request->user('sanctum');

        $visible = ($job->isPostedJob() && $job->status === ServiceRequest::STATUS_OPEN)
            || ($user && $job->customer_id === (int) $user->getKey());
        abort_unless($visible, 404);

        return new JobResource($job);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'category' => ['required', 'string', 'exists:categories,slug'],
            'title' => ['required', 'string', 'max:150'],
            'description' => ['nullable', 'string', 'max:2000'],
            'area' => ['required', 'string', 'exists:areas,slug'],
            'preferred_time' => ['nullable', 'string', 'max:100'],
            'workers_needed' => ['required', 'integer', 'min:1', 'max:50'],
            'budget' => ['nullable', 'integer', 'min:1000', 'max:100000000'],
            'budget_basis' => ['nullable', 'in:fixed,total,per person,per day,per hour,negotiable'],
        ], [
            'budget.min' => 'Enter a budget of at least UGX 1,000, or leave it empty.',
        ]);

        $job = $this->marketplace->postJob($request->user(), $data);

        return (new JobResource($job->load('category', 'area')))->response()->setStatusCode(201);
    }

    public function apply(Request $request, int $id): JsonResponse
    {
        $job = ServiceRequest::findOrFail($id);

        $data = $request->validate([
            'message' => ['required', 'string', 'min:20', 'max:2000'],
            'start_time' => ['required', 'string', 'max:60'],
            'amount' => ['nullable', 'integer', 'min:1000', 'max:100000000'],
        ]);

        $quotation = $this->marketplace->apply($request->user(), $job, $data);

        return response()->json(['data' => ['id' => $quotation->id, 'status' => $quotation->status]], 201);
    }

    public function requestProvider(Request $request, int $providerId): JsonResponse
    {
        $provider = ProviderProfile::publiclyListed()->findOrFail($providerId);

        $data = $request->validate([
            'category' => ['required', 'string'],
            'description' => ['required', 'string', 'min:15', 'max:2000'],
            'area' => ['required', 'string', 'exists:areas,slug'],
            'preferred_time' => ['required', 'string', 'max:100'],
            'budget' => ['nullable', 'integer', 'min:1000', 'max:100000000'],
        ]);

        $serviceRequest = $this->marketplace->requestProvider($request->user(), $provider, $data);

        return (new JobResource($serviceRequest->load('category', 'area', 'provider')))->response()->setStatusCode(201);
    }
}
