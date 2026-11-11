<?php

namespace App\Services;

use App\Models\ServiceRequest;

/**
 * When and where a request is, as each person involved may see it.
 *
 * The exact point and the directions are private: the customer always sees
 * them; the provider only once they've accepted (the owner's decision).
 * Before that the provider sees the area and the landmark. Nobody else sees
 * any of it through these.
 */
class RequestDetails
{
    public static function schedule(ServiceRequest $r): array
    {
        $iso = function ($at) {
            return $at ? $at->copy()->setTimezone(Scheduling::TIMEZONE)->toIso8601String() : null;
        };

        return [
            'timing' => $r->timing,
            'start' => $iso($r->scheduled_start_at),
            'end' => $iso($r->scheduled_end_at),
            'duration_minutes' => $r->duration_minutes,
            // A time the provider suggested, waiting for the customer
            'proposed_start' => $iso($r->proposed_start_at),
            'proposed_duration_minutes' => $r->proposed_duration_minutes,
            'started_at' => $iso($r->started_at),
            'cancelled_by' => $r->cancelled_by,
        ];
    }

    /** $exact: whether this viewer may see the exact point and directions */
    public static function location(ServiceRequest $r, bool $exact): array
    {
        $hasPin = $r->latitude !== null && $r->longitude !== null;

        return [
            'area_name' => $r->area_name,
            'landmark' => $r->landmark,
            'has_pin' => $hasPin,
            'directions' => $exact ? $r->directions : null,
            'latitude' => $exact && $hasPin ? $r->latitude : null,
            'longitude' => $exact && $hasPin ? $r->longitude : null,
            'accuracy_m' => $exact && $hasPin ? $r->location_accuracy_m : null,
        ];
    }

    /** The provider's view: exact once accepted */
    public static function forProvider(ServiceRequest $r): array
    {
        return ['schedule' => self::schedule($r), 'location' => self::location($r, $r->isAccepted())];
    }

    /** The customer's own request: everything */
    public static function forCustomer(ServiceRequest $r): array
    {
        return ['schedule' => self::schedule($r), 'location' => self::location($r, true)];
    }
}
