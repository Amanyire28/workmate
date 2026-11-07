<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\SaveProviderDetailsRequest;
use App\Http\Resources\ProviderProfileResource;
use App\Models\LegalAcceptance;
use App\Models\ProviderProfile;
use App\Services\LegalDocuments;
use App\Services\ProviderApplications;
use App\Services\ProviderOnboarding;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** The signed-in account's own application to offer services. */
class ProviderApplicationController extends Controller
{
    /** @var ProviderApplications */
    private $applications;

    public function __construct(ProviderApplications $applications)
    {
        $this->applications = $applications;
    }

    public function show(Request $request)
    {
        $profile = $request->user()->providerProfile;

        // null means "not applied"
        return $profile ? $this->present($profile) : response()->json(['data' => null]);
    }

    public function save(SaveProviderDetailsRequest $request): ProviderProfileResource
    {
        return $this->present($this->applications->saveDetails($request->user(), $request->validated()));
    }

    public function submit(Request $request, LegalDocuments $legal): ProviderProfileResource
    {
        $agreement = $legal->providerAgreement();
        $data = $request->validate([
            'note' => ['nullable', 'string', 'max:1000'],
            // The Service Provider Agreement, when one is published
            'accept_provider_terms' => [$agreement ? 'accepted' : 'nullable'],
        ], ['accept_provider_terms.accepted' => 'Tick the box to agree to the Service Provider Agreement.']);

        $application = $this->applications->submit($request->user(), $data['note'] ?? null);
        if ($agreement) {
            $legal->accept($request->user(), [$agreement->id], LegalAcceptance::METHOD_PROVIDER_APPLICATION, $request);
        }

        return $this->present($application->profile);
    }

    /** Start earning: send the details and the documents together */
    public function send(Request $request, LegalDocuments $legal, ProviderOnboarding $onboarding): JsonResponse
    {
        $request->validate([
            'accept_provider_terms' => [$legal->providerAgreement() ? 'accepted' : 'nullable'],
        ], ['accept_provider_terms.accepted' => 'Tick the box to agree to the Service Provider Agreement.']);

        $sent = $onboarding->send($request->user(), $request);

        return response()->json(['data' => ['sent' => $sent]]);
    }

    public function reopen(Request $request): ProviderProfileResource
    {
        return $this->present($this->applications->reopen($request->user()));
    }

    private function present(ProviderProfile $profile): ProviderProfileResource
    {
        return new ProviderProfileResource($profile->fresh()->load('professions', 'professionSuggestions.category', 'latestApplication', 'baseArea', 'serviceAreas'));
    }
}
