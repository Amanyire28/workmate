<?php

namespace App\Services;

use App\Exceptions\BusinessRuleException;
use App\Models\CommunityPost;
use App\Models\CommunityPostMedia;
use App\Models\CommunityVideoUpload;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Video posts in the community. The phone uploads straight to the private R2
 * bucket with an address signed here; nothing passes through or is stored on
 * this server except the small poster image. A post is made only once the
 * upload has arrived and its MP4 header passes the checks (Mp4Inspector).
 *
 * Who may post: everyone when community.video_posts is on; otherwise, during
 * the pilot (community.video_pilot), admins and the tester emails listed in
 * community.video_testers. Everyone can watch published videos.
 */
class CommunityVideos
{
    /** @var R2Storage */
    private $r2;

    /** @var Mp4Inspector */
    private $inspector;

    /** @var Community */
    private $community;

    /** @var PhotoVariants */
    private $photos;

    /** @var PlatformSettings */
    private $settings;

    public function __construct(R2Storage $r2, Mp4Inspector $inspector, Community $community, PhotoVariants $photos, PlatformSettings $settings)
    {
        $this->r2 = $r2;
        $this->inspector = $inspector;
        $this->community = $community;
        $this->photos = $photos;
        $this->settings = $settings;
    }

    /* ---------------- Who may post ---------------- */

    public function canPost(?User $user): bool
    {
        if (! $user || ! $this->r2->configured()) {
            return false;
        }
        if ($this->settings->get('community.video_posts')) {
            return true;
        }
        if (! $this->settings->get('community.video_pilot')) {
            return false;
        }
        if (Gate::forUser($user)->allows('admin-access')) {
            return true;
        }
        $testers = array_filter(array_map(function ($e) {
            return strtolower(trim($e));
        }, explode(',', (string) $this->settings->get('community.video_testers'))));

        return in_array(strtolower((string) $user->email), $testers, true);
    }

    public function maxBytes(): int
    {
        return (int) $this->settings->get('community.video_max_mb') * 1024 * 1024;
    }

    public function maxSeconds(): int
    {
        return (int) $this->settings->get('community.video_max_seconds');
    }

    /* ---------------- 1. Where to upload ---------------- */

    /**
     * @param  array{category: string, body: ?string, topic: ?string, mime_type: string, size: int, duration: float, width: int, height: int, client_token: string}  $data
     * @return array{upload: CommunityVideoUpload, url: string, headers: array<string, string>}
     */
    public function authorize(User $user, array $data): array
    {
        $this->guardCanPost($user);

        // The same request sent twice (a retry on a bad connection) gets the same upload
        $existing = CommunityVideoUpload::where('user_id', $user->getKey())->where('client_token', $data['client_token'])->first();
        if ($existing) {
            if ($existing->status !== CommunityVideoUpload::STATUS_PENDING || $existing->isExpired()) {
                throw new BusinessRuleException('upload_used', 'This upload has already been used. Please try again.', 409);
            }

            return $this->ticket($existing);
        }

        $pending = CommunityVideoUpload::where('user_id', $user->getKey())
            ->whereIn('status', [CommunityVideoUpload::STATUS_PENDING, CommunityVideoUpload::STATUS_CHECKING])
            ->where('expires_at', '>', now())->count();
        if ($pending >= (int) config('videos.max_pending_uploads')) {
            throw new BusinessRuleException('too_many_uploads', 'You have other videos still uploading. Wait for them to finish, then try again.', 429);
        }

        $upload = new CommunityVideoUpload();
        $upload->forceFill([
            'uuid' => (string) Str::uuid(),
            'user_id' => $user->getKey(),
            'client_token' => $data['client_token'],
            // Chosen here, never by the phone, and impossible to guess
            'object_key' => 'community/'.now()->format('Y/m').'/'.Str::lower(Str::random(40)).'.mp4',
            'status' => CommunityVideoUpload::STATUS_PENDING,
            'category' => $data['category'],
            'body' => $data['body'] ?? null,
            'topic' => $data['topic'] ?? null,
            'mime_type' => 'video/mp4',
            'size' => (int) $data['size'],
            'duration' => (float) $data['duration'],
            'width' => (int) $data['width'],
            'height' => (int) $data['height'],
            'expires_at' => now()->addMinutes((int) config('videos.upload_window_minutes')),
        ])->save();

        return $this->ticket($upload);
    }

    /** The signed upload address: only this object, this type and exactly this size */
    private function ticket(CommunityVideoUpload $upload): array
    {
        $headers = ['Content-Type' => 'video/mp4'];
        $url = $this->r2->presign('PUT', $upload->object_key, (int) config('videos.upload_url_seconds'), $headers + [
            // Signed too: the phone's browser sends it by itself and can't send a bigger file
            'Content-Length' => (string) $upload->size,
        ]);

        return ['upload' => $upload, 'url' => $url, 'headers' => $headers];
    }

    /* ---------------- 3. Finished: check it and publish ---------------- */

