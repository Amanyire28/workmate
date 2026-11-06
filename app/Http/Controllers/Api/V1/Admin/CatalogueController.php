<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Models\Category;
use App\Models\Profession;
use App\Services\Catalogue;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** Service categories and professions (catalogue.manage) */
class CatalogueController extends Controller
{
    /** Bootstrap icon names, so a category can't be given anything else */
    private const ICON = ['nullable', 'string', 'regex:/^bi-[a-z0-9-]{2,40}$/'];

    /** @var Catalogue */
    private $catalogue;

    public function __construct(Catalogue $catalogue)
    {
        $this->catalogue = $catalogue;
    }

    public function storeCategory(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'min:3', 'max:60'],
            'description' => ['nullable', 'string', 'max:255'],
            'icon' => self::ICON,
            'reason' => ['nullable', 'string', 'max:500'],
        ]);

        return $this->category($this->catalogue->createCategory($request->user(), $data), 201);
    }

    public function updateCategory(Request $request, string $category): JsonResponse
    {
        $data = $request->validate([
            'name' => ['sometimes', 'required', 'string', 'min:3', 'max:60'],
            'description' => ['sometimes', 'nullable', 'string', 'max:255'],
            'icon' => self::ICON,
            'reason' => ['nullable', 'string', 'max:500'],
        ]);

        return $this->category($this->catalogue->updateCategory($request->user(), $this->findCategory($category), $data));
    }

    public function categoryVisibility(Request $request, string $category): JsonResponse
    {
        $show = $request->routeIs('*.show');
        // Hiding takes something away from customers, so it needs a reason
        $data = $request->validate(['reason' => [$show ? 'nullable' : 'required', 'string', 'min:5', 'max:500']]);

        return $this->category($this->catalogue->setCategoryVisible($request->user(), $this->findCategory($category), $show, $data['reason'] ?? null));
    }

    public function moveCategory(Request $request, string $category): JsonResponse
    {
        $data = $request->validate(['direction' => ['required', Rule::in(['up', 'down'])]]);

        return $this->category($this->catalogue->moveCategory($request->user(), $this->findCategory($category), $data['direction']));
    }

    public function storeProfession(Request $request): JsonResponse
    {
        $data = $request->validate([
            'category' => ['required', 'string', 'exists:categories,slug'],
            'name' => ['required', 'string', 'min:3', 'max:60'],
            'plural_name' => ['required', 'string', 'min:3', 'max:60'],
            'reason' => ['nullable', 'string', 'max:500'],
        ]);

        return $this->profession($this->catalogue->createProfession($request->user(), $data), 201);
    }

    public function updateProfession(Request $request, string $profession): JsonResponse
    {
        $data = $request->validate([
            'category' => ['sometimes', 'required', 'string', 'exists:categories,slug'],
            'name' => ['sometimes', 'required', 'string', 'min:3', 'max:60'],
            'plural_name' => ['sometimes', 'required', 'string', 'min:3', 'max:60'],
            'reason' => ['nullable', 'string', 'max:500'],
        ]);

        return $this->profession($this->catalogue->updateProfession($request->user(), $this->findProfession($profession), $data));
    }

    public function professionVisibility(Request $request, string $profession): JsonResponse
    {
        $show = $request->routeIs('*.show');
        $data = $request->validate(['reason' => [$show ? 'nullable' : 'required', 'string', 'min:5', 'max:500']]);

        return $this->profession($this->catalogue->setProfessionVisible($request->user(), $this->findProfession($profession), $show, $data['reason'] ?? null));
    }

    private function findCategory(string $slug): Category
    {
        return Category::where('slug', $slug)->firstOrFail();
    }

    private function findProfession(string $slug): Profession
    {
        return Profession::where('slug', $slug)->firstOrFail();
    }

    private function category(Category $c, int $status = 200): JsonResponse
    {
        return response()->json(['data' => [
            'id' => $c->slug, 'name' => $c->name, 'description' => $c->description, 'icon' => $c->icon,
            'visible' => (bool) $c->is_active, 'sort_order' => $c->sort_order,
        ]], $status);
    }

    private function profession(Profession $p, int $status = 200): JsonResponse
    {
        return response()->json(['data' => [
            'id' => $p->slug, 'name' => $p->name, 'plural_name' => $p->plural_name,
            'category' => optional($p->category()->first())->slug, 'visible' => (bool) $p->is_active,
        ]], $status);
    }
}
