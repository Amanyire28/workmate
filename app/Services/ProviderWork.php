<?php

namespace App\Services;

use App\Exceptions\BusinessRuleException;
use App\Models\Area;
use App\Models\Category;
use App\Models\CommunityPost;
use App\Models\ProviderProfile;
use App\Models\ProviderWorkItem;
use App\Models\ProviderWorkPhoto;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * "Recent work" on a provider's profile: jobs they've done, with photos.
 * Only the person who owns the provider profile changes it, and only while
 * it's approved. WorkMate can remove an item that breaks the rules.
 */
class ProviderWork
{
    /** @var AuditLogger */
    private $audit;

    /** @var PhotoVariants */
    private $photos;

    /** @var EventNotifications */
    private $notify;

    public function __construct(AuditLogger $audit, PhotoVariants $photos, EventNotifications $notify)
    {
        $this->audit = $audit;
        $this->photos = $photos;
        $this->notify = $notify;
    }

    /**
     * @param  array{title: string, description: ?string, category: ?string, area: ?string, done_on: ?string}  $data
     * @param  UploadedFile[]  $photos
     */
    public function add(User $owner, array $data, array $photos): ProviderWorkItem
    {
        $profile = $this->ownApprovedProfile($owner);
        if (! $photos) {
            throw new BusinessRuleException('work_needs_photo', 'Add at least one photo of the work.', 422);
        }
        if ($profile->workItems()->count() >= ProviderWorkItem::MAX_ITEMS) {
            throw new BusinessRuleException('work_limit', 'You can show up to '.ProviderWorkItem::MAX_ITEMS.' jobs. Delete an older one to add this.', 422);
        }

        $stored = [];
        try {
            foreach ($photos as $photo) {
                $stored[] = $this->photos->store($photo, 'work');
            }

            $item = DB::transaction(function () use ($owner, $profile, $data, $stored) {
                $item = new ProviderWorkItem();
                $item->forceFill(['uuid' => (string) Str::uuid(), 'provider_profile_id' => $profile->id] + $this->details($data, $profile))->save();

                foreach ($stored as $i => $file) {
                    $photo = new ProviderWorkPhoto();
                    $photo->forceFill($file + ['provider_work_item_id' => $item->id, 'position' => $i])->save();
                }

                $this->audit->record('provider.work_added', $item, [], ['title' => $item->title, 'photos' => count($stored)], null, $owner);

                return $item;
            });
        } catch (\Throwable $e) {
            $this->photos->discard(array_merge(array_column($stored, 'path'), array_column($stored, 'thumb_path')));
            throw $e;
        }

        return $item;
    }

    /**
     * Also posts the work in the community feed. The work stays on the
     * profile whatever happens here.
     *
     * @param  UploadedFile[]  $photos
     */
    public function shareToCommunity(User $owner, ProviderWorkItem $item, array $photos): void
    {
        $post = app(Community::class)->publish($owner, [
            'category' => 'my_work',
            'body' => trim($item->title."\n\n".($item->description ?? '')),
            'topic' => $item->category ? $item->category->slug : null,
        ], $photos);
        $item->forceFill(['community_post_id' => $post->id])->save();
    }

    /** @param array{title?: string, description?: ?string, category?: ?string, area?: ?string, done_on?: ?string} $data */
    public function edit(User $owner, ProviderWorkItem $item, array $data): ProviderWorkItem
    {
        $profile = $this->ownApprovedProfile($owner);
        $this->guardOwn($profile, $item);
        if ($item->status === ProviderWorkItem::STATUS_REMOVED) {
            throw new BusinessRuleException('work_removed', 'WorkMate removed this work, so it can’t be changed. You can delete it.', 409);
        }

        return DB::transaction(function () use ($owner, $profile, $item, $data) {
            $before = $item->only(['title', 'description', 'category_id', 'area_id', 'done_on']);
            $item->forceFill($this->details($data, $profile, $item))->save();
            $this->audit->record('provider.work_edited', $item, $before, $item->only(['title', 'description', 'category_id', 'area_id', 'done_on']), null, $owner);

            return $item;
        });
    }

    public function delete(User $owner, ProviderWorkItem $item): void
    {
        $profile = $owner->providerProfile;
        if (! $profile) {
            throw new BusinessRuleException('no_provider_profile', 'Set up your provider profile first.', 409);
        }
        $this->guardOwn($profile, $item);

        DB::transaction(function () use ($owner, $item) {
            $item->delete();
            $this->audit->record('provider.work_deleted', $item, [], ['title' => $item->title], null, $owner);
        });
    }

    /* ---------------- Moderation ---------------- */

    public function remove(ProviderWorkItem $item, User $admin, string $reason): ProviderWorkItem
    {
        return DB::transaction(function () use ($item, $admin, $reason) {
            $item = ProviderWorkItem::withTrashed()->whereKey($item->id)->lockForUpdate()->firstOrFail();
            if ($item->status === ProviderWorkItem::STATUS_REMOVED) {
                throw new BusinessRuleException('already_removed', 'This work is already removed.', 409);
            }
            $item->forceFill(['status' => ProviderWorkItem::STATUS_REMOVED, 'removed_reason' => $reason, 'removed_by' => $admin->getKey(), 'removed_at' => now()])->save();
            $this->audit->record('provider.work_removed', $item, ['status' => ProviderWorkItem::STATUS_PUBLISHED], ['status' => $item->status], $reason, $admin);
            $owner = optional($item->provider)->owner_id;
            if ($owner) {
                $this->notify->workRemoved($owner, '/provider/'.$item->provider_profile_id.'#work');
            }

            return $item;
        });
    }

