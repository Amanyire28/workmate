<?php

namespace App\Services;

use App\Exceptions\BusinessRuleException;
use App\Models\Area;
use App\Models\Profession;
use App\Models\ProviderApplication;
use App\Models\ProviderMember;
use App\Models\ProviderProfile;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * The provider application lifecycle. All status changes happen here.
 *
 *   (no profile) → draft → pending_review → approved ⇄ suspended
 *                              ↓        ↘
 *                  more_info_required    rejected → draft (applicant reopens)
 *                              ↓
 *                       pending_review (resubmitted)
 */
class ProviderApplications
{
    /** @var AuditLogger */
    private $audit;

    public function __construct(AuditLogger $audit)
    {
        $this->audit = $audit;
    }

    /**
     * Create or update the applicant's own provider details.
     *
     * @param  array{type: string, display_name: string, bio: ?string, business_registration_number: ?string, professions: array<int, array{profession: string, years_experience: ?int}>}  $data
     */
    public function saveDetails(User $user, array $data): ProviderProfile
    {
        return DB::transaction(function () use ($user, $data) {
            $profile = ProviderProfile::where('owner_id', $user->getKey())->lockForUpdate()->first();

            if ($profile && ! $profile->isEditable()) {
                throw new BusinessRuleException('provider_locked', $this->lockedMessage($profile->status), 409);
            }

            $creating = $profile === null;
            $profile = $profile ?: new ProviderProfile();
            $before = $creating ? [] : $this->details($profile);

            $profile->fill([
                'type' => $data['type'],
                'display_name' => $data['display_name'],
                'bio' => $data['bio'] ?? null,
                'business_registration_number' => $data['type'] === 'individual' ? null : ($data['business_registration_number'] ?? null),
            ]);
            // Public listing details are optional and only changed when sent
            $profile->fill(array_intersect_key($data, array_flip([
                'skills', 'availability_status', 'availability_note', 'price_from', 'price_basis',
            ])));
            $profile->owner_id = $user->getKey();

            if (array_key_exists('base_area', $data)) {
                // The base is the area's centre point, never the provider's own position
                $area = $data['base_area'] ? Area::where('slug', $data['base_area'])->first() : null;
                $profile->forceFill([
                    'base_area_id' => $area ? $area->id : null,
                    'latitude' => $area ? $area->latitude : null,
                    'longitude' => $area ? $area->longitude : null,
                ]);
            }
            $profile->save();

            if (array_key_exists('service_areas', $data)) {
                $profile->serviceAreas()->sync(Area::whereIn('slug', $data['service_areas'] ?: [])->pluck('id'));
            }

            if ($creating) {
                $profile->members()->create(['user_id' => $user->getKey(), 'role' => ProviderMember::ROLE_OWNER]);
            }

            $this->syncProfessions($profile, $data['professions']);
            $profile->load('professions');

            $this->audit->record(
                $creating ? 'provider.started' : 'provider.details_updated',
                $profile, $before, $this->details($profile), null, $user
            );

            return $profile;
        });
    }

    /** Send the draft (or the requested changes) for review. */
    public function submit(User $user, ?string $note = null): ProviderApplication
    {
        if (! $user->hasVerifiedEmail()) {
            throw new BusinessRuleException('email_not_verified', 'Confirm your email address before sending your application.', 403);
        }

        return DB::transaction(function () use ($user, $note) {
            $profile = $this->ownProfileForUpdate($user);

            if (! in_array($profile->status, [ProviderProfile::STATUS_DRAFT, ProviderProfile::STATUS_MORE_INFO_REQUIRED], true)) {
                throw new BusinessRuleException('provider_not_submittable', $this->lockedMessage($profile->status), 409);
            }

            $profile->load('professions');

            if ($profile->professions->isEmpty()) {
                throw new BusinessRuleException('provider_incomplete', 'Choose at least one profession before sending your application.');
            }

            $application = $profile->status === ProviderProfile::STATUS_MORE_INFO_REQUIRED
                ? $profile->applications()->where('status', ProviderApplication::STATUS_MORE_INFO_REQUIRED)->latest('id')->firstOrFail()
                : new ProviderApplication();

            $application->forceFill([
                'provider_profile_id' => $profile->getKey(),
                'status' => ProviderApplication::STATUS_PENDING_REVIEW,
                'snapshot' => $this->details($profile),
                'applicant_note' => $note,
                'submitted_at' => now(),
                'decided_at' => null,
                'decided_by' => null,
            ])->save();

            $before = $profile->status;
            $this->setStatus($profile, ProviderProfile::STATUS_PENDING_REVIEW);

            $this->audit->record(
                $before === ProviderProfile::STATUS_DRAFT ? 'provider.submitted' : 'provider.resubmitted',
                $application, ['status' => $before], ['status' => $profile->status], $note, $user
            );

            return $application;
        });
    }

