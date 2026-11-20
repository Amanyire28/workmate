<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\CommunityPostResource;
use App\Models\CommunityPost;
use App\Models\CommunityPostMedia;
use App\Models\CommunityVideoUpload;
use App\Services\CommunityVideos;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Video posts: an upload address, then "finished" (checked and published),
 * and fresh playback addresses. See docs/community-video-api.md.
 */
class CommunityVideoController extends Controller
{
    /** @var CommunityVideos */
    private $videos;

    public function __construct(CommunityVideos $videos)
    {
        $this->videos = $videos;
    }

    /** POST /community/video-uploads */
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'category' => ['required', Rule::in(array_keys(CommunityPost::CATEGORIES))],
            'body' => ['nullable', 'string', 'max:'.CommunityPost::MAX_BODY],
            'topic' => ['nullable', 'string', Rule::exists('categories', 'slug')->where('is_active', true)],
            'file_name' => ['nullable', 'string', 'max:255'],
            'mime_type' => ['required', Rule::in(array_keys(CommunityVideos::TYPES))],
            'size' => ['required', 'integer', 'min:1', 'max:'.$this->videos->maxBytes()],
            // Only for reference: the file's own header decides (there's no length limit)
            'duration' => ['nullable', 'numeric', 'min:0'],
            'width' => ['required', 'integer', 'min:16', 'max:8192'],
            'height' => ['required', 'integer', 'min:16', 'max:8192'],
            'client_token' => ['required', 'uuid'],
        ], [
            'mime_type.in' => 'Choose an MP4, MOV or WebM video.',
            'size.max' => 'Videos can be up to '.(int) ($this->videos->maxBytes() / 1048576).' MB.',
        ]);

        $ticket = $this->videos->authorize($request->user(), $data);

        return response()->json(['data' => [
            'upload_id' => $ticket['upload']->uuid,
            'upload' => ['url' => $ticket['url'], 'method' => 'PUT', 'headers' => $ticket['headers']],
            'expires_at' => now()->addSeconds((int) config('videos.upload_url_seconds'))->toIso8601String(),
        ]], 201);
    }

    /** POST /community/video-uploads/{upload}/complete */
    public function complete(Request $request, string $upload): JsonResponse
    {
        $request->validate([
            'poster' => ['nullable', 'file', 'mimes:jpg,jpeg,png,webp', 'max:2048'],
        ]);

        $post = $this->videos->complete($request->user(), $this->findOwn($request, $upload), $request->file('poster'));
        $post->load(CommunityPostResource::RELATIONS);

        return response()->json(['data' => new CommunityPostResource($post)], 201);
    }

    /** DELETE /community/video-uploads/{upload} */
    public function destroy(Request $request, string $upload): JsonResponse
    {
        $this->videos->cancel($request->user(), $this->findOwn($request, $upload));

        return response()->json(null, 204);
    }

    /** GET /community/media/{media}/playback: a fresh address (the old one ran out) */
    public function playback(string $media): JsonResponse
    {
        $item = CommunityPostMedia::where('uuid', $media)->where('type', CommunityPostMedia::TYPE_VIDEO)->first();
        $url = $item ? $this->videos->playbackUrl($item) : null;
        abort_unless($url, 404);

        return response()->json(['data' => [
            'url' => $url,
            'expires_at' => now()->addSeconds((int) config('videos.playback_url_seconds'))->toIso8601String(),
        ]]);
    }

    /** Only the member's own uploads; anyone else's is "not found" */
    private function findOwn(Request $request, string $uuid): CommunityVideoUpload
    {
        $upload = CommunityVideoUpload::where('uuid', $uuid)->where('user_id', $request->user()->getKey())->first();
        abort_unless($upload, 404);

        return $upload;
    }
}
