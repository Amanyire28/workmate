<?php

namespace App\Services;

use App\Exceptions\BusinessRuleException;
use App\Models\ProviderProfile;
use App\Models\ProviderUnavailablePeriod;
use App\Models\ServiceRequest;
use Carbon\Carbon;

/**
 * Appointment times and double-booking prevention.
 *
 * - Times are entered and shown in Uganda time (Africa/Kampala) and stored in UTC.
 * - Only confirmed bookings (scheduled, in progress) reserve a provider's
 *   time; pending requests never do.
 * - A booking without a stated length reserves the admin's default
 *   (booking.default_duration_minutes); "as soon as possible" reserves that
 *   long from the moment it's accepted.
 * - One appointment at a time per provider business (the owner's decision),
 *   checked with the standard overlap test (start < other end AND end > other
 *   start) and never in the browser alone.
 * - Callers confirm a time inside a transaction after lockProvider(), so two
 *   acceptances at the same moment are checked one after the other.
 */
class Scheduling
{
    public const TIMEZONE = 'Africa/Kampala';

    // Bookable from shortly after now, up to a few months ahead
    public const MIN_LEAD_MINUTES = 30;
    public const MAX_DAYS_AHEAD = 120;
    public const DURATIONS = [30, 60, 90, 120, 180, 240, 300, 360, 480, 600, 720];

    /** @var PlatformSettings */
    private $settings;

    public function __construct(PlatformSettings $settings)
    {
        $this->settings = $settings;
    }

    public function defaultDuration(): int
    {
        return max(30, (int) $this->settings->get('booking.default_duration_minutes'));
    }

    /** "2026-11-20 10:00" in Uganda time → a UTC moment */
    public function parseLocal(string $value): Carbon
    {
        try {
            $at = Carbon::createFromFormat('Y-m-d H:i', $value, self::TIMEZONE);
        } catch (\Throwable $e) {
            $at = null;
        }
        if (! $at) {
            throw new BusinessRuleException('time_invalid', 'Choose a date and time.', 422);
        }

        return $at->setTimezone('UTC');
    }

    /** A start customers or providers may choose: not in the past, not too far ahead */
    public function guardBookable(Carbon $start): void
    {
        if ($start->lt(now()->addMinutes(self::MIN_LEAD_MINUTES - 1))) {
            throw new BusinessRuleException('time_too_soon', 'Choose a time at least 30 minutes from now, or choose “As soon as possible”.', 422);
        }
        if ($start->gt(now()->addDays(self::MAX_DAYS_AHEAD))) {
            throw new BusinessRuleException('time_too_far', 'Choose a date within the next '.self::MAX_DAYS_AHEAD.' days.', 422);
        }
    }

    /** The provider's row, locked for the rest of the transaction: one booking change at a time */
    public function lockProvider(int $providerId): ProviderProfile
    {
        return ProviderProfile::whereKey($providerId)->lockForUpdate()->firstOrFail();
    }

    /**
     * Whether the provider already has a confirmed booking or time off in
     * [start, end). $except leaves out the request being changed.
     * $confirming: also apply the legacy rule (see legacyHold), which only
     * stops confirmations, never a customer sending a request.
     */
    public function hasConflict(int $providerId, Carbon $start, Carbon $end, ?int $except = null, bool $confirming = true): bool
    {
        return $this->conflictReason($providerId, $start, $end, $except, $confirming) !== null;
    }

    /** null when free; 'booked' (a booking or time off) or 'legacy' (an older job still in progress) */
    public function conflictReason(int $providerId, Carbon $start, Carbon $end, ?int $except = null, bool $confirming = true): ?string
    {
        if ($this->bookedOrAway($providerId, $start, $end, $except)) {
            return 'booked';
        }

        return $confirming && $this->legacyHold($providerId, $start, $except) ? 'legacy' : null;
    }

    /**
     * A job from before the booking system that's still in progress has no
     * known end, so its time can't be checked. Rather than treat the day as
     * free, nothing starting before the end of today (Uganda time) is
     * confirmed until the provider marks that job done. Later days are fine.
     * Nothing is added to the older job.
     */
    public function legacyHold(int $providerId, Carbon $start, ?int $except = null): bool
    {
        $endOfToday = now()->setTimezone(self::TIMEZONE)->endOfDay()->setTimezone('UTC');
        if ($start->gt($endOfToday)) {
            return false;
        }

        return ServiceRequest::where('provider_profile_id', $providerId)
            ->where('booking_version', ServiceRequest::BOOKING_LEGACY)
            ->where('status', ServiceRequest::STATUS_IN_PROGRESS)
            ->when($except, function ($q) use ($except) {
                $q->whereKeyNot($except);
            })
            ->exists();
    }

    private function bookedOrAway(int $providerId, Carbon $start, Carbon $end, ?int $except): bool
    {
        $booked = ServiceRequest::where('provider_profile_id', $providerId)
            ->whereIn('status', ServiceRequest::BLOCKING_STATUSES)
            ->whereNotNull('scheduled_start_at')->whereNotNull('scheduled_end_at')
            ->where('scheduled_start_at', '<', $end)
            ->where('scheduled_end_at', '>', $start)
            ->when($except, function ($q) use ($except) {
                $q->whereKeyNot($except);
            })
            ->exists();
        if ($booked) {
            return true;
        }

        return ProviderUnavailablePeriod::where('provider_profile_id', $providerId)
            ->where('starts_at', '<', $end)
            ->where('ends_at', '>', $start)
            ->exists();
    }

    /**
     * Throws the customer- or provider-facing message when the time is taken.
     * $confirming: false when a customer is only sending a request (the
     * provider reviews it), so an older job in progress doesn't stop it.
     */
    public function guardFree(int $providerId, Carbon $start, Carbon $end, ?int $except, bool $forProvider, bool $confirming = true): void
    {
        $reason = $this->conflictReason($providerId, $start, $end, $except, $confirming);
        if ($reason === 'legacy') {
            throw new BusinessRuleException('earlier_job_in_progress', $forProvider
                ? 'You’re still marked as working on an earlier job. Mark it as done first, or suggest a time on a later day.'
                : 'This provider is finishing another job today. Choose a later day.', 409);
        }
        if ($reason === 'booked') {
            throw new BusinessRuleException('time_taken', $forProvider
                ? 'You already have an appointment (or time off) at that time. Suggest another time instead.'
                : 'This provider already has an appointment at that time. Please choose another time.', 409);
        }
    }

    public function endOf(Carbon $start, ?int $minutes): Carbon
    {
        return $start->copy()->addMinutes($minutes ?: $this->defaultDuration());
    }

    /** "Fri 20 Nov, 10:00 AM", kept in preferred_time for older screens */
    public function label(Carbon $start): string
    {
        return $start->copy()->setTimezone(self::TIMEZONE)->format('D j M, g:i A');
    }
}
