<?php

/*
|--------------------------------------------------------------------------
| Smart push policy (Step 5)
|--------------------------------------------------------------------------
|
| Used only while the platform setting notifications.smart_push is on.
| Likes and follows are low priority: they are grouped in the bell always, and
| pushed (at most a few times a day) only under these rules. Bookings,
| messages and account notices are never held back by any of this.
|
*/

return [
    // Likes on one post or video, for its author
    'likes' => [
        'window_minutes' => 15,     // wait this long after the first like before pushing
        'early_at' => 3,            // ...or push as soon as this many different people liked
        'cooldown_hours' => 3,      // no further like push about the same content within this time
        'max_per_day' => 2,         // like pushes per person per local day, all content together
    ],

    // New followers
    'follows' => [
        'window_minutes' => 30,
        'early_at' => 5,
        'cooldown_hours' => 6,
        'max_per_day' => 1,
    ],

    // Delivery: bounded retries with growing waits (minutes), then given up
    'retry_minutes' => [1, 5, 30],
    // A push claimed but never finished (the process died) is made ready again after this
    'claim_recovery_minutes' => 10,
    // A push still waiting this long after it was due is dropped as stale
    'stale_after_hours' => 24,
    // A device that failed this many times in a row is forgotten
    'forget_device_after_failures' => 8,
    // At most this many pushes per flush run, and finished rows are kept this long
    'flush_batch' => 100,
    'keep_days' => 30,

    // Quiet hours when an account has not chosen a timezone
    'default_timezone' => 'Africa/Kampala',
];
