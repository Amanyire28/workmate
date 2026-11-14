<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A comment as anyone may see it. Deleted or removed comments that still
 * have replies keep their place with no text. Load author, author.providerProfile
 * (with owner and professions) and replies the same way.
 */
class CommunityCommentResource extends JsonResource
{
    /**
     * @param  \Illuminate\Http\Request  $request
     * @return array
     */
    public function toArray($request)
    {
        $viewer = $request->user('sanctum');
        $gone = $this->trashed() || $this->status !== 'published';
        $postAuthor = $this->relationLoaded('post') && $this->post ? $this->post->user_id : null;

        return [
            'id' => $this->id,
            'parent_id' => $this->parent_id,
            'body' => $gone ? null : $this->body,
            'gone' => $gone,
            'created_at' => $this->created_at->toIso8601String(),
            'author' => $gone ? null : CommunityPostResource::author($this->author, $this->author->providerProfile),
            'likes_count' => $gone ? 0 : (int) $this->likes_count,
            // Set by the comments list for the signed-in viewer
            'liked' => ! $gone && ! empty($this->resource->likedByViewer),
            'mine' => $viewer && $viewer->getKey() === $this->user_id,
            // The post's author may delete comments on it
            'can_delete' => ! $gone && $viewer && ($viewer->getKey() === $this->user_id || $viewer->getKey() === $postAuthor),
            'replies' => self::collection($this->whenLoaded('replies')),
        ];
    }
}
