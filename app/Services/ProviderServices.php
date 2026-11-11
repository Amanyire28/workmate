<?php

namespace App\Services;

use App\Exceptions\BusinessRuleException;
use App\Models\Category;
use App\Models\ProviderProfile;
use App\Models\ProviderService;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * A provider's "Services & Prices": what they do and what they usually charge.
 * Only the provider's owner changes the list, while their details can be
 * edited (draft, more information needed, approved). Customers see the active
 * services of providers they can find. Prices are a guide: nothing here is
 * charged or owed.
 */
class ProviderServices
{
    /** @var AuditLogger */
    private $audit;

    public function __construct(AuditLogger $audit)
    {
        $this->audit = $audit;
    }

    /** @param array{title: string, description: ?string, category: ?string, pricing_type: string, price: ?int, price_unit: ?string} $data */
    public function add(User $owner, array $data): ProviderService
    {
        $profile = $this->ownEditableProfile($owner);

        return DB::transaction(function () use ($owner, $profile, $data) {
            // One change at a time per provider, so the limit and the order stay right
            ProviderProfile::whereKey($profile->id)->lockForUpdate()->first();
            if ($profile->services()->count() >= ProviderService::MAX) {
                throw new BusinessRuleException('services_limit', 'You can list up to '.ProviderService::MAX.' services. Remove one to add another.', 422);
            }

            $service = new ProviderService();
            $service->forceFill($this->details($data, $profile) + [
                'provider_profile_id' => $profile->id,
                'is_active' => true,
                'position' => (int) $profile->services()->max('position') + 1,
            ])->save();
            $this->audit->record('provider.service_added', $service, [], $this->audited($service), null, $owner);

            return $service;
        });
    }

    /** @param array<string, mixed> $data any of the fields, and is_active */
    public function edit(User $owner, ProviderService $service, array $data): ProviderService
    {
        $profile = $this->ownEditableProfile($owner);
        $this->guardOwn($profile, $service);

        return DB::transaction(function () use ($owner, $profile, $service, $data) {
            $before = $this->audited($service);
            $changes = $this->details($data, $profile, $service);
            if (array_key_exists('is_active', $data)) {
                $changes['is_active'] = (bool) $data['is_active'];
            }
            $service->forceFill($changes)->save();
            $this->audit->record('provider.service_edited', $service, $before, $this->audited($service), null, $owner);

            return $service;
        });
    }

    public function remove(User $owner, ProviderService $service): void
    {
        $profile = $this->ownEditableProfile($owner);
        $this->guardOwn($profile, $service);

        DB::transaction(function () use ($owner, $service) {
            // Requests that named it keep their own copy of the details
            $this->audit->record('provider.service_removed', $service, $this->audited($service), [], null, $owner);
            $service->delete();
        });
    }

    /** @param int[] $ids the provider's services, in the new order */
    public function reorder(User $owner, array $ids): Collection
    {
        $profile = $this->ownEditableProfile($owner);
        $ids = array_values(array_unique(array_map('intval', $ids)));

        return DB::transaction(function () use ($owner, $profile, $ids) {
            ProviderProfile::whereKey($profile->id)->lockForUpdate()->first();
            $mine = $profile->services()->pluck('id')->all();
            // Every one of theirs, and only theirs
            if (count($ids) !== count($mine) || array_diff($ids, $mine)) {
                throw new BusinessRuleException('services_order', 'The list changed meanwhile. Reload and try again.', 409);
            }
            foreach ($ids as $i => $id) {
                ProviderService::whereKey($id)->update(['position' => $i + 1]);
            }
            $this->audit->record('provider.services_reordered', $profile, [], ['order' => $ids], null, $owner);

            return $this->listFor($profile, true);
        });
    }

    /* ---------------- Showing them ---------------- */

    /** The owner sees every service; customers only active ones */
    public function listFor(ProviderProfile $profile, bool $forOwner = false): Collection
    {
        $query = $profile->services()->with('category')->orderBy('position')->orderBy('id');
        if (! $forOwner) {
            $query->active();
        }

        return $query->get()->map(function (ProviderService $s) use ($forOwner) {
            return $this->present($s, $forOwner);
        })->values();
    }

