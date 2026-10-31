<?php

namespace App\Http\Requests;

use App\Http\Requests\Concerns\ValidatesPhone;
use Illuminate\Foundation\Http\FormRequest;

class UpdateProfileRequest extends FormRequest
{
    use ValidatesPhone;

    public function authorize()
    {
        return true;
    }

    protected function prepareForValidation()
    {
        $this->normalizePhoneInput();
    }

    /** Email changes need re-verification and are handled separately. */
    public function rules()
    {
        return [
            'name' => ['sometimes', 'required', 'string', 'max:100'],
            'phone' => array_merge(['sometimes'], $this->phoneRules($this->user()->getKey())),
            'locale' => ['sometimes', 'required', 'in:en,lg'],
        ];
    }

    public function messages()
    {
        return [
            'phone.unique' => 'Another account already uses this phone number.',
        ];
    }
}
