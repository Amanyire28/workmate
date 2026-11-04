<?php

namespace App\Http\Resources;

use App\Models\CommunityPost;
use App\Models\CommunityPostMedia;
use App\Models\ProviderProfile;
use App\Models\User;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A community post as anyone may see it. No contact details or account
 * fields; members who aren't listed providers show as "Aisha N.". Load with
 * CommunityPostResource::RELATIONS, and set viewer_liked / viewer_saved.
 */
class CommunityPostResource extends JsonResource
{
    public const RELATIONS = ['author', 'provider.owner', 'provider.professions', 'topic', 'area', 'media'];

    /**
     * @param  \Illuminate\Http\Request  $request
     * @return array
     */
    public function toArray($request)
    {
        $viewer = $request->user('sanctum');
        $mine = $viewer && $viewer->getKey() === $this->user_id;
        $removed = $this->status === CommunityPost::STATUS_REMOVED;

        return [
            'id' => $this->uuid,
            'category' => $this->category,
            'body' => $this->body,
            'created_at' => $this->created_at->toIso8601String(),
            'edited_at' => optional($this->edited_at)->toIso8601String(),
            'author' => self::author($this->author, $this->provider) + ['following' => (bool) $this->viewer_follows_author],
            'topic' => $this->topic ? ['slug' => $this->topic->slug, 'name' => $this->topic->name] : null,
            'area' => $this->area ? $this->area->name : null,
            'media' => $this->media->map(function (CommunityPostMedia $m) {
                return ['id' => $m->uuid, 'url' => $m->url(), 'thumb_url' => $m->url(true), 'width' => $m->width, 'height' => $m->height];
            })->values(),
            'likes_count' => $this->likes_count,
            'comments_count' => $this->comments_count,
            'liked' => (bool) $this->viewer_liked,
            'saved' => (bool) $this->viewer_saved,
            'mine' => $mine,
            // Only the author sees that, and why, WorkMate removed it
            'removed' => $mine && $removed ? ['reason' => $this->removed_reason, 'at' => optional($this->removed_at)->toIso8601String()] : null,
        ];
    }

    /** How a member appears next to what they write */
    public static function author(User $user, ?ProviderProfile $provider): array
    {
        $listed = $provider && $provider->isApproved() && $provider->owner && $provider->owner->isActive() && $provider->owner_id === $user->getKey();
        $main = $listed ? ($provider->professions->first(function ($p) {
            return (bool) $p->pivot->is_primary;
        }) ?: $provider->professions->first()) : null;

        return [
            // Their community page: /community/people/{id}
            'id' => $user->id,
            'name' => $listed ? $provider->display_name : User::shortNameOf($user->name),
            'avatar_url' => $user->avatarUrl(),
            'title' => $main ? $main->name : null,
            // Listed providers link to their public profile
            'provider_id' => $listed ? $provider->id : null,
            'document_verified' => $listed && $provider->document_verified_at !== null,
            'blue_tick' => $listed && $provider->blue_tick_awarded_at !== null,
        ];
    }
}
