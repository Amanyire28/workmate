<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Exceptions\BusinessRuleException;
use App\Http\Controllers\Controller;
use App\Http\Resources\ProviderApplicationResource;
use App\Models\ProviderApplication;
use App\Services\ProviderApplications;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class ProviderApplicationController extends Controller
{
    /** @var ProviderApplications */
    private $applications;

    public function __construct(ProviderApplications $applications)
    {
        $this->applications = $applications;
    }

    public function index(Request $request)
    {
        $filters = $request->validate([
            'status' => ['nullable', Rule::in(['pending_review', 'more_info_required', 'approved', 'rejected'])],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $applications = ProviderApplication::query()
            ->with(['profile.owner', 'decider'])
            ->when($filters['status'] ?? null, function ($query, $status) {
                $query->where('status', $status);
            })
            // Oldest waiting first, so nobody is left behind
            ->orderBy('submitted_at')
            ->paginate((int) ($filters['per_page'] ?? 25))
            ->withQueryString();

        return ProviderApplicationResource::collection($applications);
    }

    public function show(ProviderApplication $application): ProviderApplicationResource
    {
        return $this->present($application);
    }

    public function approve(Request $request, ProviderApplication $application): ProviderApplicationResource
    {
        $data = $request->validate(['internal_note' => ['nullable', 'string', 'max:2000']]);

        // Nobody is listed before their ID is checked: approve both from the verification review
        if (! $application->profile->document_verified_at) {
            throw new BusinessRuleException('id_not_verified', 'Check this applicant’s National ID first: open them in Verification → Applications and approve their details and documents together.', 409);
        }

        return $this->present($this->applications->approve($application, $request->user(), $data['internal_note'] ?? null));
    }

    public function reject(Request $request, ProviderApplication $application): ProviderApplicationResource
    {
        $data = $this->validateDecision($request);

        return $this->present($this->applications->reject($application, $request->user(), $data['reason'], $data['internal_note'] ?? null));
    }

    public function requestInformation(Request $request, ProviderApplication $application): ProviderApplicationResource
    {
        $data = $this->validateDecision($request);

        return $this->present($this->applications->requestMoreInformation($application, $request->user(), $data['reason'], $data['internal_note'] ?? null));
    }

    /** The reason is shown to the applicant; the internal note is not. */
    private function validateDecision(Request $request): array
    {
        return $request->validate([
            'reason' => ['required', 'string', 'max:1000'],
            'internal_note' => ['nullable', 'string', 'max:2000'],
        ]);
    }

    private function present(ProviderApplication $application): ProviderApplicationResource
    {
        return new ProviderApplicationResource($application->fresh()->load('profile.owner', 'decider'));
    }
}
