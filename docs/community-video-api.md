# Community video posts: API (Phase 2, implemented)

Videos are uploaded straight from the member's browser to the private Cloudflare R2
bucket `workmate-community-videos` and played from it. They are never stored, downloaded or
processed on the WorkMate server. Laravel signs short-lived addresses (AWS Signature V4,
`App\Services\R2Storage`, no SDK), checks each upload, and publishes the post.

## Who may post

The settings are in Admin → Settings → Platform → Community videos (`config/workmate.php`):

| Key | Default | Meaning |
|---|---|---|
| `community.video_posts` | off | Every member who can post may add videos |
| `community.video_pilot` | off | Admins (`admin-access`) and listed testers may add videos |
| `community.video_testers` | empty | Tester emails, separated by commas |
| `community.video_max_mb` | 25 | Largest file (public) |
| `community.video_max_seconds` | 60 | Longest video (public) |

`GET /api/v1/me` returns `can_post_video` for the signed-in member. It is false whenever the
R2 keys are missing. The usual community rules still apply: a confirmed email, no maintenance mode, and
an active account. Everyone can watch published videos.

## 1. Ask for an upload address

`POST /api/v1/community/video-uploads` (`auth:sanctum`, `active`, `throttle:community-post`)

```json
{ "category": "my_work", "body": "…", "topic": "plumbing",
  "file_name": "clip.mp4", "mime_type": "video/mp4", "size": 11534336,
  "duration": 42.3, "width": 720, "height": 1280, "client_token": "uuid" }
```

`201` →

```json
{ "data": { "upload_id": "uuid",
  "upload": { "url": "https://<account>.r2.cloudflarestorage.com/workmate-community-videos/community/2026/11/<40 random chars>.mp4?X-Amz-…",
              "method": "PUT", "headers": { "Content-Type": "video/mp4" } },
  "expires_at": "…" } }
```

- The server chooses the object key, which can't be guessed. The browser never names it.
- The address is signed for `PUT`, that key, `Content-Type: video/mp4` **and the exact
  `Content-Length`**, so a different or larger file is refused by R2. It is valid for 30 minutes.
- The same `client_token` returns the same upload (safe to retry). A member can have at most 3
  uploads in progress.
- Errors: `422` (validation: only `video/mp4`, size, duration), `403 video_posts_unavailable`,
  `403 email_not_verified`, `429 too_many_uploads`, `409 upload_used`, `429` (rate limit).

## 2. Upload (browser to R2)

`PUT <url>` with the file as the body and the returned headers. No cookies are sent.
R2 CORS must allow `PUT` with `Content-Type` from the WorkMate origins.

## 3. Finish

`POST /api/v1/community/video-uploads/{upload_id}/complete` (multipart, optional `poster`
JPEG/PNG/WebP up to 2 MB). Only the member's own uploads; anyone else gets `404`.

The server:
1. Uses HEAD to check the object exists and is exactly the announced size (and within the limit).
2. Reads the MP4 header with small ranged GETs, held in memory and capped at 4 MB
   (`App\Services\Mp4Inspector`). It requires an `ftyp` box, not the QuickTime brand, a `moov` box, no
   fragments, H.264 video (`avc1`/`avc3`), AAC sound (`mp4a`) or none, and the real duration
   within the limit.
3. Stores the poster as a watermarked community photo (local disk, a few KB).
4. Publishes the post (audit log `community.post_published` with `videos: 1`, followers told).

`201` → `{ "data": <post> }`. Calling it again returns the same post.

Errors:
- `422 video_not_received`: the object is missing (the upload stays open so it can be retried) or is the wrong size (refused).
- `422 video_hevc`, `video_mov`, `video_codec`, `video_audio_codec`, `video_fragmented`, `video_damaged`, `video_too_long`: refused, and the file is deleted.
- `410 upload_expired`: more than 2 hours after asking.
- `409`: already being finished, or stopped.
- `503 video_storage_unavailable`: R2 couldn't be reached; the upload can be finished later.

## Cancel

`DELETE /api/v1/community/video-uploads/{upload_id}` → `204`. The file is deleted.

## Post media

Photos are unchanged (no `type`). A video post has one media item:

```json
{ "id": "uuid", "type": "video",
  "url": "https://…r2.cloudflarestorage.com/…mp4?X-Amz-…",   // signed GET, 1 hour; null when not playable
  "poster_url": "/api/v1/community/media/<uuid>.jpg", "thumb_url": "…-t.jpg",  // null without a poster
  "width": 720, "height": 1280, "duration": 42.3, "size": 11534336,
  "status": "ready" }   // or "unavailable": the post was removed or deleted, or the video deleted
```

`GET /api/v1/community/media/{id}/playback` → `{ url, expires_at }`: a fresh address for a
video that may still be watched, otherwise `404`. The player calls it when an address has run out.
Playback uses HTTP range requests straight from R2, and Laravel never relays video bytes.

A removed or deleted post gets **no new addresses**. One already handed out keeps
working until it expires (at most 1 hour).

## Cleanup

`workmate:video-cleanup` runs hourly (scheduler). It:
- expires uploads that were never finished;
- deletes the files of failed, stopped and expired uploads, once their upload window has closed;
- deletes the video files of posts deleted by their authors more than 7 days ago (`config/videos.php`).

Failures are retried on the next run. Nothing is downloaded. Posts removed by moderators keep their
video, so they can be restored.

## Limits of the checks

- Only the container header is checked. A file with a valid header but damaged picture data
  would pass, then not play. No transcoding, compression or frame decoding happens anywhere.
- The duration and codec come from the file's own header, not from what the browser says.
- The phone runs the same header check before uploading, so refused files don't use up data.
