<?php

/*
|--------------------------------------------------------------------------
| Photo watermark
|--------------------------------------------------------------------------
|
| Public photos people add (community posts and providers' work) carry the
| WorkMate logo and wm.resnetsystems.com in the bottom-right corner, drawn
| into the picture itself (App\Services\ImageWatermark). Each photo is done
| once, when it's added; a clean copy is kept privately so it can be redone
| without stacking watermarks. Private photos (chats, job photos, documents,
| profile photos) are never watermarked.
|
| The stamp image (logo and address together) is made from
| resources/watermark/stamp.html; see that file to change it.
|
*/

return [
    // Switch off with WATERMARK_ENABLED=false (new photos are then kept as before)
    'enabled' => (bool) env('WATERMARK_ENABLED', true),

    'stamp' => resource_path('watermark/workmate-watermark.png'),

    // What the stamp says (it's part of the stamp image; kept here for reference and checks)
    'text' => 'wm.resnetsystems.com',

    // The logo and address: 0 = invisible, 1 = solid
    'opacity' => (float) env('WATERMARK_OPACITY', 0.30),

    // The soft dark halo around them, a little stronger so white letters read on bright photos
    'shadow_opacity' => (float) env('WATERMARK_SHADOW_OPACITY', 0.55),

    // The stamp's width as a share of the photo's width (opened on a phone, every
    // photo fills the screen's width, so this keeps the address readable), and its limits
    'size' => 0.28,
    'min_width' => 90,          // px: below this the address can't be read
    'max_share' => 0.5,         // never wider than half the photo
    'max_height_share' => 0.2,  // never taller than a fifth of it (wide photos)

    // Gap from the bottom and right edges, as a share of the shorter side (at least 6 px)
    'margin' => 0.03,

    // JPEG quality of the watermarked full size and thumbnail
    'quality' => ['full' => 84, 'thumb' => 78],

    // Which kinds of photo are watermarked (public ones only)
    'categories' => [
        'community' => true,   // community post photos
        'work' => true,        // providers' recent work and featured projects
    ],

    // Refused before decoding (protects the server from "decompression bomb" images)
    'max_pixels' => 64000000,
    'max_side' => 12000,
];
