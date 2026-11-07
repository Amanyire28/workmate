<?php

namespace App\Http\Requests;

use App\Http\Requests\Concerns\ValidatesPhone;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Password;

class RegisterRequest extends FormRequest
{
    use ValidatesPhone;

    public function authorize()
    {
        return true;
    }

    protected function prepareForValidation()
    {
        $this->merge(['email' => strtolower(trim((string) $this->input('email')))]);
        $this->normalizePhoneInput();
    }

    public function rules()
    {
        return [
            'name' => ['required', 'string', 'max:100'],
            'email' => ['required', 'string', 'email:rfc', 'max:255', 'unique:users,email'],
            'phone' => $this->phoneRules(),
            'locale' => ['nullable', 'in:en,lg'],
            'password' => ['required', 'confirmed', Password::min(8)->letters()->numbers()],
            // Old enough, and agrees to the Terms (and has read the Privacy Policy)
            'accept_terms' => ['accepted'],
            // Optional: news and offers, off unless ticked
            'marketing' => ['sometimes', 'boolean'],
        ];
    }

    public function messages()
    {
        return [
            'email.unique' => 'An account already uses this email address. Try signing in instead.',
            'phone.unique' => 'An account already uses this phone number.',
            'accept_terms.accepted' => 'Tick the box to confirm your age and agree to the Terms and Conditions.',
        ];
    }
}
