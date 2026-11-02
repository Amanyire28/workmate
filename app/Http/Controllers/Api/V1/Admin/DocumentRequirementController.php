<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Models\Category;
use App\Services\DocumentRequirements;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** The documents each profession needs, in published versions */
class DocumentRequirementController extends Controller
{
    /** @var DocumentRequirements */
    private $requirements;

    public function __construct(DocumentRequirements $requirements)
    {
        $this->requirements = $requirements;
    }

    /** Every profession's versions, keyed by profession, oldest first */
    public function index(): JsonResponse
    {
        return response()->json(['data' => [
            'versions' => (object) $this->requirements->overview(),
            'conditions' => array_keys(DocumentRequirements::CONDITIONS),
            'formats' => DocumentRequirements::FORMATS,
            'max_size_mb' => DocumentRequirements::MAX_SIZE_MB,
            'max_count' => DocumentRequirements::MAX_COUNT,
        ]]);
    }

    public function publish(Request $request, string $category): JsonResponse
    {
        $category = Category::where('slug', $category)->firstOrFail();
        $data = $request->validate(DocumentRequirements::rules(), DocumentRequirements::messages());

        $version = $this->requirements->publish($category, $data['documents'], $data['reason'], $request->user());

        return response()->json(['data' => $this->requirements->presentVersion($version->load('publisher'))], 201);
    }
}