    public function present(ProviderService $s, bool $forOwner = false): array
    {
        $data = [
            'id' => $s->id,
            'title' => $s->title,
            'description' => $s->description,
            'category' => $s->category ? $s->category->slug : null,
            'category_name' => $s->category ? $s->category->name : null,
            'pricing_type' => $s->pricing_type,
            'price' => $s->pricing_type === ProviderService::PRICING_QUOTE ? null : $s->price,
            'price_unit' => $s->price_unit,
        ];
        if ($forOwner) {
            $data['is_active'] = $s->is_active;
            $data['position'] = $s->position;
        }

        return $data;
    }

    /* ---------------- Rules ---------------- */

    private function ownEditableProfile(User $owner): ProviderProfile
    {
        $profile = $owner->providerProfile;
        if (! $profile) {
            throw new BusinessRuleException('no_provider_profile', 'Set up your provider profile first.', 409);
        }
        if (! in_array($profile->status, ProviderProfile::EDITABLE_STATUSES, true)) {
            throw new BusinessRuleException('provider_not_editable', 'Your services can’t be changed while your application is being reviewed or your account is restricted.', 409);
        }

        return $profile;
    }

    private function guardOwn(ProviderProfile $profile, ProviderService $service): void
    {
        // Another provider's service is reported as not found
        if ($service->provider_profile_id !== $profile->id) {
            throw new BusinessRuleException('not_found', 'The requested item was not found.', 404);
        }
    }

    /** Cleaned fields; on edit only those given. The category must be one the provider offers. */
    private function details(array $data, ProviderProfile $profile, ?ProviderService $service = null): array
    {
        $out = [];
        if (! $service || array_key_exists('title', $data)) {
            $out['title'] = $this->clean($data['title'] ?? '', 80);
            if ($out['title'] === '') {
                throw new BusinessRuleException('service_title', 'Give the service a name.', 422);
            }
        }
        if (! $service || array_key_exists('description', $data)) {
            $description = $this->clean($data['description'] ?? '', 300);
            $out['description'] = $description === '' ? null : $description;
        }
        if (! $service || array_key_exists('category', $data)) {
            $offered = $this->offeredCategories($profile);
            if (! empty($data['category'])) {
                $id = Category::where('slug', $data['category'])->value('id');
                if (! $id || ! in_array($id, $offered, true)) {
                    throw new BusinessRuleException('service_category', 'Choose one of the services you offer.', 422);
                }
                $out['category_id'] = $id;
            } else {
                $out['category_id'] = $profile->mainCategoryId() ?: ($offered[0] ?? null);
            }
        }

        // Price, type and unit are checked together
        $type = array_key_exists('pricing_type', $data) ? $data['pricing_type'] : ($service ? $service->pricing_type : null);
        $price = array_key_exists('price', $data) ? $data['price'] : ($service ? $service->price : null);
        $unit = array_key_exists('price_unit', $data) ? $data['price_unit'] : ($service ? $service->price_unit : null);
        if (! $service || array_intersect(['pricing_type', 'price', 'price_unit'], array_keys($data))) {
            if (! in_array($type, ProviderService::PRICING_TYPES, true)) {
                throw new BusinessRuleException('service_pricing', 'Choose how you price this service.', 422);
            }
            if ($type === ProviderService::PRICING_QUOTE) {
                $price = null;
                $unit = null;
            } elseif ($price === null || $price === '') {
                throw new BusinessRuleException('service_price', 'Enter your usual price in UGX, or choose “Request a quote”.', 422);
            }
            $out['pricing_type'] = $type;
            $out['price'] = $price === null ? null : (int) $price;
            $out['price_unit'] = $unit ?: null;
        }

        return $out;
    }

    /** @return int[] */
    private function offeredCategories(ProviderProfile $profile): array
    {
        return $profile->professions()->with('category')->get()
            ->pluck('category.id')->filter()->map(function ($id) {
                return (int) $id;
            })->unique()->values()->all();
    }

    private function clean(string $text, int $max): string
    {
        return mb_substr(trim(preg_replace('/\s+/u', ' ', $text)), 0, $max);
    }

    private function audited(ProviderService $s): array
    {
        return $s->only(['title', 'category_id', 'pricing_type', 'price', 'price_unit', 'is_active']);
    }
}
