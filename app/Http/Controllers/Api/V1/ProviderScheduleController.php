<?php

namespace App\Http\Controllers\Api\V1;

use App\Exceptions\BusinessRuleException;
use App\Http\Controllers\Controller;
use App\Models\ProviderProfile;
use App\Models\ProviderUnavailablePeriod;
use App\Models\ServiceRequest;
use App\Services\AuditLogger;
use App\Services\Scheduling;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * The provider's own booking settings: whether they take immediate work and
 * bookings for later (both off is "Bookings paused"), and times they're
 * away. Only the provider's owner changes them; customers see the result.
 */
class ProviderScheduleController extends Controller
{
    /** @var Scheduling */
    private $scheduling;

    /** @var AuditLogger */
    private $audit;

    public function __construct(Scheduling $scheduling, AuditLogger $audit)
    {
        $this->scheduling = $scheduling;
        $this->audit = $audit;
    }

    /** Their settings, upcoming time off and what customers see now */
    public function show(Request $request): JsonResponse
    {
        $profile = $this->own($request);

        return response()->json(['data' => $this->present($profile)]);
    }

    public function updateAvailability(Request $request): JsonResponse
    {
        $profile = $this->own($request);
        $data = $request->validate([
            'accepts_immediate' => ['sometimes', 'boolean'],
            'accepts_bookings' => ['sometimes', 'boolean'],
        ]);

        DB::transaction(function () use ($profile, $data, $request) {
            $before = $profile->only(['accepts_immediate', 'accepts_bookings', 'availability_status']);
            $profile->forceFill($data);
            // Kept in step for older screens and the existing ranking
            $profile->forceFill([
                'availability_status' => ! $profile->accepts_immediate && ! $profile->accepts_bookings ? 'unavailable'
                    : (! $profile->accepts_immediate ? 'busy' : 'available'),
                'availability_updated_at' => now(),
            ])->save();
            $this->audit->record('provider.availability_changed', $profile, $before, $profile->only(['accepts_immediate', 'accepts_bookings', 'availability_status']), null, $request->user());
        });

        return response()->json(['data' => $this->present($profile->fresh())]);
    }

    /** Time away, from and to in Uganda time ("2026-11-20 08:00") */
    public function addTimeOff(Request $request): JsonResponse
    {
        $profile = $this->own($request);
        $data = $request->validate([
            'starts_at' => ['required', 'date_format:Y-m-d H:i'],
            'ends_at' => ['required', 'date_format:Y-m-d H:i'],
            'note' => ['nullable', 'string', 'max:120'],
        ], ['starts_at.required' => 'Choose when it starts.', 'ends_at.required' => 'Choose when it ends.']);
        $start = $this->scheduling->parseLocal($data['starts_at']);
        $end = $this->scheduling->parseLocal($data['ends_at']);
        if ($end->lte($start)) {
            throw new BusinessRuleException('time_off_order', 'The end must be after the start.', 422);
        }
        if ($end->lte(now())) {
            throw new BusinessRuleException('time_off_past', 'Choose a time that hasn’t passed yet.', 422);
        }
        if ($end->gt(now()->addDays(366))) {
            throw new BusinessRuleException('time_off_far', 'Choose dates within the next year.', 422);
        }

        DB::transaction(function () use ($profile, $start, $end, $data, $request) {
            $this->scheduling->lockProvider($profile->id);
            if (ProviderUnavailablePeriod::where('provider_profile_id', $profile->id)->where('ends_at', '>', now())->count() >= ProviderUnavailablePeriod::MAX) {
                throw new BusinessRuleException('time_off_limit', 'You have too many upcoming times off. Remove one first.', 422);
            }
            // Confirmed bookings in that time stay: tell them to sort those out first
            $clash = ServiceRequest::where('provider_profile_id', $profile->id)->whereIn('status', ServiceRequest::BLOCKING_STATUSES)
                ->where('scheduled_start_at', '<', $end)->where('scheduled_end_at', '>', $start)->exists();
            if ($clash) {
                throw new BusinessRuleException('time_off_booked', 'You have a confirmed booking in that time. Suggest the customer another time, or cancel it, first.', 409);
            }
            $period = new ProviderUnavailablePeriod();
            $period->forceFill(['provider_profile_id' => $profile->id, 'starts_at' => $start, 'ends_at' => $end, 'note' => isset($data['note']) ? trim($data['note']) : null])->save();
            $this->audit->record('provider.time_off_added', $period, [], ['starts_at' => $start->toIso8601String(), 'ends_at' => $end->toIso8601String()], null, $request->user());
        });

        return response()->json(['data' => $this->present($profile->fresh())], 201);
    }

    public function removeTimeOff(Request $request, int $period): JsonResponse
    {
        $profile = $this->own($request);
        $row = ProviderUnavailablePeriod::where('provider_profile_id', $profile->id)->whereKey($period)->first();
        if (! $row) {
            throw new BusinessRuleException('not_found', 'The requested item was not found.', 404);
        }
        DB::transaction(function () use ($row, $request) {
            $this->audit->record('provider.time_off_removed', $row, ['starts_at' => $row->starts_at->toIso8601String()], [], null, $request->user());
            $row->delete();
        });

        return response()->json(['data' => $this->present($profile->fresh())]);
    }

    private function own(Request $request): ProviderProfile
    {
        $profile = $request->user()->providerProfile;
        if (! $profile) {
            throw new BusinessRuleException('no_provider_profile', 'Set up your provider profile first.', 409);
        }
        if (! in_array($profile->status, ProviderProfile::EDITABLE_STATUSES, true)) {
            throw new BusinessRuleException('provider_not_editable', 'Your booking settings can’t be changed while your account is restricted or under review.', 409);
        }

        return $profile;
    }

    private function present(ProviderProfile $profile): array
    {
        $local = function ($at) {
            return $at->copy()->setTimezone(Scheduling::TIMEZONE)->toIso8601String();
        };

        return [
            'accepts_immediate' => (bool) $profile->accepts_immediate,
            'accepts_bookings' => (bool) $profile->accepts_bookings,
            'work_status' => $profile->workStatus(),
            'default_duration_minutes' => $this->scheduling->defaultDuration(),
            'time_off' => ProviderUnavailablePeriod::where('provider_profile_id', $profile->id)->where('ends_at', '>', now())
                ->orderBy('starts_at')->get()->map(function ($p) use ($local) {
                    return ['id' => $p->id, 'starts_at' => $local($p->starts_at), 'ends_at' => $local($p->ends_at), 'note' => $p->note];
                })->values(),
        ];
    }
}
