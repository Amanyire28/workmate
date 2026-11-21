<?php

namespace App\Support;

use Carbon\Carbon;
use DateTimeZone;

/**
 * An account's quiet hours: local "HH:MM" start and end in its timezone. An
 * interval may cross midnight (22:00 to 07:00). Everything here takes and
 * returns real moments, so it follows now() and test clocks.
 */
class QuietHours
{
    /** @var string */
    private $start;

    /** @var string */
    private $end;

    /** @var DateTimeZone */
    private $zone;

    public function __construct(string $start, string $end, ?string $timezone = null)
    {
        $this->start = $start;
        $this->end = $end;
        $this->zone = new DateTimeZone(self::validZone($timezone) ? $timezone : config('notification_policy.default_timezone'));
    }

    /** The account's quiet hours, or null when it has none */
    public static function forUser($user): ?self
    {
        if (! $user->quiet_hours_start || ! $user->quiet_hours_end || $user->quiet_hours_start === $user->quiet_hours_end) {
            return null;
        }

        return new self($user->quiet_hours_start, $user->quiet_hours_end, $user->timezone);
    }

    public static function validTime(?string $time): bool
    {
        return is_string($time) && (bool) preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $time);
    }

    public static function validZone(?string $zone): bool
    {
        return is_string($zone) && $zone !== '' && in_array($zone, DateTimeZone::listIdentifiers(), true);
    }

    private static function minutes(string $time): int
    {
        return (int) substr($time, 0, 2) * 60 + (int) substr($time, 3, 2);
    }

    private function local(Carbon $at): Carbon
    {
        return $at->copy()->setTimezone($this->zone);
    }

    public function isQuiet(Carbon $at): bool
    {
        $local = $this->local($at);
        $now = $local->hour * 60 + $local->minute;
        $start = self::minutes($this->start);
        $end = self::minutes($this->end);

        return $start < $end
            ? ($now >= $start && $now < $end)
            : ($now >= $start || $now < $end);   // crosses midnight
    }

    /** When the quiet period that $at is in ends (null if $at is not quiet) */
    public function endsAt(Carbon $at): ?Carbon
    {
        if (! $this->isQuiet($at)) {
            return null;
        }
        $local = $this->local($at);
        $end = $local->copy()->setTime((int) substr($this->end, 0, 2), (int) substr($this->end, 3, 2), 0);
        if ($end->lte($local)) {
            $end->addDay();
        }

        return $end->setTimezone('UTC');
    }

    /** When the quiet period that $at is in began (null if $at is not quiet) */
    public function startedAt(Carbon $at): ?Carbon
    {
        if (! $this->isQuiet($at)) {
            return null;
        }
        $local = $this->local($at);
        $start = $local->copy()->setTime((int) substr($this->start, 0, 2), (int) substr($this->start, 3, 2), 0);
        if ($start->gt($local)) {
            $start->subDay();
        }

        return $start->setTimezone('UTC');
    }

    /** The account's timezone (or the Uganda default), for its local day */
    public static function zoneOf($user): DateTimeZone
    {
        return new DateTimeZone(self::validZone($user->timezone) ? $user->timezone : config('notification_policy.default_timezone'));
    }

    /** The start of the account's local day that $at falls in, as a real moment */
    public static function dayStart($user, Carbon $at): Carbon
    {
        return $at->copy()->setTimezone(self::zoneOf($user))->startOfDay()->setTimezone('UTC');
    }

    public static function nextDayStart($user, Carbon $at): Carbon
    {
        return $at->copy()->setTimezone(self::zoneOf($user))->startOfDay()->addDay()->setTimezone('UTC');
    }
}
