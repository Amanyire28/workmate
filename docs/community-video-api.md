# Community video posts: API (Phase 2, implemented)

Videos are uploaded straight from the member's browser to the private Cloudflare R2
bucket `workmate-community-videos` and played from it. They are never stored, downloaded or
processed on the WorkMate server. Laravel signs short-lived addresses (AWS Signature V4,
`App\Services\R2Storage`, no SDK), checks each upload, and publishes the post.

## Who may post

Anyone who may post in the community may add a video, exactly as with photos. They must be
signed in, have an active (not suspended) account and a confirmed email, and maintenance mode
must be off. There is no separate video permission or switch.

Only the size limit is a setting (Admin → Settings → Platform → Community videos, public):

| Key | Default |
|---|---|
| `community.video_max_mb` | 25 |

There is no length limit: a video of any length is fine within the size limit.

If the server can't see the R2 keys, uploads are refused with `503 video_storage_unavailable`
and an error is logged. Run `php artisan workmate:video-check` on the server: it shows which
keys Laravel sees (never their values) and whether R2 accepts them.

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
- Errors: `422` (validation: only `video/mp4`, size, duration), `401` signed out,
  `403 email_not_verified`, `403 account_inactive`, `503 maintenance`,
  `503 video_storage_unavailable`, `429 too_many_uploads`, `409 upload_used`, `429` (rate limit).

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

## Formats

Original files are kept byte for byte, with no conversion. Accepted, after the server has checked the file's own structure:

| Structure | Files | Video codecs accepted | Sound |
|---|---|---|---|
| ISO media (`ftyp`) | MP4, MOV, 3GP, M4V | H.264, H.265/HEVC (incl. Dolby Vision), VP9, AV1, MPEG-4, H.263 | any |
| EBML | WebM, MKV | VP8, VP9, AV1, H.264, H.265 | any |

- Upload types: `video/mp4`, `video/quicktime`, `video/3gpp`, `video/x-m4v`, `video/webm`, `video/x-matroska`.
  The announced type must match the file's real structure (`422 video_unsupported_format`).
- Stored as `video/mp4` (ISO) or `video/webm` (EBML). Browsers look inside the file anyway.
- Refused (`422`): unknown or encrypted video codecs, a missing picture, more than 8192 px, a broken structure,
  or any other container (AVI, FLV, MPEG-TS…).
- The length comes from the file (`mvhd`, `mehd`, or Matroska `Duration`). When the file doesn't state it
  (common in browser-made WebM), it's stored as unknown, never taken from the phone.
- **Playback depends on the viewer's device.** H.265/HEVC plays on iPhones and most recent Android phones,
  but not everywhere. The player says clearly when a format can't play in that browser.

## Trimming on the phone (videos over the size limit)

`resources/js/utils/videoTrim.js` (Mediabunny 1.65.1, MPL-2.0) is downloaded only when a chosen video is over
the limit (about 127 KB gzipped).

- The member picks a start and an end. They see the kept length and an estimated size.
- The chosen part is **copied** into a new file of the same kind (MOV stays MOV, WebM stays WebM) without
  decoding or re-encoding, so picture, sound and quality are unchanged. This also works for formats the
  browser can't play.
- Copying cuts at key frames, so the clip may start up to a key-frame interval (about 1–2 s on phones)
  earlier than chosen.
- The real file is measured. If it's still over the limit, the trimmer reopens and asks for a shorter part.
  Only a complete file within the limit is ever uploaded.
- If the sound can't be copied in this browser, trimming stops; the sound is never dropped quietly.
- If the browser can't read the format, the member is told so and shown how to trim it in the phone's Gallery.
- The server checks the trimmed file like any other upload.
