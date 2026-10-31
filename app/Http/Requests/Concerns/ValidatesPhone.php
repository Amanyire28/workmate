<?php

namespace App\Http\Requests\Concerns;

use App\Support\PhoneNumber;
use Illuminate\Validation\Rule;

trait ValidatesPhone
{
    /** Store the normalised form so uniqueness can't be dodged with spacing. */
    protected function normalizePhoneInput(): void
    {
        if (! $this->has('phone')) {
            return;
        }

        $raw = trim((string) $this->input('phone'));

        $this->merge(['phone' => $raw === '' ? null : (PhoneNumber::normalize($raw) ?: $raw)]);
    }

    protected function phoneRules(?int $ignoreUserId = null): array
    {
        return [
            'nullable',
            'string',
            function ($attribute, $value, $fail) {
                if (PhoneNumber::normalize($value) === null) {
                    $fail('Enter a valid phone number, for example 0772 123 456.');
                }
            },
            Rule::unique('users', 'phone')->ignore($ignoreUserId),
        ];
    }
}
