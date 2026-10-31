<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Models\ProviderReport;
use App\Models\Review;
use App\Models\User;
use App\Services\AdminActions;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class AdminActionController extends Controller
{
    /** @var AdminActions */
    private $actions;

    public function __construct(AdminActions $actions)
    {
        $this->actions = $actions;
    }

    public function suspendAccount(Request $request, User $user): JsonResponse
    {
        $user = $this->actions->setAccountStatus($user, User::STATUS_SUSPENDED, $request->user(), $this->reason($request));

        return response()->json(['data' => ['id' => $user->id, 'status' => $user->status]]);
    }

    public function reactivateAccount(Request $request, User $user): JsonResponse
    {
        $user = $this->actions->setAccountStatus($user, User::STATUS_ACTIVE, $request->user(), $this->reason($request));

        return response()->json(['data' => ['id' => $user->id, 'status' => $user->status]]);
    }

    public function updateReview(Request $request, Review $review): JsonResponse
    {
        $status = $request->validate(['status' => ['required', Rule::in(['published', 'hidden'])]])['status'];
        $review = $this->actions->setReviewStatus($review, $status, $request->user(), $this->reason($request));

        return response()->json(['data' => ['id' => $review->id, 'status' => $review->status]]);
    }

    public function updateReport(Request $request, ProviderReport $report): JsonResponse
    {
        $status = $request->validate(['status' => ['required', Rule::in(['under_review', 'confirmed', 'dismissed'])]])['status'];
        $report = $this->actions->setReportStatus($report, $status, $request->user(), $this->reason($request));

        return response()->json(['data' => ['id' => $report->id, 'status' => $report->status]]);
    }

    private function reason(Request $request): string
    {
        return $request->validate(['reason' => ['required', 'string', 'min:5', 'max:1000']])['reason'];
    }
}
