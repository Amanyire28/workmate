<?php

/*
|--------------------------------------------------------------------------
| Push delivery policy
|--------------------------------------------------------------------------
|
| Used only while the platform setting notifications.smart_push is on.
|
| Nothing here makes a notification wait. Likes, follows, comments and mentions are
| saved in the notification list at once and a push is tried at once after the
| change is committed. The limits below only keep a phone from being buzzed
| over and over: past them, a push is still delivered, but silently (it replaces
| the one already on the screen), and the notification list is never affected.
|
*/

return [
    // Phone alerts that make a sound for one person: after this many in an hour, further ones arrive silently
    'audible_per_hour' => 30,
    // Anti-abuse: most pushes tried for one person in a minute (their list still gets everything)
    'pushes_per_minute' => 60,
    // A push still not delivered this long after it was made is dropped as stale (an old like is not news)
    'stale_after_minutes' => 180,

    // Retries when no device took a push: seconds to wait after each failed try, then given up
    'retry_seconds' => [20, 60, 300, 1800],
    // A push claimed but never finished (the process died) is made ready again after this
    'claim_recovery_minutes' => 10,
    // A device that failed this many times in a row is forgotten
    'forget_device_after_failures' => 8,
    // At most this many waiting pushes per scheduler run, and finished rows are kept this long
    'flush_batch' => 200,
    'keep_days' => 30,

    // Quiet hours when an account has not chosen a timezone
    'default_timezone' => 'Africa/Kampala',
];
