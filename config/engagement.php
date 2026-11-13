<?php

/*
|--------------------------------------------------------------------------
| Booking reminders, suggestion cards and recommendations
|--------------------------------------------------------------------------
|
| Booking reminders are the only reminders sent: the scheduler runs
| `php artisan workmate:reminders` every 15 minutes, and each booking is
| checked again (still confirmed, still at that time) before anything goes
| out. They follow the "Requests and jobs" notification choice.
|
| Suggestions ("Add your services and prices"...) are never sent as
| notifications: they're cards in the pages while they apply
| (App\Services\Suggestions). Recommendations are shown in the app too.
|
*/

return [
    'bookings' => [
        // The day before (down to two hours before), and shortly before
        'day_before' => ['enabled' => true, 'hours_before' => 24],
        'soon' => ['enabled' => true, 'minutes_before' => 60],
    ],

    // Shown in the app, never pushed: jobs and posts from the last N days, and how many
    'recommendations' => ['job_days' => 14, 'jobs' => 6, 'post_days' => 14, 'posts' => 5],
];
