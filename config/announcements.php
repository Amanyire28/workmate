<?php

/*
|--------------------------------------------------------------------------
| Release announcements ("What's New on WorkMate")
|--------------------------------------------------------------------------
|
| A short card shown once to everyone after a release, with a detailed view
| of what changed. Each announcement has its own id: dismissing one never
| hides a later one, and new deploys don't reset what people dismissed.
|
| Only `current` is shown. To announce a release, set its `enabled` to true
| in the commit that ships it (or set WHATS_NEW_ENABLED=true in the server's
| .env and refresh the config cache). It stays off until then, whatever the
| date: `starts_at` can only hold it back further, never switch it on.
|
| `known` lists ids people may acknowledge (the current one and earlier
| ones), so the acknowledgement endpoint never stores made-up ids.
|
*/

return [
    'current' => [
        'id' => 'workmate-major-booking-update-2026-11',
        // Switched on for the approved November 2026 release (WHATS_NEW_ENABLED=false in .env turns it off)
        'enabled' => (bool) env('WHATS_NEW_ENABLED', true),
        // Optional: not before this moment (ISO 8601), even when enabled
        'starts_at' => env('WHATS_NEW_STARTS_AT'),
        // everyone | signed_in | guests
        'audience' => 'everyone',
        // How long the small card stays before it goes by itself (it counts as seen)
        'display_seconds' => 20,
        // Wait this long after the app is ready before showing it
        'delay_seconds' => 2,

        'header' => 'What’s New on WorkMate! 🎉',
        'title' => 'Finding and booking services just got easier!',
        'summary' => 'We’ve improved WorkMate to help you find the right service provider, choose when you need them, and manage your bookings more easily.',
        'items' => [
            ['icon' => 'bi-tags', 'title' => 'Services & Prices', 'text' => 'See the services providers offer and their usual prices before requesting work.'],
            ['icon' => 'bi-calendar-check', 'title' => 'Book for a Date & Time', 'text' => 'Choose when you need a service, including future appointments.'],
            ['icon' => 'bi-person-check', 'title' => 'Smarter Provider Availability', 'text' => 'See whether a provider is available now, busy but accepting bookings, or temporarily paused.'],
            ['icon' => 'bi-circle-fill', 'title' => 'Online & Last Seen', 'text' => 'Know when providers are online or were last active, where they have enabled visibility.'],
            ['icon' => 'bi-geo-alt', 'title' => 'Better Service Locations', 'text' => 'Use your current location or choose the exact place where you need the work done.'],
            ['icon' => 'bi-chat-square-text', 'title' => 'Improved Booking Updates', 'text' => 'Follow the status of your request and see important booking details in your conversations.'],
        ],
        'closing' => 'WorkMate keeps getting better for you. 💙',
        'primary_action' => 'Explore What’s New',
        'secondary_action' => 'Got it',
    ],

    // Every announcement id ever used (newest first)
    'known' => [
        'workmate-major-booking-update-2026-11',
    ],
];
