<?php

namespace App\Services;

use App\Exceptions\BusinessRuleException;
use App\Models\Category;
use App\Models\Profession;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * The service categories and professions customers choose from, managed by
 * administrators (catalogue.manage). Nothing is deleted: providers, jobs and
 * history refer to them, so hiding is the off-switch. Slugs are fixed when
 * created, so renaming never breaks a link. A new category starts hidden and
 * can't be shown until it has a visible profession. Every change is audited.
 */
class Catalogue
{
    /** @var AuditLogger */
    private $audit;

    public function __construct(AuditLogger $audit)
    {
        $this->audit = $audit;
    }

    /* ---------------- Categories ---------------- */

    public function createCategory(User $admin, array $data): Category
    {
        $name = $this->clean($data['name']);
        $this->guardUnique(Category::query(), $name, 'A category with this name already exists.');

        return DB::transaction(function () use ($admin, $data, $name) {
            $category = new Category();
            $category->forceFill([
                'slug' => self::uniqueSlug(Category::query(), $name, '-'),
                'name' => $name,
                'description' => $this->clean($data['description'] ?? '') ?: null,
                'icon' => $data['icon'] ?? 'bi-briefcase',
                'sort_order' => ((int) Category::max('sort_order')) + 10,
                // Hidden until it has professions and an administrator shows it
                'is_active' => false,
            ])->save();
            $this->audit->record('catalogue.category_created', $category, [], $category->only(['slug', 'name', 'description', 'icon']), $data['reason'] ?? null, $admin);

            return $category;
        });
    }

    public function updateCategory(User $admin, Category $category, array $data): Category
    {
        $changes = [];
        if (isset($data['name'])) {
            $changes['name'] = $this->clean($data['name']);
            $this->guardUnique(Category::query()->whereKeyNot($category->id), $changes['name'], 'A category with this name already exists.');
        }
        if (array_key_exists('description', $data)) {
            $changes['description'] = $this->clean($data['description'] ?? '') ?: null;
        }
        if (isset($data['icon'])) {
            $changes['icon'] = $data['icon'];
        }

        return $this->change($admin, $category, $changes, 'catalogue.category_updated', $data['reason'] ?? null);
    }

    public function setCategoryVisible(User $admin, Category $category, bool $visible, ?string $reason): Category
    {
        if ($visible && ! $category->professions()->where('is_active', true)->exists()) {
            throw new BusinessRuleException('category_empty', 'Add a visible profession to this category before showing it to customers.', 422);
        }

        return $this->change($admin, $category, ['is_active' => $visible], $visible ? 'catalogue.category_shown' : 'catalogue.category_hidden', $reason);
    }

    /** Swaps a category with its neighbour in the display order */
    public function moveCategory(User $admin, Category $category, string $direction): Category
    {
        return DB::transaction(function () use ($admin, $category, $direction) {
            $ordered = Category::orderBy('sort_order')->orderBy('name')->lockForUpdate()->get()->values();
            $index = $ordered->search(function ($c) use ($category) {
                return $c->id === $category->id;
            });
            $other = $ordered->get($direction === 'up' ? $index - 1 : $index + 1);
            if (! $other) {
                return $category;
            }
            // Spread the order out first, so equal numbers can't get stuck
            foreach ($ordered as $i => $c) {
                $c->forceFill(['sort_order' => ($i + 1) * 10])->save();
            }
            [$a, $b] = [$category->fresh()->sort_order, $other->fresh()->sort_order];
            Category::whereKey($category->id)->update(['sort_order' => $b]);
            Category::whereKey($other->id)->update(['sort_order' => $a]);
            $this->audit->record('catalogue.category_moved', $category, ['position' => $index + 1], ['position' => $index + ($direction === 'up' ? 0 : 2)], null, $admin);

            return $category->fresh();
        });
    }

    /* ---------------- Professions ---------------- */

    public function createProfession(User $admin, array $data): Profession
    {
        $name = $this->clean($data['name']);
        $this->guardUnique(Profession::query(), $name, 'A profession with this name already exists.');
        $category = Category::where('slug', $data['category'])->firstOrFail();

        return DB::transaction(function () use ($admin, $data, $name, $category) {
            $profession = new Profession();
            $profession->forceFill([
                'category_id' => $category->id,
                'slug' => self::professionSlug($name),
                'name' => $name,
                'plural_name' => $this->clean($data['plural_name']),
                'sort_order' => ((int) Profession::where('category_id', $category->id)->max('sort_order')) + 10,
                'is_active' => true,
            ])->save();
            $this->audit->record('profession.created', $profession, [], $profession->only(['slug', 'name', 'plural_name', 'category_id']), $data['reason'] ?? null, $admin);

            return $profession;
        });
    }

    public function updateProfession(User $admin, Profession $profession, array $data): Profession
    {
        $changes = [];
        if (isset($data['name'])) {
            $changes['name'] = $this->clean($data['name']);
            $this->guardUnique(Profession::query()->whereKeyNot($profession->id), $changes['name'], 'A profession with this name already exists.');
        }
        if (isset($data['plural_name'])) {
            $changes['plural_name'] = $this->clean($data['plural_name']);
        }
        if (isset($data['category'])) {
            $changes['category_id'] = Category::where('slug', $data['category'])->firstOrFail()->id;
        }

        return $this->change($admin, $profession, $changes, 'catalogue.profession_updated', $data['reason'] ?? null);
    }

    public function setProfessionVisible(User $admin, Profession $profession, bool $visible, ?string $reason): Profession
    {
        return $this->change($admin, $profession, ['is_active' => $visible], $visible ? 'catalogue.profession_shown' : 'catalogue.profession_hidden', $reason);
    }

    /* ---------------- Helpers ---------------- */

    /** @param Category|Profession $model */
    private function change(User $admin, $model, array $changes, string $action, ?string $reason)
    {
        if (! $changes) {
            return $model;
        }

        return DB::transaction(function () use ($admin, $model, $changes, $action, $reason) {
            $before = $model->only(array_keys($changes));
            $model->forceFill($changes)->save();
            $this->audit->record($action, $model, $before, $model->only(array_keys($changes)), $reason, $admin);

            return $model;
        });
    }

    private function clean(string $text): string
    {
        return trim(preg_replace('/\s+/', ' ', $text));
    }

    private function guardUnique($query, string $name, string $message): void
    {
        if ($query->whereRaw('LOWER(name) = ?', [mb_strtolower($name)])->exists()) {
            throw new BusinessRuleException('name_taken', $message, 409);
        }
    }

    /** A profession slug that has never been used (also used for typed professions) */
    public static function professionSlug(string $name): string
    {
        return self::uniqueSlug(Profession::query(), $name, '_');
    }

    /** A slug that has never been used, so old links can't point at the wrong thing */
    private static function uniqueSlug($query, string $name, string $separator): string
    {
        $base = Str::limit(Str::slug($name, $separator), 44, '') ?: 'item';
        $slug = $base;
        for ($i = 2; (clone $query)->where('slug', $slug)->exists(); $i++) {
            $slug = $base.$separator.$i;
        }

        return $slug;
    }
}
