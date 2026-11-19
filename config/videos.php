<?php

/*
|--------------------------------------------------------------------------
| Community videos (Cloudflare R2)
|--------------------------------------------------------------------------
|
| Videos go straight from the member's phone to a private R2 bucket using
| short-lived signed addresses; they are never stored, downloaded or
| processed on the WorkMate server. Laravel only signs addresses, checks the
| upload's size and MP4 header (a few small byte ranges, in memory), and
| publishes the post. See docs/community-video-api.md.
|
| Who may post videos, and the size and length limits, are platform settings
| (Admin → Settings → Community videos). The keys below come from the server's
| .env only and are never sent to browsers.
|
*/

return [
    'r2' => [
        'account_id' => env('R2_ACCOUNT_ID'),
        'access_key_id' => env('R2_ACCESS_KEY_ID'),
        'secret_access_key' => env('R2_SECRET_ACCESS_KEY'),
        'bucket' => env('R2_VIDEO_BUCKET', 'workmate-community-videos'),
        // Defaults to https://<account_id>.r2.cloudflarestorage.com
        'endpoint' => env('R2_ENDPOINT'),
    ],

    // How long a signed upload address works (seconds): long enough for 25 MB on a slow connection
    'upload_url_seconds' => 1800,

    // How long after asking for an address the upload can still be finished
    'upload_window_minutes' => 120,

    // Uploads one member can have in progress at once
    'max_pending_uploads' => 3,

    // How long a signed playback address works (seconds). A removed post gets no new
    // address, but one already handed out keeps working until it runs out.
    'playback_url_seconds' => 3600,

    // A video in a post its author deleted is removed from storage after this many days
    'delete_after_days' => 7,

    // The MP4 header (moov box) is read into memory to check the video; larger ones are refused
    'max_header_bytes' => 4 * 1024 * 1024,
];
