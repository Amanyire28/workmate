<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Resources\ProviderProfileResource;
use App\Models\ProviderProfile;
use App\Services\ProviderApplications;
use Illuminate\Http\Request;

/** Suspends and reinstates approved providers. Each change needs a reason. */
class ProviderStandingController extends Controller
{
    /** @var ProviderApplications */
    private $applications;

    public function __construct(ProviderApplications $applications)
    {
        $this->applications = $applications;
    }

    public function suspend(Request $request, ProviderProfile $provider): ProviderProfileResource
    {
        $data = $request->validate(['reason' => ['required', 'string', 'max:1000']]);

        return $this->present($this->applications->suspend($provider, $request->user(), $data['reason']));
    }

    public function reinstate(Request $request, ProviderProfile $provider): ProviderProfileResource
    {
        $data = $request->validate(['reason' => ['required', 'string', 'max:1000']]);

        return $this->present($this->applications->reinstate($provider, $request->user(), $data['reason']));
    }

    private function present(ProviderProfile $profile): ProviderProfileResource
    {
        return new ProviderProfileResource($profile->fresh()->load('professions', 'latestApplication'));
    }
}
