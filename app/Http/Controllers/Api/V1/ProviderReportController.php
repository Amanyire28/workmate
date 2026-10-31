<?php

namespace App\Http\Controllers\Api\V1;

use App\Exceptions\BusinessRuleException;
use App\Http\Controllers\Controller;
use App\Models\ProviderProfile;
use App\Models\ProviderReport;
use App\Services\AuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class ProviderReportController extends Controller
{
    public function store(Request $request, AuditLogger $audit, int $id): JsonResponse
    {
        $provider = ProviderProfile::publiclyListed()->findOrFail($id);
        $user = $request->user();

        $data = $request->validate([
            'reason' => ['required', Rule::in(ProviderReport::REASONS)],
            'details' => ['nullable', 'string', 'max:2000'],
        ]);

        if ($provider->hasMember($user)) {
            throw new BusinessRuleException('own_provider', 'You can’t report your own provider profile.', 422);
        }

        $report = new ProviderReport($data);
        $report->forceFill([
            'provider_profile_id' => $provider->id,
            'reporter_id' => $user->getKey(),
            'status' => 'open',
        ])->save();

        $audit->record('trust.report_received', $report, [], ['provider_profile_id' => $provider->id, 'reason' => $report->reason], null, $user);

        return response()->json(['data' => ['id' => $report->id, 'status' => $report->status]], 201);
    }
}
