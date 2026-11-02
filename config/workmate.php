<?php

/*
|--------------------------------------------------------------------------
| Platform settings
|--------------------------------------------------------------------------
|
| The only settings administrators can change through the API. Values saved
| by administrators live in the platform_settings table; anything not saved
| falls back to the default here. Unknown keys are rejected, so a request can
| never create arbitrary configuration.
|
| Each group names the permission needed to see its settings and the one
| needed to change them. Settings marked 'public' are also sent to the app
| (GET /catalog → settings), which applies them: nearby search, the default
| place, support contact, provider sign-ups, maintenance mode, presence.
|
*/

return [

    'setting_groups' => [
        'search' => [
            'label' => 'Location matching',
            'view_permission' => 'location.view',
            'change_permission' => 'location.configure',
        ],
        'presence' => [
            'label' => 'Online presence',
            'view_permission' => 'usage.view',
            'change_permission' => 'admin.manage',
        ],
        'platform' => [
            'label' => 'Platform',
            'view_permission' => 'admin.manage',
            'change_permission' => 'admin.manage',
        ],
    ],

    'settings' => [
        'search.nearby_km' => [
            'group' => 'search',
            'label' => 'Nearby radius (km)',
            'description' => 'Providers within this distance are shown as closest to the customer.',
            'type' => 'integer', 'min' => 1, 'max' => 50, 'default' => 5,
            'below' => 'search.extended_km', 'public' => true,
        ],
        'search.extended_km' => [
            'group' => 'search',
            'label' => 'Wider search radius (km)',
            'description' => 'How far the search widens when too few nearby providers are found.',
            'type' => 'integer', 'min' => 2, 'max' => 200, 'default' => 10,
            'below' => 'search.max_km', 'public' => true,
        ],
        'search.max_km' => [
            'group' => 'search',
            'label' => 'Maximum search distance (km)',
            'description' => 'Providers further than this are only shown when they travel to the customer’s place.',
            'type' => 'integer', 'min' => 5, 'max' => 300, 'default' => 25, 'public' => true,
        ],
        'search.min_nearby_results' => [
            'group' => 'search',
            'label' => 'Minimum nearby results',
            'description' => 'With fewer nearby matches than this, the search widens and says so.',
            'type' => 'integer', 'min' => 1, 'max' => 20, 'default' => 3, 'public' => true,
        ],
        'search.use_service_areas' => [
            'group' => 'search',
            'label' => 'Include providers who travel to the customer’s place',
            'description' => 'A provider based further away who lists the place among those they travel to can appear.',
            'type' => 'boolean', 'default' => true, 'public' => true,
        ],
        'search.same_town_fallback' => [
            'group' => 'search',
            'label' => 'Include providers based in the same town or district',
            'description' => 'Beyond the wider radius but within the maximum distance.',
            'type' => 'boolean', 'default' => true, 'public' => true,
        ],
        'search.gps_area_match_km' => [
            'group' => 'search',
            'label' => 'Match a shared location to a place within (km)',
            'description' => 'Used to check which providers travel to someone who shared their location.',
            'type' => 'integer', 'min' => 1, 'max' => 20, 'default' => 6, 'public' => true,
        ],
        'search.gps_max_age_minutes' => [
            'group' => 'search',
            'label' => 'Refresh a shared location older than (minutes)',
            'description' => 'An older position is asked for again before searching.',
            'type' => 'integer', 'min' => 1, 'max' => 60, 'default' => 2, 'public' => true,
        ],
        'search.rank_availability' => [
            'group' => 'search',
            'label' => 'Prefer providers available now',
            'description' => 'Within the same distance, available providers come first.',
            'type' => 'boolean', 'default' => true, 'public' => true,
        ],
        'search.rank_verification' => [
            'group' => 'search',
            'label' => 'Prefer providers with the tick',
            'description' => 'Within the same distance, providers with checked documents or the blue tick come first.',
            'type' => 'boolean', 'default' => true, 'public' => true,
        ],
        'search.rank_rating' => [
            'group' => 'search',
            'label' => 'Prefer higher-rated providers',
            'description' => 'Within the same distance. Off by default so new providers without reviews aren’t buried.',
            'type' => 'boolean', 'default' => false, 'public' => true,
        ],
        'presence.online_minutes' => [
            'group' => 'presence',
            'label' => 'Online window (minutes)',
            'description' => 'Seen within this time counts as online. Online does not mean available for work.',
            'type' => 'integer', 'min' => 1, 'max' => 30, 'default' => 5,
            'below' => 'presence.recent_minutes',
        ],
        'presence.recent_minutes' => [
            'group' => 'presence',
            'label' => 'Recently active window (minutes)',
            'description' => 'Seen within this time, but not online, counts as recently active.',
            'type' => 'integer', 'min' => 2, 'max' => 1440, 'default' => 30,
        ],
        'presence.heartbeat_seconds' => [
            'group' => 'presence',
            'label' => 'Heartbeat interval (seconds)',
            'description' => 'How often an open app reports that it is still in use.',
            'type' => 'integer', 'min' => 15, 'max' => 600, 'default' => 60, 'public' => true,
        ],
        'platform.default_area' => [
            'group' => 'platform',
            'label' => 'Default place',
            'description' => 'Where searches start when someone hasn’t chosen a place.',
            'type' => 'area', 'default' => 'kampala', 'public' => true,
        ],
        'platform.support_phone' => [
            'group' => 'platform',
            'label' => 'Support phone',
            'description' => 'Shown in the site footer and on the Account page.',
            'type' => 'string', 'max' => 30, 'default' => null, 'public' => true,
        ],
        'platform.support_email' => [
            'group' => 'platform',
            'label' => 'Support email',
            'description' => 'Shown in the site footer and on the Account page.',
            'type' => 'email', 'default' => null, 'public' => true,
        ],
        'platform.provider_signups' => [
            'group' => 'platform',
            'label' => 'Accept new provider applications',
            'description' => 'When off, nobody can send a new provider application. Requested changes can still be sent.',
            'type' => 'boolean', 'default' => true, 'public' => true,
        ],
        'platform.maintenance_mode' => [
            'group' => 'platform',
            'label' => 'Maintenance mode',
            'description' => 'Shows a notice and pauses new jobs, requests, applications and hiring. The admin keeps working.',
            'type' => 'boolean', 'default' => false, 'public' => true,
        ],
    ],

];
