<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\UserResource;
use App\Services\ProfilePhotos;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class ProfilePhotoController extends Controller
{
    /** @var ProfilePhotos */
    private $photos;

    public function __construct(ProfilePhotos $photos)
    {
        $this->photos = $photos;
    }

    public function store(Request $request): UserResource
    {
        $request->validate([
            'photo' => ['required', 'file', 'mimes:jpg,jpeg,png,webp', 'max:5120', 'dimensions:min_width=96,min_height=96'],
        ], [
            'photo.mimes' => 'Choose a JPG, PNG or WebP photo.',
            'photo.max' => 'Choose a photo smaller than 5 MB.',
            'photo.dimensions' => 'Choose a larger photo (at least 96 × 96 pixels).',
        ]);

        $user = $this->photos->replace($request->user(), $request->file('photo'));

        return new UserResource($user->load('roles', 'providerProfile'));
    }

    public function destroy(Request $request): UserResource
    {
        $user = $this->photos->remove($request->user());

        return new UserResource($user->load('roles', 'providerProfile'));
    }

    /**
     * Profile photos are shown publicly next to names, like on any marketplace.
     * Names are random and change with every upload, so they can be cached for good.
     */
    public function show(string $file): BinaryFileResponse
    {
        $path = ProfilePhotos::DIRECTORY.'/'.$file;
        abort_unless(Storage::disk('local')->exists($path), 404);

        return response()->file(Storage::disk('local')->path($path), [
            'Content-Type' => 'image/jpeg',
            'Cache-Control' => 'public, max-age=31536000, immutable',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
