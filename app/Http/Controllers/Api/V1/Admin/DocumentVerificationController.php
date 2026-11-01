<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Api\V1\VerificationController;
use App\Http\Controllers\Controller;
use App\Models\DocumentVerification;
use App\Models\VerificationDocument;
use App\Services\DocumentVerifications;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Reviewing providers' verification documents */
class DocumentVerificationController extends Controller
{
    /** @var DocumentVerifications */
    private $verifications;

    public function __construct(DocumentVerifications $verifications)
    {
        $this->verifications = $verifications;
    }

    public function assign(Request $request, DocumentVerification $verification): JsonResponse
    {
        return $this->done($this->verifications->assign($verification, $request->user()));
    }

    public function acceptDocument(Request $request, DocumentVerification $verification, string $document): JsonResponse
    {
        return $this->done($this->verifications->acceptDocument($verification, $this->document($verification, $document), $request->user()));
    }

    public function sendBackDocument(Request $request, DocumentVerification $verification, string $document): JsonResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'min:5', 'max:500']]);

        return $this->done($this->verifications->sendBackDocument($verification, $this->document($verification, $document), $request->user(), $data['reason']));
    }

    public function requestDocument(Request $request, DocumentVerification $verification): JsonResponse
    {
        $data = $request->validate([
            'label' => ['required', 'string', 'min:3', 'max:80'],
            'instructions' => ['nullable', 'string', 'max:500'],
        ]);

        return $this->done($this->verifications->requestDocument($verification, $request->user(), $data['label'], $data['instructions'] ?? null));
    }

    public function requestInformation(Request $request, DocumentVerification $verification): JsonResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'min:5', 'max:1000']]);

        return $this->done($this->verifications->requestInformation($verification, $request->user(), $data['reason']));
    }

    public function approve(Request $request, DocumentVerification $verification): JsonResponse
    {
        $data = $request->validate(['note' => ['nullable', 'string', 'max:1000']]);

        return $this->done($this->verifications->approve($verification, $request->user(), $data['note'] ?? null));
    }

    public function reject(Request $request, DocumentVerification $verification): JsonResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'min:5', 'max:1000']]);

        return $this->done($this->verifications->reject($verification, $request->user(), $data['reason']));
    }

    public function reopen(Request $request, DocumentVerification $verification): JsonResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'min:5', 'max:1000']]);

        return $this->done($this->verifications->reopen($verification, $request->user(), $data['reason']));
    }

    public function note(Request $request, DocumentVerification $verification): JsonResponse
    {
        $data = $request->validate(['note' => ['required', 'string', 'min:3', 'max:1000']]);
        $this->verifications->note($verification, $request->user(), $data['note']);

        return $this->done($verification);
    }

    /** Opens a private file. Every opening is recorded. */
    public function file(Request $request, DocumentVerification $verification, string $document, int $index)
    {
        $document = $this->document($verification, $document);
        abort_unless(isset(($document->files ?: [])[$index]), 404);
        $this->verifications->recordView($document, $index, $request->user());

        return VerificationController::stream($document, $index);
    }

    private function document(DocumentVerification $verification, string $uuid): VerificationDocument
    {
        return $verification->documents()->where('uuid', $uuid)->firstOrFail();
    }

    private function done(DocumentVerification $verification): JsonResponse
    {
        return response()->json(['data' => ['status' => $verification->fresh()->status]]);
    }
}
