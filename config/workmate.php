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
        // Facts shown in the legal documents (Admin → Legal Documents)
        'legal' => [
            'label' => 'Legal documents',
            'view_permission' => 'legal.manage',
            'change_permission' => 'legal.manage',
        ],
        // Invite Friends (Admin → Invitations)
        'referrals' => [
            'label' => 'Invitations',
            'view_permission' => 'referrals.view',
            'change_permission' => 'referrals.configure',
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
            'type' => 'string', 'max' => 30, 'default' => '0787320647', 'public' => true,
        ],
        'platform.support_email' => [
            'group' => 'platform',
            'label' => 'Support email',
            'description' => 'Shown in the site footer and on the Account page.',
            'type' => 'email', 'default' => 'resnetsystems@gmail.com', 'public' => true,
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
        'legal.ask_acceptance' => [
            'group' => 'legal',
            'label' => 'Ask people to accept the legal documents',
            'description' => 'Shows the welcome and terms on first opening, and asks signed-in people to accept new or materially changed documents.',
            'type' => 'boolean', 'default' => true, 'public' => true,
        ],
        'referrals.attribution_days' => [
            'group' => 'referrals',
            'label' => 'Days an invitation link counts',
            'description' => 'Someone who opens an invitation link and signs up within this many days is credited to the person who invited them.',
            'type' => 'integer', 'min' => 1, 'max' => 90, 'default' => 30,
        ],
        'referrals.daily_review_limit' => [
            'group' => 'referrals',
            'label' => 'Sign-ups in a day before a review',
            'description' => 'More sign-ups than this through one person’s link in 24 hours are held for a person to check instead of counting straight away.',
            'type' => 'integer', 'min' => 2, 'max' => 100, 'default' => 5,
        ],
        'legal.minimum_age' => [
            'group' => 'legal',
            'label' => 'Minimum age',
            'description' => 'People confirm they are at least this old when they sign up.',
            'type' => 'integer', 'min' => 16, 'max' => 21, 'default' => 18, 'public' => true,
        ],
        'legal.registration_number' => [
            'group' => 'legal', 'label' => 'Company registration number',
            'description' => 'ResNet Systems (U) Ltd’s registration number (Electronic Transactions Act s.24).',
            'type' => 'string', 'max' => 60, 'default' => null,
        ],
        'legal.registered_office' => [
            'group' => 'legal', 'label' => 'Registered office',
            'description' => 'The registered office address.',
            'type' => 'string', 'max' => 255, 'default' => null,
        ],
        'legal.directors' => [
            'group' => 'legal', 'label' => 'Directors',
            'description' => 'Names of the directors, separated by commas.',
            'type' => 'string', 'max' => 255, 'default' => null,
        ],
        'legal.service_address' => [
            'group' => 'legal', 'label' => 'Address for legal notices',
            'description' => 'Where documents and legal notices can be delivered.',
            'type' => 'string', 'max' => 255, 'default' => null,
        ],
        'legal.pdpo_certificate' => [
            'group' => 'legal', 'label' => 'PDPO registration certificate',
            'description' => 'Personal Data Protection Office certificate number.',
            'type' => 'string', 'max' => 60, 'default' => null,
        ],
        'legal.dpo_name' => [
            'group' => 'legal', 'label' => 'Data protection officer',
            'description' => 'Named in the Privacy Policy as the privacy contact.',
            'type' => 'string', 'max' => 100, 'default' => 'Mathew Amanyire',
        ],
        'legal.privacy_email' => [
            'group' => 'legal', 'label' => 'Privacy email',
            'description' => 'Where people send privacy requests and complaints.',
            'type' => 'email', 'default' => null,
        ],
        'legal.support_hours' => [
            'group' => 'legal', 'label' => 'Support hours',
            'description' => 'For example “Monday to Friday, 8am to 6pm”.',
            'type' => 'string', 'max' => 100, 'default' => null,
        ],
        'legal.hosting_provider' => [
            'group' => 'legal', 'label' => 'Hosting provider',
            'description' => 'Company hosting WorkMate’s servers in Uganda (listed as a processor).',
            'type' => 'string', 'max' => 120, 'default' => null,
        ],
        'legal.email_provider' => [
            'group' => 'legal', 'label' => 'Email provider',
            'description' => 'Company that delivers WorkMate’s emails (listed as a processor).',
            'type' => 'string', 'max' => 120, 'default' => null,
        ],
        'legal.pdpo_contact' => [
            'group' => 'legal', 'label' => 'PDPO contact',
            'description' => 'How people reach the Personal Data Protection Office, for complaints.',
            'type' => 'string', 'max' => 255, 'default' => null,
        ],
        'legal.liability_cap' => [
            'group' => 'legal', 'label' => 'Liability limit',
            'description' => 'The amount in Terms 32.4, as set by your advocate.',
            'type' => 'string', 'max' => 120, 'default' => null,
        ],
    ],

];
