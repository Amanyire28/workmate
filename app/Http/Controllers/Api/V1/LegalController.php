<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\UserResource;
use App\Models\LegalAcceptance;
use App\Models\LegalDocument;
use App\Models\LegalDocumentVersion;
use App\Models\UserConsent;
use App\Services\LegalDocuments;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Public legal documents (anyone can read the published versions), and each
 * person's acceptances and optional consents.
 */
class LegalController extends Controller
{
    /** @var LegalDocuments */
    private $legal;

    public function __construct(LegalDocuments $legal)
    {
        $this->legal = $legal;
    }

    public function index(): JsonResponse
    {
        $documents = $this->legal->documents()->map(function (LegalDocument $d) {
            $current = $this->legal->current($d);

            return $current ? $this->legal->present($d, $current, false) : null;
        })->filter()->values();

        return response()->json(['data' => $documents]);
    }

    /** The current version, or an earlier published one with ?version_id= */
    public function show(Request $request, string $key): JsonResponse
    {
        $document = $this->legal->find($key);
        $published = $document->publishedVersions()->orderByDesc('published_at')->orderByDesc('id')->get();

        $version = $request->filled('version_id')
            ? $published->firstWhere('id', (int) $request->query('version_id'))
            : $published->first();
        abort_unless($version, 404);

        $data = $this->legal->present($document, $version);
        $data['is_current'] = $version->id === $published->first()->id;
        $data['versions'] = $published->map(function (LegalDocumentVersion $v) {
            return ['version_id' => $v->id, 'version' => $v->version, 'published_at' => $v->published_at->toIso8601String()];
        })->values();

        return response()->json(['data' => $data]);
    }

    /** What I still need to accept, what I accepted, and my optional choices */
    public function mine(Request $request): JsonResponse
    {
        $user = $request->user();

        return response()->json(['data' => [
            'pending' => $this->legal->pendingFor($user),
            'accepted' => $this->legal->historyFor($user),
            'consents' => $this->legal->consentsFor($user),
        ]]);
    }

    public function accept(Request $request): UserResource
    {
        $data = $request->validate([
            'version_ids' => ['required', 'array', 'min:1', 'max:20'],
            'version_ids.*' => ['integer'],
        ]);

        $this->legal->accept($request->user(), $data['version_ids'], LegalAcceptance::METHOD_PROMPT, $request);

        return new UserResource($request->user()->load('roles', 'providerProfile'));
    }

    public function consent(Request $request): JsonResponse
    {
        $data = $request->validate([
            'purpose' => ['required', Rule::in(UserConsent::PURPOSES)],
            'granted' => ['required', 'boolean'],
        ]);

        $this->legal->setConsent($request->user(), $data['purpose'], (bool) $data['granted'], 'settings', $request);

        return response()->json(['data' => $this->legal->consentsFor($request->user())]);
    }
}
