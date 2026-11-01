<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Models\ProfessionSuggestion;
use App\Services\ProfessionSuggestions;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** Professions applicants typed under "Other" */
class ProfessionSuggestionController extends Controller
{
    /** @var ProfessionSuggestions */
    private $suggestions;

    public function __construct(ProfessionSuggestions $suggestions)
    {
        $this->suggestions = $suggestions;
    }

    public function add(Request $request, ProfessionSuggestion $suggestion): JsonResponse
    {
        $data = $request->validate([
            'category' => ['required', 'string', Rule::exists('categories', 'slug')],
            'name' => ['required', 'string', 'min:3', 'max:60'],
            'plural_name' => ['required', 'string', 'min:3', 'max:80'],
        ]);

        $given = $this->suggestions->add($suggestion, $request->user(), $data);

        return response()->json(['data' => ['status' => ProfessionSuggestion::STATUS_ADDED, 'applicants' => $given]]);
    }

    public function match(Request $request, ProfessionSuggestion $suggestion): JsonResponse
    {
        $data = $request->validate([
            'profession' => ['required', 'string', Rule::exists('professions', 'slug')->where('is_active', true)],
            'reason' => ['nullable', 'string', 'max:255'],
        ]);

        $this->suggestions->match($suggestion, $request->user(), $data['profession'], $data['reason'] ?? null);

        return response()->json(['data' => ['status' => ProfessionSuggestion::STATUS_MATCHED]]);
    }

    public function decline(Request $request, ProfessionSuggestion $suggestion): JsonResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'min:5', 'max:255']]);

        $this->suggestions->decline($suggestion, $request->user(), $data['reason']);

        return response()->json(['data' => ['status' => ProfessionSuggestion::STATUS_DECLINED]]);
    }
}
