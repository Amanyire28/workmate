<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\ProviderProfile;
use App\Models\ProviderService;
use App\Services\ProviderServices;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** "Services & Prices" on provider profiles, and the provider's own list */
class ProviderServiceController extends Controller
{
    /** @var ProviderServices */
    private $services;

    public function __construct(ProviderServices $services)
    {
        $this->services = $services;
    }

    /** Active services of a provider customers can find */
    public function index(int $provider): JsonResponse
    {
        $profile = ProviderProfile::publiclyListed()->findOrFail($provider);

        return response()->json(['data' => $this->services->listFor($profile)]);
    }

    /** The provider's own list, inactive services too */
    public function mine(Request $request): JsonResponse
    {
        $profile = $request->user()->providerProfile;

        return response()->json(['data' => $profile ? $this->services->listFor($profile, true) : []]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate($this->rules(), $this->messages());
        $service = $this->services->add($request->user(), $data);

        return response()->json(['data' => $this->services->present($service->fresh('category'), true)], 201);
    }

    public function update(Request $request, int $service): JsonResponse
    {
        $data = $request->validate(array_map(function ($rule) {
            return array_merge(['sometimes'], $rule);
        }, $this->rules()) + ['is_active' => ['sometimes', 'boolean']], $this->messages());
        $updated = $this->services->edit($request->user(), ProviderService::findOrFail($service), $data);

        return response()->json(['data' => $this->services->present($updated->fresh('category'), true)]);
    }

    public function destroy(Request $request, int $service): JsonResponse
    {
        $this->services->remove($request->user(), ProviderService::findOrFail($service));

        return response()->json(['data' => ['id' => $service, 'deleted' => true]]);
    }

    public function reorder(Request $request): JsonResponse
    {
        $data = $request->validate([
            'ids' => ['required', 'array', 'max:'.ProviderService::MAX],
            'ids.*' => ['integer'],
        ]);

        return response()->json(['data' => $this->services->reorder($request->user(), $data['ids'])]);
    }

    private function rules(): array
    {
        return [
            'title' => ['required', 'string', 'max:80'],
            'description' => ['nullable', 'string', 'max:300'],
            'category' => ['nullable', 'string', 'max:60'],
            'pricing_type' => ['required', Rule::in(ProviderService::PRICING_TYPES)],
            'price' => ['nullable', 'integer', 'min:'.ProviderService::MIN_PRICE, 'max:'.ProviderService::MAX_PRICE],
            'price_unit' => ['nullable', Rule::in(ProviderService::UNITS)],
        ];
    }

    private function messages(): array
    {
        return [
            'title.required' => 'Give the service a name.',
            'title.max' => 'Keep the name under 80 characters.',
            'description.max' => 'Keep the description under 300 characters.',
            'pricing_type.required' => 'Choose how you price this service.',
            'pricing_type.in' => 'Choose how you price this service.',
            'price.integer' => 'Enter the price in whole shillings.',
            'price.min' => 'Enter a price of at least UGX '.number_format(ProviderService::MIN_PRICE).'.',
            'price.max' => 'That price is too high. Check the amount.',
            'price_unit.in' => 'Choose a unit from the list.',
        ];
    }
}
