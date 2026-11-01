<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\SaveProviderDetailsRequest;
use App\Http\Resources\ProviderProfileResource;
use App\Models\ProviderProfile;
use App\Services\ProviderApplications;
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

    public function submit(Request $request): ProviderProfileResource
    {
        $data = $request->validate(['note' => ['nullable', 'string', 'max:1000']]);

        $application = $this->applications->submit($request->user(), $data['note'] ?? null);

        return $this->present($application->profile);
    }

    public function reopen(Request $request): ProviderProfileResource
    {
        return $this->present($this->applications->reopen($request->user()));
    }

    private function present(ProviderProfile $profile): ProviderProfileResource
    {
        return new ProviderProfileResource($profile->fresh()->load('professions', 'professionSuggestions.category', 'latestApplication'));
    }
}
