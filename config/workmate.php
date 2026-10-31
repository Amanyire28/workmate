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
| needed to change them. Defaults match what the frontend uses today
| (SEARCH_CONFIG in utils/nearbySearch.js, PRESENCE_DEFAULTS in adminApi.js).
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
    ],

    'settings' => [
        'search.nearby_km' => [
            'group' => 'search',
            'label' => 'Nearby radius (km)',
            'description' => 'Providers within this distance are shown as closest to the customer.',
            'type' => 'integer', 'min' => 1, 'max' => 50, 'default' => 5,
            'below' => 'search.extended_km',
        ],
        'search.extended_km' => [
            'group' => 'search',
            'label' => 'Wider search radius (km)',
            'description' => 'How far the search widens when too few nearby providers are found.',
            'type' => 'integer', 'min' => 2, 'max' => 200, 'default' => 10,
        ],
        'search.min_nearby_results' => [
            'group' => 'search',
            'label' => 'Minimum nearby results',
            'description' => 'With fewer nearby matches than this, the search widens and says so.',
            'type' => 'integer', 'min' => 1, 'max' => 20, 'default' => 3,
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
            'type' => 'integer', 'min' => 15, 'max' => 600, 'default' => 60,
        ],
    ],

];
