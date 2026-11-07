<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Models\LegalAcceptance;
use App\Models\LegalDocumentVersion;
use App\Services\AuditLogger;
use App\Services\LegalDocuments;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** Admin → Legal Documents (legal.manage) */
class LegalDocumentController extends Controller
{
    /** @var LegalDocuments */
    private $legal;

    public function __construct(LegalDocuments $legal)
    {
        $this->legal = $legal;
    }

    public function index(): JsonResponse
    {
        return response()->json(['data' => [
            'documents' => $this->legal->adminOverview(),
            'placeholders' => array_keys(LegalDocuments::FACTS),
        ]]);
    }

    public function storeDraft(Request $request, string $key): JsonResponse
    {
        $draft = $this->legal->createDraft($request->user(), $this->legal->find($key));

        return response()->json(['data' => ['id' => $draft->id, 'version' => $draft->version]], 201);
    }

    public function updateDraft(Request $request, LegalDocumentVersion $version): JsonResponse
    {
        $data = $request->validate([
            'version' => ['sometimes', 'required', 'string', 'max:20', 'regex:/^[0-9][0-9A-Za-z.\-]*$/'],
            'summary' => ['sometimes', 'nullable', 'string', 'max:500'],
            'body' => ['sometimes', 'required', 'string', 'max:200000'],
            'change_note' => ['sometimes', 'nullable', 'string', 'max:1000'],
            'is_material' => ['sometimes', 'boolean'],
        ]);

        $draft = $this->legal->updateDraft($request->user(), $version, $data);

        return response()->json(['data' => ['id' => $draft->id, 'version' => $draft->version, 'updated_at' => $draft->updated_at->toIso8601String()]]);
    }

    public function publish(Request $request, LegalDocumentVersion $version): JsonResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'min:5', 'max:500']]);
        $published = $this->legal->publish($request->user(), $version, $data['reason']);

        return response()->json(['data' => ['id' => $published->id, 'version' => $published->version]]);
    }

    public function discard(Request $request, LegalDocumentVersion $version): JsonResponse
    {
        $this->legal->discard($request->user(), $version);

        return new JsonResponse(null, 204);
    }

    /** How a version reads with today's company facts (drafts too) */
    public function preview(LegalDocumentVersion $version): JsonResponse
    {
        return response()->json(['data' => $this->legal->present($version->document, $version)]);
    }

    /** Who accepted this version, as CSV */
    public function export(Request $request, LegalDocumentVersion $version, AuditLogger $audit): StreamedResponse
    {
        $document = $version->document;
        $filename = "workmate-{$document->key}-v{$version->version}-acceptances.csv";
        $audit->record('legal.acceptances_exported', $version, [], ['document' => $document->key, 'version' => $version->version], null, $request->user());

        return response()->streamDownload(function () use ($version) {
            $out = fopen('php://output', 'w');
            fputcsv($out, ['user_id', 'name', 'accepted_at', 'method']);
            LegalAcceptance::where('legal_document_version_id', $version->id)
                ->with('user:id,name')
                ->orderBy('id')
                ->chunk(500, function ($rows) use ($out) {
                    foreach ($rows as $a) {
                        // A leading = + - @ would run as a formula in spreadsheets
                        $name = preg_replace('/^([=+\-@])/', "'$1", optional($a->user)->name ?? '');
                        fputcsv($out, [$a->user_id, $name, $a->accepted_at->toIso8601String(), $a->method]);
                    }
                });
            fclose($out);
        }, $filename, ['Content-Type' => 'text/csv; charset=UTF-8', 'Cache-Control' => 'no-store']);
    }
}
