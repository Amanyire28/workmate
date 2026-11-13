<?php

namespace App\Services;

use App\Models\ProviderProfile;
use App\Models\User;

/**
 * How complete someone's profile is, from what's actually saved, with what's
 * missing and the most useful next step. Three sets of rules:
 *
 * - Customers: name, confirmed email and phone number are required; a
 *   profile photo is recommended.
 * - Provider applicants: what the application needs before it can be sent
 *   (confirmed email, a profession, a base area, a name customers will see);
 *   a description and photo are recommended. Applicants are never described
 *   as available for hire.
 * - Approved providers: photo, description, at least one listed service, a
 *   price (a starting price or a priced service) and a base area are
 *   required to stand out; places served and shown work are recommended.
 *
 * Document verification and the tick are never part of it.
 */
class ProfileCompleteness
{
    public const ROLE_CUSTOMER = 'customer';
    public const ROLE_APPLICANT = 'applicant';
    public const ROLE_PROVIDER = 'provider';

    private const APPLICANT_STATUSES = [ProviderProfile::STATUS_DRAFT, ProviderProfile::STATUS_MORE_INFO_REQUIRED];

    /**
     * @return array{role: string, percent: int, complete: bool, applies: bool,
     *   items: array<int, array{key: string, label: string, level: string, done: bool, url: string}>,
     *   missing: array, next: ?array}
     */
    public function forUser(User $user): array
    {
        $profile = $user->providerProfile;

        if ($profile && $profile->status === ProviderProfile::STATUS_APPROVED) {
            $role = self::ROLE_PROVIDER;
            $items = $this->provider($user, $profile);
        } elseif ($profile && in_array($profile->status, self::APPLICANT_STATUSES, true)) {
            $role = self::ROLE_APPLICANT;
            $items = $this->applicant($user, $profile);
        } elseif ($profile) {
            // Waiting for review, rejected or suspended: nothing to complete here
            return ['role' => self::ROLE_APPLICANT, 'status' => $profile->status, 'percent' => 100, 'complete' => true, 'applies' => false, 'items' => [], 'missing' => [], 'next' => null];
        } else {
            $role = self::ROLE_CUSTOMER;
            $items = $this->customer($user);
        }

        $done = count(array_filter($items, function ($i) {
            return $i['done'];
        }));
        $missing = array_values(array_filter($items, function ($i) {
            return ! $i['done'];
        }));
        $requiredMissing = array_values(array_filter($missing, function ($i) {
            return $i['level'] === 'required';
        }));
        // Required first, then recommended
        $next = $requiredMissing[0] ?? ($missing[0] ?? null);

        return [
            'role' => $role,
            'status' => $profile ? $profile->status : null,
            'percent' => $items ? (int) round($done / count($items) * 100) : 100,
            // Complete = nothing required is missing (recommended extras are a bonus)
            'complete' => empty($requiredMissing),
            'applies' => true,
            'items' => $items,
            'missing' => $missing,
            'next' => $next,
        ];
    }

    private function customer(User $user): array
    {
        return [
            $this->item('name', 'Your name', 'required', trim((string) $user->name) !== '', '/account?panel=profile'),
            $this->item('email', 'Confirm your email address', 'required', $user->email_verified_at !== null, '/account'),
            $this->item('phone', 'Add your phone number', 'required', ! empty($user->phone), '/account?panel=profile'),
            $this->item('photo', 'Add a profile photo', 'recommended', ! empty($user->avatar_path), '/account#profile-photo'),
        ];
    }

    private function applicant(User $user, ProviderProfile $p): array
    {
        return [
            $this->item('email', 'Confirm your email address', 'required', $user->email_verified_at !== null, '/account'),
            $this->item('profession', 'Choose what you do (your profession)', 'required', $p->professions()->exists(), '/start-earning'),
            $this->item('base_area', 'Choose where you’re based', 'required', $p->base_area_id !== null, '/start-earning'),
            $this->item('display_name', 'The name customers will see', 'required', trim((string) $p->display_name) !== '', '/start-earning'),
            $this->item('bio', 'Describe your experience', 'recommended', mb_strlen(trim((string) $p->bio)) >= 30, '/start-earning'),
            $this->item('photo', 'Add a profile photo', 'recommended', ! empty($user->avatar_path), '/account#profile-photo'),
        ];
    }

    private function provider(User $user, ProviderProfile $p): array
    {
        $hasServices = $p->services()->active()->exists();
        $hasPrice = $p->price_from !== null || $p->services()->active()->whereNotNull('price')->exists()
            || $p->services()->active()->where('pricing_type', 'quote')->exists();

        return [
            $this->item('photo', 'Add a profile photo', 'required', ! empty($user->avatar_path), '/account#profile-photo'),
            $this->item('bio', 'Describe your experience and skills', 'required', mb_strlen(trim((string) $p->bio)) >= 30, '/account?panel=provider'),
            $this->item('services', 'List the services you offer', 'required', $hasServices, '/provider/'.$p->id.'#services'),
            $this->item('price', 'Add your usual prices or quote options', 'required', $hasPrice, '/provider/'.$p->id.'#services'),
            $this->item('base_area', 'Choose where you’re based', 'required', $p->base_area_id !== null, '/account?panel=provider'),
            $this->item('service_areas', 'Add the places you serve', 'recommended', $p->serviceAreas()->exists(), '/account?panel=provider'),
            $this->item('work', 'Show photos of your work', 'recommended', $p->workItems()->where('status', 'published')->exists(), '/provider/'.$p->id.'#work'),
        ];
    }

    private function item(string $key, string $label, string $level, bool $done, string $url): array
    {
        return compact('key', 'label', 'level', 'done', 'url');
    }
}
