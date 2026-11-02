<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\DocumentVerificationResource;
use App\Models\DocumentVerification;
use App\Models\VerificationDocument;
use App\Services\DocumentVerifications;
use App\Services\PrivateFiles;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** The signed-in provider's own documents for verification */
class VerificationController extends Controller
{
    /** jpg, png or pdf, up to 8 MB each */
    private const FILE_RULES = ['file', 'mimes:jpg,jpeg,png,pdf', 'max:8192'];

    /** @var DocumentVerifications */
    private $verifications;

    public function __construct(DocumentVerifications $verifications)
    {
        $this->verifications = $verifications;
    }

    public function show(Request $request): JsonResponse
    {
        $profile = $request->user()->providerProfile;

        // null means the provider details haven't been started yet
        if (! $profile) {
            return response()->json(['data' => null]);
        }

        return $this->present($this->verifications->forProfile($profile));
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'kind' => ['required', Rule::in(VerificationDocument::KINDS)],
            'label' => ['exclude_unless:kind,supporting', 'required', 'string', 'min:3', 'max:80'],
            'request' => ['exclude_unless:kind,requested', 'required', 'integer'],
            'expires_on' => ['nullable', 'date', 'after:today'],
            'front' => array_merge(['exclude_unless:kind,national_id', 'required'], self::FILE_RULES),
            'back' => array_merge(['exclude_unless:kind,national_id', 'required'], self::FILE_RULES),
            'files' => ['exclude_if:kind,national_id', 'required', 'array', 'min:1', 'max:4'],
            'files.*' => self::FILE_RULES,
        ], $this->messages());

        $this->verifications->upload($request->user(), $data, $this->files($request, $data['kind']));

        return $this->present($request->user()->providerProfile->documentVerification, 201);
    }

    /** A new version of a document that was sent back (or not reviewed yet) */
    public function replace(Request $request, string $document): JsonResponse
    {
        $document = $this->own($request, $document);
        $isId = $document->kind === VerificationDocument::KIND_NATIONAL_ID;

        $data = $request->validate([
            'expires_on' => ['nullable', 'date', 'after:today'],
            'front' => array_merge($isId ? ['required'] : ['exclude'], self::FILE_RULES),
            'back' => array_merge($isId ? ['required'] : ['exclude'], self::FILE_RULES),
            'files' => $isId ? ['exclude'] : ['required', 'array', 'min:1', 'max:4'],
            'files.*' => self::FILE_RULES,
        ], $this->messages());

        $this->verifications->upload($request->user(), $data, $this->files($request, $document->kind), $document);

        return $this->present($document->verification->fresh());
    }

    public function destroy(Request $request, string $document): JsonResponse
    {
        $document = $this->own($request, $document);
        $this->verifications->remove($request->user(), $document);

        return $this->present($document->verification->fresh());
    }

    public function submit(Request $request): JsonResponse
    {
        $request->validate(['confirm' => ['accepted']], ['confirm.accepted' => 'Confirm that these documents are yours and genuine.']);

        return $this->present($this->verifications->submit($request->user()));
    }

    /** The provider's own file */
    public function file(Request $request, string $document, int $index)
    {
        $document = $this->own($request, $document);

        return $this->stream($document, $index);
    }

    public static function stream(VerificationDocument $document, int $index)
    {
        $file = ($document->files ?: [])[$index] ?? null;
        abort_unless($file, 404);

        return app(PrivateFiles::class)->response($file);
    }

    private function own(Request $request, string $uuid): VerificationDocument
    {
        $profile = $request->user()->providerProfile;
        $document = $profile ? VerificationDocument::where('uuid', $uuid)
            ->whereHas('verification', function ($q) use ($profile) {
                $q->where('provider_profile_id', $profile->id);
            })->first() : null;

        abort_unless($document, 404);

        return $document;
    }

    /** @return array<int, array{0: \Illuminate\Http\UploadedFile, 1: ?string}> */
    private function files(Request $request, string $kind): array
    {
        if ($kind === VerificationDocument::KIND_NATIONAL_ID) {
            return [[$request->file('front'), 'front'], [$request->file('back'), 'back']];
        }

        return array_map(function ($file) {
            return [$file, null];
        }, array_values($request->file('files', [])));
    }

    private function messages(): array
    {
        return [
            'front.required' => 'Add a photo of the front of your ID.',
            'back.required' => 'Add a photo of the back of your ID.',
            'files.required' => 'Add at least one file.',
            'files.max' => 'Add up to 4 files (pages) per document.',
            'mimes' => 'Use a JPG, PNG or PDF file.',
            'max' => 'Each file can be up to 8 MB.',
            'label.required' => 'Say what this document is.',
        ];
    }

    private function present(DocumentVerification $verification, int $status = 200): JsonResponse
    {
        $verification->load(['documents', 'requests']);

        return (new DocumentVerificationResource($verification))->response()->setStatusCode($status);
    }
}
