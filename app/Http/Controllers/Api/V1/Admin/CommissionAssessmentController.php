<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Services\CommissionAssessments;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Phase B6, administrators read the recorded commission obligations (commission.view). Nothing here changes an obligation. */
class CommissionAssessmentController extends Controller
{
    /** @var CommissionAssessments */
    private $assessments;

    public function __construct(CommissionAssessments $assessments)
    {
        $this->assessments = $assessments;
    }

    public function index(Request $request): JsonResponse
    {
        $data = $request->validate(['provider' => ['nullable', 'integer', 'min:1'], 'page' => ['nullable', 'integer', 'min:1', 'max:100000']]);

        return response()->json(['data' => $this->assessments->adminList($data)]);
    }

    public function show(int $id): JsonResponse
    {
        $one = $this->assessments->adminShow($id);
        abort_unless($one, 404);

        return response()->json(['data' => $one]);
    }
}
