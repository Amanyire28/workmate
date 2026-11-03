<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Models\CommunityComment;
use App\Models\CommunityPost;
use App\Services\Community;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Removing and restoring community posts and comments (content.view) */
class CommunityModerationController extends Controller
{
    /** @var Community */
    private $community;

    public function __construct(Community $community)
    {
        $this->community = $community;
    }

    public function removePost(Request $request, string $post): JsonResponse
    {
        $post = $this->community->removePost($this->post($post), $request->user(), $this->reason($request));

        return response()->json(['data' => ['id' => $post->uuid, 'status' => $post->status]]);
    }

    public function restorePost(Request $request, string $post): JsonResponse
    {
        $post = $this->community->restorePost($this->post($post), $request->user(), $this->reason($request));

        return response()->json(['data' => ['id' => $post->uuid, 'status' => $post->status]]);
    }

    public function removeComment(Request $request, int $comment): JsonResponse
    {
        $comment = $this->community->removeComment(CommunityComment::withTrashed()->findOrFail($comment), $request->user(), $this->reason($request));

        return response()->json(['data' => ['id' => $comment->id, 'status' => $comment->status]]);
    }

    private function post(string $uuid): CommunityPost
    {
        return CommunityPost::withTrashed()->where('uuid', $uuid)->firstOrFail();
    }

    /** Shown to the author, so it says which rule was broken */
    private function reason(Request $request): string
    {
        return $request->validate(['reason' => ['required', 'string', 'min:5', 'max:500']])['reason'];
    }
}