    /** After a rejection the applicant may start again from their previous details. */
    public function reopen(User $user): ProviderProfile
    {
        return DB::transaction(function () use ($user) {
            $profile = $this->ownProfileForUpdate($user);

            if ($profile->status !== ProviderProfile::STATUS_REJECTED) {
                throw new BusinessRuleException('provider_not_reopenable', 'Only a rejected application can be started again.', 409);
            }

            $this->setStatus($profile, ProviderProfile::STATUS_DRAFT);
            $this->audit->record('provider.reopened', $profile, ['status' => 'rejected'], ['status' => 'draft'], null, $user);

            return $profile;
        });
    }

    public function approve(ProviderApplication $application, User $admin, ?string $note = null): ProviderApplication
    {
        return $this->decide($application, $admin, ProviderApplication::STATUS_APPROVED, null, $note);
    }

    public function reject(ProviderApplication $application, User $admin, string $reason, ?string $note = null): ProviderApplication
    {
        return $this->decide($application, $admin, ProviderApplication::STATUS_REJECTED, $reason, $note);
    }

    public function requestMoreInformation(ProviderApplication $application, User $admin, string $reason, ?string $note = null): ProviderApplication
    {
        return $this->decide($application, $admin, ProviderApplication::STATUS_MORE_INFO_REQUIRED, $reason, $note);
    }

    public function suspend(ProviderProfile $profile, User $admin, string $reason): ProviderProfile
    {
        return $this->changeStanding($profile, $admin, ProviderProfile::STATUS_APPROVED, ProviderProfile::STATUS_SUSPENDED, $reason);
    }

    public function reinstate(ProviderProfile $profile, User $admin, string $reason): ProviderProfile
    {
        return $this->changeStanding($profile, $admin, ProviderProfile::STATUS_SUSPENDED, ProviderProfile::STATUS_APPROVED, $reason);
    }

    private function decide(ProviderApplication $application, User $admin, string $outcome, ?string $reason, ?string $note): ProviderApplication
    {
        return DB::transaction(function () use ($application, $admin, $outcome, $reason, $note) {
            $application = ProviderApplication::whereKey($application->getKey())->lockForUpdate()->firstOrFail();
            $profile = ProviderProfile::whereKey($application->provider_profile_id)->lockForUpdate()->firstOrFail();

            $this->guardConflictOfInterest($profile, $admin);

            if ($application->status !== ProviderApplication::STATUS_PENDING_REVIEW) {
                throw new BusinessRuleException('application_already_decided', 'This application is not waiting for review.', 409);
            }

            $application->forceFill([
                'status' => $outcome,
                'decided_at' => now(),
                'decided_by' => $admin->getKey(),
                'decision_reason' => $reason,
                'internal_note' => $note,
            ])->save();

            $this->setStatus($profile, $outcome);

            $this->audit->record(
                'provider.application_'.$outcome,
                $application, ['status' => ProviderApplication::STATUS_PENDING_REVIEW], ['status' => $outcome], $reason ?: $note, $admin
            );

            return $application;
        });
    }

