<?php

namespace App\Http\Requests;

use App\Models\Profession;
use App\Models\ProviderProfile;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SaveProviderDetailsRequest extends FormRequest
{
    public function authorize()
    {
        return true;
    }

    public function rules()
    {
        return [
            'type' => ['required', Rule::in(ProviderProfile::TYPES)],
            'display_name' => ['required', 'string', 'max:120'],
            'bio' => ['nullable', 'string', 'max:1000'],
            'business_registration_number' => ['nullable', 'string', 'max:50'],
            // The first profession is the main one
            'professions' => ['required', 'array', 'min:1', 'max:5'],
            'professions.*.profession' => ['required', 'string', 'distinct'],
            'professions.*.years_experience' => ['nullable', 'integer', 'min:0', 'max:60'],
            // What customers see when they find you
            'base_area' => ['nullable', 'string', Rule::exists('areas', 'slug')->where('is_active', true)],
            'service_areas' => ['nullable', 'array', 'max:20'],
            'service_areas.*' => ['string', 'distinct', Rule::exists('areas', 'slug')->where('is_active', true)],
            'price_from' => ['nullable', 'integer', 'min:1000', 'max:100000000'],
            'price_basis' => ['nullable', 'string', 'max:40'],
            'availability_status' => ['nullable', Rule::in(ProviderProfile::AVAILABILITY_STATUSES)],
            'availability_note' => ['nullable', 'string', 'max:120'],
            'skills' => ['nullable', 'array', 'max:12'],
            'skills.*' => ['string', 'max:40'],
        ];
    }

    public function withValidator($validator)
    {
        $validator->after(function ($validator) {
            $requested = collect($this->input('professions', []))->pluck('profession')->filter()->all();
            $available = Profession::active()->whereIn('slug', $requested)->pluck('slug')->all();

            foreach ($requested as $index => $slug) {
                if (! in_array($slug, $available, true)) {
                    $validator->errors()->add("professions.{$index}.profession", 'This profession is not available.');
                }
            }
        });
    }

    public function messages()
    {
        return [
            'professions.required' => 'Choose at least one profession.',
            'professions.min' => 'Choose at least one profession.',
            'professions.max' => 'Choose up to 5 professions.',
            'professions.*.profession.distinct' => 'Each profession can only be chosen once.',
        ];
    }
}
