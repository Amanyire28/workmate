<?php

namespace App\Services;

use App\Exceptions\BusinessRuleException;
use App\Models\Category;
use App\Models\Profession;
use App\Models\ProfessionSuggestion;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Administrators' decisions on professions applicants typed under "Other".
 * Once added or matched, the profession joins the applicant's list in the
 * place they gave it, so a typed main profession becomes their main one.
 */
class ProfessionSuggestions
{
    /** @var AuditLogger */
    private $audit;

    public function __construct(AuditLogger $audit)
    {
        $this->audit = $audit;
    }

    /**
     * Adds the profession to the list. Everyone else waiting with the same
     * name is matched to it too.
     *
     * @param  array{category: string, name: string, plural_name: string}  $data
     * @return int how many applicants it was given to
     */
    public function add(ProfessionSuggestion $suggestion, User $admin, array $data): int
    {
        return DB::transaction(function () use ($suggestion, $admin, $data) {
            $suggestion = $this->pendingForUpdate($suggestion);
            $name = trim(preg_replace('/\s+/', ' ', $data['name']));

            if (Profession::whereRaw('LOWER(name) = ?', [mb_strtolower($name)])->exists()) {
                throw new BusinessRuleException('profession_exists', 'A profession with this name is already in the list. Match the suggestion to it instead.', 409);
            }

            $category = Category::where('slug', $data['category'])->firstOrFail();
            $profession = Profession::create([
                'category_id' => $category->id,
                'slug' => $this->uniqueSlug($name),
                'name' => $name,
                'plural_name' => trim($data['plural_name']),
                'sort_order' => ((int) Profession::where('category_id', $category->id)->max('sort_order')) + 10,
                'is_active' => true,
            ]);
            $this->audit->record('profession.created', $profession, [], $profession->only(['slug', 'name', 'plural_name', 'category_id']), 'Suggested by an applicant', $admin);

            $same = ProfessionSuggestion::pending()
                ->whereRaw('LOWER(name) = ?', [mb_strtolower($suggestion->name)])
                ->lockForUpdate()
                ->get();

            $given = 0;
            foreach ($same as $each) {
                // Someone else decides for a provider this administrator belongs to
                if (! $each->is($suggestion) && $each->profile->hasMember($admin)) {
                    continue;
                }
                $this->give($each, $profession, $admin, $each->is($suggestion) ? ProfessionSuggestion::STATUS_ADDED : ProfessionSuggestion::STATUS_MATCHED, null);
                $given++;
            }

            return $given;
        });
    }

    /** The applicant meant a profession that's already in the list */
    public function match(ProfessionSuggestion $suggestion, User $admin, string $professionSlug, ?string $reason): void
    {
        DB::transaction(function () use ($suggestion, $admin, $professionSlug, $reason) {
            $suggestion = $this->pendingForUpdate($suggestion);
            $profession = Profession::active()->where('slug', $professionSlug)->firstOrFail();

            $this->give($suggestion, $profession, $admin, ProfessionSuggestion::STATUS_MATCHED, $reason);
        });
    }

    /** Not a service WorkMate lists. The applicant sees the reason. */
    public function decline(ProfessionSuggestion $suggestion, User $admin, string $reason): void
    {
        DB::transaction(function () use ($suggestion, $admin, $reason) {
            $suggestion = $this->pendingForUpdate($suggestion);
            $this->guardConflictOfInterest($suggestion, $admin);

            $suggestion->forceFill([
                'status' => ProfessionSuggestion::STATUS_DECLINED,
                'decision_reason' => $reason,
                'decided_by' => $admin->getKey(),
                'decided_at' => now(),
            ])->save();

            $this->audit->record('profession.suggestion_declined', $suggestion, ['status' => 'pending'], ['status' => 'declined', 'name' => $suggestion->name], $reason, $admin);
        });
    }

    private function give(ProfessionSuggestion $suggestion, Profession $profession, User $admin, string $status, ?string $reason): void
    {
        $this->guardConflictOfInterest($suggestion, $admin);
        $profile = $suggestion->profile;
        $main = $suggestion->position === 0;

        if ($main) {
            DB::table('provider_professions')->where('provider_profile_id', $profile->id)->update(['is_primary' => false]);
        }
        $existing = $profile->professions()->where('professions.id', $profession->id)->first();
        if ($existing) {
            $profile->professions()->updateExistingPivot($profession->id, ['is_primary' => $main || $existing->pivot->is_primary]);
        } else {
            $profile->professions()->attach($profession->id, ['years_experience' => $suggestion->years_experience, 'is_primary' => $main]);
        }

        $suggestion->forceFill([
            'status' => $status,
            'profession_id' => $profession->id,
            'decision_reason' => $reason,
            'decided_by' => $admin->getKey(),
            'decided_at' => now(),
        ])->save();

        $this->audit->record('profession.suggestion_'.$status, $suggestion, ['status' => 'pending'], [
            'status' => $status, 'name' => $suggestion->name, 'profession' => $profession->slug,
        ], $reason, $admin);
    }

    private function pendingForUpdate(ProfessionSuggestion $suggestion): ProfessionSuggestion
    {
        $suggestion = ProfessionSuggestion::whereKey($suggestion->getKey())->lockForUpdate()->firstOrFail();

        if ($suggestion->status !== ProfessionSuggestion::STATUS_PENDING) {
            throw new BusinessRuleException('suggestion_already_decided', 'This suggestion has already been dealt with.', 409);
        }

        return $suggestion;
    }

    private function guardConflictOfInterest(ProfessionSuggestion $suggestion, User $admin): void
    {
        if ($suggestion->profile->hasMember($admin)) {
            throw new BusinessRuleException('conflict_of_interest', 'You cannot decide on a provider you belong to.', 403);
        }
    }

    private function uniqueSlug(string $name): string
    {
        $base = Str::limit(Str::slug($name, '_'), 44, '') ?: 'profession';
        $slug = $base;
        for ($i = 2; Profession::where('slug', $slug)->exists(); $i++) {
            $slug = $base.'_'.$i;
        }

        return $slug;
    }
}