    private function changeStanding(ProviderProfile $profile, User $admin, string $from, string $to, string $reason): ProviderProfile
    {
        return DB::transaction(function () use ($profile, $admin, $from, $to, $reason) {
            $profile = ProviderProfile::whereKey($profile->getKey())->lockForUpdate()->firstOrFail();

            $this->guardConflictOfInterest($profile, $admin);

            if ($profile->status !== $from) {
                throw new BusinessRuleException(
                    'provider_status_conflict',
                    $to === ProviderProfile::STATUS_SUSPENDED ? 'Only an approved provider can be suspended.' : 'Only a suspended provider can be reinstated.',
                    409
                );
            }

            $profile->forceFill([
                'suspended_at' => $to === ProviderProfile::STATUS_SUSPENDED ? now() : null,
                'suspension_reason' => $to === ProviderProfile::STATUS_SUSPENDED ? $reason : null,
            ]);
            $this->setStatus($profile, $to);

            $this->audit->record(
                $to === ProviderProfile::STATUS_SUSPENDED ? 'provider.suspended' : 'provider.reinstated',
                $profile, ['status' => $from], ['status' => $to], $reason, $admin
            );

            return $profile;
        });
    }

    private function setStatus(ProviderProfile $profile, string $status): void
    {
        $profile->status = $status;

        if ($status === ProviderProfile::STATUS_APPROVED && ! $profile->approved_at) {
            $profile->approved_at = now();
        }

        $profile->save();
    }

    /** @param array<int, array{profession: string, years_experience: ?int}> $entries */
    private function syncProfessions(ProviderProfile $profile, array $entries): void
    {
        $ids = Profession::active()->whereIn('slug', array_column($entries, 'profession'))->pluck('id', 'slug');
        $sync = [];

        foreach (array_values($entries) as $index => $entry) {
            $sync[$ids[$entry['profession']]] = [
                'years_experience' => $entry['years_experience'] ?? null,
                // The first profession listed is the main one
                'is_primary' => $index === 0,
            ];
        }

        $profile->professions()->sync($sync);
    }

    private function ownProfileForUpdate(User $user): ProviderProfile
    {
        $profile = ProviderProfile::where('owner_id', $user->getKey())->lockForUpdate()->first();

        if (! $profile) {
            throw new BusinessRuleException('provider_not_started', 'Add your service details first.', 404);
        }

        return $profile;
    }

    private function guardConflictOfInterest(ProviderProfile $profile, User $admin): void
    {
        if ($profile->hasMember($admin)) {
            throw new BusinessRuleException('conflict_of_interest', 'You cannot review or change a provider you belong to.', 403);
        }
    }

    private function details(ProviderProfile $profile): array
    {
        $professions = $profile->relationLoaded('professions') ? $profile->professions : $profile->professions()->get();

        return [
            'type' => $profile->type,
            'display_name' => $profile->display_name,
            'bio' => $profile->bio,
            'business_registration_number' => $profile->business_registration_number,
            'professions' => $professions->sortBy(function ($profession) {
                return [$profession->pivot->is_primary ? 0 : 1, $profession->name];
            })->map(function ($profession) {
                return [
                    'profession' => $profession->slug,
                    'name' => $profession->name,
                    'years_experience' => $profession->pivot->years_experience === null ? null : (int) $profession->pivot->years_experience,
                    'is_primary' => (bool) $profession->pivot->is_primary,
                ];
            })->values()->all(),
        ];
    }

    private function lockedMessage(string $status): string
    {
        $messages = [
            ProviderProfile::STATUS_PENDING_REVIEW => 'Your application is being reviewed. You can make changes once a decision is made.',
            ProviderProfile::STATUS_REJECTED => 'Your application was not approved. Start a new application to make changes.',
            ProviderProfile::STATUS_SUSPENDED => 'Your provider account is suspended. Contact WorkMate support.',
            ProviderProfile::STATUS_APPROVED => 'You are already an approved provider.',
        ];

        return $messages[$status] ?? 'This action is not available right now.';
    }
}
