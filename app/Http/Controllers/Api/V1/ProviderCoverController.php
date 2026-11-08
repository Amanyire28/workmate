<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\ProviderProfile;
use App\Services\ProviderCovers;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class ProviderCoverController extends Controller
{
    /** @var ProviderCovers */
    private $covers;

    public function __construct(ProviderCovers $covers)
    {
        $this->covers = $covers;
    }

    public function store(Request $request): JsonResponse
    {
        $request->validate([
            'cover' => ['required', 'file', 'mimes:jpg,jpeg,png,webp', 'max:5120', 'dimensions:min_width=600,min_height=200'],
        ], [
            'cover.mimes' => 'Choose a JPG, PNG or WebP photo.',
            'cover.max' => 'Choose a photo smaller than 5 MB.',
            'cover.dimensions' => 'Choose a larger photo (at least 600 × 200 pixels).',
        ]);

        return $this->respond($this->covers->replace($request->user(), $request->file('cover')));
    }

    public function destroy(Request $request): JsonResponse
    {
        return $this->respond($this->covers->remove($request->user()));
    }

    /**
     * Covers are shown publicly on provider profiles. Names are random and
     * change with every upload, so they can be cached for good.
     */
    public function show(string $file): BinaryFileResponse
    {
        $path = ProviderCovers::DIRECTORY.'/'.$file;
        abort_unless(Storage::disk('local')->exists($path), 404);

        return response()->file(Storage::disk('local')->path($path), [
            'Content-Type' => 'image/jpeg',
            'Cache-Control' => 'public, max-age=31536000, immutable',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    private function respond(ProviderProfile $profile): JsonResponse
    {
        return response()->json(['data' => ['id' => $profile->id, 'cover_url' => $profile->coverUrl()]]);
    }
}
