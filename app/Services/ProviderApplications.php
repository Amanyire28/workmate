<?php

namespace App\Services;

use App\Exceptions\BusinessRuleException;
use App\Models\Area;
use App\Models\Category;
use App\Models\Profession;
use App\Models\ProfessionSuggestion;
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
     * @param  array{type: string, display_name: string, bio: ?string, business_registration_number: ?string, professions: array<int, array{profession: ?string, other: ?string, category: ?string, years_experience: ?int}>}  $data
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
            $profile->load('professions', 'professionSuggestions');

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

            $profile->load('professions', 'professionSuggestions');

            if ($profile->professions->isEmpty() && $profile->professionSuggestions->where('status', ProfessionSuggestion::STATUS_PENDING)->isEmpty()) {
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

            if ($outcome === ProviderApplication::STATUS_APPROVED) {
                $this->guardProfessionsSettled($profile);
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

    /**
     * Professions from the list go on the profile; typed ones wait as
     * suggestions. The first entry is the main one, whichever kind it is.
     *
     * @param array<int, array{profession: ?string, other: ?string, category: ?string, years_experience: ?int}> $entries
     */
    private function syncProfessions(ProviderProfile $profile, array $entries): void
    {
        $entries = array_values($entries);
        $ids = Profession::active()->whereIn('slug', array_filter(array_column($entries, 'profession')))->pluck('id', 'slug');
        $categories = Category::active()->whereIn('slug', array_filter(array_column($entries, 'category')))->pluck('id', 'slug');
        $sync = [];
        $typed = [];

        foreach ($entries as $index => $entry) {
            $years = $entry['years_experience'] ?? null;

            if (! empty($entry['profession'])) {
                $sync[$ids[$entry['profession']]] = ['years_experience' => $years, 'is_primary' => $index === 0];
            } else {
                $name = trim(preg_replace('/\s+/', ' ', $entry['other']));
                $typed[mb_strtolower($name)] = [
                    'name' => $name,
                    'category_id' => isset($entry['category']) ? ($categories[$entry['category']] ?? null) : null,
                    'position' => $index,
                    'years_experience' => $years,
                ];
            }
        }

        $profile->professions()->sync($sync);

        // Waiting suggestions follow the list; decided ones stay as a record
        $pending = $profile->professionSuggestions()->pending()->get()->keyBy(function ($suggestion) {
            return mb_strtolower($suggestion->name);
        });
        foreach ($typed as $key => $values) {
            $suggestion = $pending->pull($key) ?: new ProfessionSuggestion();
            $suggestion->forceFill($values + ['provider_profile_id' => $profile->getKey(), 'status' => ProfessionSuggestion::STATUS_PENDING])->save();
        }
        foreach ($pending as $removed) {
            $removed->delete();
        }
    }

    private function guardProfessionsSettled(ProviderProfile $profile): void
    {
        if ($profile->professionSuggestions()->pending()->exists()) {
            throw new BusinessRuleException('professions_unsettled', 'This applicant typed a profession that isn’t in the list. Add it, match it to an existing one or decline it first.', 409);
        }
        if (! $profile->professions()->exists()) {
            throw new BusinessRuleException('provider_incomplete', 'This applicant has no profession from the list, so customers couldn’t find them.', 409);
        }
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
        $suggestions = ($profile->relationLoaded('professionSuggestions') ? $profile->professionSuggestions : $profile->professionSuggestions()->get())
            ->where('status', ProfessionSuggestion::STATUS_PENDING);

        $listed = $professions->map(function ($profession) {
            return [
                'profession' => $profession->slug,
                'name' => $profession->name,
                'years_experience' => $profession->pivot->years_experience === null ? null : (int) $profession->pivot->years_experience,
                'is_primary' => (bool) $profession->pivot->is_primary,
            ];
        });
        $typed = $suggestions->map(function ($suggestion) {
            return [
                'profession' => null,
                'other' => $suggestion->name,
                'name' => $suggestion->name,
                'years_experience' => $suggestion->years_experience,
                'is_primary' => $suggestion->position === 0,
            ];
        });

        return [
            'type' => $profile->type,
            'display_name' => $profile->display_name,
            'bio' => $profile->bio,
            'business_registration_number' => $profile->business_registration_number,
            'professions' => $listed->concat($typed)->sortBy(function ($entry) {
                return [$entry['is_primary'] ? 0 : 1, $entry['name']];
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
