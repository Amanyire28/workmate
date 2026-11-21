<?php

namespace App\Services;

use App\Exceptions\BusinessRuleException;
use App\Models\CommunityPost;
use App\Models\CommunityPostMedia;
use App\Models\CommunityVideoUpload;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Video posts in the community. The phone uploads straight to the private R2
 * bucket with an address signed here; nothing passes through or is stored on
 * this server except the small poster image. A post is made only once the
 * upload has arrived and its MP4 header passes the checks (Mp4Inspector).
 *
 * Who may post a video: anyone who may post in the community (signed in,
 * active account, confirmed email, no maintenance), exactly as for photos.
 * Everyone can watch published videos.
 */
class CommunityVideos
{
    /** @var R2Storage */
    private $r2;

    /** @var Mp4Inspector */
    private $inspector;

    /** @var MatroskaInspector */
    private $matroska;

    /** @var Community */
    private $community;

    /** @var PhotoVariants */
    private $photos;

    /** @var PlatformSettings */
    private $settings;

    public function __construct(R2Storage $r2, Mp4Inspector $inspector, MatroskaInspector $matroska, Community $community, PhotoVariants $photos, PlatformSettings $settings)
    {
        $this->r2 = $r2;
        $this->inspector = $inspector;
        $this->matroska = $matroska;
        $this->community = $community;
        $this->photos = $photos;
        $this->settings = $settings;
    }

    /* ---------------- Limits ---------------- */

    public function maxBytes(): int
    {
        return (int) $this->settings->get('community.video_max_mb') * 1024 * 1024;
    }

    /**
     * The kinds of file accepted (as the phone names them) and how each is
     * stored: MP4, MOV and 3GP are one structure (ISO media), WebM and MKV another.
     */
    public const TYPES = [
        'video/mp4' => 'iso', 'video/quicktime' => 'iso', 'video/3gpp' => 'iso', 'video/x-m4v' => 'iso',
        'video/webm' => 'ebml', 'video/x-matroska' => 'ebml',
    ];

    // What storage tells browsers it is (they look inside the file anyway)
    private const STORED_AS = ['iso' => ['video/mp4', 'mp4'], 'ebml' => ['video/webm', 'webm']];

    // The length columns hold up to this (2.7 hours): far beyond what 25 MB holds
    private const MAX_STORED_SECONDS = 9999.99;

    /* ---------------- 1. Where to upload ---------------- */

    /**
     * @param  array{category: string, body: ?string, topic: ?string, mime_type: string, size: int, duration: float, width: int, height: int, client_token: string}  $data
     * @return array{upload: CommunityVideoUpload, url: string, headers: array<string, string>}
     */
    public function authorize(User $user, array $data): array
    {
        $this->guardCanPost($user);
        // Mentions in the caption are checked now, not after the whole video has uploaded
        $this->community->checkCaption($user, $data['body'] ?? null);

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

        [$storedType, $extension] = self::STORED_AS[self::TYPES[$data['mime_type']]];
        $upload = new CommunityVideoUpload();
        $upload->forceFill([
            'uuid' => (string) Str::uuid(),
            'user_id' => $user->getKey(),
            'client_token' => $data['client_token'],
            // Chosen here, never by the phone, and impossible to guess
            'object_key' => 'community/'.now()->format('Y/m').'/'.Str::lower(Str::random(40)).'.'.$extension,
            'status' => CommunityVideoUpload::STATUS_PENDING,
            'category' => $data['category'],
            'body' => $data['body'] ?? null,
            'topic' => $data['topic'] ?? null,
            'mime_type' => $storedType,
            'size' => (int) $data['size'],
            // What the phone said; only for reference (the file's own header decides)
            'duration' => min(self::MAX_STORED_SECONDS, (float) ($data['duration'] ?? 0)),
            'width' => (int) $data['width'],
            'height' => (int) $data['height'],
            'expires_at' => now()->addMinutes((int) config('videos.upload_window_minutes')),
        ])->save();

        return $this->ticket($upload);
    }

    /** The signed upload address: only this object, this type and exactly this size */
    private function ticket(CommunityVideoUpload $upload): array
    {
        $headers = ['Content-Type' => $upload->mime_type];
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
            // Which structure the file really has decides how it's checked (not its name or type)
            // One ranged read of the start serves the type check and the header; more only if the header is further on
            $start = $this->r2->read($upload->object_key, 0, (int) min(262144, $object['size']));
            $read = function (int $offset, int $length) use ($upload, $start) {
                return $offset + $length <= strlen($start)
                    ? (string) substr($start, $offset, $length)
                    : $this->r2->read($upload->object_key, $offset, $length);
            };
            $first = substr($start, 0, 16);
            $family = MatroskaInspector::looksLike($first) ? 'ebml' : (Mp4Inspector::looksLike($first) ? 'iso' : null);
            if ($family === null || self::STORED_AS[$family][0] !== $upload->mime_type) {
                throw new BusinessRuleException('video_unsupported_format', 'This file isn’t a video format we can check. Choose an MP4, MOV or WebM video.', 422);
            }
            $facts = $family === 'ebml'
                ? $this->matroska->inspect($read, $object['size'])
                : $this->inspector->inspect($read, $object['size'], (int) config('videos.max_header_bytes'));
        } catch (BusinessRuleException $e) {
            $this->fail($upload, $e->errorCode());
            throw $e;
        } catch (RuntimeException $e) {
            $this->backToPending($upload);
            Log::warning('Video storage unreachable while checking an upload', ['upload' => $upload->uuid, 'error' => $e->getMessage()]);
            throw new BusinessRuleException('video_storage_unavailable', 'We couldn’t reach video storage. Please try again in a moment.', 503);
        }

        // The picture size the phone saw (after rotation) when it matches the file, either way round
        [$width, $height] = $this->displaySize($upload, $facts);

        $stored = $poster ? [$this->photos->store($poster, 'community', 'community')] : [];
        try {
            $post = DB::transaction(function () use ($user, $upload, $facts, $stored, $width, $height) {
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
                    'duration' => $facts['duration'] === null ? null : min(self::MAX_STORED_SECONDS, $facts['duration']),
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

        return $post;
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

    /** The community's own posting rules, and storage that's set up */
    private function guardCanPost(User $user): void
    {
        $this->community->assertCanPost($user);
        if (! $this->r2->configured()) {
            Log::error('Community video upload refused: R2 keys are missing from the server configuration (R2_* in .env, then php artisan config:cache).');
            throw new BusinessRuleException('video_storage_unavailable', 'Video uploads aren’t available right now. Please try again later, or share photos instead.', 503);
        }
    }
}
