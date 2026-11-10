<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Models\RecognitionMilestone;
use App\Models\Referral;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\Referrals;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/** Deciding on referrals that need a person, and setting the badges */
class ReferralController extends Controller
{
    /** @var Referrals */
    private $referrals;

    public function __construct(Referrals $referrals)
    {
        $this->referrals = $referrals;
    }

    public function approve(Request $request, Referral $referral): JsonResponse
    {
        return $this->respond($this->referrals->approve($referral, $request->user(), $this->reason($request)));
    }

    public function reject(Request $request, Referral $referral): JsonResponse
    {
        return $this->respond($this->referrals->reject($referral, $request->user(), $this->reason($request)));
    }

    public function revoke(Request $request, Referral $referral): JsonResponse
    {
        return $this->respond($this->referrals->revoke($referral, $request->user(), $this->reason($request)));
    }

    public function storeMilestone(Request $request, AuditLogger $audit): JsonResponse
    {
        $data = $request->validate($this->milestoneRules() + [
            'threshold' => ['required', 'integer', 'min:1', 'max:10000', 'unique:recognition_milestones,threshold'],
        ]);
        $data['icon'] = $data['icon'] ?? 'bi-award';
        $milestone = DB::transaction(function () use ($data, $request, $audit) {
            $milestone = new RecognitionMilestone();
            $milestone->forceFill($data + ['key' => $this->keyFor($data['name']), 'is_active' => true])->save();
            $audit->record('badge.milestone_created', $milestone, [], $data, $request->input('reason'), $request->user());

            return $milestone;
        });
        $this->resync();

        return response()->json(['data' => $milestone], 201);
    }

    public function updateMilestone(Request $request, RecognitionMilestone $milestone, AuditLogger $audit): JsonResponse
    {
        $data = $request->validate(array_map(function ($rule) {
            return array_merge(['sometimes'], $rule);
        }, $this->milestoneRules()) + [
            'threshold' => ['sometimes', 'integer', 'min:1', 'max:10000', Rule::unique('recognition_milestones', 'threshold')->ignore($milestone->id)],
            'is_active' => ['sometimes', 'boolean'],
            'reason' => ['required', 'string', 'min:5', 'max:500'],
        ]);
        $reason = $data['reason'];
        unset($data['reason']);
        if (array_key_exists('icon', $data) && ! $data['icon']) {
            $data['icon'] = 'bi-award';
        }
        DB::transaction(function () use ($milestone, $data, $reason, $request, $audit) {
            $before = $milestone->only(array_keys($data));
            $milestone->forceFill($data)->save();
            $audit->record('badge.milestone_updated', $milestone, $before, $data, $reason, $request->user());
        });
        // A changed number or a switched-off badge applies to everyone straight away
        if (array_key_exists('threshold', $data) || array_key_exists('is_active', $data)) {
            $this->resync();
        }

        return response()->json(['data' => $milestone->fresh()]);
    }

    /** Everyone with invitations, brought in line with the badges as they now are */
    private function resync(): void
    {
        $ids = Referral::where('status', Referral::STATUS_QUALIFIED)->distinct()->pluck('referrer_id')
            ->merge(DB::table('user_badges')->distinct()->pluck('user_id'))->unique();
        User::whereIn('id', $ids)->get()->each(function (User $user) {
            $this->referrals->syncBadges($user, true, 'The badge milestones changed.');
        });
    }

    private function milestoneRules(): array
    {
        return [
            'name' => ['required', 'string', 'max:60'],
            'description' => ['required', 'string', 'max:255'],
            'icon' => ['nullable', 'string', 'regex:/^bi-[a-z0-9-]+$/', 'max:40'],
        ];
    }

    private function keyFor(string $name): string
    {
        $base = trim(preg_replace('/[^a-z0-9]+/', '_', strtolower($name)), '_') ?: 'badge';
        $key = substr($base, 0, 34);
        for ($i = 2; RecognitionMilestone::where('key', $key)->exists(); $i++) {
            $key = substr($base, 0, 34).'_'.$i;
        }

        return $key;
    }

    /** Shown in the record and the audit log */
    private function reason(Request $request): string
    {
        return $request->validate(['reason' => ['required', 'string', 'min:5', 'max:500']])['reason'];
    }

    private function respond(Referral $referral): JsonResponse
    {
        return response()->json(['data' => ['id' => $referral->id, 'status' => $referral->status]]);
    }
}
