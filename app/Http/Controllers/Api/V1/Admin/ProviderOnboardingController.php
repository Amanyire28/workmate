<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Models\DocumentVerification;
use App\Services\ProviderOnboarding;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * One decision on an applicant's service details and documents together,
 * from Verification → Applications (documents.review and providers.review).
 */
class ProviderOnboardingController extends Controller
{
    /** @var ProviderOnboarding */
    private $onboarding;

    public function __construct(ProviderOnboarding $onboarding)
    {
        $this->onboarding = $onboarding;
    }

    public function approve(Request $request, DocumentVerification $verification): JsonResponse
    {
        $data = $request->validate(['note' => ['nullable', 'string', 'max:2000']]);
        $this->onboarding->approve($verification, $request->user(), $data['note'] ?? null);

        return $this->done($verification);
    }

    public function requestChanges(Request $request, DocumentVerification $verification): JsonResponse
    {
        $data = $request->validate([
            'details' => ['nullable', 'string', 'min:5', 'max:1000'],
            'documents' => ['nullable', 'string', 'min:5', 'max:1000'],
        ]);
        $this->onboarding->requestChanges($verification, $request->user(), $data['details'] ?? null, $data['documents'] ?? null);

        return $this->done($verification);
    }

    public function reject(Request $request, DocumentVerification $verification): JsonResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'min:5', 'max:1000']]);
        $this->onboarding->reject($verification, $request->user(), $data['reason']);

        return $this->done($verification);
    }

    private function done(DocumentVerification $verification): JsonResponse
    {
        $verification->refresh();

        return response()->json(['data' => [
            'verification' => $verification->status,
            'provider' => $verification->profile->status,
        ]]);
    }
}
