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
use Illuminate\Validation\ValidationException;

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
        $kind = $request->validate(['kind' => ['required', Rule::in(VerificationDocument::KINDS)]])['kind'];
        $spec = $kind === VerificationDocument::KIND_PROFESSION ? $this->spec($request, (string) $request->input('requirement')) : null;

        $data = $request->validate(array_merge([
            'kind' => ['required'],
            'label' => $kind === VerificationDocument::KIND_SUPPORTING ? ['required', 'string', 'min:3', 'max:80'] : ['exclude'],
            'request' => $kind === VerificationDocument::KIND_REQUESTED ? ['required', 'integer'] : ['exclude'],
            'requirement' => $kind === VerificationDocument::KIND_PROFESSION ? ['required', 'string'] : ['exclude'],
        ], $this->uploadRules($kind, $spec)), $this->messages($spec));

        $this->verifications->upload($request->user(), $data, $this->files($request, $kind, $spec));

        return $this->present($request->user()->providerProfile->documentVerification, 201);
    }

    /** A new version of a document that was sent back (or not reviewed yet) */
    public function replace(Request $request, string $document): JsonResponse
    {
        $document = $this->own($request, $document);
        $spec = $document->kind === VerificationDocument::KIND_PROFESSION ? $this->spec($request, (string) $document->requirement_key) : null;

        $data = $request->validate($this->uploadRules($document->kind, $spec), $this->messages($spec));

        $this->verifications->upload($request->user(), $data, $this->files($request, $document->kind, $spec), $document);

        return $this->present($document->verification->fresh());
    }

    /** Answers to the questions that decide which documents apply */
    public function answers(Request $request): JsonResponse
    {
        $data = $request->validate([
            'own_vehicle' => ['required', 'boolean'],
        ]);

        return $this->present($this->verifications->answer($request->user(), ['own_vehicle' => (bool) $data['own_vehicle']]));
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

    /** The profession document being added, as it applies to this provider */
    private function spec(Request $request, string $key): array
    {
        $spec = $this->verifications->requirement($request->user(), $key);
        if (! $spec) {
            throw ValidationException::withMessages(['requirement' => 'This document isn’t needed for your profession any more.']);
        }

        return $spec;
    }

    /** File and expiry rules: fixed for the ID and free documents, set by the profession for its own */
    private function uploadRules(string $kind, ?array $spec): array
    {
        $file = self::FILE_RULES;
        $expiry = ['nullable', 'date', 'after:today'];
        $sides = $kind === VerificationDocument::KIND_NATIONAL_ID;
        $maxFiles = 4;

        if ($spec) {
            $mimes = str_replace('jpg', 'jpg,jpeg', implode(',', $spec['formats']));
            $file = ['file', 'mimes:'.$mimes, 'max:'.($spec['maxSizeMb'] * 1024)];
            $sides = (bool) $spec['frontBack'];
            $maxFiles = $spec['multiPage'] ? 4 : 1;
            if ($spec['expiry']['required']) {
                $months = (int) $spec['expiry']['minMonthsValid'];
                $expiry = ['required', 'date', $months > 0 ? 'after_or_equal:'.now()->addMonthsNoOverflow($months)->toDateString() : 'after:today'];
            }
        }

        return [
            'expires_on' => $expiry,
            'front' => $sides ? array_merge(['required'], $file) : ['exclude'],
            'back' => $sides ? array_merge(['required'], $file) : ['exclude'],
            'files' => $sides ? ['exclude'] : ['required', 'array', 'min:1', 'max:'.$maxFiles],
            'files.*' => $sides ? ['exclude'] : $file,
        ];
    }

    /** @return array<int, array{0: \Illuminate\Http\UploadedFile, 1: ?string}> */
    private function files(Request $request, string $kind, ?array $spec = null): array
    {
        if ($kind === VerificationDocument::KIND_NATIONAL_ID || ($spec && $spec['frontBack'])) {
            return [[$request->file('front'), 'front'], [$request->file('back'), 'back']];
        }

        return array_map(function ($file) {
            return [$file, null];
        }, array_values($request->file('files', [])));
    }

    private function messages(?array $spec = null): array
    {
        $what = $spec ? $spec['name'] : 'your ID';
        $formats = $spec ? implode(', ', array_map('strtoupper', $spec['formats'])) : 'JPG, PNG or PDF';
        $months = $spec ? (int) $spec['expiry']['minMonthsValid'] : 0;

        return [
            'front.required' => 'Add a photo of the front of '.$what.'.',
            'back.required' => 'Add a photo of the back of '.$what.'.',
            'files.required' => 'Add at least one file.',
            'files.max' => $spec && ! $spec['multiPage'] ? 'Add one file for this document.' : 'Add up to 4 files (pages) per document.',
            'mimes' => 'Use a '.$formats.' file.',
            'max' => 'Each file can be up to '.($spec ? $spec['maxSizeMb'] : 8).' MB.',
            'label.required' => 'Say what this document is.',
            'expires_on.required' => 'Add the expiry date.',
            'expires_on.after_or_equal' => 'It must still be valid for at least '.$months.' '.($months === 1 ? 'month' : 'months').'.',
            'expires_on.after' => 'This document has expired.',
        ];
    }

    private function present(DocumentVerification $verification, int $status = 200): JsonResponse
    {
        $verification->load(['documents', 'requests']);

        return (new DocumentVerificationResource($verification))->response()->setStatusCode($status);
    }
}