    public function complete(User $user, CommunityVideoUpload $upload, ?UploadedFile $poster): CommunityPost
    {
        $this->guardCanPost($user);

        if ($upload->status === CommunityVideoUpload::STATUS_COMPLETED && $upload->post) {
            // Finished already (the phone asked twice)
            return $upload->post;
        }
        if ($upload->isExpired() && $upload->status === CommunityVideoUpload::STATUS_PENDING) {
            $upload->forceFill(['status' => CommunityVideoUpload::STATUS_EXPIRED])->save();
        }
        if ($upload->status === CommunityVideoUpload::STATUS_EXPIRED) {
            throw new BusinessRuleException('upload_expired', 'This upload took too long. Please share the video again.', 410);
        }

        // Only one check at a time for an upload
        $claimed = CommunityVideoUpload::whereKey($upload->id)->where('status', CommunityVideoUpload::STATUS_PENDING)
            ->update(['status' => CommunityVideoUpload::STATUS_CHECKING, 'updated_at' => now()]);
        if (! $claimed) {
            throw new BusinessRuleException('upload_not_pending', 'This upload is already being finished, or was stopped.', 409);
        }
        $upload->refresh();

        try {
            $object = $this->r2->head($upload->object_key);
        } catch (RuntimeException $e) {
            $this->backToPending($upload);
            Log::warning('Video storage unreachable while finishing an upload', ['upload' => $upload->uuid, 'error' => $e->getMessage()]);
            throw new BusinessRuleException('video_storage_unavailable', 'We couldn’t reach video storage. Please try again in a moment.', 503);
        }
        if ($object === null) {
            // Not there (yet): the phone may try finishing again
            $this->backToPending($upload);
            throw new BusinessRuleException('video_not_received', 'We didn’t receive the whole video. Please upload it again.', 422);
        }
        if ($object['size'] !== $upload->size || $object['size'] > $this->maxBytes()) {
            $this->fail($upload, 'size_mismatch');
            throw new BusinessRuleException('video_not_received', 'The video that arrived isn’t the one you chose. Please upload it again.', 422);
        }

        try {
            $facts = $this->inspector->inspect(function (int $offset, int $length) use ($upload) {
                return $this->r2->read($upload->object_key, $offset, $length);
            }, $object['size'], (int) config('videos.max_header_bytes'));
        } catch (BusinessRuleException $e) {
            $this->fail($upload, $e->errorCode());
            throw $e;
        } catch (RuntimeException $e) {
            $this->backToPending($upload);
            Log::warning('Video storage unreachable while checking an upload', ['upload' => $upload->uuid, 'error' => $e->getMessage()]);
            throw new BusinessRuleException('video_storage_unavailable', 'We couldn’t reach video storage. Please try again in a moment.', 503);
        }
        if ($facts['duration'] > $this->maxSeconds() + 0.5) {
            $this->fail($upload, 'too_long');
            throw new BusinessRuleException('video_too_long', 'This video is longer than '.$this->maxSeconds().' seconds. Trim it and try again.', 422);
        }

        // The picture size the phone saw (after rotation) when it matches the file, either way round
        [$width, $height] = $this->displaySize($upload, $facts);

        $stored = $poster ? [$this->photos->store($poster, 'community', 'community')] : [];
        try {
            return DB::transaction(function () use ($user, $upload, $facts, $stored, $width, $height) {
                $post = $this->community->publishVideo($user, [
                    'category' => $upload->category, 'body' => $upload->body, 'topic' => $upload->topic,
                ], [
                    'uuid' => (string) Str::uuid(),
                    'type' => CommunityPostMedia::TYPE_VIDEO,
                    'path' => $stored ? $stored[0]['path'] : '',
                    'thumb_path' => $stored ? $stored[0]['thumb_path'] : '',
                    'original_path' => $stored ? ($stored[0]['original_path'] ?? null) : null,
                    'watermarked_at' => $stored ? ($stored[0]['watermarked_at'] ?? null) : null,
                    'video_key' => $upload->object_key,
                    'width' => $width,
                    'height' => $height,
                    'duration' => $facts['duration'],
                    'size' => $upload->size,
                ]);
                $upload->forceFill([
                    'status' => CommunityVideoUpload::STATUS_COMPLETED,
                    'community_post_id' => $post->id,
                    'completed_at' => now(),
                    'error' => null,
                ])->save();

                return $post;
            });
        } catch (\Throwable $e) {
            $this->photos->discardStored($stored);
            $this->backToPending($upload);
            throw $e;
        }
    }

    /** @return array{0: int, 1: int} */
    private function displaySize(CommunityVideoUpload $upload, array $facts): array
    {
        $close = function ($a, $b) {
            return abs($a - $b) <= 2;
        };
        $same = $close($upload->width, $facts['width']) && $close($upload->height, $facts['height']);
        $turned = $close($upload->width, $facts['height']) && $close($upload->height, $facts['width']);

        return $same || $turned ? [$upload->width, $upload->height] : [$facts['width'], $facts['height']];
    }

    /* ---------------- Stopping ---------------- */

