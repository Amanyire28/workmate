<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\ProviderProfile;
use App\Models\Report;
use App\Services\Reports;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Reporting a provider from their profile: the original endpoint, kept for
 * app versions already installed. New screens use POST /reports.
 */
class ProviderReportController extends Controller
{
    public function store(Request $request, Reports $reports, int $id): JsonResponse
    {
        $provider = ProviderProfile::publiclyListed()->findOrFail($id);

        $data = $request->validate([
            'reason' => ['required', Rule::in(Report::REASONS[Report::SUBJECT_PROVIDER])],
            'details' => ['nullable', 'string', 'max:2000'],
        ]);

        $report = $reports->submit($request->user(), [
            'about' => Report::SUBJECT_PROVIDER,
            'provider' => $provider->id,
            'reason' => $data['reason'],
            'details' => $data['details'] ?? null,
        ]);

        return response()->json(['data' => ['id' => $report->id, 'status' => $report->status]], 201);
    }
}