    public function restore(ProviderWorkItem $item, User $admin, string $reason): ProviderWorkItem
    {
        return DB::transaction(function () use ($item, $admin, $reason) {
            $item = ProviderWorkItem::withTrashed()->whereKey($item->id)->lockForUpdate()->firstOrFail();
            if ($item->status !== ProviderWorkItem::STATUS_REMOVED) {
                throw new BusinessRuleException('not_removed', 'This work isn’t removed.', 409);
            }
            $item->forceFill(['status' => ProviderWorkItem::STATUS_PUBLISHED, 'removed_reason' => null, 'removed_by' => null, 'removed_at' => null])->save();
            $this->audit->record('provider.work_restored', $item, ['status' => ProviderWorkItem::STATUS_REMOVED], ['status' => $item->status], $reason, $admin);

            return $item;
        });
    }

    /* ---------------- Showing it ---------------- */

    public function present(ProviderWorkItem $item, bool $forOwner = false): array
    {
        $data = [
            'id' => $item->uuid,
            'title' => $item->title,
            'description' => $item->description,
            'category' => $item->category ? $item->category->slug : null,
            'category_name' => $item->category ? $item->category->name : null,
            'area' => $item->area ? $item->area->slug : null,
            'area_name' => $item->area ? $item->area->name : null,
            'done_on' => $item->done_on ? $item->done_on->format('Y-m') : null,
            'photos' => $item->photos->map(function (ProviderWorkPhoto $p) {
                return ['url' => $p->url(), 'thumb_url' => $p->url(true), 'width' => $p->width, 'height' => $p->height];
            })->values()->all(),
            'created_at' => $item->created_at->toIso8601String(),
        ];
        if ($forOwner) {
            $data['status'] = $item->status;
            $data['removed_reason'] = $item->removed_reason;
            $data['in_community'] = $item->community_post_id !== null;
        }

        return $data;
    }

    /** Newest work first: by when it was done, then when it was added */
    public function listFor(ProviderProfile $profile, bool $forOwner = false)
    {
        $query = $profile->workItems()->with(['photos', 'category', 'area'])
            ->orderByRaw('done_on IS NULL')->orderByDesc('done_on')->orderByDesc('id');
        if (! $forOwner) {
            $query->published();
        }

        return $query->get()->map(function (ProviderWorkItem $item) use ($forOwner) {
            return $this->present($item, $forOwner);
        })->values();
    }

    /* ---------------- Helpers ---------------- */

    /** The provider profile this account owns, while customers can find it */
    private function ownApprovedProfile(User $owner): ProviderProfile
    {
        $profile = $owner->providerProfile;
        if (! $profile) {
            throw new BusinessRuleException('no_provider_profile', 'Set up your provider profile first.', 409);
        }
        if (! $profile->isApproved()) {
            throw new BusinessRuleException('provider_not_approved', 'You can show your work once your provider profile is approved.', 409);
        }

        return $profile;
    }

    private function guardOwn(ProviderProfile $profile, ProviderWorkItem $item): void
    {
        if ($item->provider_profile_id !== $profile->id) {
            throw new BusinessRuleException('not_found', 'The requested item was not found.', 404);
        }
    }

    /** Cleaned details; the type of job defaults to their main profession, the place to their base */
    private function details(array $data, ProviderProfile $profile, ?ProviderWorkItem $item = null): array
    {
        $out = [];
        if (! $item || array_key_exists('title', $data)) {
            $out['title'] = $this->clean($data['title'] ?? '', 120);
            if ($out['title'] === '') {
                throw new BusinessRuleException('work_title', 'Say what the job was.', 422);
            }
        }
        if (! $item || array_key_exists('description', $data)) {
            $description = $this->clean($data['description'] ?? '', 500);
            $out['description'] = $description === '' ? null : $description;
        }
        if (! $item || array_key_exists('category', $data)) {
            $category = ! empty($data['category']) ? Category::active()->where('slug', $data['category'])->value('id') : null;
            $out['category_id'] = $category ?: ($item ? null : $profile->mainCategoryId());
        }
        if (! $item || array_key_exists('area', $data)) {
            $area = ! empty($data['area']) ? Area::active()->where('slug', $data['area'])->value('id') : null;
            $out['area_id'] = $area ?: ($item ? null : $profile->base_area_id);
        }
        if (! $item || array_key_exists('done_on', $data)) {
            $out['done_on'] = ! empty($data['done_on']) ? Carbon::createFromFormat('Y-m-d', $data['done_on'].'-01')->startOfMonth() : null;
        }

        return $out;
    }

    private function clean(?string $text, int $max): string
    {
        $text = trim(preg_replace('/[^\P{C}\n]+/u', '', (string) $text));

        return Str::limit(preg_replace("/\n{3,}/", "\n\n", $text), $max, '');
    }
}