    public function cancel(User $user, CommunityVideoUpload $upload): void
    {
        if ($upload->status !== CommunityVideoUpload::STATUS_PENDING) {
            return;
        }
        $upload->forceFill(['status' => CommunityVideoUpload::STATUS_CANCELLED])->save();
        $this->deleteNow($upload);
    }

    /* ---------------- Watching ---------------- */

    /** A fresh, short-lived address to play the video, or null when it may not be played */
    public function playbackUrl(CommunityPostMedia $media, ?CommunityPost $post = null): ?string
    {
        $post = $post ?: $media->post;
        if (! $media->isVideo() || ! $media->video_key || $media->video_deleted_at || ! $post || ! $post->isVisible() || ! $this->r2->configured()) {
            return null;
        }

        return $this->r2->presign('GET', $media->video_key, (int) config('videos.playback_url_seconds'));
    }

    /* ---------------- Clean-up (workmate:video-cleanup) ---------------- */

    /**
     * Expires abandoned uploads, removes their files, and removes the files of
     * videos whose posts were deleted more than videos.delete_after_days ago.
     * Safe to run again: whatever fails is tried next time.
     *
     * @return array{expired: int, uploads_deleted: int, videos_deleted: int, failures: int}
     */
    public function cleanup(int $limit = 200): array
    {
        $done = ['expired' => 0, 'uploads_deleted' => 0, 'videos_deleted' => 0, 'failures' => 0];

        // Never finished (allowing an hour for an upload being checked)
        $done['expired'] = CommunityVideoUpload::whereIn('status', [CommunityVideoUpload::STATUS_PENDING, CommunityVideoUpload::STATUS_CHECKING])
            ->where('expires_at', '<', now()->subHour())
            ->limit($limit)->update(['status' => CommunityVideoUpload::STATUS_EXPIRED, 'updated_at' => now()]);

        if (! $this->r2->configured()) {
            return $done;
        }

        // Only once the upload window has closed, so the signed upload address can't put the file back
        $leftovers = CommunityVideoUpload::whereIn('status', [CommunityVideoUpload::STATUS_FAILED, CommunityVideoUpload::STATUS_CANCELLED, CommunityVideoUpload::STATUS_EXPIRED])
            ->whereNull('object_deleted_at')->where('cleanup_attempts', '<', 50)->where('expires_at', '<', now())
            ->orderBy('id')->limit($limit)->get();
        foreach ($leftovers as $upload) {
            $this->deleteUploadObject($upload) ? $done['uploads_deleted']++ : $done['failures']++;
        }

        $cutoff = now()->subDays((int) config('videos.delete_after_days'));
        $gone = CommunityPostMedia::where('type', CommunityPostMedia::TYPE_VIDEO)
            ->whereNotNull('video_key')->whereNull('video_deleted_at')
            ->whereHas('post', function ($q) use ($cutoff) {
                $q->onlyTrashed()->where('deleted_at', '<', $cutoff);
            })
            ->orderBy('id')->limit($limit)->get();
        foreach ($gone as $media) {
            try {
                $this->r2->delete($media->video_key);
                $media->forceFill(['video_deleted_at' => now()])->save();
                $done['videos_deleted']++;
            } catch (RuntimeException $e) {
                $done['failures']++;
                Log::warning('Couldn’t remove a deleted post’s video from storage; will retry', ['media' => $media->uuid, 'error' => $e->getMessage()]);
            }
        }

        return $done;
    }

    private function deleteUploadObject(CommunityVideoUpload $upload): bool
    {
        try {
            $this->r2->delete($upload->object_key);
            $upload->forceFill(['object_deleted_at' => now()])->save();

            return true;
        } catch (RuntimeException $e) {
            $upload->forceFill(['cleanup_attempts' => $upload->cleanup_attempts + 1])->save();
            Log::warning('Couldn’t remove an unused video upload from storage; will retry', ['upload' => $upload->uuid, 'error' => $e->getMessage()]);

            return false;
        }
    }

    /** Removes a stopped or refused upload's file straight away (clean-up removes it again later, for certain) */
    private function deleteNow(CommunityVideoUpload $upload): void
    {
        try {
            $this->r2->delete($upload->object_key);
        } catch (RuntimeException $e) {
            // Clean-up tries again
        }
    }

    private function backToPending(CommunityVideoUpload $upload): void
    {
        $upload->forceFill(['status' => CommunityVideoUpload::STATUS_PENDING])->save();
    }

    private function fail(CommunityVideoUpload $upload, string $reason): void
    {
        $upload->forceFill(['status' => CommunityVideoUpload::STATUS_FAILED, 'error' => substr($reason, 0, 40)])->save();
        // A file that can't be used isn't kept
        $this->deleteNow($upload);
    }

    private function guardCanPost(User $user): void
    {
        $this->community->assertCanPost($user);
        if (! $this->canPost($user)) {
            throw new BusinessRuleException('video_posts_unavailable', 'Sharing videos isn’t available on your account yet.', 403);
        }
    }
}
