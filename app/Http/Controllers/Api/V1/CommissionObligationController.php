<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\CommissionPaymentReport;
use App\Services\CommissionAssessments;
use App\Services\CommissionSettlements;
use App\Services\PrivateFiles;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Phase B6/B7: a provider business's own commission (owner or manager). `data` is null while the system is off. */
class CommissionObligationController extends Controller
{
    public function mine(Request $request, CommissionAssessments $assessments): JsonResponse
    {
        return response()->json(['data' => $assessments->forProvider($request->user())]);
    }

    /** Reports paying WorkMate commission. A statement only: it credits nothing until finance verifies it. */
    public function report(Request $request, CommissionSettlements $settlements): JsonResponse
    {
        $data = $request->validate([
            'cycle_id' => ['nullable', 'integer', 'min:1'],
            'amount' => ['required', 'integer', 'min:' . CommissionSettlements::MIN_AMOUNT, 'max:' . CommissionSettlements::MAX_AMOUNT],
            'channel' => ['required', 'in:' . implode(',', CommissionPaymentReport::CHANNELS)],
            'reference' => ['nullable', 'string', 'max:' . CommissionSettlements::MAX_REFERENCE],
            'paid_on' => ['nullable', 'date_format:Y-m-d'],
            'note' => ['nullable', 'string', 'max:' . CommissionSettlements::MAX_NOTE],
            'client_token' => ['nullable', 'string', 'max:64'],
            'evidence' => ['nullable', 'file', 'mimes:jpg,jpeg,png,pdf', 'max:5120'],
        ], [
            'evidence.mimes' => 'Attach a photo (JPG or PNG) or a PDF.',
            'evidence.max' => 'The file can be up to 5 MB.',
        ]);
        $report = $settlements->report($request->user(), $data, $request->file('evidence'));

        return response()->json(['data' => $settlements->presentPayment($report, false)], 201);
    }

    /** The proof a provider attached, for that provider's own owner or manager only */
    public function evidence(Request $request, CommissionSettlements $settlements, PrivateFiles $files, int $id)
    {
        $report = CommissionPaymentReport::find($id);
        abort_unless($report && in_array((int) $report->provider_profile_id, $settlements->providerIdsFor($request->user()), true), 404);

        return $files->response($settlements->evidence($report));
    }
}
